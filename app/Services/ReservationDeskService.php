<?php

namespace App\Services;

use App\Models\BusinessSource;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Guest;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceRequest;
use App\Models\OtaChannel;
use App\Models\Reservation;
use App\Models\ReservationDeskView;
use App\Models\ReservationRoom;
use App\Models\ReservationWaitlist;
use App\Models\Room;
use App\Models\RoomAmenity;
use App\Models\RoomAmenityLink;
use App\Models\RoomImage;
use App\Models\RoomOutage;
use App\Models\RoomType;
use App\Support\Numbering;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Read model and small write helpers for the reservation command centre.
 * Check-in, check-out, room moves, folios and payments stay in their existing services.
 */
class ReservationDeskService
{
    public const VIEWS = ['dashboard', 'timeline', 'board', 'list', 'arrivals', 'departures', 'inhouse', 'unassigned', 'waitlist', 'groups'];

    public static function defaultQuery(): ?string
    {
        $view = ReservationDeskView::where('user_id', auth()->id())->where('is_default', true)->first();

        return $view && $view->query !== '' ? $view->query : null;
    }

    /** Turn the existing room-type amenity text into a catalog. Never invents amenities. */
    public static function syncAmenities(): void
    {
        // Reconcile legacy comma-separated room-type amenities on every desk
        // load. The first-run-only shortcut left newly added room types without
        // links and made the catalog appear incomplete.
        foreach (RoomType::whereNotNull('amenities')->get(['id', 'amenities']) as $type) {
            foreach (preg_split('/[,;|]/', (string) $type->amenities) ?: [] as $name) {
                $name = trim($name);
                if ($name === '' || mb_strlen($name) > 80) {
                    continue;
                }
                $amenity = RoomAmenity::firstOrCreate(['name' => $name], [
                    'category' => 'general', 'show_on_desk' => true, 'show_online' => true, 'is_active' => true,
                ]);
                RoomAmenityLink::firstOrCreate([
                    'room_amenity_id' => $amenity->id, 'room_type_id' => $type->id, 'room_id' => null,
                ]);
            }
        }
    }

