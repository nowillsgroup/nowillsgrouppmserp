<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * v5.0 Allergen management. The 14 major allergens (Codex Alimentarius list used by the Ghana FDA labelling
 * guidelines, same as EU FIC Annex II). A dish "contains" what the chef declared plus every allergen carried by
 * its recipe ingredients; "may contain" is the cross-contact warning. Guests' allergies on a POS order are
 * checked against every item sent to the kitchen.
 */
class AllergenService
{
    /** key => [label, short code] */
    public const ALLERGENS = [
        'gluten' => ['Cereals containing gluten', 'GL'], 'crustaceans' => ['Crustaceans (prawns, crab, lobster)', 'CR'],
        'eggs' => ['Eggs', 'EG'], 'fish' => ['Fish', 'FI'], 'peanuts' => ['Peanuts (groundnuts)', 'PN'],
        'soy' => ['Soybeans', 'SO'], 'milk' => ['Milk / dairy', 'MI'], 'nuts' => ['Tree nuts (cashew, almond …)', 'TN'],
        'celery' => ['Celery', 'CE'], 'mustard' => ['Mustard', 'MU'], 'sesame' => ['Sesame', 'SE'],
        'sulphites' => ['Sulphites (> 10 mg/kg)', 'SU'], 'lupin' => ['Lupin', 'LU'], 'molluscs' => ['Molluscs (snails, squid …)', 'MO'],
    ];

    /** Short names for chits, KOTs and the KDS. */
    public const SHORT = ['gluten' => 'Gluten', 'crustaceans' => 'Crustaceans', 'eggs' => 'Egg', 'fish' => 'Fish', 'peanuts' => 'Peanut', 'soy' => 'Soy', 'milk' => 'Milk',
        'nuts' => 'Tree nuts', 'celery' => 'Celery', 'mustard' => 'Mustard', 'sesame' => 'Sesame', 'sulphites' => 'Sulphites', 'lupin' => 'Lupin', 'molluscs' => 'Molluscs'];

    public static function label(string $k): string
    {
        return self::ALLERGENS[$k][0] ?? $k;
    }

    public static function short(string $k): string
    {
        return self::SHORT[$k] ?? ucfirst($k);
    }

    public static function code(string $k): string
    {
        return self::ALLERGENS[$k][1] ?? strtoupper(substr($k, 0, 2));
    }

    /** Keep valid keys only, in the canonical order. */
    public static function clean(?array $keys): array
    {
        $keys = array_flip(array_map('strval', (array) $keys));

        return array_values(array_filter(array_keys(self::ALLERGENS), fn ($k) => isset($keys[$k])));
    }

    public static function names(?array $keys, bool $short = true): string
    {
        return implode(', ', array_map(fn ($k) => $short ? self::short($k) : self::label($k), self::clean($keys)));
    }

    /** Allergens coming in through the recipe ingredients. */
    public static function fromRecipe(MenuItem $m): array
    {
        $m->loadMissing('recipes.inventoryItem');

        return self::clean($m->recipes->flatMap(fn ($r) => (array) $r->inventoryItem?->allergens)->unique()->all());
    }

    /** Everything the dish contains: declared + recipe-derived. */
    public static function effective(MenuItem $m): array
    {
        return self::clean(array_merge((array) $m->allergens, self::fromRecipe($m)));
    }

    /** Recipe allergens the chef has not declared – the menu information may be wrong. */
    public static function undeclared(MenuItem $m): array
    {
        return array_values(array_diff(self::fromRecipe($m), self::clean($m->allergens)));
    }

    /**
     * id => ['contains' => [...], 'may' => [...], 'undeclared' => [...]] for many menu items at once.
     *
     * @param  Collection<int, MenuItem>  $items
     */
    public static function map(Collection $items): array
    {
        $items->loadMissing('recipes.inventoryItem');
        $out = [];
        foreach ($items as $m) {
            $contains = self::effective($m);
            $out[$m->id] = ['contains' => $contains, 'may' => array_values(array_diff(self::clean($m->may_contain), $contains)), 'undeclared' => self::undeclared($m)];
        }

        return $out;
    }

