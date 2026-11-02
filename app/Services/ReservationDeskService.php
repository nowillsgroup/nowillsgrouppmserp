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
    public const VIEWS = ['dashboard', 'timeline', 'board', 'list', 'arrivals', 'departures', 'inhouse', 'unassigned', 'waitlist', 'groups', 'noshow', 'cancelled'];

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
        $scalarDefaults = [
            'date' => bdate()->toDateString(), 'days' => 14, 'group' => 'type', 'view' => '', 'tab' => '', 'q' => '',
            'status' => '', 'source' => '', 'type' => '', 'channel' => '', 'company' => '', 'arrival_on' => '', 'departure_on' => '', 'cancelled_on' => '', 'from' => '', 'to' => '',
            'sort' => 'arrival', 'per_page' => 25, 'page' => 1, 'room_type' => '', 'amenity' => '', 'hk' => '', 'floor' => '',
            'focus' => '', 'vip' => '', 'waitlist_status' => '', 'waitlist_focus' => '', 'group_status' => '', 'group_focus' => '', 'noshow_focus' => '', 'cancelled_focus' => '',
        ];
        foreach ($scalarDefaults as $key => $fallback) {
            $value = $request->input($key);
            if ($value !== null && ! is_scalar($value)) {
                $request->merge([$key => $fallback]);
            }
        }
        if ($request->query->has('page')) {
            $rawPage = $request->query->get('page');
            $pageNumber = is_scalar($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT) : false;
            $request->merge(['page' => $pageNumber !== false && $pageNumber > 0 ? (int) $pageNumber : 1]);
        }
        $rawSearch = $request->input('q');
        if (is_string($rawSearch)) {
            $request->merge(['q' => mb_substr(trim($rawSearch), 0, 160)]);
        }
        foreach (['source', 'channel', 'company'] as $idFilter) {
            $rawId = $request->input($idFilter);
            if ($rawId !== null && $rawId !== '') {
                $id = self::validPositiveId($rawId);
                $request->merge([$idFilter => $id === null ? '' : (string) $id]);
            }
        }
        $dateString = self::validDateString($request->input('date')) ?? bdate()->toDateString();
        $request->merge(['date' => $dateString]);
        foreach (['arrival_on', 'departure_on', 'cancelled_on', 'from', 'to'] as $dateFilter) {
            $rawDate = $request->input($dateFilter);
            if ($rawDate !== null) {
                $request->merge([$dateFilter => self::validDateString($rawDate) ?? '']);
            }
        }
        $date = Carbon::createFromFormat('!Y-m-d', $dateString)->startOfDay();
        $days = min(31, max(7, (int) $request->input('days', 14)));
        $to = $date->copy()->addDays($days);
        $view = $request->input('view');
        if (! in_array($view, self::VIEWS, true)) {
            $view = $request->hasAny(['status', 'q', 'arrival_on', 'departure_on', 'cancelled_on', 'from', 'to', 'source', 'type', 'sort', 'channel', 'per_page']) ? 'list' : 'dashboard';
        }
        if (in_array($view, ['noshow', 'cancelled'], true)) {
            $days = min(31, max(1, (int) $request->input('days', 14)));
            $to = $date->copy()->addDays($days);
        }
        if ($view === 'list') {
            $legacyTab = $request->input('tab');
            if (! $request->filled('status') && in_array($legacyTab, ['noshow', 'cancelled'], true)) {
                $request->merge(['status' => $legacyTab === 'noshow' ? 'no_show' : 'cancelled']);
            }
            if ($request->input('sort') === 'arrival_asc' && $request->input('status') !== 'checked_out') {
                $request->merge(['sort' => 'arrival']);
            }
            $request->query->remove('tab');
        }
        $group = in_array($request->input('group'), ['floor', 'hk', 'status', 'type'], true) ? $request->input('group') : 'type';

        if ($view === 'cancelled') {
            $windowEnd = $to->toDateString();
            $windowStart = $date->toDateString();
            $baseCancelled = Reservation::where('status', 'cancelled')
                ->whereNotNull('cancelled_at')
                ->whereDate('cancelled_at', '>=', $windowStart)
                ->whereDate('cancelled_at', '<', $windowEnd);
            $cancelledSummary = [
                'total' => (clone $baseCancelled)->count(),
                'groups' => (clone $baseCancelled)->where('booking_type', 'group')->count(),
                'individuals' => (clone $baseCancelled)->where('booking_type', '!=', 'group')->count(),
                'room_stays' => ReservationRoom::where('status', 'cancelled')->whereHas('reservation', fn ($query) => $query
                    ->where('status', 'cancelled')->whereNotNull('cancelled_at')
                    ->whereDate('cancelled_at', '>=', $windowStart)->whereDate('cancelled_at', '<', $windowEnd))->count(),
            ];
            $cancelledFocus = (string) $request->input('cancelled_focus');
            if (! in_array($cancelledFocus, ['groups', 'individuals'], true)) {
                $request->query->remove('cancelled_focus');
                $request->request->remove('cancelled_focus');
                $cancelledFocus = '';
            }
            $cancelledSearch = mb_substr(trim((string) $request->input('q')), 0, 160);
            $canSearchCancelledContact = can_do('guests.view');
            $cancelledQuery = Reservation::with(['guest', 'source', 'otaChannel', 'company', 'rooms.room', 'rooms.roomType'])
                ->where('status', 'cancelled')->whereNotNull('cancelled_at')
                ->whereDate('cancelled_at', '>=', $windowStart)->whereDate('cancelled_at', '<', $windowEnd)
                ->when($cancelledFocus === 'groups', fn ($query) => $query->where('booking_type', 'group'))
                ->when($cancelledFocus === 'individuals', fn ($query) => $query->where('booking_type', '!=', 'group'));
            if ($cancelledSearch !== '') {
                $cancelledQuery->where(function ($query) use ($cancelledSearch, $canSearchCancelledContact) {
                    $query->where('reservation_no', 'like', "%{$cancelledSearch}%")
                        ->orWhere('group_name', 'like', "%{$cancelledSearch}%")
                        ->orWhere('ota_reference', 'like', "%{$cancelledSearch}%")
                        ->orWhereHas('company', fn ($company) => $company->where('name', 'like', "%{$cancelledSearch}%"))
                        ->orWhereHas('source', fn ($source) => $source->where('name', 'like', "%{$cancelledSearch}%"))
                        ->orWhereHas('rooms.roomType', fn ($type) => $type->where('name', 'like', "%{$cancelledSearch}%"))
                        ->orWhereHas('rooms.room', fn ($room) => $room->where('room_no', 'like', "%{$cancelledSearch}%"))
                        ->orWhereHas('guest', function ($guest) use ($cancelledSearch, $canSearchCancelledContact) {
                            $guest->whereNull('privacy_status')->where(function ($person) use ($cancelledSearch, $canSearchCancelledContact) {
                                $person->where('first_name', 'like', "%{$cancelledSearch}%")
                                    ->orWhere('last_name', 'like', "%{$cancelledSearch}%");
                                if ($canSearchCancelledContact) {
                                    $person->orWhere('phone', 'like', "%{$cancelledSearch}%")
                                        ->orWhere('email', 'like', "%{$cancelledSearch}%");
                                }
                            });
                        });
                });
            }
            $pageSize = self::listPageSize($request);
            $cancelledQuery->orderBy('cancelled_at')->orderBy('id');
            $cancelledReservations = $cancelledQuery->paginate($pageSize)->withQueryString();
            if ($cancelledReservations->currentPage() > $cancelledReservations->lastPage()) {
                $request->merge(['page' => $cancelledReservations->lastPage()]);
                $cancelledReservations = $cancelledQuery->paginate($pageSize)->withQueryString();
            }

            return [
                'view' => $view, 'date' => $date, 'days' => $days, 'to' => $to, 'group' => $group,
                'tabs' => self::tabs($date, $days), 'cancelledReservations' => $cancelledReservations,
                'cancelledSummary' => $cancelledSummary, 'cancelledFocus' => $cancelledFocus,
                'saved' => ReservationDeskView::where('user_id', auth()->id())->orderBy('name')->get(),
            ];
        }

        if ($view === 'noshow') {
            $windowEnd = $to->toDateString();
            $windowStart = $date->toDateString();
            $baseNoShows = Reservation::where('status', 'no_show')
                ->whereDate('arrival_date', '>=', $windowStart)
                ->whereDate('arrival_date', '<', $windowEnd);
            $noShowSummary = [
                'total' => (clone $baseNoShows)->count(),
                'groups' => (clone $baseNoShows)->where('booking_type', 'group')->count(),
                'individuals' => (clone $baseNoShows)->where('booking_type', '!=', 'group')->count(),
                'room_stays' => ReservationRoom::where('status', 'no_show')->whereHas('reservation', fn ($query) => $query
                    ->where('status', 'no_show')->whereDate('arrival_date', '>=', $windowStart)->whereDate('arrival_date', '<', $windowEnd))->count(),
            ];
            $noShowFocus = (string) $request->input('noshow_focus');
            if (! in_array($noShowFocus, ['groups', 'individuals'], true)) {
                $request->query->remove('noshow_focus');
                $request->request->remove('noshow_focus');
                $noShowFocus = '';
            }
            $noShowSearch = mb_substr(trim((string) $request->input('q')), 0, 160);
            $canSearchNoShowContact = can_do('guests.view');
            $noShowQuery = Reservation::with(['guest', 'source', 'otaChannel', 'company', 'rooms.room', 'rooms.roomType'])
                ->where('status', 'no_show')
                ->whereDate('arrival_date', '>=', $windowStart)
                ->whereDate('arrival_date', '<', $windowEnd)
                ->when($noShowFocus === 'groups', fn ($query) => $query->where('booking_type', 'group'))
                ->when($noShowFocus === 'individuals', fn ($query) => $query->where('booking_type', '!=', 'group'));
            if ($noShowSearch !== '') {
                $noShowQuery->where(function ($query) use ($noShowSearch, $canSearchNoShowContact) {
                    $query->where('reservation_no', 'like', "%{$noShowSearch}%")
                        ->orWhere('group_name', 'like', "%{$noShowSearch}%")
                        ->orWhere('ota_reference', 'like', "%{$noShowSearch}%")
                        ->orWhereHas('company', fn ($company) => $company->where('name', 'like', "%{$noShowSearch}%"))
                        ->orWhereHas('source', fn ($source) => $source->where('name', 'like', "%{$noShowSearch}%"))
                        ->orWhereHas('rooms.roomType', fn ($type) => $type->where('name', 'like', "%{$noShowSearch}%"))
                        ->orWhereHas('rooms.room', fn ($room) => $room->where('room_no', 'like', "%{$noShowSearch}%"))
                        ->orWhereHas('guest', function ($guest) use ($noShowSearch, $canSearchNoShowContact) {
                            $guest->whereNull('privacy_status')->where(function ($person) use ($noShowSearch, $canSearchNoShowContact) {
                                $person->where('first_name', 'like', "%{$noShowSearch}%")
                                    ->orWhere('last_name', 'like', "%{$noShowSearch}%");
                                if ($canSearchNoShowContact) {
                                    $person->orWhere('phone', 'like', "%{$noShowSearch}%")
                                        ->orWhere('email', 'like', "%{$noShowSearch}%");
                                }
                            });
                        });
                });
            }
            $pageSize = self::listPageSize($request);
            $noShowQuery->orderBy('arrival_date')->orderBy('id');
            $noShowReservations = $noShowQuery->paginate($pageSize)->withQueryString();
            if ($noShowReservations->currentPage() > $noShowReservations->lastPage()) {
                $request->merge(['page' => $noShowReservations->lastPage()]);
                $noShowReservations = $noShowQuery->paginate($pageSize)->withQueryString();
            }

            return [
                'view' => $view, 'date' => $date, 'days' => $days, 'to' => $to, 'group' => $group,
                'tabs' => self::tabs($date, $days), 'noShowReservations' => $noShowReservations,
                'noShowSummary' => $noShowSummary, 'noShowFocus' => $noShowFocus,
                'saved' => ReservationDeskView::where('user_id', auth()->id())->orderBy('name')->get(),
            ];
        }

        if ($view === 'list') {
            $pageSize = self::listPageSize($request);
            $reservations = self::listQuery($request)->paginate($pageSize);
            if ($reservations->currentPage() > $reservations->lastPage()) {
                $request->merge(['page' => $reservations->lastPage()]);
                $reservations = self::listQuery($request)->paginate($pageSize);
            }
            $reservations = $reservations->withQueryString();

            return [
                'view' => $view, 'date' => $date, 'days' => $days, 'to' => $to, 'group' => $group,
                'tabs' => collect(), 'reservations' => $reservations,
                'counts' => self::listStatusCounts($request),
                'sources' => BusinessSource::orderBy('name')->pluck('name', 'id'),
                'channels' => OtaChannel::orderBy('name')->pluck('name', 'id'),
                'companies' => Company::orderBy('name')->get(['id', 'name', 'is_active'])
                    ->mapWithKeys(fn ($company) => [$company->id => $company->name.($company->is_active ? '' : ' (inactive)')]),
                'saved' => ReservationDeskView::where('user_id', auth()->id())->orderBy('name')->get(),
            ];
        }

        self::syncAmenities();
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
        if ($view === 'inhouse' && ! in_array($focus, ['due_today', 'overdue', 'stayovers', 'vip', 'unassigned', 'balance'], true)) {
            $request->query->remove('focus');
            $request->request->remove('focus');
            $focus = '';
        }
        if ($view === 'unassigned' && ! in_array($focus, ['arriving_today', 'past_arrival', 'next_7_days', 'groups'], true)) {
            $request->query->remove('focus');
            $request->request->remove('focus');
            $focus = '';
        }
        if ($focus === 'available') {
            $historical = $date->copy()->startOfDay()->lt(bdate()->copy()->startOfDay());
            $freeIds = $historical
                ? AvailabilityService::historicalReservationRooms($date)->pluck('id')
                : AvailabilityService::reservationRooms($date, $date->copy()->addDay())->pluck('id');
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
            $ids = HousekeepingTask::whereDate('task_date', $date->toDateString())->where('status', 'in_progress')->pluck('room_id');
            $rooms = $rooms->whereIn('id', $ids)->values();
        } elseif ($focus === 'maintenance') {
            $ids = MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->pluck('room_id');
            $rooms = $rooms->whereIn('id', $ids)->values();
        }

        $stays = ReservationRoom::with(['reservation.guest', 'reservation.source', 'reservation.company', 'room', 'roomType'])
            ->whereIn('status', ['reserved', 'checked_in', 'checked_out'])
            ->whereDate('arrival_date', '<', $to->toDateString())
            ->whereDate('departure_date', $view === 'board' ? '>=' : '>', $date->toDateString())
            ->get();
        $stays = self::filterStays($stays, $request, $date);

        $roomIds = $rooms->pluck('id');
        $outages = RoomOutage::with('room')->where('status', 'active')
            ->whereDate('starts_on', '<', $to->toDateString())->whereDate('ends_on', '>', $date->toDateString())
            ->get();

        $day = $date->toDateString();
        $dateStatuses = self::deskDateStatuses($date);
        $arrivals = ReservationRoom::with(['reservation.guest', 'reservation.source', 'room', 'roomType', 'folio'])
            ->whereDate('arrival_date', $day)->whereIn('status', $dateStatuses['arrivals'])->get()
            ->sort(function ($a, $b) {
                // Keep guests who still need service at the top; within that queue,
                // show the earliest stated ETA first and arrivals without an ETA last.
                $pendingOrder = ($a->status === 'reserved' ? 0 : 1) <=> ($b->status === 'reserved' ? 0 : 1);
                if ($pendingOrder !== 0) {
                    return $pendingOrder;
                }
                $etaA = $a->reservation?->eta ? substr((string) $a->reservation->eta, 0, 5) : '99:99';
                $etaB = $b->reservation?->eta ? substr((string) $b->reservation->eta, 0, 5) : '99:99';

                return ($etaA <=> $etaB) ?: ($a->id <=> $b->id);
            })->values();
        $guestDetailsIds = $arrivals->filter(function ($stay) {
            if ($stay->status !== 'reserved') {
                return false;
            }
            $needs = StayService::missingCheckInRequirements($stay);

            return in_array('ID', $needs, true) || in_array('phone', $needs, true);
        })->pluck('id');
        $now = now();
        $lateEtaIds = $date->isSameDay(bdate())
            ? $arrivals->filter(fn ($stay) => $stay->status === 'reserved'
                && self::etaHasPassed($date, $stay->reservation?->eta, $now))->pluck('id')
            : collect();
        $arrivalSummary = [
            'total' => $arrivals->count(),
            'due_in' => $arrivals->where('status', 'reserved')->count(),
            'processed' => $arrivals->whereIn('status', ['checked_in', 'checked_out'])->count(),
            'unassigned' => $arrivals->where('status', 'reserved')->whereNull('room_id')->count(),
            'guest_details' => $guestDetailsIds->count(),
            'late_eta' => $lateEtaIds->count(),
            'walkins' => $arrivals->filter(fn ($s) => $s->reservation?->source?->type === 'walk_in')->count(),
        ];
        $arrivals = self::filterStays($arrivals, $request, $date);
        if ($request->input('due') === 'in') {
            $arrivals = $arrivals->where('status', 'reserved')->values();
        }
        if ($focus === 'walkin') {
            $arrivals = $arrivals->filter(fn ($s) => $s->reservation?->source?->type === 'walk_in')->values();
        } elseif ($focus === 'unassigned') {
            $arrivals = $arrivals->where('status', 'reserved')->whereNull('room_id')->values();
        } elseif ($focus === 'guest_details') {
            $arrivals = $arrivals->whereIn('id', $guestDetailsIds)->values();
        } elseif ($focus === 'late_eta') {
            $arrivals = $arrivals->whereIn('id', $lateEtaIds)->values();
        }
        $departureRelations = ['reservation.guest', 'reservation.source', 'room', 'roomType'];
        if (can_do('folio.view')) {
            $departureRelations[] = 'folio';
        }
        $departures = ReservationRoom::with($departureRelations)
            ->whereDate('departure_date', $day)->whereIn('status', $dateStatuses['departures'])->get()
            ->sort(function ($a, $b) {
                $priority = ['checked_in' => 0, 'reserved' => 1, 'checked_out' => 2];
                $statusOrder = ($priority[$a->status] ?? 3) <=> ($priority[$b->status] ?? 3);

                return $statusOrder ?: ($a->id <=> $b->id);
            })->values();
        $departureSummary = [
            'total' => $departures->count(),
            'still_in_house' => $departures->where('status', 'checked_in')->count(),
            'checked_out' => $departures->where('status', 'checked_out')->count(),
            'not_yet_arrived' => $departures->where('status', 'reserved')->count(),
        ];
        $departures = self::filterStays($departures, $request, $date);
        if ($request->input('tab') === 'dueout') {
            $departures = $departures->where('status', 'checked_in')->values();
        }
        if ($focus === 'still_in_house') {
            $departures = $departures->where('status', 'checked_in')->values();
        } elseif ($focus === 'checked_out') {
            $departures = $departures->where('status', 'checked_out')->values();
        } elseif ($focus === 'not_yet_arrived') {
            $departures = $departures->where('status', 'reserved')->values();
        }
        $canViewInhouseFolios = $view === 'inhouse' && can_do('folio.view');
        $inhouseRelations = ['reservation.guest', 'reservation.company', 'reservation.source', 'room', 'roomType'];
        if ($canViewInhouseFolios) {
            $inhouseRelations[] = 'folio';
        }
        $businessDay = bdate()->toDateString();
        $inhouse = ReservationRoom::with($inhouseRelations)->where('status', 'checked_in')->get()
            ->sort(function ($a, $b) use ($businessDay) {
                $priority = static function ($stay) use ($businessDay): int {
                    $departure = $stay->departure_date->toDateString();

                    return $departure < $businessDay ? 0 : ($departure === $businessDay ? 1 : 2);
                };
                $statusOrder = $priority($a) <=> $priority($b);
                if ($statusOrder !== 0) {
                    return $statusOrder;
                }
                $dateOrder = $a->departure_date->toDateString() <=> $b->departure_date->toDateString();
                if ($dateOrder !== 0) {
                    return $dateOrder;
                }
                $roomOrder = strnatcasecmp((string) ($a->room?->room_no ?? ''), (string) ($b->room?->room_no ?? ''));

                return $roomOrder ?: ($a->id <=> $b->id);
            })->values();
        $inhouseMoneyFlags = $canViewInhouseFolios ? self::moneyFlags($inhouse) : [];
        $inhouseSummary = [
            'total' => $inhouse->count(),
            'guests' => (int) $inhouse->sum(fn ($stay) => (int) $stay->adults + (int) $stay->children),
            'due_today' => $inhouse->filter(fn ($stay) => $stay->departure_date->toDateString() === $businessDay)->count(),
            'overdue' => $inhouse->filter(fn ($stay) => $stay->departure_date->toDateString() < $businessDay)->count(),
            'stayovers' => $inhouse->filter(fn ($stay) => $stay->departure_date->toDateString() > $businessDay)->count(),
            'vip' => $inhouse->filter(fn ($stay) => (bool) $stay->reservation?->guest?->is_vip)->count(),
            'unassigned' => $inhouse->whereNull('room_id')->count(),
            'balance_review' => $canViewInhouseFolios
                ? collect($inhouseMoneyFlags)->filter(fn ($flags) => abs($flags['balance'] ?? 0) > 0.009)->count()
                : null,
        ];
        $inhouseSearch = mb_strtolower(trim((string) $request->input('q', '')));
        if ($inhouseSearch !== '') {
            $canSearchGuestPhone = can_do('guests.view');
            $inhouse = $inhouse->filter(function ($stay) use ($inhouseSearch, $canSearchGuestPhone) {
                $reservation = $stay->reservation;
                $guest = $reservation?->guest;
                $fields = [
                    $reservation?->reservation_no,
                    $reservation?->group_name,
                    $reservation?->company?->name,
                    $reservation?->source?->name,
                    $guest?->full_name,
                    $stay->room?->room_no,
                    $canSearchGuestPhone ? $guest?->phone : null,
                ];

                return str_contains(mb_strtolower(implode(' ', array_filter($fields))), $inhouseSearch);
            })->values();
        }
        if ($focus === 'due_today') {
            $inhouse = $inhouse->filter(fn ($stay) => $stay->departure_date->toDateString() === $businessDay)->values();
        } elseif ($focus === 'overdue') {
            $inhouse = $inhouse->filter(fn ($stay) => $stay->departure_date->toDateString() < $businessDay)->values();
        } elseif ($focus === 'stayovers') {
            $inhouse = $inhouse->filter(fn ($stay) => $stay->departure_date->toDateString() > $businessDay)->values();
        } elseif ($focus === 'vip') {
            $inhouse = $inhouse->filter(fn ($stay) => (bool) $stay->reservation?->guest?->is_vip)->values();
        } elseif ($focus === 'unassigned') {
            $inhouse = $inhouse->whereNull('room_id')->values();
        } elseif ($focus === 'balance') {
            $inhouse = $canViewInhouseFolios
                ? $inhouse->filter(fn ($stay) => abs($inhouseMoneyFlags[$stay->id]['balance'] ?? 0) > 0.009)->values()
                : collect();
        }
        $unassignedRelations = ['reservation.guest', 'roomType'];
        if ($view === 'unassigned') {
            $unassignedRelations[] = 'reservation.source';
            $unassignedRelations[] = 'reservation.company';
        }
        $unassigned = ReservationRoom::with($unassignedRelations)
            ->where('status', 'reserved')->whereNull('room_id')->whereDate('departure_date', '>', $day)
            ->orderBy('arrival_date')->orderBy('id')->get();
        $unassignedSummary = [
            'total' => $unassigned->count(),
            'arriving_today' => $unassigned->filter(fn ($stay) => $stay->arrival_date->toDateString() === $day)->count(),
            'past_arrival' => $unassigned->filter(fn ($stay) => $stay->arrival_date->toDateString() < $day)->count(),
            'next_7_days' => $unassigned->filter(fn ($stay) => $stay->arrival_date->toDateString() > $day
                && $stay->arrival_date->toDateString() <= $date->copy()->addDays(7)->toDateString())->count(),
            'groups' => $unassigned->filter(fn ($stay) => $stay->reservation?->booking_type === 'group')->count(),
        ];
        if ($view === 'unassigned' && $request->filled('q')) {
            $unassignedSearch = mb_strtolower(trim((string) $request->input('q')));
            $canSearchUnassignedPhone = can_do('guests.view');
            $unassigned = $unassigned->filter(function ($stay) use ($unassignedSearch, $canSearchUnassignedPhone) {
                $reservation = $stay->reservation;
                $guest = $reservation?->guest;
                $fields = [
                    $reservation?->reservation_no,
                    $reservation?->group_name,
                    $reservation?->company?->name,
                    $reservation?->source?->name,
                    $guest?->full_name,
                    $stay->roomType?->name,
                    $stay->roomType?->code,
                    $canSearchUnassignedPhone ? $guest?->phone : null,
                ];

                return str_contains(mb_strtolower(implode(' ', array_filter($fields))), $unassignedSearch);
            })->values();
        }
        if ($view === 'unassigned') {
            if ($focus === 'arriving_today') {
                $unassigned = $unassigned->filter(fn ($stay) => $stay->arrival_date->toDateString() === $day)->values();
            } elseif ($focus === 'past_arrival') {
                $unassigned = $unassigned->filter(fn ($stay) => $stay->arrival_date->toDateString() < $day)->values();
            } elseif ($focus === 'next_7_days') {
                $rangeEnd = $date->copy()->addDays(7)->toDateString();
                $unassigned = $unassigned->filter(fn ($stay) => $stay->arrival_date->toDateString() > $day
                    && $stay->arrival_date->toDateString() <= $rangeEnd)->values();
            } elseif ($focus === 'groups') {
                $unassigned = $unassigned->filter(fn ($stay) => $stay->reservation?->booking_type === 'group')->values();
            }
        }
        $waitlist = ReservationWaitlist::with(['guest', 'roomType', 'reservation'])
            ->orderByDesc('priority')->orderBy('arrival_date')->orderBy('id')->get();
        $waitlistSummary = [
            'total' => $waitlist->count(),
            'waiting' => $waitlist->where('status', 'waiting')->count(),
            'converted' => $waitlist->where('status', 'converted')->count(),
            'cancelled' => $waitlist->where('status', 'cancelled')->count(),
            'needs_type' => $waitlist->filter(fn ($entry) => $entry->status === 'waiting' && ! $entry->room_type_id)->count(),
            'next_7_days' => $waitlist->filter(fn ($entry) => $entry->status === 'waiting'
                && $entry->arrival_date->toDateString() > $day
                && $entry->arrival_date->toDateString() <= $date->copy()->addDays(7)->toDateString())->count(),
        ];
        $waitlistStatus = (string) $request->input('waitlist_status');
        if (! in_array($waitlistStatus, ['waiting', 'converted', 'cancelled'], true)) {
            $request->query->remove('waitlist_status');
            $request->request->remove('waitlist_status');
            $waitlistStatus = '';
        }
        $waitlistFocus = (string) $request->input('waitlist_focus');
        if (! in_array($waitlistFocus, ['next_7_days', 'needs_type'], true)) {
            $request->query->remove('waitlist_focus');
            $request->request->remove('waitlist_focus');
            $waitlistFocus = '';
        }
        if ($view === 'waitlist' && $request->filled('q')) {
            $waitlistSearch = mb_strtolower(trim((string) $request->input('q')));
            $canSearchWaitlistContact = can_do('guests.view');
            $waitlist = $waitlist->filter(function ($entry) use ($waitlistSearch, $canSearchWaitlistContact) {
                $fields = [
                    $entry->contact_name,
                    $entry->roomType?->name,
                    $entry->reservation?->reservation_no,
                    $canSearchWaitlistContact ? $entry->notes : null,
                    $canSearchWaitlistContact ? $entry->phone : null,
                    $canSearchWaitlistContact ? $entry->email : null,
                ];

                return str_contains(mb_strtolower(implode(' ', array_filter($fields))), $waitlistSearch);
            })->values();
        }
        if ($view === 'waitlist') {
            if ($waitlistStatus !== '') {
                $waitlist = $waitlist->where('status', $waitlistStatus)->values();
            }
            if ($waitlistFocus === 'next_7_days') {
                $rangeEnd = $date->copy()->addDays(7)->toDateString();
                $waitlist = $waitlist->filter(fn ($entry) => $entry->status === 'waiting'
                    && $entry->arrival_date->toDateString() > $day
                    && $entry->arrival_date->toDateString() <= $rangeEnd)->values();
            } elseif ($waitlistFocus === 'needs_type') {
                $waitlist = $waitlist->filter(fn ($entry) => $entry->status === 'waiting' && ! $entry->room_type_id)->values();
            }
        }
        $groupRows = in_array($view, ['dashboard', 'groups'], true)
            ? self::groupReservationsInWindow($date, $days)
                ->with(['guest', 'company', 'rooms.room', 'rooms.roomType', 'block.lines.roomType'])
                ->orderBy('arrival_date')->orderBy('id')->get()
            : collect();
        $groupSummary = [
            'total' => $groupRows->count(),
            'tentative' => $groupRows->where('status', 'tentative')->count(),
            'confirmed' => $groupRows->where('status', 'confirmed')->count(),
            'in_house' => $groupRows->where('status', 'checked_in')->count(),
            'completed' => $groupRows->where('status', 'checked_out')->count(),
            'room_stays' => $groupRows->sum(fn ($reservation) => $reservation->rooms->where('status', '!=', 'cancelled')->count()),
            'with_block' => $groupRows->filter(fn ($reservation) => $reservation->block !== null)->count(),
            'without_block' => $groupRows->filter(fn ($reservation) => $reservation->block === null)->count(),
        ];
        $groupStatus = (string) $request->input('group_status');
        if (! in_array($groupStatus, ['tentative', 'confirmed', 'checked_in', 'checked_out'], true)) {
            $request->query->remove('group_status');
            $request->request->remove('group_status');
            $groupStatus = '';
        }
        $groupFocus = (string) $request->input('group_focus');
        if (! in_array($groupFocus, ['with_block', 'without_block'], true)) {
            $request->query->remove('group_focus');
            $request->request->remove('group_focus');
            $groupFocus = '';
        }
        $groups = $groupRows;
        if ($view === 'groups' && $request->filled('q')) {
            $groupSearch = mb_strtolower(trim((string) $request->input('q')));
            $canSearchGroupPhone = can_do('guests.view');
            $groups = $groups->filter(function ($reservation) use ($groupSearch, $canSearchGroupPhone) {
                $roomTypes = $reservation->rooms->pluck('roomType.name')->filter()->all();
                $blockRoomTypes = $reservation->block?->lines->pluck('roomType.name')->filter()->all() ?? [];
                $fields = [
                    $reservation->reservation_no,
                    $reservation->group_name,
                    $reservation->guest?->full_name,
                    $reservation->company?->name,
                    $reservation->block?->block_no,
                    $reservation->block?->name,
                    ...$roomTypes,
                    ...$blockRoomTypes,
                    $canSearchGroupPhone ? $reservation->guest?->phone : null,
                ];

                return str_contains(mb_strtolower(implode(' ', array_filter($fields))), $groupSearch);
            })->values();
        }
        if ($view === 'groups') {
            if ($groupStatus !== '') {
                $groups = $groups->where('status', $groupStatus)->values();
            }
            if ($groupFocus === 'with_block') {
                $groups = $groups->filter(fn ($reservation) => $reservation->block !== null)->values();
            } elseif ($groupFocus === 'without_block') {
                $groups = $groups->filter(fn ($reservation) => $reservation->block === null)->values();
            }
        }

        if ($focus === 'blocked') {
            $historical = $date->copy()->startOfDay()->lt(bdate()->copy()->startOfDay());
            $blockedIds = $historical
                ? AvailabilityService::historicalOutageRoomIds($date)
                : RoomOutage::covering($date, $date->copy()->addDay())->pluck('room_id');
            $rooms = $rooms->whereIn('id', $blockedIds)->values();
        }
        if ($focus === 'reserved') {
            $stays = $stays->filter(fn ($stay) => $stay->status === 'reserved'
                && $stay->arrival_date->toDateString() <= $day
                && $stay->departure_date->toDateString() > $day
                && in_array($stay->status, $dateStatuses['covering'], true))->values();
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
            'tabs' => self::tabs($date, $days),
            'arrivals' => $arrivals, 'arrivalSummary' => $arrivalSummary, 'lateEtaIds' => $lateEtaIds, 'departures' => $departures, 'departureSummary' => $departureSummary, 'inhouse' => $inhouse, 'inhouseSummary' => $inhouseSummary, 'inhouseMoneyFlags' => $canViewInhouseFolios ? $inhouseMoneyFlags : [],
            'pipeline' => $view === 'dashboard' ? self::pipeline($date, 14) : null,
            'vipArrivals' => $view === 'dashboard' ? self::vipArrivals($date) : null,
            'onTheBooks' => $view === 'dashboard' ? self::onTheBooks($date) : null,
            'lateArrivals' => $view === 'dashboard' && $date->isSameDay(bdate()) ? self::lateArrivals($date) : collect(),
            'sourceMix' => $view === 'dashboard' ? self::sourceMix($date) : null,
            'walkInArrivals' => $view === 'dashboard' ? self::walkInArrivals($date) : null,
            'compStays' => $view === 'dashboard' ? self::compStays($date) : null,
            'roomTypePipeline' => $view === 'dashboard' ? self::roomTypePipeline($date) : null,
            'groupArrivals' => $view === 'dashboard' ? self::groupArrivals($date) : null,
            'noShows' => $view === 'dashboard' ? self::noShows($date) : null,
            'unassigned' => $unassigned, 'unassignedSummary' => $unassignedSummary, 'groups' => $groups, 'groupSummary' => $groupSummary,
            'waitlist' => $waitlist, 'waitlistSummary' => $waitlistSummary,
            'reservations' => $view === 'list' ? self::listQuery($request)->paginate(self::listPageSize($request))->withQueryString() : null,
            'counts' => $view === 'list' ? self::listStatusCounts($request) : Reservation::select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status'),
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

    private static function validDateString($value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    private static function validPositiveId($value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }
        $id = filter_var((string) $value, FILTER_VALIDATE_INT);

        return $id !== false && $id > 0 ? $id : null;
    }

    public static function listPageSize(Request $request): int
    {
        $value = $request->input('per_page', 25);
        $perPage = is_scalar($value) ? filter_var((string) $value, FILTER_VALIDATE_INT) : false;

        return $perPage !== false && in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
    }

    public static function listStatusCounts(Request $request)
    {
        $filters = $request->query->all();
        unset($filters['status'], $filters['page']);
        $facetRequest = Request::create($request->url(), 'GET', $filters);

        return self::listQuery($facetRequest)->reorder()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')->pluck('aggregate', 'status');
    }

    public static function listQuery(Request $request)
    {
        $date = self::validDateString($request->input('date')) ?? bdate()->toDateString();
        $rawSearch = $request->input('q');
        $search = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 160) : '';
        $rawStatus = $request->input('status');
        $status = is_string($rawStatus) && in_array($rawStatus, ['tentative', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'], true) ? $rawStatus : null;
        $rawType = $request->input('type');
        $type = is_string($rawType) && in_array($rawType, ['individual', 'group'], true) ? $rawType : null;
        $sourceId = self::validPositiveId($request->input('source'));
        $channelId = self::validPositiveId($request->input('channel'));
        $companyId = self::validPositiveId($request->input('company'));
        $arrivalOn = self::validDateString($request->input('arrival_on'));
        $departureOn = self::validDateString($request->input('departure_on'));
        $cancelledOn = self::validDateString($request->input('cancelled_on'));
        $from = self::validDateString($request->input('from'));
        $to = self::validDateString($request->input('to'));
        $registerView = $request->input('view') === 'list';
        $tab = $registerView ? null : $request->input('tab');
        $vipOnly = ! $registerView && ($request->input('tab') === 'vip' || $request->boolean('vip'));
        $q = Reservation::with(['guest', 'source', 'otaChannel', 'rooms.room', 'rooms.roomType', 'company'])
            ->when($search !== '', fn ($w) => $w->where(fn ($x) => $x->where('reservation_no', 'like', "%$search%")
                ->orWhere('group_name', 'like', "%$search%")
                ->orWhere('ota_reference', 'like', "%$search%")
                ->orWhereHas('guest', fn ($g) => $g->whereNull('privacy_status')->search($search))
                ->orWhereHas('company', fn ($company) => $company->where('name', 'like', "%$search%"))
                ->orWhereHas('otaChannel', fn ($channel) => $channel->where('name', 'like', "%$search%"))
                ->orWhereHas('source', fn ($source) => $source->where('name', 'like', "%$search%"))
                ->orWhereHas('rooms.room', fn ($room) => $room->where('room_no', 'like', "%$search%"))
                ->orWhereHas('rooms.roomType', fn ($roomType) => $roomType->where('code', 'like', "%$search%")->orWhere('name', 'like', "%$search%"))))
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($sourceId, fn ($w) => $w->where('business_source_id', $sourceId))
            ->when($arrivalOn, fn ($w) => $w->whereDate('arrival_date', $arrivalOn))
            ->when($departureOn, fn ($w) => $w->whereDate('departure_date', $departureOn))
            ->when($cancelledOn, fn ($w) => $w->whereDate('cancelled_at', $cancelledOn))
            ->when($from, fn ($w) => $w->whereDate('arrival_date', '>=', $from))
            ->when($to, fn ($w) => $w->whereDate('arrival_date', '<=', $to))
            ->when($type, fn ($w) => $w->where('booking_type', $type))
            ->when($channelId, fn ($w) => $w->where('ota_channel_id', $channelId))
            ->when($tab === 'arrivals', fn ($w) => $w->whereDate('arrival_date', $date)->whereIn('status', ['tentative', 'confirmed', 'checked_in']))
            ->when($tab === 'inhouse', fn ($w) => $w->where('status', 'checked_in'))
            ->when($tab === 'stayover', fn ($w) => $w->where('status', 'checked_in')->whereDate('arrival_date', '<=', $date)->whereDate('departure_date', '>', $date))
            ->when($tab === 'dueout', fn ($w) => $w->where('status', 'checked_in')->whereDate('departure_date', $date))
            ->when($tab === 'departures', fn ($w) => $w->whereDate('departure_date', $date)->whereIn('status', ['checked_in', 'checked_out']))
            ->when($tab === 'unassigned', fn ($w) => $w->whereHas('rooms', fn ($r) => $r->whereNull('room_id')->where('status', 'reserved')))
            ->when($vipOnly, fn ($w) => $w->whereHas('guest', fn ($g) => $g->where('is_vip', true))->whereIn('status', ['tentative', 'confirmed', 'checked_in']))
            ->when($tab === 'groups', fn ($w) => $w->where('booking_type', 'group')->whereNotIn('status', ['cancelled', 'no_show']))
            ->when($tab === 'cancelled', fn ($w) => $w->where('status', 'cancelled'))
            ->when($tab === 'duein', fn ($w) => $w->whereDate('arrival_date', $date)->whereIn('status', ['tentative', 'confirmed']))
            ->when($tab === 'noshow', fn ($w) => $w->where('status', 'no_show'))
            ->when($companyId, fn ($w) => $w->where('company_id', $companyId));
        $requestedSort = $request->input('sort', 'arrival');
        $sort = is_string($requestedSort) && in_array($requestedSort, ['arrival', 'arrival_asc', 'arrival_desc', 'departure', 'departure_desc', 'created'], true) ? $requestedSort : 'arrival';
        if ($sort === 'arrival_asc' && $status !== 'checked_out') {
            $sort = 'arrival';
        }
        [$column, $direction] = match ($sort) {
            'created' => ['created_at', 'desc'],
            'arrival_asc' => ['arrival_date', 'asc'],
            'arrival_desc' => ['arrival_date', 'desc'],
            'departure' => ['departure_date', 'asc'],
            'departure_desc' => ['departure_date', 'desc'],
            default => ['arrival_date', $status === 'checked_out' ? 'desc' : 'asc'],
        };
        $tieDirection = in_array($sort, ['created', 'arrival_desc', 'departure_desc'], true) ? 'desc' : 'asc';
        $q->orderBy($column, $direction)->orderBy('id', $tieDirection);

        return $q;
    }

    /** Selected-date N-day outlook: arrivals, departures, expected rooms, occupancy, and room revenue. Read-only. */
    public static function pipeline(Carbon $date, int $days = 7): array
    {
        $roomRows = Room::where('is_active', true)->get(['id', 'status']);
        $sellableByDay = AvailabilityService::sellableRoomCounts($date, $days, $roomRows);
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $date->copy()->addDays($i);
            $ds = $d->toDateString();
            $dateStatuses = self::deskDateStatuses($d);
            $covering = ReservationRoom::whereIn('status', $dateStatuses['covering'])
                ->whereDate('arrival_date', '<=', $ds)->whereDate('departure_date', '>', $ds);
            $rooms = (clone $covering)->count();
            $out[] = [
                'date' => $d,
                'arrivals' => ReservationRoom::whereDate('arrival_date', $ds)->whereIn('status', $dateStatuses['arrivals'])->count(),
                'departures' => ReservationRoom::whereDate('departure_date', $ds)->whereIn('status', $dateStatuses['departures'])->count(),
                'rooms' => $rooms,
                'occ' => ($sellableByDay[$ds] ?? 0) ? round($rooms / $sellableByDay[$ds] * 100, 1) : null,
                'revenue' => (float) (clone $covering)->sum('rate'),
            ];
        }

        return $out;
    }

    /** Expected room revenue (pre-tax), summed per occupied night in each 14- and 30-day window. Read-only. */
    public static function onTheBooks(Carbon $date): array
    {
        $start = $date->copy()->startOfDay();
        $sum = function (int $days) use ($start): float {
            $end = $start->copy()->addDays($days);
            $businessDate = bdate()->copy()->startOfDay();
            $stays = ReservationRoom::whereIn('status', ['reserved', 'checked_in', 'checked_out'])
                ->whereDate('arrival_date', '<', $end->toDateString())
                ->whereDate('departure_date', '>', $start->toDateString())
                ->get(['arrival_date', 'departure_date', 'rate', 'status']);

            return (float) $stays->sum(fn ($stay) => self::roomRevenueInWindow($stay, $start, $end, $businessDate));
        };

        return ['days14' => round($sum(14), 2), 'days30' => round($sum(30), 2)];
    }

    /** Count stay nights supported by its current status on either side of the business date. */
    private static function roomNightsInWindow(ReservationRoom $stay, Carbon $start, Carbon $end, Carbon $businessDate): int
    {
        $from = $stay->arrival_date->greaterThan($start) ? $stay->arrival_date->copy() : $start->copy();
        $to = $stay->departure_date->lessThan($end) ? $stay->departure_date->copy() : $end->copy();
        if (! $from->lt($to)) {
            return 0;
        }

        $nights = 0;
        if (in_array($stay->status, ['checked_in', 'checked_out'], true)) {
            $historicalEnd = $to->lessThan($businessDate) ? $to : $businessDate;
            if ($from->lt($historicalEnd)) {
                $nights += (int) $from->diffInDays($historicalEnd);
            }
        }
        if (in_array($stay->status, ['reserved', 'checked_in'], true)) {
            $futureStart = $from->greaterThan($businessDate) ? $from : $businessDate;
            if ($futureStart->lt($to)) {
                $nights += (int) $futureStart->diffInDays($to);
            }
        }

        return max(0, $nights);
    }

    /** Room rates are nightly, so revenue is the rate multiplied by eligible overlapping nights. */
    private static function roomRevenueInWindow(ReservationRoom $stay, Carbon $start, Carbon $end, Carbon $businessDate): float
    {
        return (float) $stay->rate * self::roomNightsInWindow($stay, $start, $end, $businessDate);
    }

    /** Arrivals whose ETA has passed but who have not checked in. Read-only. */
    public static function lateArrivals(Carbon $date)
    {
        $now = now();

        return ReservationRoom::with(['reservation.guest', 'room'])
            ->whereDate('arrival_date', $date->toDateString())
            ->where('status', 'reserved')
            ->get()
            ->filter(fn ($stay) => self::etaHasPassed(
                $date,
                $stay->reservation?->eta,
                $now,
            ))
            ->sortBy(fn ($s) => (string) $s->reservation->eta)
            ->values();
    }

    /** Compare an ETA on its actual arrival date with the current instant. */
    public static function etaHasPassed(Carbon $arrivalDate, ?string $eta, Carbon $now): bool
    {
        if (! filled($eta)) {
            return false;
        }
        $time = substr($eta, 0, 5);
        if (! preg_match('/^\\d{2}:\\d{2}$/', $time)) {
            return false;
        }

        try {
            $etaAt = $arrivalDate->copy()->startOfDay()->setTimeFromTimeString($time);
        } catch (\Throwable) {
            return false;
        }

        return $etaAt->lt($now);
    }

    /** Booking sources and nightly revenue for stays covering the selected $days-day window. Read-only. */
    public static function sourceMix(Carbon $date, int $days = 14)
    {
        $start = $date->copy()->startOfDay();
        $end = $start->copy()->addDays($days);
        $businessDate = bdate()->copy()->startOfDay();

        return ReservationRoom::with('reservation.source')
            ->whereIn('status', ['reserved', 'checked_in', 'checked_out'])
            ->whereDate('arrival_date', '<', $end->toDateString())
            ->whereDate('departure_date', '>', $start->toDateString())
            ->get()
            ->filter(fn ($stay) => self::roomNightsInWindow($stay, $start, $end, $businessDate) > 0)
            ->groupBy(fn ($s) => $s->reservation?->source?->name ?? 'No source')
            ->map(fn ($g, $name) => [
                'source' => $name,
                'rooms' => $g->count(),
                'revenue' => (float) $g->sum(fn ($stay) => self::roomRevenueInWindow($stay, $start, $end, $businessDate)),
            ])
            ->sortByDesc('revenue')
            ->values();
    }

    /** Walk-in arrivals for the given date. Read-only. */
    public static function walkInArrivals(Carbon $date)
    {
        $dateStatuses = self::deskDateStatuses($date);

        return ReservationRoom::with(['reservation.guest', 'room'])
            ->whereDate('arrival_date', $date->toDateString())
            ->whereIn('status', $dateStatuses['arrivals'])
            ->whereHas('reservation.source', fn ($s) => $s->where('type', 'walk_in'))
            ->orderBy('id')
            ->get();
    }

    /** Complimentary and house-use stays covering the given date. Read-only. */
    public static function compStays(Carbon $date)
    {
        $d = $date->toDateString();
        $dateStatuses = self::deskDateStatuses($date);

        return ReservationRoom::with(['reservation.guest', 'room'])
            ->whereIn('status', $dateStatuses['covering'])
            ->whereDate('arrival_date', '<=', $d)
            ->whereDate('departure_date', '>', $d)
            ->get()
            ->filter(fn ($s) => in_array(self::stayLabel($s, $date), ['Complimentary', 'House use'], true))
            ->values();
    }

    /** Rooms expected by room type on the desk date and across the following $days window. Read-only. */
    public static function roomTypePipeline(Carbon $date, int $days = 14)
    {
        $deskDate = $date->copy()->startOfDay();
        $deskDay = $deskDate->toDateString();
        $end = $deskDate->copy()->addDays($days);
        $businessDate = bdate()->copy()->startOfDay();
        $window = ReservationRoom::with('roomType')
            ->whereIn('status', ['reserved', 'checked_in', 'checked_out'])
            ->whereDate('arrival_date', '<', $end->toDateString())
            ->whereDate('departure_date', '>', $deskDay)
            ->get()
            ->filter(fn ($stay) => self::roomNightsInWindow($stay, $deskDate, $end, $businessDate) > 0);
        $deskEnd = $deskDate->copy()->addDay();
        $deskDateIds = $window
            ->filter(fn ($stay) => self::roomNightsInWindow($stay, $deskDate, $deskEnd, $businessDate) > 0)
            ->pluck('id');

        return $window
            ->groupBy(fn ($s) => $s->roomType?->name ?? 'No type')
            ->map(function ($g, $name) use ($deskDateIds, $deskDate, $end, $businessDate) {
                return [
                    'type' => $name,
                    'desk_date' => $g->whereIn('id', $deskDateIds)->count(),
                    'bookings' => $g->count(),
                    'revenue' => (float) $g->sum(fn ($stay) => self::roomRevenueInWindow($stay, $deskDate, $end, $businessDate)),
                ];
            })
            ->sortByDesc('revenue')
            ->values();
    }

    /** Active group reservations overlapping a desk window, with historical rows allowed only before the business date. */
    private static function groupReservationsInWindow(Carbon $date, int $days = 14)
    {
        $deskDate = $date->copy()->startOfDay();
        $end = $deskDate->copy()->addDays($days)->toDateString();
        $start = $deskDate->toDateString();
        $businessDate = bdate()->copy()->startOfDay();
        $statuses = $deskDate->lt($businessDate)
            ? ['checked_in', 'checked_out']
            : ['tentative', 'confirmed', 'checked_in'];

        return Reservation::where('booking_type', 'group')
            ->whereIn('status', $statuses)
            ->whereDate('arrival_date', '<', $end)
            ->whereDate('departure_date', '>', $start);
    }

    /** Group bookings arriving within the selected $days-day window. Read-only. */
    public static function groupArrivals(Carbon $date, int $days = 7)
    {
        $businessDate = bdate()->copy()->startOfDay();

        return Reservation::with(['guest', 'rooms.room'])
            ->where('booking_type', 'group')
            ->whereIn('status', ['tentative', 'confirmed', 'checked_in', 'checked_out'])
            ->whereDate('arrival_date', '>=', $date->toDateString())->whereDate('arrival_date', '<=', $date->copy()->addDays($days - 1)->toDateString())
            ->orderBy('arrival_date')->orderBy('id')
            ->get()
            ->filter(function ($group) use ($businessDate) {
                $historical = $group->arrival_date->copy()->startOfDay()->lt($businessDate);
                $statuses = $historical ? ['checked_in', 'checked_out'] : ['tentative', 'confirmed', 'checked_in'];

                return in_array($group->status, $statuses, true);
            })
            ->values();
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

    /** VIP stays arriving within the selected $days-day window. Read-only. */
    public static function vipArrivals(Carbon $date, int $days = 7)
    {
        $businessDate = bdate()->copy()->startOfDay();

        return ReservationRoom::with(['reservation.guest', 'room'])
            ->whereIn('status', ['reserved', 'checked_in', 'checked_out'])
            ->whereDate('arrival_date', '>=', $date->toDateString())->whereDate('arrival_date', '<=', $date->copy()->addDays($days - 1)->toDateString())
            ->whereHas('reservation.guest', fn ($g) => $g->where('is_vip', true))
            ->orderBy('arrival_date')->orderBy('id')->get()
            ->filter(function ($stay) use ($businessDate) {
                $historical = $stay->arrival_date->copy()->startOfDay()->lt($businessDate);
                $statuses = $historical ? ['checked_in', 'checked_out'] : ['reserved', 'checked_in'];

                return in_array($stay->status, $statuses, true);
            })
            ->values();
    }

    private static function deskDateStatuses(Carbon $date): array
    {
        $selectedDate = $date->copy()->startOfDay();
        $businessDate = bdate()->copy()->startOfDay();
        $historical = $selectedDate->lt($businessDate);
        $current = $selectedDate->isSameDay($businessDate);
        $covering = $historical ? ['checked_in', 'checked_out'] : ['reserved', 'checked_in'];

        return [
            'covering' => $covering,
            'arrivals' => $covering,
            'departures' => $historical || $current ? ['checked_in', 'checked_out'] : ['reserved', 'checked_in'],
            'due_out' => $historical ? ['checked_in', 'checked_out'] : ($current ? ['checked_in'] : ['reserved', 'checked_in']),
            'due_in' => $historical ? [] : ['reserved'],
            'reservation_covering' => $historical ? ['checked_in', 'checked_out'] : ['tentative', 'confirmed', 'checked_in'],
            'reservation_arrivals' => $historical ? ['checked_in', 'checked_out'] : ['tentative', 'confirmed', 'checked_in'],
        ];
    }

    public static function kpis(Carbon $date): array
    {
        $d = $date->toDateString();
        $dateStatuses = self::deskDateStatuses($date);
        $coveringStatuses = $dateStatuses['covering'];
        $covering = ReservationRoom::whereIn('status', $coveringStatuses)->whereDate('arrival_date', '<=', $d)->whereDate('departure_date', '>', $d);
        $sold = (clone $covering)->count();
        $onBooks = (float) (clone $covering)->sum('rate');
        $roomRows = Room::where('is_active', true)->get(['id', 'status', 'hk_status']);
        $sellable = AvailabilityService::sellableRoomCounts($date, 1, $roomRows)[$d] ?? 0;
        $historical = $date->copy()->startOfDay()->lt(bdate()->copy()->startOfDay());
        $available = $historical
            ? AvailabilityService::historicalReservationRooms($date)->count()
            : AvailabilityService::reservationRooms($d, $date->copy()->addDay()->toDateString())->count();
        $posted = (float) FolioTransaction::whereDate('business_date', $d)->where('is_void', false)->where('type', 'charge')->sum(DB::raw('amount + tax_amount'));
        $roomPosted = (float) FolioTransaction::whereDate('business_date', $d)->where('is_void', false)->where('type', 'charge')->where('category', 'room')->sum(DB::raw('amount + tax_amount'));
        $deposits = (float) FolioTransaction::whereDate('business_date', $d)->where('is_void', false)->where('type', 'payment')->where('category', 'advance')->sum('amount');
        $openIds = Folio::where('status', 'open')
            ->where(fn ($query) => $query->whereNotNull('reservation_id')->orWhereNotNull('reservation_room_id'))
            ->pluck('id');
        $outstanding = 0.0;
        if ($openIds->isNotEmpty()) {
            $outstanding = (float) (FolioTransaction::whereIn('folio_id', $openIds)->where('is_void', false)
                ->selectRaw("coalesce(sum(case type when 'charge' then amount + tax_amount when 'discount' then -amount when 'allowance' then -amount when 'payment' then -amount when 'refund' then amount else 0 end), 0) as bal")
                ->first()->bal ?? 0);
        }

        return [
            'arrivals' => ReservationRoom::whereDate('arrival_date', $d)->whereIn('status', $dateStatuses['arrivals'])->count(),
            'inhouse' => ReservationRoom::where('status', 'checked_in')->count(),
            'due_out' => ReservationRoom::whereDate('departure_date', $d)->whereIn('status', $dateStatuses['due_out'])->count(),
            'available' => $available,
            'occupancy' => $sellable ? round($sold / $sellable * 100, 1) : null,
            'adr' => $sold ? round($onBooks / $sold, 2) : null,
            'on_books' => round($onBooks, 2),
            'posted' => round($posted, 2),
            'outstanding' => round($outstanding, 2),
            'unassigned' => ReservationRoom::where('status', 'reserved')->whereNull('room_id')->whereDate('departure_date', '>', $d)->count(),
            'vip' => ReservationRoom::whereIn('status', $dateStatuses['covering'])->whereDate('arrival_date', '<=', $d)->whereDate('departure_date', '>', $d)->whereHas('reservation.guest', fn ($g) => $g->where('is_vip', true))->count(),
            'groups' => Reservation::where('booking_type', 'group')->whereIn('status', $dateStatuses['reservation_covering'])->whereDate('arrival_date', '<=', $d)->whereDate('departure_date', '>', $d)->count(),
            'sellable' => $sellable,
            'sold' => $sold,
            'total' => $roomRows->count(),
            'occupied' => $roomRows->where('status', 'occupied')->count(),
            'reserved' => (clone $covering)->where('status', 'reserved')->count(),
            'vacant_clean' => $roomRows->where('status', 'vacant')->where('hk_status', 'clean')->count(),
            'vacant_dirty' => $roomRows->where('status', 'vacant')->where('hk_status', 'dirty')->count(),
            'inspected' => $roomRows->where('hk_status', 'inspected')->count(),
            'cleaning' => HousekeepingTask::whereDate('task_date', $d)->where('status', 'in_progress')->distinct()->count('room_id'),
            'maintenance' => MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->whereNotNull('room_id')->distinct()->count('room_id'),
            'ooo' => $roomRows->where('status', 'out_of_order')->count(),
            'oos' => $roomRows->where('status', 'out_of_service')->count(),
            'blocked' => $historical
                ? AvailabilityService::historicalOutageRoomIds($date)->count()
                : RoomOutage::covering($d, $date->copy()->addDay())->distinct('room_id')->count('room_id'),
            'stayover' => ReservationRoom::where('status', 'checked_in')->whereDate('arrival_date', '<=', $d)->whereDate('departure_date', '>', $d)->count(),
            'due_in' => $dateStatuses['due_in'] === [] ? 0 : ReservationRoom::whereDate('arrival_date', $d)->whereIn('status', $dateStatuses['due_in'])->count(),
            'walkins' => ReservationRoom::whereDate('arrival_date', $d)->whereIn('status', $dateStatuses['arrivals'])->whereHas('reservation.source', fn ($s) => $s->where('type', 'walk_in'))->count(),
            'noshow' => Reservation::where('status', 'no_show')->whereDate('arrival_date', $d)->count(),
            'cancelled' => Reservation::where('status', 'cancelled')->whereDate('cancelled_at', $d)->count(),
            'waitlist' => ReservationWaitlist::where('status', 'waiting')->count(),
            'room_posted' => round($roomPosted, 2),
            'deposits' => round($deposits, 2),
            'revpar' => $sellable ? round($roomPosted / $sellable, 2) : null,
        ];
    }

    public static function tabs(Carbon $date, int $days = 14): array
    {
        $d = $date->toDateString();
        $dateStatuses = self::deskDateStatuses($date);

        return [
            'arrivals' => ReservationRoom::whereDate('arrival_date', $d)->whereIn('status', $dateStatuses['arrivals'])->count(),
            'inhouse' => ReservationRoom::where('status', 'checked_in')->count(),
            'stayover' => ReservationRoom::where('status', 'checked_in')->whereDate('arrival_date', '<=', $d)->whereDate('departure_date', '>', $d)->count(),
            'dueout' => ReservationRoom::whereDate('departure_date', $d)->where('status', 'checked_in')->count(),
            'departures' => ReservationRoom::whereDate('departure_date', $d)->whereIn('status', $dateStatuses['departures'])->count(),
            'unassigned' => ReservationRoom::where('status', 'reserved')->whereNull('room_id')->whereDate('departure_date', '>', $d)->count(),
            'vip' => ReservationRoom::whereIn('status', $dateStatuses['covering'])->whereHas('reservation.guest', fn ($g) => $g->where('is_vip', true))->whereDate('arrival_date', '<=', $d)->whereDate('departure_date', '>', $d)->count(),
            'groups' => self::groupReservationsInWindow($date, $days)->count(),
            'waitlist' => ReservationWaitlist::where('status', 'waiting')->count(),
            'cancelled' => Reservation::where('status', 'cancelled')->count(),
            'duein' => $dateStatuses['due_in'] === [] ? 0 : ReservationRoom::whereDate('arrival_date', $d)->whereIn('status', $dateStatuses['due_in'])->count(),
            'noshow' => Reservation::where('status', 'no_show')->count(),
        ];
    }

    public static function stayLabel(ReservationRoom $stay, Carbon $date): string
    {
        $purpose = mb_strtolower((string) $stay->reservation?->purpose);
        if (str_contains($purpose, 'house use')) {
            return 'House use';
        }
        if ((float) $stay->rate === 0.0 && in_array($stay->status, ['reserved', 'checked_in', 'checked_out'], true)) {
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
            ->selectRaw("folio_id, coalesce(sum(case type when 'payment' then amount when 'refund' then -amount else 0 end), 0) as paid, coalesce(sum(case type when 'charge' then amount + tax_amount when 'opening_balance' then amount when 'discount' then -amount when 'allowance' then -amount when 'payment' then -amount when 'refund' then amount else 0 end), 0) as bal")
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
            FolioTransaction::whereDate('business_date', $d)->max('updated_at'),
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

    /**
     * Web path for the room board photo. Uploaded room/type images win; the
     * room-type media column seeded in v7.41 is the fallback, so a fresh build
     * still shows the standard photos when nothing has been uploaded.
     */
    public static function photoPath(Room $room): ?string
    {
        return self::photo($room)?->path ?: $room->roomType?->image ?: null;
    }

    /** Same refusals as block(). Writes nothing. */
    public static function describeBlock(int $roomId, string $from, string $to, string $reason): string
    {
        [$room, $starts, $ends] = self::blockWindow($roomId, $from, $to);
        $label = RoomOutage::REASONS[$reason] ?? $reason;
        $lastBlockedNight = $ends->copy()->subDay();
        $ooo = $starts->lte(bdate()) && $ends->gt(bdate()) && $room->status === 'vacant';

        return "Block room {$room->room_no} from {$starts->format('d M Y')} through {$lastBlockedNight->format('d M Y')}; it returns on {$ends->format('d M Y')} ({$label}). "
            .($ooo ? 'The block covers today and the room is vacant, so confirming would mark it out of order.' : 'Room status would not change.')
            .' Nothing is saved until you confirm. A block does not post a charge.';
    }

    public static function block(int $roomId, string $from, string $to, string $reason, ?string $notes): RoomOutage
    {
        return DB::transaction(function () use ($roomId, $from, $to, $reason, $notes) {
            // Serialise blocks with reservation placement and other desk users.
            Room::whereKey($roomId)->lockForUpdate()->firstOrFail();
            [$room, $starts, $ends] = self::blockWindow($roomId, $from, $to);
            $setsOutOfOrder = $starts->lte(bdate()) && $ends->gt(bdate()) && $room->status === 'vacant';
            $outage = RoomOutage::create([
                'room_id' => $room->id, 'starts_on' => $starts->toDateString(), 'ends_on' => $ends->toDateString(),
                'reason' => $reason, 'notes' => $notes, 'status' => 'active', 'created_by' => auth()->id(),
                'room_status_changed_by_block' => $setsOutOfOrder,
            ]);
            if ($setsOutOfOrder) {
                $room->update(['status' => 'out_of_order', 'notes' => trim(($notes ?: 'Blocked from the reservation desk').' · until '.$ends->format('d M'))]);
            }
            ActivityLogger::log('reservations', 'block', "Blocked room {$room->room_no} {$starts->format('d M')}–{$ends->copy()->subDay()->format('d M')} (returns {$ends->format('d M')}, {$reason})", $room);

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
            ->whereDate('arrival_date', '<', $ends->toDateString())->whereDate('departure_date', '>', $starts->toDateString())->exists()) {
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
            if ($room && $locked->room_status_changed_by_block && $room->status === 'out_of_order' && ! $still && ! $ticket) {
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
        $dateStatuses = self::deskDateStatuses($date);

        return $stays->filter(function ($s) use ($request, $tab, $day, $dateStatuses) {
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
                    $res?->reservation_no, $res?->group_name, $res?->ota_reference, $res?->guest?->full_name,
                    can_do('guests.view') ? $res?->guest?->phone : null,
                    $s->room?->room_no,
                ])));
                if (! str_contains($blob, $q)) {
                    return false;
                }
            }
            return match ($tab) {
                'arrivals' => $s->arrival_date->toDateString() === $day && in_array($s->status, $dateStatuses['arrivals'], true),
                'inhouse' => $s->status === 'checked_in',
                'stayover' => $s->status === 'checked_in' && $s->arrival_date->toDateString() <= $day && $s->departure_date->toDateString() > $day,
                'dueout' => $s->status === 'checked_in' && $s->departure_date->toDateString() === $day,
                'departures' => $s->departure_date->toDateString() === $day && in_array($s->status, $dateStatuses['departures'], true),
                'duein' => $s->arrival_date->toDateString() === $day && $s->status === 'reserved',
                'unassigned' => $s->room_id === null && $s->status === 'reserved',
                'vip' => (bool) $res?->guest?->is_vip
                    && $s->arrival_date->toDateString() <= $day
                    && $s->departure_date->toDateString() > $day
                    && in_array($s->status, $dateStatuses['covering'], true),
                'groups' => $res?->booking_type === 'group',
                'cancelled' => $res?->status === 'cancelled',
                default => true,
            };
        })->values();
    }
}