    public static function page(Request $request): array
    {
        self::syncAmenities();
        try {
            $date = Carbon::parse($request->input('date', bdate()->toDateString()))->startOfDay();
        } catch (\Throwable) {
            $date = Carbon::parse(bdate()->toDateString())->startOfDay();
        }
        $days = min(31, max(7, (int) $request->input('days', 14)));
        $to = $date->copy()->addDays($days);
        $view = $request->input('view');
        if (! in_array($view, self::VIEWS, true)) {
            $view = $request->hasAny(['status', 'q', 'from', 'to', 'source', 'type', 'sort', 'channel']) ? 'list' : 'dashboard';
        }
        $group = in_array($request->input('group'), ['floor', 'hk', 'status', 'type'], true) ? $request->input('group') : 'type';

        $rooms = Room::with(['roomType.amenityLinks.amenity', 'roomType.images', 'floor', 'images', 'amenityLinks.amenity'])
            ->where('is_active', true)->orderBy('room_no')->get();
        $amenityId = (int) $request->input('amenity');
        if ($amenityId) {
            $rooms = $rooms->filter(fn ($r) => self::amenityIds($r)->contains($amenityId))->values();
        }
        if ($request->filled('hk')) {
            $rooms = $rooms->where('hk_status', $request->hk)->values();
        }
        if ($request->filled('room_type')) {
            $rooms = $rooms->where('room_type_id', (int) $request->room_type)->values();
        }
        if ($request->filled('floor')) {
            $rooms = $rooms->where('floor_id', (int) $request->floor)->values();
        }
        $focus = (string) $request->input('focus');
        if ($focus === 'available') {
            $freeIds = AvailabilityService::reservationRooms($date, $date->copy()->addDay())->pluck('id');
            $rooms = $rooms->whereIn('id', $freeIds)->values();
        } elseif ($focus === 'occupied') {
            $rooms = $rooms->where('status', 'occupied')->values();
        } elseif ($focus === 'vacant_clean') {
            $rooms = $rooms->where('status', 'vacant')->where('hk_status', 'clean')->values();
        } elseif ($focus === 'vacant_dirty') {
            $rooms = $rooms->where('status', 'vacant')->where('hk_status', 'dirty')->values();
        } elseif ($focus === 'inspected') {
            $rooms = $rooms->where('hk_status', 'inspected')->values();
        } elseif ($focus === 'ooo') {
            $rooms = $rooms->where('status', 'out_of_order')->values();
        } elseif ($focus === 'oos') {
            $rooms = $rooms->where('status', 'out_of_service')->values();
        } elseif ($focus === 'cleaning') {
            $ids = HousekeepingTask::where('task_date', $date->toDateString())->where('status', 'in_progress')->pluck('room_id');
            $rooms = $rooms->whereIn('id', $ids)->values();
        } elseif ($focus === 'maintenance') {
            $ids = MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->pluck('room_id');
            $rooms = $rooms->whereIn('id', $ids)->values();
        }

        $stays = ReservationRoom::with(['reservation.guest', 'reservation.source', 'reservation.company', 'room', 'roomType'])
            ->whereIn('status', ['reserved', 'checked_in', 'checked_out'])
            ->where('arrival_date', '<', $to->toDateString())
            ->where('departure_date', '>', $date->toDateString())
            ->get();
        $stays = self::filterStays($stays, $request, $date);

        $roomIds = $rooms->pluck('id');
        $outages = RoomOutage::with('room')->where('status', 'active')
            ->where('starts_on', '<', $to->toDateString())->where('ends_on', '>', $date->toDateString())
            ->get();

        $day = $date->toDateString();
        $arrivals = ReservationRoom::with(['reservation.guest', 'reservation.source', 'room', 'roomType', 'folio'])
            ->where('arrival_date', $day)->whereIn('status', ['reserved', 'checked_in'])->orderBy('id')->get();
        if ($request->input('due') === 'in') {
            $arrivals = $arrivals->where('status', 'reserved')->values();
        }
        if ($focus === 'walkin') {
            $arrivals = $arrivals->filter(fn ($s) => $s->reservation?->source?->type === 'walk_in')->values();
        }
        $departures = ReservationRoom::with(['reservation.guest', 'room', 'roomType', 'folio'])
            ->where('departure_date', $day)->whereIn('status', ['checked_in', 'checked_out'])->orderBy('id')->get();
        $inhouse = ReservationRoom::with(['reservation.guest', 'reservation.company', 'room', 'roomType', 'folio'])
            ->where('status', 'checked_in')->orderBy('room_id')->get();
        $unassigned = ReservationRoom::with(['reservation.guest', 'roomType'])
            ->where('status', 'reserved')->whereNull('room_id')->where('departure_date', '>', $day)
            ->orderBy('arrival_date')->get();
        $groups = Reservation::with(['guest', 'rooms.room', 'rooms.roomType', 'block.lines.roomType'])
            ->where('booking_type', 'group')->whereIn('status', ['tentative', 'confirmed', 'checked_in'])
            ->where('arrival_date', '<', $to->toDateString())->where('departure_date', '>', $date->toDateString())
            ->orderBy('arrival_date')->get();

        if ($focus === 'blocked') {
            $rooms = $rooms->whereIn('id', RoomOutage::covering($date, $date->copy()->addDay())->pluck('room_id'))->values();
        }
        if ($focus === 'reserved') {
            $stays = $stays->where('status', 'reserved')->values();
        }
        $flags = self::moneyFlags($stays);
        if ($focus === 'balance') {
            $ids = collect($flags)->filter(fn ($f) => ($f['balance'] ?? 0) > 0.009)->keys();
            $stays = $stays->whereIn('id', $ids)->values();
        } elseif ($focus === 'deposit') {
            $ids = collect($flags)->filter(fn ($f) => ($f['paid'] ?? 0) > 0.009)->keys();
            $stays = $stays->whereIn('id', $ids)->values();
        }

        $visibleRooms = $rooms;
        if (in_array($focus, ['reserved', 'balance', 'deposit'], true)) {
            $hit = $stays->pluck('room_id')->filter();
            $visibleRooms = $rooms->whereIn('id', $hit)->values();
        }
        if ($request->filled('q')) {
            $q = mb_strtolower(trim((string) $request->q));
            $hitRooms = $stays->pluck('room_id')->filter();
            $visibleRooms = $rooms->filter(fn ($r) => str_contains(mb_strtolower($r->room_no), $q) || $hitRooms->contains($r->id))->values();
        }
        if ($request->input('tab') === 'vip' || $request->boolean('vip')) {
            $vipRooms = $stays->filter(fn ($s) => $s->reservation?->guest?->is_vip)->pluck('room_id');
            $visibleRooms = $visibleRooms->filter(fn ($r) => $vipRooms->contains($r->id))->values();
        }

        return [
            'view' => $view, 'date' => $date, 'days' => $days, 'to' => $to, 'group' => $group,
            'dates' => collect(range(0, $days - 1))->map(fn ($i) => $date->copy()->addDays($i)),
            'rooms' => $visibleRooms,
            'grouped' => $visibleRooms->groupBy(fn ($r) => match ($group) {
                'floor' => $r->floor->name ?? 'No floor',
                'hk' => ucfirst(str_replace('_', ' ', $r->hk_status)),
                'status' => ucfirst(str_replace('_', ' ', $r->status)),
                default => $r->roomType->name ?? 'No type',
            }),
            'byRoom' => $stays->whereNotNull('room_id')->groupBy('room_id'),
            'loose' => $stays->whereNull('room_id')->where('status', 'reserved'),
            'outages' => $outages->whereIn('room_id', $roomIds)->groupBy('room_id'),
            'kpis' => self::kpis($date),
            'tabs' => self::tabs($date),
            'arrivals' => $arrivals, 'departures' => $departures, 'inhouse' => $inhouse,
            'pipeline' => $view === 'dashboard' ? self::pipeline($date, 14) : null,
            'vipArrivals' => $view === 'dashboard' ? self::vipArrivals($date) : null,
            'onTheBooks' => $view === 'dashboard' ? self::onTheBooks($date) : null,
            'lateArrivals' => $view === 'dashboard' ? self::lateArrivals($date) : null,
            'sourceMix' => $view === 'dashboard' ? self::sourceMix($date) : null,
            'walkInArrivals' => $view === 'dashboard' ? self::walkInArrivals($date) : null,
            'compStays' => $view === 'dashboard' ? self::compStays($date) : null,
            'roomTypePipeline' => $view === 'dashboard' ? self::roomTypePipeline($date) : null,
            'groupArrivals' => $view === 'dashboard' ? self::groupArrivals($date) : null,
            'noShows' => $view === 'dashboard' ? self::noShows($date) : null,
            'unassigned' => $unassigned, 'groups' => $groups,
            'waitlist' => ReservationWaitlist::with(['guest', 'roomType', 'reservation'])->orderByDesc('priority')->orderBy('arrival_date')->get(),
            'reservations' => $view === 'list' ? self::listQuery($request)->paginate(25)->withQueryString() : null,
            'counts' => Reservation::select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status'),
            'sources' => BusinessSource::orderBy('name')->pluck('name', 'id'),
            'roomTypes' => RoomType::where('is_active', true)->orderBy('name')->get(),
            'channels' => OtaChannel::orderBy('name')->pluck('name', 'id'),
            'amenities' => RoomAmenity::where('is_active', true)->orderBy('name')->get(),
            'saved' => ReservationDeskView::where('user_id', auth()->id())->orderBy('name')->get(),
            'hkCounts' => Room::where('is_active', true)->select('hk_status', DB::raw('count(*) c'))->groupBy('hk_status')->pluck('c', 'hk_status'),
            'flags' => $flags,
            'floors' => \App\Models\Floor::orderBy('sort_order')->pluck('name', 'id'),
            'companies' => Company::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'tick' => self::tick($date),
        ];
    }

