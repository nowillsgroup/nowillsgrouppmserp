<?php

namespace App\Services;

use App\Models\FbCostSnapshot;
use App\Models\FbPriceChange;
use App\Models\FbRecipeCard;
use App\Models\FbStaffMeal;
use App\Models\FbYieldTest;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemRecipe;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v6.8 F&B cost control & menu engineering.
 *  - Plate cost = Σ ingredient quantity per portion × unit cost ÷ trim yield. Unit cost is the last purchase cost or the
 *    weighted average purchase cost of the last 90 days (setting). Yield comes from butcher / yield tests.
 *  - Theoretical (ideal) cost = portions sold × plate cost. Actual cost = everything that left the F&B stores: recipe
 *    consumption, issues, the food waste log, other wastage and stock-count adjustments. USALI: cost of food sold = cost
 *    consumed − employee meals (to employee benefits) − officers' checks / complimentary bills (to A&G / S&M).
 *  - Menu engineering (Kasavana & Smith): an item is popular when its menu mix ≥ 70 % of an equal share (1 / n), and
 *    profitable when its contribution margin (net price − plate cost) ≥ the weighted average CM → Star, Plowhorse,
 *    Puzzle or Dog, each with the recommended action.
 *  - Cost creep: the night audit snapshots every plate cost; a dish whose cost rose by more than the threshold over the
 *    window is flagged together with dishes above their target cost %.
 * USALI 11th edition: beverage = alcoholic drinks only; coffee, tea, juices, soft drinks and water are food revenue and food cost.
 * Theoretical cost is split by the class of each ingredient so it lines up with the actual outflows from the stores.
 * Menu prices are exclusive of VAT, NHIL, GETFund and the tourism levy, so revenue here is net of tax and discounts.
 */
class FbCostService
{
    public const DEFAULTS = [
        'fbc_bev_target' => '25', 'fbc_basis' => 'last', 'fbc_popularity' => '70', 'fbc_creep_pct' => '5', 'fbc_creep_days' => '60',
        'fbc_meal_cost' => '25', 'fbc_round' => '5',
    ];

    public const CLASSES = [
        'star' => ['Star', 'green', 'Popular and profitable – keep it, protect quality and visibility, test a small price rise.'],
        'plowhorse' => ['Plowhorse', 'amber', 'Popular but a low margin – re-engineer the recipe or portion, or raise the price gradually.'],
        'puzzle' => ['Puzzle', 'blue', 'Profitable but slow – reposition it on the menu, rename, feature or have staff recommend it.'],
        'dog' => ['Dog', 'red', 'Unpopular and a low margin – replace or drop it unless it is needed for menu balance.'],
    ];

