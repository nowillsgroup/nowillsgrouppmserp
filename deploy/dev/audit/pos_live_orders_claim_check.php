<?php

/** Exercise claiming and waiter visibility on one real unassigned POS order; every write rolls back. */

use App\Http\Controllers\PosController;
use App\Models\Hotel;
use App\Models\PosOrder;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$hotel = Hotel::where('code', 'NOWILLS')->firstOrFail();
Tenant::set($hotel);
$user = User::where('hotel_id', $hotel->id)->where('username', 'admin')->firstOrFail();
$unassigned = PosOrder::whereIn('status', ['open', 'billed'])->where(fn ($q) => $q->whereNull('steward')->orWhereRaw("TRIM(COALESCE(steward, '')) = ''"))->oldest('id')->firstOrFail();
$guard = Auth::guard();
$originalUser = $guard->user();
$controller = app(PosController::class);
DB::beginTransaction();

try {
    Auth::login($user);
    $claimRequest = Request::create('/pos/orders/'.$unassigned->id.'/claim', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $claimResponse = $controller->claim($claimRequest, $unassigned);
    $claimData = $claimResponse->getData(true);
    if ($claimResponse->getStatusCode() !== 200 || ($claimData['waiter'] ?? null) !== $user->name) {
        throw new RuntimeException('The claim action did not assign the live order to the current staff member.');
    }

    $feedRequest = Request::create('/pos/live-orders/feed?scope=mine', 'GET', ['scope' => 'mine'], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $feed = $controller->liveOrdersFeed($feedRequest)->getData(true);
    $visible = collect($feed['orders'] ?? [])->contains(fn ($row) => (int) ($row['id'] ?? 0) === (int) $unassigned->id);
    if (! $visible) {
        throw new RuntimeException('The newly claimed order did not appear in the waiter’s My orders feed.');
    }

    echo "PASS claimed order appears in My orders for {$user->name}; test writes will be rolled back.\n";
} finally {
    DB::rollBack();
    if ($originalUser) {
        $guard->setUser($originalUser);
    } else {
        $guard->forgetUser();
    }
}

if (filled(PosOrder::find($unassigned->id)?->steward)) {
    throw new RuntimeException('The rollback-only waiter claim left a steward assignment in the database.');
}

echo "PASS rollback restored the original unassigned order.\n";
