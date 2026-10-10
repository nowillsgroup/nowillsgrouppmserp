<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\ReservationRoom;
use App\Models\StayFixedCharge;
use Carbon\Carbon;

/**
 * Fixed charges / packages attached to a stay (breakfast, parking, extra bed, a one-off pickup…).
 * Posted together with each night's room tariff, so the night audit, late check-outs and day-use
 * stays are all covered. Idempotent per (charge, night): a voided posting is treated as waived and
 * is NOT re-posted.
 */
class FixedChargeService
{
    public static function postForNight(ReservationRoom $stay, Folio $folio, Carbon $night): int
    {
        $key = $night->toDateString();
        $posted = 0;
        foreach (StayFixedCharge::where('reservation_room_id', $stay->id)->where('is_active', true)->get() as $c) {
            if ($night->lt($c->start_date) || ($c->end_date && $night->gt($c->end_date))) {
                continue;
            }
            if ($c->frequency === 'once' && $c->times_posted > 0) {
                continue;
            }
            $done = $folio->transactions()->where('source_type', 'fixed')->where('source_id', $c->id)
                ->when($c->frequency === 'daily', fn ($q) => $q->where('reference', $key))->exists();
            if ($done) {
                continue;
            }
            FolioService::charge($folio, $c->category, $c->description.($c->frequency === 'daily' ? " ({$night->format('d M')})" : ''),
                (float) $c->amount, $c->apply_tax, ['source_type' => 'fixed', 'source_id' => $c->id, 'reference' => $key]);
            $c->forceFill(['last_posted_date' => $key, 'times_posted' => $c->times_posted + 1])->save();
            $posted++;
        }

        return $posted;
    }

    /** At checkout: post active one-off charges that started on or before $until but were never posted. */
    public static function postPendingOnce(ReservationRoom $stay, Folio $folio, Carbon $until): int
    {
        $n = 0;
        foreach (StayFixedCharge::where('reservation_room_id', $stay->id)->where('is_active', true)->where('frequency', 'once')
            ->where('times_posted', 0)->whereDate('start_date', '<=', $until)->get() as $c) {
            if ($folio->status !== 'open' || $folio->transactions()->where('source_type', 'fixed')->where('source_id', $c->id)->exists()) {
                continue;
            }
            FolioService::charge($folio, $c->category, $c->description, (float) $c->amount, $c->apply_tax, ['source_type' => 'fixed', 'source_id' => $c->id, 'reference' => $until->toDateString()]);
            $c->forceFill(['last_posted_date' => $until->toDateString(), 'times_posted' => 1])->save();
            $n++;
        }

        return $n;
    }

    /** Expected total for the remaining stay (for the folio preview). */
    public static function projected(StayFixedCharge $c, ReservationRoom $stay): float
    {
        if ($c->frequency === 'once') {
            return $c->times_posted ? 0 : (float) $c->amount;
        }
        $from = $c->start_date->copy()->max(bdate())->max($stay->arrival_date);
        $to = $stay->departure_date->copy();
        if ($c->end_date) {
            $to = $to->min($c->end_date->copy()->addDay());
        }

        return max(0, $from->diffInDays($to, false)) * (float) $c->amount;
    }
}
