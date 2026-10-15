<?php

namespace App\Services;

use App\Models\CarRental;
use App\Models\RentalVehicle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v7.40 RentalService — self-drive car rental: fleet, pricing, bookings and the
 * pick-up / return desk with fuel, mileage and late-return adjustments.
 *
 * A rental is either billed to an in-house stay (folio charge on return) or
 * settled directly (total + deposit recorded on the booking for the cashier).
 * Vehicles clash-check per property: one active rental per vehicle, and no
 * overlapping booking window.
 */
class RentalService
{
    /** % tank shortfall charged per missing 10% + rate card */
    public const FUEL_PRICE_PER_TANK = 250.0;   // flat tank refill when returned below the pickup level
    public const LATE_PER_HOUR = 60.0;
    public const MILEAGE_PER_KM = 2.5;          // beyond the free 150 km/day

    public static function freeKmPerDay(): int
    {
        return (int) setting('rental_free_km', 150);
    }

    // ------------------------------------------------------------------ pricing

    /** @return array{days:int, daily:float, weeks:int, extras:float, rental:float, deposit:float, total:float} */
    public static function pricing(RentalVehicle $v, $from, $to, float $extras = 0.0): array
    {
        $from = \Illuminate\Support\Carbon::parse($from);
        $to = \Illuminate\Support\Carbon::parse($to);
        $seconds = max(3600, $to->getTimestamp() - $from->getTimestamp()); // integer math - Carbon float diffs round up a phantom day
        $days = (int) max(1, (int) ceil($seconds / 86400));
        $weeks = intdiv($days, 7);
        $rest = $days % 7;
        $weekly = (float) ($v->weekly_rate ?: 7 * $v->daily_rate);
        $rental = $weeks * $weekly + $rest * (float) $v->daily_rate;

        return [
            'days' => $days, 'daily' => (float) $v->daily_rate, 'weeks' => $weeks,
            'extras' => round($extras, 2), 'rental' => round($rental, 2),
            'deposit' => (float) $v->deposit, 'total' => round($rental + $extras, 2),
        ];
    }

    // ------------------------------------------------------------------ fleet

    /** Vehicles free for the whole window (optionally of one class). */
    public static function available($from, $to, ?string $class = null)
    {
        $busy = self::clashingIds($from, $to);

        return RentalVehicle::where('status', '!=', 'retired')
            ->where('status', '!=', 'maintenance')
            ->when($class, fn ($q) => $q->where('class', $class))
            ->whereNotIn('id', $busy)
            ->orderBy('class')->orderBy('make')->get();
    }

    protected static function clashingIds($from, $to, ?int $exceptId = null): array
    {
        return CarRental::whereIn('status', ['reserved', 'picked_up'])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('pickup_at', '<', $to)->where('return_due_at', '>', $from)
            ->pluck('rental_vehicle_id')->all();
    }

    // ------------------------------------------------------------------ bookings

