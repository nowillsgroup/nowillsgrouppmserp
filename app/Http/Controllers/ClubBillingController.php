<?php

namespace App\Http\Controllers;

use App\Models\ClubCharge;
use App\Models\ClubMembership;
use App\Models\ClubPayment;
use App\Models\PaymentMode;
use App\Services\ClubBillingService;
use App\Services\ClubService;
use App\Services\TaxService;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

/** v5.1 Members' accounts: renewals, receipts, refunds, credit notes, statements, arrears. */
class ClubBillingController extends Controller
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
        $today = bdate()->toDateString();
        $renewDays = (int) ClubService::cfg('club_renew_days');
        $from = $request->query('from', bdate()->copy()->startOfMonth()->toDateString());
        $to = $request->query('to', $today);

        return view('club.billing', [
            'arrears' => ClubBillingService::arrears(),
            'due' => ClubMembership::with('plan', 'company')->whereIn('status', ['active', 'frozen'])->whereNull('cancel_mode')->where('auto_renew', true)
                ->whereDate('end_date', '>=', Carbon::parse($today)->subDay()->toDateString())->whereDate('end_date', '<=', Carbon::parse($today)->addDays($renewDays)->toDateString())->orderBy('end_date')->get(),
            'ending' => ClubMembership::with('plan')->whereIn('status', ['active', 'frozen'])->where(fn ($q) => $q->where('auto_renew', false)->orWhereNotNull('cancel_mode'))
                ->whereDate('end_date', '>=', $today)->whereDate('end_date', '<=', Carbon::parse($today)->addDays(30)->toDateString())->orderBy('end_date')->get(),
            'lapsed' => ClubMembership::with('plan')->where('status', 'expired')->whereDate('end_date', '>=', Carbon::parse($today)->subDays(60)->toDateString())->orderByDesc('end_date')->get(),
            'charges' => ClubCharge::with('membership')->whereBetween('date', [$from, $to])->orderByDesc('date')->orderByDesc('id')->get(),
            'receipts' => ClubPayment::with('membership', 'paymentMode')->whereBetween('date', [$from, $to])->orderByDesc('date')->orderByDesc('id')->get(),
            'recognised' => ClubBillingService::recognised($from, $to),
            'unearned' => ClubBillingService::unearned($to),
            'receivable' => ClubBillingService::controlBalance($to),
            'from' => $from, 'to' => $to, 'renewDays' => $renewDays,
        ]);
    }

    public function renewalRun(Request $request)
    {
        $r = ClubService::renewalRun($request->user());

        return back()->with($r['errors'] ? 'warning' : 'success', count($r['renewed']).' renewal invoice(s) raised'.($r['renewed'] ? ': '.implode(', ', $r['renewed']) : '').'.'
            .($r['errors'] ? ' Skipped: '.implode('; ', $r['errors']) : ''));
    }

    public function renew(Request $request, ClubMembership $membership)
    {
        $d = $request->validate(['club_plan_id' => 'nullable|integer', 'pay_amount' => 'nullable|numeric|min:0', 'payment_mode_id' => 'nullable|integer|required_with:pay_amount', 'reference' => 'nullable|string|max:80']);

        return $this->attempt(function () use ($membership, $d, $request) {
            $c = ClubService::renew($membership->load('plan'), $request->user(), null, $d['club_plan_id'] ?? null);
            if ((float) ($d['pay_amount'] ?? 0) > 0) {
                ClubBillingService::pay($membership, (float) $d['pay_amount'], (int) $d['payment_mode_id'], $d['reference'] ?? null, $request->user());
            }

            return $c;
        }, fn ($c) => "Renewal {$c->charge_no} raised – ".money($c->total).'. Paid through '.dmy($c->period_end).' once settled.');
    }

    public function pay(Request $request, ClubMembership $membership)
    {
        $d = $request->validate(['amount' => 'required|numeric|min:0.01', 'payment_mode_id' => 'required|integer', 'reference' => 'nullable|string|max:80']);

        return $this->attempt(fn () => ClubBillingService::pay($membership, (float) $d['amount'], (int) $d['payment_mode_id'], $d['reference'] ?? null, $request->user()),
            fn ($p) => "Receipt {$p->receipt_no} – ".money($p->amount).'. Balance now '.money(ClubBillingService::balance($membership)).'.');
    }

    public function refund(Request $request, ClubMembership $membership)
    {
        $d = $request->validate(['amount' => 'required|numeric|min:0.01', 'payment_mode_id' => 'required|integer', 'reference' => 'nullable|string|max:80']);

        return $this->attempt(fn () => ClubBillingService::pay($membership, (float) $d['amount'], (int) $d['payment_mode_id'], $d['reference'] ?? null, $request->user(), null, 'refund'),
            fn ($p) => "Refund {$p->receipt_no} – ".money($p->amount).' paid out.');
    }

    public function charge(Request $request, ClubMembership $membership)
    {
        $d = $request->validate(['type' => 'required|in:guest_pass,other', 'description' => 'required|string|max:160', 'amount' => 'required|numeric|min:0.01|max:100000']);

        return $this->attempt(fn () => ClubBillingService::raise($membership, $d['type'], $d['description'], (float) $d['amount'], ['due_date' => bdate()->toDateString()], $request->user()),
            fn ($c) => "Charge {$c->charge_no} – ".money($c->total).' (incl. tax) added to the account.');
    }

    public function cancelCharge(Request $request, ClubCharge $clubcharge)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => ClubBillingService::cancelCharge($clubcharge, $d['reason'], $request->user()), "{$clubcharge->charge_no} cancelled.");
    }

    public function credit(Request $request, ClubCharge $clubcharge)
    {
        $d = $request->validate(['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:200']);

        return $this->attempt(fn () => ClubBillingService::creditNote($clubcharge, (float) $d['amount'], $d['reason'], $request->user()),
            fn ($c) => "Credit note {$c->charge_no} – ".money(abs((float) $c->total)).' (incl. tax).');
    }

    public function voidReceipt(Request $request, ClubPayment $clubpay)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => ClubBillingService::voidPayment($clubpay, $d['reason'], $request->user()), "{$clubpay->receipt_no} voided.");
    }

    public function statement(Request $request, ClubMembership $membership)
    {
        $membership->load('plan', 'company', 'members');
        $from = $request->query('from', $membership->start_date->toDateString());
        $to = $request->query('to', bdate()->toDateString());
        $opening = ClubBillingService::balance($membership, Carbon::parse($from)->subDay()->toDateString());
        $lines = collect();
        foreach (ClubCharge::where('club_membership_id', $membership->id)->whereNull('cancelled_at')->whereBetween('date', [$from, $to])->get() as $c) {
            $lines->push(['date' => $c->date, 'ref' => $c->charge_no, 'desc' => $c->description, 'debit' => max(0, (float) $c->total), 'credit' => max(0, -(float) $c->total), 'sort' => 0, 'id' => $c->id]);
        }
        foreach (ClubPayment::with('paymentMode')->where('club_membership_id', $membership->id)->whereNull('voided_at')->whereBetween('date', [$from, $to])->get() as $p) {
            $refund = $p->kind === 'refund';
            $lines->push(['date' => $p->date, 'ref' => $p->receipt_no, 'desc' => ($refund ? 'Refund' : 'Payment').' – '.$p->paymentMode?->name.($p->reference ? " ({$p->reference})" : ''),
                'debit' => $refund ? (float) $p->amount : 0, 'credit' => $refund ? 0 : (float) $p->amount, 'sort' => 1, 'id' => $p->id]);
        }
        $lines = $lines->sortBy([['date', 'asc'], ['sort', 'asc'], ['id', 'asc']])->values();

        return view('club.statement', ['m' => $membership, 'lines' => $lines, 'opening' => $opening, 'from' => $from, 'to' => $to, 'hotel' => Tenant::hotel()]);
    }

    public function invoice(ClubCharge $clubcharge)
    {
        $clubcharge->load('membership.plan', 'membership.company', 'creditOf');

        return view('club.invoice', ['c' => $clubcharge, 'm' => $clubcharge->membership, 'hotel' => Tenant::hotel(),
            'split' => TaxService::breakdown(abs((float) $clubcharge->tax), 'club', (float) $clubcharge->tax_rate), 'labels' => TaxService::labels('club')]);
    }

    public function receipt(ClubPayment $clubpay)
    {
        $clubpay->load('membership.plan', 'paymentMode', 'user');

        return view('club.receipt', ['p' => $clubpay, 'm' => $clubpay->membership, 'hotel' => Tenant::hotel(), 'balance' => ClubBillingService::balance($clubpay->membership)]);
    }
}
