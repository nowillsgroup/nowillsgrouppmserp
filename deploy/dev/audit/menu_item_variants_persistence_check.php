<?php
/** Exercise nested menu-item variant create/update/delete persistence inside a rollback-only transaction. */

use App\Http\Controllers\MasterController;
use App\Models\Hotel;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\PosMenuVariantService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$hotel = Hotel::where('code', 'NOWILLS')->firstOrFail();
Tenant::set($hotel);
$user = User::where('hotel_id', $hotel->id)->where('username', 'admin')->firstOrFail();
Auth::login($user);
$category = MenuCategory::where('hotel_id', $hotel->id)->firstOrFail();
$name = 'Variant persistence check '.bin2hex(random_bytes(4));
$controller = app(MasterController::class);

DB::beginTransaction();
try {
    $base = [
        'code' => null, 'name' => $name, 'menu_category_id' => $category->id,
        'outlet_id' => null, 'kot_printer_id' => null, 'price' => '42.50',
        'tax_rate' => '0', 'food_type' => 'veg', 'description' => null,
        'is_available' => '1', 'variants_present' => '1',
        'variants' => [
            ['name' => 'Chips', 'rate' => '10.50', 'cost' => '3.25'],
            ['name' => 'Side salad', 'rate' => '5.00', 'cost' => '2.00'],
        ],
    ];
    $controller->store(Request::create('/masters/menu-items', 'POST', $base), 'menu-items');
    $item = MenuItem::where('name', $name)->firstOrFail();
    $variants = $item->variants;
    if ($variants->count() !== 2 || $variants[0]->name !== 'Chips' || (float) $variants[0]->rate !== 10.5 || (float) $variants[0]->cost !== 3.25 || $variants[1]->name !== 'Side salad') {
        throw new RuntimeException('Create did not persist both side-dish variants with their rate and cost.');
    }
    [$snapshot, $sideDishRate] = PosMenuVariantService::snapshot($item, $variants->pluck('id')->all(), 0);
    if (count($snapshot) !== 2 || $snapshot[0]['type'] !== 'side_dish' || $snapshot[0]['name'] !== 'Chips' || (float) $snapshot[0]['price_delta'] !== 10.5 || $sideDishRate !== 15.5) {
        throw new RuntimeException('POS side-dish snapshot did not preserve the selected names and rates.');
    }
    $otherData = array_diff_key($base, ['variants_present' => true, 'variants' => true]);
    $other = MenuItem::create(array_merge($otherData, ['name' => $name.' unrelated']));
    $foreignVariantRejected = false;
    try {
        PosMenuVariantService::snapshot($other, $variants->pluck('id')->all(), 1);
    } catch (\Illuminate\Validation\ValidationException) {
        $foreignVariantRejected = true;
    }
    if (! $foreignVariantRejected) {
        throw new RuntimeException('A side dish from another menu item was accepted at POS.');
    }

    // Rate and cost are optional: an empty rate/cost (null after the request middleware) saves as 0,
    // which the POS reads as "included in the item price" rather than inventing a charge.
    $blank = $base;
    $blank['name'] = $name.' blank rate';
    $blank['variants'] = [['name' => 'Garden salad', 'rate' => null, 'cost' => null]];
    $controller->store(Request::create('/masters/menu-items', 'POST', $blank), 'menu-items');
    $blankVariant = MenuItem::where('name', $blank['name'])->firstOrFail()->variants->first();
    if (! $blankVariant || (float) $blankVariant->rate !== 0.0 || (float) $blankVariant->cost !== 0.0) {
        throw new RuntimeException('A side dish submitted without a rate and cost was not saved at zero.');
    }

    $updated = $base;
    $updated['variants'] = [['name' => 'Fried plantain', 'rate' => '8.75', 'cost' => '4.10']];
    $controller->update(Request::create('/masters/menu-items/'.$item->id, 'PUT', $updated), 'menu-items', $item->id);
    $item->refresh();
    $variants = $item->variants;
    if ($variants->count() !== 1 || $variants[0]->name !== 'Fried plantain' || (float) $variants[0]->rate !== 8.75 || (float) $variants[0]->cost !== 4.1) {
        throw new RuntimeException('Update did not replace the variant list with the submitted rate and cost.');
    }

    $updated['variants'] = [];
    $controller->update(Request::create('/masters/menu-items/'.$item->id, 'PUT', $updated), 'menu-items', $item->id);
    if ($item->variants()->count() !== 0) {
        throw new RuntimeException('Removing all variants did not clear the item’s side-dish list.');
    }

    echo "Menu-item variant create/update/remove persistence passed; all test writes will be rolled back.\n";
} finally {
    DB::rollBack();
    Auth::logout();
}
