<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Guest;
use App\Models\LoyaltyEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guest loyalty programme.
 *
 * Earn:   when a guest folio is closed, 1 point per `loyalty_spend_per_point` of net spend
 *         (charges before tax, less discounts). Re-closing a reopened folio only earns the delta.
 * Redeem: points are converted at `loyalty_point_value` into a folio discount (source_type = loyalty).
 *         Voiding that discount refunds the points.
 */
class LoyaltyService
{
    public static function enabled(): bool
    {
        return setting('loyalty_enabled', '0') === '1';
    }

    public static function spendPerPoint(): float
    {
        return max(1, (float) setting('loyalty_spend_per_point', '100'));
    }

    public static function pointValue(): float
    {
        return max(0, (float) setting('loyalty_point_value', '1'));
    }

    public static function minRedeem(): int
    {
        return max(1, (int) setting('loyalty_min_redeem', '100'));
    }

    /** Net spend that qualifies for points (pre-tax charges minus discounts/allowances). */
    public static function eligibleSpend(Folio $folio): float
    {
        $tx = $folio->activeTransactions()->get();

        return max(0, $tx->where('type', 'charge')->sum('amount') - $tx->whereIn('type', ['discount', 'allowance'])->sum('amount'));
    }

    public static function earn(Folio $folio): int
    {
        if (! self::enabled() || ! $folio->guest_id || ! in_array($folio->type, ['guest', 'master', 'walk_in'], true)) {
            return 0;
        }

        return DB::transaction(function () use ($folio) {
            $due = (int) floor(self::eligibleSpend($folio) / self::spendPerPoint());
            $already = (int) LoyaltyEntry::where('folio_id', $folio->id)->where('type', 'earn')->sum('points');
            $pts = $due - $already;
            if ($pts <= 0) {
                return 0;
            }
            $guest = Guest::lockForUpdate()->findOrFail($folio->guest_id);
            LoyaltyEntry::create(['type' => 'earn', 'guest_id' => $guest->id, 'folio_id' => $folio->id, 'points' => $pts, 'user_id' => auth()->id(),
                'description' => "Earned on folio {$folio->folio_no}".($folio->invoice_no ? " / invoice {$folio->invoice_no}" : '')]);
            $guest->increment('loyalty_points', $pts);

            return $pts;
        });
    }

    public static function redeem(Folio $folio, int $points): FolioTransaction
    {
        if (! self::enabled()) {
            throw new RuntimeException('The loyalty programme is not enabled.');
        }
        if (! $folio->guest_id) {
            throw new RuntimeException('This folio has no guest profile.');
        }
        if ($points < self::minRedeem()) {
            throw new RuntimeException('Minimum redemption is '.self::minRedeem().' points.');
        }

        return DB::transaction(function () use ($folio, $points) {
            $guest = Guest::lockForUpdate()->findOrFail($folio->guest_id);
            if ($guest->loyalty_points < $points) {
                throw new RuntimeException("{$guest->full_name} has only {$guest->loyalty_points} points.");
            }
            $value = round($points * self::pointValue(), 2);
            if ($value > $folio->balance() + 0.009) {
                throw new RuntimeException('Redemption value '.money($value).' exceeds the folio balance '.money($folio->balance()).'.');
            }
            $tx = FolioService::discount($folio, $value, "Loyalty redemption ({$points} pts)");
            $tx->update(['source_type' => 'loyalty', 'source_id' => $points]);
            LoyaltyEntry::create(['type' => 'redeem', 'guest_id' => $guest->id, 'folio_id' => $folio->id, 'points' => -$points, 'user_id' => auth()->id(),
                'description' => "Redeemed on folio {$folio->folio_no} for ".money($value)]);
            $guest->decrement('loyalty_points', $points);

            return $tx;
        });
    }

    /** Called when a loyalty discount is voided – give the points back. */
    public static function refund(FolioTransaction $tx): void
    {
        $folio = $tx->folio;
        if ($tx->source_type !== 'loyalty' || ! $folio?->guest_id) {
            return;
        }
        $pts = (int) $tx->source_id;
        LoyaltyEntry::create(['type' => 'refund', 'guest_id' => $folio->guest_id, 'folio_id' => $folio->id, 'points' => $pts, 'user_id' => auth()->id(),
            'description' => "Redemption voided on folio {$folio->folio_no}"]);
        Guest::whereKey($folio->guest_id)->increment('loyalty_points', $pts);
    }

    /** Manual adjustment (goodwill / correction). */
    public static function adjust(Guest $guest, int $points, string $reason): void
    {
        DB::transaction(function () use ($guest, $points, $reason) {
            $g = Guest::lockForUpdate()->findOrFail($guest->id);
            if ($g->loyalty_points + $points < 0) {
                throw new RuntimeException("Adjustment would make the balance negative ({$g->loyalty_points} available).");
            }
            LoyaltyEntry::create(['type' => 'adjust', 'guest_id' => $g->id, 'points' => $points, 'user_id' => auth()->id(), 'description' => 'Manual adjustment: '.$reason]);
            $g->increment('loyalty_points', $points);
        });
    }

    public static function tier(int $points): string
    {
        return match (true) {
            $points >= 5000 => 'Platinum', $points >= 2000 => 'Gold', $points >= 500 => 'Silver', default => 'Member',
        };
    }
}