    protected static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::firstOrCreate(['key' => 'food_cost_target_pct'], ['value' => '35']);
        // classify once; afterwards the masters own the classification
        if (! Setting::where('key', 'fbc_classified')->exists()) {
            MenuCategory::get()->each(function ($c) {
                if (preg_match('/\bbar\b|wine|cocktail|liquor|spirit|beer|alcohol/i', $c->name)) { // USALI: beverage = alcoholic only
                    $c->update(['fb_class' => 'beverage']);
                }
            });
            ItemCategory::whereNull('fb_class')->get()->each(function ($c) {
                $cls = match (true) {
                    (bool) preg_match('/liquor|wine|beer|spirit|alcohol|\bbar\b/i', $c->name) => 'beverage',
                    (bool) preg_match('/grocery|food|meat|poultry|fish|sea ?food|produce|vegetable|fruit|dairy|bakery|kitchen|spice|dry goods|beverage|soft drink|water|juice/i', $c->name) => 'food', // non-alcoholic drinks are food cost
                    default => null,
                };
                if ($cls) {
                    $c->update(['fb_class' => $cls]);
                }
            });
            Setting::create(['key' => 'fbc_classified', 'value' => '1']);
        }
        Setting::$version++;
        self::flush();
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'.Tenant::id()] ??= [
            'food_target' => (float) setting('food_cost_target_pct', '35'),
            'bev_target' => (float) setting('fbc_bev_target', '25'),
            'basis' => setting('fbc_basis', 'last') === 'average' ? 'average' : 'last',
            'popularity' => (float) setting('fbc_popularity', '70'),
            'creep_pct' => (float) setting('fbc_creep_pct', '5'),
            'creep_days' => (int) setting('fbc_creep_days', '60'),
            'meal_cost' => (float) setting('fbc_meal_cost', '25'),
            'round' => (int) setting('fbc_round', '5'),
        ];
    }

    public static function saveSettings(array $d): void
    {
        $num = ['food_cost_target_pct' => ['food_target', 5, 80, 'The food cost target is 5–80 %.'], 'fbc_bev_target' => ['bev_target', 5, 80, 'The beverage cost target is 5–80 %.'],
            'fbc_popularity' => ['popularity', 50, 100, 'The popularity factor is 50–100 %.'], 'fbc_creep_pct' => ['creep_pct', 1, 50, 'The cost-creep threshold is 1–50 %.'],
            'fbc_creep_days' => ['creep_days', 7, 365, 'The cost-creep window is 7–365 days.'], 'fbc_meal_cost' => ['meal_cost', 0, 500, 'The staff meal cost per cover is 0–500.']];
        $out = [];
        foreach ($num as $key => [$k, $lo, $hi, $msg]) {
            $v = $d[$k] ?? null;
            self::need(is_numeric($v) && $v >= $lo && $v <= $hi, $msg);
            $out[$key] = (string) round((float) $v, 2);
        }
        self::need(in_array($d['basis'] ?? '', ['last', 'average'], true), 'Choose the costing basis.');
        self::need(in_array((int) ($d['round'] ?? 0), [1, 5, 10], true), 'Round suggested prices to 1, 5 or 10.');
        $out['fbc_basis'] = $d['basis'];
        $out['fbc_round'] = (string) (int) $d['round'];
        foreach ($out as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('fbc', 'settings', 'F&B cost control settings updated');
    }

    public static function canView(?User $u): bool
    {
        return (bool) $u?->hasPermission('fbc.view') || self::canCost($u) || self::canPrice($u);
    }

    public static function canCost(?User $u): bool
    {
        return (bool) $u?->hasPermission('fbc.cost') || self::canPrice($u);
    }

    public static function canPrice(?User $u): bool
    {
        return (bool) $u?->hasPermission('fbc.price');
    }

    // ------------------------------------------------------------------ costing

    /** Unit cost of every inventory item on the chosen basis. */
    public static function unitCosts(): array
    {
        return self::$memo['unit'.Tenant::id()] ??= (function () {
            $last = InventoryItem::pluck('last_cost', 'id')->map(fn ($v) => (float) $v)->all();
            if (self::cfg()['basis'] !== 'average') {
                return $last;
            }
            $avg = StockMovement::where('type', 'purchase')->where('qty', '>', 0)->where('rate', '>', 0)
                ->where('date', '>=', bdate()->copy()->subDays(90)->toDateString())
                ->groupBy('inventory_item_id')->select('inventory_item_id', DB::raw('SUM(qty * rate) / SUM(qty) as avg_cost'))->pluck('avg_cost', 'inventory_item_id');
            foreach ($avg as $id => $v) {
                $last[$id] = round((float) $v, 4);
            }

            return $last;
        })();
    }

    public static function unitCost(InventoryItem $i): float
    {
        if (self::cfg()['basis'] !== 'average') {
            return (float) $i->last_cost; // always the fresh model value
        }

        return self::unitCosts()[$i->id] ?? (float) $i->last_cost;
    }

    /** Cost of one recipe line: net quantity ÷ yield × unit cost. */
    public static function lineCost(float $qty, InventoryItem $i): float
    {
        $y = max(1.0, (float) ($i->yield_pct ?: 100));

        return $qty * 100 / $y * self::unitCost($i);
    }

    /** Plate cost per menu item id (only items that have a recipe). */
    public static function costs(): array
    {
        return self::$memo['costs'.Tenant::id()] ??= MenuItemRecipe::with('inventoryItem')->get()->groupBy('menu_item_id')
            ->map(fn ($lines) => round($lines->sum(fn ($r) => $r->inventoryItem ? self::lineCost((float) $r->qty, $r->inventoryItem) : 0), 4))->all();
    }

    /** Plate cost per menu item split by ingredient class: [id => ['food' => x, 'beverage' => y]]. */
    public static function costsByClass(): array
    {
        return self::$memo['cbc'.Tenant::id()] ??= (function () {
            $out = [];
            foreach (MenuItemRecipe::with('inventoryItem.category')->get() as $r) {
                $c = $r->inventoryItem?->category?->fb_class;
                if (! in_array($c, ['food', 'beverage'], true)) {
                    continue;
                }
                $out[$r->menu_item_id][$c] = ($out[$r->menu_item_id][$c] ?? 0) + self::lineCost((float) $r->qty, $r->inventoryItem);
            }

            return $out;
        })();
    }

    public static function portionCost(int $menuItemId): float
    {
        return self::costs()[$menuItemId] ?? 0.0;
    }

    public static function classOf(MenuItem $m): string
    {
        return ($m->category?->fb_class ?? 'food') === 'beverage' ? 'beverage' : 'food';
    }

    public static function targetFor(MenuItem $m): float
    {
        $cfg = self::cfg();

        return (float) ($m->card?->target_pct ?: (self::classOf($m) === 'beverage' ? $cfg['bev_target'] : $cfg['food_target']));
    }

    /** Price that brings the dish to the target cost %, rounded up to the price step. */
    public static function suggestPrice(float $cost, float $target): float
    {
        if ($cost <= 0 || $target <= 0) {
            return 0.0;
        }
        $step = max(1, self::cfg()['round']);

        return ceil($cost * 100 / $target / $step) * $step;
    }

    /** Every dish with price, plate cost, cost %, GP, target, status and cost creep. */
    public static function dishes(?int $outletId = null): Collection
    {
        $costs = self::costs();
        $creep = self::creep();

        return MenuItem::with(['category', 'outlet', 'card'])->withCount('recipes')
            ->when($outletId, fn ($q) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $outletId)))
            ->orderBy('menu_category_id')->orderBy('name')->get()->map(function ($m) use ($costs, $creep) {
                $has = $m->recipes_count > 0;
                $cost = $has ? ($costs[$m->id] ?? 0.0) : null;
                $price = (float) $m->price;
                $pct = $has && $price > 0 ? round($cost / $price * 100, 1) : null;
                $target = self::targetFor($m);
                $m->setAttribute('cost', $cost);
                $m->setAttribute('pct', $pct);
                $m->setAttribute('gp', $has ? round($price - $cost, 2) : null);
                $m->setAttribute('target', $target);
                $m->setAttribute('cls', self::classOf($m));
                $m->setAttribute('creep', $creep[$m->id] ?? null);
                $m->setAttribute('suggested', $has ? self::suggestPrice($cost, $target) : null);
                $m->setAttribute('st', ! $has ? 'no_recipe' : ($pct !== null && $pct > $target ? 'over' : 'ok'));

                return $m;
            });
    }

    // ------------------------------------------------------------------ recipe cards

    /**
     * $d: lines[] {inventory_item_id, qty (net per portion)}, batch_portions, portion_size, prep_minutes, station, garnish, method, target_pct.
     * Changing the ingredients bumps the version and clears the approval.
     */
    public static function saveCard(MenuItem $m, array $d, User $u): FbRecipeCard
    {
        self::need(self::canCost($u));
        $lines = collect((array) ($d['lines'] ?? []))->filter(fn ($l) => filled($l['inventory_item_id'] ?? null) && filled($l['qty'] ?? null));
        foreach ($lines as $l) {
            self::need(is_numeric($l['qty']) && $l['qty'] > 0 && $l['qty'] <= 9999, 'Ingredient quantities must be more than zero.');
        }
        $ids = $lines->pluck('inventory_item_id')->map(fn ($v) => (int) $v);
        self::need($ids->count() === $ids->unique()->count(), 'Each ingredient can appear only once – add the quantities together.');
        self::need($ids->count() <= 40, 'A recipe has at most 40 ingredients.');
        self::need(InventoryItem::whereIn('id', $ids)->where('is_active', true)->count() === $ids->count(), 'Choose active stock items as ingredients.');
        $portions = (int) ($d['batch_portions'] ?? 1);
        self::need($portions >= 1 && $portions <= 500, 'The batch makes 1 to 500 portions.');
        $target = filled($d['target_pct'] ?? null) ? (float) $d['target_pct'] : null;
        self::need($target === null || ($target >= 5 && $target <= 80), 'The target cost is 5–80 %.');
        $prep = filled($d['prep_minutes'] ?? null) ? (int) $d['prep_minutes'] : null;
        self::need($prep === null || ($prep >= 0 && $prep <= 1440), 'Preparation time is 0–1,440 minutes.');

        return DB::transaction(function () use ($m, $d, $u, $lines, $portions, $target, $prep) {
            $old = MenuItemRecipe::where('menu_item_id', $m->id)->orderBy('inventory_item_id')->get()->map(fn ($r) => $r->inventory_item_id.':'.round((float) $r->qty, 3))->implode('|');
            $new = $lines->sortBy(fn ($l) => (int) $l['inventory_item_id'])->map(fn ($l) => (int) $l['inventory_item_id'].':'.round((float) $l['qty'], 3))->implode('|');
            $changed = $old !== $new;
            if ($changed) {
                MenuItemRecipe::where('menu_item_id', $m->id)->delete();
                foreach ($lines as $l) {
                    MenuItemRecipe::create(['menu_item_id' => $m->id, 'inventory_item_id' => (int) $l['inventory_item_id'], 'qty' => round((float) $l['qty'], 3)]);
                }
            }
            $card = FbRecipeCard::firstOrNew(['menu_item_id' => $m->id]);
            $card->fill(['batch_portions' => $portions, 'portion_size' => self::text($d['portion_size'] ?? null, 60), 'prep_minutes' => $prep,
                'station' => self::text($d['station'] ?? null, 40), 'garnish' => self::text($d['garnish'] ?? null, 190), 'method' => self::text($d['method'] ?? null, 5000),
                'target_pct' => $target, 'updated_by' => $u->id]);
            if ($changed && $card->exists) {
                $card->version = (int) $card->version + 1;
                $card->approved_by = null;
                $card->approved_at = null;
            }
            $card->save();
            self::flush();
            ActivityLogger::log('fbc', 'recipe', "Recipe card {$m->name} saved".($changed ? " – {$lines->count()} ingredient(s), plate cost ".money(self::portionCost($m->id)) : ''), $m);

            return $card;
        });
    }

    public static function approveCard(FbRecipeCard $c, User $u): FbRecipeCard
    {
        self::need(self::canPrice($u), 'Only the F&B manager or cost controller approves a recipe card.');
        self::need(! $c->approved_at, 'Already approved.');
        self::need($c->updated_by !== $u->id, 'A different person must approve the recipe card – not the one who last changed it.');
        self::need(MenuItemRecipe::where('menu_item_id', $c->menu_item_id)->exists(), 'Add the ingredients before approving.');
        $c->update(['approved_by' => $u->id, 'approved_at' => now()]);
        ActivityLogger::log('fbc', 'recipe_approve', "Recipe card {$c->menuItem?->name} v{$c->version} approved", $c);

        return $c;
    }

    // ------------------------------------------------------------------ snapshots & cost creep

    /** Snapshot every costed dish for the business date (idempotent). Called by the night audit. */
    public static function snapshot(?string $date = null): int
    {
        $date ??= bdate()->toDateString();
        $costs = self::costs();
        $prices = MenuItem::whereIn('id', array_keys($costs))->pluck('price', 'id');
        $n = 0;
        foreach ($costs as $id => $cost) {
            if (! isset($prices[$id])) {
                continue;
            }
            FbCostSnapshot::updateOrCreate(['menu_item_id' => $id, 'business_date' => $date], ['cost' => $cost, 'price' => (float) $prices[$id]]);
            $n++;
        }

        return $n;
    }

    public static function nightly(string $date): void
    {
        self::flush();
        self::snapshot($date);
    }

    /** [menu item id => ['was' => cost, 'on' => date, 'pct' => change %]] against the latest snapshot at or before the window start. */
    public static function creep(): array
    {
        return self::$memo['creep'.Tenant::id()] ??= (function () {
            $costs = self::costs();
            $from = bdate()->copy()->subDays(self::cfg()['creep_days'])->toDateString();
            $base = FbCostSnapshot::where('business_date', '<=', $from)
                ->whereIn('id', FbCostSnapshot::where('business_date', '<=', $from)->groupBy('menu_item_id')->select(DB::raw('MAX(id)')))
                ->get()->keyBy('menu_item_id');
            $out = [];
            foreach ($costs as $id => $cost) {
                $b = $base[$id] ?? null;
                if ($b && $b->cost > 0) {
                    $out[$id] = ['was' => $b->cost, 'on' => $b->business_date, 'pct' => round(($cost - $b->cost) / $b->cost * 100, 1)];
                }
            }

            return $out;
        })();
    }

    /** Plate cost of one dish per snapshot date (for the card's trend). */
    public static function history(int $menuItemId, int $days = 120): Collection
    {
        return FbCostSnapshot::where('menu_item_id', $menuItemId)->where('business_date', '>=', bdate()->copy()->subDays($days)->toDateString())
            ->orderBy('business_date')->get(['business_date', 'cost', 'price']);
    }

    // ------------------------------------------------------------------ sales

    /** Portions and net revenue per menu item (settled, paid bills; complimentary excluded; discounts spread). */
    public static function sales(string $from, string $to, ?int $outletId = null, bool $comps = false): Collection
    {
        return DB::table('pos_order_items as i')->join('pos_orders as o', 'o.id', '=', 'i.pos_order_id')
            ->where('o.hotel_id', Tenant::id())->where('o.status', 'settled')->whereNull('o.merged_into_id')
            ->where('o.settlement', $comps ? '=' : '!=', 'complimentary')
            ->whereBetween('o.business_date', [$from, $to])->where('i.is_cancelled', false)->where('i.is_pending', false)->whereNotNull('i.menu_item_id')
            ->when($outletId, fn ($q) => $q->where('o.outlet_id', $outletId))
            ->groupBy('i.menu_item_id')
            ->select('i.menu_item_id', DB::raw('SUM(i.qty) as qty'), DB::raw('SUM(i.amount * CASE WHEN o.subtotal > 0 THEN (o.subtotal - o.discount) / o.subtotal ELSE 1 END) as revenue'))
            ->get()->keyBy('menu_item_id');
    }

    /** Same, per business date and item. */
    protected static function salesByDay(string $from, string $to, bool $comps = false): Collection
    {
        return DB::table('pos_order_items as i')->join('pos_orders as o', 'o.id', '=', 'i.pos_order_id')
            ->where('o.hotel_id', Tenant::id())->where('o.status', 'settled')->whereNull('o.merged_into_id')
            ->where('o.settlement', $comps ? '=' : '!=', 'complimentary')
            ->whereBetween('o.business_date', [$from, $to])->where('i.is_cancelled', false)->where('i.is_pending', false)->whereNotNull('i.menu_item_id')
            ->groupBy('o.business_date', 'i.menu_item_id')
            ->select('o.business_date', 'i.menu_item_id', DB::raw('SUM(i.qty) as qty'), DB::raw('SUM(i.amount * CASE WHEN o.subtotal > 0 THEN (o.subtotal - o.discount) / o.subtotal ELSE 1 END) as revenue'))
            ->get();
    }

    // ------------------------------------------------------------------ menu engineering

    /** Kasavana & Smith matrix for the period / outlet / menu category. */
    public static function menuEngineering(string $from, string $to, ?int $outletId = null, ?int $categoryId = null): array
    {
        $sold = self::sales($from, $to, $outletId);
        $costs = self::costs();
        $items = MenuItem::with('category')
            ->when($outletId, fn ($q) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $outletId)))
            ->when($categoryId, fn ($q) => $q->where('menu_category_id', $categoryId))
            ->where(fn ($q) => $q->where('is_available', true)->orWhereIn('id', $sold->keys()))
            ->orderBy('name')->get();
        $rows = $items->map(function ($m) use ($sold, $costs) {
            $s = $sold[$m->id] ?? null;
            $n = (float) ($s->qty ?? 0);
            $rev = (float) ($s->revenue ?? 0);
            $price = $n > 0 ? $rev / $n : (float) $m->price;
            $cost = $costs[$m->id] ?? null;

            return (object) ['item' => $m, 'n' => $n, 'revenue' => round($rev, 2), 'price' => round($price, 2), 'cost' => $cost,
                'cm' => $cost !== null ? round($price - $cost, 2) : null, 'total_cm' => $cost !== null ? round(($price - $cost) * $n, 2) : null,
                'total_cost' => $cost !== null ? round($cost * $n, 2) : null];
        });
        $costed = $rows->filter(fn ($r) => $r->cost !== null)->values();
        $N = $costed->sum('n');
        $count = $costed->count();
        $threshold = $count ? 100 / $count * self::cfg()['popularity'] / 100 : 0;
        $avgCm = $N > 0 ? $costed->sum('total_cm') / $N : 0;
        foreach ($costed as $r) {
            $r->mix = $N > 0 ? round($r->n / $N * 100, 2) : 0;
            $pop = $r->mix >= $threshold && $r->n > 0;
            $hi = $r->cm >= $avgCm;
            $r->cls = $pop ? ($hi ? 'star' : 'plowhorse') : ($hi ? 'puzzle' : 'dog');
        }
        $rev = $costed->sum('revenue');
        $cost = $costed->sum('total_cost');

        return ['rows' => $costed->sortByDesc('total_cm')->values(), 'uncosted' => $rows->filter(fn ($r) => $r->cost === null)->values(),
            'n' => $N, 'count' => $count, 'threshold' => round($threshold, 2), 'avg_cm' => round($avgCm, 2),
            'revenue' => round($rev, 2), 'cost' => round($cost, 2), 'cm' => round($rev - $cost, 2), 'pct' => $rev > 0 ? round($cost / $rev * 100, 1) : null,
            'counts' => collect(array_keys(self::CLASSES))->mapWithKeys(fn ($k) => [$k => $costed->where('cls', $k)->count()])->all()];
    }

    // ------------------------------------------------------------------ theoretical vs actual

    /** Stores whose outflows are F&B cost: kitchen and bar stores plus every outlet's store. */
    public static function fbStores(): array
    {
        return self::$memo['stores'.Tenant::id()] ??= Store::whereIn('type', ['kitchen', 'bar'])->pluck('id')
            ->merge(Outlet::whereNotNull('store_id')->pluck('store_id'))->unique()->values()->all();
    }

    /** Per business date and class: revenue, theoretical cost, stores outflows by kind, staff meals and comps. */
    public static function figures(string $from, string $to): array
    {
        $key = "fig{$from}{$to}".Tenant::id();
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $costs = self::costs();
        $byClass = self::costsByClass();
        $cls = MenuItem::with('category')->get()->mapWithKeys(fn ($m) => [$m->id => self::classOf($m)]);
        $blank = fn () => ['revenue' => 0.0, 'theoretical' => 0.0, 'uncosted_revenue' => 0.0, 'consumption' => 0.0, 'issues' => 0.0, 'waste' => 0.0, 'other_waste' => 0.0,
            'adjustments' => 0.0, 'staff_meals' => 0.0, 'comps' => 0.0];
        $out = [];
        foreach (self::salesByDay($from, $to) as $s) {
            $d = Carbon::parse($s->business_date)->toDateString();
            $c = $cls[$s->menu_item_id] ?? 'food';
            $out[$d][$c] ??= $blank();
            $out[$d][$c]['revenue'] += (float) $s->revenue;
            if (! isset($costs[$s->menu_item_id])) {
                $out[$d][$c]['uncosted_revenue'] += (float) $s->revenue;
            }
            foreach ($byClass[$s->menu_item_id] ?? [] as $ic => $v) { // theoretical by ingredient class
                $out[$d][$ic] ??= $blank();
                $out[$d][$ic]['theoretical'] += (float) $s->qty * $v;
            }
        }
        foreach (self::salesByDay($from, $to, true) as $s) {
            $d = Carbon::parse($s->business_date)->toDateString();
            foreach ($byClass[$s->menu_item_id] ?? [] as $ic => $v) {
                $out[$d][$ic] ??= $blank();
                $out[$d][$ic]['comps'] += (float) $s->qty * $v;
            }
        }
        $itemCls = InventoryItem::leftJoin('item_categories as c', 'c.id', '=', 'inventory_items.item_category_id')->pluck('c.fb_class', 'inventory_items.id');
        $moves = StockMovement::whereIn('store_id', self::fbStores())->whereBetween('date', [$from, $to])
            ->whereIn('type', ['consumption', 'issue', 'wastage', 'adjustment'])->with('item:id,last_cost')->get();
        foreach ($moves as $m) {
            $c = $itemCls[$m->inventory_item_id] ?? null;
            if (! in_array($c, ['food', 'beverage'], true)) {
                continue;
            }
            $d = $m->date->toDateString();
            $out[$d][$c] ??= $blank();
            $value = -(float) $m->qty * ((float) $m->rate ?: (float) ($m->item?->last_cost ?? 0)); // outflow = positive cost
            $bucket = match ($m->type) {
                'consumption' => 'consumption',
                'issue' => 'issues',
                'wastage' => str_starts_with((string) $m->reference, 'FW-') ? 'waste' : 'other_waste',
                default => 'adjustments',
            };
            $out[$d][$c][$bucket] += $value;
        }
        foreach (FbStaffMeal::whereBetween('business_date', [$from, $to])->get() as $sm) {
            $d = $sm->business_date->toDateString();
            $out[$d]['food'] ??= $blank();
            $out[$d]['food']['staff_meals'] += $sm->total;
        }
        ksort($out);

        return self::$memo[$key] = $out;
    }

    /** Totals for a class over the period, with actual cost, cost of sales and the percentages. */
    public static function statement(string $from, string $to, string $class = 'food'): array
    {
        $t = ['revenue' => 0.0, 'theoretical' => 0.0, 'uncosted_revenue' => 0.0, 'consumption' => 0.0, 'issues' => 0.0, 'waste' => 0.0, 'other_waste' => 0.0,
            'adjustments' => 0.0, 'staff_meals' => 0.0, 'comps' => 0.0];
        foreach (self::figures($from, $to) as $day) {
            foreach ($day[$class] ?? [] as $k => $v) {
                $t[$k] += $v;
            }
        }
        $t = array_map(fn ($v) => round($v, 2), $t);

        return $t + self::derive($t, $class);
    }

    protected static function derive(array $t, string $class): array
    {
        $actual = round($t['consumption'] + $t['issues'] + $t['waste'] + $t['other_waste'] + $t['adjustments'], 2);
        $cos = round($actual - $t['staff_meals'] - $t['comps'], 2);
        $rev = $t['revenue'];
        $target = $class === 'beverage' ? self::cfg()['bev_target'] : self::cfg()['food_target'];

        return ['actual' => $actual, 'cost_of_sales' => $cos, 'recipe_gap' => round($t['consumption'] - $t['theoretical'], 2),
            'variance' => round($cos - $t['theoretical'], 2), 'pct' => $rev > 0 ? round($cos / $rev * 100, 1) : null,
            'theoretical_pct' => $rev > 0 ? round($t['theoretical'] / $rev * 100, 1) : null, 'target' => $target];
    }

    /** Daily flash rows: [date => ['food' => [...derived], 'beverage' => [...]]]. */
    public static function daily(string $from, string $to): array
    {
        $out = [];
        $fig = self::figures($from, $to);
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay()) {
            $k = $d->toDateString();
            foreach (['food', 'beverage'] as $c) {
                $t = array_map(fn ($v) => round($v, 2), $fig[$k][$c] ?? ['revenue' => 0.0, 'theoretical' => 0.0, 'uncosted_revenue' => 0.0, 'consumption' => 0.0, 'issues' => 0.0,
                    'waste' => 0.0, 'other_waste' => 0.0, 'adjustments' => 0.0, 'staff_meals' => 0.0, 'comps' => 0.0]);
                $out[$k][$c] = $t + self::derive($t, $c);
            }
        }

        return $out;
    }

    /** Ideal vs actual usage per ingredient (quantities in the stock unit). */
    public static function usage(string $from, string $to, ?string $class = null): Collection
    {
        $sold = self::sales($from, $to)->map(fn ($s) => (float) $s->qty);
        $comps = self::sales($from, $to, null, true)->map(fn ($s) => (float) $s->qty);
        $ideal = [];
        foreach (MenuItemRecipe::with('inventoryItem')->whereIn('menu_item_id', $sold->keys()->merge($comps->keys())->unique())->get() as $r) {
            if (! $r->inventoryItem) {
                continue;
            }
            $n = ($sold[$r->menu_item_id] ?? 0) + ($comps[$r->menu_item_id] ?? 0);
            $ideal[$r->inventory_item_id] = ($ideal[$r->inventory_item_id] ?? 0) + $n * (float) $r->qty * 100 / max(1, (float) ($r->inventoryItem->yield_pct ?: 100));
        }
        $actual = StockMovement::whereIn('store_id', self::fbStores())->whereBetween('date', [$from, $to])
            ->whereIn('type', ['consumption', 'issue', 'wastage', 'adjustment'])
            ->groupBy('inventory_item_id', 'type')->select('inventory_item_id', 'type', DB::raw('-SUM(qty) as used'))->get()->groupBy('inventory_item_id');
        $ids = collect(array_keys($ideal))->merge($actual->keys())->unique();

        return InventoryItem::with('category')->whereIn('id', $ids)->orderBy('name')->get()
            ->filter(fn ($i) => in_array($i->category?->fb_class, ['food', 'beverage'], true) && (! $class || $i->category->fb_class === $class))
            ->map(function ($i) use ($ideal, $actual) {
                $by = ($actual[$i->id] ?? collect())->pluck('used', 'type')->map(fn ($v) => (float) $v);
                $act = $by->sum();
                $idq = round($ideal[$i->id] ?? 0, 3);
                $uc = self::unitCost($i);
                $var = round($act - $idq, 3);

                return (object) ['item' => $i, 'ideal' => $idq, 'actual' => round($act, 3), 'consumed' => round($by['consumption'] ?? 0, 3),
                    'issued' => round($by['issue'] ?? 0, 3), 'wasted' => round($by['wastage'] ?? 0, 3), 'counted' => round($by['adjustment'] ?? 0, 3),
                    'variance' => $var, 'unit_cost' => $uc, 'value' => round($var * $uc, 2), 'pct' => $idq > 0 ? round($var / $idq * 100, 1) : null];
            })->sortByDesc(fn ($r) => abs($r->value))->values();
    }

    // ------------------------------------------------------------------ pricing

    public static function changePrice(MenuItem $m, $price, string $reason, User $u): FbPriceChange
    {
        self::need(self::canPrice($u), 'Only the F&B manager or cost controller changes menu prices.');
        self::need(is_numeric($price) && $price > 0 && $price <= 100000, 'Enter the new price.');
        $new = round((float) $price, 2);
        $old = (float) $m->price;
        self::need(abs($new - $old) >= 0.01, 'The price is unchanged.');
        self::need(trim($reason) !== '', 'Give a reason for the price change.');
        $cost = self::portionCost($m->id);
        self::need($cost <= 0 || $new > $cost, 'The new price must be above the plate cost of '.money($cost).'.');
        self::need($old <= 0 || abs($new - $old) / $old <= 0.5, 'A change of more than 50 % needs to go through a menu review – change it in two steps or in the menu master.');

        return DB::transaction(function () use ($m, $new, $old, $cost, $reason, $u) {
            $m->update(['price' => $new]);
            $pc = FbPriceChange::create(['menu_item_id' => $m->id, 'effective_on' => bdate()->toDateString(), 'old_price' => $old, 'new_price' => $new, 'cost_at' => $cost,
                'reason' => mb_substr(trim($reason), 0, 190), 'user_id' => $u->id]);
            ActivityLogger::log('fbc', 'price', "{$m->name}: price ".money($old).' → '.money($new)." ({$reason})", $m);
            self::flush();

            return $pc;
        });
    }

    // ------------------------------------------------------------------ yield tests

    /** $d: inventory_item_id, tested_on, gross_qty, trim_qty, cooking_loss_qty, usable_qty (optional), notes */
    public static function recordYield(array $d, User $u): FbYieldTest
    {
        self::need(self::canCost($u));
        $item = InventoryItem::where('is_active', true)->find((int) ($d['inventory_item_id'] ?? 0));
        self::need((bool) $item, 'Choose the ingredient.');
        $on = self::date($d['tested_on'] ?? null) ?? bdate()->copy();
        self::need($on->toDateString() <= bdate()->toDateString() && $on->gte(bdate()->copy()->subYear()), 'The test date must be within the last year.');
        $gross = (float) ($d['gross_qty'] ?? 0);
        $trim = (float) ($d['trim_qty'] ?? 0);
        $cook = (float) ($d['cooking_loss_qty'] ?? 0);
        self::need($gross > 0 && $gross <= 10000, 'Enter the weight / quantity as purchased.');
        self::need($trim >= 0 && $cook >= 0, 'Trim and cooking loss cannot be negative.');
        $usable = filled($d['usable_qty'] ?? null) ? (float) $d['usable_qty'] : $gross - $trim - $cook;
        self::need($usable > 0, 'Nothing usable is left – check the weights.');
        self::need($usable <= $gross + 0.0005, 'The usable quantity cannot be more than the gross.');
        self::need(abs(($trim + $cook + $usable) - $gross) <= max(0.01, $gross * 0.02), 'Usable + trim + cooking loss must add up to the gross weight (within 2 %).');
        $pct = round($usable / $gross * 100, 2);
        $t = FbYieldTest::create(['number' => Numbering::next('fb_yield'), 'inventory_item_id' => $item->id, 'tested_on' => $on->toDateString(), 'gross_qty' => round($gross, 3),
            'trim_qty' => round($trim, 3), 'cooking_loss_qty' => round($cook, 3), 'usable_qty' => round($usable, 3), 'yield_pct' => $pct, 'unit_cost' => self::unitCost($item),
            'notes' => self::text($d['notes'] ?? null), 'user_id' => $u->id]);
        ActivityLogger::log('fbc', 'yield', "{$t->number} {$item->name}: yield {$pct} % ({$usable} of {$gross} {$item->unit})", $t);

        return $t;
    }

    /** Use the test's yield for the ingredient from now on (plate costs and recipe consumption). */
    public static function applyYield(FbYieldTest $t, User $u): FbYieldTest
    {
        self::need(self::canCost($u));
        self::need(! $t->applied, 'This yield is already applied.');
        DB::transaction(function () use ($t, $u) {
            FbYieldTest::where('inventory_item_id', $t->inventory_item_id)->where('applied', true)->update(['applied' => false]);
            $t->update(['applied' => true, 'applied_by' => $u->id]);
            InventoryItem::whereKey($t->inventory_item_id)->update(['yield_pct' => $t->yield_pct]);
        });
        self::flush();
        ActivityLogger::log('fbc', 'yield_apply', "{$t->item->name}: yield set to {$t->yield_pct} % from {$t->number}", $t);

        return $t;
    }

    // ------------------------------------------------------------------ staff meals

    /** $d: business_date, meal, covers, cost_per_cover, notes. One entry per date and meal (saving again replaces it). */
    public static function saveMeal(array $d, User $u): FbStaffMeal
    {
        self::need(self::canCost($u));
        $on = self::date($d['business_date'] ?? null) ?? bdate()->copy();
        self::need($on->toDateString() <= bdate()->toDateString() && $on->gte(bdate()->copy()->subDays(31)), 'Staff meals are logged for the business date or up to 31 days back.');
        self::need(isset(FbStaffMeal::MEALS[$d['meal'] ?? '']), 'Choose the meal.');
        $covers = (int) ($d['covers'] ?? 0);
        self::need($covers >= 1 && $covers <= 2000, 'Covers are 1 to 2,000.');
        $cpc = filled($d['cost_per_cover'] ?? null) ? (float) $d['cost_per_cover'] : self::cfg()['meal_cost'];
        self::need($cpc > 0 && $cpc <= 500, 'The cost per cover is more than 0 and at most 500.');
        $m = FbStaffMeal::updateOrCreate(['business_date' => $on->toDateString(), 'meal' => $d['meal']],
            ['covers' => $covers, 'cost_per_cover' => round($cpc, 2), 'total' => round($covers * $cpc, 2), 'notes' => self::text($d['notes'] ?? null, 190), 'user_id' => $u->id]);
        self::flush();
        ActivityLogger::log('fbc', 'staff_meal', "Staff {$d['meal']} {$on->format('d M')}: {$covers} covers × ".money($cpc), $m);

        return $m;
    }

    public static function deleteMeal(FbStaffMeal $m, User $u): void
    {
        self::need(self::canCost($u));
        self::need($m->business_date->gte(bdate()->copy()->subDays(31)), 'Entries older than 31 days are locked.');
        $m->delete();
        self::flush();
        ActivityLogger::log('fbc', 'staff_meal_delete', "Staff {$m->meal} {$m->business_date->format('d M')} removed");
    }

    // ------------------------------------------------------------------ dashboard

    public static function kpis(): array
    {
        return self::$memo['kpi'.Tenant::id()] ??= (function () {
            $to = bdate()->copy()->subDay();
            $from = bdate()->copy()->startOfMonth();
            if ($from->gt($to)) {
                $from = $to->copy()->startOfMonth();
            }
            $f = self::statement($from->toDateString(), $to->toDateString(), 'food');
            $b = self::statement($from->toDateString(), $to->toDateString(), 'beverage');
            $d = self::dishes();

            return ['from' => $from, 'to' => $to, 'food' => $f, 'beverage' => $b, 'dishes' => $d->count(), 'costed' => $d->where('st', '!=', 'no_recipe')->count(),
                'over' => $d->where('st', 'over')->count(), 'creep' => $d->filter(fn ($m) => $m->creep && $m->creep['pct'] >= self::cfg()['creep_pct'])->count()];
        })();
    }

    public static function alerts(): array
    {
        return self::$memo['alerts'.Tenant::id()] ??= (function () {
            $d = self::dishes();
            $used = MenuItemRecipe::distinct()->pluck('inventory_item_id');
            $mealsInUse = FbStaffMeal::where('business_date', '>=', bdate()->copy()->subDays(30)->toDateString())->exists();

            return [
                'over' => $d->where('st', 'over')->count(),
                'no_recipe' => $d->where('st', 'no_recipe')->where('is_available', true)->count(),
                'creep' => $d->filter(fn ($m) => $m->creep && $m->creep['pct'] >= self::cfg()['creep_pct'])->count(),
                'zero_cost' => InventoryItem::whereIn('id', $used)->where(fn ($q) => $q->whereNull('last_cost')->orWhere('last_cost', '<=', 0))->count(),
                'unapproved' => FbRecipeCard::whereNull('approved_at')->whereIn('menu_item_id', MenuItemRecipe::distinct()->pluck('menu_item_id'))->count(),
                'meals_missing' => $mealsInUse && ! FbStaffMeal::where('business_date', bdate()->copy()->subDay()->toDateString())->exists() ? 1 : 0,
            ];
        })();
    }

    /** Nav badge: dishes over target, cost creep and dishes on sale without a recipe. */
    public static function attention(): int
    {
        $a = self::alerts();

        return $a['over'] + $a['creep'] + $a['no_recipe'];
    }

    // ------------------------------------------------------------------ helpers

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    protected static function text($v, int $max = 255): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    protected static function date($v): ?Carbon
    {
        if (! filled($v)) {
            return null;
        }
        try {
            return Carbon::parse((string) $v)->startOfDay();
        } catch (\Throwable) {
            throw new RuntimeException('Enter a valid date.');
        }
    }
}
