<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use App\Support\Numbering;
use Illuminate\Http\Request;
use App\Services\ProcurementService;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        return view('inventory.purchases', ['orders' => PurchaseOrder::with('supplier', 'store')->when($request->status, fn ($q, $s) => $q->where('status', $s))->latest('po_date')->paginate(25)->withQueryString()]);
    }

    public function create()
    {
        return view('inventory.po-form', ['suppliers' => Supplier::where('is_active', true)->pluck('name', 'id'), 'stores' => Store::pluck('name', 'id'), 'items' => InventoryItem::where('is_active', true)->orderBy('name')->get(),
            'prices' => ProcurementService::priceMap(), 'limits' => ProcurementService::limits()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer|owned:suppliers', 'store_id' => 'required|integer|owned:stores', 'po_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:po_date', 'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1', 'lines.*.item_id' => 'required|integer|owned:inventory_items',
            'lines.*.qty' => 'required|numeric|min:0.01', 'lines.*.rate' => 'required|numeric|min:0', 'lines.*.tax_rate' => 'nullable|numeric|min:0|max:50',
        ]);
        $po = DB::transaction(function () use ($data) {
            $po = PurchaseOrder::create(collect($data)->except('lines')->all() + ['po_no' => Numbering::next('po'), 'status' => 'draft', 'created_by' => auth()->id()]);
            $sub = 0; $tax = 0;
            foreach ($data['lines'] as $l) {
                $amt = round($l['qty'] * $l['rate'], 2);
                $po->items()->create(['inventory_item_id' => $l['item_id'], 'qty' => $l['qty'], 'rate' => $l['rate'], 'tax_rate' => $l['tax_rate'] ?? 0, 'amount' => $amt]);
                $sub += $amt; $tax += $amt * ($l['tax_rate'] ?? 0) / 100;
            }
            $po->update(['subtotal' => $sub, 'tax' => round($tax, 2), 'total' => round($sub + $tax, 2)]);
            ProcurementService::stampListRates($po); // v4.8: flag lines priced above the agreed rate

            return $po;
        });
        ActivityLogger::log('inventory', 'po_create', "Created {$po->po_no}", $po);

        return redirect()->route('purchases.show', $po)->with('success', "Purchase order {$po->po_no} created.");
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
        $msg = "{$grn->grn_no} recorded – ".money($grn->total).' taken into stock'.($po->status === 'partial' ? '; the balance is still on order.' : '; PO fully received.');
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
