<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityBooking;
use App\Models\ActivityDeparture;
use App\Models\ActivitySettlement;
use App\Models\ActivityVendor;
use App\Models\ConciergePlace;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Services\ActivityLogger;
use App\Services\ConciergeService as CS;
use App\Services\EInvoiceService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v5.9 Concierge desk: activities & excursions sold as agent, departures & manifests, vendors, local guide, settlements. */
class ConciergeController extends Controller
{
    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', is_string($r) ? $r : $ok);
    }

    /** in-house guests and today's / tomorrow's arrivals a booking can be charged to */
    protected function stays()
    {
        return ReservationRoom::with('guest', 'room', 'reservation.guest')->where(fn ($q) => $q->where('status', 'checked_in')
            ->orWhere(fn ($w) => $w->where('status', 'reserved')->whereDate('arrival_date', '>=', bdate()->toDateString())->whereDate('arrival_date', '<=', bdate()->copy()->addDays(7)->toDateString())))
            ->get()->sortBy(fn ($s) => [$s->status === 'checked_in' ? 0 : 1, $s->room?->room_no ?? 'zzz'])->values();
    }

    public function index(Request $r)
    {
        CS::ensureDefaults();
        $date = $r->filled('date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->date) ? Carbon::parse($r->date) : bdate()->copy();
        $waiting = ActivityBooking::with('activity.vendor')->where('status', 'requested')->whereDate('date', '>=', bdate()->toDateString())->orderBy('date')->orderBy('time')->limit(30)->get();
        $waitlist = ActivityBooking::with('activity')->where('status', 'waitlisted')->whereDate('date', '>=', bdate()->toDateString())->orderBy('date')->orderBy('time')->limit(30)->get();
        $bal = CS::balances();

        return view('concierge.index', [
            'date' => $date, 'board' => CS::board($date), 'stats' => CS::monthStats(bdate()->copy()), 'alerts' => CS::alerts(), 'waiting' => $waiting, 'waitlist' => $waitlist,
            'payable' => $bal->sum('payable'), 'receivable' => $bal->sum('receivable'), 'cfg' => CS::cfg(),
            'upcoming' => ActivityBooking::whereIn('status', ['requested', 'confirmed'])->whereDate('date', '>=', bdate()->copy()->addDay()->toDateString())->whereDate('date', '<=', bdate()->copy()->addDays(7)->toDateString())->count(),
        ]);
    }

    public function bookings(Request $r)
    {
        $q = ActivityBooking::with('activity', 'vendor', 'creator')->orderByDesc('date')->orderByDesc('time')->orderByDesc('id');
        if ($r->filled('status')) {
            $r->status === 'open' ? $q->whereIn('status', ActivityBooking::OPEN) : $q->where('status', $r->status);
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if ($r->filled($k) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->$k)) {
                $q->whereDate('date', $op, $r->$k);
            }
        }
        if ($r->filled('vendor')) {
            $q->where('activity_vendor_id', (int) $r->vendor);
        }
        if ($r->filled('source')) {
            $q->where('source', $r->source);
        }
        if ($r->filled('q')) {
            $s = '%'.trim((string) $r->q).'%';
            $q->where(fn ($w) => $w->where('number', 'like', $s)->orWhere('guest_name', 'like', $s)->orWhere('room_no', 'like', $s)->orWhere('vendor_ref', 'like', $s));
        }

        return view('concierge.bookings', ['bookings' => $q->paginate(40)->withQueryString(), 'vendors' => ActivityVendor::orderBy('name')->get()]);
    }

    public function create(Request $r)
    {
        CS::ensureDefaults();
        $activities = Activity::with('vendor')->where('is_active', true)->whereHas('vendor', fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('name')->get();

        return view('concierge.create', ['activities' => $activities, 'stays' => $this->stays(), 'pre' => [
            'activity_id' => (int) $r->query('activity', 0), 'reservation_room_id' => (int) $r->query('stay', 0), 'date' => $r->query('date', bdate()->copy()->addDay()->toDateString()), 'time' => $r->query('time'),
        ]]);
    }

    protected function bookingRules(): array
    {
        return ['activity_id' => 'required|integer|owned:activities', 'date' => 'required|date', 'time' => 'nullable|date_format:H:i', 'adults' => 'required|integer|min:1|max:30', 'children' => 'nullable|integer|min:0|max:30',
            'reservation_room_id' => 'nullable|integer|owned:reservation_rooms', 'guest_name' => 'nullable|string|max:150', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:150',
            'pickup_point' => 'nullable|string|max:150', 'pickup_time' => 'nullable|date_format:H:i', 'notes' => 'nullable|string|max:500', 'collection' => 'nullable|in:folio,vendor',
            'waitlist' => 'nullable|boolean', 'vendor_ref' => 'nullable|string|max:60', 'override_cutoff' => 'nullable|boolean', 'source' => 'nullable|in:desk,phone'];
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->bookingRules());
        try {
            $b = CS::book($d, $r->user(), $d['source'] ?? 'desk');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('concierge.booking', $b)->with('success', "Booking {$b->number} ".($b->status === 'waitlisted' ? 'added to the waitlist.' : ($b->status === 'confirmed' ? 'confirmed.' : 'saved – confirm it with the vendor.')));
    }

    public function show(ActivityBooking $cbooking)
    {
        $b = $cbooking->load('activity.vendor', 'departure', 'stay.room', 'folio', 'folioTransaction', 'journal', 'settlement', 'creator', 'confirmer');
        $log = \App\Models\ActivityLog::where('subject_type', 'ActivityBooking')->where('subject_id', $b->id)->latest('id')->limit(20)->get();

        return view('concierge.show', ['b' => $b, 'fee' => CS::cancelFee($b), 'log' => $log,
            'messages' => \App\Models\MessageLog::where('related_type', ActivityBooking::class)->where('related_id', $b->id)->latest('id')->limit(10)->get()]);
    }

    public function update(Request $r, ActivityBooking $cbooking)
    {
        $d = $r->validate(['date' => 'required|date', 'time' => 'required|date_format:H:i', 'adults' => 'required|integer|min:1|max:30', 'children' => 'nullable|integer|min:0|max:30',
            'pickup_point' => 'nullable|string|max:150', 'pickup_time' => 'nullable|date_format:H:i', 'notes' => 'nullable|string|max:500', 'phone' => 'nullable|string|max:30', 'override_cutoff' => 'nullable|boolean']);

        return $this->attempt(fn () => CS::update($cbooking, $d, $r->user()) && false, 'Booking updated.');
    }

    public function confirm(Request $r, ActivityBooking $cbooking)
    {
        $d = $r->validate(['vendor_ref' => 'nullable|string|max:60']);

        return $this->attempt(function () use ($cbooking, $d, $r) {
            CS::confirm($cbooking, $d['vendor_ref'] ?? null, $r->user());

            return "{$cbooking->number} confirmed".($cbooking->collection === 'folio' ? ' and charged to the room.' : '.');
        }, 'Confirmed.');
    }

    public function complete(Request $r, ActivityBooking $cbooking)
    {
        return $this->attempt(function () use ($cbooking, $r) {
            $b = CS::complete($cbooking, $r->user());

            return "{$b->number} completed – commission ".money($b->commission).' recognised.';
        }, 'Completed.');
    }

    public function noShow(Request $r, ActivityBooking $cbooking)
    {
        $d = $r->validate(['reason' => 'nullable|string|max:190']);

        return $this->attempt(function () use ($cbooking, $d, $r) {
            CS::noShow($cbooking, $d['reason'] ?? 'Guest did not show', $r->user());

            return "{$cbooking->number} marked no-show.";
        }, 'No-show recorded.');
    }

    public function cancel(Request $r, ActivityBooking $cbooking)
    {
        $d = $r->validate(['reason' => 'required|string|max:190', 'waive' => 'nullable|boolean']);
        $waive = ! empty($d['waive']);
        if ($waive && ! can_do('concierge.manage')) {
            return back()->with('error', 'Only a concierge manager can waive the cancellation fee.');
        }

        return $this->attempt(function () use ($cbooking, $d, $r, $waive) {
            $b = CS::cancel($cbooking, $d['reason'], $r->user(), $waive);

            return "{$b->number} cancelled".((float) $b->cancel_fee > 0 ? ' – cancellation fee '.money($b->cancel_fee).' charged to the room.' : '.');
        }, 'Cancelled.');
    }

    public function sendVoucher(ActivityBooking $cbooking)
    {
        if (! in_array($cbooking->status, ['confirmed', 'completed'], true)) {
            return back()->with('error', 'Only confirmed bookings have a voucher.');
        }
        if (! $cbooking->phone && ! $cbooking->email) {
            return back()->with('error', 'Add the guest\'s phone number or e-mail first.');
        }

        return CS::notify($cbooking, 'voucher', true) ? back()->with('success', 'Voucher sent to the guest.') : back()->with('error', 'The voucher could not be sent – check the SMS / e-mail settings.');
    }

    public function voucher(ActivityBooking $cbooking)
    {
        $cbooking->load('activity.vendor');

        return view('concierge.voucher', ['b' => $cbooking, 'hotel' => hotel(), 'qr' => EInvoiceService::qrSvg($cbooking->number.'|'.$cbooking->voucher_token), 'cfg' => CS::cfg(), 'public' => false]);
    }

    /** the guest's voucher link (SMS / e-mail) – booking number, activity, time, meeting point; no room number */
    public function publicVoucher(string $token)
    {
        $b = ActivityBooking::withoutGlobalScopes()->where('voucher_token', $token)->firstOrFail();
        $hotel = Hotel::find($b->hotel_id);
        abort_unless($hotel?->is_active, 404);
        Tenant::set($hotel);
        $b = ActivityBooking::with('activity.vendor')->findOrFail($b->id);
        abort_unless(in_array($b->status, ['confirmed', 'completed'], true), 404);

        return response()->view('concierge.voucher', ['b' => $b, 'hotel' => $hotel, 'qr' => EInvoiceService::qrSvg($b->number.'|'.$b->voucher_token), 'cfg' => CS::cfg(), 'public' => true, 'currentHotel' => $hotel])
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }

    /** JSON: departure times with seats left and the price for a party (booking form) */
    public function slots(Request $r)
    {
        $a = $r->filled('activity') ? Activity::with('vendor')->find((int) $r->activity) : null;
        if (! $a || ! $r->filled('date') || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->date)) {
            return response()->json(['slots' => [], 'quote' => null, 'runs' => false]);
        }
        $d = Carbon::parse($r->date);

        return response()->json(['runs' => $a->runsOn($d), 'scheduled' => $a->isScheduled(), 'slots' => CS::slots($a, $d), 'days' => $a->daysLabel(),
            'quote' => CS::quote($a, max(1, (int) $r->query('adults', 1)), max(0, (int) $r->query('children', 0))), 'collection' => $a->collectionMode()]);
    }

    // ───────── departures ─────────

    public function manifest(ActivityDeparture $cdeparture)
    {
        $cdeparture->load('activity.vendor');
        $list = $cdeparture->bookings()->whereIn('status', ['requested', 'confirmed', 'completed', 'no_show', 'waitlisted'])->orderByRaw("FIELD(status,'confirmed','completed','no_show','requested','waitlisted')")->orderBy('id')->get();

        return view('concierge.manifest', ['dep' => $cdeparture, 'list' => $list, 'hotel' => hotel()]);
    }

    public function departureUpdate(Request $r, ActivityDeparture $cdeparture)
    {
        $d = $r->validate(['status' => 'required|in:open,closed', 'capacity' => 'nullable|integer|min:1|max:500', 'guide' => 'nullable|string|max:100']);

        return $this->attempt(fn () => CS::setDepartureStatus($cdeparture, $d['status'], isset($d['capacity']) ? (int) $d['capacity'] : null, $d['guide'] ?? null) && false, 'Departure updated.');
    }

    public function departureCancel(Request $r, ActivityDeparture $cdeparture)
    {
        $d = $r->validate(['reason' => 'required|string|max:190']);

        return $this->attempt(fn () => 'Departure cancelled – '.CS::cancelDeparture($cdeparture, $d['reason'], $r->user()).' booking(s) cancelled free of charge and the guests told.', 'Cancelled.');
    }

    // ───────── activities & vendors ─────────

    public function catalogue()
    {
        return view('concierge.catalogue', [
            'activities' => Activity::with('vendor')->withCount(['bookings as sold' => fn ($q) => $q->whereIn('status', ['confirmed', 'completed'])])->orderBy('sort_order')->orderBy('name')->get(),
            'vendors' => ActivityVendor::withCount('activities')->orderBy('name')->get(),
        ]);
    }

    protected function activityData(Request $r, ?Activity $a = null): array
    {
        $d = $r->validate([
            'activity_vendor_id' => 'required|integer|owned:activity_vendors', 'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('activities', 'code')->where('hotel_id', Tenant::id())->ignore($a?->id)],
            'name' => 'required|string|max:150', 'category' => ['required', Rule::in(array_keys(Activity::CATEGORIES))], 'description' => 'nullable|string|max:3000', 'highlights' => 'nullable|string|max:255',
            'bring' => 'nullable|string|max:255', 'duration_min' => 'required|integer|min:15|max:1440', 'meeting_point' => 'nullable|string|max:150', 'pickup_included' => 'nullable|boolean',
            'pricing' => 'required|in:person,group', 'price_adult' => 'required|numeric|min:0|max:1000000', 'price_child' => 'nullable|numeric|min:0|max:1000000', 'child_max_age' => 'nullable|integer|min:0|max:17',
            'commission_pct' => 'nullable|numeric|min:0|max:60', 'collection' => 'nullable|in:folio,vendor', 'min_pax' => 'required|integer|min:1|max:100', 'capacity' => 'nullable|integer|min:1|max:500',
            'schedule' => 'required|in:scheduled,request', 'weekdays' => 'nullable|array', 'weekdays.*' => 'integer|between:1,7', 'times' => 'nullable|string|max:120', 'instant' => 'nullable|boolean',
            'cutoff_hours' => 'required|integer|min:0|max:720', 'free_cancel_hours' => 'required|integer|min:0|max:720', 'cancel_fee_pct' => 'required|numeric|min:0|max:100',
            'portal_visible' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:0|max:9999', 'is_active' => 'nullable|boolean',
        ]);
        $times = collect(preg_split('/[\s,;]+/', (string) ($d['times'] ?? '')))->filter()->map(fn ($t) => preg_match('/^(\d{1,2})[:.](\d{2})$/', $t, $m) && $m[1] < 24 && $m[2] < 60 ? sprintf('%02d:%02d', $m[1], $m[2]) : false);
        if ($times->contains(false)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['times' => 'Enter departure times as HH:MM, separated by commas.']);
        }
        if ($d['schedule'] === 'scheduled' && ($times->isEmpty() || empty($d['weekdays']))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['times' => 'A scheduled activity needs at least one weekday and one departure time.']);
        }
        if ($d['pricing'] === 'group' && empty($d['capacity'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['capacity' => 'A private group price needs the maximum group size.']);
        }
        $d['times'] = $times->unique()->sort()->values()->all();
        $d['weekdays'] = array_values(array_unique(array_map('intval', $d['weekdays'] ?? [])));
        foreach (['pickup_included', 'instant', 'portal_visible', 'is_active'] as $k) {
            $d[$k] = $r->boolean($k);
        }
        $d['code'] = strtoupper($d['code']);
        $d['price_child'] = (float) ($d['price_child'] ?? 0);
        $d['child_max_age'] = (int) ($d['child_max_age'] ?? 11);
        $d['sort_order'] = (int) ($d['sort_order'] ?? 0);
        $d['commission_pct'] = ($d['commission_pct'] ?? '') === '' ? null : $d['commission_pct'];

        return $d;
    }

    public function activityStore(Request $r)
    {
        $a = Activity::create($this->activityData($r));
        ActivityLogger::log('concierge', 'activity_created', "Activity {$a->code} {$a->name} added", $a);

        return back()->with('success', "{$a->name} added.");
    }

    public function activityUpdate(Request $r, Activity $cactivity)
    {
        $cactivity->update($this->activityData($r, $cactivity));
        ActivityLogger::log('concierge', 'activity_updated', "Activity {$cactivity->code} updated", $cactivity);

        return back()->with('success', "{$cactivity->name} saved.");
    }

    public function activityDelete(Activity $cactivity)
    {
        if ($cactivity->bookings()->exists()) {
            $cactivity->update(['is_active' => false]);

            return back()->with('success', "{$cactivity->name} has bookings, so it was taken off sale instead of deleted.");
        }
        $cactivity->departures()->delete();
        $cactivity->delete();

        return back()->with('success', 'Activity deleted.');
    }

    protected function vendorData(Request $r): array
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'kind' => ['required', Rule::in(array_keys(ActivityVendor::KINDS))], 'contact_name' => 'nullable|string|max:100', 'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150', 'payout_details' => 'nullable|string|max:255', 'licence_no' => 'nullable|string|max:60', 'licence_expires' => 'nullable|date', 'commission_pct' => 'required|numeric|min:0|max:60',
            'collection' => 'required|in:folio,vendor', 'notes' => 'nullable|string|max:255', 'is_active' => 'nullable|boolean']);
        $d['is_active'] = $r->boolean('is_active');

        return $d;
    }

    public function vendorStore(Request $r)
    {
        $v = ActivityVendor::create($this->vendorData($r));
        ActivityLogger::log('concierge', 'vendor_created', "Activity vendor {$v->name} added", $v);

        return back()->with('success', "{$v->name} added.");
    }

    public function vendorUpdate(Request $r, ActivityVendor $cvendor)
    {
        $cvendor->update($this->vendorData($r));
        ActivityLogger::log('concierge', 'vendor_updated', "Activity vendor {$cvendor->name} updated", $cvendor);

        return back()->with('success', "{$cvendor->name} saved.");
    }

    public function vendorDelete(ActivityVendor $cvendor)
    {
        if ($cvendor->activities()->exists() || $cvendor->bookings()->exists()) {
            return back()->with('error', "{$cvendor->name} has activities or bookings – switch it off instead.");
        }
        $cvendor->delete();

        return back()->with('success', 'Vendor deleted.');
    }

    // ───────── local guide ─────────

    public function guide()
    {
        return view('concierge.guide', ['places' => ConciergePlace::orderBy('category')->orderBy('sort_order')->orderBy('name')->get()->groupBy('category')]);
    }

    protected function placeData(Request $r): array
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'category' => ['required', Rule::in(array_keys(ConciergePlace::CATEGORIES))], 'area' => 'nullable|string|max:100', 'address' => 'nullable|string|max:190',
            'phone' => 'nullable|string|max:30', 'website' => 'nullable|url|max:190', 'distance_km' => 'nullable|numeric|min:0|max:9999', 'hours' => 'nullable|string|max:120', 'price_level' => 'nullable|integer|between:1,4',
            'description' => 'nullable|string|max:500', 'tip' => 'nullable|string|max:255', 'portal_visible' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:0|max:9999']);
        $d['portal_visible'] = $r->boolean('portal_visible');
        $d['sort_order'] = (int) ($d['sort_order'] ?? 0);

        return $d;
    }

    public function placeStore(Request $r)
    {
        $p = ConciergePlace::create($this->placeData($r));

        return back()->with('success', "{$p->name} added to the guide.");
    }

    public function placeUpdate(Request $r, ConciergePlace $cplace)
    {
        $cplace->update($this->placeData($r));

        return back()->with('success', "{$cplace->name} saved.");
    }

    public function placeDelete(ConciergePlace $cplace)
    {
        $cplace->delete();

        return back()->with('success', 'Removed from the guide.');
    }

    public function guidePrint()
    {
        return view('concierge.guide_print', ['places' => ConciergePlace::where('portal_visible', true)->orderBy('category')->orderBy('sort_order')->orderBy('name')->get()->groupBy('category'),
            'activities' => CS::portalActivities(), 'hotel' => hotel(), 'cfg' => CS::cfg()]);
    }

    // ───────── settlements ─────────

    public function settlements(Request $r)
    {
        $upTo = $r->filled('up_to') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->up_to) ? $r->up_to : bdate()->toDateString();

        return view('concierge.settlements', ['balances' => CS::balances($upTo), 'upTo' => $upTo, 'accounts' => CS::payAccounts(),
            'settlements' => ActivitySettlement::with('vendor', 'account', 'creator')->orderByDesc('date')->orderByDesc('id')->limit(60)->get(),
            'control' => ['payable' => CS::payableBalance(), 'receivable' => CS::receivableBalance()], 'open' => CS::balances()]);
    }

    public function settle(Request $r)
    {
        $d = $r->validate(['activity_vendor_id' => 'required|integer|owned:activity_vendors', 'up_to' => 'required|date', 'treasury_account_id' => 'nullable|integer|owned:treasury_accounts',
            'date' => 'required|date', 'reference' => 'nullable|string|max:80']);
        try {
            $s = CS::settle(ActivityVendor::findOrFail($d['activity_vendor_id']), Carbon::parse($d['up_to'])->toDateString(), (int) ($d['treasury_account_id'] ?? 0), Carbon::parse($d['date'])->toDateString(), $d['reference'] ?? null, $r->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('concierge.settlement', $s)->with('success', "Settlement {$s->number} posted – ".strtolower($s->direction()).' '.money(abs((float) $s->net)).'.');
    }

    public function settlement(ActivitySettlement $csettle)
    {
        $csettle->load('vendor', 'account', 'journal', 'creator');
        $rows = ActivityBooking::with('activity')->where('activity_settlement_id', $csettle->id)->orderBy('date')->orderBy('id')->get();
        if ($csettle->status === 'void') {
            $rows = collect();
        }

        return view('concierge.settlement', ['s' => $csettle, 'rows' => $rows, 'hotel' => hotel()]);
    }

    public function settlementVoid(Request $r, ActivitySettlement $csettle)
    {
        $d = $r->validate(['reason' => 'required|string|max:190']);

        return $this->attempt(fn () => CS::voidSettlement($csettle, $d['reason'], $r->user()) && false, 'Settlement voided – its bookings are open for settlement again.');
    }

    // ───────── settings ─────────

    public function settings()
    {
        CS::ensureDefaults();

        return view('concierge.settings', ['cfg' => CS::cfg()]);
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['portal_lead' => 'required|integer|min:0|max:168', 'confirm_hours' => 'required|integer|min:0|max:168', 'desk_phone' => 'nullable|string|max:30']);
        CS::saveSettings($d + ['portal' => $r->boolean('portal'), 'auto_complete' => $r->boolean('auto_complete'), 'notify' => $r->boolean('notify'), 'desk_phone' => $d['desk_phone'] ?? '']);

        return back()->with('success', 'Concierge settings saved.');
    }
}
