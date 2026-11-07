<?php

namespace App\Services;

use App\Models\MenuItem;
use Illuminate\Validation\ValidationException;

class PosMenuVariantService
{
    /**
     * Price the side dishes a line carries.
     *
     * A side dish can be ordered more than once — a double portion of extra banku is two rate lines, not one — and the
     * request says so by naming the variant twice (`variants[]=2&variants[]=2`). The snapshot therefore counts the ids
     * rather than de-duplicating them, prices each unit, and records the count beside the rate so the kitchen ticket,
     * the bill and the reprint can all say "Extra banku ×2" without re-reading the request.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    public static function snapshot(MenuItem $item, array $selectedIds, int $line): array
    {
        $counts = [];
        foreach ($selectedIds as $id) {
            $id = (int) $id;
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        if (! $item->relationLoaded('variants')) {
            $item->load('variants');
        }
        $variants = $item->variants->keyBy('id');
        if (array_diff(array_keys($counts), $variants->keys()->all()) !== []) {
            throw ValidationException::withMessages(["items.{$line}.variants" => 'One or more selected side dishes are not available for this menu item.']);
        }

        $snapshots = [];
        $amount = 0.0;
        foreach ($counts as $id => $qty) {
            $variant = $variants->get($id);
            $rate = round((float) $variant->rate, 2);
            $amount += $rate * $qty;
            $snapshots[] = [
                'type' => 'side_dish', 'id' => $variant->id, 'group' => 'Side dishes',
                'name' => $variant->name, 'price_delta' => $rate, 'qty' => $qty,
                // v9.34 the side carries the cost in force when it was sold, so the food cost and the margin of the dish
                // it was ordered with are not silently overstated by its own cost
                'cost' => round((float) $variant->cost, 2),
            ];
        }

        if (round((float) $item->price + $amount, 2) < 0) {
            throw ValidationException::withMessages(["items.{$line}.variants" => 'The selected side dishes cannot reduce the item price below zero.']);
        }

        return [$snapshots, round($amount, 2)];
    }

    /**
     * "Extra banku ×2" — the side dishes of a line as one readable phrase, the count only shown when it is more than one.
     *
     * @param  iterable<int, array<string, mixed>>  $sideDishes  the `side_dish` entries of a line's modifier snapshot
     */
    public static function describe(iterable $sideDishes): string
    {
        $parts = [];
        foreach ($sideDishes as $side) {
            $qty = (int) ($side['qty'] ?? 1);
            $parts[] = $side['name'].($qty > 1 ? ' ×'.$qty : '');
        }

        return implode(', ', $parts);
    }
}
