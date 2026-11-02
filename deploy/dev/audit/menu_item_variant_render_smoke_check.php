<?php
/** Render the real POS bill and KOT with a temporary side-dish snapshot, then roll back all writes. */

use App\Http\Controllers\PosController;
use App\Models\Hotel;
use App\Models\PosOrder;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;

$hotel = Hotel::where('code', 'NOWILLS')->firstOrFail();
Tenant::set($hotel);
view()->share('currentHotel', $hotel);
view()->share('errors', new ViewErrorBag());
$user = User::where('hotel_id', $hotel->id)->where('username', 'admin')->firstOrFail();
Auth::login($user);
$order = PosOrder::with('items')->where('hotel_id', $hotel->id)
    ->whereHas('items', fn ($q) => $q->whereNotNull('kot_no')->where('is_cancelled', false))
    ->firstOrFail();
$line = $order->items->first(fn ($item) => $item->kot_no && ! $item->is_cancelled);
$kot = (int) $line->kot_no;
$controller = app(PosController::class);

DB::beginTransaction();
try {
    $line->modifiers = [
        ['type' => 'side_dish', 'name' => 'Render audit side dish', 'price_delta' => 3.5],
        ['name' => 'Render audit sauce', 'price_delta' => 1.0],
    ];
    $line->save();

    $billHtml = $controller->printBill($order)->render();
    $kotHtml = $controller->printKot(Request::create('/pos/orders/'.$order->id.'/kot/'.$kot), $order, $kot)->render();
    foreach (['bill' => $billHtml, 'KOT' => $kotHtml] as $document => $html) {
        $plainText = html_entity_decode(strip_tags($html));
        if (! str_contains($plainText, 'Side dishes: Render audit side dish')) {
            throw new RuntimeException("The rendered {$document} did not label the side dish.");
        }
    }
    if (! str_contains(strip_tags($billHtml), 'Render audit sauce') || ! str_contains(strip_tags($kotHtml), 'Render audit sauce')) {
        throw new RuntimeException('Existing modifier labels were not preserved alongside the side dish.');
    }

    echo "Rendered POS bill and KOT show the temporary side dish alongside existing options; all test writes will be rolled back.\n";
} finally {
    DB::rollBack();
    Auth::logout();
}
