<?php

namespace App\Http\Controllers;

use App\Models\FolioTransaction;
use App\Models\PosOrder;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        try {
            foreach ($user->accessibleHotels() as $hotel) {
                Tenant::set($hotel);
                $rows[] = $this->metrics($hotel, $trend);
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
            'group' => $user->group,
            'totals' => [
                'rooms' => $rooms, 'occupied' => $occupied, 'occ' => $rooms ? round($occupied * 100 / $rooms, 1) : 0,
                'in_house' => $rows->sum('in_house'), 'arrivals' => $rows->sum('arrivals'), 'departures' => $rows->sum('departures'),
                'rev_today' => $rows->sum('rev_today'), 'rev_mtd' => $rows->sum('rev_mtd'), 'room_rev_mtd' => $rows->sum('room_rev_mtd'),
                'collections' => $rows->sum('collections'), 'open_pos' => $rows->sum('open_pos'),
            ],
        ]);
    }

    protected function revenue(string $from, string $to): array
    {
        $tx = FolioTransaction::whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->where('is_void', false)->where('type', 'charge')
            ->selectRaw("business_date, SUM(CASE WHEN category IN ('room','extra_bed') THEN amount ELSE 0 END) AS room, SUM(CASE WHEN category NOT IN ('room','extra_bed') THEN amount ELSE 0 END) AS other")
            ->groupBy('business_date')->get()->keyBy(fn ($r) => Carbon::parse($r->business_date)->toDateString());
        $pos = PosOrder::whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->where('status', 'settled')->where('settlement', 'payment')
            ->selectRaw('business_date, SUM(total - tax) AS net')->groupBy('business_date')->get()->keyBy(fn ($r) => Carbon::parse($r->business_date)->toDateString());
        $out = [];
        foreach ($tx->keys()->merge($pos->keys())->unique() as $d) {
            $out[$d] = ['room' => (float) ($tx[$d]->room ?? 0), 'other' => (float) ($tx[$d]->other ?? 0) + (float) ($pos[$d]->net ?? 0)];
        }

        return $out;
    }

    protected function metrics($hotel, array &$trend): array
    {
        $bd = $hotel->business_date->copy();
        $d = $bd->toDateString();
        $rooms = Room::where('is_active', true)->get(['id', 'status', 'hk_status']);
        $occupied = $rooms->where('status', 'occupied')->count();
        $rev = $this->revenue($bd->copy()->startOfMonth()->min($bd->copy()->subDays(13))->toDateString(), $d);
        $mtdFrom = $bd->copy()->startOfMonth()->toDateString();
        $mtd = collect($rev)->filter(fn ($v, $k) => $k >= $mtdFrom);
        foreach ($rev as $day => $v) {
            // align trend by "days before business date" so properties with different dates line up
            $offset = Carbon::parse($day)->diffInDays($bd);
            if ($offset < 14) {
                $trend[13 - $offset] = ($trend[13 - $offset] ?? 0) + $v['room'] + $v['other'];
            }
        }
        $payments = FolioTransaction::whereDate('business_date', $d)->where('is_void', false)->where('type', 'payment')->where('category', '!=', 'city_ledger')->sum('amount')
            + PosOrder::whereDate('business_date', $d)->where('status', 'settled')->where('settlement', 'payment')->sum('total');

        return [
            'hotel' => $hotel, 'business_date' => $bd,
            'rooms' => $rooms->count(), 'occupied' => $occupied, 'occ' => $rooms->count() ? round($occupied * 100 / $rooms->count(), 1) : 0,
            'ooo' => $rooms->whereIn('status', ['out_of_order', 'out_of_service'])->count(), 'dirty' => $rooms->where('hk_status', 'dirty')->count(),
            'in_house' => (int) ReservationRoom::where('status', 'checked_in')->sum(DB::raw('adults + children')),
            'arrivals' => ReservationRoom::where('status', 'reserved')->whereDate('arrival_date', $d)->count(),
            'departures' => ReservationRoom::where('status', 'checked_in')->whereDate('departure_date', '<=', $d)->count(),
            'rev_today' => ($rev[$d]['room'] ?? 0) + ($rev[$d]['other'] ?? 0),
            'rev_mtd' => $mtd->sum(fn ($v) => $v['room'] + $v['other']), 'room_rev_mtd' => $mtd->sum('room'),
            'adr' => $occupied ? ($rev[$d]['room'] ?? 0) / $occupied : 0,
            'collections' => (float) $payments,
            'open_pos' => PosOrder::whereIn('status', ['open', 'billed'])->count(),
        ];
    }
}
