<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\GuestFeedback;
use App\Models\GuestRequest;
use App\Models\Hotel;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\ReservationRoom;
use App\Models\SpaAppointment;
use App\Models\SpaService;
use App\Services\GuestPortalService;
use App\Services\SpaBookingService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Public guest mobile portal – /stay/{token}. The 40-character token printed on the welcome card / sent by SMS
 * is the only credential; it gives access to one stay only, and only while that stay is current
 * (plus a short read-only window after check-out for the bill and feedback).
 */
class GuestPortalController extends Controller
{
    protected function stay(string $token): ReservationRoom
    {
        $stay = ReservationRoom::withoutGlobalScopes()->where('portal_token', $token)->firstOrFail();
        $hotel = Hotel::find($stay->hotel_id);
        abort_unless($hotel?->is_active, 404);
        Tenant::set($hotel);
        abort_unless(GuestPortalService::enabled(), 404);
        $stay = ReservationRoom::with('reservation.guest', 'guest', 'room', 'roomType')->findOrFail($stay->id);
        if (! $stay->portal_seen_at || $stay->portal_seen_at->lt(now()->subMinutes(5))) {
            $stay->forceFill(['portal_seen_at' => now()])->saveQuietly();
        }

        return $stay;
    }

    /** Guard + common view data. Closed stays get a friendly "link expired" page. */
    protected function page(ReservationRoom $stay, string $view, string $tab, array $data = [], array $allowed = ['inhouse', 'upcoming', 'departed'])
    {
        $state = $stay->portalState();
        if ($state === 'closed' || ! in_array($state, $allowed)) {
            $view = $state === 'closed' ? 'portal.expired' : 'portal.unavailable';
        }
        $guest = $stay->guest ?? $stay->reservation?->guest;

        return response()->view($view, $data + [
            'hotel' => hotel(), 'stay' => $stay, 'guest' => $guest, 'state' => $state, 'tab' => $tab, 'token' => $stay->portal_token,
            'diningOn' => (bool) GuestPortalService::diningOutlet(), 'spaOn' => setting('portal_spa_enabled', '1') === '1' && SpaService::where('is_active', true)->exists(),
            'transportOn' => self::transportOn(),
            'exploreOn' => in_array($state, ['inhouse', 'upcoming'], true) && (\App\Services\ConciergeService::portalOn() || \App\Models\ConciergePlace::where('portal_visible', true)->exists()), // v5.9
            'forexOn' => $state === 'inhouse' && self::forexOn($stay),
            'openRequests' => GuestRequest::where('reservation_room_id', $stay->id)->whereIn('status', GuestRequest::OPEN)->count(),
            'chatOn' => in_array($state, ['inhouse', 'upcoming'], true) && \App\Services\InboxService::portalOn(), // v7.3
            'chatUnread' => ($pc = \App\Services\InboxService::portalOn() ? \App\Services\InboxService::portalThread($stay) : null) ? \App\Models\ConversationMessage::where('conversation_id', $pc->id)->where('direction', 'out')->whereNull('read_at')->count() : 0,
            'deskMessages' => \App\Models\GuestMessage::where('reservation_room_id', $stay->id)->whereIn('status', \App\Models\GuestMessage::OPEN)->whereNull('portal_read_at')->count(), // v3.5
        ])->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }

    protected static function transportOn(): bool
    {
        return \App\Models\Vehicle::where('is_active', true)->exists();
    }

    protected function fail(RuntimeException $e)
    {
        return back()->withInput()->withErrors(['portal' => $e->getMessage()]);
    }

