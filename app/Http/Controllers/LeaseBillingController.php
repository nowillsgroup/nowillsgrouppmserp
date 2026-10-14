<?php

namespace App\Http\Controllers;

use App\Models\Lease;
use App\Models\LeaseCharge;
use App\Models\LeasePayment;
use App\Services\LeaseBillingService as LB;
use App\Services\LeaseService as LS;
use App\Services\TaxService;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

/** v5.2 Tenant accounts: receipts (with withholding tax), deposits, extra charges, credit notes, statements, arrears. */
class LeaseBillingController extends Controller
{
    protected function attempt(callable $fn, string|callable $ok)
    {
        try {
            $r = $fn();

            return back()->with('success', is_callable($ok) ? $ok($r) : $ok);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function index(Request $request)
    {
        $today = LS::today();
        $from = $request->query('from', bdate()->copy()->startOfMonth()->toDateString());
        $to = $request->query('to', $today);
        $upcoming = Lease::with('unit')->whereIn('status', ['active', 'notice'])->get()->map(function ($l) {
            $ps = $l->billed_to ? $l->billed_to->copy()->addDay() : $l->start_date->copy();

            return $ps->gt($l->lastDay()) ? null : ['lease' => $l, 'next' => $ps, 'amount' => LS::periodAmount((float) $l->rent + (float) $l->service_charge, $ps->toDateString(),
                min(LS::periodEnd($ps->toDateString(), $l->cycle_months), $l->lastDay()->toDateString()), $l->cycle_months)];
        })->filter()->sortBy('next')->values();

        return view('leases.billing', [
            'arrears' => LB::arrears(),
            'upcoming' => $upcoming,
            'charges' => LeaseCharge::with('lease.unit')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderByDesc('date')->orderByDesc('id')->get(),
            'receipts' => LeasePayment::with('lease.unit', 'paymentMode')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderByDesc('date')->orderByDesc('id')->get(),
            'receivable' => LB::controlBalance($to), 'deposits' => LB::depositControl($to),
            'from' => $from, 'to' => $to, 'grace' => (int) LS::cfg('lease_grace_days'), 'ahead' => (int) LS::cfg('lease_bill_ahead_days'),
        ]);
    }

    public function pay(Request $request, Lease $lease)
    {
        $d = $request->validate(['kind' => 'required|in:payment,deposit', 'amount' => 'required|numeric|min:0.01', 'payment_mode_id' => 'required|integer', 'reference' => 'nullable|string|max:80',
            'wht_amount' => 'nullable|numeric|min:0', 'wht_cert_no' => 'nullable|string|max:40', 'note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => LB::pay($lease, $d['kind'], (float) $d['amount'], (int) $d['payment_mode_id'], $d['reference'] ?? null, $request->user(), null,
            (float) ($d['wht_amount'] ?? 0), $d['wht_cert_no'] ?? null, $d['note'] ?? null),
            fn ($p) => ($p->kind === 'deposit' ? 'Deposit' : 'Receipt')." {$p->receipt_no} – ".money($p->amount).((float) $p->wht_amount > 0 ? ' + WHT '.money($p->wht_amount) : '')
                .'. Balance '.money(LB::balance($lease)).', deposit held '.money(LB::depositHeld($lease)).'.');
    }

    public function refund(Request $request, Lease $lease)
    {
        $d = $request->validate(['kind' => 'required|in:refund,deposit_refund', 'amount' => 'required|numeric|min:0.01', 'payment_mode_id' => 'required|integer', 'reference' => 'nullable|string|max:80', 'note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => LB::pay($lease, $d['kind'], (float) $d['amount'], (int) $d['payment_mode_id'], $d['reference'] ?? null, $request->user(), null, 0, null, $d['note'] ?? null),
            fn ($p) => "Refund {$p->receipt_no} – ".money($p->amount).' paid out.');
    }

    public function applyDeposit(Request $request, Lease $lease)
    {
        $d = $request->validate(['amount' => 'required|numeric|min:0.01', 'note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => LB::pay($lease, 'deposit_applied', (float) $d['amount'], null, null, $request->user(), null, 0, null, $d['note'] ?? null),
            fn ($p) => money($p->amount).' of the deposit applied to the account.');
    }

    public function charge(Request $request, Lease $lease)
    {
        $d = $request->validate(['type' => 'required|in:other,damage,electricity,water', 'description' => 'required|string|max:160', 'amount' => 'required|numeric|min:0.01|max:1000000']);
        if (! in_array($lease->status, ['active', 'notice', 'ended', 'terminated'], true)) {
            return back()->with('error', 'The lease has not started.');
        }

        return $this->attempt(fn () => LB::raise($lease, $d['type'], $d['description'], (float) $d['amount'], ['due_date' => LS::today()], $request->user()),
            fn ($c) => "Charge {$c->charge_no} – ".money($c->total).' added to the account.');
    }

    public function cancelCharge(Request $request, LeaseCharge $leasecharge)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => LB::cancelCharge($leasecharge, $d['reason'], $request->user()), "{$leasecharge->charge_no} cancelled.");
    }

    public function credit(Request $request, LeaseCharge $leasecharge)
    {
        $d = $request->validate(['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:200']);

        return $this->attempt(fn () => LB::creditNote($leasecharge, (float) $d['amount'], $d['reason'], $request->user()),
            fn ($c) => "Credit note {$c->charge_no} – ".money(abs((float) $c->total)).' (incl. tax).');
    }

    public function voidReceipt(Request $request, LeasePayment $leasepay)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => LB::voidPayment($leasepay, $d['reason'], $request->user()), "{$leasepay->receipt_no} voided.");
    }

