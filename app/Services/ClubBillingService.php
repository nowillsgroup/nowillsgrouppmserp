<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\ClubCharge;
use App\Models\ClubDayPass;
use App\Models\ClubMembership;
use App\Models\ClubPayment;
use App\Models\PaymentMode;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.1 Members' accounts. Every charge is a tax invoice line (VAT + NHIL + GETFund + Tourism Levy on the service
 * category). Subscriptions are billed in advance: the net goes to "Membership fees received in advance" and is
 * recognised as revenue day by day over the service period (one journal per closed month). Joining fees, guest
 * passes and other charges are revenue when raised. Receipts are allocated oldest-due first; credit notes settle
 * the account the same way, and any credit balance can be refunded.
 */
class ClubBillingService
{
    public static function tax(float $net, ?float $rate = null): array
    {
        if ($rate === null) {
            $rate = (float) TaxService::forCategory('club', abs($net))['rate'];
        }
        $tax = round($net * $rate / 100, 2);

        return ['rate' => $rate, 'tax' => $tax, 'total' => round($net + $tax, 2)];
    }

    /** Raise a charge on a membership. $o: date, period_start, period_end, due_date, credit_of_id, tax_rate. */
    public static function raise(ClubMembership $m, string $type, string $description, float $net, array $o = [], ?User $u = null): ClubCharge
    {
        if (! array_key_exists($type, ClubCharge::TYPES)) {
            throw new RuntimeException('Unknown charge type.');
        }
        if ($type !== 'credit' && $net <= 0) {
            throw new RuntimeException('The amount must be more than zero.');
        }
        $date = Carbon::parse($o['date'] ?? bdate())->toDateString();
        $t = self::tax(round($net, 2), $o['tax_rate'] ?? null);
        $due = $o['due_date'] ?? max($date, $o['period_start'] ?? $date);

        $c = ClubCharge::create([
            'charge_no' => Numbering::next('club_charge'),
            'club_membership_id' => $m->id,
            'date' => $date,
            'type' => $type,
            'description' => mb_substr($description, 0, 160),
            'period_start' => $o['period_start'] ?? null,
            'period_end' => $o['period_end'] ?? null,
            'amount' => round($net, 2),
            'tax_rate' => $t['rate'],
            'tax' => $t['tax'],
            'total' => $t['total'],
            'due_date' => $due,
            'credit_of_id' => $o['credit_of_id'] ?? null,
            'user_id' => $u?->id,
        ]);
        self::reallocate($m);

        return $c->refresh();
    }

    /** Credit note against a charge (net, tax-exclusive; tax follows the original rate). */
    public static function creditNote(ClubCharge $c, float $net, string $reason, ?User $u = null, ?string $date = null): ClubCharge
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

        $cn = self::raise($c->membership, 'credit', 'Credit note on '.$c->charge_no.' – '.trim($reason), -$net,
            ['date' => $date, 'credit_of_id' => $c->id, 'tax_rate' => (float) $c->tax_rate, 'due_date' => $date ?? bdate()->toDateString()], $u);
        ActivityLogger::log('club', 'credit_note', "Credit note {$cn->charge_no} ".money(abs((float) $cn->total))." on {$c->charge_no}", $cn);