    public function home(string $token)
    {
        $stay = $this->stay($token);
        $folios = Folio::where('reservation_room_id', $stay->id)->get();
        $balance = $folios->sum(fn ($f) => $f->totals()['balance']);
        $info = collect(preg_split('/\r?\n/', (string) setting('portal_info', SettingController::KEYS['portal_info'][2])))->map(fn ($l) => trim($l))->filter()->values();
        $recent = GuestRequest::where('reservation_room_id', $stay->id)->latest('id')->limit(3)->get();
        $spa = SpaAppointment::with('service')->where('reservation_room_id', $stay->id)->where('status', 'booked')->orderBy('date')->orderBy('start_time')->limit(3)->get();
        $wakeups = \App\Models\WakeupCall::where('reservation_room_id', $stay->id)->where('status', 'scheduled')->orderBy('call_at')->limit(3)->get(); // v3.5

        return $this->page($stay, 'portal.home', 'home', compact('balance', 'info', 'recent', 'spa', 'wakeups'));
    }

    // ───────── service requests ─────────

    public function requests(string $token)
    {
        $stay = $this->stay($token);
        $list = GuestRequest::where('reservation_room_id', $stay->id)->latest('id')->limit(50)->get();
        $cats = collect(GuestRequest::CATEGORIES)->except(self::transportOn() ? ['checkout', 'transport'] : ['checkout'])->map(fn ($c, $k) => ['label' => $c[0], 'icon' => $c[1], 'items' => GuestPortalService::items($k)]);

        $laundry = [
            'prices' => \App\Services\LaundryService::priceList(), 'express' => \App\Services\LaundryService::expressPct(),
            'hours' => ['normal' => \App\Services\LaundryService::hours('normal'), 'express' => \App\Services\LaundryService::hours('express')],
            'orders' => \App\Models\LaundryOrder::where('reservation_room_id', $stay->id)->where('status', '!=', 'cancelled')->latest('id')->limit(10)->get(),
        ];

        return $this->page($stay, 'portal.requests', 'requests', compact('list', 'cats', 'laundry'), ['inhouse', 'upcoming', 'departed']);
    }

