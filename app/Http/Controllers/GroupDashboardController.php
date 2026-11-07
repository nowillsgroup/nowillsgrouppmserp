<?php

namespace App\Http\Controllers;

use App\Services\GroupMetricsService;
use App\Support\Tenant;
use Illuminate\Http\Request;

/**
 * Consolidated multi-property dashboard for group administrators.
 * Each property is evaluated inside its own tenant scope and its own business date.
 */
class GroupDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $current = Tenant::hotel();
        $rows = [];
        $trend = [];
        $closed = [];
        $metrics = app(GroupMetricsService::class);
        try {
            foreach ($user->accessibleHotels() as $hotel) {
                // v10.7 a property licensed for no modules is shut: nobody can work in it and nothing
                // new can be posted there. Its rooms must not sit in the group's occupancy denominator
                // and its stale month-to-date must not be added to revenue as though it were trading,
                // so it is left out of the consolidated numbers — and named underneath them.
                if ($hotel->licenceIsEmpty()) {
                    $closed[] = $hotel;
                    continue;
                }
                // v10.9 the arithmetic lives in GroupMetricsService, because the consolidated report
                // reads the same figures and two copies would drift.
                $rows[] = $metrics->row($hotel, $trend);
            }
        } finally {
            Tenant::set($current);
        }
        $rows = collect($rows);
        $rooms = $rows->sum('rooms');
        $occupied = $rows->sum('occupied');
        ksort($trend);

        return view('group.dashboard', [
            'rows' => $rows, 'trend' => $trend,
            'closed' => collect($closed),
            'group' => $user->group,
            'totals' => [
                'rooms' => $rooms, 'occupied' => $occupied, 'occ' => $rooms ? round($occupied * 100 / $rooms, 1) : 0,
                'in_house' => $rows->sum('in_house'), 'arrivals' => $rows->sum('arrivals'), 'departures' => $rows->sum('departures'),
                'rev_today' => $rows->sum('rev_today'), 'rev_mtd' => $rows->sum('rev_mtd'), 'room_rev_mtd' => $rows->sum('room_rev_mtd'),
                'collections' => $rows->sum('collections'), 'open_pos' => $rows->sum('open_pos'),
            ],
        ]);
    }
}
