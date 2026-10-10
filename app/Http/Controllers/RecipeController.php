<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\MenuItemRecipe;
use App\Services\ActivityLogger;
use App\Services\RecipeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $items = MenuItem::with(['category', 'recipes.inventoryItem'])
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->q.'%'))
            ->when($request->status === 'missing', fn ($q) => $q->doesntHave('recipes'))
            ->when($request->status === 'costed', fn ($q) => $q->has('recipes'))
            ->orderBy('name')->paginate(30)->withQueryString();
        $items->getCollection()->transform(function ($m) {
            $m->portion_cost = $m->recipes->sum(fn ($r) => $r->inventoryItem ? \App\Services\FbCostService::lineCost((float) $r->qty, $r->inventoryItem) : 0);
            $m->cost_pct = $m->price > 0 && $m->recipes->isNotEmpty() ? round($m->portion_cost / $m->price * 100, 1) : null;

            return $m;
        });

        return view('recipes.index', ['items' => $items, 'target' => (float) setting('food_cost_target_pct', 35),
            'costed' => MenuItem::has('recipes')->count(), 'total' => MenuItem::count()]);
    }

    public function edit(MenuItem $menuItem)
    {
        return view('recipes.edit', ['item' => $menuItem->load('recipes.inventoryItem'), 'stock' => InventoryItem::where('is_active', true)->orderBy('name')->get(),
            'cost' => RecipeService::portionCost($menuItem->id)]);
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $data = $request->validate(['lines' => 'nullable|array|max:40', 'lines.*.inventory_item_id' => 'nullable|integer|owned:inventory_items',
            'lines.*.qty' => 'nullable|numeric|min:0.001|max:9999']);
        $lines = collect($data['lines'] ?? [])->filter(fn ($l) => ! empty($l['inventory_item_id']) && ! empty($l['qty']))
            ->groupBy('inventory_item_id')->map(fn ($g) => $g->sum('qty'));
        DB::transaction(function () use ($menuItem, $lines) {
            MenuItemRecipe::where('menu_item_id', $menuItem->id)->delete();
            foreach ($lines as $inv => $qty) {
                MenuItemRecipe::create(['menu_item_id' => $menuItem->id, 'inventory_item_id' => $inv, 'qty' => $qty]);
            }
        });
        ActivityLogger::log('inventory', 'recipe', "Updated recipe for {$menuItem->name} ({$lines->count()} ingredients)", $menuItem);

        return redirect()->route('recipes.index')->with('success', "Recipe for {$menuItem->name} saved – portion cost ".money(RecipeService::portionCost($menuItem->id)).'.');
    }
}
