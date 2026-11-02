<?php

// Verify room-profile folio data is both query- and render-gated by folio.view.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/reservation_room_page_permission_render_check.php';"

use App\Http\Controllers\ReservationDeskController;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

Tenant::set(Hotel::where('code', 'NOWILLS')->firstOrFail());
$roomId = 1;
$request = Request::create('/reservations/rooms/'.$roomId.'/card', 'GET');
$cro = User::where('username', 'cro')->firstOrFail();
if (! $cro->hasPermission('reservations.view') || $cro->hasPermission('folio.view')) {
    throw new RuntimeException('The CRO fixture must have reservations.view without folio.view.');
}
Auth::login($cro);
$view = (new ReservationDeskController())->roomCard($request, Room::findOrFail($roomId));
if (! $view instanceof View) {
    throw new RuntimeException('Room profile did not return a view for the CRO.');
}
$view->with('currentHotel', hotel())->with('errors', new ViewErrorBag());
$html = $view->render();
if (str_contains($html, 'Folio balance')) {
    throw new RuntimeException('Room profile exposed a folio balance label without folio.view.');
}
$stay = $view->getData()['room']->currentStay;
if ($stay && $stay->relationLoaded('folio')) {
    throw new RuntimeException('Room profile queried the current stay folio without folio.view.');
}

$admin = User::where('username', 'admin')->firstOrFail();
if (! $admin->hasPermission('folio.view')) {
    throw new RuntimeException('Admin fixture must have folio.view.');
}
Auth::login($admin);
$adminView = (new ReservationDeskController())->roomCard($request, Room::findOrFail($roomId));
$adminView->with('currentHotel', hotel())->with('errors', new ViewErrorBag());
$adminHtml = $adminView->render();
if (! str_contains($adminHtml, 'Folio balance')) {
    throw new RuntimeException('Room profile should show the folio summary to a user with folio.view.');
}

echo 'CRO sees no folio balance and triggers no folio relation load; admin sees the folio summary.'.PHP_EOL;
