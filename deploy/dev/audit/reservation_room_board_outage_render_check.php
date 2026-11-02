<?php

// Render multiple synthetic Room Board blocks without writing outage records.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/reservation_room_board_outage_render_check.php';"

use App\Models\Hotel;
use App\Models\User;
use App\Services\ReservationDeskService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;

Tenant::set(Hotel::firstOrFail());
Auth::login(User::where('username', 'admin')->firstOrFail());
$page = ReservationDeskService::page(Request::create('/reservations', 'GET', [
    'view' => 'board',
    'date' => bdate()->toDateString(),
]));
$page['currentHotel'] = hotel();
$page['errors'] = new ViewErrorBag();
$room = $page['rooms']->first();
if (! $room) {
    throw new RuntimeException('No active room is available for the in-memory room-block render check.');
}

$from = $page['date']->copy()->startOfDay();
$turnoverRoom = $page['rooms']->first(fn ($candidate) => $page['byRoom']->get($candidate->id, collect())->isNotEmpty());
if (! $turnoverRoom) {
    throw new RuntimeException('No assigned stay is available for the in-memory turnover render check.');
}
$sourceStay = $page['byRoom']->get($turnoverRoom->id)->first();
$stayover = clone $sourceStay;
$stayover->status = 'checked_in';
$stayover->arrival_date = $from->copy()->subDay();
$stayover->departure_date = $from->copy()->addDay();
$dueOut = clone $sourceStay;
$dueOut->status = 'checked_in';
$dueOut->arrival_date = $from->copy()->subDay();
$dueOut->departure_date = $from->copy();
$page['byRoom']->put($turnoverRoom->id, collect([$stayover, $dueOut]));
$activeBlock = (object) [
    'room_id' => $room->id,
    'starts_on' => $from->copy(),
    'ends_on' => $from->copy()->addDays(2),
    'reason' => 'maintenance',
];
$upcomingBlock = (object) [
    'room_id' => $room->id,
    'starts_on' => $from->copy()->addDays(3),
    'ends_on' => $from->copy()->addDays(5),
    'reason' => 'renovation',
];

// Deliberately reverse their input order: the Room Board should sort blocks by start date.
$page['outages'] = collect([$room->id => collect([$upcomingBlock, $activeBlock])]);
$html = view('reservations.index', $page)->render();
$activeReturn = $from->copy()->addDays(2);
$upcomingStart = $from->copy()->addDays(3);
$upcomingReturn = $from->copy()->addDays(5);
$expected = [
    'class="room-board-block is-active"',
    'Active · Maintenance',
    'Starts <time datetime="'.$from->toDateString().'">'.$from->format('d M').'</time> · returns <time datetime="'.$activeReturn->toDateString().'">'.$activeReturn->format('d M Y').'</time>',
    'class="room-board-block is-upcoming"',
    'Upcoming · Renovation',
    'class="room-board-turnover"',
    'Due out · <time datetime="'.$from->toDateString().'">'.$from->format('d M').'</time>',
    'Starts <time datetime="'.$upcomingStart->toDateString().'">'.$upcomingStart->format('d M').'</time> · returns <time datetime="'.$upcomingReturn->toDateString().'">'.$upcomingReturn->format('d M Y').'</time>',
];
foreach ($expected as $needle) {
    if (! str_contains($html, $needle)) {
        throw new RuntimeException("Room Board block render is missing: {$needle}");
    }
}
$activeAt = strpos($html, 'Active · Maintenance');
$upcomingAt = strpos($html, 'Upcoming · Renovation');
if ($activeAt === false || $upcomingAt === false || $activeAt >= $upcomingAt) {
    throw new RuntimeException('Room Board block entries are not ordered by their start date.');
}
if (substr_count($html, 'class="room-board-block ') !== 2) {
    throw new RuntimeException('Room Board did not render exactly two block entries for the test room.');
}

echo 'Rendered a same-day due-out beside a continuing stay, plus active and upcoming blocks in start-date order; no records were written.'.PHP_EOL;
