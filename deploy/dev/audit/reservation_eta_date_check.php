<?php

use App\Services\ReservationDeskService;
use Illuminate\Support\Carbon;

$zone = 'Africa/Accra';
$cases = [
    ['ETA passed before midnight', '2026-10-29', '23:30', '2026-10-29 23:31', true],
    ['ETA passed after midnight on arrival date', '2026-10-29', '23:30', '2026-10-30 01:00', true],
    ['arrival-date ETA has not passed yet', '2026-10-30', '23:30', '2026-10-30 01:00', false],
    ['ETA exactly now is not yet past', '2026-10-30', '12:00', '2026-10-30 12:00', false],
    ['malformed ETA is ignored', '2026-10-30', '25:00', '2026-10-30 23:00', false],
    ['missing ETA is ignored', '2026-10-30', null, '2026-10-30 23:00', false],
];

foreach ($cases as [$label, $arrival, $eta, $now, $expected]) {
    $actual = ReservationDeskService::etaHasPassed(
        Carbon::parse($arrival, $zone),
        $eta,
        Carbon::parse($now, $zone),
    );
    if ($actual !== $expected) {
        throw new RuntimeException("FAIL {$label}: expected ".($expected ? 'true' : 'false').', got '.($actual ? 'true' : 'false'));
    }
    echo "PASS {$label}".PHP_EOL;
}
