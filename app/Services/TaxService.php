<?php

namespace App\Services;

use App\Models\Tax;
use Illuminate\Support\Collection;

/**
 * Tax engine – Ghana (VAT Act 2025, Act 1151, effective 1 Jan 2026; Tourism Act 2011, Act 817 / L.I. 2185):
 *
 *   VAT 15% + NHIL 2.5% + GETFund levy 2.5%  → all on the SAME tax-exclusive base (no cascading) – collected for GRA
 *   Tourism Levy 1%                           → on the same net value, charged separately – collected for GTA (Tourism Development Fund)
 *
 * e.g. room night GH₵ 1,000.00 → VAT 150.00 + NHIL 25.00 + GETFund 25.00 + Tourism Levy 10.00 = GH₵ 1,210.00.
 *
 * Folio / POS lines store ONE combined tax amount and rate (sum of the active components). breakdown() splits
 * that amount into its statutory components for invoices, returns and the ledger (GRA vs GTA liability).
 * Tax rows stay configurable (Masters › Taxes): applies_to (room / fnb / service / all) and optional tariff slabs.
 */
class TaxService
{
    /** Statutory components: code => [label, authority, default rate]. */
    public const COMPONENTS = [
        'VAT' => ['VAT', 'GRA', 15.0],
        'NHIL' => ['NHIL', 'GRA', 2.5],
        'GETFUND' => ['GETFund levy', 'GRA', 2.5],
        'TL' => ['Tourism levy', 'GTA', 1.0],
    ];

    public const AUTHORITIES = ['GRA' => 'Ghana Revenue Authority', 'GTA' => 'Ghana Tourism Authority'];

    /** Per-process cache of component rates; flushed whenever a tax row changes (Tax model events). */
    protected static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Active tax rows applying to a category group (room / fnb / service) at this amount (slab-aware). */
    public static function applicable(string $appliesTo, ?float $amount = null): Collection
    {
        return Tax::where('is_active', true)->whereIn('applies_to', [$appliesTo, 'all'])->orderBy('id')->get()
            ->filter(fn ($t) => $amount === null || ($amount >= (float) $t->slab_from && ($t->slab_to === null || $amount <= (float) $t->slab_to)))->values();
    }

    public static function rateFor(string $appliesTo, float $amount): float
    {
        return round((float) self::applicable($appliesTo, $amount)->sum('rate'), 4);
    }

    /** v7.39 Tax-settings switch: are entered prices tax-inclusive? Default: exclusive (tax added on top). */
    public static function pricesInclusive(): bool
    {
        return setting('tax_prices_inclusive', '0') === '1';
    }

    /**
     * Split an entered price into net + tax under the current mode.
     * Exclusive: the entered figure is the net and tax is added on top.
     * Inclusive: the entered figure is the gross; VAT/NHIL/GETFund/levy are extracted from it.
     */
    public static function splitPrice(float $entered, ?float $rate = null, string $category = 'other'): array
    {
        $rate ??= (float) self::forCategory($category, abs($entered))['rate'];
        if (! self::pricesInclusive()) {
            $tax = round($entered * $rate / 100, 2);

            return ['rate' => $rate, 'net' => round($entered, 2), 'tax' => $tax, 'total' => round($entered + $tax, 2)];
        }
        $net = round($entered / (1 + $rate / 100), 2);

        return ['rate' => $rate, 'net' => $net, 'tax' => round($entered - $net, 2), 'total' => round($entered, 2)];
    }

    public static function roomTax(float $nightlyRate): array
    {
        $rate = self::rateFor('room', $nightlyRate);

        return ['rate' => $rate, 'amount' => round($nightlyRate * $rate / 100, 2)];
    }

    public static function categoryMap(): array
    {
        return ['room' => 'room', 'extra_bed' => 'room', 'fnb' => 'fnb', 'minibar' => 'fnb', 'banquet' => 'fnb'];
    }

    public static function groupFor(?string $category): string
    {
        return self::categoryMap()[(string) $category] ?? 'service';
    }

    public static function forCategory(string $category, float $amount): array
    {
        $rate = self::rateFor(self::groupFor($category), $amount);

        return ['rate' => $rate, 'amount' => round($amount * $rate / 100, 2)];
    }

    /** Statutory code of a tax row (rows created by hand without a code are treated as VAT). */
    public static function codeOf(Tax $t): string
    {
        return $t->code && isset(self::COMPONENTS[$t->code]) ? $t->code : 'VAT';
    }

