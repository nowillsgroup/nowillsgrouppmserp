<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Lease;
use App\Models\LeaseCharge;
use App\Models\LeasePayment;
use App\Models\PaymentMode;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.2 The tenant's account: invoices by tax class, credit notes, receipts with tax withheld by company tenants, refunds,
 * the security deposit (held as a liability, applied at move-out or refunded), oldest-due-first allocation, arrears,
 * the Rent Act advance-rent cap, and the ledger entries.
 */
class LeaseBillingService
{
    /**
     * Combined tax rate of a lease tax class. Serviced apartments are hotel accommodation (VAT + NHIL + GETFund + Tourism
     * Levy); commercial lets carry VAT + NHIL + GETFund but no Tourism Levy; residential tenancies are VAT-exempt.
     */
    public static function rateFor(string $class): float
    {
        if ($class === 'residential') {
            return 0.0;
        }
        $rates = TaxService::componentRates('room');
        if (! $rates) {
            $rates = collect(TaxService::COMPONENTS)->map(fn ($c) => $c[2])->all();
        }
        if ($class === 'commercial') {
            unset($rates['TL']);
        }

        return round(array_sum($rates), 3);
    }

    public static function tax(float $net, string $class, ?float $rate = null): array
    {
        $rate ??= self::rateFor($class);
        $tax = round($net * $rate / 100, 2);

        return ['rate' => $rate, 'tax' => $tax, 'total' => round($net + $tax, 2)];
    }

    /** Raise a charge. $o: date, period_start, period_end, due_date, credit_of_id, late_fee_of_id, tax_rate. */
    public static function raise(Lease $l, string $type, string $description, float $net, array $o = [], ?User $u = null): LeaseCharge
    {
        if (! array_key_exists($type, LeaseCharge::TYPES)) {
            throw new RuntimeException('Unknown charge type.');
        }
        if ($type !== 'credit' && $net <= 0) {
            throw new RuntimeException('The amount must be more than zero.');
        }
        $date = Carbon::parse($o['date'] ?? bdate())->toDateString();
        $t = self::tax(round($net, 2), $l->tax_class, $o['tax_rate'] ?? null);

        $c = LeaseCharge::create([
            'charge_no' => Numbering::next('lease_charge'),
            'lease_id' => $l->id,
            'date' => $date,
            'type' => $type,
            'description' => mb_substr($description, 0, 160),
            'period_start' => $o['period_start'] ?? null,
            'period_end' => $o['period_end'] ?? null,
            'amount' => round($net, 2),
            'tax_rate' => $t['rate'],
            'tax' => $t['tax'],
            'total' => $t['total'],
            'due_date' => $o['due_date'] ?? max($date, $o['period_start'] ?? $date),
            'credit_of_id' => $o['credit_of_id'] ?? null,
            'late_fee_of_id' => $o['late_fee_of_id'] ?? null,
            'user_id' => $u?->id,
        ]);
        self::reallocate($l);

        return $c->refresh();
    }

    public static function creditable(LeaseCharge $c): float
    {
        return round((float) $c->amount + (float) LeaseCharge::where('credit_of_id', $c->id)->whereNull('cancelled_at')->sum('amount'), 2);
    }

    /** Credit note against a charge (net; tax follows the original rate). */
    public static function creditNote(LeaseCharge $c, float $net, string $reason, ?User $u = null, ?string $date = null): LeaseCharge
    {
        if ($c->cancelled_at || $c->type === 'credit') {
            throw new RuntimeException('A credit note can only be raised against a live charge.');
        }
        $net = round($net, 2);
        $max = self::creditable($c);
        if ($net <= 0 || $net > $max + 0.004) {
            throw new RuntimeException('The credit must be between GH₵ 0.01 and '.money($max).' (the uncredited amount of '.$c->charge_no.').');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for the credit note.');
        }
        $cn = self::raise($c->lease, 'credit', 'Credit note on '.$c->charge_no.' – '.trim($reason), -$net,
            ['date' => $date, 'credit_of_id' => $c->id, 'tax_rate' => (float) $c->tax_rate, 'due_date' => $date ?? bdate()->toDateString(),
                'period_start' => $c->period_start?->toDateString(), 'period_end' => $c->period_end?->toDateString()], $u);
        ActivityLogger::log('leases', 'credit_note', "Credit note {$cn->charge_no} ".money(abs((float) $cn->total))." on {$c->charge_no}", $cn);

        return $cn;
    }

