<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Models\PosTable;
use App\Models\ReservationRoom;
use App\Models\TableReservation;
use App\Services\ActivityLogger;
use App\Services\TableBookingService as TB;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** v3.2 restaurant table reservations, floor plan & waitlist (staff side). */
class TableBookingController extends Controller
{
    public const TABS = ['all' => 'All', 'upcoming' => 'To come', 'seated' => 'Seated', 'done' => 'Completed', 'waitlist' => 'Waitlist', 'lost' => 'Cancelled / no-show'];

    /** The outlet being worked on: ?outlet=, else the first with reservations switched on. */
    protected function outlet(Request $request, bool $any = false): ?Outlet
    {
        $list = $any ? Outlet::where('is_active', true)->whereIn('type', ['restaurant', 'bar', 'cafe'])->orderBy('id')->get() : TB::enabledOutlets();

        return $list->firstWhere('id', $request->integer('outlet')) ?? $list->first();
    }

    protected function inHouse()
    {
        return ReservationRoom::with('room', 'reservation.guest')->where('status', 'checked_in')->get()->sortBy(fn ($s) => $s->room?->room_no)->values();
    }

    public function index(Request $request)
    {
        $outlet = $this->outlet($request);
        if (! $outlet) {
            return view('tables.index', ['outlet' => null, 'outlets' => collect()]);
        }
        $date = Carbon::parse($request->input('date', now()->toDateString()))->startOfDay();
        $tab = array_key_exists($request->tab, self::TABS) ? $request->tab : 'all';
        $rows = TableReservation::with('tables', 'guest', 'stay.room')->where('outlet_id', $outlet->id)->whereDate('date', $date->toDateString())
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('phone', 'like', "%$t%")->orWhere('booking_no', 'like', "%$t%")))
            ->orderBy('start_at')->get();
        $counts = [];
        foreach (array_keys(self::TABS) as $t) {
            $counts[$t] = $this->tab($rows, $t)->count();
        }
        $tables = PosTable::where('outlet_id', $outlet->id)->where('is_active', true)->orderBy('id')->get();
        // timeline window: service periods of the day (or 11:00-23:00), whole hours
        $slots = array_keys(TB::slots($outlet, $date));
        $first = $slots ? (int) substr($slots[0], 0, 2) : 11;
        $last = $slots ? (int) substr(end($slots), 0, 2) + 3 : 23;
        $booked = $rows->whereIn('status', ['booked', 'confirmed', 'seated', 'completed']);
        foreach ($booked as $r) {
            $first = min($first, (int) $r->start_at->format('H'));
            $last = max($last, min(24, (int) $r->end_at->format('H') + ($r->end_at->isSameDay($date) ? 1 : 24)));
        }
        $history = [];
        foreach ($rows->whereIn('status', TableReservation::UPCOMING) as $r) {
            $history[$r->id] = TB::history($r->phone, $r->guest_id, $r->id);
        }

        return view('tables.index', [
            'outlet' => $outlet, 'outlets' => TB::enabledOutlets(), 'date' => $date, 'tab' => $tab, 'counts' => $counts,
            'items' => $this->tab($rows, $tab), 'timeline' => $booked, 'tables' => $tables, 'from' => max(0, $first), 'to' => min(max($last, $first + 4), 26),
            'stats' => TB::dayStats($outlet, $date), 'periods' => TB::periodsOn($outlet, $date), 'inHouse' => $this->inHouse(), 'history' => $history,
            'flag' => (int) TB::cfg($outlet)['noshow_flag'],
        ]);
    }

    protected function tab($rows, string $tab)
    {
        return (match ($tab) {
            'upcoming' => $rows->whereIn('status', TableReservation::UPCOMING),
            'seated' => $rows->where('status', 'seated'),
            'done' => $rows->where('status', 'completed'),
            'waitlist' => $rows->where('type', 'waitlist'),
            'lost' => $rows->whereIn('status', ['cancelled', 'no_show', 'left']),
            default => $rows,
        })->values();
    }

    public function show(TableReservation $tres)
    {
        $tres->load('tables', 'guest', 'stay.room', 'order', 'outlet', 'creator');
        $log = \App\Models\ActivityLog::with('user')->where('subject_type', 'TableReservation')->where('subject_id', $tres->id)->orderBy('id')->get();

        return view('tables.show', ['r' => $tres, 'log' => $log, 'history' => TB::history($tres->phone, $tres->guest_id, $tres->id), 'flagged' => TB::flagged($tres),
            'tables' => PosTable::where('outlet_id', $tres->outlet_id)->where('is_active', true)->orderBy('id')->get(), 'inHouse' => $this->inHouse()]);
    }

    protected function rules(bool $create = true): array
    {
        return [
            'date' => 'required|date', 'time' => 'required|date_format:H:i', 'party_size' => 'required|integer|min:1|max:50',
            'name' => ($create ? 'required_without:reservation_room_id|' : 'required|').'nullable|string|max:120',
            'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:150',
            'reservation_room_id' => 'nullable|integer|owned:reservation_rooms', 'guest_id' => 'nullable|integer|owned:guests',
            'occasion' => ['nullable', Rule::in(array_keys(TableReservation::OCCASIONS))], 'tags' => 'nullable|array', 'tags.*' => Rule::in(array_keys(TableReservation::TAGS)),
            'section_pref' => 'nullable|string|max:40', 'notes' => 'nullable|string|max:500',
            'table_ids' => 'nullable|array|max:4', 'table_ids.*' => 'integer|owned:pos_tables', 'duration' => 'nullable|integer|min:30|max:360',
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules() + [
            'outlet_id' => 'required|integer|owned:outlets', 'source' => ['required', Rule::in(['phone', 'email', 'walkin', 'concierge', 'staff'])],
        ]);
        if (empty($data['phone']) && empty($data['email']) && empty($data['reservation_room_id']) && $data['source'] !== 'walkin') {
            return back()->withInput()->withErrors(['phone' => 'Enter a phone number or e-mail so we can reach the guest (or pick the in-house room).']);
        }
        $r = TB::book(Outlet::findOrFail($data['outlet_id']), $data, $data['source']);
        $msg = "Table booking {$r->booking_no} – {$r->name} ({$r->party_size}) on ".$r->start_at->format('d M H:i').' at table '.$r->tableLabel().'.';
        if (TB::flagged($r)) {
            return redirect()->route('tb.show', $r)->with('warning', $msg.' Note: this guest has '.TB::history($r->phone, $r->guest_id, $r->id)['no_shows'].' previous no-shows.');
        }

        return $request->input('back') === 'floor' ? redirect()->route('tb.floor', ['outlet' => $r->outlet_id])->with('success', $msg)
            : redirect()->route('tb.index', ['outlet' => $r->outlet_id, 'date' => $r->date->toDateString()])->with('success', $msg);
    }

    public function update(Request $request, TableReservation $tres)
    {
        $data = $request->validate($this->rules(false) + ['notify' => 'nullable|boolean']);
        $r = TB::update($tres, $data, $request->boolean('notify'));

        return redirect()->route('tb.show', $r)->with('success', "Booking {$r->booking_no} updated – ".$r->start_at->format('d M H:i').', table '.$r->tableLabel().'.');
    }

    public function confirm(TableReservation $tres)
    {
        TB::confirm($tres);

        return back()->with('success', "Booking {$tres->booking_no} confirmed – the guest has been told.");
    }

    public function seat(Request $request, TableReservation $tres)
    {
        $data = $request->validate(['table_ids' => 'nullable|array|max:4', 'table_ids.*' => 'integer|owned:pos_tables']);
        $order = TB::seat($tres, $data['table_ids'] ?? []);
        $msg = "{$tres->name} ({$tres->party_size}) seated at ".$tres->fresh('tables')->tableLabel()." – order {$order->order_no} is open on the POS.";

        return $request->input('go') === 'pos' && can_do('pos.order') ? redirect()->route('pos.terminal', $order)->with('success', $msg) : back()->with('success', $msg);
    }

    public function cancel(Request $request, TableReservation $tres)
    {
        $data = $request->validate(['reason' => 'required|string|max:200', 'notify' => 'nullable|boolean']);
        $wait = $tres->isWaiting();
        TB::cancel($tres, $data['reason'], false, $request->boolean('notify'));

        return back()->with('success', $wait ? "{$tres->name} removed from the waitlist." : "Booking {$tres->booking_no} cancelled.");
    }

    public function noShow(TableReservation $tres)
    {
        TB::noShow($tres);

        return back()->with('success', "Booking {$tres->booking_no} marked as no-show – the table is free again.");
    }

    public function reinstate(TableReservation $tres)
    {
        $r = TB::reinstate($tres);

        return back()->with('success', $r->type === 'waitlist' ? "{$r->name} is back on the waitlist." : "Booking {$r->booking_no} reinstated at table ".$r->tableLabel().'.');
    }

    public function resend(TableReservation $tres)
    {
        abort_unless($tres->isUpcoming(), 422, 'Only upcoming bookings.');
        $ok = TB::message($tres, $tres->status === 'confirmed' ? 'confirmation' : 'received');
        ActivityLogger::log('tables', 'resend', "Re-sent the confirmation for {$tres->booking_no}", $tres);

        return back()->with($ok ? 'success' : 'warning', $ok ? 'Confirmation sent again.' : 'Could not send – check the phone number / e-mail and the messaging settings.');
    }

    public function notify(TableReservation $tres)
    {
        $sent = TB::notifyReady($tres);

        return back()->with($sent || ! $tres->phone ? 'success' : 'warning', $sent ? "Text sent – {$tres->name}'s table is ready." : ($tres->phone ? 'Marked as called, but the SMS failed.' : "Marked as called – no phone number, call {$tres->name} by name."));
    }

    // ------------------------------------------------------------------ floor plan & waitlist

    public function floor(Request $request)
    {
        $outlet = $this->outlet($request);
        if (! $outlet) {
            return view('tables.floor', ['outlet' => null, 'outlets' => collect()]);
        }
        TB::autoLayout($outlet);
        $c = TB::cfg($outlet);
        $waitlist = TableReservation::where('outlet_id', $outlet->id)->whereIn('status', TableReservation::WAITING)->orderBy('created_at')->get();
        $upcoming = TableReservation::with('tables')->where('outlet_id', $outlet->id)->whereIn('status', TableReservation::UPCOMING)
            ->whereBetween('start_at', [now()->subMinutes((int) $c['hold_minutes'] + 30), now()->addHours(3)])->orderBy('start_at')->get();

        return view('tables.floor', [
            'outlet' => $outlet, 'outlets' => TB::enabledOutlets(), 'live' => TB::live($outlet), 'waitlist' => $waitlist, 'upcoming' => $upcoming,
            'stats' => TB::dayStats($outlet, now()->startOfDay()), 'inHouse' => $this->inHouse(), 'cfg' => $c,
            'sections' => PosTable::where('outlet_id', $outlet->id)->where('is_active', true)->whereNotNull('section')->distinct()->orderBy('section')->pluck('section'),
            'tables' => PosTable::where('outlet_id', $outlet->id)->where('is_active', true)->orderBy('id')->get(),
            'quote' => [2 => TB::estimateWait($outlet, 2), 4 => TB::estimateWait($outlet, 4), 6 => TB::estimateWait($outlet, 6)],
        ]);
    }

    public function walkIn(Request $request)
    {
        $data = $request->validate(['outlet_id' => 'required|integer|owned:outlets', 'party_size' => 'required|integer|min:1|max:50', 'name' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:30', 'table_ids' => 'required|array|min:1|max:4', 'table_ids.*' => 'integer|owned:pos_tables']);
        $r = TB::walkIn(Outlet::findOrFail($data['outlet_id']), (int) $data['party_size'], $data['table_ids'], $data['name'] ?? null, $data['phone'] ?? null);
        $msg = "Walk-in {$r->name} ({$r->party_size}) seated at ".$r->tableLabel()." – order {$r->order->order_no} is open.";

        return $request->input('go') === 'pos' && can_do('pos.order') ? redirect()->route('pos.terminal', $r->pos_order_id)->with('success', $msg)
            : redirect()->route('tb.floor', ['outlet' => $r->outlet_id])->with('success', $msg);
    }

    public function waitlistStore(Request $request)
    {
        $data = $request->validate(['outlet_id' => 'required|integer|owned:outlets', 'name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30',
            'party_size' => 'required|integer|min:1|max:50', 'quoted_minutes' => 'nullable|integer|min:0|max:240', 'section_pref' => 'nullable|string|max:40',
            'tags' => 'nullable|array', 'tags.*' => Rule::in(array_keys(TableReservation::TAGS)), 'notes' => 'nullable|string|max:300', 'send_sms' => 'nullable|boolean']);
        $r = TB::addToWaitlist(Outlet::findOrFail($data['outlet_id']), $data);

        return redirect()->route('tb.floor', ['outlet' => $r->outlet_id])->with('success', "{$r->name} ({$r->party_size}) added to the waitlist – quoted about {$r->quoted_minutes} min.");
    }

    public function clear(PosTable $ptable)
    {
        TB::clearTable($ptable);

        return back()->with('success', "Table {$ptable->table_no} cleared and ready.");
    }

    public function block(Request $request, PosTable $ptable)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:120']);
        if (($data['reason'] ?? null) && ($why = TB::occupiedNow($ptable))) {
            return back()->with('error', "Table {$ptable->table_no} is {$why} – it can be blocked once the party has left.");
        }
        TB::block($ptable, $data['reason'] ?? null);

        return back()->with('success', ($data['reason'] ?? null) ? "Table {$ptable->table_no} blocked." : "Table {$ptable->table_no} is back in service.");
    }

    public function layout(Request $request)
    {
        $data = $request->validate(['outlet_id' => 'required|integer|owned:outlets', 'tables' => 'required|array|max:200',
            'tables.*.id' => 'required|integer', 'tables.*.x' => 'required|integer|min:0|max:100', 'tables.*.y' => 'required|integer|min:0|max:100', 'tables.*.shape' => 'nullable|in:square,round,rect,booth']);
        $n = TB::saveLayout(Outlet::findOrFail($data['outlet_id']), $data['tables']);

        return response()->json(['ok' => true, 'saved' => $n]);
    }

    // ------------------------------------------------------------------ JSON helpers for the booking form

    public function availability(Request $request)
    {
        $data = $request->validate(['outlet_id' => 'required|integer|owned:outlets', 'date' => 'required|date', 'party_size' => 'required|integer|min:1|max:50', 'exclude' => 'nullable|integer']);
        $o = Outlet::findOrFail($data['outlet_id']);
        $slots = TB::availability($o, $data['date'], (int) $data['party_size'], $data['exclude'] ?? null);

        return response()->json(['duration' => TB::durationFor($o, (int) $data['party_size']), 'slots' => collect($slots)->map(fn ($s, $t) => ['time' => $t] + $s)->values()]);
    }

    public function lookup(Request $request)
    {
        $phone = trim((string) $request->input('phone'));
        if (mb_strlen($phone) < 6) {
            return response()->json(['found' => false]);
        }
        $g = TB::matchGuest($phone, null);
        $last = TableReservation::where('phone', \App\Services\MessageService::normalise($phone) ?? $phone)->latest('id')->first(['name', 'email']);
        $h = TB::history($phone, $g?->id);

        return response()->json(['found' => (bool) ($g || $last), 'name' => $g ? trim($g->first_name.' '.$g->last_name) : $last?->name, 'email' => $g?->email ?? $last?->email,
            'guest_id' => $g?->id, 'vip' => (bool) ($g?->is_vip ?? false)] + $h);
    }

    // ------------------------------------------------------------------ settings

    public function settings(Request $request)
    {
        $outlet = $this->outlet($request, true);

        return view('tables.settings', ['outlet' => $outlet, 'outlets' => Outlet::where('is_active', true)->whereIn('type', ['restaurant', 'bar', 'cafe'])->orderBy('id')->get(),
            'cfg' => $outlet ? TB::cfg($outlet) : TB::DEFAULTS, 'periods' => $outlet ? $outlet->periods()->get() : collect(),
            'tables' => $outlet ? PosTable::where('outlet_id', $outlet->id)->orderBy('id')->get() : collect()]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'outlet_id' => 'required|integer|owned:outlets', 'tb_enabled' => 'nullable|boolean', 'tb_online' => 'nullable|boolean',
            'slot_minutes' => 'required|integer|in:10,15,20,30,60', 'buffer' => 'required|integer|min:0|max:60', 'hold_minutes' => 'required|integer|min:5|max:120',
            'auto_no_show' => 'nullable|boolean', 'min_notice' => 'required|integer|min:0|max:2880', 'days_ahead' => 'required|integer|min:1|max:365',
            'max_online_party' => 'required|integer|min:1|max:50', 'pacing_covers' => 'nullable|integer|min:0|max:500', 'auto_confirm_online' => 'nullable|boolean',
            'reminder_hours' => 'nullable|integer|min:0|max:48', 'noshow_flag' => 'nullable|integer|min:0|max:20', 'online_note' => 'nullable|string|max:500',
            'durations' => 'required|array|max:10', 'durations.*.party' => 'nullable|integer|min:1|max:99', 'durations.*.minutes' => 'nullable|integer|min:30|max:360',
            'periods' => 'nullable|array|max:10', 'periods.*.id' => 'nullable|integer', 'periods.*.name' => 'required_with:periods.*.start_time|nullable|string|max:40',
            'periods.*.start_time' => 'required_with:periods.*.name|nullable|date_format:H:i', 'periods.*.end_time' => 'required_with:periods.*.name|nullable|date_format:H:i',
            'periods.*.days' => 'nullable|array', 'periods.*.is_active' => 'nullable|boolean',
            'tables' => 'nullable|array', 'tables.*.capacity' => 'required|integer|min:1|max:30', 'tables.*.min_capacity' => 'required|integer|min:1|max:30',
            'tables.*.shape' => 'required|in:square,round,rect,booth', 'tables.*.section' => 'nullable|string|max:40', 'tables.*.combinable' => 'nullable|boolean', 'tables.*.reservable' => 'nullable|boolean',
        ]);
        foreach ((array) ($data['periods'] ?? []) as $i => $p) {
            if (! empty($p['start_time']) && ! empty($p['end_time']) && $p['end_time'] < $p['start_time']) {
                return back()->withInput()->withErrors(["periods.$i.end_time" => "{$p['name']}: the last seating must be after the first seating."]);
            }
        }
        if ($request->boolean('tb_enabled') && ! collect($data['periods'] ?? [])->contains(fn ($p) => ! empty($p['name']) && ($p['is_active'] ?? false))) {
            return back()->withInput()->withErrors(['periods' => 'Add at least one active service period (e.g. Dinner 19:00 – 22:30) before switching reservations on.']);
        }
        $o = Outlet::findOrFail($data['outlet_id']);
        TB::saveSettings($o, $data);

        return redirect()->route('tb.settings', ['outlet' => $o->id])->with('success', "Reservation settings for {$o->name} saved.");
    }
}
