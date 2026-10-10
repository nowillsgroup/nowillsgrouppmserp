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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $d = bdate()->toDateString();
        $rooms = Room::where('is_active', true)->get();
        $total = $rooms->count();
        $occupied = $rooms->where('status', 'occupied')->count();
        $ooo = $rooms->whereIn('status', ['out_of_order', 'out_of_service'])->count();
        $arrivals = ReservationRoom::with('reservation.guest', 'roomType', 'room')->where('status', 'reserved')->where('arrival_date', $d)->get();
        $departures = ReservationRoom::with('reservation.guest', 'room')->where('status', 'checked_in')->where('departure_date', '<=', $d)->get();
        $checkedOutToday = ReservationRoom::where('status', 'checked_out')->whereDate('checked_out_at', today())->count();
        $checkedInToday = ReservationRoom::where('status', 'checked_in')->whereDate('checked_in_at', today())->count();

        $tx = FolioTransaction::where('business_date', $d)->where('is_void', false)->get();
        $posToday = PosOrder::where('business_date', $d)->where('status', 'settled')->where('settlement', 'payment')->get();
        $roomRevenue = $tx->where('type', 'charge')->whereIn('category', ['room', 'extra_bed'])->sum('amount');
        $otherRevenue = $tx->where('type', 'charge')->whereNotIn('category', ['room', 'extra_bed'])->sum('amount') + $posToday->sum(fn ($o) => $o->total - $o->tax);
        $collections = $tx->where('type', 'payment')->where('category', '!=', 'city_ledger')->sum('amount') + $posToday->sum('total');

        $audits = NightAudit::orderByDesc('business_date')->take(14)->get()->reverse()->values();
        $sourceMix = Reservation::query()->join('business_sources', 'business_sources.id', '=', 'reservations.business_source_id')
            ->where('reservations.created_at', '>=', now()->subDays(30))->whereNotIn('reservations.status', ['cancelled'])
            ->select('business_sources.name', DB::raw('count(*) as c'))->groupBy('business_sources.name')->orderByDesc('c')->get();

        $inHouseGuests = ReservationRoom::where('status', 'checked_in')->sum(DB::raw('adults + children'));

        return view('dashboard', [
            'total' => $total, 'occupied' => $occupied, 'vacant' => $rooms->where('status', 'vacant')->count(), 'ooo' => $ooo,
            'dirty' => $rooms->where('hk_status', 'dirty')->count(), 'arrivals' => $arrivals, 'departures' => $departures,
            'checkedOutToday' => $checkedOutToday, 'checkedInToday' => $checkedInToday, 'inHouseGuests' => $inHouseGuests,
            'occPct' => $total ? round($occupied * 100 / $total, 1) : 0,
            'roomRevenue' => $roomRevenue, 'otherRevenue' => $otherRevenue, 'collections' => $collections,
            'adr' => $occupied ? $roomRevenue / max(1, $occupied) : 0,
            'audits' => $audits, 'sourceMix' => $sourceMix,
            'events' => BanquetEvent::with('functions.hall', 'functions.items')->whereDate('end_date', '>=', $d)->whereIn('status', ['definite', 'tentative'])->orderBy('event_date')->take(5)->get(),
            'openTickets' => MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->count(),
            'openPos' => PosOrder::whereIn('status', ['open', 'billed'])->count(),
            'lowStock' => InventoryItem::where('is_active', true)->get()->filter(fn ($i) => $i->stock() <= $i->reorder_level)->take(6),
            'activity' => ActivityLog::with('user')->latest('created_at')->take(8)->get(),
            'newBookings' => Reservation::whereDate('created_at', today())->count(),
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
