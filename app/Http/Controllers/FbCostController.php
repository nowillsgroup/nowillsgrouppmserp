<?php

namespace App\Http\Controllers;

use App\Models\FbPriceChange;
use App\Models\FbRecipeCard;
use App\Models\FbStaffMeal;
use App\Models\FbYieldTest;
use App\Models\InventoryItem;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Outlet;
use App\Services\FbCostService as FC;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** v6.8 F&B cost control & menu engineering. */
class FbCostController extends Controller
{
    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first())->withInput();
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', is_string($r) ? $r : $ok);
    }

    /** Period from the query (defaults: the last 28 closed days). */
    protected function period(Request $r, int $days = 28): array
    {
        $to = $this->day($r->input('to')) ?? bdate()->copy()->subDay();
        $from = $this->day($r->input('from')) ?? $to->copy()->subDays($days - 1);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    protected function day($v): ?Carbon
    {
        if (! filled($v)) {
            return null;
        }
        try {
            return Carbon::parse((string) $v)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function ingredients()
    {
        return InventoryItem::with('category')->where('is_active', true)->orderBy('name')->get();
    }

    // ------------------------------------------------------------------ dashboard

    public function index()
    {
        $to = bdate()->copy()->subDay();
        $daily = FC::daily($to->copy()->subDays(29)->toDateString(), $to->toDateString());
        $dishes = FC::dishes();
        $cfg = FC::cfg();
        $me = FC::menuEngineering($to->copy()->subDays(27)->toDateString(), $to->toDateString());

        return view('fbc.index', ['k' => FC::kpis(), 'alerts' => FC::alerts(), 'daily' => $daily, 'cfg' => $cfg, 'me' => $me,
            'over' => $dishes->where('st', 'over')->sortByDesc(fn ($m) => $m->pct - $m->target)->take(8),
            'creep' => $dishes->filter(fn ($m) => $m->creep && $m->creep['pct'] >= $cfg['creep_pct'])->sortByDesc(fn ($m) => $m->creep['pct'])->take(8),
            'changes' => FbPriceChange::with(['menuItem', 'user'])->orderByDesc('effective_on')->orderByDesc('id')->limit(5)->get()]);
    }

    // ------------------------------------------------------------------ recipes

    public function recipes(Request $r)
    {
        $outlet = $r->integer('outlet') ?: null;
        $dishes = FC::dishes($outlet)
            ->when($r->integer('category'), fn ($c) => $c->where('menu_category_id', $r->integer('category')))
            ->when($r->input('status'), fn ($c) => $c->where('st', $r->input('status')))
            ->when($r->input('q'), fn ($c) => $c->filter(fn ($m) => stripos($m->name.' '.$m->code, (string) $r->input('q')) !== false));

        return view('fbc.recipes', ['dishes' => $dishes->values(), 'outlets' => Outlet::orderBy('name')->get(), 'categories' => MenuCategory::orderBy('sort_order')->get(),
            'all' => FC::dishes(), 'cfg' => FC::cfg()]);
    }

    protected function cardData(MenuItem $m): array
    {
        $m->load(['category', 'outlet', 'card.approver', 'card.editor', 'recipes.inventoryItem.category']);
        $lines = $m->recipes->filter(fn ($r) => $r->inventoryItem)->map(function ($r) {
            $i = $r->inventoryItem;
            $uc = FC::unitCost($i);
            $cost = FC::lineCost((float) $r->qty, $i);

            return (object) ['r' => $r, 'item' => $i, 'qty' => (float) $r->qty, 'gross' => (float) $r->qty * 100 / max(1, (float) $i->yield_pct), 'unit_cost' => $uc, 'cost' => $cost];
        })->sortByDesc('cost')->values();
        $cost = round($lines->sum('cost'), 4);
        $target = FC::targetFor($m);

        return ['m' => $m, 'card' => $m->card, 'lines' => $lines, 'cost' => $cost, 'target' => $target, 'price' => (float) $m->price,
            'pct' => $m->price > 0 && $lines->isNotEmpty() ? round($cost / $m->price * 100, 1) : null,
            'suggested' => FC::suggestPrice($cost, $target), 'history' => FC::history($m->id), 'creep' => FC::creep()[$m->id] ?? null,
            'changes' => FbPriceChange::with('user')->where('menu_item_id', $m->id)->orderByDesc('effective_on')->orderByDesc('id')->limit(10)->get(),
            'cls' => FC::classOf($m), 'cfg' => FC::cfg()];
    }

    public function card(MenuItem $fbdish)
    {
        return view('fbc.card', $this->cardData($fbdish));
    }

    public function cardPrint(MenuItem $fbdish)
    {
        return view('fbc.card_print', $this->cardData($fbdish));
    }

    public function cardEdit(MenuItem $fbdish)
    {
        return view('fbc.card_edit', $this->cardData($fbdish) + ['ingredients' => $this->ingredients()]);
    }

    public function cardSave(Request $r, MenuItem $fbdish)
    {
        try {
            FC::saveCard($fbdish, $r->all(), $r->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('fbc.card', $fbdish)->with('success', "Recipe card saved – plate cost ".money(FC::portionCost($fbdish->id)).'.');
    }

    public function cardApprove(Request $r, MenuItem $fbdish)
    {
        return $this->attempt(function () use ($r, $fbdish) {
            $card = FbRecipeCard::where('menu_item_id', $fbdish->id)->first();
            if (! $card) {
                throw new RuntimeException('Save the recipe card first.');
            }
            FC::approveCard($card, $r->user());
        }, 'Recipe card approved.');
    }

    // ------------------------------------------------------------------ menu engineering

    public function menu(Request $r)
    {
        [$from, $to] = $this->period($r);
        $outlet = $r->integer('outlet') ?: null;
        $cat = $r->integer('category') ?: null;

        return view('fbc.menu', ['me' => FC::menuEngineering($from, $to, $outlet, $cat), 'from' => $from, 'to' => $to,
            'outlets' => Outlet::orderBy('name')->get(), 'categories' => MenuCategory::orderBy('sort_order')->get(), 'cfg' => FC::cfg()]);
    }

    // ------------------------------------------------------------------ cost statement

    public function statement(Request $r)
    {
        [$from, $to] = $this->period($r);

        return view('fbc.statement', ['from' => $from, 'to' => $to, 'food' => FC::statement($from, $to, 'food'), 'bev' => FC::statement($from, $to, 'beverage'),
            'daily' => FC::daily($from, $to), 'cfg' => FC::cfg()]);
    }

    public function variance(Request $r)
    {
        [$from, $to] = $this->period($r);
        $class = in_array($r->input('class'), ['food', 'beverage'], true) ? $r->input('class') : null;

        return view('fbc.variance', ['rows' => FC::usage($from, $to, $class), 'from' => $from, 'to' => $to, 'class' => $class]);
    }

    // ------------------------------------------------------------------ pricing

    public function pricing(Request $r)
    {
        $outlet = $r->integer('outlet') ?: null;
        $dishes = FC::dishes($outlet)->filter(fn ($m) => $m->st !== 'no_recipe')
            ->when($r->input('show') === 'over', fn ($c) => $c->where('st', 'over'))
            ->sortBy([['menu_category_id', 'asc'], ['name', 'asc']])->values();

        return view('fbc.pricing', ['dishes' => $dishes, 'outlets' => Outlet::orderBy('name')->get(), 'cfg' => FC::cfg(),
            'changes' => FbPriceChange::with(['menuItem', 'user'])->orderByDesc('effective_on')->orderByDesc('id')->limit(15)->get()]);
    }

    public function priceChange(Request $r, MenuItem $fbdish)
    {
        return $this->attempt(fn () => FC::changePrice($fbdish, $r->input('price'), (string) $r->input('reason'), $r->user()), "Price of {$fbdish->name} changed.");
    }

    // ------------------------------------------------------------------ yields

    public function yields()
    {
        return view('fbc.yields', ['tests' => FbYieldTest::with(['item', 'user', 'applier'])->orderByDesc('tested_on')->orderByDesc('id')->paginate(30),
            'ingredients' => $this->ingredients()->filter(fn ($i) => in_array($i->category?->fb_class, ['food', 'beverage'], true))->values(),
            'trimmed' => InventoryItem::where('yield_pct', '<', 100)->orderBy('name')->get()]);
    }

    public function yieldStore(Request $r)
    {
        return $this->attempt(fn () => 'Yield test '.FC::recordYield($r->all(), $r->user())->number.' recorded.', 'Yield test recorded.');
    }

    public function yieldApply(Request $r, FbYieldTest $fbyield)
    {
        return $this->attempt(fn () => FC::applyYield($fbyield, $r->user()), "Yield applied to {$fbyield->item?->name}.");
    }

    // ------------------------------------------------------------------ staff meals

    public function meals(Request $r)
    {
        // Meals are logged for the open business date too, so the default window ends today, not yesterday.
        if (! $r->filled('to')) {
            $r->merge(['to' => bdate()->toDateString()]);
        }
        [$from, $to] = $this->period($r, 31);
        $to = min($to, bdate()->toDateString());
        $rows = FbStaffMeal::with('user')->whereBetween('business_date', [$from, $to])->orderByDesc('business_date')->orderBy('meal')->get();

        return view('fbc.meals', ['rows' => $rows, 'from' => $from, 'to' => $to, 'cfg' => FC::cfg(),
            'total' => $rows->sum('total'), 'covers' => $rows->sum('covers'), 'days' => $rows->pluck('business_date')->map(fn ($d) => $d->toDateString())->unique()->count()]);
    }

    public function mealSave(Request $r)
    {
        return $this->attempt(fn () => FC::saveMeal($r->all(), $r->user()), 'Staff meal logged.');
    }

    public function mealDelete(Request $r, FbStaffMeal $fbmeal)
    {
        return $this->attempt(fn () => FC::deleteMeal($fbmeal, $r->user()), 'Entry removed.');
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        return view('fbc.settings', ['cfg' => FC::cfg(), 'mcats' => MenuCategory::orderBy('sort_order')->get(), 'icats' => \App\Models\ItemCategory::orderBy('name')->get()]);
    }

    public function settingsSave(Request $r)
    {
        return $this->attempt(fn () => FC::saveSettings($r->all()), 'Settings saved.');
    }
}
