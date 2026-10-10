<?php

namespace App\Http\Controllers;

use App\Models\ItemCategory;
use App\Models\StockTake;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\StockTakeService;
use Illuminate\Http\Request;

class StockTakeController extends Controller
{
    public function index()
    {
        return view('inventory.stocktakes', [
            'takes' => StockTake::with('store', 'creator', 'poster')->withCount(['lines', 'lines as counted_count' => fn ($q) => $q->whereNotNull('counted_qty')])->latest('id')->paginate(25),
            'stores' => Store::orderBy('name')->pluck('name', 'id'),
            'categories' => ItemCategory::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['store_id' => 'required|integer|owned:stores', 'count_date' => 'required|date|before_or_equal:'.bdate()->toDateString(),
            'item_category_id' => 'nullable|integer|owned:item_categories', 'notes' => 'nullable|string|max:500']);
        $take = StockTakeService::create((int) $data['store_id'], $data['count_date'], $data['item_category_id'] ?? null, $data['notes'] ?? null);

        return redirect()->route('stocktakes.show', $take)->with('success', "Count sheet {$take->take_no} created. Enter the physical quantities.");
    }

    public function show(StockTake $stocktake)
    {
        $stocktake->load('store', 'lines.item.category', 'creator', 'poster');

        return view('inventory.stocktake-show', ['take' => $stocktake]);
    }

    public function update(Request $request, StockTake $stocktake)
    {
        $data = $request->validate(['counts' => 'array', 'counts.*' => 'nullable|numeric|min:0|max:99999999', 'remarks' => 'array', 'remarks.*' => 'nullable|string|max:190',
            'action' => 'nullable|in:save,post', 'blank_as_zero' => 'nullable|boolean']);
        $stocktake->load('lines');
        $n = StockTakeService::saveCounts($stocktake, $data['counts'] ?? [], $data['remarks'] ?? []);
        if (($data['action'] ?? 'save') === 'post') {
            $take = StockTakeService::post($stocktake, $request->boolean('blank_as_zero'));

            return redirect()->route('stocktakes.show', $take)->with('success', "Stock take posted. Variance value: ".money($take->variance_value).'.');
        }

        return back()->with('success', "Counts saved ({$n} line(s) changed).");
    }

    public function cancel(StockTake $stocktake)
    {
        StockTakeService::cancel($stocktake);

        return redirect()->route('stocktakes.index')->with('success', "Stock take {$stocktake->take_no} cancelled.");
    }

    public function print(Request $request, StockTake $stocktake)
    {
        $stocktake->load('store', 'lines.item.category');

        return view('inventory.stocktake-print', ['take' => $stocktake, 'blind' => $request->boolean('blind')]);
    }

    public function reorder()
    {
        return view('inventory.reorder', [
            'rows' => StockTakeService::reorderSuggestions(),
            'suppliers' => Supplier::orderBy('name')->pluck('name', 'id'),
            'stores' => Store::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function reorderStore(Request $request)
    {
        $data = $request->validate(['store_id' => 'required|integer|owned:stores', 'expected_date' => 'nullable|date|after_or_equal:today',
            'lines' => 'required|array', 'lines.*.pick' => 'nullable|boolean', 'lines.*.item_id' => 'required|integer|owned:inventory_items',
            'lines.*.supplier_id' => 'nullable|integer|owned:suppliers', 'lines.*.qty' => 'nullable|numeric|min:0', 'lines.*.rate' => 'nullable|numeric|min:0']);
        $lines = collect($data['lines'])->filter(fn ($l) => ! empty($l['pick']) && (float) ($l['qty'] ?? 0) > 0)->values();
        if ($lines->isEmpty()) {
            return back()->with('error', 'Tick at least one item with a quantity.');
        }
        if ($lines->contains(fn ($l) => empty($l['supplier_id']))) {
            return back()->with('error', 'Choose a supplier for every ticked item.');
        }
        $pos = StockTakeService::createPurchaseOrders($lines->map(fn ($l) => ['item_id' => (int) $l['item_id'], 'supplier_id' => (int) $l['supplier_id'], 'qty' => (float) $l['qty'], 'rate' => (float) ($l['rate'] ?? 0)])->all(),
            (int) $data['store_id'], $data['expected_date'] ?? null);

        return redirect()->route('purchases.index')->with('success', $pos->count().' draft purchase order(s) created: '.$pos->pluck('po_no')->join(', ').'. Review and approve them.');
    }
}
