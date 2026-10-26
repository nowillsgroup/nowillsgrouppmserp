<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\Department;
use App\Models\InventoryItem;
use App\Models\Requisition;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\StoreIssue;
use App\Models\StorePar;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\RequisitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RequisitionController extends Controller
{
    public const TABS = ['action' => 'Needs my action', 'open' => 'Open', 'draft' => 'Drafts', 'submitted' => 'Awaiting approval', 'approved' => 'To issue',
        'partial' => 'Part issued', 'receipt' => 'Awaiting receipt', 'done' => 'Completed', 'all' => 'All'];

    public function index(Request $request)
    {
        $u = auth()->user();
        $tab = array_key_exists($request->input('show'), self::TABS) ? $request->input('show') : 'open';
        $q = Requisition::with('department', 'fromStore', 'toStore', 'raiser', 'approver', 'lines', 'issues')
            ->when($request->department, fn ($q, $d) => $q->where('department_id', $d))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('req_no', 'like', "%$t%")->orWhere('purpose', 'like', "%$t%")))
            ->when($request->boolean('mine'), fn ($q) => $q->where('raised_by', $u->id));
        $all = $q->orderByDesc('id')->get();
        $needs = fn ($r) => (RequisitionService::canApprove($u, $r)) || (RequisitionService::canIssue($u, $r))
            || ($u->hasPermission('requisitions.raise') && (int) $r->raised_by === (int) $u->id && $r->issues->contains(fn ($i) => ! $i->received_at))
            || ($r->status === 'draft' && (int) $r->raised_by === (int) $u->id);
        $filter = [
            'action' => $needs,
            'open' => fn ($r) => in_array($r->status, ['draft', 'submitted', 'approved', 'partial'], true),
            'draft' => fn ($r) => $r->status === 'draft', 'submitted' => fn ($r) => $r->status === 'submitted',
            'approved' => fn ($r) => $r->status === 'approved', 'partial' => fn ($r) => $r->status === 'partial',
            'receipt' => fn ($r) => $r->issues->contains(fn ($i) => ! $i->received_at),
            'done' => fn ($r) => in_array($r->status, ['issued', 'closed', 'rejected', 'cancelled'], true),
            'all' => fn ($r) => true,
        ];
        $counts = collect($filter)->map(fn ($f) => $all->filter($f)->count());
        $monthFrom = bdate()->copy()->startOfMonth()->toDateString();

        return view('requisitions.index', [
            'rows' => $all->filter($filter[$tab])->values(), 'tab' => $tab, 'counts' => $counts,
            'departments' => Department::orderBy('name')->pluck('name', 'id'),
            'monthIssued' => (float) StoreIssue::whereBetween('date', [$monthFrom, bdate()->toDateString()])->sum('value'),
            'monthIssues' => StoreIssue::whereBetween('date', [$monthFrom, bdate()->toDateString()])->count(),
        ]);
    }

    public function create(Request $request)
    {
        $deptId = (int) ($request->input('department') ?: RequisitionService::userDepartmentId());
        $dept = $deptId ? Department::find($deptId) : null;
        $toStore = $request->has('to_store') ? ((int) $request->input('to_store') ?: null) : $dept?->default_store_id;
        $lines = [];
        if ($request->boolean('fill') && $toStore) {
            $lines = RequisitionService::parSuggestions($toStore)->filter(fn ($s) => $s->suggest > 0)
                ->map(fn ($s) => ['item_id' => $s->item->id, 'qty' => $s->suggest, 'remarks' => 'Top-up to par '.rtrim(rtrim(number_format($s->par, 2, '.', ''), '0'), '.')])->values()->all();
        }
        $r = new Requisition(['department_id' => $dept?->id, 'from_store_id' => (int) ($request->input('from_store') ?: Store::where('type', 'main')->value('id')),
            'to_store_id' => $toStore, 'priority' => 'normal']);

        return view('requisitions.form', $this->formData($r, $lines) + ['filled' => $request->boolean('fill')]);
    }

    public function store(Request $request)
    {
        $r = RequisitionService::saveDraft($this->validated($request));
        if ($request->input('action') === 'submit') {
            $r = RequisitionService::submit($r);

            return redirect()->route('req.show', $r)->with('success', "Requisition {$r->req_no} ".($r->status === 'approved' ? 'submitted and auto-approved.' : 'submitted for approval.'));
        }
        ActivityLogger::log('inventory', 'requisition', "Requisition {$r->req_no} saved as draft", $r);

        return redirect()->route('req.show', $r)->with('success', "Requisition {$r->req_no} saved as a draft.");
    }

    public function edit(Requisition $req)
    {
        abort_unless(RequisitionService::canEdit(auth()->user(), $req), 403);
        $lines = $req->lines->map(fn ($l) => ['item_id' => $l->inventory_item_id, 'qty' => (float) $l->qty_requested, 'remarks' => $l->remarks])->all();

        return view('requisitions.form', $this->formData($req, $lines) + ['filled' => false]);
    }

    public function update(Request $request, Requisition $req)
    {
        abort_unless(RequisitionService::canEdit(auth()->user(), $req), 403);
        $req = RequisitionService::saveDraft($this->validated($request), $req);
        if ($request->input('action') === 'submit') {
            $req = RequisitionService::submit($req);

            return redirect()->route('req.show', $req)->with('success', "Requisition {$req->req_no} ".($req->status === 'approved' ? 'submitted and auto-approved.' : 'submitted for approval.'));
        }

        return redirect()->route('req.show', $req)->with('success', 'Draft saved.');
    }

    public function show(Requisition $req)
    {
        $req->load('lines.item', 'issues.lines.item', 'issues.issuer', 'issues.receiver', 'department.hod', 'fromStore', 'toStore', 'raiser', 'approver', 'closer');
        $u = auth()->user();
        $stock = StockMovement::where('store_id', $req->from_store_id)->whereIn('inventory_item_id', $req->lines->pluck('inventory_item_id'))
            ->groupBy('inventory_item_id')->selectRaw('inventory_item_id, SUM(qty) q')->pluck('q', 'inventory_item_id');
        $toStock = $req->to_store_id ? StockMovement::where('store_id', $req->to_store_id)->whereIn('inventory_item_id', $req->lines->pluck('inventory_item_id'))
            ->groupBy('inventory_item_id')->selectRaw('inventory_item_id, SUM(qty) q')->pluck('q', 'inventory_item_id') : collect();

        return view('requisitions.show', [
            'r' => $req, 'stock' => $stock, 'toStock' => $toStock,
            'budget' => RequisitionService::budgetStatus($req->department, null, $req->status === 'submitted' ? $req->id : null),
            'canApprove' => RequisitionService::canApprove($u, $req), 'approvalBlocker' => $req->status === 'submitted' ? RequisitionService::approvalBlocker($u, $req) : null,
            'canIssue' => RequisitionService::canIssue($u, $req), 'canEdit' => RequisitionService::canEdit($u, $req),
            'canCancel' => RequisitionService::canCancel($u, $req) && $u->hasPermission('requisitions.raise'),
        ]);
    }

    public function submit(Requisition $req)
    {
        abort_unless(RequisitionService::canEdit(auth()->user(), $req), 403);
        $req = RequisitionService::submit($req);

        return back()->with('success', "Requisition {$req->req_no} ".($req->status === 'approved' ? 'submitted and auto-approved.' : 'submitted for approval.'));
    }

    public function approve(Request $request, Requisition $req)
    {
        $data = $request->validate(['qty' => 'nullable|array', 'qty.*' => 'nullable|numeric|min:0', 'approval_note' => 'nullable|string|max:255', 'over_budget_ok' => 'nullable|boolean']);
        $req = RequisitionService::approve($req, $data['qty'] ?? [], $data['approval_note'] ?? null, (bool) ($data['over_budget_ok'] ?? false));

        return back()->with('success', "Requisition {$req->req_no} approved – ".money($req->est_value).'. The store can issue it now.');
    }

    public function reject(Request $request, Requisition $req)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        RequisitionService::reject($req, $data['reason']);

        return back()->with('success', "Requisition {$req->req_no} rejected.");
    }

    public function issue(Request $request, Requisition $req)
    {
        $data = $request->validate(['qty' => 'required|array', 'qty.*' => 'nullable|numeric|min:0', 'date' => 'required|date', 'notes' => 'nullable|string|max:255']);
        $issue = RequisitionService::issue($req, $data['qty'], $data['date'], $data['notes'] ?? null);
        $req->refresh();

        return back()->with('success', "Issue note {$issue->issue_no} – ".money($issue->value).($req->status === 'issued' ? '. Requisition fully issued.' : '. The rest is still to issue.'));
    }

    public function acknowledge(Request $request, StoreIssue $sissue)
    {
        $data = $request->validate(['receipt_note' => 'nullable|string|max:255']);
        RequisitionService::acknowledge($sissue, $data['receipt_note'] ?? null);

        return back()->with('success', "Receipt of {$sissue->issue_no} acknowledged.");
    }

    public function close(Request $request, Requisition $req)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        RequisitionService::closeShort($req, $data['reason']);

        return back()->with('success', "Requisition {$req->req_no} closed short.");
    }

    public function cancel(Requisition $req)
    {
        abort_unless(RequisitionService::canCancel(auth()->user(), $req), 403);
        RequisitionService::cancel($req);

        return back()->with('success', "Requisition {$req->req_no} cancelled.");
    }

    public function repeat(Requisition $req)
    {
        $new = RequisitionService::repeat($req);
        ActivityLogger::log('inventory', 'requisition', "Requisition {$new->req_no} drafted as a repeat of {$req->req_no}", $new);

        return redirect()->route('req.edit', $new)->with('success', "Draft {$new->req_no} created from {$req->req_no} – check the quantities and submit.");
    }

    public function purchase(Requisition $req)
    {
        [$pos, $skipped] = RequisitionService::raisePurchase($req);
        $msg = $pos->count() ? $pos->count().' draft purchase order(s) created: '.$pos->pluck('po_no')->join(', ').'.' : 'No purchase order created.';
        if ($skipped->isNotEmpty()) {
            $msg .= ' No purchase history for '.$skipped->join(', ').' – order '.($skipped->count() > 1 ? 'those' : 'it').' by hand.';
        }

        return back()->with($pos->count() ? 'success' : 'warning', $msg);
    }

    public function print(Requisition $req)
    {
        $req->load('lines.item', 'issues.lines.item', 'issues.issuer', 'issues.receiver', 'department', 'fromStore', 'toStore', 'raiser', 'approver');

        return view('requisitions.print', ['r' => $req, 'issue' => null, 'hotel' => \App\Support\Tenant::hotel()]);
    }

    public function printIssue(StoreIssue $sissue)
    {
        $sissue->load('lines.item', 'lines.requisitionLine', 'issuer', 'receiver', 'department', 'fromStore', 'toStore', 'requisition.raiser', 'requisition.approver');

        return view('requisitions.print', ['r' => $sissue->requisition, 'issue' => $sissue, 'hotel' => \App\Support\Tenant::hotel()]);
    }

    // ------------------------------------------------------------------ setup: departments, budgets, pars

    public function setup()
    {
        $depts = Department::with('hod', 'expenseHead', 'defaultStore')->orderBy('name')->get()
            ->each(fn ($d) => $d->budget_status = RequisitionService::budgetStatus($d));

        return view('requisitions.setup', [
            'departments' => $depts,
            'users' => User::visibleToHotel()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'heads' => AccountHead::where('type', 'expense')->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($h) => [$h->id => $h->code.' – '.$h->name]),
            'stores' => Store::orderBy('name')->pluck('name', 'id'),
            'settings' => Setting::whereIn('key', ['req_auto_approve_below', 'req_over_budget'])->pluck('value', 'key'),
        ]);
    }

    public function updateDepartment(Request $request, Department $rdept)
    {
        $data = $request->validate([
            'hod_user_id' => 'nullable|integer|owned:users', 'expense_head_id' => ['nullable', 'integer', Rule::exists('account_heads', 'id')->where('hotel_id', \App\Support\Tenant::id())->where('type', 'expense')],
            'default_store_id' => 'nullable|integer|owned:stores', 'monthly_budget' => 'nullable|numeric|min:0|max:999999999',
        ]);
        $rdept->forceFill($data + ['monthly_budget' => null])->save();
        ActivityLogger::log('inventory', 'department', "Requisition setup for {$rdept->name} updated", $rdept);

        return back()->with('success', "{$rdept->name} updated.");
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate(['req_auto_approve_below' => 'required|numeric|min:0|max:10000000', 'req_over_budget' => 'required|in:warn,block']);
        foreach ($data as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        Setting::$version++;
        ActivityLogger::log('settings', 'requisitions', 'Updated requisition settings');

        return back()->with('success', 'Requisition settings saved.');
    }

    public function pars(Request $request)
    {
        $stores = Store::where('type', '!=', 'main')->orderBy('name')->get();
        $store = $stores->firstWhere('id', (int) $request->input('store')) ?? $stores->first();
        $rows = collect();
        if ($store) {
            $pars = StorePar::where('store_id', $store->id)->pluck('par_qty', 'inventory_item_id');
            $stock = StockMovement::where('store_id', $store->id)->groupBy('inventory_item_id')->selectRaw('inventory_item_id, SUM(qty) q')->pluck('q', 'inventory_item_id');
            $sugg = RequisitionService::parSuggestions($store->id)->keyBy(fn ($s) => $s->item->id);
            $rows = InventoryItem::with('category')->where('is_active', true)->orderBy('name')->get()
                ->map(fn ($i) => (object) ['item' => $i, 'par' => isset($pars[$i->id]) ? (float) $pars[$i->id] : null, 'stock' => round((float) ($stock[$i->id] ?? 0), 2),
                    'pending' => $sugg[$i->id]->pending ?? 0, 'suggest' => $sugg[$i->id]->suggest ?? 0])
                ->sortBy(fn ($r) => [$r->par === null ? 1 : 0, $r->item->name])->values();
        }
        $dept = $store ? Department::where('default_store_id', $store->id)->first() : null;

        return view('requisitions.pars', ['stores' => $stores, 'store' => $store, 'rows' => $rows, 'dept' => $dept]);
    }

    public function savePars(Request $request)
    {
        $data = $request->validate(['store_id' => 'required|integer|owned:stores', 'par' => 'nullable|array', 'par.*' => 'nullable|numeric|min:0|max:9999999']);
        $store = Store::findOrFail($data['store_id']);
        abort_if($store->type === 'main', 422, 'The main store has re-order levels, not par levels.');
        $valid = InventoryItem::pluck('id')->flip();
        $n = 0;
        DB::transaction(function () use ($data, $store, $valid, &$n) {
            foreach ($data['par'] ?? [] as $itemId => $par) {
                if (! isset($valid[(int) $itemId])) {
                    continue;
                }
                if ($par === null || $par === '' || (float) $par <= 0) {
                    StorePar::where('store_id', $store->id)->where('inventory_item_id', (int) $itemId)->delete();

                    continue;
                }
                StorePar::updateOrCreate(['store_id' => $store->id, 'inventory_item_id' => (int) $itemId], ['par_qty' => round((float) $par, 2)]);
                $n++;
            }
        });
        ActivityLogger::log('inventory', 'store_par', "Par levels for {$store->name} saved ({$n} item(s))");

        return back()->with('success', "Par levels for {$store->name} saved – {$n} item(s) with a par.");
    }

    // ------------------------------------------------------------------ helpers

    protected function validated(Request $request): array
    {
        return $request->validate([
            'department_id' => 'required|integer|owned:departments', 'from_store_id' => 'required|integer|owned:stores',
            'to_store_id' => 'nullable|integer|owned:stores', 'required_by' => 'nullable|date', 'priority' => 'nullable|in:normal,urgent',
            'purpose' => 'nullable|string|max:255', 'lines' => 'required|array|min:1', 'lines.*.item_id' => 'nullable|integer|owned:inventory_items',
            'lines.*.qty' => 'nullable|numeric|min:0|max:999999', 'lines.*.remarks' => 'nullable|string|max:190',
        ], ['lines.required' => 'Add at least one item.']);
    }

    protected function formData(Requisition $r, array $lines): array
    {
        $stores = Store::orderBy('name')->get();
        $stock = StockMovement::groupBy('inventory_item_id', 'store_id')->selectRaw('inventory_item_id, store_id, SUM(qty) q')->get()
            ->groupBy('inventory_item_id')->map(fn ($g) => $g->mapWithKeys(fn ($m) => [$m->store_id => round((float) $m->q, 2)]));
        $items = InventoryItem::with('category')->where('is_active', true)->orderBy('name')->get();
        $depts = Department::orderBy('name')->get();

        return [
            'r' => $r, 'lines' => $lines ?: [['item_id' => null, 'qty' => null, 'remarks' => null]],
            'departments' => $depts, 'stores' => $stores, 'items' => $items,
            'itemData' => $items->mapWithKeys(fn ($i) => [$i->id => ['u' => $i->unit, 'c' => (float) $i->last_cost, 's' => $stock[$i->id] ?? (object) []]]),
            'deptData' => $depts->mapWithKeys(fn ($d) => [$d->id => ['store' => $d->default_store_id, 'b' => RequisitionService::budgetStatus($d, null, $r->exists ? $r->id : null)]]),
            'parStores' => StorePar::distinct()->pluck('store_id')->all(),
        ];
    }
}
