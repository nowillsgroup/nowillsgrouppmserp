<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Cancellation / no-show retention charges driven by the property policy settings:
 *   cancel_free_hours   – free cancellation until N hours before check-in time on arrival day
 *   cancel_fee_type     – none | first_night | percent | fixed   (+ cancel_fee_value)
 *   noshow_fee_type     – none | first_night | percent | fixed   (+ noshow_fee_value)
 * The fee is posted as a 'cancellation' charge on the reservation's advance folio, so any advance
 * already received is netted automatically and the balance is refunded or collected from that folio.
 */
class CancellationService
{
    public const TYPES = ['none' => 'No charge', 'first_night' => 'First night tariff', 'percent' => '% of total stay tariff', 'fixed' => 'Fixed amount per room'];

    /**
     * @param  Collection  $stays  reservation rooms being cancelled / marked no-show
     * @return array{amount: float, basis: string, free_until: ?Carbon}
     */
    public static function quote(Reservation $r, Collection $stays, string $kind = 'cancel', ?Carbon $at = null): array
    {
        $at ??= now();
        $prefix = $kind === 'noshow' ? 'noshow' : 'cancel';
        $type = setting("{$prefix}_fee_type", $kind === 'noshow' ? 'first_night' : 'none');
        $value = (float) setting("{$prefix}_fee_value", 0);
        $freeUntil = null;
        if ($type === 'none' || ! isset(self::TYPES[$type]) || $stays->isEmpty()) {
            return ['amount' => 0.0, 'basis' => 'No charge as per policy', 'free_until' => null];
        }
        if ($r->status === 'tentative') {
            return ['amount' => 0.0, 'basis' => 'Tentative booking: no charge', 'free_until' => null];
        }
        if ($kind === 'cancel') {
            $hotel = hotel();
            $checkin = Carbon::parse($r->arrival_date->toDateString().' '.($hotel->checkin_time ?? '12:00:00'), config('app.timezone'));
            $freeUntil = $checkin->copy()->subHours((int) setting('cancel_free_hours', 48));
            if ($r->rate_contract_id && ($rc = $r->rateContract)) { // v2.7: negotiated terms replace the house policy
                $freeUntil = $checkin->copy()->subDays((int) $rc->cancel_days);
            }
            if ($at->lt($freeUntil)) {
                return ['amount' => 0.0, 'basis' => 'Free cancellation until '.$freeUntil->format('d M Y H:i').($r->rate_contract_id ? ' (contract '.$r->rateContract->code.')' : ''), 'free_until' => $freeUntil];
            }
        }
        $amount = $stays->sum(fn ($s) => match ($type) {
            'first_night' => (float) $s->rate,
            'percent' => round((float) $s->rate * max(1, $s->nights) * $value / 100, 2),
            'fixed' => $value,
            default => 0,
        });
        $basis = match ($type) {
            'first_night' => 'First night tariff × '.$stays->count().' room(s)',
            'percent' => rtrim(rtrim(number_format($value, 2), '0'), '.').'% of stay tariff',
            'fixed' => money($value).' × '.$stays->count().' room(s)',
        };

        return ['amount' => round($amount, 2), 'basis' => ($kind === 'cancel' ? 'Late cancellation: ' : 'No-show: ').$basis, 'free_until' => $freeUntil];
    }

    /**
     * Cancel reserved room lines (all of them when $stays is null) and post the late-cancellation fee unless waived.
     * Used by the reservation screen and the REST API. Returns [cancelled stays, fee posted, quote].
     */
    public static function cancel(Reservation $r, ?Collection $stays, string $reason, bool $waive = false, ?string $waiveReason = null, string $via = ''): array
    {
        $fee = 0.0;
        $effective = collect();
        $quote = [];
        \Illuminate\Support\Facades\DB::transaction(function () use ($r, $stays, $reason, $waive, $waiveReason, $via, &$fee, &$effective, &$quote) {
            $r = Reservation::whereKey($r->id)->lockForUpdate()->firstOrFail();
            $ids = $stays?->pluck('id')->filter()->all();
            $effective = $r->rooms()->where('status', 'reserved')->when($stays !== null, fn ($q) => $q->whereIn('id', $ids ?: [-1]))->lockForUpdate()->get();
            if ($effective->isEmpty()) {
                throw new \RuntimeException('Nothing to cancel – no room on this reservation is still pending arrival.');
            }
            $quote = self::quote($r, $effective, 'cancel');
            $effectiveIds = $effective->pluck('id');
            \App\Models\ReservationRoom::whereIn('id', $effectiveIds)->update(['status' => 'cancelled']);
            UpsellService::closeForStays($effectiveIds, 'Reservation cancelled');   // v4.1
            if (! $r->rooms()->whereNotIn('status', ['cancelled'])->exists()) {
                $r->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now()]);
            }
            ActivityLogger::log('reservations', 'cancel', 'Cancelled '.$effective->count()." room(s) on {$r->reservation_no}{$via}: {$reason}", $r);
            if ($quote['amount'] > 0) {
                if ($waive) {
                    ActivityLogger::log('reservations', 'cancel_fee_waived', 'Waived cancellation fee '.money($quote['amount']).": {$waiveReason}", $r);
                } else {
                    self::post($r, $quote['amount'], 'Cancellation fee');
                    $fee = $quote['amount'];
                }
            }
        });

        return [$effective, $fee, $quote];
    }

    /** Post the retention charge on the reservation's advance (standalone) folio. */
    public static function post(Reservation $r, float $amount, string $label): ?FolioTransaction
    {
        if ($amount <= 0) {
            return null;
        }
        $folio = Folio::where('reservation_id', $r->id)->whereNull('reservation_room_id')->where('status', 'open')->first()
            ?? FolioService::openStandalone(['reservation_id' => $r->id, 'guest_id' => $r->guest_id, 'type' => 'guest',
                'company_id' => $r->billing_instruction === 'company' ? $r->company_id : null]);
        $tx = FolioService::charge($folio, 'cancellation', $label.' – '.$r->reservation_no, $amount, true);
        $r->forceFill(['cancellation_fee' => round((float) $r->cancellation_fee + $amount, 2)])->save();
        ActivityLogger::log('reservations', 'cancel_fee', $label.' of '.money($amount).' posted to folio '.$folio->folio_no, $r);

        return $tx;
    }
}