    public static function listQuery(Request $request)
    {
        try {
            $date = Carbon::parse($request->input('date', bdate()->toDateString()))->toDateString();
        } catch (\Throwable) {
            $date = bdate()->toDateString();
        }
        $q = Reservation::with(['guest', 'source', 'rooms.room', 'rooms.roomType', 'company'])
            ->when($request->q, fn ($w, $t) => $w->where(fn ($x) => $x->where('reservation_no', 'like', "%$t%")->orWhere('group_name', 'like', "%$t%")->orWhere('ota_reference', 'like', "%$t%")->orWhereHas('guest', fn ($g) => $g->whereNull('privacy_status')->search($t))))
            ->when($request->status, fn ($w, $s) => $w->where('status', $s))
            ->when($request->source, fn ($w, $s) => $w->where('business_source_id', $s))
            ->when($request->from, fn ($w, $d) => $w->where('arrival_date', '>=', $d))
            ->when($request->to, fn ($w, $d) => $w->where('arrival_date', '<=', $d))
            ->when($request->type, fn ($w, $t) => $w->where('booking_type', $t))
            ->when($request->channel, fn ($w, $c) => $w->where('ota_channel_id', (int) $c))
            ->when($request->input('tab') === 'arrivals', fn ($w) => $w->where('arrival_date', $date)->whereIn('status', ['tentative', 'confirmed', 'checked_in']))
            ->when($request->input('tab') === 'inhouse', fn ($w) => $w->where('status', 'checked_in'))
            ->when($request->input('tab') === 'stayover', fn ($w) => $w->where('status', 'checked_in')->where('departure_date', '>', $date))
            ->when($request->input('tab') === 'dueout', fn ($w) => $w->where('status', 'checked_in')->where('departure_date', $date))
            ->when($request->input('tab') === 'departures', fn ($w) => $w->where('departure_date', $date)->whereIn('status', ['checked_in', 'checked_out']))
            ->when($request->input('tab') === 'unassigned', fn ($w) => $w->whereHas('rooms', fn ($r) => $r->whereNull('room_id')->where('status', 'reserved')))
            ->when($request->input('tab') === 'vip' || $request->boolean('vip'), fn ($w) => $w->whereHas('guest', fn ($g) => $g->where('is_vip', true))->whereIn('status', ['tentative', 'confirmed', 'checked_in']))
            ->when($request->input('tab') === 'groups', fn ($w) => $w->where('booking_type', 'group')->whereNotIn('status', ['cancelled', 'no_show']))
            ->when($request->input('tab') === 'cancelled', fn ($w) => $w->where('status', 'cancelled'))
            ->when($request->input('tab') === 'duein', fn ($w) => $w->where('arrival_date', $date)->whereIn('status', ['tentative', 'confirmed']))
            ->when($request->input('tab') === 'noshow', fn ($w) => $w->where('status', 'no_show'))
            ->when($request->company, fn ($w, $c) => $w->where('company_id', (int) $c));
        $sort = $request->input('sort', 'arrival');
        $q->orderBy($sort === 'created' ? 'created_at' : 'arrival_date', $sort === 'created' ? 'desc' : ($request->status === 'checked_out' ? 'desc' : 'asc'));

        return $q;
    }

    /** N-day outlook: arrivals, departures, expected rooms, occupancy, expected room revenue. Read-only. */
    public static function pipeline(Carbon $date, int $days = 7): array
    {
        $sellable = Room::where('is_active', true)->whereNotIn('status', ['out_of_order', 'out_of_service'])->count();
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $date->copy()->addDays($i);
            $ds = $d->toDateString();
            $covering = ReservationRoom::whereIn('status', ['reserved', 'checked_in'])
                ->where('arrival_date', '<=', $ds)->where('departure_date', '>', $ds);
            $rooms = (clone $covering)->count();
            $out[] = [
                'date' => $d,
                'arrivals' => ReservationRoom::where('arrival_date', $ds)->whereIn('status', ['reserved', 'checked_in'])->count(),
                'departures' => ReservationRoom::where('departure_date', $ds)->whereIn('status', ['checked_in', 'checked_out'])->count(),
                'rooms' => $rooms,
                'occ' => $sellable ? round($rooms / $sellable * 100, 1) : 0,
                'revenue' => (float) (clone $covering)->sum('rate'),
            ];
        }

