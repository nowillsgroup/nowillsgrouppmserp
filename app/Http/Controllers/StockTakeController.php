<?php

namespace App\Http\Controllers;

use App\Models\ItemCategory;
use App\Models\PurchaseOrderItem;
use App\Models\RequisitionLine;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\StockTakeService;
use Illuminate\Http\Request;

class StockTakeController extends Controller
{
    public function index()
    {
        $takes = StockTake::with('store', 'creator', 'poster')->withCount(['lines', 'lines as counted_count' => fn ($q) => $q->whereNotNull('counted_qty')])->latest('id')->paginate(25);

        // v9.21 a count sheet is only worth what the posting made of it: the variance, the coverage behind it and what is still unposted
        $all = StockTake::with('store', 'creator', 'poster')->get();
        $lines = StockTakeLine::whereIn('stock_take_id', $all->pluck('id'))->with('item')->get()->groupBy('stock_take_id');
        $summary = $all->mapWithKeys(function ($t) use ($lines) {
            $set = $lines[$t->id] ?? collect();
            $counted = $set->filter(fn ($l) => $l->counted_qty !== null);
            $varied = $counted->filter(fn ($l) => abs((float) $l->varianceQty()) >= 0.005);

            return [$t->id => ['lines' => $set->count(), 'counted' => $counted->count(),
                'pct' => $set->count() ? round($counted->count() / $set->count() * 100, 1) : null,
                'varied' => $varied->count(), 'value' => round((float) $counted->sum(fn ($l) => $l->varianceValue()), 2),
                'short' => round((float) $counted->sum(fn ($l) => min(0, (float) $l->varianceValue())), 2),
                'over' => round((float) $counted->sum(fn ($l) => max(0, (float) $l->varianceValue())), 2),
                'days' => (int) max(0, $t->count_date->diffInDays(bdate()->startOfDay(), false))]];
        });

        $posted = $all->where('status', 'posted');
        $drafts = $all->where('status', 'draft');
        // grouped rows are collections of lines, not models — Eloquent Collection::only() would try getDictionary() on them
        $postedLines = collect($posted->pluck('id')->all())->flatMap(fn ($id) => $lines[$id] ?? collect())->values();
        $countedLines = $postedLines->filter(fn ($l) => $l->counted_qty !== null);
        $variance = ['value' => round((float) $posted->sum(fn ($t) => $summary[$t->id]['value']), 2),
            'short' => round((float) $posted->sum(fn ($t) => $summary[$t->id]['short']), 2),
            'over' => round((float) $posted->sum(fn ($t) => $summary[$t->id]['over']), 2),
            'lines' => $postedLines->count(), 'counted' => $countedLines->count(),
            'skipped' => $postedLines->count() - $countedLines->count(),
            'pct' => $postedLines->count() ? round($countedLines->count() / $postedLines->count() * 100, 1) : null,
            'adjusted' => (int) $posted->sum(fn ($t) => $summary[$t->id]['varied']),
            'worst' => $countedLines->filter(fn ($l) => (float) $l->varianceValue() < -0.005)
                ->sortBy(fn ($l) => (float) $l->varianceValue())->first(),
            'worst_take' => $posted->filter(fn ($t) => $summary[$t->id]['pct'] !== null)
                ->sortBy(fn ($t) => $summary[$t->id]['pct'])->first(),
            'takes' => $posted->count()];

        $postedRows = $posted->map(fn ($t) => ['take' => $t->take_no, 'store' => $t->store?->name, 'counted' => $summary[$t->id]['counted'],
            'lines' => $summary[$t->id]['lines'], 'pct' => $summary[$t->id]['pct'], 'varied' => $summary[$t->id]['varied'],
            'short' => $summary[$t->id]['short'], 'over' => $summary[$t->id]['over'], 'value' => $summary[$t->id]['value'],
            'days' => $summary[$t->id]['days']])->values();
        $variance['rows'] = $postedRows->sortByDesc(fn ($r) => abs($r['value']))->take(10)->values()->all();
        $variance['coverage_rows'] = $postedRows->sortBy(fn ($r) => $r['pct'] ?? 100)->take(10)->map(fn ($r) => $r + ['tone' => ($r['pct'] ?? 100) < 95 ? 'text-red bold' : ''])->values()->all();
        $worstLine = $variance['worst'];
        $variance['pct_tone'] = ($variance['pct'] ?? 0) >= 95 ? 'green' : 'amber';
        $variance['pct_label'] = $variance['pct'].' %';
        $variance['value_sub'] = $variance['adjusted'].' line'.($variance['adjusted'] === 1 ? '' : 's').' adjusted across the posted counts';
        $variance['worst_short'] = $worstLine ? ['item' => $worstLine->item?->name, 'unit' => $worstLine->item?->unit,
            'take' => $all->firstWhere('id', $worstLine->stock_take_id)?->take_no,
            'counted' => (float) $worstLine->counted_qty, 'system' => (float) $worstLine->system_qty,
            'qty' => abs((float) $worstLine->varianceQty()), 'cost' => (float) $worstLine->unit_cost,
            'value' => abs((float) $worstLine->varianceValue())] : null;

        $posting = ['drafts' => $drafts->count(), 'cancelled' => $all->where('status', 'cancelled')->count(),
            'value' => round((float) $drafts->sum(fn ($t) => abs($summary[$t->id]['value'])), 2),
            'lines' => (int) $drafts->sum(fn ($t) => $summary[$t->id]['lines']),
            'counted' => (int) $drafts->sum(fn ($t) => $summary[$t->id]['counted']),
            'oldest' => $drafts->sortBy(fn ($t) => $t->count_date->toDateString())->first(),
            'oldest_days' => (int) $drafts->max(fn ($t) => $summary[$t->id]['days']),
            'rows' => $drafts->sortBy(fn ($t) => $t->count_date->toDateString())->map(fn ($t) => ['take' => $t->take_no,
                'store' => $t->store?->name, 'date' => $t->count_date, 'days' => $summary[$t->id]['days'],
                'counted' => $summary[$t->id]['counted'], 'lines' => $summary[$t->id]['lines'],
                'value' => $summary[$t->id]['value'],
                'tone' => $summary[$t->id]['days'] > 7 ? 'text-red bold' : ''])->values()->all()];
        $posting['oldest_tone'] = $posting['oldest_days'] > 7 ? 'red' : 'slate';
        $posting['sub'] = $posting['drafts'].' draft'.($posting['drafts'] === 1 ? '' : 's')
            .' · oldest '.$posting['oldest_days'].' day'.($posting['oldest_days'] === 1 ? '' : 's').' unposted';

        return view('inventory.stocktakes', [
            'takes' => $takes, 'summary' => $summary, 'variance' => $variance, 'posting' => $posting,
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

    /**
     * One count sheet — where the counting actually happens.
     *
     * v9.24 the sheet was a grid of inputs with a variance column, and the one thing that decides what posting does was
     * invisible: a line left blank is posted with the book quantity untouched, while a line counted at zero writes the
     * whole quantity off. They look the same in a grid. So the screen is read three ways before anything is posted —
     * how far through the count is (by category, because a count is done aisle by aisle), what the counted lines have
     * found so far, and what posting will do line by line, including the 16,154.00 the "blank counts as zero" box would
     * write off in one click.
     */
    public function show(StockTake $stocktake)
    {
        $stocktake->load('store', 'lines.item.category', 'creator', 'poster');
        $lines = $stocktake->lines;
        $counted = $lines->filter(fn ($l) => $l->counted_qty !== null);
        $blank = $lines->filter(fn ($l) => $l->counted_qty === null);
        $zero = $counted->filter(fn ($l) => (float) $l->counted_qty === 0.0 && (float) $l->system_qty !== 0.0);
        $moved = $counted->filter(fn ($l) => abs((float) $l->varianceQty()) > 0.005);
        $same = $counted->filter(fn ($l) => abs((float) $l->varianceQty()) <= 0.005);
        $pct = $lines->count() ? round($counted->count() / $lines->count() * 100, 1) : null;

        $categories = $lines->groupBy(fn ($l) => $l->item?->category?->name ?? 'Uncategorised')->map(function ($set, $name) {
            $counted = $set->filter(fn ($l) => $l->counted_qty !== null);
            $short = round((float) $counted->sum(fn ($l) => min(0, (float) $l->varianceValue())), 2);
            $over = round((float) $counted->sum(fn ($l) => max(0, (float) $l->varianceValue())), 2);

            return ['category' => $name, 'lines' => $set->count(), 'counted' => $counted->count(),
                'blank' => $set->count() - $counted->count(),
                'pct' => $set->count() ? round($counted->count() / $set->count() * 100, 1) : null,
                'short' => $short, 'over' => $over, 'net' => round($short + $over, 2)];
        })->sortBy('category')->values();

        $progress = ['lines' => $lines->count(), 'counted' => $counted->count(), 'blank' => $blank->count(),
            'pct' => $pct, 'zero' => $zero->count(), 'same' => $same->count(), 'moved' => $moved->count(),
            'rows' => $categories->map(fn ($c) => ['category' => $c['category'], 'lines' => $c['lines'], 'counted' => $c['counted'],
                'blank' => $c['blank'], 'pct' => $c['pct'], 'tone' => $c['pct'] !== null && $c['pct'] < 100 ? 'text-red bold' : ''])->all(),
            'pct_tone' => ($pct ?? 0) >= 100 ? 'green' : 'amber'];
        $progress['sub'] = $counted->count().' counted · '.$blank->count().' blank';
        $behind = $categories->filter(fn ($c) => $c['blank'] > 0)->pluck('category')->all();
        $progress['behind'] = count($behind) > 1 ? implode(', ', array_slice($behind, 0, -1)).' and '.end($behind) : ($behind[0] ?? '');

        $short = round((float) $counted->sum(fn ($l) => min(0, (float) $l->varianceValue())), 2);
        $over = round((float) $counted->sum(fn ($l) => max(0, (float) $l->varianceValue())), 2);
        $worst = $counted->filter(fn ($l) => (float) $l->varianceValue() < -0.005)->sortBy(fn ($l) => (float) $l->varianceValue())->first();

        $found = ['value' => round($short + $over, 2), 'short' => $short, 'over' => $over,
            'rows' => $categories->map(fn ($c) => ['category' => $c['category'], 'counted' => $c['counted'],
                'short' => $c['short'], 'over' => $c['over'], 'net' => $c['net']])->all(),
            'worst' => $worst ? ['item' => $worst->item?->name, 'unit' => $worst->item?->unit,
                'system' => (float) $worst->system_qty, 'counted' => (float) $worst->counted_qty,
                'qty' => abs((float) $worst->varianceQty()), 'cost' => (float) $worst->unit_cost,
                'value' => abs((float) $worst->varianceValue())] : null];
        $found['tone'] = $short < 0 ? 'red' : 'green';

        $blankValue = round((float) $blank->sum(fn ($l) => (float) $l->system_qty * (float) $l->unit_cost), 2);
        $zeroValue = round((float) $zero->sum(fn ($l) => (float) $l->system_qty * (float) $l->unit_cost), 2);
        $biggestBlank = $blank->sortByDesc(fn ($l) => (float) $l->system_qty * (float) $l->unit_cost)->first();

        $writes = ['moved' => $moved->count(), 'value' => round((float) $moved->sum(fn ($l) => $l->varianceValue()), 2),
            'zero' => $zero->count(), 'zero_value' => $zeroValue, 'same' => $same->count(),
            'blank' => $blank->count(), 'blank_value' => $blankValue,
            'biggest_blank' => $biggestBlank ? ['item' => $biggestBlank->item?->name, 'unit' => $biggestBlank->item?->unit,
                'qty' => (float) $biggestBlank->system_qty, 'value' => round((float) $biggestBlank->system_qty * (float) $biggestBlank->unit_cost, 2)] : null,
            'rows' => $moved->sortByDesc(fn ($l) => abs((float) $l->varianceValue()))->take(10)->map(fn ($l) => [
                'item' => $l->item?->name, 'unit' => $l->item?->unit, 'system' => (float) $l->system_qty,
                'counted' => (float) $l->counted_qty, 'qty' => (float) $l->varianceQty(),
                'value' => round((float) $l->varianceValue(), 2), 'tone' => (float) $l->varianceQty() < 0 ? 'text-red' : 'text-green'])->values()->all()];
        $writes['tone'] = $blank->count() ? 'amber' : 'green';
        $writes['blank_head_sub'] = $blank->count()
            ? 'GH₵ '.number_format($blankValue, 2).' stays on the book unless the box below is ticked'
            : 'every line carries a figure, so nothing is left unseen';
        $writes['box_sub'] = $blank->count()
            ? 'tick “treat blank counts as zero” and this is written off the book, sight unseen'
            : 'with every line counted there is nothing for the box to write off';
        $writes['blank_sub'] = $blank->count()
            ? $blank->count().' line'.($blank->count() === 1 ? '' : 's').' with no figure — posting leaves them on the book'
            : 'every line carries a figure, so posting covers the whole sheet';
        $writes['zero_sub'] = $zero->count()
            ? $zero->count().' line'.($zero->count() === 1 ? '' : 's').' counted at zero — the whole quantity is written off'
            : 'nothing was counted at zero against a book quantity';

        return view('inventory.stocktake-show', ['take' => $stocktake, 'progress' => $progress, 'found' => $found,
            'writes' => $writes, 'editable' => $stocktake->status === 'draft' && can_do('inventory.manage')]);
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
        $rows = StockTakeService::reorderSuggestions();
        $since = bdate()->copy()->subDays(60)->toDateString();

        // v9.20 the suggestion is three readings of the same row: what the shelf is short of, what is already coming, and how long the stock lasts
        $usage = StockMovement::where('date', '>=', $since)->where('qty', '<', 0)->groupBy('inventory_item_id')
            ->selectRaw('inventory_item_id, SUM(-qty) q, SUM(-qty * rate) v')->get()->keyBy('inventory_item_id');
        $lastMove = StockMovement::groupBy('inventory_item_id')->selectRaw('inventory_item_id, MAX(date) d')->pluck('d', 'inventory_item_id');
        $draft = PurchaseOrderItem::join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.hotel_id', \App\Support\Tenant::id())->where('purchase_orders.status', 'draft')
            ->groupBy('purchase_order_items.inventory_item_id')
            ->selectRaw('purchase_order_items.inventory_item_id, SUM(CASE WHEN purchase_order_items.qty > purchase_order_items.received_qty THEN purchase_order_items.qty - purchase_order_items.received_qty ELSE 0 END) q')
            ->pluck('q', 'purchase_order_items.inventory_item_id');
        $requested = RequisitionLine::whereHas('requisition', fn ($q) => $q->whereIn('status', ['approved', 'partial']))
            ->groupBy('inventory_item_id')
            ->selectRaw('inventory_item_id, SUM(CASE WHEN qty_approved IS NOT NULL AND qty_approved > qty_issued THEN qty_approved - qty_issued ELSE 0 END) q')
            ->pluck('q', 'inventory_item_id');

        $rows->each(function ($r) use ($usage, $lastMove, $draft, $requested) {
            $r->used60 = round((float) ($usage[$r->item->id]->q ?? 0), 2);
            $r->used_value = round((float) ($usage[$r->item->id]->v ?? 0), 2);
            $r->per_day = round($r->used60 / 60, 3);
            $r->days_cover = $r->per_day > 0 ? (int) round($r->stock / $r->per_day) : null;
            $r->runout = $r->days_cover !== null ? bdate()->copy()->addDays(max(0, $r->days_cover))->toDateString() : null;
            $r->last_moved = $lastMove[$r->item->id] ?? null;
            $r->short = round(max(0, $r->level * 2 - $r->stock), 2);
            $r->short_value = round($r->short * $r->rate, 2);
            $r->draft_qty = round((float) ($draft[$r->item->id] ?? 0), 2);
            $r->requested_qty = round((float) ($requested[$r->item->id] ?? 0), 2);
            $r->used_label = number_format($r->used60);
        });

        $gap = ['count' => $rows->count(), 'zero' => $rows->filter(fn ($r) => $r->stock <= 0)->count(),
            'short_qty' => round($rows->sum(fn ($r) => $r->short), 2), 'short_value' => round($rows->sum(fn ($r) => $r->short_value), 2),
            'nothing_coming' => $rows->filter(fn ($r) => $r->on_order <= 0 && $r->suggest > 0)->count(),
            'worst' => $rows->sortByDesc('short_value')->first(), 'rows' => $rows->sortByDesc('short_value')->take(10)->values()->all()];
        $gap['short_qty_label'] = number_format($gap['short_qty']);

        $pipeline = ['value' => round($rows->sum(fn ($r) => $r->on_order * $r->rate), 2),
            'items' => $rows->filter(fn ($r) => $r->on_order > 0)->count(),
            'draft_value' => round($rows->sum(fn ($r) => $r->draft_qty * $r->rate), 2), 'draft_items' => $rows->filter(fn ($r) => $r->draft_qty > 0)->count(),
            'requested_value' => round($rows->sum(fn ($r) => $r->requested_qty * $r->rate), 2),
            'requested_items' => $rows->filter(fn ($r) => $r->requested_qty > 0)->count(),
            'nothing' => $rows->filter(fn ($r) => $r->on_order <= 0 && $r->requested_qty <= 0 && $r->suggest > 0)->count(),
            'worst' => $rows->sortByDesc(fn ($r) => $r->draft_qty * $r->rate)->first(),
            'rows' => $rows->filter(fn ($r) => $r->on_order > 0 || $r->requested_qty > 0)->sortByDesc('on_order')->take(10)->values()->all()];

        $cover = ['usage_qty' => round($rows->sum('used60'), 2), 'usage_value' => round($rows->sum('used_value'), 2),
            'silent' => $rows->filter(fn ($r) => $r->used60 <= 0)->count(),
            'silent_rows' => $rows->filter(fn ($r) => $r->used60 <= 0)->sortByDesc('short_value')->take(10)->values()->all(),
            'aged' => $rows->filter(fn ($r) => $r->stock <= $r->level && $r->last_moved && \Carbon\Carbon::parse($r->last_moved)->lt(bdate()->copy()->subDays(90)))->count(),
            'fastest' => $rows->filter(fn ($r) => $r->days_cover !== null)->sortBy('days_cover')->first(),
            'rows' => $rows->filter(fn ($r) => $r->days_cover !== null)->sortBy('days_cover')->take(10)->values()->all()];

        $cover['usage_qty_label'] = number_format($cover['usage_qty']);
        $cover['aged_sub'] = $cover['aged']
            ? ($cover['aged'] === 1 ? '1 of them has' : $cover['aged'].' of them have').' not moved in ninety days, so the level is a habit not a demand'
            : 'every item below the level has been used in the last sixty days';

        return view('inventory.reorder', [
            'rows' => $rows,
            'suppliers' => Supplier::orderBy('name')->pluck('name', 'id'),
            'stores' => Store::orderBy('name')->pluck('name', 'id'),
            'since' => $since, 'gap' => $gap, 'pipeline' => $pipeline, 'cover' => $cover,
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