    public function storeRequest(Request $request, string $token)
    {
        $stay = $this->stay($token);
        $data = $request->validate([
            'category' => 'required|string|max:20', 'item' => 'nullable|string|max:120', 'quantity' => 'nullable|integer|min:1|max:10',
            'notes' => 'nullable|string|max:500', 'preferred_at' => 'nullable|date', 'time' => 'nullable|date_format:H:i', 'website' => 'nullable|size:0',
        ]);
        try {
            $req = GuestPortalService::raise($stay, $data);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('portal.requests', $token)->with('success', "Request sent ({$req->request_no}). We'll take care of it.");
    }

    protected function myRequest(ReservationRoom $stay, int $rid): GuestRequest
    {
        return GuestRequest::where('reservation_room_id', $stay->id)->findOrFail($rid);
    }

    public function cancelRequest(string $token, int $rid)
    {
        $req = $this->myRequest($this->stay($token), $rid);
        try {
            GuestPortalService::transition($req, 'cancel', 'Cancelled by guest');
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Request cancelled.');
    }

    public function rateRequest(Request $request, string $token, int $rid)
    {
        $req = $this->myRequest($this->stay($token), $rid);
        $data = $request->validate(['rating' => 'required|integer|min:1|max:5']);
        try {
            GuestPortalService::rate($req, $data['rating']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Thank you for your rating!');
    }

    // ───────── in-room dining ─────────

    public function dining(string $token)
    {
        $stay = $this->stay($token);
        $outlet = GuestPortalService::diningOutlet();
        $cats = $items = collect();
        if ($outlet) {
            $cats = MenuCategory::where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))->orderBy('sort_order')->get();
            $items = MenuItem::with(['modifierGroups' => fn ($q) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))->with('modifiers')])
                ->where('is_available', true)->whereIn('menu_category_id', $cats->pluck('id'))
                ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))->orderBy('name')->get();
            $cats = $cats->filter(fn ($c) => $items->contains('menu_category_id', $c->id))->values();
        }
        $orders = PosOrder::with('items')->where('reservation_room_id', $stay->id)->where('source', 'portal')->latest('id')->limit(5)->get();

        return $this->page($stay, $outlet ? 'portal.dining' : 'portal.unavailable', 'dining', compact('outlet', 'cats', 'items', 'orders'), ['inhouse']);
    }

    public function orderDining(Request $request, string $token)
    {
        $stay = $this->stay($token);
        $data = $request->validate([
            'items' => 'required|array|min:1|max:30', 'items.*.id' => 'required|integer|owned:menu_items',
            'items.*.qty' => 'required|integer|min:1|max:20', 'items.*.notes' => 'nullable|string|max:120',
            'items.*.modifiers' => 'nullable|array|max:20', 'items.*.modifiers.*' => 'integer|owned:menu_modifiers', 'website' => 'nullable|size:0',
        ]);
        try {
            $order = GuestPortalService::orderDining($stay, $data['items']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('portal.dining', $token)->with('success', "Order sent ({$order->order_no}). The restaurant will confirm it shortly – it will be charged to your room.");
    }

    // ───────── spa & activities ─────────

    public function spa(Request $request, string $token)
    {
        $stay = $this->stay($token);
        $services = SpaService::where('is_active', true)->orderBy('category')->orderBy('name')->get();
        $service = $request->filled('service') ? $services->firstWhere('id', (int) $request->query('service')) : null;
        $from = max(bdate()->toDateString(), now()->toDateString());
        $to = $stay->departure_date->toDateString();
        $dates = collect();
        for ($d = Carbon::parse($from); $d->toDateString() <= $to && $dates->count() < 14; $d->addDay()) {
            $dates->push($d->toDateString());
        }
        $date = in_array($request->query('date'), $dates->all(), true) ? $request->query('date') : $dates->first();
        $slots = $service && $date ? GuestPortalService::spaSlots($service, $date) : [];
        $mine = SpaAppointment::with('service')->where('reservation_room_id', $stay->id)->latest('date')->latest('start_time')->limit(10)->get();

        return $this->page($stay, setting('portal_spa_enabled', '1') === '1' ? 'portal.spa' : 'portal.unavailable', 'spa', compact('services', 'service', 'dates', 'date', 'slots', 'mine'), ['inhouse']);
    }

    public function bookSpa(Request $request, string $token)
    {
        $stay = $this->stay($token);
        $data = $request->validate(['spa_service_id' => 'required|integer|owned:spa_services', 'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i', 'pax' => 'nullable|integer|min:1|max:4']);
        try {
            $a = GuestPortalService::bookSpa($stay, $data['spa_service_id'], $data['date'], $data['time'], (int) ($data['pax'] ?? 1));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('portal.spa', $token)->with('success', "Booked: {$a->service->name} on ".$a->date->format('D d M').' at '.substr($a->start_time, 0, 5).". It will be added to your bill.");
    }

    public function cancelSpa(string $token, int $aid)
    {
        $stay = $this->stay($token);
        $a = SpaAppointment::where('reservation_room_id', $stay->id)->findOrFail($aid);
        if ($a->status !== 'booked' || Carbon::parse($a->date->toDateString().' '.$a->start_time)->lt(now()->addHours(2))) {
            return back()->withErrors(['portal' => 'This booking can no longer be cancelled online – please call the spa.']);
        }
        SpaBookingService::setStatus($a, 'cancelled', 'Cancelled by guest via portal');

        return back()->with('success', 'Booking cancelled.');
    }

    // ───────── v3.3 airport transfers ─────────

    public function transport(string $token)
    {
        $stay = $this->stay($token);
        $trips = \App\Models\TransportTrip::with('vehicle', 'driver')->where('reservation_room_id', $stay->id)->where(fn ($q) => $q->whereIn('status', \App\Models\TransportTrip::OPEN)
            ->orWhere('scheduled_at', '>=', now()->subDays(2)))->orderBy('scheduled_at')->limit(10)->get();
        $tariffs = \App\Models\TransportTariff::where('is_active', true)->whereIn('kind', ['airport', 'railway'])->orderBy('sort_order')->limit(6)->get();

        return $this->page($stay, 'portal.transport', 'transport', ['trips' => $trips, 'tariffs' => $tariffs, 'cfg' => \App\Services\TransportService::cfg()], ['inhouse', 'upcoming']);
    }

    /** v3.4 today's buying rates at the forex desk (foreign guests only). */
    public function exchange(string $token)
    {
        $stay = $this->stay($token);
        abort_unless(self::forexOn($stay), 404);
        $rates = \App\Services\ForexService::todayRates();
        $currencies = \App\Models\Currency::where('is_active', true)->orderBy('sort_order')->get()->filter(fn ($c) => isset($rates[$c->id]));

        return $this->page($stay, 'portal.exchange', 'exchange', ['rates' => $rates, 'currencies' => $currencies, 'cfg' => \App\Services\ForexService::cfg()], ['inhouse']);
    }

    // ───────── v3.5 messages & parcels held at the desk ─────────

    public function messages(string $token)
    {
        $stay = $this->stay($token);
        $list = \App\Models\GuestMessage::where('reservation_room_id', $stay->id)->where('status', '!=', 'cancelled')->latest('id')->limit(30)->get();
        $unseen = $list->whereNull('portal_read_at')->pluck('id')->all();
        if ($stay->portalState() === 'inhouse') {
            \App\Services\GuestMessageService::portalRead($stay); // opening the page counts as the guest having read them
        }

        return $this->page($stay, 'portal.messages', 'messages', ['list' => $list, 'unseen' => $unseen], ['inhouse', 'upcoming']);
    }

    // ───────── v7.3 chat with the hotel (the guest side of the shared inbox) ─────────

    public function chat(string $token)
    {
        $stay = $this->stay($token);
        abort_unless(\App\Services\InboxService::portalOn(), 404);
        $c = \App\Services\InboxService::portalThread($stay);
        $list = \App\Services\InboxService::portalMessages($c);
        \App\Services\InboxService::portalSeen($c);

        return $this->page($stay, 'portal.chat', 'chat', ['conv' => $c, 'list' => $list, 'inHours' => \App\Services\InboxService::inHours(), 'cfg' => \App\Services\InboxService::cfg(),
            'canWrite' => in_array($stay->portalState(), ['inhouse', 'upcoming'], true)], ['inhouse', 'upcoming', 'departed']);
    }

    public function chatPoll(Request $request, string $token)
    {
        $stay = $this->stay($token);
        abort_unless(\App\Services\InboxService::portalOn() && $stay->portalState() !== 'closed', 404);
        $c = \App\Services\InboxService::portalThread($stay);
        $new = \App\Services\InboxService::portalMessages($c, max(0, (int) $request->query('after', 0)));
        if ($new->where('direction', 'out')->isNotEmpty()) {
            \App\Services\InboxService::portalSeen($c);
        }

        return response()->json(['messages' => $new->map(fn ($m) => ['id' => $m->id, 'mine' => $m->direction === 'in', 'body' => $m->body,
            'at' => $m->created_at->format('H:i'), 'who' => $m->direction === 'in' ? 'You' : ($m->auto ? hotel()->name : ($m->user ? \Illuminate\Support\Str::before($m->user->name, ' ').' · '.hotel()->name : hotel()->name))])->values(),
            'rate' => (bool) ($c?->status === 'closed' && $c->csat_asked && $c->csat === null)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function chatSend(Request $request, string $token)
    {
        $stay = $this->stay($token);
        $request->validate(['body' => 'required|string|max:1000'], ['body.required' => 'Write a message first.']);
        try {
            $m = \App\Services\InboxService::portalSend($stay, (string) $request->input('body'));
        } catch (RuntimeException $e) {
            return $request->expectsJson() ? response()->json(['error' => $e->getMessage()], 422) : $this->fail($e);
        }

        return $request->expectsJson() ? response()->json(['ok' => true, 'id' => $m->id]) : redirect()->route('portal.chat', $token);
    }

    public function chatRate(Request $request, string $token)
    {
        $stay = $this->stay($token);
        $c = \App\Services\InboxService::portalThread($stay);
        abort_unless($c, 404);
        try {
            \App\Services\InboxService::rate($c, (int) $request->input('stars'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('portal.chat', $token)->with('success', 'Thank you for your rating!');
    }

    // ───────── v4.2 visitors: pre-register, share a pass, privacy preference ─────────

    public function visitors(string $token)
    {
        $stay = $this->stay($token);
        $list = \App\Models\Visitor::where('reservation_room_id', $stay->id)->whereIn('status', ['expected', 'awaiting', 'in', 'out'])
            ->where('created_at', '>=', now()->subDays(14))->orderByRaw("FIELD(status, 'in', 'awaiting', 'expected', 'out')")->orderBy('expected_at')->limit(20)->get();

        return $this->page($stay, 'portal.visitors', 'visitors', ['list' => $list, 'cfg' => \App\Services\SecurityService::cfg(),
            'policies' => \App\Services\SecurityService::POLICIES], ['inhouse', 'upcoming']);
    }

    // ───────── v5.9 explore: activities & excursions + local guide ─────────

    public function explore(string $token)
    {
        $stay = $this->stay($token);
        $CS = \App\Services\ConciergeService::class;
        $bookable = $CS::portalOn() && in_array($stay->status, ['reserved', 'checked_in'], true);
        $mine = \App\Models\ActivityBooking::with('activity.vendor')->where('reservation_room_id', $stay->id)->where(fn ($q) => $q->whereIn('status', \App\Models\ActivityBooking::OPEN)
            ->orWhere('date', '>=', now()->subDays(1)->toDateString()))->orderBy('date')->orderBy('time')->limit(12)->get();
        $places = \App\Models\ConciergePlace::where('portal_visible', true)->orderBy('sort_order')->orderBy('name')->get()->groupBy('category');

        return $this->page($stay, 'portal.explore', 'explore', ['activities' => $bookable ? $CS::portalActivities() : collect(), 'bookable' => $bookable, 'mine' => $mine, 'places' => $places,
            'cfg' => $CS::cfg(), 'fees' => $mine->mapWithKeys(fn ($b) => [$b->id => $CS::cancelFee($b)])], ['inhouse', 'upcoming']);
    }

    public function bookActivity(Request $request, string $token)
    {
        $stay = $this->stay($token);
        abort_unless(in_array($stay->portalState(), ['inhouse', 'upcoming'], true) && \App\Services\ConciergeService::portalOn(), 403);
        $d = $request->validate(['activity_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d', 'time' => 'nullable|date_format:H:i', 'adults' => 'required|integer|min:1|max:12',
            'children' => 'nullable|integer|min:0|max:12', 'phone' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:300', 'waitlist' => 'nullable|boolean', 'website' => 'nullable|size:0']);
        try {
            $b = \App\Services\ConciergeService::portalBook($stay, $d);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('portal.explore', $token)->with('success', match ($b->status) {
            'confirmed' => $b->activity->name.' on '.$b->date->format('D d M').' at '.$b->time.' is confirmed (ref '.$b->number.').',
            'waitlisted' => 'That departure is full – you are on the waitlist (ref '.$b->number.'). We\'ll let you know if a seat comes free.',
            default => 'Thank you! We have your request (ref '.$b->number.') and will confirm with the operator shortly.',
        });
    }

    public function cancelActivity(string $token, int $bid)
    {
        $stay = $this->stay($token);
        $b = \App\Models\ActivityBooking::where('reservation_room_id', $stay->id)->findOrFail($bid);
        try {
            \App\Services\ConciergeService::portalCancel($stay, $b);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Booking '.$b->number.' cancelled.');
    }

    public function registerVisitor(Request $request, string $token)
    {
        $stay = $this->stay($token);
        abort_unless(in_array($stay->portalState(), ['inhouse', 'upcoming'], true), 403);
        $d = $request->validate(['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i', 'persons' => 'required|integer|min:1|max:10', 'notes' => 'nullable|string|max:300', 'website' => 'nullable|size:0']);
        try {
            $v = \App\Services\SecurityService::preRegister($stay, $d);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('portal.visitors', $token)->with('success', "{$v->name} is on our visitor list for ".$v->expected_at->format('D d M, H:i').'. Share their visitor pass so they can skip the queue at the desk.')
            ->with('new_pass', $v->id);
    }

    public function cancelVisitor(string $token, int $vid)
    {
        $stay = $this->stay($token);
        $v = \App\Models\Visitor::where('reservation_room_id', $stay->id)->findOrFail($vid);
        try {
            \App\Services\SecurityService::cancel($v, 'Cancelled by the guest via the portal');
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "Visitor {$v->name} removed from the list.");
    }

    public function visitorPolicy(Request $request, string $token)
    {
        $stay = $this->stay($token);
        abort_unless(in_array($stay->portalState(), ['inhouse', 'upcoming'], true), 403);
        $d = $request->validate(['policy' => 'required|in:'.implode(',', array_keys(\App\Services\SecurityService::POLICIES))]);
        \App\Services\SecurityService::setPolicy($stay, $d['policy'], 'guest');

        return back()->with('success', 'Thank you – the front desk will follow your preference: '.\App\Services\SecurityService::POLICIES[$d['policy']].'.');
    }

    /** v7.0 the guest switches the green-stay choice on or off from the portal */
    public function green(Request $request, string $token)
    {
        $stay = $this->stay($token);
        abort_unless($stay->portalState() === 'inhouse', 404);
        try {
            if ($request->input('choice') === 'off') {
                $g = \App\Services\EsgService::activeGreen($stay);
                if ($g) {
                    \App\Services\EsgService::optOut($g, 'portal');
                }
                $msg = 'Daily housekeeping is back on – thank you for trying the green stay.';
            } else {
                \App\Services\EsgService::optIn($stay, 'portal');
                $msg = 'Thank you for choosing the green stay! We will skip the daily clean from tomorrow (with a full refresh every few nights).';
            }
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('portal.home', $token)->with('success', $msg);
    }

    protected static function forexOn(ReservationRoom $stay): bool
    {
        return \App\Services\ForexService::enabled() && \App\Services\FormCService::isForeign($stay->guest ?? $stay->reservation?->guest);
    }

    public function requestTransport(Request $request, string $token)
    {
        $stay = $this->stay($token);
        abort_unless(in_array($stay->portalState(), ['inhouse', 'upcoming'], true), 403);
        $data = $request->validate(['type' => 'required|in:pickup,drop,transfer', 'date' => 'required|date_format:Y-m-d', 'time' => 'required|date_format:H:i',
            'pax' => 'required|integer|min:1|max:20', 'bags' => 'nullable|integer|min:0|max:30', 'flight_no' => 'nullable|string|max:20', 'place' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:300', 'website' => 'nullable|size:0']);
        try {
            $t = \App\Services\TransportService::portalRequest($stay, $data);
            \App\Services\TransportService::message($t, 'received');
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('portal.transport', $token)->with('success', "Thank you – we've received your request ({$t->trip_no}). The front desk will confirm it and send your driver's details.");
    }

    public function cancelTransport(string $token, int $tid)
    {
        $stay = $this->stay($token);
        $t = \App\Models\TransportTrip::where('reservation_room_id', $stay->id)->findOrFail($tid);
        if (! $t->isPlanned() || $t->busy_from->lt(now()->addHours(2))) {
            return back()->withErrors(['portal' => 'This transfer can no longer be cancelled online – please call the front desk.']);
        }
        \App\Services\TransportService::cancel($t, 'Cancelled by the guest via the portal', false);

        return back()->with('success', 'Transfer cancelled.');
    }

    // ───────── bill, express check-out, feedback ─────────

    public function bill(string $token)
    {
        $stay = $this->stay($token);
        $folios = Folio::with(['transactions' => fn ($q) => $q->where('is_void', false)->orderBy('business_date')->orderBy('id')])
            ->where('reservation_room_id', $stay->id)->orderBy('id')->get();
        $totals = $folios->mapWithKeys(fn ($f) => [$f->id => $f->totals()]);
        $checkoutReq = GuestRequest::where('reservation_room_id', $stay->id)->where('category', 'checkout')->latest('id')->first();
        $feedback = GuestFeedback::where('reservation_room_id', $stay->id)->first();

        $payOnline = $stay->portalState() === 'inhouse' && setting('pay_portal_enabled', '1') === '1' && \App\Services\OnlinePaymentService::enabled();

        return $this->page($stay, 'portal.bill', 'bill', compact('folios', 'totals', 'checkoutReq', 'feedback', 'payOnline'));
    }

    /** v2.1: pay the open folio balance online (reuses a still-valid link for the same amount). */
    public function payOnline(string $token)
    {
        $stay = $this->stay($token);
        abort_unless($stay->portalState() === 'inhouse' && setting('pay_portal_enabled', '1') === '1' && \App\Services\OnlinePaymentService::enabled(), 404);
        $folio = Folio::where('reservation_room_id', $stay->id)->where('status', 'open')->orderBy('id')->first();
        abort_unless($folio, 404);
        $bal = round((float) $folio->totals()['balance'], 2);
        if ($bal <= 0) {
            return back()->with('success', 'Nothing to pay – your bill is fully settled.');
        }
        $p = \App\Models\OnlinePayment::where('folio_id', $folio->id)->where('status', 'pending')->where('amount', $bal)->where('expires_at', '>', now()->addMinutes(10))->latest('id')->first();
        try {
            $p ??= \App\Services\OnlinePaymentService::create(['amount' => $bal, 'purpose' => 'balance', 'hours' => 24, 'description' => 'Room '.($stay->room?->room_no ?? '').' – bill balance'], null, $folio);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('pay.show', $p->token);
    }

    public function expressCheckout(Request $request, string $token)
    {
        $stay = $this->stay($token);
        $data = $request->validate(['notes' => 'nullable|string|max:500', 'email_invoice' => 'nullable|boolean']);
        try {
            GuestPortalService::raise($stay, ['category' => 'checkout', 'notes' => trim(($data['notes'] ?? '').($request->boolean('email_invoice') ? ' · Please e-mail my invoice.' : ''), ' ·')]);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Express check-out requested. Leave your key card in the room or at the desk – we will settle your bill and send the invoice.');
    }

    public function feedback(Request $request, string $token)
    {
        $stay = $this->stay($token);
        abort_unless(in_array($stay->portalState(), ['inhouse', 'departed']), 404);
        $data = $request->validate([
            'rating_overall' => 'required|integer|min:1|max:5', 'rating_room' => 'nullable|integer|min:1|max:5', 'rating_service' => 'nullable|integer|min:1|max:5',
            'rating_food' => 'nullable|integer|min:1|max:5', 'rating_cleanliness' => 'nullable|integer|min:1|max:5', 'comments' => 'nullable|string|max:2000',
        ]);
        $guest = $stay->guest ?? $stay->reservation?->guest;
        $fb = GuestFeedback::updateOrCreate(['reservation_room_id' => $stay->id], $data + [
            'reservation_id' => $stay->reservation_id, 'guest_id' => $guest?->id, 'guest_name' => $guest?->full_name ?? 'Guest', 'contact' => $guest?->email ?: $guest?->phone, 'source' => 'portal',
            'in_house' => $stay->status === 'checked_in',
        ]);
        \App\Services\FeedbackService::analyse($fb); // v3.1: score, sentiment, departments – and a service-recovery case while the guest can still be helped

        return back()->with('success', 'Thank you for your feedback!');
    }
}
