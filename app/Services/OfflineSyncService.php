<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\GuestRequest;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceRequest;
use App\Models\OfflineSyncOperation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Server half of the browser offline outbox.
 *
 * The housekeeping slice supports room-status changes and assigned task
 * actions. Both are replay-safe and reject stale changes rather than silently
 * overwriting a newer server-side action.
 */
class OfflineSyncService
{
    public const VERSION = 5;

    public const ROOM_STATUSES = ['clean', 'dirty', 'inspected', 'touch_up'];

    /** @return array<string, mixed> */
    public static function housekeepingSnapshot(User $user): array
    {
        $rooms = Room::with(['roomType', 'floor', 'currentStay'])
            ->where('is_active', true)->orderBy('room_no')->get();
        $manage = $user->hasPermission('housekeeping.manage');
        $employeeId = Employee::where('user_id', $user->id)->where('is_active', true)->value('id');
        $tasks = HousekeepingTask::with(['room.roomType', 'room.floor'])
            ->whereDate('task_date', bdate()->toDateString())
            ->when(! $manage, fn ($q) => $employeeId ? $q->where('employee_id', $employeeId) : $q->whereRaw('1 = 0'))
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderBy('priority')->orderBy('id')->get();

        return [
            'version' => self::VERSION,
            'hotel' => Tenant::hotel()?->code,
            'generated_at' => now()->toIso8601String(),
            'business_date' => bdate()->toDateString(),
            'can_edit' => $manage || (bool) $employeeId,
            'checklist_required' => setting('hk_checklist_required', '1') === '1',
            'checklist_items' => HousekeepingService::checklist(),
            'rooms' => $rooms->map(fn (Room $room) => self::room($room))->values()->all(),
            'tasks' => $tasks->map(fn (HousekeepingTask $task) => self::task($task))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function guestRequestSnapshot(User $user): array
    {
        $stays = ReservationRoom::with('room')->where('status', 'checked_in')->whereNotNull('room_id')->get()
            ->sortBy(fn (ReservationRoom $stay) => $stay->room?->room_no)->values();
        $stayIds = $stays->pluck('id')->all();
        $requests = GuestRequest::with('room')->whereIn('reservation_room_id', $stayIds)
            ->whereIn('status', GuestRequest::OPEN)->latest('id')->limit(100)->get();

        return [
            'version' => self::VERSION,
            'hotel' => Tenant::hotel()?->code,
            'generated_at' => now()->toIso8601String(),
            'can_edit' => $user->hasPermission('requests.manage'),
            'stays' => $stays->map(fn (ReservationRoom $stay) => [
                'id' => $stay->id, 'room_no' => $stay->room?->room_no,
            ])->values()->all(),
            // Deliberately omit guest names, notes, and other guest PII from the offline snapshot.
            'requests' => $requests->map(fn (GuestRequest $request) => [
                'id' => $request->id, 'request_no' => $request->request_no,
                'stay_id' => $request->reservation_room_id, 'room_id' => $request->room_id,
                'room_no' => $request->room?->room_no, 'category' => $request->category,
                'item' => $request->item, 'quantity' => (int) $request->quantity,
                'status' => $request->status, 'preferred_at' => $request->preferred_at?->toIso8601String(),
                'created_at' => $request->created_at?->toIso8601String(),
            ])->values()->all(),
            'categories' => collect(GuestRequest::CATEGORIES)->map(fn (array $cat, string $key) => [
                'key' => $key, 'label' => $cat[0], 'icon' => $cat[1], 'items' => GuestPortalService::items($key),
            ])->values()->all(),
        ];
    }

    /** @param list<array<string, mixed>> $operations */
    public static function push(array $operations, User $user, ?string $deviceId = null): array
    {
        $deviceId = $deviceId ? mb_substr($deviceId, 0, 80) : null;
        $results = [];

        foreach ($operations as $raw) {
            try {
                $results[] = self::apply($raw, $user, $deviceId);
            } catch (ValidationException $e) {
                $results[] = self::invalid($raw, collect($e->errors())->flatten()->implode(' '), 'rejected');
            } catch (Throwable $e) {
                report($e);
                $results[] = self::invalid($raw, 'The operation could not be applied. It remains safe to retry.', 'retry');
            }
        }

        return [
            'version' => self::VERSION,
            'server_time' => now()->toIso8601String(),
            'results' => $results,
        ];
    }

    /** @param array<string, mixed> $raw */
    protected static function apply(array $raw, User $user, ?string $deviceId): array
    {
        $operationId = (string) ($raw['operation_id'] ?? '');
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}$/', $operationId)) {
            throw ValidationException::withMessages(['operation_id' => ['A valid operation id is required.']]);
        }

        $operation = (string) ($raw['operation'] ?? '');
        $payload = $raw['payload'] ?? [];
        if (! is_array($payload)) {
            throw ValidationException::withMessages(['payload' => ['The offline operation payload must be an object.']]);
        }

        return match ($operation) {
            'housekeeping.room_status' => self::applyRoomStatus($operationId, $operation, $raw, $payload, $deviceId, $user),
            'housekeeping.task' => self::applyTask($operationId, $operation, $raw, $payload, $deviceId, $user),
            'housekeeping.maintenance' => self::applyMaintenance($operationId, $operation, $payload, $deviceId, $user),
            'guest_request.create' => self::applyGuestRequest($operationId, $operation, $payload, $deviceId, $user),
            default => self::rejectUnsupported($operationId, $operation ?: 'unknown', $payload, $deviceId, $user),
        };
    }

