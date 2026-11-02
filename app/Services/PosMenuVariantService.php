<?php

namespace App\Services;

use App\Models\MenuItem;
use Illuminate\Validation\ValidationException;

class PosMenuVariantService
{
    /** @return array{0: array<int, array<string, mixed>>, 1: float} */
    public static function snapshot(MenuItem $item, array $selectedIds, int $line): array
    {
        $selectedIds = array_values(array_unique(array_map('intval', $selectedIds)));
        if (! $item->relationLoaded('variants')) {
            $item->load('variants');
        }
        $variants = $item->variants->keyBy('id');
        if (count($selectedIds) !== count(array_intersect($selectedIds, $variants->keys()->all()))) {
            throw ValidationException::withMessages(["items.{$line}.variants" => 'One or more selected side dishes are not available for this menu item.']);
        }

        $snapshots = [];
        $amount = 0.0;
        foreach ($selectedIds as $id) {
            $variant = $variants->get($id);
            $rate = round((float) $variant->rate, 2);
            $amount += $rate;
            $snapshots[] = [
                'type' => 'side_dish', 'id' => $variant->id, 'group' => 'Side dishes',
                'name' => $variant->name, 'price_delta' => $rate,
            ];
        }

        if (round((float) $item->price + $amount, 2) < 0) {
            throw ValidationException::withMessages(["items.{$line}.variants" => 'The selected side dishes cannot reduce the item price below zero.']);
        }

        return [$snapshots, round($amount, 2)];
    }
}
