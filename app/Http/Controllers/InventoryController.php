<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $stores = Store::orderBy('name')->get();
        $stock = StockMovement::select('inventory_item_id', 'store_id', DB::raw('SUM(qty) as qty'))->groupBy('inventory_item_id', 'store_id')->get()->groupBy('inventory_item_id');
        $items = InventoryItem::with('category')->where('is_active', true)
            ->when($request->category, fn ($q, $c) => $q->where('item_category_id', $c))
            ->when($request->q, fn ($q, $t) => $q->where('name', 'like', "%$t%"))->orderBy('name')->get()
            ->each(function ($i) use ($stock) {
                $i->by_store = ($stock[$i->id] ?? collect())->pluck('qty', 'store_id');
                $i->total = (float) $i->by_store->sum();
            });
        if ($request->boolean('low')) {
            $items = $items->filter(fn ($i) => $i->total <= $i->reorder_level);
        }

        return view('inventory.index', ['items' => $items, 'stores' => $stores, 'categories' => ItemCategory::pluck('name', 'id'),
            'value' => $items->sum(fn ($i) => $i->total * $i->last_cost)]);
    }

    public function movement()
    {
        return view('inventory.movement', ['items' => InventoryItem::where('is_active', true)->orderBy('name')->get(), 'stores' => Store::pluck('name', 'id'),
            'departments' => \App\Models\Department::orderBy('name')->pluck('name', 'name')]);
    }

    public function storeMovement(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:issue,transfer,adjustment,wastage,opening', 'store_id' => 'required|integer|owned:stores',
            'to_store_id' => 'nullable|required_if:type,transfer|different:store_id|integer|owned:stores',
            'date' => 'required|date', 'department' => 'nullable|string|max:80', 'remarks' => 'nullable|string|max:255',
            'lines' => 'required|array|min:1', 'lines.*.item_id' => 'required|integer|owned:inventory_items',
            'lines.*.qty' => 'required|numeric|not_in:0', 'adjust_sign' => 'nullable|in:plus,minus',
        ]);
        DB::transaction(function () use ($data) {
            foreach ($data['lines'] as $line) {
                $item = InventoryItem::findOrFail($line['item_id']);
                $qty = abs((float) $line['qty']);
                $current = $item->stock((int) $data['store_id']);
                $out = in_array($data['type'], ['issue', 'transfer', 'wastage']) || ($data['type'] === 'adjustment' && ($data['adjust_sign'] ?? 'minus') === 'minus');
                if ($out && $qty > $current + 0.0001) {
                    throw ValidationException::withMessages(['lines' => "Insufficient stock of {$item->name} (available {$current} {$item->unit})."]);
                }
                $base = ['inventory_item_id' => $item->id, 'date' => $data['date'], 'rate' => $item->last_cost, 'department' => $data['department'] ?? null, 'remarks' => $data['remarks'] ?? null, 'user_id' => auth()->id(),
                    'department_id' => $data['type'] === 'issue' && ! empty($data['department']) ? \App\Models\Department::where('name', $data['department'])->value('id') : null];
                if ($data['type'] === 'transfer') {
                    StockMovement::create($base + ['store_id' => $data['store_id'], 'type' => 'transfer_out', 'qty' => -$qty]);
                    StockMovement::create($base + ['store_id' => $data['to_store_id'], 'type' => 'transfer_in', 'qty' => $qty]);
                } else {
                    StockMovement::create($base + ['store_id' => $data['store_id'], 'type' => $data['type'], 'qty' => $out ? -$qty : $qty]);
                }
            }
        });
        ActivityLogger::log('inventory', $data['type'], ucfirst($data['type']).' of '.count($data['lines']).' item(s)');

        return redirect()->route('inventory.ledger')->with('success', 'Stock '.$data['type'].' recorded.');
    }

    public function ledger(Request $request)
    {
        $from = $request->input('from', bdate()->copy()->subDays(30)->toDateString());
        $to = $request->input('to', bdate()->toDateString());
        $moves = StockMovement::with('item', 'store', 'user')->whereBetween('date', [$from, $to])
            ->when($request->item, fn ($q, $i) => $q->where('inventory_item_id', $i))
            ->when($request->store, fn ($q, $s) => $q->where('store_id', $s))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString();

        return view('inventory.ledger', ['moves' => $moves, 'from' => $from, 'to' => $to, 'items' => InventoryItem::orderBy('name')->pluck('name', 'id'), 'stores' => Store::pluck('name', 'id')]);
    }
}
