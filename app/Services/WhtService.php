<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\WhtPeriod;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v4.4 Ghana withholding tax on supplier payments (Income Tax Act 2015, Act 896, as amended).
 *
 * A resident business paying a resident supplier withholds tax at source and pays it to the GRA:
 *   goods 3 %, works 5 %, services 7.5 % – once payments to that supplier exceed GH₵ 2,000 in the year;
 *   rent 8 % (residential) / 15 % (commercial) and non-resident suppliers 20 % – regardless of the amount.
 * Base = the VAT-exclusive value paid. Suppliers holding a GRA exemption certificate are not withheld while it is valid.
 * Optional: appointed withholding VAT agents also withhold 7 % of the taxable value on standard-rated supplies.
 *
 * For a payment in the property's own currency, `amount` is what is cleared from the supplier's account (the bills it
 * settles); the supplier receives amount − WHT − withholding VAT, with the rest owed to GRA. For a v7.54 foreign
 * transfer, `amount` and `netPaid()` are the GHS equivalent of the actual net amount the bank transferred, while the
 * WHT base is the GHS bill allocation; tax is still a separate GHS payable and the realised exchange difference
 * balances the journal:
 *   Dr Accounts Payable / Exchange Gain or Loss / Cr bank / Cr 2250 WHT Payable / Cr 2255 Withholding VAT Payable.
 * Each withheld payment gets a numbered WHT credit certificate for the supplier. The monthly return lists them and
 * is due by the 15th of the following month; marking it as filed locks that month's withheld payments.
 */
class WhtService
{
    public const CATEGORIES = [
        'none' => ['Not subject to WHT', 0.0, false],
        'goods' => ['Supply of goods', 3.0, true],
        'works' => ['Supply of works', 5.0, true],
        'services' => ['Supply of services', 7.5, true],
        'rent_res' => ['Rent – residential property', 8.0, false],
        'rent_com' => ['Rent – commercial property', 15.0, false],
        'nonresident' => ['Non-resident supplier (goods, works, services)', 20.0, false],
    ]; // [label, rate %, subject to the annual threshold]

    public const DEFAULTS = ['wht_enabled' => '1', 'wht_threshold' => '2000', 'wvat_agent' => '0', 'wvat_rate' => '7'];

    public static function cfg(string $k): string
    {
        return (string) setting($k, self::DEFAULTS[$k]);
    }

    public static function enabled(): bool
    {
        return GstService::isGhana() && self::cfg('wht_enabled') === '1';
    }

    public static function label(?string $cat): string
    {
        return self::CATEGORIES[$cat ?? 'none'][0] ?? (string) $cat;
    }

    public static function options(): array
    {
        return collect(self::CATEGORIES)->map(fn ($c, $k) => $c[0].($c[1] > 0 ? ' – '.rtrim(rtrim(number_format($c[1], 1), '0'), '.').'%' : ''))->all();
    }

    public static function exempt(Supplier $s, ?string $date = null): bool
    {
        return $s->wht_exempt_ref && (! $s->wht_exempt_until || $s->wht_exempt_until->toDateString() >= ($date ?? bdate()->toDateString()));
    }

    /** VAT-exclusive share of a bill (what WHT is calculated on) and its standard-rated taxable share (withholding VAT). */
    protected static function ratios(SupplierInvoice $b): array
    {
        $total = (float) $b->total;
        if ($total <= 0) {
            return [1.0, 0.0];
        }
        $ex = min(1.0, max(0.0, (float) $b->subtotal / $total));

        return [$ex, (float) $b->tax > 0 ? $ex : 0.0];
    }

    /** Value paid to the supplier so far this calendar year, VAT-exclusive (payments not cancelled). */
    public static function yearToDate(Supplier $s, string $date, ?int $exceptPaymentId = null): float
    {
        $y = substr($date, 0, 4);

        return round((float) SupplierPayment::where('supplier_id', $s->id)->whereNull('cancelled_at')->whereDate('date', '>=', "$y-01-01")->whereDate('date', '<=', $date)
            ->when($exceptPaymentId, fn ($q) => $q->where('id', '!=', $exceptPaymentId))->sum('wht_base'), 2);
    }

