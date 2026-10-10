<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Models\MenuModifier;
use App\Models\MenuModifierGroup;
use App\Models\Outlet;
use App\Services\ActivityLogger;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosModifierController extends Controller
{
    public function index()
    {
        return view('pos.modifiers', [
            'groups' => MenuModifierGroup::with('modifiers', 'outlet')->orderBy('sort_order')->orderBy('name')->get(),
            'items' => MenuItem::with('category', 'outlet', 'modifierGroups')->orderBy('name')->get(),
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function storeGroup(Request $request)
    {
        $data = $this->groupData($request);
        $group = MenuModifierGroup::create($data);
        ActivityLogger::log('pos', 'modifier_group_create', "Created modifier group {$group->name}", $group);

        return back()->with('success', 'Modifier group created.');
    }

    public function updateGroup(Request $request, MenuModifierGroup $group)
    {
        $group->update($this->groupData($request));
        ActivityLogger::log('pos', 'modifier_group_update', "Updated modifier group {$group->name}", $group);

        return back()->with('success', 'Modifier group updated.');
    }

    public function destroyGroup(MenuModifierGroup $group)
    {
        $name = $group->name;
        $group->delete();
        ActivityLogger::log('pos', 'modifier_group_delete', "Deleted modifier group {$name}");

        return back()->with('success', 'Modifier group deleted.');
    }

    public function storeModifier(Request $request)
    {
        $data = $this->modifierData($request);
        $modifier = MenuModifier::create($data);
        ActivityLogger::log('pos', 'modifier_create', "Created modifier {$modifier->name}", $modifier);

        return back()->with('success', 'Modifier created.');
    }

    public function updateModifier(Request $request, MenuModifier $modifier)
    {
        $modifier->update($this->modifierData($request));
        ActivityLogger::log('pos', 'modifier_update', "Updated modifier {$modifier->name}", $modifier);

        return back()->with('success', 'Modifier updated.');
    }

    public function destroyModifier(MenuModifier $modifier)
    {
        $name = $modifier->name;
        $modifier->delete();
        ActivityLogger::log('pos', 'modifier_delete', "Deleted modifier {$name}");

        return back()->with('success', 'Modifier deleted.');
    }

    public function assign(Request $request, MenuItem $menuItem)
    {
        $data = $request->validate(['groups' => 'nullable|array|max:20', 'groups.*' => 'integer|owned:menu_modifier_groups']);
        $groups = MenuModifierGroup::whereIn('id', $data['groups'] ?? [])->where('is_active', true)->get();
        foreach ($groups as $group) {
            if ($group->outlet_id && $menuItem->outlet_id && $group->outlet_id !== $menuItem->outlet_id) {
                throw ValidationException::withMessages(['groups' => "{$group->name} belongs to another outlet."]);
            }
        }
        DB::transaction(function () use ($menuItem, $groups): void {
            DB::table('menu_item_modifier_groups')->where('menu_item_id', $menuItem->id)->delete();
            foreach ($groups->values() as $sort => $group) {
                DB::table('menu_item_modifier_groups')->insert([
                    'hotel_id' => Tenant::id(), 'menu_item_id' => $menuItem->id, 'menu_modifier_group_id' => $group->id,
                    'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        ActivityLogger::log('pos', 'modifier_assign', "Updated modifiers for {$menuItem->name}", $menuItem);

        return back()->with('success', "Modifier groups updated for {$menuItem->name}.");
    }

    protected function groupData(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80', 'outlet_id' => 'nullable|integer|owned:outlets',
            'min_selections' => 'required|integer|min:0|max:10', 'max_selections' => 'required|integer|min:1|max:10',
            'is_required' => 'nullable|boolean', 'is_active' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:0|max:999',
        ]);
        $data['is_required'] = $request->boolean('is_required');
        if ($data['is_required']) {
            $data['min_selections'] = max(1, $data['min_selections']);
        }
        if ($data['max_selections'] < $data['min_selections']) {
            throw ValidationException::withMessages(['max_selections' => 'Maximum selections cannot be below the minimum.']);
        }
        $data['is_active'] = $request->boolean('is_active', true);
        $data['sort_order'] ??= 0;

        return $data;
    }

    protected function modifierData(Request $request): array
    {
        return $request->validate([
            'menu_modifier_group_id' => 'required|integer|owned:menu_modifier_groups', 'name' => 'required|string|max:100',
            'price_delta' => 'required|numeric|min:-100000|max:100000', 'is_available' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:0|max:999',
        ]) + ['is_available' => $request->boolean('is_available', true), 'sort_order' => (int) $request->input('sort_order', 0)];
    }
}
