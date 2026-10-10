<?php

namespace App\Http\Controllers;

use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\PaymentMode;
use App\Services\ActivityLogger;
use App\Services\CommissionService;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'due');
        $rows = AgentCommission::with('company', 'reservation.guest', 'stay.room', 'voucher')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c))
            ->orderByDesc('business_date')->orderByDesc('id')->paginate(50)->withQueryString();
        $summary = AgentCommission::with('company')->where('status', 'due')->get()->groupBy('company_id')
            ->map(fn ($g) => ['company' => $g->first()->company, 'stays' => $g->count(), 'revenue' => $g->sum('room_revenue'), 'amount' => $g->sum('amount')])->sortByDesc('amount');

        return view('accounts.commissions', [
            'rows' => $rows, 'summary' => $summary, 'status' => $status,
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
