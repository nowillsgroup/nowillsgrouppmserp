<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Currency;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use App\Services\ProcurementService;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    /**
     * v9.17 Purchase orders, read as three things at once.
     *
     * An order is a commitment the ledger does not carry until the goods arrive, a gate somebody with the right level has
     * to pass, and a document the shelf and the invoice both have to match. The register could list orders and nothing
     * else. Each reading is taken from the records that own it: the goods receipts against the order, the approval matrix
     * in ProcurementService (the same requiredLevel()/approvalBlocker() the approve button tests), and the order's bill
     * relation — filtered to bills that are actually on the ledger, as the goods-receipts screen filters it.
     */
    public function index(Request $request)
    {
        $asOf = bdate()->toDateString();
        $today = bdate()->startOfDay();
        $ageOf = fn ($d) => $d ? max(0, (int) round(($today->timestamp - \Carbon\Carbon::parse($d)->startOfDay()->timestamp) / 86400)) : 0;
        $onLedger = fn ($q) => $q->whereIn('status', ['open', 'partial', 'paid']);
        // what the shelf took in, per order, summed once — receivedValue() would ask the same question once per row
        $receivedOf = function (array $ids) {
            return GoodsReceipt::whereIn('purchase_order_id', $ids)->selectRaw('purchase_order_id, SUM(total) v')
                ->groupBy('purchase_order_id')->pluck('v', 'purchase_order_id');
        };
        $receivedFor = function ($po, $map) {
            $grn = round((float) ($map[$po->id] ?? 0), 2);

            return $grn > 0 ? $grn : ($po->status === 'received' ? round((float) $po->total, 2) : 0.0);
        };

        $orders = PurchaseOrder::with('supplier', 'store', ['bill' => $onLedger])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('late'), fn ($q) => $q->whereIn('status', ['approved', 'partial'])->whereDate('expected_date', '<', $asOf))
            ->latest('po_date')->latest('id')->paginate(25)->withQueryString();
        $pageReceived = $receivedOf($orders->getCollection()->pluck('id')->all());
        $orders->getCollection()->transform(function ($po) use ($pageReceived, $receivedFor) {
            $po->receipt_value = $receivedFor($po, $pageReceived);
            $po->bill_total = round((float) ($po->bill?->total ?? 0), 2);
            $po->open_value = round((float) $po->total - $po->receipt_value, 2);

            return $po;
        });

        // 1. the commitment: approved or part-received, and the goods have not all arrived — money promised, not yet posted
        $openOrders = PurchaseOrder::with('supplier')->whereIn('status', ['approved', 'partial'])->get();
        $openReceived = $receivedOf($openOrders->pluck('id')->all());
        $openRows = $openOrders->map(function ($po) use ($openReceived, $receivedFor, $asOf, $ageOf) {
            $received = $receivedFor($po, $openReceived);
            $expected = $po->expected_date;
            $late = $expected && $expected->toDateString() < $asOf ? $ageOf($expected) : 0;

            return ['po_no' => $po->po_no, 'supplier' => $po->supplier?->name, 'store' => $po->store?->name, 'expected' => $expected,
                'total' => round((float) $po->total, 2), 'received' => $received, 'open' => round((float) $po->total - $received, 2), 'late' => $late];
        })->filter(fn ($r) => $r['open'] > 0.005)->values();
        $lateRows = $openRows->filter(fn ($r) => $r['late'] > 0)->sortByDesc('late')->values();
        $commit = ['orders' => $openRows->count(), 'value' => round((float) $openRows->sum('open'), 2),
            'late' => $lateRows->count(), 'late_value' => round((float) $lateRows->sum('open'), 2),
            'worst' => $lateRows->first(), 'soonest' => $openRows->filter(fn ($r) => $r['late'] === 0 && $r['expected'])->sortBy(fn ($r) => $r['expected']->toDateString())->first(),
            'rows' => $openRows->sortByDesc('open')->take(10)->values()->all()];

        // 2. the gate: who has to approve what, and who may not — the matrix the approve button itself tests
        $drafts = PurchaseOrder::with('items', 'supplier')->where('status', 'draft')->where('total', '>', 0)->get();
        $levelRows = [];
        foreach ([1, 2, 3] as $level) {
            $set = $drafts->filter(fn ($po) => ProcurementService::requiredLevel($po) === $level)->sortByDesc('total')->values();
            $levelRows[] = ['level' => $level, 'label' => ProcurementService::levelLabel($level), 'count' => $set->count(),
                'value' => round((float) $set->sum('total'), 2), 'largest' => $set->first()?->po_no, 'largest_value' => round((float) ($set->first()?->total ?? 0), 2)];
        }
        $blocked = $drafts->map(fn ($po) => ['po' => $po, 'why' => ProcurementService::approvalBlocker($po)])->filter(fn ($r) => $r['why'] !== null)->values();
        $gate = ['drafts' => $drafts->count(), 'value' => round((float) $drafts->sum('total'), 2), 'levels' => $levelRows,
            'approvable' => $drafts->count() - $blocked->count(),
            'approvable_value' => round((float) $drafts->sum('total') - (float) $blocked->sum(fn ($r) => (float) $r['po']->total), 2),
            'largest_approvable' => $drafts->reject(fn ($po) => ProcurementService::approvalBlocker($po) !== null)->sortByDesc('total')->first(),
            'blocked' => $blocked->count(), 'blocked_value' => round((float) $blocked->sum(fn ($r) => (float) $r['po']->total), 2),
            'blocked_rows' => $blocked->take(10)->map(fn ($r) => ['po_no' => $r['po']->po_no, 'supplier' => $r['po']->supplier?->name,
                'total' => round((float) $r['po']->total, 2), 'why' => $r['why']])->all(),
            'user_level' => ProcurementService::userLevel(), 'limits' => ProcurementService::limits(),
            'segregation' => setting('po_segregation', '0') === '1'];

        // 3. the match: a receipt and the bill for it must be the same money, order by order
        $matched = PurchaseOrder::with('supplier', 'store', ['bill' => $onLedger])->whereIn('status', ['received', 'partial', 'closed'])->get();
        $matchReceived = $receivedOf($matched->pluck('id')->all());
        $matchRows = $matched->map(function ($po) use ($matchReceived, $receivedFor) {
            $received = $receivedFor($po, $matchReceived);
            $billed = round((float) ($po->bill?->total ?? 0), 2);

            return ['po_no' => $po->po_no, 'supplier' => $po->supplier?->name, 'store' => $po->store?->name, 'status' => $po->status,
                'ordered' => round((float) $po->total, 2), 'received' => $received, 'billed' => $billed,
                'bill_no' => $po->bill?->doc_no, 'diff' => round($received - $billed, 2)];
        })->filter(fn ($r) => $r['received'] > 0.005)->values();
        $breaks = $matchRows->filter(fn ($r) => abs($r['diff']) > 0.005)->sortByDesc(fn ($r) => abs($r['diff']))->values();
        $match = ['orders' => $matchRows->count(), 'received' => round((float) $matchRows->sum('received'), 2),
            'billed' => round((float) $matchRows->sum('billed'), 2), 'diff' => round((float) $matchRows->sum('diff'), 2),
            'breaks' => $breaks->count(), 'rows' => $breaks->take(10)->all(),
            'largest' => $matchRows->sortByDesc('received')->first(), 'unbilled' => $matchRows->filter(fn ($r) => $r['billed'] <= 0.005)->count()];

        return view('inventory.purchases', ['orders' => $orders, 'status' => $request->status, 'as_of' => $asOf,
            'commit' => $commit, 'gate' => $gate, 'match' => $match,
            'mine' => ProcurementService::awaitingMyApproval(), 'late_pos' => ProcurementService::overdueDeliveries(),
            'limits' => ProcurementService::limits(), 'segregation' => $gate['segregation'],
            'suppliers' => Supplier::where('is_active', true)->pluck('name', 'id')]);
    }

    public function create()
    {
        $home = strtoupper(trim((string) (hotel()?->currency ?: 'GHS')));

        return view('inventory.po-form', [
            'suppliers' => Supplier::where('is_active', true)->pluck('name', 'id'),
            'stores' => Store::pluck('name', 'id'),
            'items' => InventoryItem::where('is_active', true)->orderBy('name')->get(),
            'prices' => ProcurementService::priceMap(), 'limits' => ProcurementService::limits(),
            'currencies' => Currency::where('is_active', true)->orderBy('sort_order')->orderBy('code')->get(),
            'home' => $home, 'fxEnabled' => \App\Services\ForexService::apConvert(),
            'fxRateUrl' => route('purchases.fx-rate'),
        ]);
    }

    public function fxRate(Request $request)
    {
        $data = $request->validate(['currency' => 'required|string|size:3', 'date' => 'required|date']);
        try {
            return response()->json(ProcurementService::poRate($data['currency'], $data['date']));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer|owned:suppliers', 'store_id' => 'required|integer|owned:stores', 'po_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:po_date', 'notes' => 'nullable|string|max:1000',
            'currency' => 'nullable|string|size:3',
            'lines' => 'required|array|min:1', 'lines.*.item_id' => 'required|integer|owned:inventory_items',
            'lines.*.qty' => 'required|numeric|min:0.01', 'lines.*.rate' => 'required|numeric|min:0|max:999999999', 'lines.*.tax_rate' => 'nullable|numeric|min:0|max:50',
        ]);
        try {
            $po = ProcurementService::createPurchaseOrder($data);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = "Purchase order {$po->po_no} created.";
        if ($po->isForeign()) {
            $message .= " {$po->fxLabel()} at ".number_format((float) $po->fx_rate, 4)." {$po->fx_basis}, board of {$po->fx_date->format('d M Y')} = ".money($po->total).'.';
        }

        return redirect()->route('purchases.show', $po)->with('success', $message);
    }

    public function show(PurchaseOrder $po)
    {
        $po->load('supplier', 'store', 'items.item', 'creator', 'approver', 'receipts.lines.item', 'receipts.receiver', 'rfq');

        return view('inventory.po-show', ['po' => $po, 'level' => ProcurementService::requiredLevel($po), 'blocker' => $po->status === 'draft' ? ProcurementService::approvalBlocker($po) : null,
            'overPct' => ProcurementService::overReceiptPct()]);
    }

    public function status(Request $request, PurchaseOrder $po)
    {
        $data = $request->validate(['status' => 'required|in:approved,received,cancelled', 'invoice_no' => 'nullable|string|max:60']);
        if ($data['status'] === 'received' && $po->isForeign()) {
            return back()->with('error', 'Foreign-currency purchase orders must be received through a GRN so the receipt-date rate and local stock value are recorded.');
        }
        $allowed = ['draft' => ['approved', 'cancelled'], 'approved' => ['received', 'cancelled']];
        abort_unless(in_array($data['status'], $allowed[$po->status] ?? []), 422, 'Invalid status transition.');
        abort_if($data['status'] !== 'approved' && ! can_do('inventory.manage'), 403); // approvers without store rights can only approve
        if ($data['status'] === 'approved') { // v4.8 approval matrix
            try {
                ProcurementService::approve($po);
            } catch (\RuntimeException $e) {
                return back()->with('error', $e->getMessage());
            }

            return back()->with('success', "{$po->po_no} approved.");
        }
        DB::transaction(function () use ($po, $data) {
            if ($data['status'] === 'received') {
                foreach ($po->items as $l) {
                    StockMovement::create(['inventory_item_id' => $l->inventory_item_id, 'store_id' => $po->store_id, 'date' => bdate(), 'type' => 'purchase', 'qty' => $l->qty, 'rate' => $l->rate, 'reference' => $po->po_no, 'user_id' => auth()->id()]);
                    $l->item->update(['last_cost' => $l->rate]);
                    $l->forceFill(['received_qty' => $l->qty])->save(); // v4.8
                }
                $po->received_at = now();
                $po->invoice_no = $data['invoice_no'] ?? $po->invoice_no;
            }
            $po->status = $data['status'];
            $po->save();
        });
        ActivityLogger::log('inventory', 'po_'.$data['status'], "{$po->po_no} {$data['status']}", $po);

        // v1.8: the supplier's invoice arrived with the goods and matches the PO – record the bill in one step
        if ($data['status'] === 'received' && $request->boolean('create_bill') && ! empty($data['invoice_no']) && can_do('ap.manage')) {
            try {
                $bill = \App\Services\ApService::billFromReceipt($po, ['supplier_ref' => $data['invoice_no'], 'doc_date' => bdate()->toDateString()]);

                return back()->with('success', "Goods received and supplier bill {$bill->doc_no} recorded (due ".$bill->due_date->format('d M Y').').');
            } catch (\RuntimeException $e) {
                return back()->with('success', 'Goods received.')->with('error', 'Supplier bill not recorded: '.$e->getMessage());
            }
        }

        return back()->with('success', "PO {$data['status']}.");
    }

    /** v4.8 Goods receipt note – full or part delivery, with rejections. */
    public function receive(Request $request, PurchaseOrder $po)
    {
        $data = $request->validate([
            'date' => 'required|date', 'delivery_note' => 'nullable|string|max:60', 'invoice_no' => 'nullable|string|max:60', 'vehicle_no' => 'nullable|string|max:30',
            'notes' => 'nullable|string|max:500', 'lines' => 'required|array', 'lines.*.delivered' => 'nullable|numeric|min:0', 'lines.*.rejected' => 'nullable|numeric|min:0',
            'lines.*.reason' => 'nullable|string|max:120',
        ]);
        try {
            $grn = ProcurementService::receive($po, $data);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $po->refresh()->forgetGrnCache();
        $msg = "{$grn->grn_no} recorded – ".money($grn->total).' taken into stock'.($grn->isForeign()
            ? " (".$grn->fxLabel().' at '.number_format((float) $grn->fx_rate, 4)." {$grn->fx_basis}, board of {$grn->fx_date->format('d M Y')})" : '')
            .($po->status === 'partial' ? '; the balance is still on order.' : '; PO fully received.');
        if ($po->status === 'received' && $request->boolean('create_bill') && ! empty($data['invoice_no']) && can_do('ap.manage')) {
            try {
                $bill = \App\Services\ApService::billFromReceipt($po, ['supplier_ref' => $data['invoice_no'], 'doc_date' => bdate()->toDateString()]);
                $msg .= " Supplier bill {$bill->doc_no} recorded.";
            } catch (\RuntimeException $e) {
                return back()->with('success', $msg)->with('error', 'Supplier bill not recorded: '.$e->getMessage());
            }
        }

        return back()->with('success', $msg);
    }

    /** v4.8 Short-close: the undelivered balance is cancelled. */
    public function close(Request $request, PurchaseOrder $po)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);
        try {
            ProcurementService::shortClose($po, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$po->po_no} closed – the balance will not be delivered.");
    }

    /** v4.8 Printable goods received note. */
    public function grn(\App\Models\GoodsReceipt $grn)
    {
        $grn->load('lines.item', 'order', 'supplier', 'store', 'receiver');

        return view('procurement.grn-print', compact('grn'));
    }
}
