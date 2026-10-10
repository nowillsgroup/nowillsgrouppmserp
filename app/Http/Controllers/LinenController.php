<?php

namespace App\Http\Controllers;

use App\Models\LinenBatch;
use App\Models\LinenMovement;
use App\Models\LinenType;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use App\Services\LinenService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LinenController extends Controller
{
    public function index()
    {
        $types = LinenService::parReport();
        $monthStart = bdate()->copy()->startOfMonth()->toDateString();

        return view('linen.index', [
            'types' => $types, 'all' => LinenType::orderBy('code')->get(),
            'moves' => LinenMovement::with('linen', 'batch', 'user')->latest('id')->limit(30)->get(),
            'levels' => (float) setting('linen_par_levels', '4'), 'rooms' => \App\Models\Room::count(),
            'stats' => [
                'store' => (int) $types->sum('qty_store'), 'floor' => (int) $types->sum('qty_floor'), 'soiled' => (int) $types->sum('qty_soiled'), 'laundry' => (int) $types->sum('qty_laundry'),
                'short' => $types->where('short', '>', 0)->count(),
                'loss' => (float) LinenMovement::whereIn('type', ['discard', 'lost'])->where('date', '>=', $monthStart)->sum('value'),
                'loss_n' => (int) LinenMovement::whereIn('type', ['discard', 'lost'])->where('date', '>=', $monthStart)->sum('qty'),
            ],
        ]);
    }

    public function move(Request $request)
    {
        $data = $request->validate([
            'linen_type_id' => 'required|integer|owned:linen_types', 'type' => ['required', Rule::in(LinenService::MANUAL)], 'qty' => 'required|integer|min:1|max:100000',
            'from' => ['nullable', 'required_if:type,discard,lost', Rule::in(array_keys(LinenType::LOCATIONS))], 'reason' => 'nullable|string|max:190',
            'value' => 'nullable|numeric|min:0|max:100000000',
        ]);
        $t = LinenType::findOrFail($data['linen_type_id']);
        $m = LinenService::move($t, $data['type'], (int) $data['qty'], ['from' => $data['from'] ?? null, 'reason' => $data['reason'] ?? null, 'value' => $data['value'] ?? null]);

        return back()->with('success', LinenMovement::TYPES[$m->type].": {$m->qty} × {$t->name}".($m->value > 0 ? ' ('.money($m->value).')' : '').'.');
    }

    public function count(Request $request)
    {
        $data = $request->validate(['linen_type_id' => 'required|integer|owned:linen_types', 'loc' => ['required', Rule::in(array_keys(LinenType::LOCATIONS))],
            'counted' => 'required|integer|min:0|max:100000', 'reason' => 'required|string|max:190']);
        $t = LinenType::findOrFail($data['linen_type_id']);
        $m = LinenService::count($t, $data['loc'], (int) $data['counted'], $data['reason']);

        return back()->with('success', $m ? "Count recorded – {$t->name} ".strtolower(LinenType::LOCATIONS[$data['loc']]).' '.($m->qty > 0 ? '+' : '').$m->qty.'.' : 'Count matches the system – nothing to adjust.');
    }

    public function saveType(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|owned:linen_types',
            'code' => ['required', 'string', 'max:12', 'alpha_dash', Rule::unique('linen_types')->where('hotel_id', hotel()->id)->ignore($request->input('id'))],
            'name' => 'required|string|max:80', 'per_room' => 'nullable|numeric|min:0|max:50', 'par_override' => 'nullable|integer|min:0|max:100000',
            'unit_cost' => 'nullable|numeric|min:0|max:1000000', 'wash_rate' => 'nullable|numeric|min:0|max:100000', 'is_active' => 'nullable|boolean',
        ]);
        $t = ! empty($data['id']) ? LinenType::findOrFail($data['id']) : new LinenType;
        $t->fill(['code' => strtoupper($data['code']), 'name' => $data['name'], 'per_room' => $data['per_room'] ?? 0, 'par_override' => $data['par_override'] ?? null,
            'unit_cost' => $data['unit_cost'] ?? 0, 'wash_rate' => $data['wash_rate'] ?? 0, 'is_active' => $request->boolean('is_active', true)])->save();
        ActivityLogger::log('linen', 'type', ($data['id'] ?? null ? 'Updated' : 'Added')." linen type {$t->code} {$t->name}", $t);

        return back()->with('success', "Linen type {$t->code} saved.");
    }

    public function saveLevels(Request $request)
    {
        $data = $request->validate(['linen_par_levels' => 'required|numeric|min:1|max:10']);
        Setting::updateOrCreate(['key' => 'linen_par_levels'], ['value' => (string) $data['linen_par_levels']]);

        return back()->with('success', 'Par levels updated.');
    }

    // ------------------------------------------------------------------ batches

    public function batches(Request $request)
    {
        $status = $request->input('status');

        return view('linen.batches', [
            'list' => LinenBatch::with('lines', 'supplier', 'bill')->when($status, fn ($q) => $status === 'open' ? $q->where('status', '!=', 'closed') : $q->where('status', $status))
                ->orderByRaw("status = 'closed'")->orderByDesc('sent_date')->orderByDesc('id')->paginate(25)->withQueryString(),
            'status' => $status, 'types' => LinenType::where('is_active', true)->orderBy('code')->get(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']), 'vendorId' => (int) setting('linen_default_supplier_id'),
        ]);
    }

    public function send(Request $request)
    {
        $data = $request->validate(['supplier_id' => 'nullable|integer|owned:suppliers', 'sent_date' => 'required|date', 'expected_date' => 'nullable|date',
            'notes' => 'nullable|string|max:255', 'qty' => 'required|array', 'qty.*' => 'nullable|integer|min:0|max:100000']);
        $b = LinenService::send($data, $data['qty']);
        if (! empty($data['supplier_id'])) {
            Setting::updateOrCreate(['key' => 'linen_default_supplier_id'], ['value' => (string) $data['supplier_id']]);
        }

        return redirect()->route('linen.batch', $b)->with('success', "Batch {$b->batch_no} sent – {$b->sent()} pcs.");
    }

    public function batch(LinenBatch $lbatch)
    {
        return view('linen.batch', ['b' => $lbatch->load('lines.linen', 'supplier', 'bill', 'creator'),
            'moves' => LinenMovement::with('linen', 'user')->where('linen_batch_id', $lbatch->id)->orderBy('id')->get()]);
    }

    public function receive(Request $request, LinenBatch $lbatch)
    {
        $data = $request->validate(['date' => 'required|date', 'ret' => 'nullable|array', 'ret.*' => 'nullable|integer|min:0', 'dmg' => 'nullable|array', 'dmg.*' => 'nullable|integer|min:0']);
        $lines = [];
        foreach ($lbatch->lines as $l) {
            $lines[$l->id] = ['returned' => (int) ($data['ret'][$l->id] ?? 0), 'damaged' => (int) ($data['dmg'][$l->id] ?? 0)];
        }
        $b = LinenService::receive($lbatch, $lines, $data['date']);

        return back()->with('success', $b->status === 'closed' ? "Batch {$b->batch_no} fully returned and closed." : "Received – {$b->outstanding()} pcs still at the laundry.");
    }

    public function close(Request $request, LinenBatch $lbatch)
    {
        $data = $request->validate(['reason' => 'required|string|max:190']);
        $n = $lbatch->load('lines')->outstanding();
        LinenService::close($lbatch, $data['reason']);

        return back()->with('success', "Batch {$lbatch->batch_no} closed – {$n} pcs written off as lost.");
    }

    public function bill(Request $request, LinenBatch $lbatch)
    {
        $data = $request->validate(['supplier_ref' => 'nullable|string|max:60', 'tax_rate' => 'nullable|numeric|min:0|max:100', 'doc_date' => 'nullable|date']);
        $bill = LinenService::bill($lbatch, $data);

        return back()->with('success', "Supplier bill {$bill->doc_no} created for ".money($bill->total).'.');
    }
}
