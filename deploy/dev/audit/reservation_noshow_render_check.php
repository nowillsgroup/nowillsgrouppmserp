<?php

use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationDeskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;

$operator = User::where('username', 'admin')->firstOrFail();
Auth::login($operator);
$reservation = Reservation::whereNotIn('status', ['cancelled', 'no_show', 'checked_out'])
    ->whereHas('rooms', fn ($query) => $query->where('status', 'reserved'))
    ->orderBy('arrival_date')->first();
if (! $reservation) {
    throw new RuntimeException('No reserved development booking is available for a rollback-only no-show render check.');
}

DB::beginTransaction();
try {
    $reservation->rooms()->where('status', 'reserved')->update(['status' => 'no_show']);
    $reservation->refresh();
    $reservation->update(['status' => 'no_show']);
    $request = Request::create('/reservations', 'GET', [
        'view' => 'noshow', 'date' => $reservation->arrival_date->toDateString(), 'days' => 1,
    ]);
    $data = ReservationDeskService::page($request);
    $data['currentHotel'] = Hotel::findOrFail($operator->hotel_id);
    $data['errors'] = new ViewErrorBag();
    $html = view('reservations.index', $data)->render();
    foreach (['data-view="noshow"', 'No-show booking register', $reservation->reservation_no, 'Review booking'] as $needle) {
        if (! str_contains($html, $needle)) {
            throw new RuntimeException("No-show render check failed: missing {$needle}");
        }
    }
    echo "No-show populated view rendered reservation {$reservation->reservation_no} with room stays; database writes rolled back.\n";
} finally {
    DB::rollBack();
}
