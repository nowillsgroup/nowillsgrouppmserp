<?php

/**
 * Render a real connected-channel detail page as Sales & Marketing, a role with channels.view but not reservations.view.
 * This is read-only and checks that OTA references remain visible without adding unauthorized reservation drill-downs.
 * Run with: TINKER='require "/app/deploy/dev/audit/channel_manager_reservation_permission_render_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Http\Controllers\ChannelConnectionController;
use App\Models\ChannelConnection;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

$hotel = Hotel::where('code', 'NOWILLS')->firstOrFail();
Tenant::set($hotel);
$user = User::where('hotel_id', $hotel->id)->where('username', 'marketing')->firstOrFail();
if (! $user->hasPermission('channels.view') || $user->hasPermission('reservations.view')) {
    throw new RuntimeException('The seeded Sales & Marketing role no longer represents this permission boundary.');
}
Auth::login($user);
View::share('errors', new ViewErrorBag);
View::share('currentHotel', $hotel);
$connection = ChannelConnection::where('hotel_id', $hotel->id)->where('code', 'BDC')->first()
    ?? ChannelConnection::where('hotel_id', $hotel->id)->orderBy('id')->firstOrFail();
if (! Reservation::where('channel_connection_id', $connection->id)->exists()) {
    throw new RuntimeException('The selected demo connection has no linked reservations to verify.');
}
$html = app(ChannelConnectionController::class)->show($connection)->render();
if (! str_contains($html, 'id="connMsgs"')) {
    throw new RuntimeException('The real channel detail did not render its recent-message table.');
}
if (preg_match('/<a\b[^>]*href="[^"]*\/reservations\/\d+[^\"]*"[^>]*>/i', $html, $match)) {
    throw new RuntimeException('A user without reservations.view can follow a reservation-detail link: '.$match[0]);
}
echo "PASS: {$user->role->name} sees the connected-channel detail and OTA refs, but no reservation-detail links (read-only render).".PHP_EOL;