    /** Cancel a charge raised in error – only on the day it was raised (otherwise issue a credit note). */
    public static function cancelCharge(LeaseCharge $c, string $reason, ?User $u = null): void
    {
        if ($c->cancelled_at) {
            throw new RuntimeException('Already cancelled.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }
        if ($c->date->toDateString() !== bdate()->toDateString()) {
            throw new RuntimeException('Only a charge raised today can be cancelled – issue a credit note instead.');
        }
        if (LeaseCharge::where('credit_of_id', $c->id)->whereNull('cancelled_at')->exists()) {
            throw new RuntimeException('This charge has a credit note – withdraw the credit note first.');
        }
        DB::transaction(function () use ($c, $reason, $u) {
            $c->update(['cancelled_at' => now(), 'cancelled_by' => $u?->id, 'cancel_reason' => mb_substr(trim($reason), 0, 255), 'paid' => 0]);
            $l = $c->lease;
            // a cancelled rent invoice hands its period back to the billing run
            if (in_array($c->type, ['rent'], true) && $l->billed_to && $c->period_end && $l->billed_to->toDateString() === $c->period_end->toDateString()) {
                $l->update(['billed_to' => $c->period_start->copy()->subDay()->toDateString()]);
                LeaseCharge::where('lease_id', $l->id)->where('type', 'service_charge')->whereNull('cancelled_at')->where('date', $c->date)
                    ->where('period_start', $c->period_start)->update(['cancelled_at' => now(), 'cancelled_by' => $u?->id, 'cancel_reason' => 'With '.$c->charge_no, 'paid' => 0]);
            }
            self::reallocate($l);
        });
        ActivityLogger::log('leases', 'charge_cancelled', "Charge {$c->charge_no} cancelled – {$reason}", $c);
    }

    /** Most a residential tenant may be asked to pay ahead (Rent Act 1963, Act 220, s.25(5)); null = no cap. */
    public static function advanceCap(Lease $l): ?float
    {
        if ($l->tax_class !== 'residential' || ! (int) setting('lease_enforce_rent_cap', 1)) {
            return null;
        }
        $months = $l->term_months > 6 ? 6 : 1;

        return round($months * ((float) $l->rent + (float) $l->service_charge), 2);
    }

    /**
     * Money on a lease. $kind: payment (receipt – $wht is tax the tenant withheld, it settles the account too), refund
     * (of a credit balance), deposit (security deposit received), deposit_refund, deposit_applied (deposit used to
     * settle the account at move-out).
     */
    public static function pay(Lease $l, string $kind, float $amount, ?int $modeId, ?string $reference, ?User $u = null, ?string $date = null, float $wht = 0, ?string $whtCert = null, ?string $note = null): LeasePayment
    {
        $amount = round($amount, 2);
        $wht = round(max(0, $wht), 2);
        if (! array_key_exists($kind, LeasePayment::KINDS)) {
            throw new RuntimeException('Unknown receipt type.');
        }
        if ($amount <= 0 && ! ($kind === 'payment' && $wht > 0)) {
            throw new RuntimeException('The amount must be more than zero.');
        }
        if ($kind !== 'deposit_applied' && ! PaymentMode::whereKey((int) $modeId)->exists()) {
            throw new RuntimeException('Choose a payment mode.');
        }
        if ($l->status === 'draft' && ! in_array($kind, ['payment', 'deposit'], true)) {
            throw new RuntimeException('The lease has not started.');
        }
        if ($wht > 0 && $kind !== 'payment') {
            throw new RuntimeException('Tax withheld can only be recorded on a receipt.');
        }
        $bal = self::balance($l);
        $held = self::depositHeld($l);
        if ($kind === 'refund' && $amount > -$bal + 0.004) {
            throw new RuntimeException('Only a credit balance can be refunded – the account is in credit by '.money(max(0, -$bal)).'.');
        }
        if (in_array($kind, ['deposit_refund', 'deposit_applied'], true) && $amount > $held + 0.004) {
            throw new RuntimeException('Only '.money($held).' of deposit is held.');
        }
        if ($kind === 'deposit_applied' && $amount > $bal + 0.004) {
            throw new RuntimeException('Apply at most the balance due ('.money(max(0, $bal)).'); refund the rest of the deposit.');
        }
        if ($kind === 'payment' && ($cap = self::advanceCap($l)) !== null) {
            $credit = round(($amount + $wht) - $bal, 2);
            if ($credit > $cap + 0.004) {
                throw new RuntimeException('Refused: this receipt would leave '.money($credit).' paid in advance. The Rent Act 1963 (Act 220) s.25(5) caps advance rent on a '
                    .($l->term_months > 6 ? 'tenancy of more than 6 months at 6 months' : 'monthly tenancy at 1 month').' ('.money($cap).'). Receive at most '.money(max(0, $bal + $cap)).'.');
            }
        }
        if ($kind === 'deposit' && (float) $l->deposit_amount > 0 && $held + $amount > (float) $l->deposit_amount + 0.004) {
            throw new RuntimeException('The agreed deposit is '.money($l->deposit_amount).' and '.money($held).' is already held.');
        }

        $p = LeasePayment::create([
            'receipt_no' => Numbering::next('lease_receipt'),
            'lease_id' => $l->id,
            'kind' => $kind,
            'date' => Carbon::parse($date ?? bdate())->toDateString(),
            'payment_mode_id' => $kind === 'deposit_applied' ? null : $modeId,
            'amount' => $amount,
            'wht_amount' => $wht,
            'wht_cert_no' => $whtCert ? mb_substr($whtCert, 0, 40) : null,
            'reference' => $reference ? mb_substr($reference, 0, 80) : null,
            'note' => $note ? mb_substr($note, 0, 255) : null,
            'user_id' => $u?->id,
        ]);
        self::reallocate($l);
        ActivityLogger::log('leases', $kind, LeasePayment::KINDS[$kind]." {$p->receipt_no} ".money($amount).($wht > 0 ? ' + WHT '.money($wht) : '')." – {$l->lease_no}", $p);

        return $p->refresh();
    }

    public static function voidPayment(LeasePayment $p, string $reason, ?User $u = null): void
    {
        if ($p->voided_at) {
            throw new RuntimeException('Already voided.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }
        $later = LeasePayment::where('lease_id', $p->lease_id)->whereNull('voided_at')->where('id', '>', $p->id);
        if ($p->kind === 'payment' && (clone $later)->where('kind', 'refund')->exists()) {
            throw new RuntimeException('A later refund depends on this receipt – void the refund first.');
        }
        if ($p->kind === 'deposit' && self::depositHeld($p->lease) - (float) $p->amount < -0.004) {
            throw new RuntimeException('Part of this deposit has been applied or refunded – void those first.');
        }
        $p->update(['voided_at' => now(), 'voided_by' => $u?->id, 'void_reason' => mb_substr(trim($reason), 0, 255)]);
        self::reallocate($p->lease);
        ActivityLogger::log('leases', 'receipt_voided', "Receipt {$p->receipt_no} voided – {$reason}", $p);
    }

    /** Receipts, applied deposit and credit notes settle the live charges oldest-due first. */
    public static function reallocate(Lease $l): void
    {
        $charges = LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->where('type', '!=', 'credit')->orderBy('due_date')->orderBy('id')->get();
        $payments = LeasePayment::where('lease_id', $l->id)->whereNull('voided_at')->whereIn('kind', ['payment', 'deposit_applied', 'refund'])->orderBy('date')->orderBy('id')->get();
        $pool = [];
        foreach (LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->where('type', 'credit')->get() as $cn) {
            $pool[$cn->credit_of_id] = ($pool[$cn->credit_of_id] ?? 0) - (float) $cn->total;
        }
        $paid = [];
        $free = 0.0;
        foreach ($charges as $c) {
            $apply = min((float) $c->total, $pool[$c->id] ?? 0);
            $paid[$c->id] = $apply;
            $free += ($pool[$c->id] ?? 0) - $apply;
        }
        $alloc = [];
        $queue = $charges->filter(fn ($c) => (float) $c->total - $paid[$c->id] > 0.004)->values();
        $qi = 0;
        $use = function (float $amt, ?int $pid) use (&$queue, &$qi, &$paid, &$alloc) {
            while ($amt > 0.004 && $qi < $queue->count()) {
                $c = $queue[$qi];
                $take = min(round((float) $c->total - $paid[$c->id], 2), $amt);
                $paid[$c->id] = round($paid[$c->id] + $take, 2);
                $amt = round($amt - $take, 2);
                if ($pid) {
                    $alloc[$pid][] = ['charge_id' => $c->id, 'charge_no' => $c->charge_no, 'amount' => round($take, 2)];
                }
                if ($paid[$c->id] >= (float) $c->total - 0.004) {
                    $qi++;
                }
            }
        };
        $use(round($free, 2), null);
        $refunds = (float) $payments->where('kind', 'refund')->sum('amount');
        foreach ($payments->whereIn('kind', ['payment', 'deposit_applied']) as $p) {
            $amt = (float) $p->amount + (float) $p->wht_amount;
            $absorb = min($amt, $refunds);
            $refunds = round($refunds - $absorb, 2);
            $use(round($amt - $absorb, 2), $p->id);
        }
        foreach ($charges as $c) {
            $v = round($paid[$c->id], 2);
            if (abs($v - (float) $c->paid) > 0.004) {
                LeaseCharge::whereKey($c->id)->update(['paid' => $v]);
            }
        }
        foreach ($payments as $p) {
            $a = $alloc[$p->id] ?? [];
            if (json_encode($a) !== json_encode($p->allocations ?? [])) {
                LeasePayment::whereKey($p->id)->update(['allocations' => $a ? json_encode($a) : null]);
            }
        }
    }

    /** Positive = the tenant owes; negative = paid ahead / in credit. */
    public static function balance(Lease $l, ?string $asOf = null): float
    {
        $ch = LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))->sum('total');
        $pay = LeasePayment::where('lease_id', $l->id)->whereNull('voided_at')->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))
            ->selectRaw("SUM(CASE WHEN kind='payment' THEN amount + wht_amount WHEN kind='deposit_applied' THEN amount WHEN kind='refund' THEN -amount ELSE 0 END) s")->value('s');

        return round((float) $ch - (float) $pay, 2);
    }

