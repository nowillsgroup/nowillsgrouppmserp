<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BanquetEvent;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Guest;
use App\Models\InventoryItem;
use App\Models\MaintenanceRequest;
use App\Models\NightAudit;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomOutage;
use App\Services\LeaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $d = bdate()->toDateString();
        $rooms = Room::where('is_active', true)->get();
        $total = $rooms->count();
        $to = bdate()->copy()->addDay()->toDateString();
        $activeRoomIds = $rooms->pluck('id')->map(fn ($id) => (int) $id)->all();
        $unavailableIds = $rooms->whereIn('status', \App\Services\AvailabilityService::NON_SELLABLE_ROOM_STATUSES)->pluck('id')
            ->merge(array_intersect(LeaseService::blockedRoomIds($d, $to), $activeRoomIds))
            ->merge(RoomOutage::covering($d, $to)->whereIn('room_id', $rooms->pluck('id'))->pluck('room_id'))
            ->map(fn ($id) => (int) $id)->unique()->values();
        $sellableRooms = $rooms->reject(fn ($room) => $unavailableIds->contains((int) $room->id));
        $sellable = $sellableRooms->count();
        $occupied = $sellableRooms->where('status', 'occupied')->count();
        $unavailable = $unavailableIds->count();
        $arrivalsQuery = ReservationRoom::where('status', 'reserved')->whereDate('arrival_date', $d);
        $arrivalCounts = ReservationRoom::whereDate('arrival_date', $d)->whereIn('status', ['reserved', 'checked_in', 'checked_out', 'no_show'])
            ->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');
        $arrivalsCount = (int) ($arrivalCounts['reserved'] ?? 0);
        $arrivalsCheckedIn = (int) ($arrivalCounts['checked_in'] ?? 0) + (int) ($arrivalCounts['checked_out'] ?? 0);
        $arrivalsExpected = $arrivalCounts->sum();
        $arrivals = can_do('frontdesk.view')
            ? $arrivalsQuery->with('reservation.guest', 'roomType', 'room')->orderBy('id')->limit(7)->get()
            : collect();
        $departuresQuery = ReservationRoom::where('status', 'checked_in')->whereDate('departure_date', '<=', $d);
        $departuresCount = (clone $departuresQuery)->count();
        $departures = can_do('frontdesk.view')
            ? $departuresQuery->with('reservation.guest', 'room')->orderBy('departure_date')->orderBy('id')->limit(7)->get()
            : collect();
        // Arrival progress is grouped by due date; checked-out guests count as checked in, while no-shows remain in the expected denominator.

        $canSeeFinance = can_do('folio.view') || can_do('reports.view') || can_do('revenue.view') || can_do('income_audit.view');
        // Aggregate ledger rows in SQL, and skip finance reads entirely for restricted dashboard users.
        $roomRevenue = $otherRevenue = $collections = 0.0;
        if ($canSeeFinance) {
            $tx = FolioTransaction::whereDate('business_date', $d)->where('is_void', false)
                ->select('type', 'category', DB::raw('SUM(amount) as amount_sum'))->groupBy('type', 'category')->get();
            $posToday = PosOrder::whereDate('business_date', $d)->where('status', 'settled')->where('settlement', 'payment')
                ->selectRaw('COALESCE(SUM(total - tax), 0) as net_total, COALESCE(SUM(total), 0) as gross_total')->first();
            $roomRevenue = $tx->where('type', 'charge')->whereIn('category', ['room', 'extra_bed'])->sum('amount_sum');
            $otherRevenue = $tx->where('type', 'charge')->whereNotIn('category', ['room', 'extra_bed'])->sum('amount_sum') + (float) ($posToday->net_total ?? 0);
            $collections = $tx->where('type', 'payment')->where('category', '!=', 'city_ledger')->sum('amount_sum') + (float) ($posToday->gross_total ?? 0);
        }

        $auditQuery = NightAudit::query()->select('business_date', 'occupancy_pct');
        if ($canSeeFinance) {
            $auditQuery->addSelect('room_revenue', 'other_revenue');
        }
        $audits = $auditQuery->orderByDesc('business_date')->take(14)->get()->reverse()->values();
        $sourceMix = can_do('reservations.view')
            ? Reservation::query()->join('business_sources', 'business_sources.id', '=', 'reservations.business_source_id')
                ->where('reservations.created_at', '>=', now()->subDays(30))->whereNotIn('reservations.status', ['cancelled'])
                ->select('business_sources.name', DB::raw('count(*) as c'))->groupBy('business_sources.name')->orderByDesc('c')->get()
            : collect();

        $inHouseGuests = ReservationRoom::where('status', 'checked_in')->sum(DB::raw('adults + children'));

        return view('dashboard', [
            'total' => $total, 'sellable' => $sellable, 'occupied' => $occupied, 'vacant' => $sellableRooms->where('status', 'vacant')->count(), 'unavailable' => $unavailable,
            'dirty' => $rooms->where('hk_status', 'dirty')->count(), 'arrivals' => $arrivals, 'arrivalsCount' => $arrivalsCount,
            'departures' => $departures, 'departuresCount' => $departuresCount,
            'arrivalsCheckedIn' => $arrivalsCheckedIn, 'arrivalsExpected' => $arrivalsExpected, 'inHouseGuests' => $inHouseGuests,
            'occPct' => $sellable ? round($occupied * 100 / $sellable, 1) : 0, 'canSeeFinance' => $canSeeFinance,
            'roomRevenue' => $roomRevenue, 'otherRevenue' => $otherRevenue, 'collections' => $collections,
            'adr' => $occupied ? $roomRevenue / max(1, $occupied) : 0,
            'audits' => $audits, 'sourceMix' => $sourceMix,
            'events' => can_do('banquet.view')
                ? BanquetEvent::with('functions.hall', 'functions.items')->whereDate('end_date', '>=', $d)->whereIn('status', ['definite', 'tentative'])->orderBy('event_date')->take(5)->get()
                : collect(),
            'openTickets' => can_do('maintenance.manage') ? MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->count() : 0,
            'openPos' => can_do('pos.view') ? PosOrder::whereIn('status', ['open', 'billed'])->count() : 0,
            'lowStock' => can_do('inventory.view')
                ? InventoryItem::where('is_active', true)->withSum('movements as stock_qty', 'qty')->get()
                    ->filter(fn ($i) => (float) ($i->stock_qty ?? 0) <= (float) $i->reorder_level)->take(6)
                : collect(),
            'activity' => can_do('logs.view') ? ActivityLog::with('user')->latest('created_at')->take(8)->get() : collect(),
            'newBookings' => can_do('reservations.view') ? Reservation::whereDate('created_at', $d)->count() : 0,
        ]);
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->input('q'));
        if ($q === '') {
            return redirect()->route('dashboard');
        }
        $room = Room::where('room_no', $q)->first();
        if ($room && $room->currentStay) {
            return redirect()->route('reservations.show', $room->currentStay->reservation_id);
        }
        $res = Reservation::where('reservation_no', $q)->orWhere('ota_reference', $q)->first();
        if ($res) {
            return redirect()->route('reservations.show', $res);
        }

        $groups = \App\Services\ReservationDeskService::searchGroups($q);

        return view('search', [
            'q' => $q,
            'guests' => $groups['guests'],
            'reservations' => $groups['reservations'],
            'rooms' => $groups['rooms'],
            'companies' => $groups['companies'],
            'folios' => $groups['folios'],
            'waitlist' => $groups['waitlist'],
        ]);
    }

    public function suggest(Request $request)
    {
        $groups = \App\Services\ReservationDeskService::searchGroups((string) $request->input('q'));

        return response()->json([
            'guests' => $groups['guests']->map(fn ($g) => ['label' => $g->full_name, 'meta' => $g->phone, 'url' => route('guests.show', $g)])->values(),
            'reservations' => $groups['reservations']->map(fn ($r) => ['label' => $r->reservation_no.' · '.($r->guest->full_name ?? ''), 'meta' => ucwords(str_replace('_', ' ', $r->status)), 'url' => route('reservations.show', $r)])->values(),
            'rooms' => $groups['rooms']->map(fn ($r) => ['label' => 'Room '.$r->room_no, 'meta' => ($r->roomType->name ?? '').' · '.$r->status, 'url' => route('reservations.index', ['view' => 'timeline', 'q' => $r->room_no])])->values(),
            'companies' => $groups['companies']->map(fn ($c) => ['label' => $c->name, 'meta' => 'Company', 'url' => route('reservations.index', ['view' => 'list', 'q' => $c->name])])->values(),
            'folios' => $groups['folios']->map(fn ($f) => ['label' => $f->folio_no, 'meta' => $f->guest->full_name ?? $f->status, 'url' => route('folios.show', $f)])->values(),
            'waitlist' => $groups['waitlist']->map(fn ($w) => ['label' => $w->contact_name, 'meta' => 'Waitlist · '.$w->arrival_date->format('d M'), 'url' => route('reservations.index', ['view' => 'waitlist', 'q' => $w->contact_name])])->values(),
        ]);
    }
}
