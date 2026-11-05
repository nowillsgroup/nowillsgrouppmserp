<?php

namespace App\Services;

use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\OfflineSyncOperation;
use App\Models\User;
use App\Support\BusinessDateContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Front desk work that survives a dead connection.
 *
 * Two intents are supported, because they are the two the desk needs in an outage
 * and the two that can be replayed without guessing what a guest was charged:
 *
 *   · frontdesk.checkin   — check in a reserved room against a vacant room
 *   · frontdesk.charge    — post a charge to the open folio of an in-house guest
 *
 * Both reuse the exact code the online desk uses (StayService::checkIn,
 * FolioService::charge) rather than re-implementing the rules, and both run inside
 * BusinessDateContext so a charge taken at 23:55 lands on the business date the
 * desk was working, not the one the sync happened to reach the server.
 *
 * Money taken from the guest — advances, settlements, refunds — is deliberately not
 * in this list: it belongs to the cashier shift and the folio's payment trail, and
 * a second implementation could take a payment twice. The desk records the charge;
 * the payment is taken at the till when the line is back.
 */
class OfflineFrontDeskService
{
    public const VERSION = 1;

    /** Categories a desk may post without a workflow behind them. */
    public const EXCLUDED_CATEGORIES = ['room', 'cancellation', 'excursion'];

    public const MAX_CHARGE = 9999999;

    public const BACKDATE_DAYS = 7;