    public static function depositHeld(Lease $l, ?string $asOf = null): float
    {
        $v = LeasePayment::where('lease_id', $l->id)->whereNull('voided_at')->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))
            ->selectRaw("SUM(CASE WHEN kind='deposit' THEN amount WHEN kind IN ('deposit_refund','deposit_applied') THEN -amount ELSE 0 END) s")->value('s');

        return round((float) $v, 2);
    }

    /** Charges past due + grace and unsettled: [amount, oldest due date]. */
    public static function overdue(Lease $l, ?string $date = null): array
    {
        $cut = Carbon::parse($date ?? bdate())->subDays((int) setting('lease_grace_days', 5))->toDateString();
        $rows = LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->where('type', '!=', 'credit')
            ->where('due_date', '<', $cut)->whereRaw('total - paid > 0.004')->orderBy('due_date')->get(['total', 'paid', 'due_date']);

        return [round($rows->sum(fn ($c) => (float) $c->total - (float) $c->paid), 2), $rows->first()?->due_date];
    }

    /** Leases with overdue amounts: [lease, amount, since, balance, buckets[0-30,31-60,61-90,90+]]. */
    public static function arrears(?string $asOf = null): Collection
    {
        $asOf ??= bdate()->toDateString();
        $cut = Carbon::parse($asOf)->subDays((int) setting('lease_grace_days', 5))->toDateString();
        $rows = LeaseCharge::whereNull('cancelled_at')->where('type', '!=', 'credit')->where('due_date', '<', $cut)->whereRaw('total - paid > 0.004')->get(['lease_id', 'total', 'paid', 'due_date']);
        $leases = Lease::with('unit', 'company')->whereIn('id', $rows->pluck('lease_id')->unique())->get()->keyBy('id');

        return $rows->groupBy('lease_id')->map(function ($g, $id) use ($leases, $asOf) {
            $b = [0.0, 0.0, 0.0, 0.0];
            foreach ($g as $c) {
                $days = (int) $c->due_date->diffInDays(Carbon::parse($asOf));
                $b[$days <= 30 ? 0 : ($days <= 60 ? 1 : ($days <= 90 ? 2 : 3))] += (float) $c->total - (float) $c->paid;
            }

            return ['lease' => $leases[$id], 'amount' => round($g->sum(fn ($c) => (float) $c->total - (float) $c->paid), 2), 'since' => $g->min('due_date'),
                'balance' => self::balance($leases[$id]), 'buckets' => array_map(fn ($v) => round($v, 2), $b)];
        })->sortByDesc('amount')->values();
    }

    // ───────────────────────── ledger ─────────────────────────

    public static function forCharge(LeaseCharge $c): ?array
    {
        if ($c->cancelled_at || abs((float) $c->total) < 0.005) {
            return null;
        }
        $l = $c->lease;
        $rev = $c->revenueType() === 'rent' ? AccountHead::sys('rev_lease_rent') : AccountHead::sys('rev_lease_other');

        return ['date' => $c->date->toDateString(), 'reference' => $c->charge_no,
            'narration' => LeaseCharge::TYPES[$c->type].' – '.$l->lease_no.' '.$l->tenant_name,
            'lines' => [[AccountHead::sys('lease_receivable'), (float) $c->total, $l->lease_no], [$rev, -(float) $c->amount, $c->description],
                ...LedgerService::taxLines((float) $c->tax, 'room', (float) $c->tax_rate)]];
    }

    public static function forPayment(LeasePayment $p): ?array
    {
        if ($p->voided_at) {
            return null;
        }
        $l = $p->lease;
        $cash = fn () => LedgerService::modeAccount($p->payment_mode_id, 'cash');
        $amt = (float) $p->amount;
        $lines = match ($p->kind) {
            'payment' => array_values(array_filter([
                $amt > 0 ? [$cash(), $amt, $p->paymentMode?->name] : null,
                (float) $p->wht_amount > 0 ? [AccountHead::sys('wht_recoverable'), (float) $p->wht_amount, 'WHT '.($p->wht_cert_no ?: 'certificate awaited')] : null,
                [AccountHead::sys('lease_receivable'), -($amt + (float) $p->wht_amount), $l->lease_no],
            ])),
            'refund' => [[AccountHead::sys('lease_receivable'), $amt, $l->lease_no], [$cash(), -$amt, $p->paymentMode?->name]],
            'deposit' => [[$cash(), $amt, $p->paymentMode?->name], [AccountHead::sys('lease_deposits'), -$amt, $l->lease_no]],
            'deposit_refund' => [[AccountHead::sys('lease_deposits'), $amt, $l->lease_no], [$cash(), -$amt, $p->paymentMode?->name]],
            'deposit_applied' => [[AccountHead::sys('lease_deposits'), $amt, $l->lease_no], [AccountHead::sys('lease_receivable'), -$amt, 'Deposit applied']],
        };

        return ['date' => $p->date->toDateString(), 'reference' => $p->receipt_no, 'narration' => LeasePayment::KINDS[$p->kind].' – '.$l->lease_no.' '.$l->tenant_name, 'lines' => $lines];
    }

    /** Tenants' receivable per the sub-ledger at the end of $asOf. */
    public static function controlBalance(string $asOf): float
    {
        $ch = LeaseCharge::whereNull('cancelled_at')->where('date', '<=', $asOf)->sum('total');
        $pay = LeasePayment::whereNull('voided_at')->where('date', '<=', $asOf)
            ->selectRaw("SUM(CASE WHEN kind='payment' THEN amount + wht_amount WHEN kind='deposit_applied' THEN amount WHEN kind='refund' THEN -amount ELSE 0 END) s")->value('s');

        return round((float) $ch - (float) $pay, 2);
    }

    public static function depositControl(string $asOf): float
    {
        $v = LeasePayment::whereNull('voided_at')->where('date', '<=', $asOf)
            ->selectRaw("SUM(CASE WHEN kind='deposit' THEN amount WHEN kind IN ('deposit_refund','deposit_applied') THEN -amount ELSE 0 END) s")->value('s');

        return round((float) $v, 2);
    }
}
