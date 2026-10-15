<?php

namespace App\Http\Controllers;

use App\Models\NightAudit;
use App\Services\NightAuditService;
use Illuminate\Http\Request;

class NightAuditController extends Controller
{
    public function index()
    {
        return view('nightaudit.index', NightAuditService::precheck() + ['history' => NightAudit::with('user')->orderByDesc('business_date')->paginate(15)]);
    }

    public function run(Request $request)
    {
        $data = $request->validate(['confirm' => 'accepted', 'notes' => 'nullable|string|max:1000']);
        $audit = NightAuditService::run($request->boolean('mark_no_shows', true), true, $data['notes'] ?? null);

        return redirect()->route('nightaudit.show', $audit)->with('success', 'Night audit completed. New business date: '.bdate()->format('d M Y'));
    }

    public function show(NightAudit $audit)
    {
        $d = $audit->business_date->toDateString();
        $tx = \App\Models\FolioTransaction::with('folio.guest', 'folio.stay.room', 'paymentMode')->whereDate('business_date', $d)->where('is_void', false)->get();

        return view('nightaudit.show', ['a' => $audit, 'charges' => $tx->where('type', 'charge')->groupBy('category')->map(fn ($g) => ['net' => $g->sum('amount'), 'tax' => $g->sum('tax_amount')]),
            'payments' => $tx->where('type', 'payment')->groupBy(fn ($t) => $t->paymentMode?->name ?? 'City Ledger')->map->sum('amount'),
            'pos' => \App\Models\PosOrder::with('outlet')->whereDate('business_date', $d)->where('status', 'settled')->get()->groupBy('outlet.name')->map(fn ($g) => $g->sum('total'))]);
    }
}
