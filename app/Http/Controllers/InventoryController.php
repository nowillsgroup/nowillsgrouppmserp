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

    /**
     * The stock ledger — the book every other inventory document writes into.
     *
     * v9.22 a ledger that lists quantities and totals nothing cannot be audited, so the window is read three ways:
     * what moved and what it was worth (flow), whether the window rolls forward from opening to closing (balance),
     * and which outward movements have no document behind them (gaps). The list itself is unchanged; the three
     * readings are worked out from the same rows, with the same filters, so the cards and the table cannot disagree.
     */
    public function ledger(Request $request)
    {
        $from = $request->input('from', bdate()->copy()->subDays(30)->toDateString());
        $to = $request->input('to', bdate()->toDateString());
        $filter = fn ($q) => $q
            ->when($request->item, fn ($q, $i) => $q->where('inventory_item_id', $i))
            ->when($request->store, fn ($q, $s) => $q->where('store_id', $s))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t));

        $moves = $filter(StockMovement::with('item', 'store', 'user')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString();

        $window = $filter(StockMovement::with('item', 'store', 'user')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->orderBy('date')->orderBy('id')->get();

        $ins = $window->filter(fn ($m) => (float) $m->qty > 0);
        $outs = $window->filter(fn ($m) => (float) $m->qty < 0);
        $money = fn ($m) => abs((float) $m->qty * (float) $m->rate);
        $label = fn ($type) => ucfirst(str_replace('_', ' ', (string) $type));

        $flow = [
            'count' => $window->count(), 'in_count' => $ins->count(), 'out_count' => $outs->count(),
            'units_in' => round((float) $ins->sum('qty'), 2), 'units_out' => round(abs((float) $outs->sum('qty')), 2),
            'value_in' => round($ins->sum($money), 2), 'value_out' => round($outs->sum($money), 2),
            'rows' => $window->groupBy('type')->map(function ($set, $type) use ($label, $money) {
                $in = $set->filter(fn ($m) => (float) $m->qty > 0);
                $out = $set->filter(fn ($m) => (float) $m->qty < 0);
                $valueIn = round($in->sum($money), 2);
                $valueOut = round($out->sum($money), 2);

                $inward = in_array($type, ['purchase', 'opening', 'transfer_in', 'consumption_in']);

                return ['type' => $type, 'label' => $label($type), 'tone' => $inward ? 'green' : 'amber', 'count' => $set->count(),
                    'units_in' => round((float) $in->sum('qty'), 2), 'units_out' => round(abs((float) $out->sum('qty')), 2),
                    'value_in' => $valueIn, 'value_out' => $valueOut, 'net' => round($valueIn - $valueOut, 2)];
            })->sortByDesc(fn ($r) => abs($r['value_in']) + abs($r['value_out']))->values()->all(),
        ];
        $flow['net'] = round($flow['value_in'] - $flow['value_out'], 2);
        $flow['net_tone'] = $flow['net'] < 0 ? 'red' : 'green';
        $flow['sub'] = $flow['in_count'].' in · '.$flow['out_count'].' out over the window';

        $biggest = $window->sortByDesc($money)->first();
        $flow['biggest'] = $biggest ? ['date' => $biggest->date, 'item' => $biggest->item?->name, 'unit' => $biggest->item?->unit,
            'store' => $biggest->store?->name, 'type' => $label($biggest->type), 'qty' => abs((float) $biggest->qty),
            'qty_label' => number_format(abs((float) $biggest->qty), (float) $biggest->qty == floor((float) $biggest->qty) ? 0 : 2),
            'rate' => (float) $biggest->rate, 'value' => round($money($biggest), 2), 'inward' => (float) $biggest->qty > 0] : null;

        // the window rolls forward: opening + in − out = closing, per item, at the rate the item carries
        $opening = $filter(StockMovement::whereDate('date', '<', $from))
            ->select('inventory_item_id', DB::raw('SUM(qty) as qty'))->groupBy('inventory_item_id')->pluck('qty', 'inventory_item_id');
        $ids = $window->pluck('inventory_item_id')->merge($opening->keys())->unique()->filter()->values();
        $items = InventoryItem::whereIn('id', $ids)->get()->keyBy('id');

        $balanceRows = $window->groupBy('inventory_item_id')->map(function ($set, $itemId) use ($opening, $items) {
            $item = $items[$itemId] ?? null;
            $open = round((float) ($opening[$itemId] ?? 0), 2);
            $in = round((float) $set->filter(fn ($m) => (float) $m->qty > 0)->sum('qty'), 2);
            $out = round(abs((float) $set->filter(fn ($m) => (float) $m->qty < 0)->sum('qty')), 2);
            $closing = round($open + $in - $out, 2);

            return ['item' => $item?->name ?? 'Item '.$itemId, 'unit' => $item?->unit, 'opening' => $open,
                'in' => $in, 'out' => $out, 'closing' => $closing, 'rate' => (float) ($item?->last_cost ?? 0),
                'value' => round($closing * (float) ($item?->last_cost ?? 0), 2),
                'tone' => $closing < 0 ? 'text-red bold' : ''];
        })->values();

        $below = $balanceRows->filter(fn ($r) => $r['closing'] < 0);
        $balance = [
            'items' => $balanceRows->count(),
            'opening_value' => round($balanceRows->sum(fn ($r) => $r['opening'] * $r['rate']), 2),
            'closing_value' => round($balanceRows->sum('value'), 2),
            'below' => $below->count(), 'below_value' => round($below->sum('value'), 2),
            'worst' => $below->sortBy('value')->first(),
            'rows' => $balanceRows->sortBy('value')->take(12)->values()->all(),
        ];
        $balance['net'] = round($balance['closing_value'] - $balance['opening_value'], 2);
        $balance['tone'] = $balance['below'] ? 'red' : 'green';
        $balance['sub'] = $balance['items'].' item'.($balance['items'] === 1 ? '' : 's').' rolled forward from opening to closing';
        $balance['below_sub'] = $balance['below']
            ? 'closed the window with a book quantity below zero — stock issued that was never received'
            : 'every item closed the window at or above zero';

        // a movement out with neither a reference nor a department is stock nothing can be asked about
        $gaps = $window->filter(fn ($m) => (float) $m->qty < 0 && blank($m->reference) && blank($m->department));
        $adjust = $window->where('type', 'adjustment');
        $waste = $window->where('type', 'wastage');

        $gap = [
            'count' => $gaps->count(), 'value' => round($gaps->sum($money), 2),
            'adjust' => $adjust->count(), 'adjust_value' => round($adjust->sum($money), 2),
            'waste' => $waste->count(), 'waste_value' => round($waste->sum($money), 2),
            'share' => $flow['value_out'] > 0 ? round($gaps->sum($money) / $flow['value_out'] * 100, 1) : null,
            'worst' => ($row = $gaps->sortByDesc($money)->first()) ? ['date' => $row->date, 'item' => $row->item?->name,
                'unit' => $row->item?->unit, 'type' => $label($row->type), 'qty' => abs((float) $row->qty),
                'value' => round($money($row), 2), 'remarks' => $row->remarks,
                'user' => $row->user ? ucfirst($row->user->username) : null] : null,
            'by' => $gaps->groupBy(fn ($m) => $m->user ? ucfirst($m->user->username) : 'Nobody signed')->map(fn ($set, $name) => ['name' => $name,
                'count' => $set->count(), 'value' => round($set->sum($money), 2)])->sortByDesc('value')->values()->all(),
            'rows' => $gaps->sortByDesc(fn ($m) => $m->date)->take(10)->map(fn ($m) => ['date' => $m->date, 'item' => $m->item?->name,
                'unit' => $m->item?->unit, 'type' => $label($m->type), 'qty' => round(abs((float) $m->qty), 2),
                'store' => $m->store?->name, 'value' => round($money($m), 2),
                'user' => $m->user ? ucfirst($m->user->username) : '—'])->values()->all(),
        ];
        $gap['share_label'] = $gap['share'].' % of the value that left the store';
        $gap['adjust_sub'] = $gap['adjust'].' movement'.($gap['adjust'] === 1 ? '' : 's').', '
            .'GH₵ '.number_format($gap['adjust_value'], 2).' in all';
        $gap['tone'] = $gap['count'] ? 'amber' : 'green';

        return view('inventory.ledger', ['moves' => $moves, 'from' => $from, 'to' => $to,
            'items' => InventoryItem::orderBy('name')->pluck('name', 'id'), 'stores' => Store::pluck('name', 'id'),
            'flow' => $flow, 'balance' => $balance, 'gaps' => $gap]);
    }
}