    /**
     * Save the menu-item matrix. Rows that change, or that are ticked as reviewed, are stamped as verified by the user.
     *
     * @param  array<int, array{contains?:array, may?:array}>  $rows
     */
    public static function saveMenu(array $rows, array $verify, User $u): int
    {
        $n = 0;
        DB::transaction(function () use ($rows, $verify, $u, &$n) {
            foreach (MenuItem::whereIn('id', array_keys($rows + $verify))->get() as $m) {
                $contains = self::clean($rows[$m->id]['contains'] ?? []);
                $may = array_values(array_diff(self::clean($rows[$m->id]['may'] ?? []), $contains));
                $changed = $contains !== self::clean($m->allergens) || $may !== self::clean($m->may_contain);
                if (! $changed && empty($verify[$m->id])) {
                    continue;
                }
                $m->allergens = $contains ?: null;
                $m->may_contain = $may ?: null;
                $m->allergens_verified_at = now();
                $m->allergens_verified_by = $u->id;
                $m->save();
                $n++;
            }
        });
        if ($n) {
            ActivityLogger::log('foodsafety', 'allergens', "Allergen information updated / verified for {$n} menu item(s)");
        }

        return $n;
    }

    /** @param  array<int, array>  $rows  inventory item id => allergen keys */
    public static function saveIngredients(array $rows): int
    {
        $n = 0;
        foreach (InventoryItem::whereIn('id', array_keys($rows))->get() as $i) {
            $new = self::clean($rows[$i->id] ?? []);
            if ($new !== self::clean($i->allergens)) {
                $i->allergens = $new ?: null;
                $i->save();
                $n++;
            }
        }
        if ($n) {
            ActivityLogger::log('foodsafety', 'allergens', "Ingredient allergens updated for {$n} stock item(s)");
        }

        return $n;
    }

    /** Which of the guest's allergies this dish contains / may contain. */
    public static function conflicts(MenuItem $m, ?array $guest): array
    {
        $guest = self::clean($guest);
        if (! $guest) {
            return ['contains' => [], 'may' => []];
        }
        $contains = self::effective($m);

        return ['contains' => array_values(array_intersect($contains, $guest)), 'may' => array_values(array_intersect(array_diff(self::clean($m->may_contain), $contains), $guest))];
    }

    public static function flagText(array $c): ?string
    {
        $parts = [];
        if ($c['contains']) {
            $parts[] = 'CONTAINS '.strtoupper(self::names($c['contains']));
        }
        if ($c['may']) {
            $parts[] = 'MAY CONTAIN '.strtoupper(self::names($c['may']));
        }

        return $parts ? mb_substr(implode(' · ', $parts), 0, 160) : null;
    }

    /** Record the guests' allergies on an order; re-flags the lines already sent. */
    public static function setOrderAllergies(PosOrder $o, ?array $keys, ?string $note): PosOrder
    {
        $keys = self::clean($keys);
        DB::transaction(function () use ($o, $keys, $note) {
            $o->allergens = $keys ?: null;
            $o->allergy_note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 160) : null;
            $o->save();
            foreach ($o->items()->with('menuItem.recipes.inventoryItem')->where('is_cancelled', false)->get() as $l) {
                $flag = $l->menuItem ? self::flagText(self::conflicts($l->menuItem, $keys)) : null;
                if ($flag !== $l->allergy_flag) {
                    $l->allergy_flag = $flag;
                    $l->save();
                }
            }
        });
        ActivityLogger::log('pos', 'allergy', $keys ? "Guest allergies on {$o->order_no}: ".self::names($keys) : "Guest allergies cleared on {$o->order_no}", $o);

        return $o;
    }

    /** Menu items needing attention: recipe allergens not declared, or never verified. */
    public static function attention(): array
    {
        $items = MenuItem::with('recipes.inventoryItem')->where('is_available', true)->get();

        return ['undeclared' => $items->filter(fn ($m) => self::undeclared($m))->count(),
            'unverified' => $items->whereNull('allergens_verified_at')->count(), 'total' => $items->count()];
    }
}
