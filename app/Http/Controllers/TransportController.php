<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Driver;
use App\Models\GuestRequest;
use App\Models\PaymentMode;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\TransportTariff;
use App\Models\TransportTrip;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Services\ActivityLogger;
use App\Services\TransportClash;
use App\Services\TransportService as TS;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v3.3 guest transport & fleet desk (staff side + the driver's phone screen). */
class TransportController extends Controller
{
    public const TABS = ['open' => 'Open', 'today' => 'Today', 'upcoming' => 'Upcoming', 'requested' => 'To confirm', 'completed' => 'Completed', 'lost' => 'Cancelled / no-show', 'all' => 'All'];

    protected function fail(RuntimeException $e, ?TransportTrip $t = null, array $extra = [])
    {
        if ($e instanceof TransportClash) {
            return back()->withInput()->with('clash', ['trip' => $t?->id, 'message' => $e->getMessage()] + $extra);
        }

        return back()->withInput()->with('error', $e->getMessage());
    }

    // ───────────────────────── dispatch board ─────────────────────────

    public function index(Request $request)
    {
        $date = Carbon::parse($request->input('date', now()->toDateString()))->startOfDay();
        $end = $date->copy()->endOfDay();
        $trips = TransportTrip::with('vehicle', 'driver', 'stay.room')->where('busy_from', '<=', $end)->where('busy_to', '>=', $date)
            ->whereNotIn('status', ['cancelled'])->orderBy('busy_from')->get();
        $vehicles = Vehicle::where('is_active', true)->orderBy('code')->get();
        // timeline window: 05:00–23:00 at least, stretched to cover every trip of the day
        $from = 5;
        $to = 23;
        foreach ($trips as $t) {
            $from = min($from, $t->busy_from->lt($date) ? 0 : (int) $t->busy_from->format('H'));
            $to = max($to, $t->busy_to->gt($end) ? 24 : (int) $t->busy_to->format('H') + 1);
        }
        $requests = GuestRequest::with('room', 'stay.reservation.guest')->where('category', 'transport')->whereIn('status', GuestRequest::OPEN)->whereNull('linked_type')->orderBy('preferred_at')->get();

        return view('transport.index', [
            'date' => $date, 'trips' => $trips, 'vehicles' => $vehicles, 'from' => $from, 'to' => min(24, max($to, $from + 6)),
            'unassigned' => $trips->whereIn('status', ['requested', 'confirmed'])->where('scheduled_at', '>=', $date)->values(),
            'stats' => TS::dayStats($date), 'alerts' => TS::alerts(), 'requests' => $requests, 'drivers' => Driver::where('is_active', true)->orderBy('name')->get(),
            'cfg' => TS::cfg(),
        ]);
    }

    /** JSON for the assign dialog: every vehicle & driver with fit / clash notes and the suggestion. */
    public function options(TransportTrip $trip)
    {
        $s = TS::suggest($trip);
        $vehicles = Vehicle::where('is_active', true)->orderBy('code')->get()->map(function ($v) use ($trip) {
            $blk = TS::blockers($trip, $v, null);
            $cl = TS::clashes($v->id, null, $trip->busy_from, $trip->busy_to, $trip->id)['vehicle'];

            return ['id' => $v->id, 'label' => "{$v->code} · {$v->name} · {$v->seats} seats", 'blocked' => implode(' ', $blk), 'busy' => implode('; ', $cl)];
        });
        $drivers = Driver::where('is_active', true)->orderBy('name')->get()->map(function ($d) use ($trip) {
            $blk = TS::blockers($trip, null, $d);
            $cl = TS::clashes(null, $d->id, $trip->busy_from, $trip->busy_to, $trip->id)['driver'];

            return ['id' => $d->id, 'label' => $d->name, 'blocked' => implode(' ', $blk), 'busy' => implode('; ', $cl)];
        });

        return response()->json([
            'trip' => ['id' => $trip->id, 'no' => $trip->trip_no, 'title' => $trip->typeLabel().' · '.$trip->scheduled_at->format('D d M H:i').' · '.$trip->passenger_name." ({$trip->pax})",
                'window' => $trip->busy_from->format('H:i').'–'.$trip->busy_to->format('H:i'), 'vehicle_id' => $trip->vehicle_id, 'driver_id' => $trip->driver_id],
            'suggest' => ['vehicle_id' => $s['vehicle']?->id, 'driver_id' => $s['driver']?->id],
            'vehicles' => $vehicles, 'drivers' => $drivers, 'action' => route('tr.assign', $trip),
        ]);
    }

    // ───────────────────────── trips ─────────────────────────

    public function trips(Request $request)
    {
        $tab = array_key_exists($request->tab, self::TABS) ? $request->tab : 'open';
        $q = TransportTrip::with('vehicle', 'driver', 'stay.room')
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('trip_no', 'like', "%$t%")->orWhere('passenger_name', 'like', "%$t%")->orWhere('phone', 'like', "%$t%")->orWhere('flight_no', 'like', "%$t%")))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->vehicle, fn ($q, $v) => $q->where('vehicle_id', $v))
            ->when($request->driver, fn ($q, $v) => $q->where('driver_id', $v));
        match ($tab) {
            'open' => $q->whereIn('status', TransportTrip::OPEN)->orderBy('scheduled_at'),
            'today' => $q->whereDate('scheduled_at', now()->toDateString())->orderBy('scheduled_at'),
            'upcoming' => $q->whereIn('status', TransportTrip::OPEN)->where('scheduled_at', '>', now()->endOfDay())->orderBy('scheduled_at'),
            'requested' => $q->where('status', 'requested')->orderBy('scheduled_at'),
            'completed' => $q->where('status', 'completed')->orderByDesc('scheduled_at'),
            'lost' => $q->whereIn('status', ['cancelled', 'no_show'])->orderByDesc('scheduled_at'),
            default => $q->orderByDesc('scheduled_at'),
        };

        return view('transport.trips', ['trips' => $q->paginate(40)->withQueryString(), 'tab' => $tab,
            'vehicles' => Vehicle::orderBy('code')->pluck('code', 'id'), 'drivers' => Driver::orderBy('name')->pluck('name', 'id')]);
    }

    protected function formData(): array
    {
        $stays = ReservationRoom::with('room', 'reservation.guest', 'guest')->where(fn ($q) => $q->where('status', 'checked_in')
            ->orWhere(fn ($w) => $w->where('status', 'reserved')->whereBetween('arrival_date', [bdate()->toDateString(), bdate()->copy()->addDays(30)->toDateString()])))
            ->orderByRaw("CASE status WHEN 'checked_in' THEN 0 ELSE 1 END")->orderBy('arrival_date')->limit(300)->get();

        return [
            'stays' => $stays, 'tariffs' => TransportTariff::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'vehicles' => Vehicle::where('is_active', true)->orderBy('code')->get(), 'drivers' => Driver::where('is_active', true)->orderBy('name')->get(),
            'cfg' => TS::cfg(),
        ];
    }

    public function create(Request $request)
    {
        $type = in_array($request->type, ['pickup', 'drop', 'transfer'], true) ? $request->type : 'pickup';
        $req = $request->filled('request') ? GuestRequest::where('category', 'transport')->find($request->integer('request')) : null;
        $stay = $request->filled('stay') ? ReservationRoom::find($request->integer('stay')) : ($req?->stay);
        $res = $request->filled('reservation') ? Reservation::find($request->integer('reservation')) : null;
        if ($req) {
            $type = str_contains(strtolower($req->item), 'airport') ? 'drop' : 'transfer';
        }
        $p = ($stay || $res) ? TS::prefill($res, $type, $stay) : TS::prefill(null, $type);
        if ($req) {
            $p['guest_request_id'] = $req->id;
            $p['notes'] = trim($req->item.($req->notes ? ' – '.$req->notes : ''));
            if ($req->preferred_at) {
                $p['scheduled_at'] = $req->preferred_at->format('Y-m-d\TH:i');
            }
        }

        return view('transport.create', $this->formData() + ['p' => $p, 'req' => $req]);
    }

    protected function rules(): array
    {
        return [
            'type' => 'required|in:pickup,drop,transfer', 'scheduled_at' => 'required|date', 'passenger_name' => 'required|string|max:150', 'phone' => 'nullable|string|max:30',
            'pax' => 'required|integer|min:1|max:60', 'bags' => 'nullable|integer|min:0|max:99', 'flight_no' => 'nullable|string|max:20',
            'pickup_point' => 'nullable|string|max:190', 'dropoff_point' => 'nullable|string|max:190', 'transport_tariff_id' => 'nullable|integer|owned:transport_tariffs',
            'vehicle_type' => ['nullable', Rule::in(array_keys(Vehicle::TYPES))], 'amount' => 'nullable|numeric|min:0|max:9999999', 'billing' => 'required|in:room,direct,complimentary',
            'reservation_room_id' => 'nullable|integer|owned:reservation_rooms', 'reservation_id' => 'nullable|integer|owned:reservations', 'guest_request_id' => 'nullable|integer|owned:guest_requests',
            'duration_min' => 'nullable|integer|min:15|max:1440', 'notes' => 'nullable|string|max:500',
            'vehicle_id' => 'nullable|integer|owned:vehicles', 'driver_id' => 'nullable|integer|owned:drivers', 'force' => 'nullable|boolean', 'notify' => 'nullable|boolean',
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->rules());
        if (($d['vehicle_id'] ?? null) xor ($d['driver_id'] ?? null)) {
            return back()->withInput()->with('error', 'Choose both a vehicle and a driver – or leave both empty and assign later.');
        }
        $d['notify'] = $request->boolean('notify', true);
        try {
            $t = TS::save($d);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route($request->input('back') === 'board' ? 'tr.index' : 'tr.show', $request->input('back') === 'board' ? ['date' => $t->scheduled_at->toDateString()] : $t)
            ->with('success', "Trip {$t->trip_no} booked for ".$t->scheduled_at->format('D d M H:i').($t->vehicle ? " – {$t->vehicle->code} with {$t->driver->name}" : '.'));
    }

    public function show(TransportTrip $trip)
    {
        $trip->load('vehicle', 'driver', 'stay.room', 'reservation', 'guest', 'tariff', 'folio', 'folioTransaction', 'paymentMode', 'creator', 'guestRequest');
        $log = ActivityLog::with('user')->where('subject_type', 'TransportTrip')->where('subject_id', $trip->id)->orderBy('id')->get();
        $msgs = \App\Models\MessageLog::where('related_type', 'TransportTrip')->where('related_id', $trip->id)->orderBy('id')->get();

        return view('transport.show', $this->formData() + ['t' => $trip, 'log' => $log, 'msgs' => $msgs,
            'suggest' => $trip->isPlanned() ? TS::suggest($trip) : null,
            'paymentModes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->pluck('name', 'id')]);
    }

    /** Printable trip sheet + name board for the driver. */
    public function sheet(TransportTrip $trip)
    {
        return view('transport.sheet', ['t' => $trip->load('vehicle', 'driver', 'stay.room')]);
    }

    public function update(Request $request, TransportTrip $trip)
    {
        $d = $request->validate($this->rules());
        $d['notify'] = $request->boolean('notify');
        try {
            TS::save($d, $trip);
        } catch (RuntimeException $e) {
            return $this->fail($e, $trip);
        }

        return back()->with('success', "Trip {$trip->trip_no} updated.");
    }

    public function confirm(TransportTrip $trip)
    {
        try {
            TS::confirm($trip);
        } catch (RuntimeException $e) {
            return $this->fail($e, $trip);
        }

        return back()->with('success', "Trip {$trip->trip_no} confirmed – the guest has been told.");
    }

    public function assign(Request $request, TransportTrip $trip)
    {
        $d = $request->validate(['vehicle_id' => 'required|integer|owned:vehicles', 'driver_id' => 'required|integer|owned:drivers', 'force' => 'nullable|boolean', 'notify' => 'nullable|boolean']);
        try {
            $t = TS::assign($trip, $d['vehicle_id'], $d['driver_id'], $request->boolean('force'), $request->boolean('notify', true));
        } catch (RuntimeException $e) {
            return $this->fail($e, $trip, ['vehicle_id' => $d['vehicle_id'], 'driver_id' => $d['driver_id']]);
        }

        return back()->with('success', "{$t->trip_no}: {$t->vehicle->code} with {$t->driver->name}".($request->boolean('notify', true) ? ' – driver & guest notified.' : '.'));
    }

    public function unassign(TransportTrip $trip)
    {
        try {
            TS::unassign($trip);
        } catch (RuntimeException $e) {
            return $this->fail($e, $trip);
        }

        return back()->with('success', "Trip {$trip->trip_no} is back in the unassigned queue.");
    }

    public function start(Request $request, TransportTrip $trip)
    {
        $d = $request->validate(['odo_start' => 'nullable|integer|min:0|max:9999999']);
        try {
            TS::start($trip, isset($d['odo_start']) ? (int) $d['odo_start'] : null);
        } catch (RuntimeException $e) {
            return $this->fail($e, $trip);
        }

        return back()->with('success', "{$trip->trip_no} is on its way.");
    }

    public function onboard(TransportTrip $trip)
    {
        try {
            TS::onboard($trip);
        } catch (RuntimeException $e) {
            return $this->fail($e, $trip);
        }

        return back()->with('success', "{$trip->passenger_name} is on board.");
    }

    public function complete(Request $request, TransportTrip $trip)
    {
        $d = $request->validate(['odo_start' => 'nullable|integer|min:0|max:9999999', 'odo_end' => 'nullable|integer|min:0|max:9999999', 'extra_amount' => 'nullable|numeric|min:0|max:999999',
            'extra_note' => 'nullable|string|max:120', 'payment_mode_id' => 'nullable|integer|owned:payment_modes', 'reference' => 'nullable|string|max:60', 'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100']);
        try {
            $t = TS::complete($trip, $d);
        } catch (RuntimeException $e) {
            return $this->fail($e, $trip);
        }

        return back()->with('success', "{$t->trip_no} completed".($t->billing === 'room' && (float) $t->total > 0 ? ' – '.money($t->total).' posted to '.($t->folio?->folio_no ?? 'the room bill') : ($t->billing === 'direct' && (float) $t->total > 0 ? ' – paid '.money($t->total) : '')).'.');
    }

    public function noShow(Request $request, TransportTrip $trip)
    {
        $d = $request->validate(['reason' => 'required|string|max:190']);
        try {
            TS::noShow($trip, $d['reason'], $request->boolean('charge'));
        } catch (RuntimeException $e) {
            return $this->fail($e, $trip);
        }

        return back()->with('success', "{$trip->trip_no} marked as no-show.");
    }

    public function cancel(Request $request, TransportTrip $trip)
    {
        $d = $request->validate(['reason' => 'required|string|max:190']);
        try {
            TS::cancel($trip, $d['reason'], $request->boolean('notify'));
        } catch (RuntimeException $e) {
            return $this->fail($e, $trip);
        }

        return back()->with('success', "Trip {$trip->trip_no} cancelled.");
    }

    public function resend(Request $request, TransportTrip $trip)
    {
        $to = $request->input('to') === 'driver' ? 'driver' : 'guest';
        if (! $trip->isOpen()) {
            return back()->with('error', "{$trip->trip_no} is {$trip->statusLabel()}.");
        }
        $kind = $trip->driver_id ? 'assigned' : ($trip->status === 'requested' ? 'received' : 'confirmed');
        if ($to === 'driver' && ! $trip->driver_id) {
            return back()->with('error', 'No driver assigned yet.');
        }
        $ok = TS::message($trip, $kind, $to);
        ActivityLogger::log('transport', 'resend', "Trip {$trip->trip_no}: details re-sent to the {$to}", $trip);

        return back()->with($ok ? 'success' : 'error', $ok ? 'Sent to the '.$to.'.' : 'Could not send – check the phone number and Messaging settings.');
    }

    public function charge(TransportTrip $trip)
    {
        try {
            $tx = TS::postCharge($trip);
        } catch (RuntimeException $e) {
            return $this->fail($e, $trip);
        }

        return back()->with($tx ? 'success' : 'error', $tx ? money($trip->total)." posted to {$trip->fresh()->folio?->folio_no}." : 'Nothing to post.');
    }

    /** Check-out screen: put every pending room-billed trip on this bill. */
    public function chargeStay(ReservationRoom $stay)
    {
        try {
            $n = TS::postPendingForStay($stay);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $n ? "{$n} transport charge(s) added to the bill." : 'No transport charges pending.');
    }

    // ───────────────────────── driver phone screen ─────────────────────────

    protected function myDriver(Request $request): ?Driver
    {
        if (can_do('transport.book') && $request->filled('as')) {
            return Driver::find($request->integer('as'));
        }

        return TS::driverFor(auth()->id());
    }

    protected function authorizeDriver(TransportTrip $trip): void
    {
        if (can_do('transport.book')) {
            return;
        }
        $me = TS::driverFor(auth()->id());
        abort_unless($me && $trip->driver_id === $me->id, 403, 'This trip is assigned to another driver.');
    }

    public function my(Request $request)
    {
        $driver = $this->myDriver($request);
        $trips = $driver ? TransportTrip::with('vehicle', 'stay.room')->where('driver_id', $driver->id)
            ->where(fn ($q) => $q->whereIn('status', TransportTrip::OPEN)->where('busy_from', '<=', now()->addDays(1)->endOfDay())
                ->orWhere(fn ($w) => $w->whereIn('status', ['completed', 'no_show'])->whereDate('scheduled_at', now()->toDateString())))
            ->orderByRaw("CASE WHEN status IN ('en_route','onboard') THEN 0 WHEN status = 'assigned' THEN 1 ELSE 2 END")->orderBy('busy_from')->get() : collect();

        return view('transport.my', ['driver' => $driver, 'trips' => $trips, 'drivers' => can_do('transport.book') ? Driver::where('is_active', true)->orderBy('name')->pluck('name', 'id') : collect(),
            'vehicles' => Vehicle::where('is_active', true)->orderBy('code')->get()]);
    }

    public function myAction(Request $request, TransportTrip $trip, string $action)
    {
        $this->authorizeDriver($trip);
        try {
            match ($action) {
                'start' => TS::start($trip, $request->filled('odo_start') ? (int) $request->validate(['odo_start' => 'integer|min:0|max:9999999'])['odo_start'] : null),
                'onboard' => TS::onboard($trip),
                'complete' => TS::complete($trip, $request->validate(['odo_end' => 'nullable|integer|min:0|max:9999999', 'extra_amount' => 'nullable|numeric|min:0|max:999999', 'extra_note' => 'nullable|string|max:120', 'reference' => 'nullable|string|max:60', 'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100'])
                    + ($trip->billing === 'direct' ? $request->validate(['payment_mode_id' => 'required|integer|owned:payment_modes']) : [])),
                'noshow' => TS::noShow($trip, 'Guest not at the pick-up point (driver)'),
            };
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', match ($action) {
            'start' => "{$trip->trip_no}: drive safe!", 'onboard' => "{$trip->passenger_name} on board.", 'complete' => "{$trip->trip_no} completed – thank you.", default => "{$trip->trip_no} marked no-show.",
        });
    }

    public function myFuel(Request $request)
    {
        $d = $request->validate(['vehicle_id' => 'required|integer|owned:vehicles', 'kind' => 'required|in:fuel,toll,parking', 'litres' => 'nullable|numeric|min:0|max:2000',
            'amount' => 'required|numeric|min:1|max:999999', 'odometer' => 'nullable|integer|min:0|max:9999999', 'vendor' => 'nullable|string|max:80']);
        $driver = $this->myDriver($request);
        $e = VehicleExpense::create($d + ['date' => now()->toDateString(), 'driver_id' => $driver?->id, 'created_by' => auth()->id()]);
        $this->bumpOdometer($e);
        ActivityLogger::log('transport', 'expense', VehicleExpense::KINDS[$e->kind].' '.money($e->amount)." for {$e->vehicle->code}", $e);

        return back()->with('success', VehicleExpense::KINDS[$e->kind].' of '.money($e->amount).' logged.');
    }

    protected function bumpOdometer(VehicleExpense $e): void
    {
        if ($e->odometer && $e->vehicle && $e->odometer > $e->vehicle->odometer) {
            $e->vehicle->forceFill(['odometer' => $e->odometer])->save();
        }
    }

    // ───────────────────────── fleet, expenses, settings ─────────────────────────

    public function fleet()
    {
        $c = TS::cfg();
        $month = now()->startOfMonth();
        $vehicles = Vehicle::with('asset')->orderByDesc('is_active')->orderBy('code')->get();
        $tripStats = TransportTrip::where('status', 'completed')->where('scheduled_at', '>=', $month)->whereNotNull('vehicle_id')
            ->selectRaw('vehicle_id, COUNT(*) n, SUM(CASE WHEN COALESCE(odo_end,0) > COALESCE(odo_start,0) THEN COALESCE(odo_end,0) - COALESCE(odo_start,0) ELSE 0 END) km, SUM(CASE WHEN billing <> \'complimentary\' THEN total ELSE 0 END) rev')->groupBy('vehicle_id')->get()->keyBy('vehicle_id');
        $cost = VehicleExpense::where('date', '>=', $month->toDateString())->selectRaw('vehicle_id, SUM(amount) amt, SUM(COALESCE(litres,0)) l')->groupBy('vehicle_id')->get()->keyBy('vehicle_id');
        $drivers = Driver::with('user', 'employee')->orderByDesc('is_active')->orderBy('name')->get();
        $today = TransportTrip::whereDate('scheduled_at', now()->toDateString())->whereNotIn('status', ['cancelled'])->whereNotNull('driver_id')->selectRaw('driver_id, COUNT(*) n')->groupBy('driver_id')->pluck('n', 'driver_id');
        $next = TransportTrip::whereIn('status', ['assigned', 'en_route', 'onboard'])->where('busy_to', '>=', now())->orderBy('busy_from')->get()->groupBy('driver_id')->map->first();
        $nextV = TransportTrip::whereIn('status', ['assigned', 'en_route', 'onboard'])->where('busy_to', '>=', now())->orderBy('busy_from')->get()->groupBy('vehicle_id')->map->first();

        return view('transport.fleet', compact('vehicles', 'tripStats', 'cost', 'drivers', 'today', 'next', 'nextV', 'c', 'month'));
    }

    public function expenses(Request $request)
    {
        $month = Carbon::parse(($request->input('month') ?: now()->format('Y-m')).'-01')->startOfMonth();
        $q = VehicleExpense::with('vehicle', 'driver', 'creator')->whereBetween('date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->when($request->vehicle, fn ($q, $v) => $q->where('vehicle_id', $v))->when($request->kind, fn ($q, $k) => $q->where('kind', $k));
        $rows = (clone $q)->orderByDesc('date')->orderByDesc('id')->get();

        return view('transport.expenses', ['rows' => $rows, 'month' => $month, 'byKind' => $rows->groupBy('kind')->map(fn ($g) => (float) $g->sum('amount')),
            'vehicles' => Vehicle::orderBy('code')->get(), 'drivers' => Driver::where('is_active', true)->orderBy('name')->pluck('name', 'id')]);
    }

    public function storeExpense(Request $request)
    {
        $d = $request->validate(['vehicle_id' => 'required|integer|owned:vehicles', 'driver_id' => 'nullable|integer|owned:drivers', 'date' => 'required|date|before_or_equal:today',
            'kind' => ['required', Rule::in(array_keys(VehicleExpense::KINDS))], 'litres' => 'nullable|numeric|min:0|max:2000', 'amount' => 'required|numeric|min:0.01|max:9999999',
            'odometer' => 'nullable|integer|min:0|max:9999999', 'vendor' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:255']);
        if ($d['kind'] === 'fuel' && empty($d['litres'])) {
            return back()->withInput()->withErrors(['litres' => 'Enter the litres (or kWh) filled.']);
        }
        $e = VehicleExpense::create($d + ['created_by' => auth()->id()]);
        $this->bumpOdometer($e);
        ActivityLogger::log('transport', 'expense', VehicleExpense::KINDS[$e->kind].' '.money($e->amount)." for {$e->vehicle->code}", $e);

        return back()->with('success', 'Expense recorded.');
    }

    public function destroyExpense(VehicleExpense $vexp)
    {
        $vexp->delete();
        ActivityLogger::log('transport', 'expense_delete', 'Deleted '.VehicleExpense::KINDS[$vexp->kind].' '.money($vexp->amount).' of '.$vexp->date->format('d M'), null);

        return back()->with('success', 'Expense deleted.');
    }

    public function settings()
    {
        return view('transport.settings', ['c' => TS::cfg()]);
    }

    public function saveSettings(Request $request)
    {
        $d = $request->validate([
            'airport' => 'required|string|max:120', 'station' => 'required|string|max:120', 'pickup_lead' => 'required|integer|min:0|max:300',
            'buffer' => 'required|integer|min:0|max:120', 'driver_reminder' => 'required|integer|min:0|max:720', 'alert_hours' => 'required|integer|min:1|max:72',
            'paper_days' => 'required|integer|min:1|max:180', 'default_billing' => 'required|in:room,direct,complimentary', 'request_expiry_hours' => 'required|integer|min:0|max:168',
        ]);
        $d['notify_guest'] = $request->boolean('notify_guest');
        $d['notify_driver'] = $request->boolean('notify_driver');
        TS::saveSettings($d);

        return back()->with('success', 'Transport settings saved.');
    }
}
