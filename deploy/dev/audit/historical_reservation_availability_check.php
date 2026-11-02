<?php

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomOutage;
use App\Services\AvailabilityService;
use App\Support\Tenant;
use Illuminate\Support\Carbon;

Tenant::set(Hotel::firstOrFail());
$date = bdate()->copy()->subDay()->startOfDay();
$nextDay = $date->copy()->addDay();
$room = AvailabilityService::historicalReservationRooms($date)->first();
if (! $room) {
    throw new RuntimeException('No historically free active room is available for the synthetic availability check.');
}

DB::beginTransaction();
try {
    $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
    $room->update(['status' => 'out_of_order']);

    $historicalIgnoresCurrentFlag = AvailabilityService::historicalReservationRooms($date)->contains('id', $room->id);
    $operationalAvailabilityExcludesCurrentFlag = ! AvailabilityService::reservationRooms($date, $nextDay)->contains('id', $room->id);

    RoomOutage::create([
        'room_id' => $room->id,
        'starts_on' => $date->toDateString(),
        'ends_on' => $date->copy()->addDays(2)->toDateString(),
        'reason' => 'maintenance',
        'status' => 'released',
        'released_at' => $nextDay->copy()->setTime(10, 0),
    ]);

    $releasedOutageCountsOnPastNight = AvailabilityService::historicalOutageRoomIds($date)->contains($room->id);
    $releaseDateIsNotCounted = ! AvailabilityService::historicalOutageRoomIds($nextDay)->contains($room->id);
    $datedOutageRemovesHistoricalAvailability = ! AvailabilityService::historicalReservationRooms($date)->contains('id', $room->id);

    $checks = [
        'historical availability ignores a room\'s current out-of-order flag' => $historicalIgnoresCurrentFlag,
        'operational assignment still excludes the currently out-of-order room' => $operationalAvailabilityExcludesCurrentFlag,
        'released outage remains counted on a past covered night' => $releasedOutageCountsOnPastNight,
        'outage release date is not counted as an unavailable night' => $releaseDateIsNotCounted,
        'dated historical outage removes the room from historical availability' => $datedOutageRemovesHistoricalAvailability,
    ];
    foreach ($checks as $label => $passed) {
        echo ($passed ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
        if (! $passed) {
            throw new RuntimeException('Historical reservation availability check failed: '.$label);
        }
    }
} finally {
    DB::rollBack();
}
