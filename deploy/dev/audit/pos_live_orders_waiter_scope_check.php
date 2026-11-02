<?php

/** Check the real Restaurant Waiter role can see matching real waiter orders; no records are written. */

use App\Http\Controllers\PosController;
use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$hotel = Hotel::where('code', 'NOWILLS')->firstOrFail();
Tenant::set($hotel);
$role = Role::where('hotel_id', $hotel->id)->where('name', 'Restaurant Waiter')->firstOrFail();
$waiter = (new User)->forceFill([
    'id' => -999999, 'hotel_id' => $hotel->id, 'role_id' => $role->id,
    'name' => 'Sunil Yadav', 'username' => 'pos-waiter-scope-audit',
]);
if (! $waiter->hasPermission('pos.view') || ! $waiter->hasPermission('pos.order') || $waiter->hasPermission('pos.settle')) {
    throw new RuntimeException('The Restaurant Waiter permission profile is not as expected.');
}

$guard = Auth::guard();
$originalUser = $guard->user();
try {
    $guard->setUser($waiter);
    $controller = app(PosController::class);
    $page = $controller->liveOrders(Request::create('/pos/live-orders', 'GET'));
    if (($page->getData()['scope'] ?? null) !== 'mine') {
        throw new RuntimeException('Restaurant Waiter does not default to My orders.');
    }
    $response = $controller->liveOrdersFeed(Request::create('/pos/live-orders/feed', 'GET'));
    $feed = $response->getData(true);
    $orders = collect($feed['orders'] ?? []);
    if ($orders->isEmpty() || $orders->contains(function ($order) {
        $waiterName = trim((string) ($order['waiter'] ?? ''));
        $firstName = preg_split('/\\s+/', $waiterName, -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
        return mb_strtolower($firstName) !== 'sunil';
    })) {
        throw new RuntimeException('The waiter did not receive only matching live orders by first-name assignment.');
    }
    if (! $feed['can_serve']) {
        throw new RuntimeException('The Restaurant Waiter cannot serve ready items from the live board.');
    }
    echo 'PASS Restaurant Waiter defaults to My orders and sees '.count($feed['orders'])." matching real live order(s); no records were written.\n";
} finally {
    if ($originalUser) {
        $guard->setUser($originalUser);
    } else {
        $guard->forgetUser();
    }
}
