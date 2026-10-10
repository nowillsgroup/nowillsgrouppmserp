<?php

namespace App\Services;

use App\Models\FsWaste;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.0 Food waste log. Raw ingredients and prepared-but-unserved dishes (exploded through the recipe) leave the
 * kitchen store as "wastage" movements referenced FW-xxxxx, which the ledger posts to 5042 Food Waste
 * (Dr Food Waste / Cr Inventory). Plate waste has already been consumed by the POS sale, so it is recorded at
 * portion cost for analysis only.
 */
class FoodWasteService
{
    public static function defaultStore(?int $outletId = null): ?int
    {
        $s = $outletId ? Outlet::whereKey($outletId)->value('store_id') : null;

        return $s ?: Store::where('type', 'kitchen')->value('id') ?: Store::value('id');
    }

    /**
     * @param  array{source:string, inventory_item_id?:int, menu_item_id?:int, store_id?:int, outlet_id?:int, qty:float, reason:string, date?:string, notes?:string}  $d
     */
    public static function record(array $d, User $u): FsWaste
    {
        $src = $d['source'];
        if (! isset(FsWaste::SOURCES[$src])) {
            throw new RuntimeException('Unknown waste type.');
        }
        if (! isset(FsWaste::REASONS[$d['reason'] ?? ''])) {
            throw new RuntimeException('Choose a reason.');
        }
        $qty = round((float) $d['qty'], 3);
        if ($qty <= 0) {
            throw new RuntimeException('Quantity must be more than zero.');
        }
        $date = Carbon::parse($d['date'] ?? bdate())->startOfDay();
        if ($date->gt(bdate()) || $date->lt(bdate()->copy()->subDays(3))) {
            throw new RuntimeException('Waste can be recorded for the business date or up to 3 days back.');
        }
        $outlet = $d['outlet_id'] ?? null;
        $store = (int) (($d['store_id'] ?? null) ?: self::defaultStore($outlet));

        return DB::transaction(function () use ($src, $d, $qty, $date, $outlet, $store, $u) {
            $no = Numbering::next('fs_waste');
            $need = []; // inventory item id => qty
            $unit = null;
            $item = $menu = null;
            if ($src === 'raw') {
                $item = InventoryItem::where('is_active', true)->find($d['inventory_item_id'] ?? 0);
                if (! $item) {
                    throw new RuntimeException('Choose the stock item that was wasted.');
                }
                $need[$item->id] = $qty;
                $unit = $item->unit;
            } else {
                $menu = MenuItem::with('recipes.inventoryItem')->find($d['menu_item_id'] ?? 0);
                if (! $menu) {
                    throw new RuntimeException('Choose the dish.');
                }
                if (fmod($qty, 0.5) > 0.0001) {
                    throw new RuntimeException('Dishes are counted in portions (whole or half).');
                }
                $unit = 'portion';
                if ($src === 'dish') {
                    if ($menu->recipes->isEmpty()) {
                        throw new RuntimeException("{$menu->name} has no recipe, so its ingredients are unknown – record the ingredients as raw waste, or add the recipe first.");
                    }
                    foreach ($menu->recipes as $r) {
                        $need[$r->inventory_item_id] = ($need[$r->inventory_item_id] ?? 0) + RecipeService::grossQty((float) $r->qty, $r->inventoryItem) * $qty; // v6.8 trim yield
                    }
                }
            }
            $cost = 0.0;
            $items = InventoryItem::whereIn('id', array_keys($need))->get()->keyBy('id');
            foreach ($need as $id => $q) {
                $i = $items[$id];
                $have = $i->stock($store);
                if ($q > $have + 0.0001) {
                    throw new RuntimeException("Not enough {$i->name} in ".Store::whereKey($store)->value('name').' (available '.rtrim(rtrim(number_format($have, 3), '0'), '.')." {$i->unit}).");
                }
            }
            foreach ($need as $id => $q) {
                $i = $items[$id];
                $cost += round($q * (float) $i->last_cost, 2);
                StockMovement::create(['inventory_item_id' => $id, 'store_id' => $store, 'date' => $date->toDateString(), 'type' => 'wastage', 'qty' => -round($q, 3),
                    'rate' => $i->last_cost, 'department' => $outlet ? Outlet::whereKey($outlet)->value('name') : 'Kitchen', 'reference' => $no,
                    'remarks' => 'Food waste – '.FsWaste::REASONS[$d['reason']].($menu ? " ({$menu->name})" : ''), 'user_id' => $u->id]);
            }
            if ($src === 'plate') {
                $cost = round(RecipeService::portionCost($menu->id) * $qty, 2);
            }
            $w = FsWaste::create(['waste_no' => $no, 'date' => $date->toDateString(), 'source' => $src, 'inventory_item_id' => $item?->id, 'menu_item_id' => $menu?->id,
                'store_id' => $src === 'plate' ? null : $store, 'outlet_id' => $outlet, 'qty' => $qty, 'unit' => $unit, 'reason' => $d['reason'], 'cost' => round($cost, 2),
                'notes' => ($d['notes'] ?? null) ? mb_substr($d['notes'], 0, 255) : null, 'user_id' => $u->id]);
            ActivityLogger::log('foodsafety', 'waste', "{$no}: ".rtrim(rtrim(number_format($qty, 3), '0'), '.')." {$unit} {$w->name()} – ".FsWaste::REASONS[$w->reason].' ('.money($w->cost).')', $w);

            return $w;
        });
    }

