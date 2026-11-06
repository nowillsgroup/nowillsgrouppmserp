<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\BanquetEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Guest;
use App\Models\HousekeepingTask;
use App\Models\InventoryItem;
use App\Models\MaintenanceRequest;
use App\Models\NightAudit;
use App\Models\PosOrder;
use App\Models\PurchaseOrder;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomOutage;
use App\Services\FleetService;
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
        $dirty = $rooms->where('hk_status', 'dirty')->count();
        $arrivalsQuery = ReservationRoom::where('status', 'reserved')->whereDate('arrival_date', $d);
        $canSeeFrontDeskMetrics = can_do('frontdesk.view') || can_do('reservations.view');
        $arrivalCounts = $canSeeFrontDeskMetrics
            ? ReservationRoom::whereDate('arrival_date', $d)->whereIn('status', ['reserved', 'checked_in', 'checked_out', 'no_show'])
                ->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status')
            : collect();
        $arrivalsCount = (int) ($arrivalCounts['reserved'] ?? 0);
        $arrivalsCheckedIn = (int) ($arrivalCounts['checked_in'] ?? 0) + (int) ($arrivalCounts['checked_out'] ?? 0);
        $arrivalsExpected = $arrivalCounts->sum();
        $arrivals = can_do('frontdesk.view')
            ? $arrivalsQuery->with('reservation.guest', 'roomType', 'room')->orderBy('id')->limit(7)->get()
            : collect();
        $departuresQuery = ReservationRoom::where('status', 'checked_in')->whereDate('departure_date', '<=', $d);
        $departuresCount = $canSeeFrontDeskMetrics ? (clone $departuresQuery)->count() : 0;
        $departures = can_do('frontdesk.view')
            ? $departuresQuery->with('reservation.guest', 'room')->orderBy('departure_date')->orderBy('id')->limit(7)->get()
            : collect();
        // Arrival progress is grouped by due date; checked-out guests count as checked in, while no-shows remain in the expected denominator.

        $canSeeFinance = can_do('folio.view') || can_do('reports.view') || can_do('revenue.view') || can_do('income_audit.view');
        // Aggregate ledger rows in SQL, and skip finance reads entirely for restricted dashboard users.
        $roomRevenue = $otherRevenue = $collections = 0.0;
        $roomRevenuePositive = $otherRevenuePositive = 0.0;
        $posToday = null;
        if ($canSeeFinance) {
            $tx = FolioTransaction::whereDate('business_date', $d)->where('is_void', false)
                ->select(
                    'type',
                    'category',
                    DB::raw('SUM(amount) as amount_sum'),
                    DB::raw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as positive_amount_sum')
                )->groupBy('type', 'category')->get();
            $posToday = PosOrder::whereDate('business_date', $d)->where('status', 'settled')->where('settlement', 'payment')
                ->selectRaw('COALESCE(SUM(total - tax), 0) as net_total, COALESCE(SUM(total), 0) as gross_total')->first();
            $roomCharges = $tx->where('type', 'charge')->whereIn('category', ['room', 'extra_bed']);
            $otherCharges = $tx->where('type', 'charge')->whereNotIn('category', ['room', 'extra_bed']);
            $roomRevenue = $roomCharges->sum('amount_sum');
            $otherRevenue = $otherCharges->sum('amount_sum') + (float) ($posToday->net_total ?? 0);
            $roomRevenuePositive = $roomCharges->sum('positive_amount_sum');
            $otherRevenuePositive = $otherCharges->sum('positive_amount_sum') + max(0, (float) ($posToday->net_total ?? 0));
            $collections = $tx->where('type', 'payment')->where('category', '!=', 'city_ledger')->sum('amount_sum') + (float) ($posToday->gross_total ?? 0);
        }

        $auditQuery = NightAudit::query()->select('business_date', 'occupancy_pct');
        if ($canSeeFinance) {
            $auditQuery->addSelect('room_revenue', 'other_revenue');
        }
        $audits = $auditQuery->orderByDesc('business_date')->take(14)->get()->reverse()->values();
        $sourceMix = can_do('reservations.view')
            ? Reservation::query()->leftJoin('business_sources', function ($join) {
                $join->on('business_sources.id', '=', 'reservations.business_source_id')
                    ->on('business_sources.hotel_id', '=', 'reservations.hotel_id');
            })
                ->where('reservations.created_at', '>=', now()->subDays(30))->whereNotIn('reservations.status', ['cancelled'])
                ->select(DB::raw("COALESCE(business_sources.name, 'No source') as name"), DB::raw('count(*) as c'))
                ->groupBy('business_sources.name')->orderByDesc('c')->get()
            : collect();

        $inHouseGuests = can_do('frontdesk.view')
            ? ReservationRoom::where('status', 'checked_in')->sum(DB::raw('adults + children'))
            : 0;
        $occPct = $sellable ? round($occupied * 100 / $sellable, 1) : 0;
        $adr = $occupied ? $roomRevenue / max(1, $occupied) : 0;
        $newBookings = can_do('reservations.view') ? Reservation::whereDate('created_at', $d)->count() : 0;
        $openTickets = can_do('maintenance.manage') ? MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->count() : 0;
        $openPos = can_do('pos.view') ? PosOrder::whereIn('status', ['open', 'billed'])->count() : 0;
        $posOrderStatusToday = can_do('pos.view')
            ? PosOrder::whereDate('business_date', $d)->whereIn('status', ['open', 'billed', 'settled'])
                ->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status')
            : collect();
        $posOrdersToday = (int) $posOrderStatusToday->sum();
        $posRevenueToday = $canSeeFinance ? (float) ($posToday->net_total ?? 0) : null;
        $housekeepingOpen = can_do('housekeeping.view') || can_do('housekeeping.manage')
            ? HousekeepingTask::whereDate('task_date', '<=', $d)->where('status', '!=', 'completed')->count()
            : null;
        $events = can_do('banquet.view')
            ? BanquetEvent::with('functions.hall', 'functions.items')->whereDate('end_date', '>=', $d)->whereIn('status', ['definite', 'tentative'])->orderBy('event_date')->take(5)->get()
            : collect();
        $eventTodayQuery = can_do('banquet.view')
            ? BanquetEvent::whereDate('event_date', $d)->whereIn('status', BanquetEvent::HOLDS)
            : null;
        $eventsToday = $eventTodayQuery ? (clone $eventTodayQuery)->count() : null;
        $eventCoversToday = $eventTodayQuery ? (int) (clone $eventTodayQuery)->sum('expected_pax') : null;
        $lowStockItems = can_do('inventory.view')
            ? InventoryItem::where('is_active', true)->withSum('movements as stock_qty', 'qty')->get()
                ->filter(fn ($i) => (float) ($i->stock_qty ?? 0) <= (float) $i->reorder_level)
            : collect();
        $lowStockCount = $lowStockItems->count();
        $lowStock = $lowStockItems->take(6)->values();
        $openPurchaseOrders = can_do('inventory.view') ? PurchaseOrder::whereIn('status', PurchaseOrder::OPEN)->count() : null;
        $activeEmployees = $attendanceLogged = null;
        if (can_do('payroll.view') || can_do('hr.manage')) {
            $activeEmployees = Employee::where('is_active', true)->count();
            $attendanceLogged = can_do('hr.manage') ? Attendance::whereDate('date', $d)->count() : null;
        }
        $guestFeedback = can_do('feedback.view')
            ? \App\Services\FeedbackService::stats(bdate()->copy()->subDays(29), bdate())
            : null;
        $channelAttention = can_do('channels.view') ? \App\Services\ChannelService::attentionCount() : null;
        $sourceBookings30d = (int) $sourceMix->sum('c');

        $executiveKpis = [];
        if (can_do('frontdesk.view')) {
            $executiveKpis[] = ['label' => 'Room occupancy', 'value' => number_format($sellable ? $occupied * 100 / $sellable : 0, 1).'%','detail' => $occupied.' occupied of '.$sellable.' sellable rooms', 'icon' => 'bed', 'tone' => 'blue'];
            $executiveKpis[] = ['label' => 'Arrivals due', 'value' => (string) $arrivalsExpected, 'detail' => $arrivalsCheckedIn.' checked in · '.$arrivalsCount.' remaining', 'icon' => 'login', 'tone' => 'green'];
            $executiveKpis[] = ['label' => 'In-house guests', 'value' => (string) $inHouseGuests, 'detail' => $departuresCount.' departures pending', 'icon' => 'users', 'tone' => 'purple'];
        }
        if ($canSeeFinance) {
            $executiveKpis[] = ['label' => 'Revenue today', 'value' => money($roomRevenue + $otherRevenue), 'detail' => 'Room + other revenue, net of tax', 'icon' => 'trend', 'tone' => 'green'];
            $executiveKpis[] = ['label' => 'Collections today', 'value' => money($collections), 'detail' => 'Payments received · excludes city ledger', 'icon' => 'wallet', 'tone' => 'teal'];
            $executiveKpis[] = ['label' => 'Room ADR', 'value' => money($adr), 'detail' => 'Posted room revenue per occupied room', 'icon' => 'chart', 'tone' => 'amber'];
        }

        $managementModules = [];
        if (can_do('frontdesk.view') || can_do('reservations.view')) {
            $managementModules[] = ['title' => 'Rooms & front office', 'value' => number_format($sellable ? $occupied * 100 / $sellable : 0, 1).'%','label' => 'occupancy', 'detail' => $arrivalsExpected.' arrivals · '.$departuresCount.' departures', 'icon' => 'bed', 'tone' => 'blue', 'href' => can_do('frontdesk.view') ? route('frontdesk.rooms') : route('reservations.index', ['view' => 'dashboard'])];
        }
        if (can_do('reservations.view')) {
            $managementModules[] = ['title' => 'Reservations & channels', 'value' => (string) $newBookings, 'label' => 'new bookings today', 'detail' => $sourceBookings30d.' bookings in source mix · last 30 days'.($channelAttention !== null ? ' · '.$channelAttention.' channel alerts' : ''), 'icon' => 'calendar', 'tone' => 'green', 'href' => route('reservations.index', ['view' => 'list'])];
        }
        if ($canSeeFinance) {
            $managementModules[] = ['title' => 'Finance & revenue', 'value' => money($roomRevenue + $otherRevenue), 'label' => 'revenue today', 'detail' => money($collections).' collected · ADR '.money($adr), 'icon' => 'wallet', 'tone' => 'teal', 'href' => can_do('reports.view') ? route('reports.show', 'revenue') : null];
        }
        if (can_do('pos.view')) {
            $managementModules[] = ['title' => 'Food & beverage', 'value' => (string) $posOrdersToday, 'label' => 'POS orders today', 'detail' => $openPos.' currently open'.($posRevenueToday !== null ? ' · net sales '.money($posRevenueToday) : ' · financial detail restricted'), 'icon' => 'cup', 'tone' => 'amber', 'href' => route('pos.orders')];
        }
        if ($housekeepingOpen !== null) {
            $managementModules[] = ['title' => 'Housekeeping', 'value' => (string) $dirty, 'label' => 'rooms marked dirty', 'detail' => $housekeepingOpen.' unfinished tasks due by today', 'icon' => 'broom', 'tone' => 'purple', 'href' => route('housekeeping.index')];
        }
        if (can_do('banquet.view')) {
            $managementModules[] = ['title' => 'Events & banquets', 'value' => (string) $eventsToday, 'label' => 'event bookings today', 'detail' => $eventCoversToday.' expected guests · '.count($events).' upcoming bookings shown below', 'icon' => 'star', 'tone' => 'pink', 'href' => route('banquet.diary')];
        }
        if (can_do('maintenance.manage')) {
            $managementModules[] = ['title' => 'Maintenance', 'value' => (string) $openTickets, 'label' => 'open work orders', 'detail' => $unavailable.' rooms unavailable · includes outages / leases', 'icon' => 'tool', 'tone' => 'red', 'href' => route('maintenance.index')];
        }
        if (can_do('inventory.view')) {
            $managementModules[] = ['title' => 'Inventory & procurement', 'value' => (string) $lowStockCount, 'label' => 'items at / below reorder level', 'detail' => $openPurchaseOrders.' open purchase orders', 'icon' => 'box', 'tone' => 'amber', 'href' => route('inventory.index')];
        }
        if ($guestFeedback) {
            $npsValue = $guestFeedback['nps']['nps'];
            $managementModules[] = ['title' => 'Guest experience', 'value' => $npsValue === null ? '—' : (string) $npsValue, 'label' => 'NPS · last 30 days', 'detail' => $guestFeedback['responses'].' feedback responses · '.($guestFeedback['csat'] === null ? 'rating not available' : number_format($guestFeedback['csat'], 1).'/5 average rating'), 'icon' => 'message', 'tone' => 'green', 'href' => route('feedback.index')];
        }
        if ($activeEmployees !== null) {
            $managementModules[] = ['title' => 'People & payroll', 'value' => (string) $activeEmployees, 'label' => 'active employees', 'detail' => $attendanceLogged === null ? 'Staff total · attendance detail restricted' : $attendanceLogged.' attendance records today', 'icon' => 'users', 'tone' => 'blue', 'href' => can_do('payroll.view') ? route('payroll.index') : (can_do('hr.manage') ? route('hr.attendance') : null)];
        }

        return view('dashboard', [
            'total' => $total, 'sellable' => $sellable, 'occupied' => $occupied, 'vacant' => $sellableRooms->where('status', 'vacant')->count(), 'unavailable' => $unavailable,
            'dirty' => $dirty, 'arrivals' => $arrivals, 'arrivalsCount' => $arrivalsCount,
            'departures' => $departures, 'departuresCount' => $departuresCount,
            'arrivalsCheckedIn' => $arrivalsCheckedIn, 'arrivalsExpected' => $arrivalsExpected, 'inHouseGuests' => $inHouseGuests,
            'occPct' => $occPct, 'canSeeFinance' => $canSeeFinance,
            'roomRevenue' => $roomRevenue, 'otherRevenue' => $otherRevenue,
            'roomRevenuePositive' => $roomRevenuePositive, 'otherRevenuePositive' => $otherRevenuePositive, 'collections' => $collections,
            'adr' => $adr,
            'audits' => $audits, 'sourceMix' => $sourceMix,
            'events' => $events,
            'openTickets' => $openTickets,
            'openPos' => $openPos,
            'posOrdersToday' => $posOrdersToday,
            'posOrderStatusToday' => $posOrderStatusToday,
            'housekeepingOpen' => $housekeepingOpen,
            'eventsToday' => $eventsToday,
            'eventCoversToday' => $eventCoversToday,
            'lowStock' => $lowStock,
            'lowStockCount' => $lowStockCount,
            'openPurchaseOrders' => $openPurchaseOrders,
            'activeEmployees' => $activeEmployees,
            'attendanceLogged' => $attendanceLogged,
            'guestFeedback' => $guestFeedback,
            'executiveKpis' => $executiveKpis,
            'managementModules' => $managementModules,
            'activity' => can_do('logs.view') ? ActivityLog::with('user')->latest('created_at')->take(8)->get() : collect(),
            'newBookings' => $newBookings,
            'fleetCost' => can_do('transport.fleet') ? FleetService::costSummary() : null,
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
