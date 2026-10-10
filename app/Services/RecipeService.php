<?php

namespace App\Services;

use App\Models\MenuItemRecipe;
use App\Models\PosOrder;
use App\Models\StockMovement;

/**
 * F&B costing: each menu item can have a recipe (stock items × quantity per portion).
 * When a POS bill is settled, the ingredients are deducted from the outlet's store as
 * "consumption" movements, so the stock ledger and consumption report reflect sales.
 */
class RecipeService
{
    /** Theoretical food cost of one portion (v6.8: on the costing basis and grossed up for the ingredient's trim yield). */
    public static function portionCost(int $menuItemId): float
    {
        return (float) MenuItemRecipe::with('inventoryItem')->where('menu_item_id', $menuItemId)->get()
            ->sum(fn ($r) => $r->inventoryItem ? FbCostService::lineCost((float) $r->qty, $r->inventoryItem) : 0);
    }

    /** Gross (as-purchased) quantity for a net recipe quantity – v6.8 yield. */
    public static function grossQty(float $qty, $item): float
    {
        return $qty * 100 / max(1, (float) ($item->yield_pct ?: 100));
    }

    /** Deduct ingredients for a settled order. Idempotent per order. Returns number of stock lines written. */
    public static function consume(PosOrder $order): int
    {
        $order->loadMissing('outlet', 'items');
        $store = $order->outlet?->store_id;
        if (! $store || StockMovement::where('reference', 'POS '.$order->order_no)->where('type', 'consumption')->exists()) {
            return 0;
        }
        $lines = $order->items->where('is_cancelled', false)->where('is_pending', false)->whereNotNull('menu_item_id');
        $recipes = MenuItemRecipe::with('inventoryItem')->whereIn('menu_item_id', $lines->pluck('menu_item_id')->unique())->get()->groupBy('menu_item_id');
        $need = [];
        foreach ($lines as $l) {
            foreach ($recipes[$l->menu_item_id] ?? [] as $r) {
                $need[$r->inventory_item_id] ??= ['qty' => 0, 'rate' => (float) $r->inventoryItem->last_cost];
                $need[$r->inventory_item_id]['qty'] += self::grossQty((float) $r->qty, $r->inventoryItem) * $l->qty;
            }
        }
        foreach ($need as $itemId => $n) {
            StockMovement::create(['inventory_item_id' => $itemId, 'store_id' => $store, 'date' => $order->business_date ?? bdate(), 'type' => 'consumption',
                'qty' => -round($n['qty'], 3), 'rate' => $n['rate'], 'department' => $order->outlet->name, 'reference' => 'POS '.$order->order_no,
                'remarks' => 'Recipe consumption for bill '.$order->bill_no, 'user_id' => auth()->id()]);
        }

        return count($need);
    }
}
