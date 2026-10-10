<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationAmendment;
use Illuminate\Support\Facades\DB;

/**
 * Immutable reservation history and safe before/after snapshots.
 *
 * Activity logs describe that something happened; this service records exactly
 * what changed so front-office staff can explain a booking to the guest and
 * audit a rate, date, room or status change later.
 */
class ReservationAmendmentService
{
    public static function snapshot(Reservation $reservation): array
    {
        $r = Reservation::with(['guest', 'rooms.room', 'rooms.roomType', 'rooms.ratePlan'])->findOrFail($reservation->id);

        return [
            'reservation' => [
                'reservation_no' => $r->reservation_no,
                'status' => $r->status,
                'arrival_date' => optional($r->arrival_date)->toDateString(),
                'departure_date' => optional($r->departure_date)->toDateString(),
                'eta' => $r->eta,
                'adults' => (int) $r->adults,
                'children' => (int) $r->children,
                'booking_type' => $r->booking_type,
                'group_name' => $r->group_name,
                'company_id' => $r->company_id,
                'business_source_id' => $r->business_source_id,
                'rate_contract_id' => $r->rate_contract_id,
                'billing_instruction' => $r->billing_instruction,
                'discount_pct' => (float) $r->discount_pct,
                'ota_reference' => $r->ota_reference,
                'purpose' => $r->purpose,
                'special_request' => $r->special_request,
            ],
            'guest' => $r->guest ? [
                'id' => $r->guest->id,
                'name' => $r->guest->full_name,
                'phone' => $r->guest->phone,
                'email' => $r->guest->email,
            ] : null,
            'rooms' => $r->rooms->map(fn ($s) => [
                'id' => $s->id,
                'room_type_id' => $s->room_type_id,
                'room_type' => $s->roomType?->name,
                'room_id' => $s->room_id,
                'room' => $s->room?->room_no,
                'rate_plan_id' => $s->rate_plan_id,
                'rate_plan' => $s->ratePlan?->name,
                'arrival_date' => optional($s->arrival_date)->toDateString(),
                'departure_date' => optional($s->departure_date)->toDateString(),
                'rate' => (float) $s->rate,
                'extra_charge' => (float) $s->extra_charge,
                'adults' => (int) $s->adults,
                'children' => (int) $s->children,
                'status' => $s->status,
            ])->values()->all(),
        ];
    }

    public static function record(
        Reservation $reservation,
        string $event,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        string $source = 'front_desk'
    ): ReservationAmendment {
        return DB::transaction(function () use ($reservation, $event, $before, $after, $reason, $source) {
            // Serialize version allocation even when the caller is an external
            // channel or payment callback rather than the desk controller.
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $version = ((int) ReservationAmendment::where('hotel_id', $locked->hotel_id)
                ->where('reservation_id', $locked->id)
                ->lockForUpdate()
                ->max('version')) + 1;

            $amendment = new ReservationAmendment([
                'reservation_id' => $locked->id,
                'version' => $version,
                'event' => $event,
                'source' => $source,
                'reason' => $reason,
                'before_state' => $before,
                'after_state' => $after,
                'changes' => self::changes($before, $after),
                'user_id' => auth()->id(),
            ]);
            $amendment->hotel_id = $locked->hotel_id;
            $amendment->save();

            return $amendment;
        });
    }

    /** Return a compact dot-path diff suitable for both JSON and the audit UI. */
    public static function changes(?array $before, ?array $after): array
    {
        $left = self::flatten($before ?: []);
        $right = self::flatten($after ?: []);
        $keys = array_unique(array_merge(array_keys($left), array_keys($right)));
        $changes = [];

        foreach ($keys as $key) {
            $old = $left[$key] ?? null;
            $new = $right[$key] ?? null;
            if ($old !== $new) {
                $changes[$key] = ['before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }

    protected static function flatten(array $value, string $prefix = ''): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($item)) {
                $out += self::flatten($item, $path);
            } else {
                $out[$path] = $item;
            }
        }

        return $out;
    }
}
