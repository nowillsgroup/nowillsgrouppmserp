<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Department;
use App\Models\GatePass;
use App\Services\EInvoiceService;
use App\Services\GatePassService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v4.2 – Security › Gate passes: raise, approve, gate out, returns (partial), overdue follow-up, printable pass. */
class GatePassController extends Controller
{
    public const FILTERS = ['action' => 'Needs action', 'pending' => 'Awaiting approval', 'approved' => 'Ready to go out', 'away' => 'Goods out',
        'overdue' => 'Overdue', 'done' => 'Completed', 'all' => 'All'];

    protected function guarded(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function index(Request $request)
    {
        $f = array_key_exists($request->query('f'), self::FILTERS) ? $request->query('f') : 'action';
        $bd = bdate()->toDateString();
        $q = trim((string) $request->query('q'));
        $list = GatePass::with('items', 'department', 'requester')
            ->when(! can_do('security.view'), fn ($w) => $w->where('requested_by', auth()->id()))   // department users see their own passes
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('pass_no', 'like', "%{$q}%")->orWhere('party_name', 'like', "%{$q}%")
                ->orWhereHas('items', fn ($i) => $i->where('description', 'like', "%{$q}%"))))
            ->when($f === 'action', fn ($w) => $w->where(fn ($x) => $x->whereIn('status', ['pending', 'approved'])
                ->orWhere(fn ($o) => $o->where('type', 'returnable')->whereIn('status', GatePass::AWAY)->whereDate('expected_return_date', '<', $bd))))
            ->when(in_array($f, ['pending', 'approved'], true), fn ($w) => $w->where('status', $f))
            ->when($f === 'away', fn ($w) => $w->whereIn('status', GatePass::AWAY))
            ->when($f === 'overdue', fn ($w) => $w->where('type', 'returnable')->whereIn('status', GatePass::AWAY)->whereDate('expected_return_date', '<', $bd))
            ->when($f === 'done', fn ($w) => $w->whereIn('status', ['returned', 'closed', 'rejected', 'cancelled']))
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'partial', 'out') = 0")->latest('id')->paginate(40)->withQueryString();
        $counts = ['pending' => GatePass::where('status', 'pending')->count(), 'approved' => GatePass::where('status', 'approved')->count(),
            'away' => GatePass::whereIn('status', GatePass::AWAY)->count(),
            'overdue' => GatePass::where('type', 'returnable')->whereIn('status', GatePass::AWAY)->whereDate('expected_return_date', '<', $bd)->count(),
            'value_out' => (float) \App\Models\GatePassItem::whereHas('pass', fn ($p) => $p->whereIn('status', GatePass::AWAY))
                ->selectRaw('COALESCE(SUM(est_value * (quantity - returned_qty) / quantity), 0) v')->value('v')];

        return view('security.gatepasses', ['list' => $list, 'f' => $f, 'q' => $q, 'counts' => $counts]);
    }

    public function create(Request $request)
    {
        return view('security.gatepass-form', ['departments' => Department::orderBy('name')->pluck('name', 'id'),
            'assets' => Asset::where('status', '!=', 'disposed')->orderBy('asset_no')->get(['id', 'asset_no', 'name', 'status']),
            'auto' => ! \App\Services\SecurityService::cfg()['gp_approval'], 'asset' => $request->integer('asset') ?: null]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['type' => 'required|in:returnable,non_returnable', 'purpose' => ['required', Rule::in(array_keys(GatePass::PURPOSES))],
            'department_id' => 'nullable|integer|owned:departments', 'party_name' => 'required|string|max:150', 'party_phone' => 'nullable|string|max:30',
            'carrier_name' => 'nullable|string|max:120', 'vehicle_no' => 'nullable|string|max:20', 'expected_return_date' => 'nullable|required_if:type,returnable|date',
            'reason' => 'nullable|string|max:500', 'items' => 'required|array|min:1|max:30', 'items.*.description' => 'nullable|string|max:200',
            'items.*.asset_id' => 'nullable|integer|owned:assets', 'items.*.quantity' => 'nullable|numeric|min:0|max:99999', 'items.*.unit' => 'nullable|string|max:15',
            'items.*.est_value' => 'nullable|numeric|min:0|max:99999999']);

        return $this->guarded(function () use ($d) {
            $p = GatePassService::create($d);

            return redirect()->route('security.gp.show', $p)->with('success', "Gate pass {$p->pass_no} raised".($p->status === 'pending' ? ' – waiting for approval.' : ' and approved.'));
        });
    }

    protected function visible(GatePass $p): void
    {
        abort_unless(can_do('security.view') || $p->requested_by === auth()->id(), 403);
    }

    public function show(GatePass $gatepass)
    {
        $this->visible($gatepass);

        return view('security.gatepass', ['p' => $gatepass->load('items.asset', 'returns.receiver', 'department', 'requester', 'approver', 'outUser', 'closer')]);
    }

    public function print(GatePass $gatepass)
    {
        $this->visible($gatepass);
        abort_if(in_array($gatepass->status, ['pending', 'rejected', 'cancelled'], true), 404);

        return view('security.gatepass-print', ['p' => $gatepass->load('items.asset', 'department', 'requester', 'approver', 'outUser'),
            'qr' => EInvoiceService::qrSvg($gatepass->pass_no), 'hotel' => hotel()]);
    }

    public function approve(GatePass $gatepass)
    {
        return $this->guarded(function () use ($gatepass) {
            GatePassService::approve($gatepass);

            return back()->with('success', "Gate pass {$gatepass->pass_no} approved – security may let the goods out.");
        });
    }

    public function reject(Request $request, GatePass $gatepass)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->guarded(function () use ($gatepass, $d) {
            GatePassService::reject($gatepass, $d['reason']);

            return back()->with('success', "Gate pass {$gatepass->pass_no} rejected.");
        });
    }

    public function cancel(Request $request, GatePass $gatepass)
    {
        abort_unless(can_do('security.approve') || $gatepass->requested_by === auth()->id(), 403);
        $d = $request->validate(['reason' => 'nullable|string|max:255']);

        return $this->guarded(function () use ($gatepass, $d) {
            GatePassService::cancel($gatepass, $d['reason'] ?? 'Cancelled');

            return back()->with('success', "Gate pass {$gatepass->pass_no} cancelled.");
        });
    }

    public function out(Request $request, GatePass $gatepass)
    {
        $d = $request->validate(['carrier_name' => 'nullable|string|max:120', 'vehicle_no' => 'nullable|string|max:20']);

        return $this->guarded(function () use ($gatepass, $d) {
            GatePassService::markOut($gatepass, $d['carrier_name'] ?? null, $d['vehicle_no'] ?? null);

            return back()->with('success', "Gate pass {$gatepass->pass_no}: goods checked out through the gate.");
        });
    }

    public function receive(Request $request, GatePass $gatepass)
    {
        $d = $request->validate(['qty' => 'required|array', 'qty.*' => 'nullable|numeric|min:0|max:99999', 'carrier_name' => 'nullable|string|max:120', 'note' => 'nullable|string|max:255']);

        return $this->guarded(function () use ($gatepass, $d) {
            GatePassService::receive($gatepass, $d['qty'], $d['carrier_name'] ?? null, $d['note'] ?? null);

            return back()->with('success', $gatepass->status === 'returned' ? "Everything on {$gatepass->pass_no} is back – pass completed." : "Return recorded on {$gatepass->pass_no} – some items are still out.");
        });
    }

    public function close(Request $request, GatePass $gatepass)
    {
        $d = $request->validate(['note' => 'required|string|max:255']);

        return $this->guarded(function () use ($gatepass, $d) {
            GatePassService::close($gatepass, $d['note']);

            return back()->with('success', "Gate pass {$gatepass->pass_no} closed.");
        });
    }

    public function extend(Request $request, GatePass $gatepass)
    {
        $d = $request->validate(['date' => 'required|date']);

        return $this->guarded(function () use ($gatepass, $d) {
            GatePassService::extend($gatepass, $d['date']);

            return back()->with('success', "Return date for {$gatepass->pass_no} moved to ".$gatepass->expected_return_date->format('d M Y').'.');
        });
    }
}