        return $cn;
    }

    public static function creditable(ClubCharge $c): float
    {
        return round((float) $c->amount + (float) ClubCharge::where('credit_of_id', $c->id)->whereNull('cancelled_at')->sum('amount'), 2);
    }

    /**
     * Cancel a charge raised in error: allowed while the service has not started (a future subscription period) or on
     * the day it was raised. Otherwise issue a credit note.
     */
    public static function cancelCharge(ClubCharge $c, string $reason, ?User $u = null, bool $system = false): void
    {
        if ($c->cancelled_at) {
            throw new RuntimeException('Already cancelled.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }
        $today = bdate()->toDateString();
        if ($c->type === 'credit') {
            if (! $system && $c->date->toDateString() !== $today) {
                throw new RuntimeException('A credit note can only be withdrawn on the day it was issued.');
            }
        } else {
            if (ClubCharge::where('credit_of_id', $c->id)->whereNull('cancelled_at')->exists()) {
                throw new RuntimeException('This charge has a credit note – withdraw the credit note first.');
            }
            // anything already received against it stays on the account as credit (used for the next charge, or refunded)
            $started = $c->type === 'subscription' ? $c->period_start->toDateString() <= $today : true;
            if (! $system && $started && $c->date->toDateString() !== $today) {
                throw new RuntimeException('The service has started – cancel the membership or issue a credit note instead.');
            }
        }

        DB::transaction(function () use ($c, $reason, $u) {
            $c->update(['cancelled_at' => now(), 'cancelled_by' => $u?->id, 'cancel_reason' => mb_substr(trim($reason), 0, 255), 'paid' => 0]);
            $m = $c->membership;
            // a cancelled renewal hands the period back: the membership runs to the end of the previous period
            if ($c->type === 'subscription' && $m->end_date->toDateString() === $c->period_end->toDateString()) {
                $m->update(['end_date' => $c->period_start->copy()->subDay()->toDateString()]);
            }
            self::reallocate($m);
        });
        ActivityLogger::log('club', 'charge_cancelled', "Charge {$c->charge_no} cancelled – {$reason}", $c);
    }

    /** Receipt (or refund of a credit balance) on a membership. */
    public static function pay(ClubMembership $m, float $amount, int $modeId, ?string $reference, ?User $u = null, ?string $date = null, string $kind = 'payment'): ClubPayment
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('The amount must be more than zero.');
        }
        if (! PaymentMode::whereKey($modeId)->exists()) {
            throw new RuntimeException('Choose a payment mode.');
        }
        if (! in_array($kind, ['payment', 'refund'], true)) {
            throw new RuntimeException('Unknown receipt type.');
        }
        if ($kind === 'refund') {
            $credit = -self::balance($m);
            if ($amount > $credit + 0.004) {
                throw new RuntimeException('Only a credit balance can be refunded – the account is in credit by '.money(max(0, $credit)).'.');
            }
        }
        $p = ClubPayment::create([
            'receipt_no' => Numbering::next('club_receipt'),
            'club_membership_id' => $m->id,
            'kind' => $kind,
            'date' => Carbon::parse($date ?? bdate())->toDateString(),
            'payment_mode_id' => $modeId,
            'amount' => $amount,
            'reference' => $reference ? mb_substr($reference, 0, 80) : null,
            'user_id' => $u?->id,
        ]);
        self::reallocate($m);
        ActivityLogger::log('club', $kind, ucfirst($kind)." {$p->receipt_no} ".money($amount)." – {$m->membership_no}", $p);

        return $p->refresh();
    }

    public static function voidPayment(ClubPayment $p, string $reason, ?User $u = null): void
    {
        if ($p->voided_at) {
            throw new RuntimeException('Already voided.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }
        if ($p->kind === 'payment' && ClubPayment::where('club_membership_id', $p->club_membership_id)->where('kind', 'refund')->whereNull('voided_at')->where('id', '>', $p->id)->exists()) {
            throw new RuntimeException('A later refund depends on this receipt – void the refund first.');
        }
        $p->update(['voided_at' => now(), 'voided_by' => $u?->id, 'void_reason' => mb_substr(trim($reason), 0, 255)]);
        self::reallocate($p->membership);
        ActivityLogger::log('club', 'receipt_voided', "Receipt {$p->receipt_no} voided – {$reason}", $p);
    }

    /**
     * Recompute what is settled on every charge: receipts (net of refunds) plus credit notes form one pool that is
     * applied to the live charges oldest-due first. Receipts record the charges they ended up settling.
     */
    public static function reallocate(ClubMembership $m): void
    {
        $charges = ClubCharge::where('club_membership_id', $m->id)->whereNull('cancelled_at')->where('type', '!=', 'credit')
            ->orderBy('due_date')->orderBy('id')->get();
        $payments = ClubPayment::where('club_membership_id', $m->id)->whereNull('voided_at')->orderBy('date')->orderBy('id')->get();

        // credit notes first settle the charge they were raised against
        $pool = [];
        foreach (ClubCharge::where('club_membership_id', $m->id)->whereNull('cancelled_at')->where('type', 'credit')->get() as $cn) {
            $pool[$cn->credit_of_id] = ($pool[$cn->credit_of_id] ?? 0) - (float) $cn->total;
        }
        $paidMap = [];
        $free = 0.0;
        foreach ($charges as $c) {
            $apply = min((float) $c->total, $pool[$c->id] ?? 0);
            $paidMap[$c->id] = $apply;
            $free += ($pool[$c->id] ?? 0) - $apply;
        }
        $free = round($free, 2);

        // then receipts (refunds reduce the pool), oldest charge first
        $alloc = [];
        $queue = $charges->filter(fn ($c) => (float) $c->total - $paidMap[$c->id] > 0.004)->values();
        $qi = 0;
        $use = function (float $amt, ?int $pid) use (&$queue, &$qi, &$paidMap, &$alloc) {
            while ($amt > 0.004 && $qi < $queue->count()) {
                $c = $queue[$qi];
                $open = round((float) $c->total - $paidMap[$c->id], 2);
                $take = min($open, $amt);
                $paidMap[$c->id] = round($paidMap[$c->id] + $take, 2);
                $amt = round($amt - $take, 2);
                if ($pid) {
                    $alloc[$pid][] = ['charge_id' => $c->id, 'charge_no' => $c->charge_no, 'amount' => round($take, 2)];
                }
                if ($paidMap[$c->id] >= (float) $c->total - 0.004) {
                    $qi++;
                }
            }

            return $amt;
        };
        $use($free, null);
        // refunds come out of the unallocated credit; allocate receipts net of refunds
        $refunds = $payments->where('kind', 'refund')->sum(fn ($p) => (float) $p->amount);
        foreach ($payments->where('kind', 'payment') as $p) {
            $amt = (float) $p->amount;
            $absorb = min($amt, $refunds);
            $refunds = round($refunds - $absorb, 2);
            $use(round($amt - $absorb, 2), $p->id);
        }

        foreach ($charges as $c) {
            $v = round($paidMap[$c->id], 2);
            if (abs($v - (float) $c->paid) > 0.004) {
                ClubCharge::whereKey($c->id)->update(['paid' => $v]);
            }
        }
        foreach ($payments as $p) {
            $a = $alloc[$p->id] ?? [];
            if (json_encode($a) !== json_encode($p->allocations ?? [])) {
                ClubPayment::whereKey($p->id)->update(['allocations' => $a ? json_encode($a) : null]);
            }
        }
    }

    /** Positive = the member owes; negative = in credit. */
    public static function balance(ClubMembership $m, ?string $asOf = null): float
    {
        $ch = ClubCharge::where('club_membership_id', $m->id)->whereNull('cancelled_at')->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))->sum('total');
        $pay = ClubPayment::where('club_membership_id', $m->id)->whereNull('voided_at')->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))
            ->selectRaw("SUM(CASE WHEN kind='refund' THEN -amount ELSE amount END) s")->value('s');

        return round((float) $ch - (float) $pay, 2);
    }

    /** Charges past due + grace and not settled: [amount, oldest due date]. */
    public static function overdue(ClubMembership $m, ?string $date = null): array
    {
        $cut = Carbon::parse($date ?? bdate())->subDays((int) setting('club_grace_days', 7))->toDateString();
        $rows = ClubCharge::where('club_membership_id', $m->id)->whereNull('cancelled_at')->where('type', '!=', 'credit')
            ->where('due_date', '<', $cut)->whereRaw('total - paid > 0.004')->orderBy('due_date')->get(['total', 'paid', 'due_date']);

        return [round($rows->sum(fn ($c) => (float) $c->total - (float) $c->paid), 2), $rows->first()?->due_date];
    }

    /** Memberships with overdue balances: collection of [membership, amount, since]. */
    public static function arrears(): \Illuminate\Support\Collection
    {
        $cut = bdate()->copy()->subDays((int) setting('club_grace_days', 7))->toDateString();
        $rows = ClubCharge::whereNull('cancelled_at')->where('type', '!=', 'credit')->where('due_date', '<', $cut)->whereRaw('total - paid > 0.004')
            ->selectRaw('club_membership_id, SUM(total - paid) amt, MIN(due_date) since')->groupBy('club_membership_id')->get();
        $ms = ClubMembership::with('plan', 'company')->whereIn('id', $rows->pluck('club_membership_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => ['membership' => $ms[$r->club_membership_id], 'amount' => round((float) $r->amt, 2), 'since' => Carbon::parse($r->since),
            'balance' => self::balance($ms[$r->club_membership_id])])->sortByDesc('amount')->values();
    }

    // ───────────────────────── revenue recognition ─────────────────────────

    /** Where recognition of a subscription stops: an immediate cancellation, otherwise the period end. */
    public static function stopDate(ClubCharge $c): string
    {
        $m = $c->relationLoaded('membership') ? $c->membership : $c->membership()->first();
        $end = $c->period_end->toDateString();
        if ($m && $m->cancel_mode === 'immediate' && $m->cancelled_on && $m->cancelled_on->toDateString() < $end) {
            return $m->cancelled_on->toDateString();
        }

        return $end;
    }

    /** Net of a subscription charge earned up to and including $d. */
    public static function earned(ClubCharge $c, string $d): float
    {
        if ($c->cancelled_at || $c->type !== 'subscription' || ! $c->period_start) {
            return 0.0;
        }
        $start = $c->period_start->toDateString();
        $stop = self::stopDate($c);
        $credits = (float) ClubCharge::where('credit_of_id', $c->id)->whereNull('cancelled_at')->where('date', '<=', $d)->sum('amount');
        if ($d >= $stop || $d >= $c->period_end->toDateString()) {
            return round((float) $c->amount + $credits, 2);
        }
        if ($d < $start) {
            return 0.0;
        }
        $total = $c->period_start->diffInDays($c->period_end) + 1;
        $elapsed = $c->period_start->diffInDays(Carbon::parse($d)) + 1;

        return round((float) $c->amount * $elapsed / $total, 2);
    }

    /** Membership fees received in advance at the end of $asOf (subscriptions raised, net of credits, not yet earned). */
    public static function unearned(string $asOf): float
    {
        $sum = 0.0;
        foreach (ClubCharge::with('membership')->where('type', 'subscription')->whereNull('cancelled_at')->where('date', '<=', $asOf)->get() as $c) {
            $credits = (float) ClubCharge::where('credit_of_id', $c->id)->whereNull('cancelled_at')->where('date', '<=', $asOf)->sum('amount');
            $sum += (float) $c->amount + $credits - self::earned($c, $asOf);
        }

        return round($sum, 2);
    }

    /** Subscription revenue recognised in a range (dates inclusive). */
    public static function recognised(string $from, string $to): float
    {
        $prev = Carbon::parse($from)->subDay()->toDateString();
        $sum = 0.0;
        foreach (ClubCharge::with('membership')->where('type', 'subscription')->whereNull('cancelled_at')->where('date', '<=', $to)->get() as $c) {
            $before = $c->date->toDateString() > $prev ? 0.0 : self::earned($c, $prev);
            $sum += self::earned($c, $to) - $before;
        }

        return round($sum, 2);
    }

    /** Monthly recognition journal: key YYYY-MM, dated the last day of the month. */
    public static function forRecognition(string $month): ?array
    {
        $start = Carbon::parse($month.'-01');
        $end = $start->copy()->endOfMonth()->toDateString();
        $amt = self::recognised($start->toDateString(), $end);
        if (abs($amt) < 0.005) {
            return null;
        }

        return ['date' => $end, 'reference' => 'CLUB-'.$month, 'narration' => 'Health club membership fees earned – '.$start->format('F Y'),
            'lines' => [[AccountHead::sys('club_unearned'), $amt, 'Released from advance'], [AccountHead::sys('rev_club'), -$amt, 'Membership revenue']]];
    }

    /** Months that are closed (month end on or before the cut-off) and have subscription activity. */
    public static function recognitionMonths(string $cutoff, string $floor): array
    {
        $first = ClubCharge::where('type', 'subscription')->min('period_start');
        $firstDate = ClubCharge::where('type', 'subscription')->min('date');
        if (! $first) {
            return [];
        }
        $m = Carbon::parse(min($first, $firstDate))->startOfMonth();
        $out = [];
        while ($m->copy()->endOfMonth()->toDateString() <= $cutoff) {
            if ($m->copy()->endOfMonth()->toDateString() > $floor) {
                $out[] = $m->format('Y-m');
            }
            $m->addMonth();
        }

        return $out;
    }

    // ───────────────────────── GL builders ─────────────────────────

    public static function forCharge(ClubCharge $c): ?array
    {
        if ($c->cancelled_at || abs((float) $c->total) < 0.005) {
            return null;
        }
        $m = $c->membership;
        $deferred = $c->type === 'subscription' || ($c->type === 'credit' && $c->creditOf?->type === 'subscription');
        $credit = $deferred ? AccountHead::sys('club_unearned') : AccountHead::sys('rev_club_other');

        return ['date' => $c->date->toDateString(), 'reference' => $c->charge_no,
            'narration' => ClubCharge::TYPES[$c->type].' – '.$m->membership_no.' '.$m->holder_name,
            'lines' => [[AccountHead::sys('club_receivable'), (float) $c->total, $m->membership_no], [$credit, -(float) $c->amount, $c->description],
                ...LedgerService::taxLines((float) $c->tax, 'club', (float) $c->tax_rate)]];
    }

    public static function forPayment(ClubPayment $p): ?array
    {
        if ($p->voided_at) {
            return null;
        }
        $amt = ($p->kind === 'refund' ? -1 : 1) * (float) $p->amount;
        $m = $p->membership;

        return ['date' => $p->date->toDateString(), 'reference' => $p->receipt_no,
            'narration' => ($p->kind === 'refund' ? 'Refund to member – ' : 'Member receipt – ').$m->membership_no.' '.$m->holder_name,
            'lines' => [[LedgerService::modeAccount($p->payment_mode_id, 'cash'), $amt, $p->paymentMode?->name], [AccountHead::sys('club_receivable'), -$amt, $m->membership_no]]];
    }

    public static function forDayPass(ClubDayPass $d): ?array
    {
        if ($d->cancelled_at || $d->reservation_room_id || $d->sale_id || ! $d->payment_mode_id || (float) $d->total <= 0) {
            return null; // room charges reach the ledger through the folio; outlet passes post via their sale
        }

        return ['date' => $d->date->toDateString(), 'reference' => $d->pass_no, 'narration' => 'Health club day pass – '.$d->name,
            'lines' => [[LedgerService::modeAccount($d->payment_mode_id, 'cash'), (float) $d->total, $d->paymentMode?->name],
                [AccountHead::sys('rev_club_other'), -(float) $d->amount, ClubDayPass::KINDS[$d->kind] ?? 'Day pass'],
                ...LedgerService::taxLines((float) $d->tax, 'club', (float) $d->tax_rate)]];
    }

    /** v7.38 Recreation outlet sale taken over the counter (split tenders). Room charges reach the ledger through the folio. */
    public static function forTicketSale(\App\Models\ClubTicketSale $s): ?array
    {
        if ($s->status === 'void' || $s->reservation_room_id || (float) $s->total <= 0) {
            return null;
        }
        $lines = [];
        foreach ($s->payments as $pay) {
            $lines[] = [LedgerService::modeAccount($pay->payment_mode_id, 'cash'), (float) $pay->amount, $pay->paymentMode?->name];
        }
        if ((float) $s->change_due > 0 && ($first = $s->payments->first())) {
            $lines[] = [LedgerService::modeAccount($first->payment_mode_id, 'cash'), -(float) $s->change_due, 'Change given'];
        }
        foreach ($s->items as $it) {
            $lines[] = [AccountHead::sys('rev_club_other'), -(float) $it->line_total, $it->description.' ('.$s->sale_no.')'];
            $lines = array_merge($lines, LedgerService::taxLines((float) $it->tax, 'club', (float) $it->tax_rate));
        }

        return ['date' => ($s->business_date ?? $s->sold_at)->toDateString(), 'reference' => $s->sale_no,
            'narration' => 'Recreation outlet '.$s->sale_no.($s->guest_name ? ' – '.$s->guest_name : ''), 'lines' => $lines];
    }

    /** Members receivable per the sub-ledger at the end of $asOf. */
    public static function controlBalance(string $asOf): float
    {
        $ch = ClubCharge::whereNull('cancelled_at')->where('date', '<=', $asOf)->sum('total');
        $pay = ClubPayment::whereNull('voided_at')->where('date', '<=', $asOf)->selectRaw("SUM(CASE WHEN kind='refund' THEN -amount ELSE amount END) s")->value('s');

        return round((float) $ch - (float) $pay, 2);
    }
}
