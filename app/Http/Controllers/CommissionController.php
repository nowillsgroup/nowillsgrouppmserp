<?php

namespace App\Http\Controllers;

use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\PaymentMode;
use App\Services\ActivityLogger;
use App\Services\ArService;
use App\Services\CommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CommissionController extends Controller
{
    /**
     * v9.12 Agent commissions, read as an ageing.
     *
     * A commission accrues at check-out and is settled when the agent asks: between those two days it sits on the books
     * for as long as somebody leaves it there. The screen listed the commissions and totalled them, but it never said how
     * old the money was, and its agent summary was computed from every due commission while the table showed whatever the
     * filter asked for — two populations on one page. Both now come from one aged collection, and the page also answers the
     * one question a commission can raise on its own: a commission marked paid must name the voucher that paid it.
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'due');
        $asOf = substr((string) $request->query('as_of', bdate()->toDateString()), 0, 10);
        if ($asOf > bdate()->toDateString()) {
            $asOf = bdate()->toDateString();   // a commission cannot be aged past today
        }
        // days from the check-out to the day being read, in whole days: positive arrears, never negative
        $asAt = Carbon::parse($asOf)->startOfDay();
        $age = fn (AgentCommission $c) => max(0, (int) round(($asAt->timestamp - $c->business_date->copy()->startOfDay()->timestamp) / 86400));

        $rows = AgentCommission::with('company', 'reservation.guest', 'stay.room', 'voucher')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))
            ->orderByDesc('business_date')->orderByDesc('id')->paginate(50)->withQueryString();
        $rows->getCollection()->transform(function ($c) use ($age) {
            $c->days = $age($c);
            $c->bucket = ArService::bucketOf($c->days);

            return $c;
        });

        // the due population, aged once: the summary and the buckets are read off this, so they cannot disagree with it
        $due = AgentCommission::with('company')->where('status', 'due')
            ->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))
            ->get()->each(function ($c) use ($age) {
                $c->days = $age($c);
                $c->bucket = ArService::bucketOf($c->days);
            });
        $summary = $due->groupBy('company_id')->map(fn ($g) => ['company' => $g->first()->company, 'stays' => $g->count(),
            'revenue' => round((float) $g->sum('room_revenue'), 2), 'amount' => round((float) $g->sum('amount'), 2),
            'oldest' => (int) $g->max('days'), 'b0' => round((float) $g->where('bucket', 'b0')->sum('amount'), 2),
            'b30' => round((float) $g->where('bucket', 'b30')->sum('amount'), 2), 'b60' => round((float) $g->where('bucket', 'b60')->sum('amount'), 2),
            'b90' => round((float) $g->where('bucket', 'b90')->sum('amount'), 2)])->sortByDesc('amount')->values();
        $aged = ['as_of' => $asOf, 'due' => round((float) $due->sum('amount'), 2), 'agents' => $summary->count(),
            'stays' => $due->count(), 'oldest' => (int) ($due->max('days') ?? 0)];
        foreach (array_keys(ArService::BUCKETS) as $k) {
            $aged[$k] = round((float) $due->where('bucket', $k)->sum('amount'), 2);
            $aged['n'.$k] = $due->where('bucket', $k)->count();
        }

        // v9.12 the document control: paid means a voucher paid it, void means nothing did, due means nothing yet.
        $all = AgentCommission::with('voucher', 'company')->get();
        $paid = $all->where('status', 'paid');
        $undocumented = $paid->filter(fn ($c) => ! $c->voucher)->values();
        $wronglyDocumented = $all->where('status', '!=', 'paid')->filter(fn ($c) => (bool) $c->voucher)->values();
        $doc = ['paid' => $paid->count(), 'paid_amount' => round((float) $paid->sum('amount'), 2),
            'documented' => $paid->filter(fn ($c) => (bool) $c->voucher)->count(),
            'missing' => $undocumented->count(), 'stray' => $wronglyDocumented->count(),
            'missing_rows' => $undocumented->map(fn ($c) => ['company' => $c->company?->name ?? '—', 'amount' => round((float) $c->amount, 2)])->all()];

        return view('accounts.commissions', [
            'rows' => $rows, 'summary' => $summary, 'status' => $status, 'asOf' => $asOf, 'aged' => $aged, 'doc' => $doc,
            'buckets' => ArService::BUCKETS,
            'companies' => Company::where('commission_pct', '>', 0)->orWhereIn('id', AgentCommission::select('company_id'))->orderBy('name')->pluck('name', 'id'),
            'paymentModes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'gift_voucher'])->pluck('name', 'id'),
        ]);
    }

    public function pay(Request $request)
    {
        $data = $request->validate(['company_id' => 'required|integer|owned:companies', 'ids' => 'required|array|min:1', 'ids.*' => 'integer|owned:agent_commissions',
            'payment_mode_id' => 'required|integer|owned:payment_modes', 'date' => 'required|date', 'reference' => 'nullable|string|max:60']);
        $v = CommissionService::pay(Company::findOrFail($data['company_id']), array_map('intval', $data['ids']), (int) $data['payment_mode_id'], $data['date'], $data['reference'] ?? null);

        return back()->with('success', "Commission paid: voucher {$v->voucher_no} for ".money($v->amount).'.');
    }

    public function void(Request $request, AgentCommission $commission)
    {
        $data = $request->validate(['reason' => 'required|string|max:190']);
        abort_unless($commission->status === 'due', 422, 'Only due commissions can be voided.');
        $commission->update(['status' => 'void']);
        ActivityLogger::log('accounts', 'commission_void', 'Voided commission '.money($commission->amount)." ({$commission->company->name}): {$data['reason']}", $commission);

        return back()->with('success', 'Commission voided.');
    }
}