    /** @return array<string, mixed> */
    public static function snapshot(User $user): array
    {
        $today = bdate()->toDateString();

        $arrivals = ReservationRoom::with(['reservation.guest', 'roomType'])
            ->where('status', 'reserved')
            ->whereDate('arrival_date', '<=', $today)
            ->orderBy('arrival_date')->orderBy('id')->limit(120)->get();

        $inHouse = ReservationRoom::with(['reservation.guest', 'room'])
            ->where('status', 'checked_in')->whereNotNull('room_id')
            ->orderBy('id')->limit(300)->get();

        $rooms = Room::where('is_active', true)->orderBy('room_no')
            ->get(['id', 'room_no', 'room_type_id', 'status', 'hk_status']);

        $canViewFolio = $user->hasPermission('folio.view');

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'business_date' => $today,
            'hotel' => \App\Support\Tenant::hotel()?->code,
            'staff' => $user->name,
            'can_checkin' => $user->hasPermission('frontdesk.checkin'),
            'can_charge' => $user->hasPermission('folio.post'),
            'arrivals' => $arrivals->map(fn (ReservationRoom $stay) => [
                'stay_id' => $stay->id,
                'reservation_no' => $stay->reservation?->reservation_no,
                'guest' => $stay->reservation?->guest?->full_name,
                'room_type' => $stay->roomType?->name,
                'room_type_id' => $stay->room_type_id,
                'arrival_date' => $stay->arrival_date?->toDateString(),
                'departure_date' => $stay->departure_date?->toDateString(),
                'nights' => $stay->arrival_date && $stay->departure_date ? $stay->arrival_date->diffInDays($stay->departure_date) : null,
                'rate' => (float) $stay->rate,
                'room_id' => $stay->room_id,
            ])->values()->all(),
            'in_house' => $inHouse->map(fn (ReservationRoom $stay) => [
                'stay_id' => $stay->id,
                'room_id' => $stay->room_id,
                'room_no' => $stay->room?->room_no,
                'guest' => $stay->reservation?->guest?->full_name,
                'departure_date' => $stay->departure_date?->toDateString(),
                'balance' => $canViewFolio && $stay->folio ? (float) ($stay->folio->totals()['balance'] ?? 0) : null,
            ])->values()->all(),
            'rooms' => $rooms->map(fn (Room $room) => [
                'id' => $room->id, 'room_no' => $room->room_no, 'room_type_id' => $room->room_type_id,
                'status' => $room->status, 'housekeeping' => $room->hk_status,
            ])->values()->all(),
            'categories' => self::categories(),
        ];
    }

    /**
     * frontdesk.checkin — put a reserved room into a vacant room.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function checkIn(string $operationId, string $operation, array $payload, ?string $deviceId, User $user): array
    {
        $stayId = (int) ($payload['stay_id'] ?? 0);
        $roomId = (int) ($payload['room_id'] ?? 0);
        if (! $stayId || ! $roomId) {
            throw ValidationException::withMessages(['payload' => ['A stay and a room are needed to check a guest in.']]);
        }
        $businessDate = OfflineSyncService::deviceBusinessDate($payload, self::BACKDATE_DAYS);

        return DB::transaction(function () use ($operationId, $operation, $payload, $deviceId, $user, $stayId, $roomId, $businessDate) {
            $existing = OfflineSyncOperation::where('operation_id', $operationId)->lockForUpdate()->first();
            if ($existing) {
                return OfflineSyncService::stored($existing);
            }
            if (! $user->hasPermission('frontdesk.checkin')) {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', null, null,
                    'Your login cannot check guests in.');
            }

            $stay = ReservationRoom::with('reservation.guest')->whereKey($stayId)->lockForUpdate()->first();
            if (! $stay) {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', null, null,
                    'That stay is no longer on the reservation.');
            }
            if ($stay->status === 'checked_in') {
                // Replayed after a lost response, or the desk did it online as well.
                if ((int) $stay->room_id === $roomId) {
                    return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'applied', $stay->id,
                        ['stay' => self::stay($stay->fresh(['room', 'reservation.guest'])), 'already' => true]);
                }

                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'conflict', $stay->id,
                    ['stay' => self::stay($stay->fresh(['room', 'reservation.guest']))],
                    'The guest was already checked in to another room. Nothing was changed here.');
            }
            if ($stay->status !== 'reserved') {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $stay->id, null,
                    'This stay is '.str_replace('_', ' ', (string) $stay->status).' and can no longer be checked in.');
            }

            $room = Room::whereKey($roomId)->where('is_active', true)->first();
            if (! $room) {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $stay->id, null,
                    'That room is not an active room.');
            }
            if ($room->status !== 'vacant') {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'conflict', $stay->id,
                    ['room' => ['id' => $room->id, 'room_no' => $room->room_no, 'status' => $room->status]],
                    'Room '.$room->room_no.' was taken by the time this check-in reached the server.');
            }

            try {
                $checked = BusinessDateContext::run($businessDate, fn () => StayService::checkIn($stay->fresh('reservation.guest'), $room->id));
            } catch (Throwable $e) {
                report($e);

                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $stay->id, null,
                    $e->getMessage() ?: 'The server could not check this guest in.');
            }

            ActivityLogger::log('frontdesk', 'offline_checkin',
                "Offline check-in {$checked->reservation?->reservation_no} into room {$room->room_no}", $checked);

            return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'applied', $checked->id,
                ['stay' => self::stay($checked->fresh(['room', 'reservation.guest']))]);
        });
    }

    /**
     * frontdesk.charge — post a charge on the open folio of an in-house guest.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function postCharge(string $operationId, string $operation, array $payload, ?string $deviceId, User $user): array
    {
        $stayId = (int) ($payload['stay_id'] ?? 0);
        $category = (string) ($payload['category'] ?? '');
        $description = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($payload['description'] ?? ''))));
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $applyTax = ! array_key_exists('apply_tax', $payload) || (bool) $payload['apply_tax'];

        if (! $stayId) {
            throw ValidationException::withMessages(['payload.stay_id' => ['Choose the guest this charge belongs to.']]);
        }
        if (! array_key_exists($category, self::categories())) {
            throw ValidationException::withMessages(['payload.category' => ['That charge category cannot be posted while offline.']]);
        }
        if ($description === '' || mb_strlen($description) > 255) {
            throw ValidationException::withMessages(['payload.description' => ['Describe the charge in 255 characters or fewer.']]);
        }
        if ($amount < 0.01 || $amount > self::MAX_CHARGE) {
            throw ValidationException::withMessages(['payload.amount' => ['The amount must be between 0.01 and '.self::MAX_CHARGE.'.']]);
        }
        $businessDate = OfflineSyncService::deviceBusinessDate($payload, self::BACKDATE_DAYS);

        return DB::transaction(function () use ($operationId, $operation, $payload, $deviceId, $user, $stayId, $category, $description, $amount, $applyTax, $businessDate) {
            $existing = OfflineSyncOperation::where('operation_id', $operationId)->lockForUpdate()->first();
            if ($existing) {
                return OfflineSyncService::stored($existing);
            }
            if (! $user->hasPermission('folio.post')) {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $stayId, null,
                    'Your login cannot post folio charges.');
            }

            $stay = ReservationRoom::with('room')->whereKey($stayId)->lockForUpdate()->first();
            if (! $stay) {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', null, null,
                    'That stay is no longer on the reservation.');
            }
            if ($stay->status !== 'checked_in') {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $stay->id, null,
                    'The guest is no longer in-house, so nothing was posted.');
            }

            try {
                $folio = FolioService::openForStay($stay);
                $charge = BusinessDateContext::run($businessDate,
                    fn () => FolioService::charge($folio, $category, $description, $amount, $applyTax));
            } catch (Throwable $e) {
                report($e);

                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $stay->id, null,
                    $e->getMessage() ?: 'The charge could not be posted.');
            }

            ActivityLogger::log('folio', 'offline_charge',
                "Offline charge {$description} ".money($amount)." to {$folio->folio_no}", $folio->fresh());

            return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'applied', $stay->id, [
                'charge' => [
                    'id' => $charge->id,
                    'category' => $charge->category,
                    'description' => $charge->description,
                    'amount' => (float) $charge->amount,
                    'tax_amount' => (float) $charge->tax_amount,
                    'business_date' => $charge->business_date?->toDateString(),
                ],
                'folio' => [
                    'folio_no' => $folio->folio_no,
                    'balance' => (float) ($folio->fresh()->totals()['balance'] ?? 0),
                ],
            ]);
        });
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        $all = (array) config('pms.charge_categories', []);

        return array_diff_key($all, array_flip(self::EXCLUDED_CATEGORIES));
    }

    /** @return array<string, mixed> */
    public static function stay(ReservationRoom $stay): array
    {
        return [
            'stay_id' => $stay->id,
            'status' => $stay->status,
            'room_id' => $stay->room_id,
            'room_no' => $stay->room?->room_no,
            'reservation_no' => $stay->reservation?->reservation_no,
            'guest' => $stay->reservation?->guest?->full_name,
            'departure_date' => $stay->departure_date?->toDateString(),
        ];
    }
}