    public function run()
    {
        $r = LS::nightly();

        return back()->with('success', "Billing run: {$r['invoices']} rent invoice(s), {$r['late_fees']} late fee(s)".($r['renewed'] ? ', renewed '.implode(', ', $r['renewed']) : '').'.');
    }

    /** Account lines [date, ref, desc, debit, credit] for a statement. */
    public static function lines(Lease $l, string $from, string $to): \Illuminate\Support\Collection
    {
        $lines = collect();
        foreach (LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get() as $c) {
            $lines->push(['date' => $c->date, 'ref' => $c->charge_no, 'desc' => $c->description, 'debit' => max(0, (float) $c->total), 'credit' => max(0, -(float) $c->total), 'sort' => 0, 'id' => $c->id]);
        }
        foreach (LeasePayment::with('paymentMode')->where('lease_id', $l->id)->whereNull('voided_at')->whereIn('kind', ['payment', 'refund', 'deposit_applied'])->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get() as $p) {
            $desc = LeasePayment::KINDS[$p->kind].($p->paymentMode ? ' – '.$p->paymentMode->name : '').($p->reference ? " ({$p->reference})" : '');
            $effect = $p->accountEffect();
            $lines->push(['date' => $p->date, 'ref' => $p->receipt_no, 'desc' => $desc.((float) $p->wht_amount > 0 ? ' incl. WHT '.money($p->wht_amount).($p->wht_cert_no ? " cert {$p->wht_cert_no}" : '') : ''),
                'debit' => max(0, $effect), 'credit' => max(0, -$effect), 'sort' => 1, 'id' => $p->id]);
        }

        return $lines->sortBy([['date', 'asc'], ['sort', 'asc'], ['id', 'asc']])->values();
    }

    public function statement(Request $request, Lease $lease)
    {
        $lease->load('unit', 'company');
        $from = $request->query('from', $lease->start_date->copy()->subDays(14)->toDateString());
        $to = $request->query('to', LS::today());

        return view('leases.print-statement', ['l' => $lease, 'lines' => self::lines($lease, $from, $to), 'opening' => LB::balance($lease, Carbon::parse($from)->subDay()->toDateString()),
            'from' => $from, 'to' => $to, 'hotel' => Tenant::hotel(), 'deposit' => LB::depositHeld($lease), 'overdue' => LB::overdue($lease)]);
    }

    public function settlement(Lease $lease)
    {
        $lease->load('unit', 'company', 'inspections');
        $deps = LeasePayment::with('paymentMode')->where('lease_id', $lease->id)->whereNull('voided_at')->whereIn('kind', ['deposit', 'deposit_applied', 'deposit_refund'])->orderBy('date')->get();

        return view('leases.print-settlement', ['l' => $lease, 'hotel' => Tenant::hotel(), 'deps' => $deps, 's' => LS::settlement($lease),
            'finals' => LeaseCharge::where('lease_id', $lease->id)->whereNull('cancelled_at')->whereDate('date', '>=', ($lease->move_out_on ?? $lease->end_date)->copy()->subDays(1))->orderBy('id')->get()]);
    }

    public function invoice(LeaseCharge $leasecharge)
    {
        $leasecharge->load('lease.unit', 'lease.company', 'creditOf');
        $l = $leasecharge->lease;
        $group = in_array($leasecharge->type, ['rent', 'service_charge'], true) && $leasecharge->period_start
            ? LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->whereIn('type', ['rent', 'service_charge'])->whereDate('period_start', $leasecharge->period_start)->orderBy('id')->get()
            : collect([$leasecharge]);
        $tax = (float) $group->sum('tax');
        $rate = (float) $leasecharge->tax_rate;

        return view('leases.print-invoice', ['c' => $leasecharge, 'group' => $group, 'l' => $l, 'hotel' => Tenant::hotel(),
            'split' => TaxService::breakdown(abs($tax), 'room', $rate), 'labels' => TaxService::labels('room'),
            'wht' => (float) $l->wht_pct > 0 ? round((float) $group->where('type', 'rent')->sum('amount') * (float) $l->wht_pct / 100, 2) : 0.0]);
    }

    public function receipt(LeasePayment $leasepay)
    {
        $leasepay->load('lease.unit', 'lease.company', 'paymentMode', 'user');

        return view('leases.print-receipt', ['p' => $leasepay, 'l' => $leasepay->lease, 'hotel' => Tenant::hotel(), 'balance' => LB::balance($leasepay->lease), 'deposit' => LB::depositHeld($leasepay->lease)]);
    }
}
