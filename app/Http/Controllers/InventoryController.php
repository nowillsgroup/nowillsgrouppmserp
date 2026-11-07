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
    /**
     * Stock on hand — what is standing on the shelf tonight, and what it is worth.
     *
     * v9.23 the screen listed quantities per store and a value column, which answers "how many" and never "how much of
     * this is real money, where is it sitting, and what has stopped moving". The three readings are computed from the
     * same movement sums the table is built from: the shelf (items, value, the largest holding), where the value sits
     * (store by store, and how concentrated it is in a few items), and what has not moved in ninety days — the capital
     * a store is holding for no reason.
     */
    public function index(Request $request)
    {
        $stores = Store::orderBy('name')->get();
        $stock = StockMovement::select('inventory_item_id', 'store_id', DB::raw('SUM(qty) as qty'), DB::raw('MAX(date) as last_on'))
            ->groupBy('inventory_item_id', 'store_id')->get()->groupBy('inventory_item_id');
        $items = InventoryItem::with('category')->where('is_active', true)
            ->when($request->category, fn ($q, $c) => $q->where('item_category_id', $c))
            ->when($request->q, fn ($q, $t) => $q->where('name', 'like', "%$t%"))->orderBy('name')->get()
            ->each(function ($i) use ($stock, $stores) {
                $rows = $stock[$i->id] ?? collect();
                $i->by_store = $rows->pluck('qty', 'store_id');
                $i->total = (float) $i->by_store->sum();
                $i->last_moved = $rows->max('last_on') ? \Illuminate\Support\Carbon::parse($rows->max('last_on')) : null;
                $i->days_still = $i->last_moved ? (int) $i->last_moved->diffInDays(bdate()->startOfDay()) : null;
                $i->value = round($i->total * (float) $i->last_cost, 2);
                $i->total_label = rtrim(rtrim(number_format($i->total, 2), '0'), '.');
                $i->cells = $stores->mapWithKeys(fn ($s) => [$s->id => ($v = (float) ($i->by_store[$s->id] ?? 0))
                    ? rtrim(rtrim(number_format($v, 2), '0'), '.') : '—'])->all();
            });
        if ($request->boolean('low')) {
            $items = $items->filter(fn ($i) => $i->total <= $i->reorder_level);
        }

        $held = $items->filter(fn ($i) => abs($i->total) > 0.004);
        $value = round((float) $items->sum(fn ($i) => $i->value), 2);
        $top = $held->sortByDesc(fn ($i) => $i->value)->first();

        $shelf = [
            'items' => $held->count(), 'value' => $value,
            'stores' => $stores->filter(fn ($s) => $items->sum(fn ($i) => abs((float) ($i->by_store[$s->id] ?? 0))) > 0)->count(),
            'negative' => $held->filter(fn ($i) => $i->total < 0)->count(),
            'zero' => $items->filter(fn ($i) => abs($i->total) <= 0.004)->count(),
            'top' => $top ? ['item' => $top->name, 'unit' => $top->unit, 'qty' => $top->total, 'qty_label' => number_format($top->total), 'value' => $top->value,
                'share' => $value > 0 ? round($top->value / $value * 100, 1) : null,
                'store' => $stores->firstWhere('id', $top->by_store->search($top->by_store->max()))?->name] : null,
            'sub' => $held->count().' item'.($held->count() === 1 ? '' : 's').' with stock, '.$items->filter(fn ($i) => abs($i->total) <= 0.004)->count().' at zero',
        ];
        $shelf['zero_sub'] = $shelf['zero'].' active item'.($shelf['zero'] === 1 ? '' : 's').' at zero, and left out of this count';
        $shelf['rows'] = $held->sortByDesc(fn ($i) => $i->value)->take(8)->map(fn ($i) => ['item' => $i->name, 'unit' => $i->unit,
            'qty' => number_format($i->total), 'value' => $i->value, 'category' => $i->category?->name,
            'share' => $value > 0 ? round($i->value / $value * 100, 1) : null])->values()->all();
        $topThree = $held->sortByDesc(fn ($i) => $i->value)->take(3)->sum(fn ($i) => $i->value);
        $shelf['top_three'] = $value > 0 ? round($topThree / $value * 100, 1) : null;

        $whereRows = $stores->map(function ($s) use ($items, $value) {
            $in = $items->filter(fn ($i) => abs((float) ($i->by_store[$s->id] ?? 0)) > 0.004);
            $worth = round((float) $in->sum(fn ($i) => (float) ($i->by_store[$s->id] ?? 0) * (float) $i->last_cost), 2);

            return ['id' => $s->id, 'store' => $s->name, 'items' => $in->count(), 'value' => $worth,
                'share' => $value > 0 ? round($worth / $value * 100, 1) : null,
                'tone' => $worth < 0 ? 'text-red' : ''];
        })->filter(fn ($r) => $r['items'] > 0)->sortByDesc('value')->values();

        $where = ['rows' => $whereRows->all(), 'top' => $whereRows->first(),
            'multi' => $items->filter(fn ($i) => $i->by_store->filter(fn ($q) => abs((float) $q) > 0.004)->count() > 1)->count()];
        $where['sub'] = $whereRows->count().' store'.($whereRows->count() === 1 ? '' : 's').' holding stock · '
            .$where['multi'].' items are split across more than one';
        $where['split_sub'] = $where['multi'].' item'.($where['multi'] === 1 ? '' : 's')
            .' are held in more than one store, and count twice against the same purchase';

        $asleep = $held->filter(fn ($i) => $i->days_still === null || $i->days_still >= 90)->sortByDesc(fn ($i) => $i->value);
        $asleepValue = round((float) $asleep->sum(fn ($i) => $i->value), 2);
        $still = [
            'count' => $asleep->count(), 'value' => $asleepValue,
            'share' => $value > 0 ? round($asleepValue / $value * 100, 1) : null,
            'never' => $held->filter(fn ($i) => $i->days_still === null)->count(),
            'oldest' => ($o = $asleep->sortByDesc(fn ($i) => $i->days_still ?? PHP_INT_MAX)->first()) ? ['item' => $o->name,
                'unit' => $o->unit, 'qty' => number_format($o->total), 'value' => $o->value, 'days' => $o->days_still,
                'last' => $o->last_moved] : null,
            'rows' => $asleep->take(10)->map(fn ($i) => ['item' => $i->name, 'unit' => $i->unit, 'qty' => number_format($i->total),
                'value' => $i->value, 'store' => $i->by_store->filter(fn ($q) => abs((float) $q) > 0.004)->keys()->first(),
                'date' => $i->last_moved, 'days' => $i->days_still])->values()->all(),
        ];
        $still['tone'] = $asleep->count() ? 'amber' : 'green';
        $still['share_label'] = $still['share'].' % of the value on the shelf';
        $still['sub'] = $asleep->count().' item'.($asleep->count() === 1 ? '' : 's').' untouched for ninety days';
        $still['asleep_sub'] = 'on the shelf and not drawn from since '.bdate()->copy()->subDays(90)->format('d M Y');

        return view('inventory.index', ['items' => $items, 'stores' => $stores, 'categories' => ItemCategory::pluck('name', 'id'),
            'value' => $value, 'shelf' => $shelf, 'where' => $where, 'still' => $still]);
    }

    /**
     * Issue / transfer / adjustment — the only document that writes a stock movement by hand.
     *
     * v9.25 the form had no reference field at all, so every movement written here reached the ledger with nothing behind
     * it, and the ledger's own gap reading could only count them. The form now asks for the document, and the screen
     * carries the three things a clerk needs before posting: what is actually in the store they are taking from, what has
     * already left it this month, and which of those movements nobody can account for.
     */
    public function movement(Request $request)
    {
        $stores = Store::orderBy('name')->get();
        $storeId = (int) ($request->input('store_id') ?: ($stores->first()?->id ?? 0));
        $store = $stores->firstWhere('id', $storeId);
        $items = InventoryItem::where('is_active', true)->orderBy('name')->get();

        $qty = StockMovement::where('store_id', $storeId)->select('inventory_item_id', DB::raw('SUM(qty) as qty'))
            ->groupBy('inventory_item_id')->pluck('qty', 'inventory_item_id');
        $held = $items->map(fn ($i) => ['item' => $i->name, 'unit' => $i->unit, 'qty' => round((float) ($qty[$i->id] ?? 0), 2),
            'level' => (float) $i->reorder_level, 'cost' => (float) $i->last_cost,
            'value' => round((float) ($qty[$i->id] ?? 0) * (float) $i->last_cost, 2)])->filter(fn ($r) => abs($r['qty']) > 0.004)
            ->sortByDesc('value')->values();
        $held = $held->map(fn ($r) => $r + ['standing' => $r['qty'] <= 0 ? 'at zero' : ($r['qty'] <= $r['level'] ? 'below level' : 'in stock')])->values();
        $value = round((float) $held->sum('value'), 2);
        $below = $held->filter(fn ($r) => $r['qty'] > 0 && $r['qty'] <= $r['level']);
        $position = ['store' => $store?->name, 'items' => $held->count(), 'value' => $value,
            'zero' => $held->filter(fn ($r) => $r['qty'] <= 0)->count(), 'below' => $below->count(),
            'top' => ($t = $held->first()) ? $t : null,
            'tightest' => $below->sortBy(fn ($r) => $r['qty'] / max($r['level'], 0.01))->first(),
            'rows' => $held->take(10)->all()];
        $position['sub'] = $held->count().' item'.($held->count() === 1 ? '' : 's').' in the '.($store?->name ?? 'store')
            .', '.$below->count().' at or below level';
        $position['zero_sub'] = $held->filter(fn ($r) => $r['qty'] <= 0)->count()
            ? $held->filter(fn ($r) => $r['qty'] <= 0)->count().' item'.($held->filter(fn ($r) => $r['qty'] <= 0)->count() === 1 ? '' : 's')
                .' with nothing in the store, so nothing can be issued against them'
            : 'every item on the list has something in the store';

        // what has already left this store since the first of the month
        $since = bdate()->copy()->startOfMonth();
        $out = StockMovement::with('item', 'user')->where('store_id', $storeId)->whereDate('date', '>=', $since)
            ->whereDate('date', '<=', bdate())->where('qty', '<', 0)->orderByDesc('date')->get();
        $label = fn ($type) => ucfirst(str_replace('_', ' ', (string) $type));
        $month = ['count' => $out->count(), 'units' => round((float) $out->sum(fn ($m) => abs((float) $m->qty)), 2),
            'value' => round((float) $out->sum(fn ($m) => abs((float) $m->qty * (float) $m->rate)), 2),
            'rows' => $out->groupBy('type')->map(function ($set, $type) use ($label) {
                $units = round((float) $set->sum(fn ($m) => abs((float) $m->qty)), 2);

                return ['type' => $type, 'label' => $label($type), 'count' => $set->count(), 'units' => $units,
                    'value' => round((float) $set->sum(fn ($m) => abs((float) $m->qty * (float) $m->rate)), 2),
                    'tone' => $type === 'adjustment' ? 'amber' : 'slate'];
            })->sortByDesc('value')->values()->all(),
            'biggest' => ($b = $out->sortByDesc(fn ($m) => abs((float) $m->qty * (float) $m->rate))->first())
                ? ['item' => $b->item?->name, 'unit' => $b->item?->unit, 'qty' => abs((float) $b->qty), 'date' => $b->date,
                    'value' => round(abs((float) $b->qty * (float) $b->rate), 2), 'department' => $b->department] : null];
        $month['sub'] = $out->count().' movement'.($out->count() === 1 ? '' : 's').' since '.$since->format('d M Y');
        $adjust = $out->where('type', 'adjustment');
        $month['corrections'] = $adjust->count();
        $month['corrections_value'] = round((float) $adjust->sum(fn ($m) => abs((float) $m->qty * (float) $m->rate)), 2);
        $month['corrections_sub'] = $adjust->count()
            ? 'GH₵ '.number_format($month['corrections_value'], 2).' corrected by hand — nothing posted is edited or deleted'
            : 'nothing has needed correcting: no adjustment has been posted out of this store this month';

        // the movements nothing can be asked about — and what this form used to write by definition
        $bare = $out->filter(fn ($m) => blank($m->reference) && blank($m->department));
        $gaps = ['count' => $bare->count(), 'value' => round((float) $bare->sum(fn ($m) => abs((float) $m->qty * (float) $m->rate)), 2),
            'waste' => $bare->where('type', 'wastage')->count(),
            'rows' => $bare->sortByDesc(fn ($m) => abs((float) $m->qty * (float) $m->rate))->take(10)->map(fn ($m) => [
                'date' => $m->date, 'item' => $m->item?->name, 'unit' => $m->item?->unit, 'type' => $label($m->type),
                'qty' => round(abs((float) $m->qty), 2), 'value' => round(abs((float) $m->qty * (float) $m->rate), 2),
                'user' => $m->user ? ucfirst($m->user->username) : '—'])->values()->all()];
        $gaps['worst'] = $gaps['rows'][0] ?? null;
        $gaps['tone'] = $gaps['count'] ? 'amber' : 'green';
        $gaps['head_sub'] = $gaps['count']
            ? 'GH₵ '.number_format($gaps['value'], 2).' left this store with nothing behind it'
            : 'every movement out names a document or a department';
        $gaps['sub'] = $gaps['count']
            ? $gaps['count'].' movement'.($gaps['count'] === 1 ? '' : 's').' out of this store with no reference and nobody named'
            : 'every movement out of this store this month names a document or a department';

        return view('inventory.movement', ['items' => $items, 'stores' => Store::pluck('name', 'id'), 'storeId' => $storeId,
            'departments' => \App\Models\Department::orderBy('name')->pluck('name', 'name'),
            'position' => $position, 'month' => $month, 'gaps' => $gaps]);
    }

    public function storeMovement(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:issue,transfer,adjustment,wastage,opening', 'store_id' => 'required|integer|owned:stores',
            'to_store_id' => 'nullable|required_if:type,transfer|different:store_id|integer|owned:stores',
            'date' => 'required|date', 'department' => 'nullable|string|max:80', 'remarks' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:60',
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
                $base = ['inventory_item_id' => $item->id, 'date' => $data['date'], 'rate' => $item->last_cost,
                    'department' => $data['department'] ?? null, 'reference' => $data['reference'] ?? null,
                    'remarks' => $data['remarks'] ?? null, 'user_id' => auth()->id(),
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
