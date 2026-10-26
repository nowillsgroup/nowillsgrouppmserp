<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\OfflineSyncOperation;
use App\Models\PaymentMode;
use App\Models\RatePlan;
use App\Models\ReservationRoom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v7.39 DesktopSyncService — multi-day offline cache-and-sync for the front desk.
 *
 * A desktop (or satellite) workstation downloads a *pack*: the operating dataset
 * for the next N days (reservations + stays + rooms + rates + payment modes),
 * works offline for several days, then syncs its operation queue back. Each
 * operation carries the `occurred_at` it happened at so multi-day offline work
 * lands with the right dates; `delta` refreshes the local cache without a full
 * re-export.
 *
 * Sibling of OfflineSyncService (housekeeping / guest-request queues and POS
 * draft caching) — this is the desk half the FEATURE-GAP-AUDIT called missing:
 * "no multi-day cache-and-sync". Same operation conventions: 8–80 char
 * operation_id, DB-unique per hotel (retry-safe idempotency), applied |
 * duplicate | rejected results, rejects persisted for the sync log.
 *
 * Offline operation kinds (payload keys):
 *   desk.check_in   {stay_id, room_id}
 *   desk.check_out  {stay_id, force?}
 *   desk.charge     {stay_id, category, description, amount, tax?}
 *   desk.payment    {stay_id, amount, payment_mode_id, reference?}
 *   desk.nights     {stay_id, until}       post room-nights up to `until`
 *
 * Every kind accepts `occurred_at` (ISO-8601) for back-dated offline work.
 */
class DesktopSyncService
{
    public const VERSION = 1;

    public const WINDOW_DAYS = 7;

    /** dataset sections a pack carries, for the desktop cache */
    public const DATASET = ['rooms', 'room_types', 'rate_plans', 'payment_modes', 'reservations', 'stays', 'guests', 'folios'];

    // ------------------------------------------------------------------ cache

    /**
     * Build the multi-day operating pack. `covers` runs from yesterday (offline
     * work is often back-dated) to +$days; `cursor` is the watermark for delta().
     */
    public static function pack(User $user, int $days = self::WINDOW_DAYS, ?string $deviceId = null): array
    {
        $days = max(1, min(31, $days));
        $snapshot = now();
        // Leave a one-second overlap before collecting rows: DB timestamp
        // precision can be whole seconds, so updates concurrent with pack()
        // must still appear in the next delta even if they share its second.
        $cursor = $snapshot->copy()->subSecond();
        $from = $snapshot->copy()->subDay()->startOfDay();
        $until = $snapshot->copy()->addDays($days)->endOfDay();
        $hotelId = $user->hotel_id;

        $stays = ReservationRoom::with(['reservation:id,guest_id,reference,status,arrival_date,departure_date', 'room:id,number,room_type_id'])
            ->whereHas('reservation', fn ($q) => $q->where('hotel_id', $hotelId))
            ->whereHas('room', fn ($q) => $q->where('hotel_id', $hotelId))
            ->where(function ($q) use ($from, $until, $snapshot) {
                // anything touching the window: in house now, arriving or departing inside it
                $q->whereDate('arrival_date', '>=', $from->copy()->subDays(3))->whereDate('arrival_date', '<=', $until)
                    ->orWhere(fn ($b) => $b->whereDate('departure_date', '>=', $from)->whereDate('departure_date', '<=', $until->copy()->addDays(3)))
                    ->orWhere(fn ($w) => $w->whereDate('arrival_date', '<=', $snapshot)->whereDate('departure_date', '>=', $snapshot));
            })
            ->orderBy('arrival_date')->get();

        $reservationIds = $stays->pluck('reservation_id')->filter()->unique()->values();
        $guestIds = \App\Models\Reservation::whereIn('id', $reservationIds)->pluck('guest_id')->filter()->unique()->values();

        return [
            'meta' => [
                'version' => self::VERSION,
                'generated_at' => $snapshot->toIso8601String(),
                'hotel_id' => $hotelId,
                'hotel' => (string) setting('hotel_name', 'Hotel'),
                'device_id' => $deviceId ? mb_substr($deviceId, 0, 80) : null,
                'user' => ['id' => $user->id, 'name' => $user->name],
                'covers' => ['from' => $from->toIso8601String(), 'until' => $until->toIso8601String(), 'days' => $days],
                'cursor' => $cursor->toIso8601String(),
                'counts' => [],
            ],
            'dataset' => [
                'rooms' => \App\Models\Room::where('hotel_id', $hotelId)->orderBy('number')->get(['id', 'number', 'room_type_id', 'status', 'updated_at']),
                'room_types' => \App\Models\RoomType::where('hotel_id', $hotelId)->get(['id', 'name', 'updated_at']),
                'rate_plans' => RatePlan::where('hotel_id', $hotelId)->get(['id', 'name', 'updated_at']),
                'payment_modes' => PaymentMode::orderBy('id')->get(['id', 'name', 'updated_at']),
                'reservations' => \App\Models\Reservation::with('guest:id,first_name,last_name')->whereIn('id', $reservationIds)
                    ->get(['id', 'reference', 'guest_id', 'status', 'arrival_date', 'departure_date', 'updated_at']),
                'stays' => $stays,
                'guests' => Guest::whereIn('id', $guestIds)->get(['id', 'first_name', 'last_name', 'email', 'phone', 'updated_at']),
                'folios' => Folio::whereIn('reservation_room_id', $stays->pluck('id'))->get(['id', 'reservation_room_id', 'updated_at']),
            ],
        ];
    }

