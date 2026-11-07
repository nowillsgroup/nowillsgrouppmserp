<?php

namespace App\Services;

use App\Models\FolioTransaction;
use App\Models\Hotel;
use App\Models\PosOrder;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v10.9 one property's consolidated figures, read inside that property's own tenant scope and on its
 * own business date, and restored afterwards.
 *
 * These numbers used to live inside GroupDashboardController, which meant the only way to see them
 * was the one screen that drew them. They are a service now because two readers need the same
 * arithmetic: the consolidated dashboard and the consolidated report. Two copies would drift, and the
 * report — the one that can be printed, exported and scheduled — is exactly where a drift would be
 * believed.
 */
class GroupMetricsService
{
    /**
     * One property's figures. The optional trend collects net revenue per "days before the business
     * date" across calls, so properties whose business dates differ still line up on one chart.
     *
     * @param  array<int, float>|null  $trend
     */
    public function row(Hotel $hotel, ?array &$trend = null): array
    {
        $previous = Tenant::hotel();

        try {
            Tenant::set($hotel);

            return $this->compute($hotel, $trend);
        } finally {
            Tenant::set($previous);
        }
    }

    /**
     * @param  array<int, float>|null  $trend
     */
    protected function compute(Hotel $hotel, ?array &$trend): array
    {
        $bd = $hotel->business_date->copy();
        $d = $bd->toDateString();
        $rooms = Room::where('is_active', true)->get(['id', 'status', 'hk_status']);
        $totalRooms = $rooms->count();
        $sellable = AvailabilityService::sellableRoomCounts($bd, 1, $rooms)[$d] ?? 0;
        $occupied = $rooms->where('status', 'occupied')->count();
        $rev = $this->revenue($bd->copy()->startOfMonth()->min($bd->copy()->subDays(13))->toDateString(), $d);
        $mtdFrom = $bd->copy()->startOfMonth()->toDateString();
        $mtd = collect($rev)->filter(fn ($v, $k) => $k >= $mtdFrom);
        foreach ($rev as $day => $v) {
            // align the trend by "days before business date" so properties with different dates line up
            $offset = Carbon::parse($day)->diffInDays($bd);
            if ($offset < 14 && $trend !== null) {
                $trend[13 - $offset] = ($trend[13 - $offset] ?? 0) + $v['room'] + $v['other'];
            }
        }
        $payments = FolioTransaction::whereDate('business_date', $d)->where('is_void', false)->where('type', 'payment')->where('category', '!=', 'city_ledger')->sum('amount')
            + PosOrder::whereDate('business_date', $d)->where('status', 'settled')->where('settlement', 'payment')->sum('total');

        return [
            'hotel' => $hotel, 'business_date' => $bd,
            'rooms' => $sellable, 'total_rooms' => $totalRooms, 'occupied' => $occupied, 'occ' => $sellable ? round($occupied * 100 / $sellable, 1) : 0,
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

    /** Net revenue per business date in the span: rooms, everything else, and the two together. */
    public function revenue(string $from, string $to): array
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

    /**
     * How the property is licensed, in the words the report prints beside its numbers: a narrowed
     * property is still trading, so its figures look low against the group and the reader must know
     * why. A property licensed for nothing is not in the rows at all — nothing can be posted there.
     */
    public function licenceLabel(Hotel $hotel): string
    {
        if ($hotel->licenceIsEmpty()) {
            return 'Nothing licensed';
        }

        if ($hotel->followsGroupLicence()) {
            return 'Group licence';
        }

        $keep = LicenceService::licenceOfHotel($hotel);
        $group = LicenceService::licenceOf(LicenceService::groupOf($hotel));
        $catalogue = count(LicenceService::catalogue());

        return ($keep === null ? $catalogue : count($keep)).' of '.($group === null ? $catalogue : count($group)).' modules';
    }
}
