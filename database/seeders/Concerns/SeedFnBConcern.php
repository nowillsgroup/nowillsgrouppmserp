<?php

namespace Database\Seeders\Concerns;

use App\Models;
use App\Services\FolioService;
use App\Services\HotelProvisioner;
use App\Services\ApService;
use App\Services\ForexService;
use App\Services\LedgerService;
use App\Services\StayService;
use App\Services\TaxService;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Demo-data seeders — split out of DatabaseSeeder (one file per domain).
 * Composed into DatabaseSeeder via `use`; every method keeps its original visibility and body.
 */
trait SeedFnBConcern
{
    protected function seedFb(bool $rich): void
    {
        $FC = \App\Services\FbCostService::class;
        $FC::ensureDefaults();
        if (! $rich) {
            return;
        }
        $hid = \App\Support\Tenant::id();
        $u = fn (string $n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        $admin = $u('admin');
        $chef = $u('chef') ?? $admin;
        $fbm = $u('restaurant') ?? $admin;
        $cashier = $u('cashier') ?? $admin;
        $B = bdate()->copy()->startOfDay();
        mt_srand(6800);
        Auth::setUser($chef);
        $kitchen = (int) Models\Store::where('type', 'kitchen')->value('id');
        $barStore = (int) Models\Store::where('type', 'bar')->value('id');
        Models\Outlet::where('type', 'bar')->update(['store_id' => $barStore]); // the bar sells from its own store, so bottles are counted there
        $groc = Models\ItemCategory::where('name', 'Grocery')->value('id');
        $bev = Models\ItemCategory::where('name', 'Beverages')->value('id');
        $liq = Models\ItemCategory::create(['name' => 'Liquor', 'fb_class' => 'beverage'])->id;
        // [code, name, unit, cost, category, opening qty in the kitchen / outlet store]
        foreach ([['FB101', 'Mutton (bone-in)', 'kg', 420, $groc, 40], ['FB102', 'Onion', 'kg', 18, $groc, 120], ['FB103', 'Tomato', 'kg', 22, $groc, 90],
            ['FB104', 'Black Lentils (Urad)', 'kg', 48, $groc, 60], ['FB105', 'Fresh Milk', 'ltr', 16, $groc, 260], ['FB106', 'Tea Leaves', 'kg', 160, $groc, 8],
            ['FB107', 'Coffee Powder', 'kg', 240, $groc, 8], ['FB108', 'Sugar', 'kg', 20, $groc, 60], ['FB109', 'Vanilla Ice Cream', 'ltr', 70, $groc, 30],
            ['FB110', 'Idli / Dosa Rice', 'kg', 30, $groc, 40], ['FB111', 'Potato', 'kg', 15, $groc, 60], ['FB112', 'Bread Loaf', 'loaf', 25, $groc, 40],
            ['FB113', 'Ginger-Garlic Paste', 'kg', 60, $groc, 12], ['FB114', 'Garam Masala', 'kg', 220, $groc, 5], ['FB115', 'Mixed Vegetables', 'kg', 25, $groc, 80],
            ['FB116', 'Gram Flour (Besan)', 'kg', 40, $groc, 15], ['FB117', 'Khoya (Milk Solids)', 'kg', 90, $groc, 12], ['FB118', 'Lime', 'kg', 30, $groc, 25],
            ['FB119', 'Fresh Mint', 'bunch', 5, $groc, 90], ['FB120', 'Chocolate Sauce', 'ltr', 85, $groc, 5], ['FB121', 'Soda Water 300ml', 'btl', 6, $bev, 300],
            ['FB131', 'Johnnie Walker Black 750ml', 'btl', 2600, $liq, 14], ['FB132', 'Old Monk Rum 750ml', 'btl', 520, $liq, 18],
            ['FB133', 'Kingfisher Beer 330ml', 'btl', 85, $liq, 420], ['FB134', 'House Red Wine 750ml', 'btl', 420, $liq, 40]] as [$code, $nm, $unit, $cost, $cat, $open]) {
            $it = Models\InventoryItem::create(['item_category_id' => $cat, 'code' => $code, 'name' => $nm, 'unit' => $unit, 'reorder_level' => max(1, round($open / 5)), 'last_cost' => $cost, 'yield_pct' => 100, 'is_active' => true]);
            $split = $cat === $liq ? [$barStore => $open] : (in_array($code, ['FB108', 'FB118', 'FB119', 'FB121'], true) ? [$kitchen => $open / 2, $barStore => $open / 2] : [$kitchen => $open]);
            foreach ($split as $st => $q) {
                Models\StockMovement::create(['inventory_item_id' => $it->id, 'store_id' => $st, 'date' => $B->copy()->subDays(32)->toDateString(), 'type' => 'opening', 'qty' => $q, 'rate' => $cost,
                    'reference' => 'OPEN-FB', 'remarks' => 'Opening stock – F&B cost control', 'user_id' => $chef->id]);
            }
        }
        $I = Models\InventoryItem::pluck('id', 'code');
        foreach (['FS026' => 145, 'ITM004' => 40, 'ITM001' => 40, 'FS027' => 15, 'FS028' => 15, 'FS029' => 10, 'FS030' => 3, 'FS032' => 15, 'FS034' => 3] as $code => $open) { // top up grocery lines the kitchen never held
            Models\StockMovement::create(['inventory_item_id' => $I[$code], 'store_id' => $kitchen, 'date' => $B->copy()->subDays(32)->toDateString(), 'type' => 'opening', 'qty' => $open,
                'rate' => (float) Models\InventoryItem::whereKey($I[$code])->value('last_cost'), 'reference' => 'OPEN-FB', 'remarks' => 'Opening stock – F&B cost control', 'user_id' => $chef->id]);
        }
        // yield tests (four applied, one pending)
        foreach ([['FB101', 40, 5, 1.3, 0, 'Goat leg from Kaneshie market – bones & fat trimmed'], ['FB102', 38, 10, 1.0, 0, 'Peeled & topped'],
            ['FB103', 37, 8, 0.5, 0, 'Cored, bruised fruit removed'], ['FS032', 36, 4, 0.2, 0, 'Pin bones & skin'], ['FB118', 6, 2, 1.1, 0, 'Juice yield – pending review']] as [$code, $ago, $gross, $trim, $loss, $note]) {
            $t = $FC::recordYield(['inventory_item_id' => $I[$code], 'tested_on' => $B->copy()->subDays($ago)->toDateString(), 'gross_qty' => $gross, 'trim_qty' => $trim, 'cooking_loss_qty' => $loss, 'notes' => $note], $chef);
            if ($code !== 'FB118') {
                $FC::applyYield($t, $chef);
            }
        }
        // recipes – net quantity per portion (the 9 original recipes are re-saved unchanged so they get a card header)
        $M = Models\MenuItem::pluck('id', 'name');
        $new = [
            'Veg Spring Rolls' => [['FB115', .15], ['FS026', .06], ['ITM002', .08], ['FS034', .015], ['FB113', .01]],
            'Fish Amritsari' => [['FS032', .3], ['FB116', .06], ['ITM002', .1], ['FB113', .02], ['FB118', .02], ['FB114', .005]],
            'Dal Makhani' => [['FB104', .1], ['FS027', .04], ['FS028', .05], ['FB103', .08], ['FB102', .05], ['FB113', .01], ['FB114', .004]],
            'Mutton Rogan Josh' => [['FB101', .3], ['FB102', .12], ['FB103', .08], ['ITM002', .05], ['FB113', .02], ['FB114', .008], ['FS028', .03]],
            'Butter Naan' => [['FS026', .12], ['FS027', .015], ['FB105', .03]],
            'Garlic Naan' => [['FS026', .12], ['FS027', .02], ['FB113', .01]],
            'Masala Dosa' => [['FB110', .12], ['FB104', .03], ['FB111', .15], ['FB102', .04], ['ITM002', .03]],
            'Idli Vada' => [['FB110', .1], ['FB104', .06], ['ITM002', .05], ['FB102', .02]],
            'Omelette' => [['FS029', .1], ['FB102', .03], ['FB103', .03], ['FS027', .01]],
            'Continental Breakfast' => [['FS029', .067], ['FB112', .2], ['FS027', .03], ['FB105', .25], ['FB111', .15], ['FB103', .05], ['FB107', .012]],
            'Gulab Jamun' => [['FB117', .06], ['FB108', .08], ['ITM002', .04], ['FS026', .01]],
            'Ice Cream Sundae' => [['FB109', .2], ['FB120', .03], ['FS030', .015]],
            'Masala Chai' => [['FB105', .12], ['FB106', .006], ['FB108', .015]],
            'Filter Coffee' => [['FB105', .12], ['FB107', .012], ['FB108', .015]],
            'Fresh Lime Soda' => [['FB118', .05], ['FB121', 1], ['FB108', .02]],
            'Cold Coffee' => [['FB105', .25], ['FB107', .015], ['FB109', .08], ['FB108', .02]],
            'Kingfisher Premium' => [['FB133', 1]],
            'Old Monk (60ml)' => [['FB132', .08]],
            'Johnnie Walker Black (60ml)' => [['FB131', .08]],
            'House Red Wine (Glass)' => [['FB134', .2]],
            'Mojito (Mocktail)' => [['FB118', .05], ['FB119', .25], ['FB108', .025], ['FB121', 1]],
        ];
        $methods = [
            'Butter Chicken' => "1. Marinate the chicken in yoghurt, ginger-garlic and spices for at least 4 hours.\n2. Char in the tandoor to 75 °C core; rest.\n3. Simmer the makhani gravy (tomato, butter, cashew), add the chicken, finish with cream and kasuri methi.\n4. Portion 250 g into a copper handi.",
            'Mutton Rogan Josh' => "1. Brown the trimmed mutton in batches.\n2. Sweat onion, add ginger-garlic, Kashmiri chilli and garam masala.\n3. Add tomato and the mutton; braise covered 90 min until tender.\n4. Finish with cream; hold at 63 °C or above; portion 300 g net meat.",
            'Dal Makhani' => "1. Soak the urad overnight; pressure-cook until soft.\n2. Simmer 3 hours with tomato, butter and ginger-garlic, stirring.\n3. Finish with cream and butter; portion one bowl (220 g).",
            'Masala Dosa' => "1. Ferment the rice-urad batter 10–12 hours.\n2. Temper potato masala with mustard seeds and onion.\n3. Spread the batter thin on the tawa, oil the edges, fill and fold. Serve with sambar and chutney.",
            'Mojito (Mocktail)' => "1. Muddle lime wedges, mint leaves and sugar in a highball.\n2. Fill with crushed ice, top with soda and stir.\n3. Garnish with a mint sprig and a lime wheel.",
            'Johnnie Walker Black (60ml)' => "Pour 60 ml with the jigger – no free pouring. Serve neat, on the rocks or with a mixer on request.",
        ];
        $portion = ['Starters' => '6 pieces', 'Main Course' => '1 handi (250 g)', 'Breads & Rice' => '1 piece / 1 bowl', 'Breakfast' => '1 plate', 'Desserts' => '1 bowl', 'Beverages' => '1 glass', 'Bar' => '1 measure / bottle'];
        $items = Models\MenuItem::with(['category', 'recipes'])->orderBy('id')->get();
        $drafts = ['Veg Spring Rolls', 'Cold Coffee', 'Garlic Naan'];
        foreach ($items as $mi) {
            $lines = isset($new[$mi->name])
                ? collect($new[$mi->name])->map(fn ($l) => ['inventory_item_id' => $I[$l[0]], 'qty' => $l[1]])->all()
                : $mi->recipes->map(fn ($r) => ['inventory_item_id' => $r->inventory_item_id, 'qty' => (float) $r->qty])->all();
            if (! $lines) {
                continue;
            }
            $card = $FC::saveCard($mi, ['lines' => $lines, 'batch_portions' => in_array($mi->category?->name, ['Main Course', 'Desserts'], true) ? 10 : 1,
                'portion_size' => $portion[$mi->category?->name] ?? null, 'prep_minutes' => match ($mi->category?->name) { 'Main Course' => 35, 'Starters' => 20, 'Breakfast' => 12, 'Bar', 'Beverages' => 3, default => 10 },
                'method' => $methods[$mi->name] ?? '1. Weigh every ingredient against this card.'."\n".'2. Prepare and cook to the standard; check seasoning.'."\n".'3. Plate the standard portion and garnish.',
                'garnish' => $mi->category?->name === 'Main Course' ? 'Coriander, cream swirl' : null], $chef);
            if (! in_array($mi->name, $drafts, true)) {
                $card->forceFill(['approved_by' => $fbm->id, 'approved_at' => $B->copy()->subDays(30)->setTime(11, 0)])->save();
            }
        }
        $FC::flush();
        // historic price changes (logged at the time)
        foreach ([['Kingfisher Premium', 20, 260, 280, 'Brewery price increase passed on'], ['Masala Chai', 12, 50, 60, 'Menu review – milk & sugar prices']] as [$nm, $ago, $old, $newP, $why]) {
            Models\FbPriceChange::create(['menu_item_id' => $M[$nm], 'effective_on' => $B->copy()->subDays($ago)->toDateString(), 'old_price' => $old, 'new_price' => $newP,
                'cost_at' => $FC::portionCost($M[$nm]), 'reason' => $why, 'user_id' => $fbm->id]);
        }
        // 28 days of settled bills with a weighted popularity mix
        $rest = Models\Outlet::where('type', 'restaurant')->first();
        $bar = Models\Outlet::where('type', 'bar')->first();
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $byName = $items->keyBy('name');
        $wRest = ['Butter Chicken' => 14, 'Chicken Biryani' => 12, 'Butter Naan' => 16, 'Garlic Naan' => 10, 'Paneer Butter Masala' => 8, 'Dal Makhani' => 9, 'Steamed Rice' => 7,
            'Jeera Rice' => 6, 'Chicken 65' => 8, 'Paneer Tikka' => 7, 'Veg Spring Rolls' => 3, 'Fish Amritsari' => 2, 'Mutton Rogan Josh' => 6, 'Veg Biryani' => 3, 'Masala Dosa' => 5,
            'Idli Vada' => 3, 'Omelette' => 4, 'Continental Breakfast' => 5, 'Gulab Jamun' => 6, 'Ice Cream Sundae' => 2, 'Masala Chai' => 8, 'Filter Coffee' => 5, 'Fresh Lime Soda' => 6,
            'Mineral Water' => 10, 'Cold Coffee' => 1];
        $wBar = ['Kingfisher Premium' => 20, 'Old Monk (60ml)' => 8, 'Johnnie Walker Black (60ml)' => 5, 'House Red Wine (Glass)' => 2, 'Mojito (Mocktail)' => 7, 'Fresh Lime Soda' => 3, 'Mineral Water' => 3];
        $pick = function (array $w) {
            $r = mt_rand(1, array_sum($w));
            foreach ($w as $k => $v) {
                if (($r -= $v) <= 0) {
                    return $k;
                }
            }

            return array_key_first($w);
        };
        $tables = Models\PosTable::where('outlet_id', $rest->id)->pluck('id')->all();
        $made = collect();
        for ($ago = 28; $ago >= 1; $ago--) {
            $day = $B->copy()->subDays($ago);
            $wk = in_array($day->dayOfWeek, [5, 6], true);
            foreach ([[$rest, $wRest, 8 + ($wk ? 4 : 0) + mt_rand(0, 2)], [$bar, $wBar, 4 + ($wk ? 3 : 0) + mt_rand(0, 1)]] as [$outlet, $w, $n]) {
                for ($k = 0; $k < $n; $k++) {
                    $comp = $outlet->id === $rest->id && $k === 0 && $ago % 3 === 0;
                    $covers = mt_rand(1, 4);
                    $lines = [];
                    for ($x = 0, $cnt = $covers + mt_rand(0, 2); $x < $cnt; $x++) {
                        $nm = $pick($w);
                        $lines[$nm] = ($lines[$nm] ?? 0) + (in_array($nm, ['Butter Naan', 'Garlic Naan', 'Kingfisher Premium'], true) && mt_rand(0, 2) === 0 ? 2 : 1);
                    }
                    $at = $day->copy()->setTime($outlet->id === $bar->id ? mt_rand(18, 22) : [8, 13, 13, 14, 19, 20, 21][mt_rand(0, 6)], mt_rand(0, 59));
                    $o = Models\PosOrder::create(['order_no' => Numbering::next('pos_order'), 'bill_no' => Numbering::next('pos_bill'), 'outlet_id' => $outlet->id,
                        'pos_table_id' => $outlet->id === $rest->id && $tables ? $tables[array_rand($tables)] : null, 'order_type' => 'dine_in', 'covers' => $covers,
                        'business_date' => $day->toDateString(), 'status' => 'settled', 'settlement' => $comp ? 'complimentary' : 'payment', 'payment_mode_id' => $comp ? null : $cash,
                        'customer_name' => $comp ? 'Officer\'s check – GM hosting a corporate client' : null, 'comp_reason' => $comp ? 'VIP / owner guest' : null, 'user_id' => $cashier->id, 'settled_by' => $cashier->id, 'settled_at' => $at]);
                    $sub = 0;
                    foreach ($lines as $nm => $q) {
                        $mi = $byName[$nm];
                        $rate = match (true) { $nm === 'Kingfisher Premium' && $ago > 20 => 260, $nm === 'Masala Chai' && $ago > 12 => 50, default => (float) $mi->price };
                        $o->items()->create(['menu_item_id' => $mi->id, 'item_name' => $mi->name, 'qty' => $q, 'rate' => $rate, 'tax_rate' => $mi->tax_rate, 'amount' => $q * $rate, 'kot_no' => 1]);
                        $sub += $q * $rate;
                    }
                    if (! $comp && mt_rand(1, 9) === 1) {
                        $o->discount = round($sub * 0.1, 2); // loyalty / corporate 10 %
                    }
                    $o->recalculate();
                    \App\Services\RecipeService::consume($o);
                    $made->push($o);
                }
            }
        }
        foreach (Models\PosOrder::where('status', 'settled')->where('business_date', '<', $B->toDateString())->whereNotIn('id', $made->pluck('id'))->get() as $o) {
            \App\Services\RecipeService::consume($o); // earlier seeded bills now that every dish has a recipe (idempotent)
        }
        if (\App\Services\PosEvatService::enabled()) {
            $clock = Carbon::getTestNow();
            foreach ($made->where('settlement', 'payment') as $o) {
                Carbon::setTestNow($o->settled_at->lt(now()) ? $o->settled_at : now());
                \App\Services\PosEvatService::generate($o->fresh());
                Carbon::setTestNow($clock);
            }
        }
        // staff canteen: daily issue from the kitchen store + the covers served
        $canteen = [['ITM001', 2.5], ['FB104', 1.2], ['FB102', 2], ['FB103', 1.5], ['ITM002', .8], ['FS026', 2], ['FB106', .1], ['FB105', 3], ['FB108', .5]];
        $cost = fn (string $c) => (float) Models\InventoryItem::whereKey($I[$c])->value('last_cost');
        for ($ago = 28; $ago >= 1; $ago--) {
            $day = $B->copy()->subDays($ago);
            $protein = $ago % 2 ? ['ITM004', 1.2] : ['FB115', 2.5];
            foreach (array_merge($canteen, [$protein]) as [$c, $q]) {
                Models\StockMovement::create(['inventory_item_id' => $I[$c], 'store_id' => $kitchen, 'date' => $day->toDateString(), 'type' => 'issue', 'qty' => -$q, 'rate' => $cost($c),
                    'department' => 'Staff canteen', 'department_id' => Models\Department::where('name', 'Kitchen')->value('id'), 'reference' => 'CANTEEN '.$day->format('Ymd'),
                    'remarks' => 'Staff meal ingredients', 'user_id' => $chef->id]);
            }
            $FC::saveMeal(['business_date' => $day->toDateString(), 'meal' => 'lunch', 'covers' => 22 + mt_rand(0, 5), 'cost_per_cover' => 25], $chef);
            $FC::saveMeal(['business_date' => $day->toDateString(), 'meal' => 'dinner', 'covers' => 12 + mt_rand(0, 4), 'cost_per_cover' => 25], $chef);
        }
        // bar count with shrinkage (bottles over-poured, half a crate of beer missing) – the kitchen store already has a draft count in progress
        $take = \App\Services\StockTakeService::create($barStore, $B->copy()->subDay()->toDateString(), null, 'Bar bottle count – F&B cost control');
        $short = ['FB131' => .6, 'FB132' => .9, 'FB133' => 12, 'FB134' => 1, 'FB121' => 4];
        $counts = [];
        $remarks = [];
        foreach ($take->lines()->with('item')->get() as $l) {
            $code = $l->item?->code;
            if (isset($short[$code])) {
                $counts[$l->id] = max(0, round((float) $l->system_qty - $short[$code], 2));
                $remarks[$l->id] = in_array($code, ['FB131', 'FB132', 'FB134'], true) ? 'Bottle weights below book – free pouring suspected' : ($code === 'FB133' ? 'Half a crate unaccounted for' : 'Count short');
            } elseif ((float) $l->system_qty > 0 && in_array($l->item?->item_category_id, [$groc, $bev, $liq], true)) {
                $counts[$l->id] = (float) $l->system_qty;
            }
        }
        \App\Services\StockTakeService::saveCounts($take, $counts, $remarks);
        \App\Services\StockTakeService::post($take);
        // plate-cost snapshots: 90 / 60 days ago and daily for the last 30 days (four dishes have crept up)
        $FC::flush();
        $creep = ['Mutton Rogan Josh' => .86, 'Johnnie Walker Black (60ml)' => .88, 'Fish Amritsari' => .9, 'Continental Breakfast' => .92];
        $rows = [];
        $now = now();
        foreach ($FC::costs() as $id => $c) {
            $mi = $items->firstWhere('id', $id);
            $f60 = $creep[$mi->name] ?? .98;
            $days = [90 => $f60 - .02, 60 => $f60] + array_fill_keys(range(30, 1), 1.0); // keep the day keys (array_merge would renumber them)
            foreach ($days as $ago => $f) {
                $price = match (true) { $mi->name === 'Kingfisher Premium' && $ago > 20 => 260, $mi->name === 'Masala Chai' && $ago > 12 => 50, default => (float) $mi->price };
                $rows[] = ['hotel_id' => $hid, 'menu_item_id' => $id, 'business_date' => $B->copy()->subDays($ago)->toDateString(), 'cost' => round($c * $f, 4), 'price' => $price, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            Models\FbCostSnapshot::insert($chunk);
        }
        $FC::flush();
        mt_srand();
    }

    protected function seedTables(bool $rich, Models\User $user): void
    {
        $T = \App\Services\TableBookingService::class;
        $rest = Models\Outlet::where('type', 'restaurant')->orderBy('id')->first();
        if (! $rest) {
            return;
        }
        // floor layout: Indoor (booths and 6-tops) on the left, Terrace (rounds) on the right
        $pos = ['T1' => [5, 12, 'booth'], 'T2' => [25, 12, 'square'], 'T3' => [5, 42, 'rect'], 'T4' => [25, 42, 'booth'], 'T5' => [5, 72, 'square'], 'T6' => [25, 72, 'rect'],
            'T7' => [58, 12, 'round'], 'T8' => [78, 12, 'round'], 'T9' => [58, 42, 'rect'], 'T10' => [78, 42, 'round'], 'T11' => [58, 72, 'round'], 'T12' => [78, 72, 'rect']];
        foreach (Models\PosTable::where('outlet_id', $rest->id)->get() as $t) {
            [$x, $y, $shape] = $pos[$t->table_no] ?? [null, null, 'square'];
            $t->update(['pos_x' => $x, 'pos_y' => $y, 'shape' => $shape, 'min_capacity' => $t->capacity >= 6 ? 3 : 1, 'reservable' => $t->table_no !== 'T12']);
        }
        $rest->update(['tb_enabled' => true, 'tb_online' => true, 'tb_settings' => array_merge($T::DEFAULTS, ['online_note' => 'Smart casual. We hold tables for 15 minutes – please call if you are running late.'])]);
        $rest->periods()->create(['name' => 'Lunch', 'start_time' => '12:00', 'end_time' => '14:30', 'days' => '1234567']);
        $rest->periods()->create(['name' => 'Dinner', 'start_time' => '19:00', 'end_time' => '22:30', 'days' => '1234567']);
        if (! $rich) {
            return;
        }
        $tables = Models\PosTable::where('outlet_id', $rest->id)->orderBy('id')->get()->keyBy('table_no');
        $names = ['Anjali Mehta', 'Karan Malhotra', 'Sneha Reddy', 'Arvind Pillai', 'Fatima Sheikh', 'David Miller', 'Neha Joshi', 'Rahul Bose', 'Emily Clarke', 'Vikram Singh', 'Pooja Nair',
            'Samuel Mensah', 'Divya Menon', 'Arjun Kapoor', 'Lakshmi Iyer', 'Tom Becker', 'Ritu Agarwal', 'Nikhil Rao', 'Meghna Das', 'Yusuf Khan', 'Kavya Shetty', 'Oliver Grant', 'Isha Gupta', 'Manoj Kumar'];
        $phone = fn ($name) => '+91 98'.str_pad((string) (abs(crc32($name)) % 100000000), 8, '0', STR_PAD_LEFT);
        $mk = function (array $a, array $tableNos) use ($rest, $tables) {
            $r = new Models\TableReservation(array_merge(['outlet_id' => $rest->id, 'booking_no' => Numbering::next('table_booking'), 'type' => 'booking', 'source' => 'phone'], $a));
            $r->save();
            $r->forceFill(['created_at' => $a['created_at'] ?? $r->start_at->copy()->subDays(2), 'token_hash' => null])->save();
            if ($r->type !== 'walkin') {
                $r->forceFill(['token_hash' => hash('sha256', \App\Services\TableBookingService::tokenFor($r))])->save();
            }
            $r->tables()->sync($tables->toBase()->only($tableNos)->pluck('id')->all());

            return $r;
        };
        // ---- history: 30 days of lunch & dinner; a per-day allocator keeps tables from overlapping
        mt_srand(2302);
        $regular = ['name' => 'Anjali Mehta', 'phone' => $phone('Anjali Mehta')];
        $flaky = ['name' => 'Rohit Verma', 'phone' => '+91 9876500011'];
        for ($d = 30; $d >= 1; $d--) {
            $day = now()->subDays($d)->startOfDay();
            $busy = [];
            $plan = [];
            foreach ([[12, 14], [19, 22]] as [$h1, $h2]) {
                for ($i = 0, $n = mt_rand($h1 === 12 ? 2 : 4, $h1 === 12 ? 4 : 8); $i < $n; $i++) {
                    $plan[] = $day->copy()->setTime(mt_rand($h1, $h2), [0, 15, 30, 45][mt_rand(0, 3)]);
                }
            }
            usort($plan, fn ($a, $b) => $a <=> $b);
            foreach ($plan as $k => $start) {
                $party = [2, 2, 2, 3, 4, 4, 4, 5, 6, 2, 8][mt_rand(0, 10)];
                $dur = $T::durationFor($rest, $party);
                $end = $start->copy()->addMinutes($dur);
                $pick = null;
                foreach ($tables->shuffle()->sortBy('capacity') as $t) {   // best fit, random among equals (shuffle re-indexes → use table_no)
                    $no = $t->table_no;
                    if ($t->reservable && $t->capacity >= $party && $t->min_capacity <= $party && collect($busy[$no] ?? [])->every(fn ($iv) => $end->lte($iv[0]) || $start->gte($iv[1]))) {
                        $pick = [$no];
                        break;
                    }
                }
                if (! $pick && $party > 6 && collect(['T9', 'T10'])->every(fn ($no) => collect($busy[$no] ?? [])->every(fn ($iv) => $end->lte($iv[0]) || $start->gte($iv[1])))) {
                    $pick = ['T9', 'T10'];
                }
                if (! $pick) {
                    continue;
                }
                $type = mt_rand(1, 100) <= 22 ? (mt_rand(0, 2) ? 'walkin' : 'waitlist') : 'booking';
                $roll = mt_rand(1, 100);
                $status = $type === 'booking' ? ($roll <= 7 ? 'no_show' : ($roll <= 16 ? 'cancelled' : 'completed')) : ($type === 'waitlist' && $roll <= 20 ? 'left' : 'completed');
                $who = ['name' => $names[mt_rand(0, count($names) - 1)]];
                if ($type === 'booking' && $d % 5 === 0 && $k === 0) {
                    $who = $regular;
                }
                $who['phone'] ??= $phone($who['name']);
                $late = $status === 'completed' && $type === 'booking' ? mt_rand(-5, 20) : 0;
                $seated = $status === 'completed' ? $start->copy()->addMinutes(max(0, $late) + ($type === 'waitlist' ? mt_rand(8, 35) : 0)) : null;
                $done = $seated ? $seated->copy()->addMinutes((int) round($dur * mt_rand(70, 115) / 100)) : null;
                $mk([
                    'type' => $type, 'status' => $status, 'name' => $type === 'walkin' && mt_rand(0, 1) ? 'Walk-in' : $who['name'], 'phone' => $type === 'walkin' ? null : $who['phone'],
                    'party_size' => $party, 'date' => $start->toDateString(), 'start_at' => $start, 'end_at' => $end, 'duration' => $dur,
                    'source' => $type === 'booking' ? ['phone', 'phone', 'online', 'online', 'concierge', 'email'][mt_rand(0, 5)] : 'walkin',
                    'occasion' => $type === 'booking' && mt_rand(1, 8) === 1 ? ['birthday', 'anniversary', 'business'][mt_rand(0, 2)] : null,
                    'quoted_minutes' => $type === 'waitlist' ? [10, 15, 20, 25][mt_rand(0, 3)] : null,
                    'confirmed_at' => $type === 'booking' ? $start->copy()->subDays(1) : null, 'seated_at' => $seated, 'completed_at' => $done,
                    'cancelled_at' => in_array($status, ['cancelled', 'left'], true) ? $start->copy()->subHours($status === 'left' ? 0 : mt_rand(2, 30)) : null,
                    'cancel_reason' => $status === 'cancelled' ? ['Change of plans', 'Guest called – unwell', 'Cancelled by guest online', 'Meeting moved'][mt_rand(0, 3)] : ($status === 'left' ? 'Left without being seated' : null),
                    'no_show_at' => $status === 'no_show' ? $start->copy()->addMinutes(15) : null,
                    'created_at' => $type === 'booking' ? $start->copy()->subHours(mt_rand(3, 96)) : $start,
                ], $status === 'completed' ? $pick : ($type === 'booking' ? $pick : []));
                if (in_array($status, ['completed'], true) || ($type === 'booking' && $status !== 'cancelled')) {
                    foreach ($pick as $no) {
                        $busy[$no][] = [$start->copy()->subMinutes(10), ($done ?? $end)->copy()->addMinutes(10)];
                    }
                }
            }
        }
        mt_srand();
        // a repeat no-show: two missed dinners (flagged when he books again)
        foreach ([21, 9] as $d) {
            $start = now()->subDays($d)->setTime(19, 30);
            $mk(['status' => 'no_show', 'name' => $flaky['name'], 'phone' => $flaky['phone'], 'party_size' => 2, 'date' => $start->toDateString(), 'start_at' => $start,
                'end_at' => $start->copy()->addMinutes(90), 'duration' => 90, 'confirmed_at' => $start->copy()->subDay(), 'no_show_at' => $start->copy()->addMinutes(15)], ['T12']);
        }
        // link the seeded settled dine-in bills to completed parties of the same day (table, covers, times follow the booking)
        foreach (Models\PosOrder::where('outlet_id', $rest->id)->where('order_type', 'dine_in')->where('status', 'settled')->orderBy('id')->get() as $po) {
            $r = Models\TableReservation::with('tables')->where('outlet_id', $rest->id)->where('status', 'completed')->whereNull('pos_order_id')
                ->whereDate('date', $po->business_date->toDateString())->has('tables')->orderBy('start_at')->first();
            if ($r) {
                $r->forceFill(['pos_order_id' => $po->id])->save();
                $po->forceFill(['pos_table_id' => $r->tables->first()->id, 'covers' => $r->party_size, 'customer_name' => $po->customer_name ?: ($r->name === 'Walk-in' ? null : $r->name),
                    'created_at' => $r->seated_at, 'settled_at' => $r->completed_at])->saveQuietly();
            }
        }

        // ---- today, relative to the clock so the floor plan is always "live"
        $now = now()->startOfMinute();
        $link = function (string $no, array $a) use ($tables, $mk, $now) {
            $order = Models\PosOrder::where('pos_table_id', $tables[$no]->id)->whereIn('status', ['open', 'billed'])->first();
            $seatedAt = $a['seated_at'];
            $r = $mk($a + ['status' => 'seated', 'date' => $now->toDateString(), 'pos_order_id' => $order?->id, 'created_at' => $a['created_at'] ?? $seatedAt->copy()->subHours(5)], [$no]);
            if ($order) {
                $order->forceFill(['created_at' => $seatedAt, 'customer_name' => $order->customer_name ?: $a['name'], 'covers' => $a['party_size']])->saveQuietly();
            }

            return $r;
        };
        $dur = fn ($p) => $T::durationFor($rest, $p);
        $link('T1', ['name' => $regular['name'], 'phone' => $regular['phone'], 'party_size' => 3, 'start_at' => $now->copy()->subMinutes(55), 'seated_at' => $now->copy()->subMinutes(50),
            'end_at' => $now->copy()->subMinutes(50)->addMinutes($dur(3)), 'duration' => $dur(3), 'occasion' => 'business', 'confirmed_at' => $now->copy()->subDay(), 'notes' => 'Regular – prefers the corner booth.']);
        $link('T2', ['type' => 'walkin', 'source' => 'walkin', 'name' => 'Walk-in', 'party_size' => 2, 'start_at' => $now->copy()->subMinutes(25), 'seated_at' => $now->copy()->subMinutes(25),
            'end_at' => $now->copy()->subMinutes(25)->addMinutes($dur(2)), 'duration' => $dur(2)]);
        // a large party on joined tables T9 + T10, seated through the service (opens its own POS order)
        $rao = $mk(['name' => 'Srinivas Rao', 'phone' => $phone('Srinivas Rao'), 'party_size' => 8, 'status' => 'confirmed', 'date' => $now->toDateString(), 'start_at' => $now->copy()->subMinutes(35),
            'end_at' => $now->copy()->subMinutes(35)->addMinutes($dur(8)), 'duration' => $dur(8), 'occasion' => 'birthday', 'tags' => ['high_chair'], 'notes' => 'Grandfather\'s 80th – cake at 9 pm, candles please.',
            'confirmed_at' => $now->copy()->subDays(3), 'created_at' => $now->copy()->subDays(4)], ['T9', 'T10']);
        $order = $T::seat($rao);
        $rao->forceFill(['seated_at' => $now->copy()->subMinutes(30), 'end_at' => $now->copy()->subMinutes(30)->addMinutes($dur(8))])->save();
        $order->forceFill(['created_at' => $now->copy()->subMinutes(30), 'steward' => 'Sunil'])->saveQuietly();
        $kot = Numbering::next('kot');
        foreach (Models\MenuItem::where('is_available', true)->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $rest->id))->orderBy('id')->limit(5)->get() as $it) {
            $order->items()->create(['menu_item_id' => $it->id, 'item_name' => $it->name, 'qty' => 2, 'rate' => $it->price, 'tax_rate' => $it->tax_rate, 'amount' => 2 * $it->price, 'kot_no' => $kot])->forceFill(['kitchen_status' => 'served'])->save();
        }
        $order->recalculate();
        // T6 just finished (bill settled) – waiting to be cleared; T11 out of service
        $mk(['name' => 'Neha Joshi', 'phone' => $phone('Neha Joshi'), 'party_size' => 5, 'status' => 'completed', 'date' => $now->toDateString(), 'start_at' => $now->copy()->subMinutes(110),
            'end_at' => $now->copy()->subMinutes(110)->addMinutes($dur(5)), 'duration' => $dur(5), 'seated_at' => $now->copy()->subMinutes(105), 'completed_at' => $now->copy()->subMinutes(6),
            'confirmed_at' => $now->copy()->subDay()], ['T6']);
        $tables['T6']->update(['needs_clearing' => true]);
        $tables['T11']->update(['blocked_reason' => 'Umbrella broken – repair booked']);
        // late & arriving
        $mk(['name' => 'Arjun Kapoor', 'phone' => $phone('Arjun Kapoor'), 'party_size' => 2, 'status' => 'confirmed', 'date' => $now->toDateString(), 'start_at' => $now->copy()->subMinutes(10),
            'end_at' => $now->copy()->subMinutes(10)->addMinutes($dur(2)), 'duration' => $dur(2), 'source' => 'online', 'confirmed_at' => $now->copy()->subHours(6), 'created_at' => $now->copy()->subHours(6)], ['T8']);
        auth()->setUser($user);
        $book = fn (array $a, string $src = 'phone') => $T::book($rest, $a, $src);
        $at = fn (int $min) => $now->copy()->addMinutes($min);
        $sharma = $book(['date' => $at(25)->toDateString(), 'time' => $at(25)->format('H:i'), 'party_size' => 4, 'name' => 'Priyanka Sharma', 'phone' => $phone('Priyanka Sharma'), 'email' => 'priyanka.sharma@mail.example',
            'occasion' => 'birthday', 'tags' => ['high_chair'], 'notes' => 'Daughter turns 5 – small candle on dessert.', 'table_ids' => [$tables['T4']->id]]);
        $book(['date' => $at(40)->toDateString(), 'time' => $at(40)->format('H:i'), 'party_size' => 2, 'name' => $flaky['name'], 'phone' => $flaky['phone'], 'table_ids' => [$tables['T7']->id]]);
        $book(['date' => $at(80)->toDateString(), 'time' => $at(80)->format('H:i'), 'party_size' => 6, 'name' => 'Clara Fernandes', 'phone' => $phone('Clara Fernandes'), 'occasion' => 'anniversary',
            'tags' => ['window', 'quiet'], 'table_ids' => [$tables['T3']->id]]);
        $inHouse = Models\ReservationRoom::with('reservation.guest', 'room')->where('status', 'checked_in')->orderBy('id')->first();
        if ($inHouse) {
            $book(['date' => $at(105)->toDateString(), 'time' => $at(105)->format('H:i'), 'party_size' => 2, 'reservation_room_id' => $inHouse->id, 'notes' => 'In-house guest – charge to room.'], 'concierge');
        }
        $book(['date' => $at(130)->toDateString(), 'time' => $at(130)->format('H:i'), 'party_size' => 2, 'name' => 'Emily Clarke', 'phone' => $phone('Emily Clarke'), 'email' => 'emily.clarke@mail.example'], 'staff')
            ->forceFill(['status' => 'booked', 'confirmed_at' => null, 'source' => 'online'])->save();
        // waitlist
        foreach ([['Kofi Boateng', 4, 12, 15, null, 'Happy to sit on the terrace'], ['Lisa Chen', 2, 4, 10, '+91 9811122233', null]] as [$n, $p, $ago, $q, $ph, $note]) {
            $w = Models\TableReservation::create(['outlet_id' => $rest->id, 'booking_no' => Numbering::next('table_booking'), 'type' => 'waitlist', 'status' => 'waiting', 'name' => $n, 'phone' => $ph,
                'party_size' => $p, 'date' => $now->toDateString(), 'start_at' => $now->copy()->subMinutes($ago), 'end_at' => $now->copy()->subMinutes($ago)->addMinutes($dur($p)), 'duration' => $dur($p),
                'source' => 'walkin', 'quoted_minutes' => $q, 'notes' => $note, 'created_by' => $user->id]);
            $w->forceFill(['created_at' => $now->copy()->subMinutes($ago)])->save();
        }
        // ---- the coming week
        mt_srand(2303);
        for ($d = 1; $d <= 7; $d++) {
            $day = now()->addDays($d)->startOfDay();
            for ($i = 0, $n = mt_rand(3, 7); $i < $n; $i++) {
                $start = $day->copy()->setTime([12, 13, 19, 20, 20, 21][mt_rand(0, 5)], [0, 15, 30, 45][mt_rand(0, 3)]);
                $nm = $names[mt_rand(0, count($names) - 1)];
                try {
                    $book(['date' => $start->toDateString(), 'time' => $start->format('H:i'), 'party_size' => [2, 2, 3, 4, 4, 6][mt_rand(0, 5)], 'name' => $nm, 'phone' => $phone($nm),
                        'occasion' => mt_rand(1, 6) === 1 ? 'anniversary' : null, 'notes' => mt_rand(1, 7) === 1 ? 'Nut allergy (one guest).' : null], ['phone', 'online', 'online', 'email'][mt_rand(0, 3)]);
                } catch (\Illuminate\Validation\ValidationException) {
                    // slot full – skip
                }
            }
        }
        mt_srand();
        $tom = Models\TableReservation::where('outlet_id', $rest->id)->whereDate('date', now()->addDay()->toDateString())->where('source', 'online')->orderBy('start_at')->first();
        $tom?->forceFill(['status' => 'booked', 'confirmed_at' => null])->save();
        auth()->forgetUser();
    }

    protected function seedProcurement(Carbon $today): void
    {
        $S = \App\Services\ProcurementService::class;
        $hid = \App\Support\Tenant::id();
        $u = fn ($n) => \App\Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        [$admin, $store, $acct] = [$u('admin'), $u('store'), $u('accounts')];
        if (! $admin || ! $store || \App\Models\Rfq::exists()) {
            return;
        }
        $prevUser = auth()->user();
        $as = fn ($user) => auth()->setUser($user);
        $D = fn (int $n) => $today->copy()->subDays($n)->toDateString();
        $F = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $sup = fn ($n) => \App\Models\Supplier::where('name', $n)->value('id');
        $item = fn ($n) => \App\Models\InventoryItem::where('name', $n)->value('id');
        $storeId = fn ($n) => \App\Models\Store::where('name', $n)->value('id');
        foreach ([['Accra Wholesale Provisions', 'Kwame Asante', '0302 771 204', 'orders@accrawholesale.example', 'Spintex Road, Accra', 'goods'],
            ['Tema Hygiene Distributors', 'Efua Mensah', '0303 204 118', 'sales@temahygiene.example', 'Community 9, Tema', 'goods']] as [$n, $c, $ph, $em, $ad, $w]) {
            if (! $sup($n)) {
                \App\Models\Supplier::create(['name' => $n, 'contact_person' => $c, 'phone' => $ph, 'email' => $em, 'address' => $ad, 'payment_terms_days' => 30, 'wht_category' => $w, 'is_active' => true]);
            }
        }
        [$metro, $fresh, $clean, $tech, $awp, $thd] = [$sup('Metro Cash & Carry'), $sup('FreshFarm Foods'), $sup('CleanPro Supplies'), $sup('TechWorld IT Solutions'), $sup('Accra Wholesale Provisions'), $sup('Tema Hygiene Distributors')];
        if (! $metro || ! $fresh || ! $clean || ! $item('Basmati Rice')) {
            auth()->setUser($prevUser ?? new \App\Models\User);

            return;
        }
        $as($store);
        // ── price lists (one expired, one expiring soon)
        $price = fn ($s, $i, $r, $from, $to = null, $lead = null) => $S::savePrice(['supplier_id' => $s, 'inventory_item_id' => $item($i), 'rate' => $r, 'min_qty' => 0, 'lead_days' => $lead, 'valid_from' => $from, 'valid_to' => $to, 'notes' => 'Rate contract 2026']);
        $price($metro, 'Basmati Rice', 98, $D(200), $D(61));
        $price($metro, 'Basmati Rice', 92, $D(60), null, 2);
        $price($metro, 'Refined Oil', 155, $D(60), null, 2);
        $price($metro, 'Mineral Water 1L', 13.5, $D(60), null, 1);
        $price($metro, 'Soft Drinks 300ml', 24, $D(60), null, 1);
        $price($fresh, 'Chicken', 240, $D(45), null, 1);
        $price($fresh, 'Paneer', 370, $D(45), null, 1);
        $price($clean, 'Floor Cleaner 5L', 470, $D(90), null, 3);
        $price($clean, 'Glass Cleaner 500ml', 135, $D(90), null, 3);
        $price($clean, 'Toilet Cleaner 1L', 105, $D(90), null, 3);
        $price($clean, 'Garbage Bags (roll of 30)', 88, $D(90), null, 3);
        $price($awp, 'Basmati Rice', 90, $D(60), $F(10), 3);
        $price($awp, 'Refined Oil', 158, $D(60), $F(10), 3);
        $price($thd, 'Floor Cleaner 5L', 455, $D(30), null, 5);
        if ($tech) {
            $price($tech, 'Printer Toner HP 88A', 1790, $D(120), null, 4);
        }
        $backdate = function ($rfq, int $ago, int $dueAgo) use ($D) {
            $rfq->forceFill(['rfq_date' => $D($ago), 'respond_by' => $D($dueAgo), 'sent_at' => $rfq->sent_at ? \Carbon\Carbon::parse($D($ago))->setTime(10, 0) : null,
                'created_at' => \Carbon\Carbon::parse($D($ago))->setTime(9, 30)])->save();
            \App\Models\RfqSupplier::where('rfq_id', $rfq->id)->whereNotNull('invited_at')->update(['invited_at' => \Carbon\Carbon::parse($D($ago))->setTime(10, 0)]);
        };
        $quote = fn ($rfq, $sid, array $rates, array $x = [], string $src = 'manual') => $S::submitQuote(\App\Models\RfqSupplier::where('rfq_id', $rfq->id)->where('supplier_id', $sid)->first(),
            $x + ['lines' => collect($rates)->mapWithKeys(fn ($v, $name) => [$rfq->lines->firstWhere('inventory_item_id', $item($name))->id => is_array($v) ? $v : ['rate' => $v, 'tax_rate' => 20]])->all()], $src);

        // ── RFQ 1: housekeeping chemicals – awarded (split), both POs delivered
        $r1 = $S::saveRfq(['title' => 'Housekeeping chemicals – Q3 replenishment', 'store_id' => $storeId('Housekeeping Store'), 'respond_by' => $F(1), 'needed_by' => $F(5),
            'terms' => 'Prices in GH₵ delivered to the hotel. Quote VAT / NHIL / GETFund as the tax %. Payment 30 days after invoice.', 'supplier_ids' => [$clean, $thd, $metro],
            'lines' => [['item_id' => $item('Floor Cleaner 5L'), 'qty' => 20], ['item_id' => $item('Glass Cleaner 500ml'), 'qty' => 30], ['item_id' => $item('Toilet Cleaner 1L'), 'qty' => 40], ['item_id' => $item('Garbage Bags (roll of 30)'), 'qty' => 50]]]);
        $S::send($r1);
        $r1->load('lines');
        $quote($r1, $clean, ['Floor Cleaner 5L' => 468, 'Glass Cleaner 500ml' => 132, 'Toilet Cleaner 1L' => 104, 'Garbage Bags (roll of 30)' => 86], ['quote_ref' => 'CP/Q/2231', 'valid_until' => $F(20), 'delivery_days' => 3, 'payment_terms_days' => 30]);
        $quote($r1, $thd, ['Floor Cleaner 5L' => 449, 'Glass Cleaner 500ml' => 139, 'Toilet Cleaner 1L' => ['rate' => 99, 'tax_rate' => 20, 'qty_offered' => 20, 'remarks' => 'Only 20 in stock'], 'Garbage Bags (roll of 30)' => 91],
            ['quote_ref' => 'THD-0917', 'valid_until' => $F(25), 'delivery_days' => 5, 'payment_terms_days' => 45], 'portal');
        $S::decline(\App\Models\RfqSupplier::where('rfq_id', $r1->id)->where('supplier_id', $metro)->first(), 'We do not stock these brands');
        $as($admin);
        $cmp = $S::comparative($r1);
        $award = [];
        foreach ($cmp['rows'] as $row) {
            $award[$row['line']->id] = $row['lowest'];
        }
        $pos = $S::award($r1, $award, [], 'L1 on every line; Tema Hygiene part-quoted toilet cleaner so CleanPro takes the full quantity.', false);
        $backdate($r1, 24, 21);
        $r1->forceFill(['awarded_at' => \Carbon\Carbon::parse($D(20))->setTime(15, 0), 'closed_at' => \Carbon\Carbon::parse($D(20))->setTime(15, 0)])->save();
        \App\Models\RfqQuote::where('rfq_id', $r1->id)->update(['quote_date' => $D(22)]);
        foreach ($pos as $po) {
            $po->forceFill(['po_date' => $D(20), 'expected_date' => $D(15)])->save();
            $S::approve($po->fresh());
            $po->forceFill(['approved_at' => \Carbon\Carbon::parse($D(20))->setTime(16, 0)])->save();
        }
        $as($store);
        foreach ($pos as $po) {
            $po = $po->fresh('items');
            $lines = $po->items->mapWithKeys(fn ($l) => [$l->id => ['delivered' => (float) $l->qty, 'rejected' => 0]])->all();
            if ($po->supplier_id === $thd) { // first delivery: 2 leaking cans rejected, balance next week
                $first = $po->items->mapWithKeys(fn ($l) => [$l->id => ['delivered' => (float) $l->qty, 'rejected' => 2, 'reason' => 'Cans leaking / seal broken']])->all();
                $S::receive($po, ['date' => $D(16), 'delivery_note' => 'THD-DN-5521', 'lines' => $first, 'vehicle_no' => 'GT 4471-21']);
                $S::receive($po->fresh(), ['date' => $D(12), 'delivery_note' => 'THD-DN-5580', 'invoice_no' => 'THD-INV-3390', 'lines' => $po->items->mapWithKeys(fn ($l) => [$l->id => ['delivered' => 2]])->all()]);
            } else {
                $S::receive($po, ['date' => $D(17), 'delivery_note' => 'CP-DN-7710', 'invoice_no' => 'CP-INV-9921', 'lines' => $lines]);
            }
        }

        // ── RFQ 2: kitchen dry stores – out for quotation (deadline in 2 days), one online quote, one keyed-in, one only viewed
        $r2 = $S::saveRfq(['title' => 'Kitchen dry stores & beverages – October', 'store_id' => $storeId('Kitchen Store'), 'respond_by' => $F(2), 'needed_by' => $F(6),
            'supplier_ids' => [$metro, $awp, $fresh], 'lines' => [['item_id' => $item('Basmati Rice'), 'qty' => 200, 'specification' => '25 kg bags, long grain'], ['item_id' => $item('Refined Oil'), 'qty' => 80],
                ['item_id' => $item('Mineral Water 1L'), 'qty' => 480, 'specification' => 'FDA-registered brand'], ['item_id' => $item('Soft Drinks 300ml'), 'qty' => 240]]]);
        $S::send($r2);
        $r2->load('lines');
        $quote($r2, $awp, ['Basmati Rice' => 89, 'Refined Oil' => 157, 'Mineral Water 1L' => 13.2, 'Soft Drinks 300ml' => 24.5], ['quote_ref' => 'AWP-Q-118', 'valid_until' => $F(21), 'delivery_days' => 3, 'payment_terms_days' => 30], 'portal');
        $quote($r2, $metro, ['Basmati Rice' => 92, 'Refined Oil' => 152, 'Mineral Water 1L' => 13.5, 'Soft Drinks 300ml' => 23.8], ['quote_ref' => 'MCC/2609/44', 'valid_until' => $F(14), 'delivery_days' => 2, 'payment_terms_days' => 21]);
        \App\Models\RfqSupplier::where('rfq_id', $r2->id)->where('supplier_id', $fresh)->update(['viewed_at' => now()->subHours(20)]);
        $backdate($r2, 2, -2);

        // ── RFQ 3: front office stationery – closed, ready to award (3 quotes)
        if ($tech) {
            $r3 = $S::saveRfq(['title' => 'Front office stationery & toner', 'store_id' => $storeId('Main Store'), 'respond_by' => $F(1), 'supplier_ids' => [$metro, $tech, $awp],
                'lines' => [['item_id' => $item('A4 Paper Ream'), 'qty' => 40, 'specification' => '80 gsm'], ['item_id' => $item('Printer Toner HP 88A'), 'qty' => 4, 'specification' => 'Genuine HP only'], ['item_id' => $item('Ball Pens (box of 10)'), 'qty' => 20]]]);
            $S::send($r3);
            $r3->load('lines');
            $quote($r3, $metro, ['A4 Paper Ream' => 272, 'Printer Toner HP 88A' => 1880, 'Ball Pens (box of 10)' => 58], ['valid_until' => $F(20), 'delivery_days' => 2, 'payment_terms_days' => 21]);
            $quote($r3, $tech, ['A4 Paper Ream' => 285, 'Printer Toner HP 88A' => ['rate' => 1760, 'tax_rate' => 20, 'remarks' => 'Genuine HP, 12-month warranty'], 'Ball Pens (box of 10)' => 62], ['quote_ref' => 'TW-4410', 'valid_until' => $F(30), 'delivery_days' => 4, 'payment_terms_days' => 30]);
            $quote($r3, $awp, ['A4 Paper Ream' => 268, 'Ball Pens (box of 10)' => 55], ['valid_until' => $F(15), 'delivery_days' => 3, 'payment_terms_days' => 30], 'portal');
            $S::close($r3);
            $backdate($r3, 6, 1);
            \App\Models\RfqQuote::where('rfq_id', $r3->id)->update(['quote_date' => $D(3)]);
        }

        // ── RFQ 4: engineering consumables – still a draft
        $S::saveRfq(['title' => 'Engineering consumables – lamps & batteries', 'store_id' => $storeId('Main Store'), 'respond_by' => $F(5), 'supplier_ids' => array_values(array_filter([$metro, $tech])),
            'lines' => [['item_id' => $item('LED Bulb 9W'), 'qty' => 60], ['item_id' => $item('Tube Light 20W LED'), 'qty' => 24], ['item_id' => $item('AA Batteries (pack of 4)'), 'qty' => 30]], 'notes' => 'Waiting for the engineer to confirm quantities']);

        // ── POs at each approval level + a part-received overdue PO
        $mk = function ($sid, $storeName, array $lines, int $ago, ?int $expAgo, string $notes) use ($item, $storeId, $D) {
            $po = \App\Models\PurchaseOrder::create(['po_no' => \App\Support\Numbering::next('po'), 'supplier_id' => $sid, 'store_id' => $storeId($storeName), 'po_date' => $D($ago),
                'expected_date' => $expAgo === null ? null : $D($expAgo), 'status' => 'draft', 'notes' => $notes, 'created_by' => auth()->id()]);
            $sub = 0;
            $tax = 0;
            foreach ($lines as [$name, $qty, $rate]) {
                $amt = round($qty * $rate, 2);
                $po->items()->create(['inventory_item_id' => $item($name), 'qty' => $qty, 'rate' => $rate, 'tax_rate' => 20, 'amount' => $amt]);
                $sub += $amt;
                $tax += $amt * 0.2;
            }
            $po->update(['subtotal' => $sub, 'tax' => round($tax, 2), 'total' => round($sub + $tax, 2)]);
            \App\Services\ProcurementService::stampListRates($po);

            return $po->fresh('items');
        };
        $as($store);
        $mk($metro, 'Kitchen Store', [['Basmati Rice', 25, 99], ['Refined Oil', 10, 155]], 1, -4, 'Urgent top-up – rice priced above the contract rate (supplier says new crop)');
        $mk($clean, 'Housekeeping Store', [['Bed Sheet (King)', 45, 650]], 0, -10, 'Linen replacement for 3rd floor refurbishment');
        $late = $mk($fresh, 'Kitchen Store', [['Chicken', 50, 240], ['Paneer', 10, 370]], 7, 5, 'Weekly protein order');
        $as($admin);
        $S::approve($late);
        $late->forceFill(['approved_at' => \Carbon\Carbon::parse($D(7))->setTime(11, 0)])->save();
        $as($store);
        $S::receive($late->fresh(), ['date' => $D(4), 'delivery_note' => 'FF-22817', 'lines' => $late->items->mapWithKeys(fn ($l) => [$l->id => $l->item?->name === 'Chicken' || $l->inventory_item_id === $item('Chicken') ? ['delivered' => 32, 'rejected' => 2, 'reason' => 'Temperature above 5°C on arrival'] : ['delivered' => 0]])->all()]);

        auth()->setUser($prevUser ?? new \App\Models\User);

        \App\Services\LedgerService::sync(true); // back-dated GRNs sit outside the incremental window
    }

    protected function seedRequisitions(Models\Hotel $hotel, bool $rich, Carbon $today, Models\User $user): void
    {
        \App\Services\RequisitionService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $U = Models\User::where('hotel_id', $hotel->id)->get()->keyBy('username');
        $D = Models\Department::pluck('id', 'name');
        $S = Models\Store::pluck('id', 'type');
        $misc = Models\AccountHead::where('code', '6110')->first();
        $stationery = Models\AccountHead::firstOrCreate(['code' => '6140'], ['name' => 'Printing & Stationery', 'type' => 'expense', 'group_name' => $misc?->group_name, 'is_active' => true]);
        foreach ([
            'Front Office' => [$U['fom']->id, $stationery->id, null, 8000], 'Housekeeping' => [$U['housekeeping']->id, null, null, 25000],
            'Kitchen' => [$user->id, null, $S['kitchen'], 60000], 'F&B Service' => [$user->id, null, $S['bar'], 15000],
            'Maintenance' => [$U['engineer']->id, null, null, 12000], 'Accounts' => [null, $stationery->id, null, null], 'Management' => [null, $stationery->id, null, null],
        ] as $name => [$hod, $head, $store, $budget]) {
            $d = Models\Department::find($D[$name]);
            $d->forceFill(['hod_user_id' => $hod, 'expense_head_id' => $head ?? $d->expense_head_id, 'default_store_id' => $store ?? $d->default_store_id, 'monthly_budget' => $budget])->save();
        }
        Models\Setting::updateOrCreate(['key' => 'req_auto_approve_below'], ['value' => '500']);

        // main store: new stationery / engineering / F&B-service items + opening stock taken on 1 Sep
        $cats = collect(['Printing & Stationery', 'Engineering Spares', 'F&B Service Supplies', 'Cleaning Chemicals'])->mapWithKeys(fn ($c) => [$c => Models\ItemCategory::firstOrCreate(['name' => $c])->id]);
        $code = Models\InventoryItem::count();
        foreach ([['Printing & Stationery', 'A4 Paper Ream', 'ream', 10, 280], ['Printing & Stationery', 'Printer Toner HP 88A', 'pcs', 2, 1850], ['Printing & Stationery', 'Ball Pens (box of 10)', 'box', 5, 60],
            ['Printing & Stationery', 'Registration Card Pad', 'pad', 4, 250], ['Engineering Spares', 'LED Bulb 9W', 'pcs', 20, 95], ['Engineering Spares', 'Tube Light 20W LED', 'pcs', 10, 180],
            ['Engineering Spares', 'AA Batteries (pack of 4)', 'pack', 10, 120], ['Engineering Spares', 'PVC Insulation Tape', 'roll', 10, 25], ['Engineering Spares', 'AC Filter Pad', 'pcs', 6, 450],
            ['F&B Service Supplies', 'Paper Napkins (pack of 100)', 'pack', 10, 85], ['Cleaning Chemicals', 'Glass Cleaner 500ml', 'btl', 6, 140], ['Cleaning Chemicals', 'Toilet Cleaner 1L', 'btl', 10, 110],
            ['Cleaning Chemicals', 'Garbage Bags (roll of 30)', 'roll', 10, 90]] as [$c, $n, $u, $ro, $cost]) {
            Models\InventoryItem::create(['item_category_id' => $cats[$c], 'code' => 'ITM'.str_pad((string) ++$code, 3, '0', STR_PAD_LEFT), 'name' => $n, 'unit' => $u, 'reorder_level' => $ro, 'last_cost' => $cost, 'tax_rate' => 20]);
        }
        $I = Models\InventoryItem::get()->keyBy('name');
        $open = $today->copy()->subDays(25);
        foreach (['Basmati Rice' => 120, 'Refined Oil' => 60, 'Paneer' => 12, 'Chicken' => 8, 'Mineral Water 1L' => 300, 'Soft Drinks 300ml' => 200, 'Shampoo Sachet' => 600, 'Soap 30g' => 600,
            'Dental Kit' => 300, 'Floor Cleaner 5L' => 2, 'A4 Paper Ream' => 30, 'Printer Toner HP 88A' => 4, 'Ball Pens (box of 10)' => 20, 'Registration Card Pad' => 10, 'LED Bulb 9W' => 80,
            'Tube Light 20W LED' => 25, 'AA Batteries (pack of 4)' => 30, 'PVC Insulation Tape' => 40, 'AC Filter Pad' => 4, 'Paper Napkins (pack of 100)' => 40, 'Glass Cleaner 500ml' => 20,
            'Toilet Cleaner 1L' => 30, 'Garbage Bags (roll of 30)' => 30] as $n => $q) {
            Models\StockMovement::create(['inventory_item_id' => $I[$n]->id, 'store_id' => $S['main'], 'date' => $open, 'type' => 'opening', 'qty' => $q, 'rate' => $I[$n]->last_cost, 'reference' => 'Opening', 'remarks' => 'Main store opening count', 'user_id' => $user->id]);
        }
        foreach (['kitchen' => ['Basmati Rice' => 60, 'Refined Oil' => 40, 'Paneer' => 10, 'Chicken' => 20], 'bar' => ['Mineral Water 1L' => 120, 'Soft Drinks 300ml' => 96, 'Paper Napkins (pack of 100)' => 12]] as $st => $pars) {
            foreach ($pars as $n => $par) {
                Models\StorePar::create(['store_id' => $S[$st], 'inventory_item_id' => $I[$n]->id, 'par_qty' => $par]);
            }
        }

        $RS = \App\Services\RequisitionService::class;
        $at = fn (int $daysAgo, string $time) => $today->copy()->subDays($daysAgo)->setTimeFromTimeString($time);
        $ids = fn ($r, array $byName) => collect($byName)->mapWithKeys(fn ($q, $n) => [$r->lines->first(fn ($l) => $l->item->name === $n)->id => $q])->all();
        $mk = function (string $who, string $dept, int $daysAgo, array $lines, array $o = []) use ($RS, $U, $D, $S, $I, $at) {
            Auth::setUser($U[$who] ?? $U['admin']);
            $d = Models\Department::find($D[$dept]);
            $r = $RS::saveDraft(['department_id' => $d->id, 'from_store_id' => $S['main'], 'to_store_id' => $d->default_store_id, 'priority' => $o['priority'] ?? 'normal',
                'required_by' => null, 'purpose' => $o['purpose'] ?? null, 'lines' => collect($lines)->map(fn ($q, $n) => ['item_id' => $I[$n]->id, 'qty' => $q])->values()->all()]);
            $r->forceFill(['req_date' => $at($daysAgo, '08:00')->toDateString(), 'created_at' => $at($daysAgo, $o['time'] ?? '08:30'),
                'required_by' => isset($o['need']) ? $at($daysAgo - $o['need'], '00:00')->toDateString() : null])->save();
            if (! ($o['draft'] ?? false)) {
                $RS::submit($r);
                $r->forceFill(['submitted_at' => $at($daysAgo, $o['time'] ?? '08:30')->addMinutes(5)])->save();
            }

            return $r->fresh(['lines.item', 'department']);
        };
        $approve = function ($r, string $who, $when, array $qty = [], ?string $note = null, bool $over = false) use ($RS, $U, $ids) {
            Auth::setUser($U[$who]);
            $r = $RS::approve($r->fresh(['lines.item', 'department']), $ids($r, $qty), $note, $over);
            $r->forceFill(['approved_at' => $when])->save();

            return $r->fresh(['lines.item', 'department']);
        };
        $issue = function ($r, $when, array $qty = [], ?string $notes = null) use ($RS, $U, $ids) {
            Auth::setUser($U['store']);
            $r = $r->fresh(['lines.item']);
            $q = $qty ? $ids($r, $qty) : $r->lines->mapWithKeys(fn ($l) => [$l->id => $l->outstanding()])->all();
            $i = $RS::issue($r, $q, $when->toDateString(), $notes);
            $i->forceFill(['created_at' => $when])->save();

            return $i;
        };
        $ack = function ($i, string $who, $when, ?string $note = null) use ($RS, $U) {
            Auth::setUser($U[$who]);
            $RS::acknowledge($i, $note);
            $i->forceFill(['received_at' => $when])->save();
        };

        // 1. kitchen weekly dry goods → kitchen store (transfer), issued in full
        $r = $mk('chef', 'Kitchen', 23, ['Basmati Rice' => 20, 'Refined Oil' => 10, 'Paneer' => 5], ['purpose' => 'Weekly dry-store top-up']);
        $r = $approve($r, 'admin', $at(23, '09:10'));
        $ack($issue($r, $at(23, '10:05')), 'chef', $at(23, '10:30'));
        // 2. housekeeping guest amenities & chemicals, soap cut by the HOD, issued (expensed to guest supplies)
        $r = $mk('laundry', 'Housekeeping', 21, ['Shampoo Sachet' => 150, 'Soap 30g' => 150, 'Dental Kit' => 80, 'Glass Cleaner 500ml' => 6, 'Toilet Cleaner 1L' => 10, 'Garbage Bags (roll of 30)' => 12], ['purpose' => 'Amenities & chemicals for the week', 'time' => '16:00']);
        $r = $approve($r, 'housekeeping', $at(21, '17:20'), ['Soap 30g' => 120], 'Soap par on floors is 120 – rest next week');
        $ack($issue($r, $at(20, '09:30')), 'laundry', $at(20, '11:00'));
        // 3. kitchen paneer – store short, part issued and closed short
        $r = $mk('chef', 'Kitchen', 18, ['Paneer' => 10, 'Refined Oil' => 5], ['purpose' => 'Banquet – Mensah–Owusu wedding prep']);
        $r = $approve($r, 'admin', $at(18, '09:00'));
        $ack($issue($r, $at(18, '11:40'), ['Paneer' => 6, 'Refined Oil' => 5], 'Only 6 kg paneer in store'), 'chef', $at(18, '12:15'));
        Auth::setUser($U['store']);
        $RS::closeShort($r->fresh(), 'Paneer balance bought fresh from the market by the chef');
        $r->forceFill(['closed_at' => $at(17, '10:00')])->save();
        // 4. maintenance spares – AC filter pads short (part issued, still open)
        $r = $mk('engineer', 'Maintenance', 16, ['LED Bulb 9W' => 30, 'Tube Light 20W LED' => 10, 'AA Batteries (pack of 4)' => 12, 'AC Filter Pad' => 12], ['purpose' => 'Monthly electrical spares + AC filter replacement (PM)']);
        $r = $approve($r, 'accounts', $at(16, '12:00'));
        $ack($issue($r, $at(15, '10:15'), ['LED Bulb 9W' => 30, 'Tube Light 20W LED' => 10, 'AA Batteries (pack of 4)' => 12, 'AC Filter Pad' => 4]), 'engineer', $at(15, '10:40'), 'AC filter pads – 8 still to come');
        // 5. front office stationery – issued, not yet acknowledged
        $r = $mk('fom', 'Front Office', 11, ['A4 Paper Ream' => 10, 'Printer Toner HP 88A' => 2, 'Ball Pens (box of 10)' => 5, 'Registration Card Pad' => 4], ['purpose' => 'Monthly stationery']);
        $r = $approve($r, 'admin', $at(11, '11:00'));
        $issue($r, $at(11, '15:30'), [], 'Collected by Priya');
        // 6. bar par top-up → bar store (transfer)
        $r = $mk('cashier', 'F&B Service', 8, ['Mineral Water 1L' => 96, 'Soft Drinks 300ml' => 72, 'Paper Napkins (pack of 100)' => 8], ['purpose' => 'Bar par top-up']);
        $r = $approve($r, 'admin', $at(8, '10:00'));
        $ack($issue($r, $at(7, '09:00')), 'cashier', $at(7, '09:20'));
        // 7. rejected
        $r = $mk('laundry', 'Housekeeping', 4, ['Soap 30g' => 40, 'Dental Kit' => 40], ['purpose' => 'Extra amenities for the group check-in', 'time' => '14:00']);
        Auth::setUser($U['housekeeping']);
        $RS::reject($r->fresh(['department']), 'Already covered by REQ-00002 – use the floor stock first');
        $r->forceFill(['approved_at' => $at(4, '15:10')])->save();
        // 8. kitchen, urgent, awaiting the GM's approval (chicken & paneer short in the main store)
        $mk('chef', 'Kitchen', 2, ['Chicken' => 15, 'Paneer' => 6, 'Basmati Rice' => 10], ['purpose' => 'Weekend buffet', 'priority' => 'urgent', 'need' => 2, 'time' => '18:30']);
        // 9. housekeeping, awaiting the housekeeping supervisor
        $mk('laundry', 'Housekeeping', 1, ['Shampoo Sachet' => 100, 'Soap 30g' => 100, 'Floor Cleaner 5L' => 4], ['purpose' => 'Amenities & chemicals for the week', 'time' => '16:10']);
        // 10. maintenance, approved over budget, waiting for the store
        $r = $mk('engineer', 'Maintenance', 1, ['LED Bulb 9W' => 20, 'PVC Insulation Tape' => 10], ['purpose' => 'Lift lobby & DG room lighting failure', 'priority' => 'urgent', 'need' => 1, 'time' => '19:00']);
        $approve($r, 'accounts', $at(1, '19:40'), [], 'Lighting failure in the lift lobby & DG room – safety issue', true);
        // 11. front office draft (would take the department over budget)
        $mk('fom', 'Front Office', 0, ['Registration Card Pad' => 2, 'Ball Pens (box of 10)' => 2], ['purpose' => 'Group check-ins next week', 'draft' => true, 'time' => '09:00']);
        Auth::setUser($user);
    }

    protected function seedFoodSafety(bool $rich, Carbon $today): void
    {
        if (\App\Models\FsEquipment::exists()) {
            return;
        }
        $FS = \App\Services\FoodSafetyService::class;
        $FS::ensureDefaults();
        if (! $rich) {
            return;
        }
        $hid = \App\Support\Tenant::id();
        $u = fn ($n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        [$admin, $chef, $fnb, $store] = [$u('admin'), $u('chef'), $u('restaurant'), $u('store')];
        $chef ??= $admin;
        $fnb ??= $admin;
        $store ??= $admin;
        $prevUser = auth()->user();
        $now = now()->copy()->second(0);
        $back = $now->copy()->subDays(30);

        // ---------- allergens: ingredients (only existing items + a few stockless allergen ingredients) and menu declarations ----------
        $inv = fn ($like) => Models\InventoryItem::where('name', 'like', $like)->first();
        $inv('Paneer%')?->update(['allergens' => ['milk']]);
        $proto = Models\InventoryItem::where('name', 'like', 'Paneer%')->first() ?? Models\InventoryItem::first();
        foreach ([['Wheat Flour (Atta)', 'kg', 38, ['gluten']], ['Butter', 'kg', 95, ['milk']], ['Fresh Cream', 'ltr', 60, ['milk']], ['Eggs (tray of 30)', 'tray', 72, ['eggs']],
            ['Cashew Nuts', 'kg', 180, ['nuts']], ['Groundnut Paste', 'kg', 45, ['peanuts']], ['Tilapia Fillet', 'kg', 85, ['fish']], ['Frozen Prawns', 'kg', 160, ['crustaceans']],
            ['Soy Sauce', 'ltr', 55, ['soy', 'gluten']], ['Sesame Seeds', 'kg', 70, ['sesame']], ['Mustard Seeds', 'kg', 40, ['mustard']]] as [$n, $unit, $cost, $al]) {
            if (! Models\InventoryItem::where('name', $n)->exists() && $proto) {
                $new = $proto->replicate(['hotel_id']);
                $new->forceFill(['name' => $n, 'unit' => $unit, 'reorder_level' => 0, 'allergens' => $al]);
                foreach (['code', 'sku', 'item_code'] as $col) {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('inventory_items', $col)) {
                        $new->{$col} = 'FS'.str_pad((string) (Models\InventoryItem::count() + 1), 3, '0', STR_PAD_LEFT);
                    }
                }
                foreach (['last_cost', 'cost_price', 'rate', 'purchase_rate'] as $col) {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('inventory_items', $col)) {
                        $new->{$col} = $cost;
                    }
                }
                $new->save();
            }
        }
        $decl = [
            'Paneer Tikka' => [['milk'], []], 'Chicken 65' => [['eggs', 'gluten'], ['mustard']], 'Veg Spring Rolls' => [['gluten', 'soy'], ['sesame']],
            'Fish Amritsari' => [['fish', 'gluten', 'eggs'], []], 'Butter Chicken' => [['milk', 'nuts'], []], 'Dal Makhani' => [['milk'], []],
            'Paneer Butter Masala' => [['nuts'], []], // milk (paneer) deliberately undeclared → shows up as a recipe discrepancy
            'Mutton Rogan Josh' => [['milk'], []], 'Veg Biryani' => [['milk', 'nuts'], []], 'Chicken Biryani' => [['milk', 'nuts'], []],
            'Butter Naan' => [['gluten', 'milk'], ['sesame']], 'Garlic Naan' => [['gluten', 'milk'], ['sesame']], 'Masala Dosa' => [['mustard'], ['nuts']],
            'Idli Vada' => [['mustard'], []], 'Omelette' => [['eggs', 'milk'], []], 'Continental' => [['gluten', 'eggs', 'milk'], ['nuts']],
            'Gulab Jamun' => [['milk', 'gluten'], ['nuts']], 'Ice Cream Sundae' => [['milk', 'nuts'], ['peanuts']], 'Masala Chai' => [['milk'], []],
            'Filter Coffee' => [['milk'], []], 'Cold Coffee' => [['milk'], []], 'Kingfisher' => [['gluten'], []], 'Beer' => [['gluten'], []], 'Wine' => [['sulphites'], []],
        ];
        $unverified = ['Masala Dosa', 'Cold Coffee'];
        foreach (Models\MenuItem::all() as $m) {
            foreach ($decl as $name => [$c, $may]) {
                if (stripos($m->name, $name) !== false) {
                    $m->forceFill(['allergens' => $c, 'may_contain' => $may ?: null,
                        'allergens_verified_at' => in_array($name, $unverified, true) ? null : $back->copy()->addDays(20), 'allergens_verified_by' => in_array($name, $unverified, true) ? null : $chef->id])->save();
                    break;
                }
            }
        }

        // ---------- equipment ----------
        $outlet = fn ($like) => Models\Outlet::where('name', 'like', $like)->value('id');
        $eqs = [];
        foreach ([['Walk-in chiller', 'fridge', 'Main kitchen', null, 1.0, 5.0, 2], ['Walk-in freezer', 'freezer', 'Main kitchen', null, null, -18.0, 2],
            ['Pastry reach-in fridge', 'fridge', 'Pastry section', null, null, 5.0, 2], ['Bain-marie (hot pass)', 'hot_hold', 'Hot pass', 'Saffron%', 63.0, null, 3],
            ['Buffet display chiller', 'display', 'Breakfast buffet', 'Saffron%', null, 8.0, 2], ['Bar fridge', 'fridge', 'Back bar', 'Skyline%', null, 5.0, 1]] as [$n, $t, $loc, $o, $min, $max, $cpd]) {
            $e = $FS::saveEquipment(['name' => $n, 'type' => $t, 'location' => $loc, 'outlet_id' => $o ? $outlet($o) : null, 'min_c' => $min, 'max_c' => $max, 'checks_per_day' => $cpd]);
            $e->forceFill(['created_at' => $back])->saveQuietly();
            $eqs[$n] = $e;
        }

        // ---------- a week of readings (inserted directly: HACCP entry is time-limited in the app) ----------
        $log = function (array $d) {
            [$min, $max] = [$d['min_c'] ?? null, $d['max_c'] ?? null];
            $ok = \App\Services\FoodSafetyService::inRange((float) $d['reading_c'], $min, $max);

            return \App\Models\FsTempLog::create($d + ['in_range' => $ok]);
        };
        $slots = [1 => ['08:10'], 2 => ['07:50', '16:20'], 3 => ['07:45', '12:30', '18:40']];
        $missed = ['Pastry reach-in fridge|4|1', 'Bar fridge|2|0']; // unit|days ago|slot index
        for ($ago = 7; $ago >= 0; $ago--) {
            $day = $now->copy()->subDays($ago);
            foreach ($eqs as $n => $e) {
                foreach ($slots[$e->checks_per_day] as $si => $hm) {
                    $at = $day->copy()->setTimeFromTimeString($hm)->addMinutes(crc32($n.$ago.$si) % 12);
                    if ($at->gt($now) || in_array("$n|$ago|$si", $missed, true)) {
                        continue;
                    }
                    $h = crc32($n.$ago.$si) % 100;
                    $v = match ($e->type) { 'freezer' => -21 + $h % 30 / 10, 'hot_hold' => 66 + $h % 90 / 10, 'display' => 3 + $h % 35 / 10, default => 1.8 + $h % 25 / 10 };
                    $action = null;
                    if ($n === 'Walk-in chiller' && $ago === 3 && $si === 1) {
                        [$v, $action] = [8.5, 'Door seal torn – high-risk stock moved to the reach-in, 2 kg chicken discarded, engineering ticket raised'];
                    }
                    if ($n === 'Bain-marie (hot pass)' && $ago === 1 && $si === 2) {
                        [$v, $action] = [58.0, 'Butter chicken reheated to 78 °C core and returned; bain-marie thermostat turned up'];
                    }
                    $l = $log(['kind' => 'equipment', 'fs_equipment_id' => $e->id, 'reading_c' => round($v, 1), 'min_c' => $e->min_c, 'max_c' => $e->max_c,
                        'corrective_action' => $action, 'recorded_at' => $at, 'user_id' => $e->outlet_id && $e->type === 'fridge' ? $fnb->id : $chef->id]);
                    if ($action && $ago === 3) {
                        $l->update(['reviewed_at' => $at->copy()->addHours(2), 'reviewed_by' => $admin->id, 'review_note' => 'Seal replaced next morning (WO raised); discard logged in waste']);
                    }
                }
            }
        }
        $sup = Models\Supplier::orderBy('id')->pluck('id')->all();
        $L = fn ($kind) => $FS::limits($kind, null, null);
        $atDay = fn ($ago, $hm) => $now->copy()->subDays($ago)->setTimeFromTimeString($hm);
        foreach ([[6, '09:20', 'receiving', 'Chilled chicken (20 kg)', 'chilled', 3.4, null], [4, '09:05', 'receiving', 'Paneer & dairy', 'chilled', 4.1, null],
            [2, '10:15', 'receiving', 'Frozen prawns (10 kg)', 'frozen', -11.0, 'Rejected – delivery partly thawed; returned to the supplier with the driver, credit note requested'],
            [1, '09:30', 'receiving', 'Chilled chicken (15 kg)', 'chilled', 2.9, null],
            [5, '12:40', 'cooking', 'Chicken 65 (batch)', null, 82.0, null], [3, '19:10', 'cooking', 'Butter chicken base', null, 79.5, null], [2, '13:05', 'cooking', 'Chicken biryani', null, 88.0, null],
            [1, '12:20', 'cooking', 'Fish Amritsari', null, 71.0, 'Probe read 71 °C – returned to the fryer 2 minutes, re-probed 80 °C'], [1, '20:15', 'reheating', 'Dal makhani (from chiller)', null, 81.0, null]] as [$ago, $hm, $kind, $item, $cat, $v, $action]) {
            [$min, $max] = $kind === 'receiving' ? $FS::limits('receiving', null, $cat) : $L($kind);
            $at = $atDay($ago, $hm);
            $l = $log(['kind' => $kind, 'item' => $item, 'category' => $cat, 'supplier_id' => $kind === 'receiving' && $sup ? $sup[$ago % count($sup)] : null,
                'reading_c' => $v, 'min_c' => $min, 'max_c' => $max, 'corrective_action' => $action, 'recorded_at' => $at, 'user_id' => $kind === 'receiving' ? $store->id : $chef->id]);
            if ($action && $kind === 'receiving') {
                $l->update(['reviewed_at' => $at->copy()->addHour(), 'reviewed_by' => $admin->id, 'review_note' => 'Supplier warned; next frozen delivery to be probed on arrival']);
            }
        }
        // cooling: yesterday's completed, today's in progress (2-hour reading taken 1 h ago)
        [, $c1] = $FS::limits('cooling', null, null, 1);
        [, $c2] = $FS::limits('cooling', null, null, 2);
        $y = $atDay(1, '15:00');
        $log(['kind' => 'cooling', 'item' => 'Chicken stock (20 L)', 'reading_c' => 18.5, 'reading2_c' => 4.2, 'reading2_at' => $y->copy()->addHours(4), 'in_range' => true, 'min_c' => null, 'max_c' => $c2, 'recorded_at' => $y, 'user_id' => $chef->id]);
        $log(['kind' => 'cooling', 'item' => 'Jollof rice base (8 kg)', 'reading_c' => 19.0, 'min_c' => null, 'max_c' => $c1, 'recorded_at' => $now->copy()->subHour(), 'user_id' => $chef->id]);

        // ---------- checklists ----------
        $lists = Models\FsChecklist::all()->keyBy('name');
        foreach ($lists as $c) {
            $c->forceFill(['created_at' => $back])->saveQuietly();
        }
        $run = function (Models\FsChecklist $c, Carbon $period, Carbon $at, Models\User $by, array $fail = [], ?Models\User $signer = null, ?string $signNote = null) {
            $answers = [];
            foreach ($c->items as $i => $text) {
                $answers[] = ['item' => $text, 'ok' => ! isset($fail[$i]), 'note' => $fail[$i] ?? null];
            }
            Models\FsChecklistRun::create(['fs_checklist_id' => $c->id, 'period' => $period->toDateString(), 'answers' => $answers, 'failed' => count($fail), 'completed_by' => $by->id, 'completed_at' => $at,
                'signed_by' => $signer?->id, 'signed_at' => $signer ? $at->copy()->addHours(10) : null, 'sign_note' => $signNote]);
        };
        $open = $lists['Kitchen opening checks'] ?? null;
        $close = $lists['Kitchen closing & cleaning'] ?? null;
        $bar = $lists['Bar opening checks'] ?? null;
        for ($ago = 7; $ago >= 0; $ago--) {
            $d = $now->copy()->subDays($ago)->startOfDay();
            if ($open && $ago !== 2 && $d->copy()->setTime(9, 35)->lte($now)) {
                $run($open, $d, $d->copy()->setTime(9, 35), $chef);
            }
            if ($close && $ago >= 1) {
                $fail = match ($ago) { 4 => [4 => 'Filters overdue – sent for cleaning, spare set fitted tomorrow'], 1 => [5 => 'Bin lid hinge broken – bin taped shut, replacement requested'], default => [] };
                $run($close, $d, $d->copy()->setTime(23, 20), $chef, $fail, $ago === 4 ? $admin : null, $ago === 4 ? 'Spare filters fitted; weekly degrease added to the deep-clean list' : null);
            }
            if ($bar && $ago !== 5 && $d->copy()->setTime(15, 40)->lte($now)) {
                $run($bar, $d, $d->copy()->setTime(15, 40), $fnb);
            }
        }
        if ($wk = $lists['Weekly deep clean'] ?? null) {
            $p = $FS::periodFor($wk, $now->copy()->subWeek());
            $run($wk, $p, $p->copy()->addDays(5)->setTime(22, 30), $chef);
        }
        if ($mo = $lists['Monthly pest control inspection'] ?? null) {
            $run($mo, $now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->startOfMonth()->addDays(9)->setTime(11, 0), $chef, [], $admin, null);
            if ($now->day > 10) {
                $run($mo, $now->copy()->startOfMonth(), $now->copy()->startOfMonth()->addDays(9)->setTime(11, 0), $chef);
            }
        }

        // ---------- handler certificates ----------
        auth()->setUser($admin);
        $emp = fn ($code) => Models\Employee::where('emp_code', $code)->first();
        $bd = $today->copy();
        foreach ([['EMP006', 'medical', 'Korle Bu Teaching Hospital', -110, 255], ['EMP006', 'hygiene', 'Ghana Tourism Authority', -300, 430],
            ['EMP007', 'medical', 'Ridge Hospital', -370, -5], ['EMP007', 'hygiene', 'Ghana Tourism Authority', -345, 20]] as [$code, $type, $issuer, $iss, $exp]) {
            if ($e = $emp($code)) {
                $FS::saveCertificate(['employee_id' => $e->id, 'type' => $type, 'issuer' => $issuer, 'cert_no' => strtoupper(substr($type, 0, 3)).'-'.$e->id.'-'.$bd->copy()->addDays($iss)->format('y'),
                    'issued_on' => $bd->copy()->addDays($iss)->toDateString(), 'expires_on' => $bd->copy()->addDays($exp)->toDateString()], $admin);
            }
        }

        // ---------- waste (posts wastage stock movements → Dr Food Waste / Cr Inventory) ----------
        $W = \App\Services\FoodWasteService::class;
        $menu = fn ($like) => Models\MenuItem::where('name', 'like', $like)->value('id');
        auth()->setUser($chef);
        $kitchen = Models\Store::where('type', 'kitchen')->value('id');
        $rest = $outlet('Saffron%');
        try {
            if ($ch = $inv('Chicken%')) {
                $W::record(['source' => 'raw', 'inventory_item_id' => $ch->id, 'qty' => 2, 'reason' => 'temperature', 'store_id' => $kitchen, 'date' => $bd->copy()->subDays(3)->toDateString(),
                    'notes' => 'Walk-in chiller at 8.5 °C – see temperature log'], $chef);
            }
            if ($m = $menu('Chicken Biryani%')) {
                $W::record(['source' => 'dish', 'menu_item_id' => $m, 'qty' => 3, 'reason' => 'overproduction', 'outlet_id' => $rest, 'date' => $bd->copy()->subDays(2)->toDateString(), 'notes' => 'Lunch buffet leftovers past 2 hours'], $chef);
            }
            if ($pa = $inv('Paneer%')) {
                $W::record(['source' => 'raw', 'inventory_item_id' => $pa->id, 'qty' => 0.4, 'reason' => 'expired', 'store_id' => $kitchen, 'date' => $bd->copy()->subDay()->toDateString()], $chef);
                $dup = $W::record(['source' => 'raw', 'inventory_item_id' => $pa->id, 'qty' => 0.4, 'reason' => 'expired', 'store_id' => $kitchen, 'date' => $bd->copy()->subDay()->toDateString()], $chef);
                $W::cancel($dup, 'Entered twice', $admin);
            }
            if ($m = $menu('Butter Chicken%')) {
                $W::record(['source' => 'plate', 'menu_item_id' => $m, 'qty' => 2, 'reason' => 'plate', 'outlet_id' => $rest, 'date' => $bd->copy()->subDay()->toDateString(), 'notes' => 'Two plates returned – too spicy'], $fnb);
            }
            if ($ri = $inv('Basmati%')) {
                $W::record(['source' => 'raw', 'inventory_item_id' => $ri->id, 'qty' => 1.5, 'reason' => 'overproduction', 'store_id' => $kitchen, 'date' => $bd->toDateString(), 'notes' => 'Cooked rice left from breakfast'], $chef);
            }
        } catch (\RuntimeException $ex) {
            // demo data must never break the seed
        }

        // ---------- prep labels ----------
        foreach ([['Butter chicken gravy', $menu('Butter Chicken%'), 'chilled', 20, 6, 'BC-'.$now->format('dm')], ['Chicken stock', null, 'frozen', 30, 4, 'CS-'.$now->copy()->subDay()->format('dm')],
            ['Shito (house pepper sauce)', null, 'chilled', 50, 3, null], ['Jollof rice base', null, 'chilled', 2, 2, null]] as [$item, $mid, $st, $hAgo, $qty, $batch]) {
            $FS::createLabels(['item' => $mid ? null : $item, 'menu_item_id' => $mid, 'storage' => $st, 'prepared_at' => $now->copy()->subHours($hAgo)->toDateTimeString(), 'qty' => $qty, 'batch' => $batch,
                'allergens' => $item === 'Shito (house pepper sauce)' ? ['fish', 'crustaceans'] : []], $chef);
        }

        // ---------- an open table with a declared allergy ----------
        $openOrders = Models\PosOrder::with('items.menuItem.recipes.inventoryItem')->where('status', 'open')->whereNotNull('pos_table_id')->orderBy('id')->get();
        $nutty = $openOrders->first(fn ($o) => $o->items->contains(fn ($l) => $l->menuItem && \App\Services\AllergenService::conflicts($l->menuItem, ['nuts', 'peanuts'])['contains']));
        if ($o = $nutty ?? $openOrders->first()) {
            \App\Services\AllergenService::setOrderAllergies($o, ['nuts', 'peanuts'], 'Guest at seat 2 – severe nut allergy, carries an EpiPen');
        }

        \App\Services\LedgerService::sync(true);
        $FS::flush();
        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
    }

}