    /**
     * Component rates for a category: [code => rate]. Uses the active tax setup; falls back to the statutory
     * defaults when nothing is configured (so historic documents still split sensibly).
     */
    public static function componentRates(?string $category = null, ?float $amount = null): array
    {
        $key = (hotel()?->id).'|'.self::groupFor($category).'|'.($amount === null ? '' : round($amount, 2));
        $cache = &self::$cache;
        if (! isset($cache[$key])) {
            $rates = [];
            foreach (self::applicable(self::groupFor($category), $amount) as $t) {
                $c = self::codeOf($t);
                $rates[$c] = ($rates[$c] ?? 0) + (float) $t->rate;
            }
            $cache[$key] = $rates;
        }

        return $cache[$key];
    }

    /**
     * Split a combined tax amount into its components: [code => amount] (VAT, NHIL, GETFUND, TL – zero when not charged).
     * Proportional to the component rates; the rounding remainder goes to VAT so the parts always add up exactly.
     * $rate (the combined rate stored on the line) is used to pick the components that applied: if it no longer matches
     * the current setup (e.g. a component was switched off later) the statutory defaults whose sum matches are used.
     */
    public static function breakdown(float $tax, ?string $category = null, ?float $rate = null): array
    {
        $out = array_fill_keys(array_keys(self::COMPONENTS), 0.0);
        if (abs($tax) < 0.005) {
            return $out;
        }
        $rates = self::componentRates($category);
        $sum = array_sum($rates);
        if ($rate !== null && $rate > 0 && abs($sum - $rate) > 0.01) {
            $std = array_map(fn ($c) => $c[2], self::COMPONENTS);
            $noTl = array_diff_key($std, ['TL' => 1]);
            $rates = match (true) {
                abs(array_sum($std) - $rate) < 0.01 => $std,
                abs(array_sum($noTl) - $rate) < 0.01 => $noTl,
                default => $rates ?: ['VAT' => $rate],
            };
            $sum = array_sum($rates);
        }
        if ($sum <= 0) {
            $out['VAT'] = round($tax, 2);

            return $out;
        }
        foreach ($rates as $c => $r) {
            $out[$c] = round($tax * $r / $sum, 2);
        }
        $out['VAT'] = round($out['VAT'] + round($tax, 2) - array_sum($out), 2);

        return $out;
    }

    /** Tourism Levy part of a combined tax amount (goes to the GTA liability; the rest is GRA output tax). */
    public static function levyShare(float $tax, ?string $category = null, ?float $rate = null): float
    {
        return self::breakdown($tax, $category, $rate)['TL'];
    }

    /** Sum breakdowns of many [tax, category, rate] rows. */
    public static function sumBreakdowns(iterable $rows): array
    {
        $out = array_fill_keys(array_keys(self::COMPONENTS), 0.0);
        foreach ($rows as [$tax, $cat, $rate]) {
            foreach (self::breakdown((float) $tax, $cat, $rate !== null ? (float) $rate : null) as $c => $a) {
                $out[$c] = round($out[$c] + $a, 2);
            }
        }

        return $out;
    }

    /** Labels for display: [code => 'VAT 15%', …] using the configured (or default) rates. */
    public static function labels(?string $category = null): array
    {
        $rates = self::componentRates($category) + array_map(fn ($c) => $c[2], self::COMPONENTS);
        $fmt = fn ($r) => rtrim(rtrim(number_format((float) $r, 2), '0'), '.');

        return collect(self::COMPONENTS)->mapWithKeys(fn ($c, $code) => [$code => $c[0].' '.$fmt($rates[$code]).'%'])->all();
    }

    /** Default Ghana tax rows for a property. */
    public static function defaultRows(): array
    {
        return [
            ['name' => 'VAT', 'code' => 'VAT', 'authority' => 'GRA', 'rate' => 15, 'applies_to' => 'all'],
            ['name' => 'NHIL', 'code' => 'NHIL', 'authority' => 'GRA', 'rate' => 2.5, 'applies_to' => 'all'],
            ['name' => 'GETFund levy', 'code' => 'GETFUND', 'authority' => 'GRA', 'rate' => 2.5, 'applies_to' => 'all'],
            ['name' => 'Tourism levy', 'code' => 'TL', 'authority' => 'GTA', 'rate' => 1, 'applies_to' => 'all'],
        ];
    }
}
