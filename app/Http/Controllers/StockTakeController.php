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
        $postedLines = $lines->only($posted->pluck('id')->all())->flatten(1);
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
