<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Support\Numbering;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Physical stock count (stock take) per store, and reorder suggestions → draft purchase orders.
 * System quantities are frozen when the count sheet is created; posting books the variance
 * (counted − frozen system qty) as 'adjustment' movements, so later receipts/issues stay correct.
 */
class StockTakeService
{
    public static function create(int $storeId, string $date, ?int $categoryId = null, ?string $notes = null): StockTake
    {
        return DB::transaction(function () use ($storeId, $date, $categoryId, $notes) {
            if (StockTake::where('store_id', $storeId)->where('status', 'draft')->exists()) {
                throw new RuntimeException('This store already has a draft stock take. Post or cancel it first.');
            }
            $items = InventoryItem::where('is_active', true)->when($categoryId, fn ($q) => $q->where('item_category_id', $categoryId))->orderBy('name')->get();
            if ($items->isEmpty()) {
                throw new RuntimeException('No active items to count.');
            }
            $stock = StockMovement::where('store_id', $storeId)->whereIn('inventory_item_id', $items->pluck('id'))
                ->groupBy('inventory_item_id')->selectRaw('inventory_item_id, SUM(qty) q')->pluck('q', 'inventory_item_id');
            $take = StockTake::create(['take_no' => Numbering::next('stock_take'), 'store_id' => $storeId, 'count_date' => $date,
                'status' => 'draft', 'notes' => $notes, 'created_by' => auth()->id()]);
            foreach ($items as $it) {
                $take->lines()->create(['inventory_item_id' => $it->id, 'system_qty' => round((float) ($stock[$it->id] ?? 0), 2), 'unit_cost' => (float) $it->last_cost]);
            }
            ActivityLogger::log('inventory', 'stocktake_create', "Created stock take {$take->take_no} ({$items->count()} items)", $take);

            return $take;
        });
    }

    /** @param array<int, string|float|null> $counts  line id => counted qty ('' = not counted) */
    public static function saveCounts(StockTake $take, array $counts, array $remarks = []): int
    {
        self::assertDraft($take);
        $n = 0;
        foreach ($take->lines as $line) {
            if (! array_key_exists($line->id, $counts)) {
                continue;
            }
            $v = $counts[$line->id];
            $line->counted_qty = ($v === null || $v === '') ? null : round((float) $v, 2);
            $line->remarks = isset($remarks[$line->id]) ? mb_substr((string) $remarks[$line->id], 0, 190) ?: null : $line->remarks;
            if ($line->isDirty()) {
                $line->save();
                $n++;
            }
        }

        return $n;
    }

    public static function post(StockTake $take, bool $blankAsZero = false): StockTake
    {
        return DB::transaction(function () use ($take, $blankAsZero) {
            $take = StockTake::lockForUpdate()->findOrFail($take->id);
            self::assertDraft($take);
            $lines = $take->lines()->get();
            if (! $blankAsZero && $lines->whereNotNull('counted_qty')->isEmpty()) {
                throw new RuntimeException('Enter at least one counted quantity before posting.');
            }
            $value = 0;
            $adjusted = 0;
            foreach ($lines as $line) {
                if ($line->counted_qty === null) {
                    if (! $blankAsZero) {
                        continue;
                    }
                    $line->counted_qty = 0;
                    $line->save();
                }
                $var = $line->varianceQty();
                if (abs($var) < 0.005) {
                    continue;
                }
                StockMovement::create(['inventory_item_id' => $line->inventory_item_id, 'store_id' => $take->store_id, 'date' => $take->count_date->toDateString(),
                    'type' => 'adjustment', 'qty' => $var, 'rate' => $line->unit_cost, 'reference' => $take->take_no,
                    'remarks' => 'Stock take variance'.($line->remarks ? ': '.$line->remarks : ''), 'user_id' => auth()->id()]);
                $value += $line->varianceValue();
                $adjusted++;
            }
            $take->update(['status' => 'posted', 'variance_value' => round($value, 2), 'posted_by' => auth()->id(), 'posted_at' => now()]);
            ActivityLogger::log('inventory', 'stocktake_post', "Posted stock take {$take->take_no}: {$adjusted} adjustment(s), variance ".money($value), $take);

            return $take;
        });
    }