    /** @param array<string, mixed> $d */
    public static function book(array $d, User $u): CarRental
    {
        $vehicle = RentalVehicle::where('hotel_id', $u->hotel_id)->find((int) ($d['rental_vehicle_id'] ?? 0));
        if (! $vehicle) {
            throw ValidationException::withMessages(['rental_vehicle_id' => ['Choose a vehicle.']]);
        }
        if (in_array($vehicle->status, ['maintenance', 'retired'], true)) {
            throw ValidationException::withMessages(['rental_vehicle_id' => ["{$vehicle->reg_no} is not rentable ({$vehicle->statusLabel()})."]]);
        }
        try {
            $from = \Illuminate\Support\Carbon::parse($d['pickup_at'] ?? '');
            $to = \Illuminate\Support\Carbon::parse($d['return_due_at'] ?? '');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['pickup_at' => ['Give valid pick-up and return times.']]);
        }
        if ($to->lte($from)) {
            throw ValidationException::withMessages(['return_due_at' => ['The return time must be after pick-up.']]);
        }
        if (in_array($vehicle->id, self::clashingIds($from, $to), true)) {
            throw ValidationException::withMessages(['rental_vehicle_id' => ["{$vehicle->reg_no} already has a booking in that window."]]);
        }
        $name = trim((string) ($d['customer_name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['customer_name' => ['Give the renter’s name.']]);
        }

        $price = self::pricing($vehicle, $from, $to, (float) ($d['extras'] ?? 0));

        $r = new CarRental([
            'reference' => self::reference(),
            'rental_vehicle_id' => $vehicle->id,
            'guest_id' => (int) ($d['guest_id'] ?? 0) ?: null,
            'reservation_room_id' => (int) ($d['reservation_room_id'] ?? 0) ?: null,
            'customer_name' => $name,
            'customer_phone' => trim((string) ($d['customer_phone'] ?? '')) ?: null,
            'license_no' => trim((string) ($d['license_no'] ?? '')) ?: null,
            'pickup_at' => $from, 'return_due_at' => $to,
            'daily_rate' => $price['daily'], 'extras' => $price['extras'],
            'total' => $price['total'], 'deposit' => $price['deposit'],
            'status' => 'reserved', 'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
            'created_by' => $u->id,
        ]);
        $r->hotel_id = $u->hotel_id; // guarded — set explicitly
        $r->save();
        ActivityLogger::log('rental', 'booking', "Car rental {$r->reference} booked ({$vehicle->reg_no}, {$price['days']} days, {$price['total']})", $r);

        return $r;
    }

    protected static function reference(): string
    {
        $n = (CarRental::max('id') ?? 0) + 1;

        return 'CR-'.now()->format('Ym').'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------ desk

    public static function pickUp(CarRental $r, ?int $mileage, ?float $fuel, User $u): CarRental
    {
        if ($r->status !== 'reserved') {
            throw ValidationException::withMessages(['status' => ["{$r->reference} is {$r->statusLabel()} – only a reserved rental can be picked up."]]);
        }
        DB::transaction(function () use ($r, $mileage, $fuel, $u) {
            $v = $r->vehicle;
            $r->update([
                'status' => 'picked_up', 'pickup_mileage' => $mileage ?? $v->odometer, 'fuel_out' => $fuel ?? $v->fuel_out,
            ]);
            $v->update(['status' => 'rented']);
        });
        ActivityLogger::log('rental', 'pickup', "Car rental {$r->reference} picked up ({$r->vehicle->reg_no})", $r);

        return $r->fresh();
    }

    /** Return with tank / odometer readings — computes fuel, mileage and late adjustments. */
    public static function return(CarRental $r, ?int $mileage, ?float $fuel, User $u): CarRental
    {
        if ($r->status !== 'picked_up') {
            throw ValidationException::withMessages(['status' => ["{$r->reference} is {$r->statusLabel()} – only an on-rent booking can be returned."]]);
        }
        $now = now();
        $adjust = 0.0;
        $lines = [];

        // late return: beyond 3 hours' grace
        if ($now->gt($r->return_due_at->copy()->addHours(3))) {
            $hours = (int) ceil($r->return_due_at->diffInHours($now));
            $late = self::LATE_PER_HOUR * max(1, $hours - 3);
            $adjust += $late;
            $lines[] = "late return (".($hours - 3)."h)";
        }

        // fuel: refill charge when below the pickup level
        $out = $r->fuel_out ?? 100;
        $in = $fuel ?? $out;
        if ($in < $out - 0.5) {
            $adjust += self::FUEL_PRICE_PER_TANK;
            $lines[] = "fuel {$in}% vs {$out}% out";
        }

        // mileage: beyond the free allowance
        if ($mileage !== null && $r->pickup_mileage !== null && $mileage >= $r->pickup_mileage) {
            $km = $mileage - $r->pickup_mileage;
            $free = self::freeKmPerDay() * max(1, (int) ceil($r->pickup_at->diffInDays($r->return_due_at)));
            if ($km > $free) {
                $over = self::MILEAGE_PER_KM * ($km - $free);
                $adjust += $over;
                $lines[] = "mileage {$km} km (".($km - $free)." over)";
            }
        }

        DB::transaction(function () use ($r, $mileage, $fuel, $adjust, $now) {
            $v = $r->vehicle;
            $r->update([
                'status' => 'returned', 'returned_at' => $now,
                'return_mileage' => $mileage, 'fuel_in' => $fuel,
                'adjustments' => round($adjust, 2),
                'total' => round((float) $r->total + $adjust, 2),
            ]);
            $v->update(['status' => 'available', 'odometer' => $mileage ?? $v->odometer]);
        });
        ActivityLogger::log('rental', 'return', "Car rental {$r->reference} returned (".($lines ? implode(', ', $lines) : 'no adjustments').")", $r);

        return $r->fresh();
    }

    public static function cancel(CarRental $r, ?string $reason, User $u): CarRental
    {
        if (! in_array($r->status, ['reserved', 'picked_up'], true)) {
            throw ValidationException::withMessages(['status' => ["{$r->reference} is {$r->statusLabel()} and cannot be cancelled."]]);
        }
        DB::transaction(function () use ($r, $reason) {
            $r->update(['status' => 'cancelled', 'notes' => trim(($r->notes ? $r->notes."\n" : '').'Cancelled'.($reason ? ': '.$reason : ''))]);
            $r->vehicle->update(['status' => 'available']);
        });
        ActivityLogger::log('rental', 'cancel', "Car rental {$r->reference} cancelled", $r);

        return $r->fresh();
    }

    /** Post the rental total (plus adjustments) to an in-house stay folio. */
    public static function chargeToFolio(CarRental $r): \App\Models\FolioTransaction
    {
        $stay = $r->stay;
        if (! $stay) {
            throw ValidationException::withMessages(['reservation_room_id' => ['This rental is not linked to a stay.']]);
        }
        $folio = FolioService::openForStay($stay);
        $tx = FolioService::charge($folio, 'transport', "Car rental {$r->reference} ({$r->vehicle->reg_no})", (float) $r->total, true,
            ['source_type' => 'car_rental', 'source_id' => $r->id]);
        ActivityLogger::log('rental', 'bill', "Car rental {$r->reference} billed to the folio ({$r->total})", $r);

        return $tx;
    }

    /** Desk snapshot: today's pickups/returns + fleet state. */
    public static function snapshot(): array
    {
        return [
            'due_out' => CarRental::with('vehicle')->where('status', 'reserved')->where('pickup_at', '<=', now()->endOfDay())->orderBy('pickup_at')->get(),
            'due_in' => CarRental::with('vehicle')->where('status', 'picked_up')->where('return_due_at', '<=', now()->addDays(2))->orderBy('return_due_at')->get(),
            'fleet' => RentalVehicle::orderBy('class')->orderBy('make')->get(),
        ];
    }
}