    /** Everything changed since the cursor — refreshes a desktop cache cheaply. */
    public static function delta(User $user, string $since): array
    {
        try {
            $since = \Carbon\Carbon::parse($since);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['since' => ['The since cursor must be a date-time.']]);
        }
        $snapshot = now();
        $cursor = $snapshot->copy()->subSecond();
        $hotelId = $user->hotel_id;

        return [
            'meta' => ['version' => self::VERSION, 'generated_at' => $snapshot->toIso8601String(), 'since' => $since->toIso8601String(), 'cursor' => $cursor->toIso8601String()],
            'rooms' => \App\Models\Room::where('hotel_id', $hotelId)->where('updated_at', '>', $since)->orderBy('number')->get(),
            'room_types' => \App\Models\RoomType::where('hotel_id', $hotelId)->where('updated_at', '>', $since)->get(['id', 'name', 'updated_at']),
            'rate_plans' => RatePlan::where('hotel_id', $hotelId)->where('updated_at', '>', $since)->get(['id', 'name', 'updated_at']),
            'payment_modes' => PaymentMode::where('hotel_id', $hotelId)->where('updated_at', '>', $since)->get(['id', 'name', 'updated_at']),
            'stays' => ReservationRoom::whereHas('room', fn ($q) => $q->where('hotel_id', $hotelId))
                ->where('updated_at', '>', $since)->orderBy('arrival_date')->get(),
            'reservations' => \App\Models\Reservation::where('hotel_id', $hotelId)->where('updated_at', '>', $since)->orderBy('arrival_date')->get(),
            'guests' => Guest::where('hotel_id', $hotelId)->where('updated_at', '>', $since)
                ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'updated_at']),
            'folios' => Folio::where('hotel_id', $hotelId)->where('updated_at', '>', $since)
                ->get(['id', 'reservation_room_id', 'updated_at']),
            'folio_transactions' => \App\Models\FolioTransaction::whereHas('folio', fn ($q) => $q->where('hotel_id', $hotelId))
                ->where('updated_at', '>', $since)->orderBy('id')->get(),
        ];
    }

    // ------------------------------------------------------------------ sync

    /** @param array<int, array<string, mixed>> $operations */
    public static function sync(array $operations, User $user, ?string $deviceId = null): array
    {
        $deviceId = $deviceId ? mb_substr($deviceId, 0, 80) : null;
        $results = [];
        foreach ($operations as $raw) {
            try {
                $results[] = self::apply($raw, $user, $deviceId);
            } catch (ValidationException $e) {
                $results[] = self::rejected((string) ($raw['operation_id'] ?? ''), (string) ($raw['operation'] ?? 'unknown'), (array) ($raw['payload'] ?? []), $deviceId, $user, collect($e->errors())->flatten()->implode(' '));
            } catch (\RuntimeException $e) {
                // services throw RuntimeException for user-facing problems
                $results[] = self::rejected((string) ($raw['operation_id'] ?? ''), (string) ($raw['operation'] ?? 'unknown'), (array) ($raw['payload'] ?? []), $deviceId, $user, $e->getMessage());
            } catch (\Throwable $e) {
                report($e);
                $results[] = self::rejected((string) ($raw['operation_id'] ?? ''), (string) ($raw['operation'] ?? 'unknown'), (array) ($raw['payload'] ?? []), $deviceId, $user, 'The operation could not be applied. It remains safe to retry.');
            }
        }

        return ['version' => self::VERSION, 'server_time' => now()->toIso8601String(), 'results' => $results];
    }

    /** @param array<string, mixed> $raw */
    protected static function apply(array $raw, User $user, ?string $deviceId): array
    {
        $operationId = (string) ($raw['operation_id'] ?? '');
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}$/', $operationId)) {
            throw ValidationException::withMessages(['operation_id' => ['A valid operation id is required.']]);
        }
        // idempotency: a retry of an applied operation reports duplicate;
        // a previously rejected one may be retried (the reject record is replaced)
        $prior = OfflineSyncOperation::where('hotel_id', $user->hotel_id)->where('operation_id', $operationId)->first();
        if ($prior && $prior->status !== 'rejected') {
            return self::duplicate($operationId);
        }
        $prior?->delete();

        $operation = (string) ($raw['operation'] ?? '');
        $payload = $raw['payload'] ?? [];
        if (! is_array($payload)) {
            throw ValidationException::withMessages(['payload' => ['The offline operation payload must be an object.']]);
        }
        $at = self::occurredAt($raw, $payload);

        $dispatch = fn () => match ($operation) {
            'desk.check_in' => self::opCheckIn($operationId, $payload, $at, $deviceId, $user),
            'desk.check_out' => self::opCheckOut($operationId, $payload, $at, $deviceId, $user),
            'desk.charge' => self::opCharge($operationId, $payload, $at, $deviceId, $user),
            'desk.payment' => self::opPayment($operationId, $payload, $at, $deviceId, $user),
            'desk.nights' => self::opNights($operationId, $payload, $at, $deviceId, $user),
            default => self::rejected($operationId, $operation ?: 'unknown', $payload, $deviceId, $user, 'This offline operation is not supported.'),
        };

        return $at ? \App\Support\BusinessDateContext::run($at, $dispatch) : $dispatch();
    }

    protected static function occurredAt(array $raw, array $payload): ?\Illuminate\Support\Carbon
    {
        $v = $raw['occurred_at'] ?? $payload['occurred_at'] ?? null;
        if (! $v) {
            return null;
        }
        try {
            $at = \Illuminate\Support\Carbon::parse($v);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['occurred_at' => ['occurred_at must be a date-time.']]);
        }
        if ($at->lt(now()->subDays(31)) || $at->gt(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['occurred_at' => ['occurred_at must be within the last 31 days and no more than 5 minutes ahead.']]);
        }

        return $at;
    }

    // ------------------------------------------------------------------ ops

    protected static function opCheckIn(string $operationId, array $payload, $at, ?string $deviceId, User $user): array
    {
        $stay = self::stay($payload);
        $roomId = (int) ($payload['room_id'] ?? 0);
        if (! $roomId) {
            throw ValidationException::withMessages(['room_id' => ['room_id is required for a check-in.']]);
        }
        if ($stay->status !== 'reserved') {
            throw ValidationException::withMessages(['stay' => ['Only a reserved stay can be checked in – this one is '.$stay->status.'.']]);
        }
        StayService::checkIn($stay->fresh(), $roomId);

        return self::applied($operationId, 'desk.check_in', $payload, $deviceId, $user, $stay->id, ['checked_in' => true, 'room_id' => $roomId, 'occurred_at' => $at?->toIso8601String()]);
    }

    protected static function opCheckOut(string $operationId, array $payload, $at, ?string $deviceId, User $user): array
    {
        $stay = self::stay($payload);
        if ($stay->status !== 'checked_in') {
            throw ValidationException::withMessages(['stay' => ['Only a checked-in stay can be checked out – this one is '.$stay->status.'.']]);
        }
        StayService::checkOut($stay->fresh(), (bool) ($payload['force'] ?? false));

        return self::applied($operationId, 'desk.check_out', $payload, $deviceId, $user, $stay->id, ['checked_out' => true, 'occurred_at' => $at?->toIso8601String()]);
    }

    protected static function opCharge(string $operationId, array $payload, $at, ?string $deviceId, User $user): array
    {
        $stay = self::stay($payload);
        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['amount must be greater than zero.']]);
        }

        return DB::transaction(function () use ($operationId, $payload, $at, $deviceId, $user, $stay, $amount) {
            if ($duplicate = self::priorApplied($operationId, $user)) {
                return $duplicate;
            }
            $folio = FolioService::openForStay($stay->fresh());
            $tx = FolioService::charge($folio, (string) ($payload['category'] ?? 'extras'), (string) ($payload['description'] ?? 'Offline charge'), $amount,
                array_key_exists('tax', $payload) ? (bool) $payload['tax'] : true,
                ['source_type' => 'offline', 'source_id' => $operationId]);

            return self::applied($operationId, 'desk.charge', $payload, $deviceId, $user, $stay->id, ['transaction_id' => $tx->id, 'amount' => $amount, 'occurred_at' => $at?->toIso8601String()]);
        });
    }

    protected static function opPayment(string $operationId, array $payload, $at, ?string $deviceId, User $user): array
    {
        $stay = self::stay($payload);
        $amount = (float) ($payload['amount'] ?? 0);
        $modeId = (int) ($payload['payment_mode_id'] ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['amount must be greater than zero.']]);
        }
        if (! $modeId) {
            throw ValidationException::withMessages(['payment_mode_id' => ['payment_mode_id is required.']]);
        }

        return DB::transaction(function () use ($operationId, $payload, $at, $deviceId, $user, $stay, $amount, $modeId) {
            if ($duplicate = self::priorApplied($operationId, $user)) {
                return $duplicate;
            }
            $folio = FolioService::openForStay($stay->fresh());
            $tx = FolioService::payment($folio, $amount, $modeId, $payload['reference'] ?? 'OFFLINE '.$operationId);

            return self::applied($operationId, 'desk.payment', $payload, $deviceId, $user, $stay->id, ['transaction_id' => $tx->id, 'amount' => $amount, 'occurred_at' => $at?->toIso8601String()]);
        });
    }

    protected static function opNights(string $operationId, array $payload, $at, ?string $deviceId, User $user): array
    {
        $stay = self::stay($payload);
        try {
            $until = filled($payload['until'] ?? null)
                ? \Illuminate\Support\Carbon::parse($payload['until'])
                : ($at ?? now());
        } catch (\Throwable) {
            throw ValidationException::withMessages(['until' => ['until must be a date-time.']]);
        }
        return DB::transaction(function () use ($operationId, $payload, $at, $deviceId, $user, $stay, $until) {
            if ($duplicate = self::priorApplied($operationId, $user)) {
                return $duplicate;
            }
            StayService::postPendingNights($stay->fresh(), $until);

            return self::applied($operationId, 'desk.nights', $payload, $deviceId, $user, $stay->id, ['posted_until' => $until->toDateString(), 'occurred_at' => $at?->toIso8601String()]);
        });
    }

    // ------------------------------------------------------------------ helpers

    /** Repeat the idempotency check under the financial write transaction. */
    protected static function priorApplied(string $operationId, User $user): ?array
    {
        $prior = OfflineSyncOperation::where('hotel_id', $user->hotel_id)->where('operation_id', $operationId)->lockForUpdate()->first();
        if ($prior && $prior->status !== 'rejected') {
            return self::duplicate($operationId);
        }
        $prior?->delete();

        return null;
    }

    protected static function duplicate(string $operationId): array
    {
        return ['operation_id' => $operationId, 'status' => 'duplicate', 'error' => null];
    }

    protected static function stay(array $payload): ReservationRoom
    {
        $stayId = (int) ($payload['stay_id'] ?? 0);
        $stay = ReservationRoom::with(['reservation', 'room'])->find($stayId);
        if (! $stay || ($stay->hotel_id ?? null) !== auth()->user()?->hotel_id) {
            throw ValidationException::withMessages(['stay_id' => ['No such stay on this property.']]);
        }

        return $stay;
    }

    protected static function applied(string $operationId, string $operation, array $payload, ?string $deviceId, User $user, int $entityId, array $result): array
    {
        return self::record($operationId, $operation, $payload, $deviceId, $user, 'applied', $entityId, $result, null);
    }

    protected static function rejected(string $operationId, string $operation, array $payload, ?string $deviceId, User $user, string $error): array
    {
        return self::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $payload['stay_id'] ?? null, null, $error);
    }

    /** @param array<string, mixed>|null $result */
    protected static function record(string $operationId, string $operation, array $payload, ?string $deviceId, User $user, string $status, ?int $entityId, ?array $result, ?string $error): array
    {
        OfflineSyncOperation::create([
            'user_id' => $user->id, 'operation_id' => $operationId, 'device_id' => $deviceId,
            'operation' => $operation, 'status' => $status, 'entity_id' => $entityId,
            'payload' => $payload, 'result' => $result, 'error' => $error, 'applied_at' => now(),
        ]);

        return ['operation_id' => $operationId, 'status' => $status, 'error' => $error];
    }
}