        return $out;
    }

    /** Expected room revenue (pre-tax) on the books for the next 14 and 30 days. Read-only. */
    public static function onTheBooks(Carbon $date): array
    {
        $sum = fn (int $days) => (float) ReservationRoom::whereIn('status', ['reserved', 'checked_in'])
            ->where('arrival_date', '<', $date->copy()->addDays($days)->toDateString())
            ->where('departure_date', '>', $date->toDateString())
            ->sum('rate');

        return ['days14' => $sum(14), 'days30' => $sum(30)];
    }

    /** Arrivals whose ETA has passed but who have not checked in. Read-only. */
    public static function lateArrivals(Carbon $date)
    {
        return ReservationRoom::with(['reservation.guest', 'room'])
            ->where('arrival_date', $date->toDateString())
            ->where('status', 'reserved')
            ->get()
            ->filter(fn ($s) => filled($s->reservation?->eta) && substr((string) $s->reservation->eta, 0, 5) < now()->format('H:i'))
            ->sortBy(fn ($s) => (string) $s->reservation->eta)
            ->values();
    }

    /** Booking sources for stays covering the next $days days. Read-only. */
    public static function sourceMix(Carbon $date, int $days = 14)
    {
        $end = $date->copy()->addDays($days)->toDateString();

        return ReservationRoom::with('reservation.source')
            ->whereIn('status', ['reserved', 'checked_in'])
            ->where('arrival_date', '<', $end)
            ->where('departure_date', '>', $date->toDateString())
            ->get()
            ->groupBy(fn ($s) => $s->reservation?->source?->name ?? 'No source')
            ->map(fn ($g, $name) => ['source' => $name, 'rooms' => $g->count(), 'revenue' => (float) $g->sum('rate')])
            ->sortByDesc('revenue')
            ->values();
    }

    /** Walk-in arrivals for the given date. Read-only. */
    public static function walkInArrivals(Carbon $date)
    {
        return ReservationRoom::with(['reservation.guest', 'room'])
            ->where('arrival_date', $date->toDateString())
            ->whereIn('status', ['reserved', 'checked_in'])
            ->whereHas('reservation.source', fn ($s) => $s->where('type', 'walk_in'))
            ->orderBy('id')
            ->get();
    }

    /** Complimentary and house-use stays covering the given date. Read-only. */
    public static function compStays(Carbon $date)
    {
        $d = $date->toDateString();

        return ReservationRoom::with(['reservation.guest', 'room'])
            ->whereIn('status', ['reserved', 'checked_in'])
            ->where('arrival_date', '<=', $d)
            ->where('departure_date', '>', $d)
            ->get()
            ->filter(fn ($s) => in_array(self::stayLabel($s, $date), ['Complimentary', 'House use'], true))
            ->values();
    }

    /** Rooms expected per room type: tonight and bookings covering the next $days days, plus expected revenue. Read-only. */
    public static function roomTypePipeline(Carbon $date, int $days = 14)
    {
        $tonight = $date->toDateString();
        $end = $date->copy()->addDays($days)->toDateString();
        $window = ReservationRoom::with('roomType')
            ->whereIn('status', ['reserved', 'checked_in'])
            ->where('arrival_date', '<', $end)
            ->where('departure_date', '>', $tonight)
            ->get();
        $tonightIds = $window
            ->filter(fn ($s) => $s->arrival_date->toDateString() <= $tonight && $s->departure_date->toDateString() > $tonight)
            ->pluck('id');

        return $window
            ->groupBy(fn ($s) => $s->roomType?->name ?? 'No type')
            ->map(function ($g, $name) use ($tonightIds) {
                return [
                    'type' => $name,
                    'tonight' => $g->whereIn('id', $tonightIds)->count(),
                    'bookings' => $g->count(),
                    'revenue' => (float) $g->sum('rate'),
                ];
            })
            ->sortByDesc('revenue')
            ->values();
    }

    /** Group bookings arriving within the next $days days. Read-only. */
    public static function groupArrivals(Carbon $date, int $days = 7)
    {
        return Reservation::with(['guest', 'rooms.room'])
            ->where('booking_type', 'group')
            ->whereIn('status', ['tentative', 'confirmed'])
            ->whereBetween('arrival_date', [$date->toDateString(), $date->copy()->addDays($days - 1)->toDateString()])
            ->orderBy('arrival_date')->orderBy('id')
            ->get();
    }

    /** Reservations marked no-show for the given arrival date. Read-only. */
    public static function noShows(Carbon $date)
    {
        return Reservation::with(['guest', 'rooms.room'])
            ->where('status', 'no_show')
            ->whereDate('arrival_date', $date->toDateString())
            ->orderBy('id')
            ->get();
    }

    /** VIP stays arriving within the next $days days. Read-only. */
    public static function vipArrivals(Carbon $date, int $days = 7)
    {
        return ReservationRoom::with(['reservation.guest', 'room'])
            ->whereIn('status', ['reserved', 'checked_in'])
            ->whereBetween('arrival_date', [$date->toDateString(), $date->copy()->addDays($days - 1)->toDateString()])
            ->whereHas('reservation.guest', fn ($g) => $g->where('is_vip', true))
            ->orderBy('arrival_date')->orderBy('id')->get();
    }

    public static function kpis(Carbon $date): array
    {
        $d = $date->toDateString();
        $covering = ReservationRoom::whereIn('status', ['reserved', 'checked_in'])->where('arrival_date', '<=', $d)->where('departure_date', '>', $d);
        $sold = (clone $covering)->count();
        $assigned = (clone $covering)->whereNotNull('room_id')->count();
        $onBooks = (float) (clone $covering)->sum('rate');
        $roomRows = Room::where('is_active', true)->get(['id', 'status', 'hk_status']);
        $sellable = $roomRows->whereNotIn('status', ['out_of_order', 'out_of_service'])->count();
        $posted = (float) FolioTransaction::where('business_date', $d)->where('is_void', false)->where('type', 'charge')->sum(DB::raw('amount + tax_amount'));
        $roomPosted = (float) FolioTransaction::where('business_date', $d)->where('is_void', false)->where('type', 'charge')->where('category', 'room')->sum(DB::raw('amount + tax_amount'));
        $deposits = (float) FolioTransaction::where('business_date', $d)->where('is_void', false)->where('type', 'payment')->where('category', 'advance')->sum('amount');
        $openIds = Folio::where('status', 'open')->pluck('id');
        $outstanding = 0.0;
        if ($openIds->isNotEmpty()) {
            $outstanding = (float) (FolioTransaction::whereIn('folio_id', $openIds)->where('is_void', false)
                ->selectRaw("coalesce(sum(case type when 'charge' then amount + tax_amount when 'discount' then -amount when 'allowance' then -amount when 'payment' then -amount when 'refund' then amount else 0 end), 0) as bal")
                ->first()->bal ?? 0);
        }

        return [
            'arrivals' => ReservationRoom::where('arrival_date', $d)->whereIn('status', ['reserved', 'checked_in'])->count(),
            'inhouse' => ReservationRoom::where('status', 'checked_in')->count(),
            'due_out' => ReservationRoom::where('status', 'checked_in')->where('departure_date', $d)->count(),
            'available' => AvailabilityService::reservationRooms($d, $date->copy()->addDay()->toDateString())->count(),
            'occupancy' => $sellable ? round($assigned / $sellable * 100, 1) : null,
            'adr' => $sold ? round($onBooks / $sold, 2) : null,
            'on_books' => round($onBooks, 2),
            'posted' => round($posted, 2),
            'outstanding' => round($outstanding, 2),
            'unassigned' => ReservationRoom::where('status', 'reserved')->whereNull('room_id')->where('departure_date', '>', $d)->count(),
            'vip' => ReservationRoom::whereIn('status', ['reserved', 'checked_in'])->where('arrival_date', '<=', $d)->where('departure_date', '>', $d)->whereHas('reservation.guest', fn ($g) => $g->where('is_vip', true))->count(),
            'groups' => Reservation::where('booking_type', 'group')->whereIn('status', ['tentative', 'confirmed', 'checked_in'])->where('arrival_date', '<=', $d)->where('departure_date', '>', $d)->count(),
            'sellable' => $sellable,
            'sold' => $sold,
            'total' => $roomRows->count(),
            'occupied' => $roomRows->where('status', 'occupied')->count(),
            'reserved' => (clone $covering)->where('status', 'reserved')->count(),
            'vacant_clean' => $roomRows->where('status', 'vacant')->where('hk_status', 'clean')->count(),
            'vacant_dirty' => $roomRows->where('status', 'vacant')->where('hk_status', 'dirty')->count(),
            'inspected' => $roomRows->where('hk_status', 'inspected')->count(),
            'cleaning' => HousekeepingTask::where('task_date', $d)->where('status', 'in_progress')->distinct()->count('room_id'),
            'maintenance' => MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->whereNotNull('room_id')->distinct()->count('room_id'),
            'ooo' => $roomRows->where('status', 'out_of_order')->count(),
            'oos' => $roomRows->where('status', 'out_of_service')->count(),
            'blocked' => RoomOutage::covering($d, $date->copy()->addDay())->distinct('room_id')->count('room_id'),
            'stayover' => ReservationRoom::where('status', 'checked_in')->where('departure_date', '>', $d)->count(),
            'due_in' => ReservationRoom::where('arrival_date', $d)->where('status', 'reserved')->count(),
            'walkins' => Reservation::where('arrival_date', $d)->whereIn('status', ['tentative', 'confirmed', 'checked_in'])->whereHas('source', fn ($s) => $s->where('type', 'walk_in'))->count(),
            'noshow' => Reservation::where('status', 'no_show')->where('arrival_date', $d)->count(),
            'cancelled' => Reservation::where('status', 'cancelled')->whereDate('cancelled_at', $d)->count(),
            'waitlist' => ReservationWaitlist::where('status', 'waiting')->count(),
            'room_posted' => round($roomPosted, 2),
            'deposits' => round($deposits, 2),
            'revpar' => $roomRows->count() ? round($roomPosted / $roomRows->count(), 2) : null,
        ];
    }

    public static function tabs(Carbon $date): array
    {
        $d = $date->toDateString();

        return [
            'arrivals' => ReservationRoom::where('arrival_date', $d)->whereIn('status', ['reserved', 'checked_in'])->count(),
            'inhouse' => ReservationRoom::where('status', 'checked_in')->count(),
            'stayover' => ReservationRoom::where('status', 'checked_in')->where('departure_date', '>', $d)->count(),
            'dueout' => ReservationRoom::where('status', 'checked_in')->where('departure_date', $d)->count(),
            'departures' => ReservationRoom::where('departure_date', $d)->whereIn('status', ['checked_in', 'checked_out'])->count(),
            'unassigned' => ReservationRoom::where('status', 'reserved')->whereNull('room_id')->where('departure_date', '>', $d)->count(),
            'vip' => ReservationRoom::whereIn('status', ['reserved', 'checked_in'])->whereHas('reservation.guest', fn ($g) => $g->where('is_vip', true))->where('departure_date', '>', $d)->count(),
            'groups' => Reservation::where('booking_type', 'group')->whereIn('status', ['tentative', 'confirmed', 'checked_in'])->count(),
            'waitlist' => ReservationWaitlist::where('status', 'waiting')->count(),
            'cancelled' => Reservation::where('status', 'cancelled')->count(),
            'duein' => ReservationRoom::where('arrival_date', $d)->where('status', 'reserved')->count(),
            'noshow' => Reservation::where('status', 'no_show')->count(),
        ];
    }

    public static function stayLabel(ReservationRoom $stay, Carbon $date): string
    {
        $purpose = mb_strtolower((string) $stay->reservation?->purpose);
        if (str_contains($purpose, 'house use')) {
            return 'House use';
        }
        if ((float) $stay->rate === 0.0 && in_array($stay->status, ['reserved', 'checked_in'], true)) {
            return 'Complimentary';
        }
        if ($stay->status === 'checked_out') {
            return 'Checked out';
        }
        if ($stay->status === 'checked_in' && $stay->departure_date->isSameDay($date)) {
            return 'Due out';
        }
        if ($stay->status === 'checked_in') {
            return $stay->departure_date->gt($date) ? 'Stayover' : 'In house';
        }
        if ($stay->status === 'reserved' && $stay->arrival_date->isSameDay($date)) {
            return 'Due in';
        }
        if ($stay->reservation?->status === 'tentative') {
            return 'Tentative';
        }
        if ($stay->reservation?->status === 'confirmed') {
            return 'Confirmed';
        }

        return ucwords(str_replace('_', ' ', (string) $stay->status));
    }

    public static function moneyFlags($stays): array
    {
        $resIds = $stays->pluck('reservation_id')->filter()->unique();
        if ($resIds->isEmpty()) {
            return [];
        }
        $folios = Folio::whereIn('reservation_id', $resIds)->get(['id', 'reservation_id', 'reservation_room_id']);
        if ($folios->isEmpty()) {
            return [];
        }
        $sums = FolioTransaction::whereIn('folio_id', $folios->pluck('id'))->where('is_void', false)
            ->selectRaw("folio_id, coalesce(sum(case type when 'payment' then amount when 'refund' then -amount else 0 end), 0) as paid, coalesce(sum(case type when 'charge' then amount + tax_amount when 'discount' then -amount when 'allowance' then -amount when 'payment' then -amount when 'refund' then amount else 0 end), 0) as bal")
            ->groupBy('folio_id')->get()->keyBy('folio_id');
        $out = [];
        foreach ($stays as $stay) {
            $ids = $folios->filter(fn ($f) => (int) $f->reservation_room_id === (int) $stay->id || ($f->reservation_room_id === null && (int) $f->reservation_id === (int) $stay->reservation_id))->pluck('id');
            $paid = 0.0;
            $bal = 0.0;
            foreach ($ids as $id) {
                $paid += (float) ($sums[$id]->paid ?? 0);
                $bal += (float) ($sums[$id]->bal ?? 0);
            }
            $nights = max(1, (int) $stay->arrival_date->diffInDays($stay->departure_date));
            $expected = (float) $stay->rate * $nights;
            $out[$stay->id] = [
                'paid' => round($paid, 2),
                'balance' => round($bal, 2),
                'deposit' => $paid <= 0 ? 'none' : ($expected > 0 && $paid + 0.009 < $expected ? 'partial' : 'received'),
            ];
        }

        return $out;
    }

    public static function tick(Carbon $date): string
    {
        $d = $date->toDateString();
        $parts = [
            ReservationRoom::whereIn('status', ['reserved', 'checked_in'])->max('updated_at'),
            Room::max('updated_at'),
            RoomOutage::max('updated_at'),
            FolioTransaction::where('business_date', $d)->max('updated_at'),
        ];

        return md5(implode('|', $parts));
    }

    public static function span(Carbon $from, Carbon $to, $arrival, $departure): ?array
    {
        $start = Carbon::parse($arrival)->startOfDay()->max($from->copy()->startOfDay());
        $end = Carbon::parse($departure)->startOfDay()->min($to->copy()->startOfDay());
        if ($end->lte($start)) {
            return null;
        }

        return [(int) round($from->copy()->startOfDay()->diffInDays($start)), max(1, (int) round($start->diffInDays($end)))];
    }

    public static function amenityIds(Room $room)
    {
        return $room->amenityLinks->pluck('room_amenity_id')
            ->merge($room->roomType?->amenityLinks?->pluck('room_amenity_id') ?? collect())
            ->unique()->values();
    }

    public static function amenityNames(Room $room)
    {
        $own = $room->amenityLinks->map(fn ($l) => $l->amenity?->name)->filter();
        $type = $room->roomType?->amenityLinks?->map(fn ($l) => $l->amenity?->name)->filter() ?? collect();

        return $type->merge($own)->unique()->values();
    }

    public static function photo(Room $room): ?RoomImage
    {
        return $room->images->firstWhere('is_primary', true) ?? $room->images->first()
            ?? $room->roomType?->images?->firstWhere('is_primary', true)
            ?? $room->roomType?->images?->first();
    }

    /** Same refusals as block(). Writes nothing. */
    public static function describeBlock(int $roomId, string $from, string $to, string $reason): string
    {
        [$room, $starts, $ends] = self::blockWindow($roomId, $from, $to);
        $label = RoomOutage::REASONS[$reason] ?? $reason;
        $ooo = $starts->lte(bdate()) && $ends->gt(bdate()) && $room->status === 'vacant';

        return "Block room {$room->room_no} from {$starts->format('d M')} to {$ends->format('d M')} ({$label}). "
            .($ooo ? 'The block covers today and the room is vacant, so confirming would mark it out of order.' : 'Room status would not change.')
            .' Nothing is saved until you confirm. A block does not post a charge.';
    }

    public static function block(int $roomId, string $from, string $to, string $reason, ?string $notes): RoomOutage
    {
        return DB::transaction(function () use ($roomId, $from, $to, $reason, $notes) {
            // Serialise blocks with reservation placement and other desk users.
            Room::whereKey($roomId)->lockForUpdate()->firstOrFail();
            [$room, $starts, $ends] = self::blockWindow($roomId, $from, $to);
            $outage = RoomOutage::create([
                'room_id' => $room->id, 'starts_on' => $starts->toDateString(), 'ends_on' => $ends->toDateString(),
                'reason' => $reason, 'notes' => $notes, 'status' => 'active', 'created_by' => auth()->id(),
            ]);
            if ($starts->lte(bdate()) && $ends->gt(bdate()) && $room->status === 'vacant') {
                $room->update(['status' => 'out_of_order', 'notes' => trim(($notes ?: 'Blocked from the reservation desk').' · until '.$ends->format('d M'))]);
            }
            ActivityLogger::log('reservations', 'block', "Blocked room {$room->room_no} {$starts->format('d M')}–{$ends->format('d M')} ({$reason})", $room);

            return $outage;
        });
    }

    /** @return array{0: Room, 1: Carbon, 2: Carbon} */
    protected static function blockWindow(int $roomId, string $from, string $to): array
    {
        $starts = Carbon::parse($from)->startOfDay();
        $ends = Carbon::parse($to)->startOfDay();
        if ($ends->lte($starts)) {
            throw new RuntimeException('The block must end after it starts. The end date is the morning the room comes back, like a departure.');
        }
        $room = Room::findOrFail($roomId);
        if (ReservationRoom::where('room_id', $room->id)->whereIn('status', ['reserved', 'checked_in'])
            ->where('arrival_date', '<', $ends->toDateString())->where('departure_date', '>', $starts->toDateString())->exists()) {
            throw new RuntimeException("Room {$room->room_no} has a reservation in that period. Move or shorten it before blocking.");
        }
        if (RoomOutage::covering($starts, $ends, $room->id)->exists()) {
            throw new RuntimeException("Room {$room->room_no} is already blocked on those dates.");
        }

        return [$room, $starts, $ends];
    }

    public static function release(RoomOutage $outage): void
    {
        DB::transaction(function () use ($outage) {
            $locked = RoomOutage::whereKey($outage->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                return;
            }
            $room = $locked->room;
            if ($room) {
                $room = Room::whereKey($room->id)->lockForUpdate()->first();
            }
            $locked->update(['status' => 'released', 'released_at' => now()]);
            $still = $room && RoomOutage::covering(bdate(), bdate()->copy()->addDay(), $room->id)->where('id', '!=', $locked->id)->exists();
            $ticket = $room && MaintenanceRequest::where('room_id', $room->id)->where('block_room', true)->whereIn('status', ['open', 'in_progress'])->exists();
            if ($room && $room->status === 'out_of_order' && ! $still && ! $ticket && $room->status !== 'occupied') {
                $room->update(['status' => 'vacant']);
            }
            ActivityLogger::log('reservations', 'unblock', 'Released block on room '.($room->room_no ?? $locked->room_id), $room ?? $locked);
        });
    }

    public static function addWaitlist(array $data): ReservationWaitlist
    {
        $guest = ! empty($data['guest_id']) ? Guest::find($data['guest_id']) : null;
        if (! $guest && ! empty($data['phone'])) {
            $guest = Guest::where('phone', $data['phone'])->first();
        }
        $row = ReservationWaitlist::create([
            'guest_id' => $guest?->id,
            'contact_name' => $data['contact_name'],
            'phone' => $data['phone'] ?? $guest?->phone,
            'email' => $data['email'] ?? $guest?->email,
            'arrival_date' => $data['arrival_date'],
            'departure_date' => $data['departure_date'],
            'room_type_id' => $data['room_type_id'] ?? null,
            'adults' => $data['adults'] ?? 1,
            'children' => $data['children'] ?? 0,
            'priority' => $data['priority'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'status' => 'waiting',
            'created_by' => auth()->id(),
        ]);
        ActivityLogger::log('reservations', 'waitlist', "Waitlisted {$row->contact_name} for {$row->arrival_date->format('d M')}", $row);

        return $row;
    }

    public static function convertWaitlist(ReservationWaitlist $row): Reservation
    {
        $reservation = DB::transaction(function () use ($row) {
            // Re-read and lock the row and the room-type inventory. The
            // availability check must be inside the same transaction as the
            // reservation insert, otherwise two agents can convert the same
            // last-room waitlist entry at once.
            $row = ReservationWaitlist::whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'waiting') {
                throw new RuntimeException('This waitlist entry is already '.$row->status.'.');
            }
            if (! $row->room_type_id) {
                throw new RuntimeException('Choose a room type before converting. Nothing was booked.');
            }
            $type = RoomType::findOrFail($row->room_type_id);
            AvailabilityService::lockRoomInventory([$type->id]);
            if (AvailabilityService::typeAvailable($type->id, $row->arrival_date, $row->departure_date) < 1) {
                throw new RuntimeException('No '.$type->name.' is free for those dates. The guest stays on the waitlist.');
            }

            $guest = $row->guest;
            if (! $guest || $guest->isPrivacyLocked()) {
                $guest = self::guestFromWaitlist($row);
            }
            if ($guest->is_blacklisted) {
                throw new RuntimeException('This guest is blacklisted. Waitlist conversion blocked.');
            }
            $quote = RateService::quote($type, $row->arrival_date, $row->departure_date);
            if ($quote['stop_sell']) {
                throw new RuntimeException('This room type is closed for sale on the selected dates.');
            }
            if (! $quote['min_stay_ok']) {
                throw new RuntimeException("A minimum stay of {$quote['min_stay']} night(s) applies to this room type.");
            }
            $extraAdults = max(0, (int) $row->adults - (int) ($type->base_occupancy ?? 2));
            $extraCharge = $extraAdults * (float) $type->extra_adult_rate;

            $res = Reservation::create([
                'reservation_no' => Numbering::next('reservation'),
                'guest_id' => $guest->id,
                'business_source_id' => BusinessSource::where('type', 'direct')->value('id'),
                'arrival_date' => $row->arrival_date->toDateString(),
                'departure_date' => $row->departure_date->toDateString(),
                'adults' => $row->adults,
                'children' => $row->children,
                'status' => 'confirmed',
                'booking_type' => 'individual',
                'billing_instruction' => 'guest',
                'special_request' => $row->notes,
                'created_by' => auth()->id(),
            ]);
            $res->rooms()->create([
                'room_type_id' => $type->id, 'guest_id' => $guest->id,
                'arrival_date' => $row->arrival_date->toDateString(), 'departure_date' => $row->departure_date->toDateString(),
                'rate' => $quote['average'], 'adults' => $row->adults, 'children' => $row->children,
                'extra_charge' => $extraCharge, 'status' => 'reserved',
            ]);
            $row->update(['status' => 'converted', 'reservation_id' => $res->id, 'guest_id' => $guest->id]);
            ActivityLogger::log('reservations', 'create', "Converted waitlist {$row->contact_name} to {$res->reservation_no}", $res);

            return $res;
        });
        try {
            MailService::autoConfirm($reservation);
            MessageService::autoConfirm($reservation);
        } catch (\Throwable $e) {
            report($e);
        }

        return $reservation;
    }

    public static function cancelWaitlist(ReservationWaitlist $row): void
    {
        DB::transaction(function () use ($row) {
            $locked = ReservationWaitlist::whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'waiting') {
                throw new RuntimeException('Only a waiting entry can be cancelled.');
            }
            $locked->update(['status' => 'cancelled']);
            ActivityLogger::log('reservations', 'waitlist', "Cancelled waitlist entry for {$locked->contact_name}", $locked);
        });
    }

    public static function searchGroups(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return ['guests' => collect(), 'reservations' => collect(), 'rooms' => collect(), 'companies' => collect(), 'folios' => collect(), 'waitlist' => collect()];
        }

        return [
            'guests' => Guest::whereNull('privacy_status')->search($q)->take(8)->get(),
            'reservations' => Reservation::with('guest')->where(function ($w) use ($q) {
                $w->where('reservation_no', 'like', "%$q%")->orWhere('group_name', 'like', "%$q%")->orWhere('ota_reference', 'like', "%$q%")
                    ->orWhereHas('guest', fn ($g) => $g->whereNull('privacy_status')->search($q));
            })->latest()->take(8)->get(),
            'rooms' => Room::with('roomType')->where('room_no', 'like', "%$q%")->orderBy('room_no')->take(8)->get(),
            'companies' => Company::where(function ($w) use ($q) {
                $w->where('name', 'like', "%$q%")->orWhere('phone', 'like', "%$q%");
            })->orderBy('name')->take(8)->get(),
            'folios' => Folio::with('guest')->where('folio_no', 'like', "%$q%")->latest()->take(8)->get(),
            'waitlist' => ReservationWaitlist::where('status', 'waiting')->where(function ($w) use ($q) {
                $w->where('contact_name', 'like', "%$q%")->orWhere('phone', 'like', "%$q%")->orWhere('email', 'like', "%$q%");
            })->orderByDesc('priority')->take(8)->get(),
        ];
    }

    protected static function guestFromWaitlist(ReservationWaitlist $row): Guest
    {
        if ($row->phone) {
            $found = Guest::whereNull('privacy_status')->where('phone', $row->phone)->first();
            if ($found) {
                return $found;
            }
        }
        $parts = preg_split('/\s+/', trim($row->contact_name), 2) ?: [$row->contact_name];

        return Guest::create([
            'first_name' => $parts[0] ?: 'Guest',
            'last_name' => $parts[1] ?? null,
            'phone' => $row->phone,
            'email' => $row->email,
        ]);
    }

    protected static function filterStays($stays, Request $request, Carbon $date)
    {
        $tab = $request->input('tab');
        $day = $date->toDateString();

        return $stays->filter(function ($s) use ($request, $tab, $day) {
            $res = $s->reservation;
            if ($request->filled('status') && $res?->status !== $request->status) {
                return false;
            }
            if ($request->filled('source') && (int) $res?->business_source_id !== (int) $request->source) {
                return false;
            }
            if ($request->filled('type') && $res?->booking_type !== $request->type) {
                return false;
            }
            if ($request->filled('company') && (int) $res?->company_id !== (int) $request->company) {
                return false;
            }
            if ($request->filled('q')) {
                $q = mb_strtolower(trim((string) $request->q));
                $blob = mb_strtolower(implode(' ', array_filter([
                    $res?->reservation_no, $res?->group_name, $res?->ota_reference, $res?->guest?->full_name, $res?->guest?->phone, $s->room?->room_no,
                ])));
                if (! str_contains($blob, $q)) {
                    return false;
                }
            }
            return match ($tab) {
                'arrivals' => $s->arrival_date->toDateString() === $day && in_array($s->status, ['reserved', 'checked_in'], true),
                'inhouse' => $s->status === 'checked_in',
                'stayover' => $s->status === 'checked_in' && $s->departure_date->toDateString() > $day,
                'dueout' => $s->status === 'checked_in' && $s->departure_date->toDateString() === $day,
                'departures' => $s->departure_date->toDateString() === $day,
                'duein' => $s->arrival_date->toDateString() === $day && $s->status === 'reserved',
                'unassigned' => $s->room_id === null && $s->status === 'reserved',
                'vip' => (bool) $res?->guest?->is_vip,
                'groups' => $res?->booking_type === 'group',
                'cancelled' => $res?->status === 'cancelled',
                default => true,
            };
        })->values();
    }
}