    /**
     * What to withhold on a payment of $amount (gross) with $alloc = [billId => amount].
     * Unallocated money (advance / on account) is treated as VAT-exclusive.
     * $apply = false: the user chose not to withhold (needs $reason).
     */
    public static function quote(Supplier $s, float $amount, array $alloc, string $date, bool $apply = true, ?string $reason = null): array
    {
        $amount = round($amount, 2);
        $bills = SupplierInvoice::whereIn('id', array_keys($alloc))->get()->keyBy('id');
        $base = 0.0;
        $wvBase = 0.0;
        foreach ($alloc as $id => $amt) {
            [$ex, $std] = ($b = $bills->get($id)) ? self::ratios($b) : [1.0, 0.0];
            $base += (float) $amt * $ex;
            $wvBase += (float) $amt * $std;
        }
        $base = round($base + max(0, $amount - array_sum($alloc)), 2);
        $wvBase = round($wvBase, 2);
        $cat = $s->wht_category ?: 'none';
        [$label, $rate, $threshold] = self::CATEGORIES[$cat] ?? self::CATEGORIES['none'];
        $ytd = self::yearToDate($s, $date);
        $limit = (float) self::cfg('wht_threshold');
        $note = null;
        if (! self::enabled()) {
            $rate = 0.0;
            $note = 'Withholding tax is switched off.';
        } elseif ($rate <= 0) {
            $note = 'Supplier is not subject to withholding tax.';
        } elseif (self::exempt($s, $date)) {
            $rate = 0.0;
            $note = "GRA exemption certificate {$s->wht_exempt_ref}".($s->wht_exempt_until ? ' (valid to '.$s->wht_exempt_until->format('d M Y').')' : '').'.';
        } elseif ($threshold && round($ytd + $base, 2) <= $limit) {
            $rate = 0.0;
            $note = 'Payments this year ('.money($ytd + $base).') do not exceed the '.money($limit).' threshold.';
        } elseif (! $apply) {
            $rate = 0.0;
            $note = 'Not withheld: '.trim((string) $reason);
        }
        $wht = round($base * $rate / 100, 2);
        $wvat = self::enabled() && self::cfg('wvat_agent') === '1' && $wvBase > 0 && ! $s->isNonResident() ? round($wvBase * (float) self::cfg('wvat_rate') / 100, 2) : 0.0;

        return ['category' => $cat, 'label' => $label, 'rate' => $rate, 'base' => $base, 'wht' => $wht, 'wvat_base' => $wvat > 0 ? $wvBase : 0.0, 'wvat' => $wvat,
            'net' => round($amount - $wht - $wvat, 2), 'ytd' => $ytd, 'threshold' => $limit, 'note' => $note,
            'overridable' => self::enabled() && $apply && $wht > 0];
    }

    /** Called by ApService::pay inside its transaction. */
    public static function stamp(SupplierPayment $p, array $q): void
    {
        if ($q['wht'] + $q['wvat'] >= $p->amount) {
            throw new RuntimeException('The tax withheld cannot be the whole payment.');
        }
        if ($q['wht'] + $q['wvat'] > 0 && self::isFiled(substr($p->date->toDateString(), 0, 7))) {
            throw new RuntimeException('The withholding tax return for '.$p->date->format('M Y').' has been filed – date the payment in an open month.');
        }
        $p->forceFill(['wht_category' => $q['category'], 'wht_rate' => $q['rate'], 'wht_base' => $q['base'], 'wht_amount' => $q['wht'],
            'wvat_base' => $q['wvat_base'], 'wvat_amount' => $q['wvat'], 'wht_note' => $q['note'] ? mb_substr($q['note'], 0, 255) : null,
            'wht_cert_no' => $q['wht'] + $q['wvat'] > 0 ? Numbering::next('wht_cert') : null])->save();
    }

    public static function isFiled(string $period): bool
    {
        return WhtPeriod::where('period', $period)->exists();
    }

    public static function assertCancellable(SupplierPayment $p): void
    {
        if ((float) $p->wht_amount + (float) $p->wvat_amount > 0 && self::isFiled($p->date->format('Y-m'))) {
            throw new RuntimeException("Tax withheld on {$p->payment_no} was declared in the filed WHT return for ".$p->date->format('M Y').' – it cannot be cancelled. Record a correction with GRA instead.');
        }
    }