    /** @param array<string, mixed> $raw @param array<string, mixed> $payload */
    protected static function applyRoomStatus(string $operationId, string $operation, array $raw, array $payload, ?string $deviceId, User $user): array
    {
        $roomId = filter_var($payload['room_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = (string) ($payload['hk_status'] ?? '');
        $baseUpdatedAt = self::baseTimestamp($raw, $payload);
        if (! $roomId || ! in_array($status, self::ROOM_STATUSES, true)) {
            throw ValidationException::withMessages(['payload' => ['A room id and a valid housekeeping status are required.']]);
        }
        $notes = array_key_exists('notes', $payload) ? trim((string) $payload['notes']) : null;
        if ($notes !== null && mb_strlen($notes) > 255) {
            throw ValidationException::withMessages(['payload.notes' => ['Notes may not exceed 255 characters.']]);
        }

        return DB::transaction(function () use ($operationId, $operation, $payload, $roomId, $status, $baseUpdatedAt, $notes, $deviceId, $user) {
            $existing = OfflineSyncOperation::where('operation_id', $operationId)->lockForUpdate()->first();
            if ($existing) {
                return self::stored($existing);
            }
            if (! $user->hasPermission('housekeeping.manage') && ! $user->hasPermission('housekeeping.attendant')) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'Your login cannot sync housekeeping changes.');
            }
            $room = Room::whereKey($roomId)->where('is_active', true)->lockForUpdate()->first();
            if (! $room) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'The room no longer exists or is not active.');
            }
            $serverChanged = $room->updated_at && Carbon::parse($baseUpdatedAt)->lt($room->updated_at);
            $notesChanged = $notes !== null && $notes !== $room->notes;
            if ($serverChanged && ($room->hk_status !== $status || $notesChanged)) {
                return self::record($operationId, $operation, $payload, $deviceId, $user, 'conflict', $room->id,
                    ['room' => self::room($room->fresh(['roomType', 'floor']))], 'Room status changed while this device was offline.');
            }
            $room->hk_status = $status;
            if ($notes !== null) {
                $room->notes = $notes;
            }
            $room->save();
            ActivityLogger::log('housekeeping', 'offline_status', "Room {$room->room_no} → {$status} (offline sync)", $room);

            return self::record($operationId, $operation, $payload, $deviceId, $user, 'applied', $room->id,
                ['room' => self::room($room->fresh(['roomType', 'floor']))]);
        });
    }

    /** @param array<string, mixed> $raw @param array<string, mixed> $payload */
    protected static function applyTask(string $operationId, string $operation, array $raw, array $payload, ?string $deviceId, User $user): array
    {
        $taskId = filter_var($payload['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $action = (string) ($payload['action'] ?? '');
        $baseUpdatedAt = self::baseTimestamp($raw, $payload);
        if (! $taskId || ! in_array($action, ['start', 'dnd', 'complete', 'inspect_pass', 'inspect_fail'], true)) {
            throw ValidationException::withMessages(['payload' => ['A task id and a valid task action are required.']]);
        }
        $checked = array_values(array_filter((array) ($payload['checked'] ?? []), fn ($item) => is_string($item) && mb_strlen($item) <= 120));
        $notes = array_key_exists('notes', $payload) ? trim((string) $payload['notes']) : null;
        if ($notes !== null && mb_strlen($notes) > 200) {
            throw ValidationException::withMessages(['payload.notes' => ['Notes may not exceed 200 characters.']]);
        }

        return DB::transaction(function () use ($operationId, $operation, $payload, $taskId, $action, $baseUpdatedAt, $checked, $notes, $deviceId, $user) {
            $existing = OfflineSyncOperation::where('operation_id', $operationId)->lockForUpdate()->first();
            if ($existing) {
                return self::stored($existing);
            }
            if (! $user->hasPermission('housekeeping.manage') && ! $user->hasPermission('housekeeping.attendant')) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'Your login cannot sync housekeeping changes.');
            }
            $task = HousekeepingTask::with('room')->whereKey($taskId)->lockForUpdate()->first();
            if (! $task) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'The housekeeping task no longer exists.');
            }
            if (str_starts_with($action, 'inspect_') && ! $user->hasPermission('housekeeping.manage')) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'Only a housekeeping supervisor may inspect rooms.');
            }
            if (! self::mayActOnTask($task, $user)) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'This task is not assigned to your housekeeping login.');
            }
            if ($action === 'inspect_fail' && ! $notes) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'Say what needs re-doing when failing an inspection.');
            }

            $serverChanged = $task->updated_at && Carbon::parse($baseUpdatedAt)->lt($task->updated_at);
            if ($serverChanged && ! self::taskAlreadyInRequestedState($task, $action)) {
                return self::record($operationId, $operation, $payload, $deviceId, $user, 'conflict', $task->id,
                    ['task' => self::task($task->fresh('room'))], 'This task changed while the device was offline.');
            }
            if (! self::taskAlreadyInRequestedState($task, $action)) {
                match ($action) {
                    'start' => HousekeepingService::start($task),
                    'dnd' => HousekeepingService::dnd($task),
                    'complete' => HousekeepingService::complete($task, $checked, $notes),
                    'inspect_pass' => HousekeepingService::inspect($task, true, $notes),
                    'inspect_fail' => HousekeepingService::inspect($task, false, $notes),
                };
            }
            $fresh = $task->fresh('room');
            ActivityLogger::log('housekeeping', 'offline_task', "Task {$task->id} {$action} (offline sync)", $fresh);

            return self::record($operationId, $operation, $payload, $deviceId, $user, 'applied', $task->id,
                ['task' => self::task($fresh)]);
        });
    }

    /** @param array<string, mixed> $payload */
    protected static function applyMaintenance(string $operationId, string $operation, array $payload, ?string $deviceId, User $user): array
    {
        $taskId = filter_var($payload['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $category = (string) ($payload['category'] ?? '');
        $title = trim((string) ($payload['title'] ?? ''));
        $priority = (string) ($payload['priority'] ?? 'normal');
        $description = trim((string) ($payload['description'] ?? ''));
        if (! $taskId || ! in_array($category, ['electrical', 'plumbing', 'ac', 'carpentry', 'it', 'civil', 'general'], true)) {
            throw ValidationException::withMessages(['payload' => ['A task and a valid maintenance category are required.']]);
        }
        if ($title === '' || mb_strlen($title) > 150 || ! in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            throw ValidationException::withMessages(['payload' => ['A title and valid priority are required.']]);
        }
        if (mb_strlen($description) > 1000) {
            throw ValidationException::withMessages(['payload.description' => ['Description may not exceed 1,000 characters.']]);
        }

        return DB::transaction(function () use ($operationId, $operation, $payload, $taskId, $category, $title, $priority, $description, $deviceId, $user) {
            $existing = OfflineSyncOperation::where('operation_id', $operationId)->lockForUpdate()->first();
            if ($existing) {
                return self::stored($existing);
            }
            if (! $user->hasPermission('housekeeping.manage') && ! $user->hasPermission('housekeeping.attendant')) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'Your login cannot sync housekeeping changes.');
            }
            $task = HousekeepingTask::with('room')->whereKey($taskId)->lockForUpdate()->first();
            if (! $task || ! $task->room) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'The housekeeping task or room no longer exists.');
            }
            if (! self::mayActOnTask($task, $user)) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'This task is not assigned to your housekeeping login.');
            }
            $ticket = HousekeepingService::reportIssue($task->room, [
                'category' => $category, 'title' => $title, 'description' => $description ?: null,
                'priority' => $priority, 'block_room' => false,
            ]);

            return self::record($operationId, $operation, $payload, $deviceId, $user, 'applied', $ticket->id,
                ['ticket' => self::ticket($ticket)]);
        });
    }

    /** @param array<string, mixed> $payload */
    protected static function applyGuestRequest(string $operationId, string $operation, array $payload, ?string $deviceId, User $user): array
    {
        $stayId = filter_var($payload['stay_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $category = trim((string) ($payload['category'] ?? ''));
        $item = trim((string) ($payload['item'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));
        $preferredAt = trim((string) ($payload['preferred_at'] ?? ''));
        $time = trim((string) ($payload['time'] ?? ''));
        $quantity = (int) ($payload['quantity'] ?? 1);

        if (! $stayId || ! array_key_exists($category, GuestRequest::CATEGORIES)) {
            throw ValidationException::withMessages(['payload' => ['A checked-in stay and valid request category are required.']]);
        }
        if (mb_strlen($item) > 120 || mb_strlen($notes) > 500 || mb_strlen($preferredAt) > 80 || mb_strlen($time) > 10) {
            throw ValidationException::withMessages(['payload' => ['Request details are too long.']]);
        }
        if ($quantity < 1 || $quantity > 10) {
            throw ValidationException::withMessages(['payload.quantity' => ['Quantity must be between 1 and 10.']]);
        }
        if ($time !== '' && ! preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $time)) {
            throw ValidationException::withMessages(['payload.time' => ['Choose a valid time.']]);
        }

        return DB::transaction(function () use ($operationId, $operation, $payload, $stayId, $category, $item, $notes, $preferredAt, $time, $quantity, $deviceId, $user) {
            $existing = OfflineSyncOperation::where('operation_id', $operationId)->lockForUpdate()->first();
            if ($existing) {
                return self::stored($existing);
            }
            if (! $user->hasPermission('requests.manage')) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'Your login cannot create guest requests.');
            }

            $stay = ReservationRoom::with('room')->whereKey($stayId)->where('status', 'checked_in')->lockForUpdate()->first();
            if (! $stay) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'The guest is no longer checked in.');
            }

            try {
                $request = GuestPortalService::raise($stay, [
                    'category' => $category,
                    'item' => $item ?: null,
                    'notes' => $notes ?: null,
                    'quantity' => $quantity,
                    'preferred_at' => $preferredAt ?: null,
                    'time' => $time ?: null,
                ], 'staff');
            } catch (Throwable $e) {
                return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, $e->getMessage());
            }

            return self::record($operationId, $operation, $payload, $deviceId, $user, 'applied', $request->id,
                ['request' => self::guestRequest($request->fresh(['room']))]);
        });
    }

    /** Persist unsupported operations idempotently too, including a lost-response retry. */
    protected static function rejectUnsupported(string $operationId, string $operation, array $payload, ?string $deviceId, User $user): array
    {
        return DB::transaction(function () use ($operationId, $operation, $payload, $deviceId, $user) {
            $existing = OfflineSyncOperation::where('operation_id', $operationId)->lockForUpdate()->first();
            if ($existing) {
                return self::stored($existing);
            }

            return self::persistRejected($operationId, $operation, $payload, $deviceId, $user, 'This offline operation is not supported.');
        });
    }

    protected static function baseTimestamp(array $raw, array $payload): string
    {
        $value = (string) ($raw['base_updated_at'] ?? $payload['base_updated_at'] ?? '');
        if ($value === '' || ! strtotime($value)) {
            throw ValidationException::withMessages(['base_updated_at' => ['The snapshot timestamp is required for conflict protection.']]);
        }

        return $value;
    }

    protected static function mayActOnTask(HousekeepingTask $task, User $user): bool
    {
        if ($user->hasPermission('housekeeping.manage')) {
            return true;
        }
        $employeeId = Employee::where('user_id', $user->id)->where('is_active', true)->value('id');

        return $employeeId && (int) $task->employee_id === (int) $employeeId;
    }

    protected static function taskAlreadyInRequestedState(HousekeepingTask $task, string $action): bool
    {
        return match ($action) {
            'start' => $task->status === 'in_progress',
            'dnd' => $task->status === 'pending' && $task->dnd_at !== null,
            'complete' => $task->status === 'completed',
            'inspect_pass' => $task->inspection_status === 'passed',
            'inspect_fail' => false,
        };
    }

    /** @param array<string, mixed> $raw */
    protected static function invalid(array $raw, string $message, string $status = 'rejected'): array
    {
        return ['operation_id' => (string) ($raw['operation_id'] ?? ''), 'status' => $status, 'error' => $message];
    }

    /** @param array<string, mixed> $payload */
    protected static function persistRejected(string $operationId, string $operation, array $payload, ?string $deviceId, User $user, string $error): array
    {
        return self::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $payload['room_id'] ?? $payload['task_id'] ?? $payload['stay_id'] ?? null, null, $error);
    }

    /** @param array<string, mixed>|null $result */
    protected static function record(string $operationId, string $operation, array $payload, ?string $deviceId, User $user, string $status, ?int $entityId, ?array $result = null, ?string $error = null): array
    {
        $record = OfflineSyncOperation::create([
            'user_id' => $user->id, 'operation_id' => $operationId, 'device_id' => $deviceId,
            'operation' => $operation, 'status' => $status, 'entity_id' => $entityId,
            'payload' => $payload, 'result' => $result, 'error' => $error, 'applied_at' => now(),
        ]);

        return self::stored($record);
    }

    protected static function stored(OfflineSyncOperation $operation): array
    {
        return ['operation_id' => $operation->operation_id, 'status' => $operation->status,
            'room' => $operation->result['room'] ?? null, 'task' => $operation->result['task'] ?? null,
            'ticket' => $operation->result['ticket'] ?? null, 'request' => $operation->result['request'] ?? null,
            'error' => $operation->error];
    }

    /** @return array<string, mixed> */
    public static function room(Room $room): array
    {
        $stay = $room->relationLoaded('currentStay') ? $room->currentStay : null;

        return [
            'id' => $room->id, 'room_no' => $room->room_no, 'room_type' => $room->roomType?->name,
            'floor' => $room->floor?->name, 'status' => $room->status, 'housekeeping' => $room->hk_status,
            'notes' => $room->notes, 'departure' => $stay?->departure_date?->toDateString(),
            'updated_at' => $room->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function guestRequest(GuestRequest $request): array
    {
        return [
            'id' => $request->id,
            'request_no' => $request->request_no,
            'stay_id' => $request->reservation_room_id,
            'room_id' => $request->room_id,
            'room_no' => $request->room?->room_no,
            'category' => $request->category,
            'item' => $request->item,
            'quantity' => (int) $request->quantity,
            'status' => $request->status,
            'linked_type' => $request->linked_type,
            'linked_id' => $request->linked_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function ticket(MaintenanceRequest $ticket): array
    {
        return ['id' => $ticket->id, 'ticket_no' => $ticket->ticket_no, 'room_id' => $ticket->room_id,
            'title' => $ticket->title, 'priority' => $ticket->priority, 'status' => $ticket->status];
    }

    /** @return array<string, mixed> */
    public static function task(HousekeepingTask $task): array
    {
        return [
            'id' => $task->id, 'room_id' => $task->room_id, 'room_no' => $task->room?->room_no, 'task_type' => $task->task_type,
            'task_label' => HousekeepingTask::TYPES[$task->task_type] ?? $task->task_type, 'status' => $task->status,
            'priority' => $task->priority, 'notes' => $task->notes, 'dnd' => (bool) $task->dnd_at,
            'inspection_status' => $task->inspection_status, 'inspection_notes' => $task->inspection_notes,
            'reclean_count' => (int) $task->reclean_count,
            'checklist' => $task->checklist ?? [], 'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }
}
