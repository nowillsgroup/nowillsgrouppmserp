<?php
$raw = \Illuminate\Support\Facades\DB::table('reservation_rooms')->select('arrival_date', 'departure_date')->limit(3)->get();
foreach ($raw as $r) { echo 'raw arr=['.$r->arrival_date.'] dep=['.$r->departure_date.']'.PHP_EOL; }
$t = now()->toDateString();
echo 'where  arr='.$t.'  => '.\App\Models\ReservationRoom::where('arrival_date', $t)->count().PHP_EOL;
echo 'whereDate arr='.$t.' => '.\App\Models\ReservationRoom::whereDate('arrival_date', $t)->count().PHP_EOL;
echo 'whereDate dep='.$t.' => '.\App\Models\ReservationRoom::whereDate('departure_date', $t)->count().PHP_EOL;
echo 'casts: '.json_encode((new \App\Models\ReservationRoom)->getCasts()).PHP_EOL;
