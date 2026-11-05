<?php

namespace App\Services;

use App\Models\OfflineSyncOperation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What the devices sent back, and what it became.
 *
 * Every operation a browser outbox replays leaves a receipt in
 * `offline_sync_operations`: which device, which member of staff, what the intent
 * was, whether it applied, and (when it applied) the record it produced. This
 * service turns those receipts into the page a supervisor reads after an outage —
 * "this is the work that was done offline, this is what it created, and this is
 * what still needs a person" — so the sync is never something staff have to take
 * on trust.
 *
 * Read-only: nothing here writes, replays or deletes an operation.
 */
class OfflineReconciliationService
{
    /** How many receipts one screen shows before it says it is showing a window. */
    public const LIMIT = 500;

    public const WINDOW_DAYS = 31;

    public const STATUSES = ['applied', 'conflict', 'rejected', 'retry', 'stalled'];

    /** What each operation means in words a duty manager uses. */
    public const LABELS = [
        'pos.order.create' => 'Opened a bill',
        'pos.order.kot' => 'Sent dishes to the kitchen',
        'frontdesk.checkin' => 'Checked a guest in',
        'frontdesk.charge' => 'Posted a folio charge',
        'housekeeping.room_status' => 'Changed a room status',
        'housekeeping.task' => 'Worked a housekeeping task',
        'housekeeping.maintenance' => 'Raised a maintenance issue',
        'guest_request.create' => 'Logged a guest request',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function report(User $user, array $filters): array
    {
        [$from, $to] = self::window($filters);

        $status = (string) ($filters['status'] ?? '');
        $operation = (string) ($filters['operation'] ?? '');
        $device = trim((string) ($filters['device'] ?? ''));

        $base = OfflineSyncOperation::query()
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString())
            ->when($status !== '' && in_array($status, self::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($operation !== '', fn ($q) => $q->where('operation', $operation))
            ->when($device !== '', fn ($q) => $q->where('device_id', 'like', $device.'%'));

        // Counts describe the whole window, not just the part the page shows.
        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $devices = (clone $base)->whereNotNull('device_id')->distinct()->orderBy('device_id')->pluck('device_id');

        $rows = (clone $base)->with('user:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (OfflineSyncOperation $receipt) => self::row($receipt));

        return [
            'from' => $from,
            'to' => $to,
            'filters' => ['status' => $status, 'operation' => $operation, 'device' => $device],
            'rows' => $rows,
            'summary' => [
                'total' => (int) $counts->sum(),
                'applied' => (int) ($counts['applied'] ?? 0),
                'needs_person' => (int) (($counts['conflict'] ?? 0) + ($counts['rejected'] ?? 0)),
                'retrying' => (int) ($counts['retry'] ?? 0),
                'devices' => $devices->count(),
            ],
            'counts' => $counts->all(),
            'devices' => $devices->all(),
            'operations' => self::LABELS,
            'limit' => self::LIMIT,
            'truncated' => $counts->sum() > self::LIMIT,
        ];
    }

    /**
     * The date window, clamped so a six-month query cannot walk the whole table.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function window(array $filters): array
    {
        $today = bdate()->copy()->startOfDay();
        $earliest = $today->copy()->subDays(self::WINDOW_DAYS);

        $parse = function ($value, Carbon $fallback) {
            $raw = trim((string) $value);
            if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return $fallback;
            }

            return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        };

        $from = $parse($filters['from'] ?? '', $today->copy()->subDays(6));
        $to = $parse($filters['to'] ?? '', $today);

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->lt($earliest)) {
            $from = $earliest;
        }
        if ($to->gt($today)) {
            $to = $today;
        }

        return [$from, $to];
    }

    /** One receipt, described the way the screen needs it. */
    public static function row(OfflineSyncOperation $operation): array
    {
        $payload = (array) ($operation->payload ?? []);
        $result = (array) ($operation->result ?? []);

        return [
            'id' => $operation->id,
            'operation' => $operation->operation,
            'label' => self::LABELS[$operation->operation] ?? $operation->operation,
            'when' => $operation->created_at,
            'applied_at' => $operation->applied_at,
            'device' => $operation->device_id,
            'staff' => $operation->user?->name,
            'status' => $operation->status,
            'what' => self::describe($operation->operation, $payload),
            'produced' => self::produced($operation->operation, $result),
            'reason' => $operation->error,
            'needs_person' => in_array($operation->status, ['conflict', 'rejected'], true),
        ];
    }

    /** What the device asked for, in one line. */
    protected static function describe(string $operation, array $payload): string
    {
        return match ($operation) {
            'pos.order.create' => trim(($payload['order_type'] ?? 'order').' · '.($payload['covers'] ?? 0).' cover(s)'),
            'pos.order.kot' => (is_array($payload['lines'] ?? null) ? count($payload['lines']) : 0).' dish(es)',
            'frontdesk.checkin' => 'stay #'.($payload['stay_id'] ?? '?').' → room #'.($payload['room_id'] ?? '?'),
            'frontdesk.charge' => ($payload['description'] ?? 'charge').' · '.money((float) ($payload['amount'] ?? 0)),
            'housekeeping.room_status' => 'room #'.($payload['room_id'] ?? '?').' → '.($payload['hk_status'] ?? '?'),
            'housekeeping.task' => 'task #'.($payload['task_id'] ?? '?').' · '.($payload['action'] ?? ''),
            'housekeeping.maintenance' => mb_substr((string) ($payload['title'] ?? 'issue'), 0, 60),
            'guest_request.create' => mb_substr((string) ($payload['item'] ?? 'request'), 0, 60),
            default => mb_substr((string) json_encode($payload), 0, 80),
        };
    }

    /** What the server made of it — the record a person can go and look at. */
    protected static function produced(string $operation, array $result): ?string
    {
        $order = $result['order'] ?? null;
        if ($order) {
            return 'Order '.($order['order_no'] ?? ('#'.$order['id'])).' · '.money((float) ($order['total'] ?? 0));
        }
        if (! empty($result['kot'])) {
            return 'Ticket KOT-'.$result['kot'];
        }
        $stay = $result['stay'] ?? null;
        if ($stay) {
            return trim('Room '.($stay['room_no'] ?? '—').' · '.($stay['guest'] ?? 'guest'));
        }
        $charge = $result['charge'] ?? null;
        if ($charge) {
            return money((float) ($charge['amount'] ?? 0)).' to '.($result['folio']['folio_no'] ?? 'folio');
        }
        $room = $result['room'] ?? null;
        if ($room) {
            return 'Room '.($room['room_no'] ?? '—');
        }
        $request = $result['request'] ?? null;
        if ($request) {
            return 'Request '.($request['request_no'] ?? ('#'.$request['id']));
        }

        return null;
    }

    /**
     * The rows as CSV, for a manager who keeps the outage in a spreadsheet.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public static function csv(Collection $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['When', 'Device', 'Staff', 'Operation', 'What was asked', 'What it produced', 'Status', 'Reason'], ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['when']?->toDateTimeString(),
                $row['device'],
                $row['staff'],
                $row['operation'],
                $row['what'],
                $row['produced'],
                $row['status'],
                $row['reason'],
            ], ',', '"', '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