    /** Cancel an entry made in error: stock comes back with a reversing movement on the business date. */
    public static function cancel(FsWaste $w, string $reason, User $u): FsWaste
    {
        if ($w->cancelled_at) {
            throw new RuntimeException("{$w->waste_no} is already cancelled.");
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for cancelling.');
        }
        DB::transaction(function () use ($w, $reason, $u) {
            foreach (StockMovement::where('reference', $w->waste_no)->where('type', 'wastage')->where('qty', '<', 0)->get() as $m) {
                StockMovement::create(['inventory_item_id' => $m->inventory_item_id, 'store_id' => $m->store_id, 'date' => bdate()->toDateString(), 'type' => 'wastage',
                    'qty' => -(float) $m->qty, 'rate' => $m->rate, 'department' => $m->department, 'reference' => $w->waste_no, 'remarks' => 'Reversal – '.$reason, 'user_id' => $u->id]);
            }
            $w->update(['cancelled_at' => now(), 'cancelled_by' => $u->id, 'cancel_reason' => mb_substr($reason, 0, 255)]);
        });
        ActivityLogger::log('foodsafety', 'waste_cancel', "Cancelled {$w->waste_no}: {$reason}", $w);

        return $w;
    }

    /** Totals for a date range, with F&B consumption cost for the waste %. */
    public static function summary(string $from, string $to): array
    {
        $rows = FsWaste::with('item', 'menuItem')->whereNull('cancelled_at')->whereBetween('date', [$from, $to])->get();
        $consumed = abs((float) StockMovement::join('stores', 'stores.id', '=', 'stock_movements.store_id')->whereIn('stores.type', ['kitchen', 'bar'])
            ->whereIn('stock_movements.type', ['consumption', 'issue'])->whereBetween('stock_movements.date', [$from, $to])
            ->sum(DB::raw('stock_movements.qty * stock_movements.rate')));
        $stock = $rows->whereIn('source', ['raw', 'dish'])->sum('cost');

        return ['total' => round($rows->sum('cost'), 2), 'stock' => round($stock, 2), 'plate' => round($rows->where('source', 'plate')->sum('cost'), 2), 'count' => $rows->count(),
            'by_reason' => $rows->groupBy('reason')->map(fn ($g) => round($g->sum('cost'), 2))->sortDesc()->all(),
            'top' => $rows->groupBy(fn ($w) => $w->name())->map(fn ($g) => round($g->sum('cost'), 2))->sortDesc()->take(5)->all(),
            'consumed' => round($consumed, 2), 'pct' => $consumed > 0 ? round($stock / ($consumed + $stock) * 100, 1) : null];
    }
}