    // ─────────────────────────── return ───────────────────────────

    public static function due(string $period): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $period.'-15')->addMonth();
    }

    /** Payments of the month that withheld tax, with totals by category. */
    public static function monthReturn(string $period): array
    {
        $rows = SupplierPayment::with('supplier:id,name,tax_number', 'paymentMode:id,name')->whereNull('cancelled_at')->where('date', 'like', $period.'%')
            ->where(fn ($q) => $q->where('wht_amount', '>', 0)->orWhere('wvat_amount', '>', 0))->orderBy('date')->orderBy('id')->get();
        $cats = $rows->where('wht_amount', '>', 0)->groupBy('wht_category')->map(fn ($g, $c) => ['category' => $c, 'label' => self::label($c), 'rate' => (float) $g->first()->wht_rate,
            'n' => $g->count(), 'base' => round($g->sum('wht_base'), 2), 'wht' => round($g->sum('wht_amount'), 2)])->sortBy('rate')->values();
        $gl = ($h = \App\Models\AccountHead::sys('wht_payable')) ? round(-(float) \App\Models\GlLine::where('account_head_id', $h)->where('date', 'like', $period.'%')->sum(DB::raw('debit - credit')), 2) : null;

        return ['period' => $period, 'rows' => $rows, 'cats' => $cats, 'wht' => round($rows->sum('wht_amount'), 2), 'wvat' => round($rows->sum('wvat_amount'), 2),
            'base' => round($rows->sum('wht_base'), 2), 'due' => self::due($period), 'filing' => WhtPeriod::with('filer')->where('period', $period)->first(), 'gl' => $gl,
            'missing_tin' => $rows->filter(fn ($p) => ! GstService::validTin($p->supplier?->tax_number))->pluck('supplier.name')->unique()->values()];
    }

    public static function file(string $period, string $reference, string $filedOn): WhtPeriod
    {
        if ($period >= bdate()->format('Y-m')) {
            throw new RuntimeException('Only a completed month can be filed.');
        }
        if (self::isFiled($period)) {
            throw new RuntimeException('That month is already filed.');
        }
        $r = self::monthReturn($period);
        $f = WhtPeriod::create(['period' => $period, 'reference' => $reference, 'filed_on' => $filedOn, 'filed_by' => auth()->id(),
            'summary' => ['wht' => $r['wht'], 'wvat' => $r['wvat'], 'base' => $r['base'], 'payments' => $r['rows']->count()]]);
        ActivityLogger::log('ap', 'wht_file', "WHT return {$period} filed ({$reference}): WHT ".money($r['wht']).($r['wvat'] > 0 ? ', withholding VAT '.money($r['wvat']) : ''), $f);

        return $f;
    }

    /** The last completed month that withheld tax but is not filed (for alerts). */
    public static function alert(): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $prev = bdate()->copy()->startOfMonth()->subMonth()->format('Y-m');
        if (self::isFiled($prev) || ! SupplierPayment::whereNull('cancelled_at')->where('date', 'like', $prev.'%')->where(fn ($q) => $q->where('wht_amount', '>', 0)->orWhere('wvat_amount', '>', 0))->exists()) {
            return null;
        }
        $due = self::due($prev);

        return ['period' => $prev, 'due' => $due, 'overdue' => bdate()->gt($due)];
    }

    public static function csv(string $period): Collection
    {
        return self::monthReturn($period)['rows']->map(fn ($p) => [
            'Payment date' => $p->date->toDateString(), 'Payment no' => $p->payment_no, 'Certificate no' => $p->wht_cert_no, 'Supplier' => $p->supplier?->name,
            'Supplier TIN' => $p->supplier?->tax_number, 'Nature of payment' => self::label($p->wht_category), 'Gross amount' => (float) $p->amount,
            'Taxable (VAT-exclusive)' => (float) $p->wht_base, 'WHT rate %' => (float) $p->wht_rate, 'WHT' => (float) $p->wht_amount,
            'Withholding VAT' => (float) $p->wvat_amount, 'Net paid' => round($p->amount - $p->wht_amount - $p->wvat_amount, 2),
        ]);
    }
}
