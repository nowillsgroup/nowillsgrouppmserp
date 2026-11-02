<?php

// Render an upcoming-room reservation row against the real room profile without writes.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/reservation_room_page_render_check.php';"

use App\Http\Controllers\ReservationDeskController;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

Tenant::set(Hotel::where('code', 'NOWILLS')->firstOrFail());
Auth::login(User::where('username', 'admin')->firstOrFail());
$room = Room::findOrFail(1);
$request = Request::create('/reservations/rooms/'.$room->id.'/card', 'GET');
$controller = new ReservationDeskController();

$emptyView = $controller->roomCard($request, $room);
if (! $emptyView instanceof View) {
    throw new RuntimeException('Room profile controller did not return a view.');
}
$emptyView->with('currentHotel', hotel())->with('errors', new ViewErrorBag())->with('upcomingStays', collect());
$emptyHtml = $emptyView->render();
if (! str_contains($emptyHtml, 'No upcoming reserved stay is currently assigned to this room.')) {
    throw new RuntimeException('Room profile empty state for upcoming stays did not render.');
}

$source = ReservationRoom::with(['reservation.guest', 'reservation.source'])
    ->whereNotNull('room_id')->whereHas('reservation')->firstOrFail();
$upcoming = clone $source;
$arrival = bdate()->copy()->addDays(14);
$departure = $arrival->copy()->addDays(2);
$upcoming->status = 'reserved';
$upcoming->arrival_date = $arrival;
$upcoming->departure_date = $departure;
$reservation = clone $source->reservation;
$reservation->status = 'confirmed';
$upcoming->setRelation('reservation', $reservation);
$guestName = $reservation->guest?->full_name ?? 'Guest profile unavailable';
$reservationNumber = $reservation->reservation_no;

$populatedView = $controller->roomCard($request, $room);
$populatedView->with('currentHotel', hotel())->with('errors', new ViewErrorBag())->with('upcomingStays', collect([$upcoming]));
$html = $populatedView->render();
$expected = [
    'class="room-upcoming-row"',
    'datetime="'.$arrival->toDateString().'"',
    'datetime="'.$departure->toDateString().'"',
    e($guestName),
    e($reservationNumber),
    'Confirmed',
];
foreach ($expected as $needle) {
    if (! str_contains($html, $needle)) {
        throw new RuntimeException("Room profile upcoming-stay render is missing: {$needle}");
    }
}

if (ReservationRoom::whereKey($source->id)->doesntExist()) {
    throw new RuntimeException('The source reservation-room record disappeared during the in-memory render check.');
}

echo 'Rendered the empty and upcoming-stay room-profile states in memory without writing reservation records.'.PHP_EOL;