    public static function cancel(StockTake $take): void
    {
        self::assertDraft($take);
        $take->update(['status' => 'cancelled']);
        ActivityLogger::log('inventory', 'stocktake_cancel', "Cancelled stock take {$take->take_no}", $take);
    }

    protected static function assertDraft(StockTake $take): void
    {
        if ($take->status !== 'draft') {
            throw new RuntimeException("Stock take {$take->take_no} is already {$take->status}.");
        }
    }

    /** Items at or below re-order level (all stores), with a suggested order qty and the last supplier used. */
    public static function reorderSuggestions(): Collection
    {
        $items = InventoryItem::with('category')->where('is_active', true)->where('reorder_level', '>', 0)->orderBy('name')->get();
        $stock = StockMovement::groupBy('inventory_item_id')->selectRaw('inventory_item_id, SUM(qty) q')->pluck('q', 'inventory_item_id');
        $lastSupplier = PurchaseOrderItem::join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.hotel_id', \App\Support\Tenant::id())->where('purchase_orders.status', '!=', 'cancelled')
            ->orderBy('purchase_orders.id')->get(['purchase_order_items.inventory_item_id', 'purchase_orders.supplier_id', 'purchase_order_items.rate'])
            ->keyBy('inventory_item_id'); // last one wins
        $onOrder = PurchaseOrderItem::join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.hotel_id', \App\Support\Tenant::id())->whereIn('purchase_orders.status', ['draft', 'approved', 'partial'])
            ->groupBy('purchase_order_items.inventory_item_id')->selectRaw('purchase_order_items.inventory_item_id, SUM(GREATEST(purchase_order_items.qty - purchase_order_items.received_qty, 0)) q')->pluck('q', 'inventory_item_id');

        return $items->map(function ($it) use ($stock, $lastSupplier, $onOrder) {
            $qty = round((float) ($stock[$it->id] ?? 0), 2);
            $pending = round((float) ($onOrder[$it->id] ?? 0), 2);
            $level = (float) $it->reorder_level;
            $suggest = max(0, ceil($level * 2 - $qty - $pending));

            return (object) ['item' => $it, 'stock' => $qty, 'on_order' => $pending, 'level' => $level, 'suggest' => $suggest,
                'supplier_id' => $lastSupplier[$it->id]->supplier_id ?? null, 'rate' => (float) ($lastSupplier[$it->id]->rate ?? $it->last_cost)];
        })->filter(fn ($r) => $r->stock <= $r->level)->values();
    }

    /**
     * @param  array<int, array{item_id:int, supplier_id:int, qty:float, rate:float}>  $lines
     * @return Collection<PurchaseOrder>
     */
    public static function createPurchaseOrders(array $lines, int $storeId, ?string $expected = null, ?string $notes = null): Collection
    {
        return DB::transaction(function () use ($lines, $storeId, $expected, $notes) {
            $pos = collect();
            foreach (collect($lines)->groupBy('supplier_id') as $supplierId => $group) {
                $po = PurchaseOrder::create(['po_no' => Numbering::next('po'), 'supplier_id' => $supplierId, 'store_id' => $storeId, 'po_date' => bdate()->toDateString(),
                    'expected_date' => $expected, 'status' => 'draft', 'notes' => $notes ?? 'Auto-generated from re-order suggestions', 'created_by' => auth()->id()]);
                $sub = 0;
                $tax = 0;
                foreach ($group as $l) {
                    $item = InventoryItem::findOrFail($l['item_id']);
                    $amt = round($l['qty'] * $l['rate'], 2);
                    $po->items()->create(['inventory_item_id' => $item->id, 'qty' => $l['qty'], 'rate' => $l['rate'], 'tax_rate' => $item->tax_rate, 'amount' => $amt]);
                    $sub += $amt;
                    $tax += $amt * $item->tax_rate / 100;
                }
                $po->update(['subtotal' => $sub, 'tax' => round($tax, 2), 'total' => round($sub + $tax, 2)]);
                ProcurementService::stampListRates($po); // v4.8
                ActivityLogger::log('inventory', 'po_create', "Created {$po->po_no} ".($notes ? '– '.$notes : 'from re-order suggestions'), $po);
                $pos->push($po);
            }

            return $pos;
        });
    }
}
