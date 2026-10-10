<?php

namespace App\Services;

use App\Models\MenuItem;
use Illuminate\Validation\ValidationException;

class PosModifierService
{
    /** @return array{0: array<int, array<string, mixed>>, 1: float} */
    public static function snapshot(MenuItem $item, array $selectedIds, int $line): array
    {
        $selectedIds = array_values(array_unique(array_map('intval', $selectedIds)));
        $groups = $item->modifierGroups->where('is_active', true);
        $available = $groups->flatMap(fn ($group) => $group->modifiers->where('is_available', true))->keyBy('id');
        if (count($selectedIds) !== count(array_intersect($selectedIds, $available->keys()->all()))) {
            throw ValidationException::withMessages(["items.{$line}.modifiers" => 'One or more selected modifiers are not available for this menu item.']);
        }
        $selected = collect($selectedIds)->map(fn ($id) => $available->get($id));
        $snapshots = [];
        $delta = 0.0;
        foreach ($groups as $group) {
            $minimum = $group->is_required ? max(1, (int) $group->min_selections) : (int) $group->min_selections;
            $maximum = (int) $group->max_selections;
            $inGroup = $selected->filter(fn ($modifier) => $modifier && $modifier->menu_modifier_group_id === $group->id);
            if ($inGroup->count() < $minimum || $inGroup->count() > $maximum) {
                throw ValidationException::withMessages(["items.{$line}.modifiers" => "Choose between {$minimum} and {$maximum} option(s) for {$group->name}."]);
            }
            foreach ($inGroup as $modifier) {
                $price = round((float) $modifier->price_delta, 2);
                $delta += $price;
                $snapshots[] = ['id' => $modifier->id, 'group_id' => $group->id, 'group' => $group->name, 'name' => $modifier->name, 'price_delta' => $price];
            }
        }
        if (round((float) $item->price + $delta, 2) < 0) {
            throw ValidationException::withMessages(["items.{$line}.modifiers" => 'The selected modifiers cannot reduce the item price below zero.']);
        }

        return [$snapshots, round($delta, 2)];
    }
}
