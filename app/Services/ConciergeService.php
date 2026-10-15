<?php

namespace App\Services;

use App\Mail\GuestMessage;
use App\Models\AccountHead;
use App\Models\Activity;
use App\Models\ActivityBooking;
use App\Models\ActivityDeparture;
use App\Models\ActivitySettlement;
use App\Models\ActivityVendor;
use App\Models\FolioTransaction;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v5.9 Concierge, activities & excursions desk.
 *
 * The hotel sells third-party activities as agent. Collection "folio": the gross goes on the room bill (charge category
 * "excursion", no VAT – the hotel is not the supplier) and is credited to 2170 Activity Vendors Payable; when the activity
 * has taken place the commission is moved to 4089 Concierge Commission (Dr 2170 / Cr 4089) and the balance is paid to the
 * vendor at settlement (Dr 2170 / Cr bank). Collection "vendor": the guest pays the vendor; on completion the commission is
 * recognised as a receivable (Dr 1140 / Cr 4089) and collected at settlement (Dr bank / Cr 1140). A settlement nets both.
 */
class ConciergeService
{
    public const DEFAULTS = [
        'concierge_portal' => '1',          // guests can book activities on the guest portal
        'concierge_portal_lead' => '3',     // minimum hours ahead for portal bookings (on top of the activity's own cut-off)
        'concierge_auto_complete' => '1',   // the night audit completes confirmed bookings of the day it closes
        'concierge_confirm_hours' => '4',   // requests waiting longer than this for the vendor are flagged
        'concierge_notify' => '1',          // SMS / e-mail the guest on confirmation, waitlist seats and cancellation
        'concierge_desk_phone' => '',       // shown on vouchers and the portal
    ];

    protected static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
    }

    public static function cfg(): array
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $v) {
            $s[substr($k, 10)] = (string) setting($k, $v);
        }
        foreach (['portal_lead', 'confirm_hours'] as $k) {
            $s[$k] = max(0, (int) $s[$k]);
        }

        return $s;
    }

    public static function saveSettings(array $d): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            $short = substr($k, 10);
            if (! array_key_exists($short, $d)) {
                continue;
            }
            $val = is_bool($d[$short]) ? ($d[$short] ? '1' : '0') : trim((string) $d[$short]);
            Setting::updateOrCreate(['key' => $k], ['value' => $val]);
        }
        Setting::$version++;
        ActivityLogger::log('concierge', 'settings', 'Concierge settings updated');
    }

    // ───────────────────────── pricing & departures ─────────────────────────

    /** price a booking: [price_adult, price_child, gross, commission_pct, commission, vendor_net] */
    public static function quote(Activity $a, int $adults, int $children, ?float $pct = null): array
    {
        $pa = (float) $a->price_adult;
        $pc = (float) $a->price_child;
        $gross = $a->pricing === 'group' ? $pa : $adults * $pa + $children * $pc;
        $pct ??= $a->commissionPct();
        $commission = round($gross * $pct / 100, 2);

        return ['price_adult' => $pa, 'price_child' => $a->pricing === 'group' ? 0.0 : $pc, 'gross' => round($gross, 2), 'commission_pct' => $pct,
            'commission' => $commission, 'vendor_net' => round($gross - $commission, 2)];
    }

    /** the departure a scheduled activity has at date + time (created on first booking) */
    public static function departure(Activity $a, string $date, string $time, bool $create = true): ?ActivityDeparture
    {
        if (! $a->isScheduled()) {
            return null;
        }
        $d = Carbon::parse($date);
        if (! $a->runsOn($d)) {
            throw new RuntimeException($a->name.' does not run on '.$d->format('l').'s ('.$a->daysLabel().').');
        }
        if (! in_array($time, $a->timesList(), true)) {
            throw new RuntimeException($a->name.' leaves at '.implode(' / ', $a->timesList()).' – choose one of those times.');
        }
        $q = ActivityDeparture::where('activity_id', $a->id)->whereDate('date', $d->toDateString())->where('time', $time);

        return $create ? ($q->first() ?? ActivityDeparture::create(['activity_id' => $a->id, 'date' => $d->toDateString(), 'time' => $time, 'capacity' => $a->capacity]))
            : $q->first();
    }

    /** departure times of an activity on a date with seats left: [[time, left|null, status]] */
    public static function slots(Activity $a, Carbon $date): array
    {
        if (! $a->isScheduled() || ! $a->runsOn($date)) {
            return [];
        }
        $deps = ActivityDeparture::where('activity_id', $a->id)->whereDate('date', $date->toDateString())->get()->keyBy('time');
        $out = [];
        foreach ($a->timesList() as $t) {
            $dep = $deps[$t] ?? null;
            $left = $dep ? $dep->seatsLeft() : $a->capacity;
            $out[] = ['time' => $t, 'left' => $left, 'status' => $dep?->status ?? 'open'];
        }

        return $out;
    }

    // ───────────────────────── bookings ─────────────────────────

    /** the stay a booking is for, if it can still take charges */
    protected static function liveStay(?int $stayId): ?ReservationRoom
    {
        if (! $stayId) {
            return null;
        }
        $stay = ReservationRoom::with('guest', 'room', 'reservation.guest')->find($stayId) ?? throw new RuntimeException('That stay was not found.');
        if (! in_array($stay->status, ['reserved', 'checked_in'], true)) {
            throw new RuntimeException('That stay is '.str_replace('_', ' ', $stay->status).' – book it without a room, paid to the vendor.');
        }

        return $stay;
    }

    /**
     * Create a booking. $d: activity_id, date, time, adults, children, reservation_room_id | guest_name + phone + email,
     * pickup_point, pickup_time, notes, collection (folio|vendor), waitlist (bool), vendor_ref, override_cutoff (bool, manager).
     */
    public static function book(array $d, ?User $by, string $source = 'desk'): ActivityBooking
    {
        $a = Activity::with('vendor')->find($d['activity_id'] ?? 0) ?? throw new RuntimeException('Choose an activity.');
        if (! $a->is_active || ! $a->vendor?->is_active) {
            throw new RuntimeException($a->name.' is not on sale.');
        }
        if ($a->vendor->licenceExpired()) {
            throw new RuntimeException($a->vendor->name.'\'s tourism licence expired on '.$a->vendor->licence_expires->format('d M Y').' – renew it before selling their activities.');
        }
        [$adults, $children] = [max(0, (int) ($d['adults'] ?? 1)), max(0, (int) ($d['children'] ?? 0))];
        if ($adults < 1) {
            throw new RuntimeException('At least one adult has to go.');
        }
        $time = $a->isScheduled() ? (string) ($d['time'] ?? '') : (string) (($d['time'] ?? '') ?: '09:00');
        if (! preg_match('/^\d{2}:\d{2}$/', $time) || empty($d['date'])) {
            throw new RuntimeException('Choose the date and time.');
        }
        $start = Carbon::parse($d['date'].' '.$time);
        self::checkTiming($a, $start, $source, $by, ! empty($d['override_cutoff']));
        self::checkParty($a, $adults, $children);

        $stay = self::liveStay(isset($d['reservation_room_id']) ? (int) $d['reservation_room_id'] : null);
        $collection = $d['collection'] ?? $a->collectionMode();
        if (! in_array($collection, ['folio', 'vendor'], true)) {
            throw new RuntimeException('Choose how the guest pays.');
        }
        if ($collection === 'folio') {
            if (! $stay) {
                throw new RuntimeException('Only a staying guest can charge an activity to the room – choose "Guest pays the vendor".');
            }
            if ($start->toDateString() < $stay->arrival_date->toDateString() || $start->toDateString() > $stay->departure_date->toDateString()) {
                throw new RuntimeException('The activity is outside the stay ('.$stay->arrival_date->format('d M').' – '.$stay->departure_date->format('d M').') – it can\'t go on the room bill.');
            }
        }
        $guest = $stay?->guest ?? $stay?->reservation?->guest;
        $name = trim((string) ($d['guest_name'] ?? '')) ?: ($guest?->full_name ?? '');
        if ($name === '') {
            throw new RuntimeException('Enter the guest\'s name.');
        }

        return DB::transaction(function () use ($a, $d, $by, $source, $adults, $children, $time, $start, $stay, $collection, $guest, $name) {
            $dep = self::departure($a, $start->toDateString(), $time);
            if ($dep) {
                $dep = ActivityDeparture::whereKey($dep->id)->lockForUpdate()->first();
                if ($dep->status === 'cancelled') {
                    throw new RuntimeException('The '.$start->format('d M H:i').' departure is cancelled'.($dep->cancel_reason ? ' ('.$dep->cancel_reason.')' : '').'.');
                }
                if ($dep->status === 'closed') {
                    throw new RuntimeException('The '.$start->format('d M H:i').' departure is closed for sale.');
                }
            }
            $left = $dep?->seatsLeft();
            $full = $left !== null && $left < $adults + $children;
            if ($full && empty($d['waitlist'])) {
                throw new RuntimeException($left ? "Only {$left} seat(s) left on the ".$start->format('d M H:i').' departure – put the guest on the waitlist or choose another time.'
                    : 'The '.$start->format('d M H:i').' departure is full – put the guest on the waitlist or choose another time.');
            }
            $q = self::quote($a, $adults, $children);
            $b = ActivityBooking::create($q + [
                'number' => Numbering::next('activity_booking'), 'activity_id' => $a->id, 'activity_vendor_id' => $a->activity_vendor_id, 'activity_departure_id' => $dep?->id,
                'reservation_id' => $stay?->reservation_id, 'reservation_room_id' => $stay?->id, 'guest_id' => $guest?->id, 'guest_name' => mb_substr($name, 0, 150),
                'room_no' => $stay?->room?->room_no, 'phone' => ($d['phone'] ?? null) ?: $guest?->phone, 'email' => ($d['email'] ?? null) ?: $guest?->email,
                'date' => $start->toDateString(), 'time' => $time, 'adults' => $adults, 'children' => $children,
                'pickup_point' => ($d['pickup_point'] ?? null) ?: ($a->pickup_included ? hotel()->name.' – main lobby' : null), 'pickup_time' => ($d['pickup_time'] ?? null) ?: null,
                'notes' => isset($d['notes']) ? mb_substr((string) $d['notes'], 0, 500) : null, 'source' => $source, 'collection' => $collection,
                'status' => $full ? 'waitlisted' : 'requested', 'vendor_ref' => $d['vendor_ref'] ?? null, 'voucher_token' => Str::random(40), 'created_by' => $by?->id,
            ]);
            ActivityLogger::log('concierge', 'booked', "Activity {$b->number}: {$a->name} ".$start->format('d M H:i')." for {$b->guest_name} ({$b->paxLabel()}) – ".money($b->gross).($full ? ' – waitlisted' : ''), $b);
            if (! $full && $a->instant) {
                self::confirm($b, $d['vendor_ref'] ?? null, $by, false);
            }
            self::notify($b->fresh(), $b->status === 'waitlisted' ? 'waitlisted' : ($b->fresh()->status === 'confirmed' ? 'confirmed' : 'received'));

            return $b->fresh();
        });
    }

    protected static function checkTiming(Activity $a, Carbon $start, string $source, ?User $by, bool $override): void
    {
        if ($start->lte(now())) {
            throw new RuntimeException('That time has already passed.');
        }
        $cut = (int) $a->cutoff_hours;
        if ($source === 'portal') {
            $lead = max($cut, self::cfg()['portal_lead']);
            if ($start->lt(now()->addHours($lead))) {
                throw new RuntimeException("Please book at least {$lead} hours ahead – for anything sooner, ask the concierge.");
            }
        } elseif ($start->lt(now()->addHours($cut)) && ! ($override && $by?->hasPermission('concierge.manage'))) {
            throw new RuntimeException("{$a->name} closes for booking {$cut} hours before it starts – a manager can override with the vendor's OK.");
        }
    }

    protected static function checkParty(Activity $a, int $adults, int $children): void
    {
        $pax = $adults + $children;
        if ($a->pricing === 'group' && $a->capacity && $pax > $a->capacity) {
            throw new RuntimeException("{$a->name} takes up to {$a->capacity} people per private booking.");
        }
        if (! $a->isScheduled() && $pax < $a->min_pax) {
            throw new RuntimeException("{$a->name} needs at least {$a->min_pax} people.");
        }
        if ($pax > 30) {
            throw new RuntimeException('For more than 30 people, arrange a group booking with the vendor.');
        }
    }

    /** change date / time / party / pickup / notes while the booking is still open (re-priced; a room charge is re-posted) */
    public static function update(ActivityBooking $b, array $d, ?User $by): ActivityBooking
    {
        if (! $b->isOpen()) {
            throw new RuntimeException("{$b->number} is {$b->statusLabel()} and can't be changed.");
        }
        $a = $b->activity()->with('vendor')->first();
        [$adults, $children] = [max(0, (int) ($d['adults'] ?? $b->adults)), max(0, (int) ($d['children'] ?? $b->children))];
        if ($adults < 1) {
            throw new RuntimeException('At least one adult has to go.');
        }
        $time = (string) ($d['time'] ?? $b->time);
        $start = Carbon::parse(($d['date'] ?? $b->date->toDateString()).' '.$time);
        $moved = $start->ne($b->startsAt());
        if ($moved || $adults + $children > $b->pax()) {
            self::checkTiming($a, $start, 'desk', $by, ! empty($d['override_cutoff']));
        }
        self::checkParty($a, $adults, $children);
        if ($b->collection === 'folio' && ($stay = $b->stay) && ($start->toDateString() < $stay->arrival_date->toDateString() || $start->toDateString() > $stay->departure_date->toDateString())) {
            throw new RuntimeException('The new date is outside the stay – it can\'t go on the room bill.');
        }

        return DB::transaction(function () use ($b, $a, $d, $adults, $children, $time, $start, $moved, $by) {
            $oldDep = $b->departure;
            $dep = self::departure($a, $start->toDateString(), $time);
            if ($dep && $b->status !== 'waitlisted') {
                $dep = ActivityDeparture::whereKey($dep->id)->lockForUpdate()->first();
                if ($dep->status !== 'open') {
                    throw new RuntimeException('That departure is '.strtolower(ActivityDeparture::STATUSES[$dep->status]).'.');
                }
                $left = $dep->seatsLeft($b->id);
                if ($left !== null && $left < $adults + $children) {
                    throw new RuntimeException("Only {$left} seat(s) left on the ".$start->format('d M H:i').' departure.');
                }
            }
            $q = self::quote($a, $adults, $children, (float) $b->commission_pct);
            $repost = $b->folio_transaction_id && abs($q['gross'] - (float) $b->gross) > 0.004;
            if ($repost) {
                self::voidCharge($b, 'Activity changed');
            }
            $b->fill($q + ['activity_departure_id' => $dep?->id, 'date' => $start->toDateString(), 'time' => $time, 'adults' => $adults, 'children' => $children,
                'pickup_point' => array_key_exists('pickup_point', $d) ? ($d['pickup_point'] ?: null) : $b->pickup_point,
                'pickup_time' => array_key_exists('pickup_time', $d) ? ($d['pickup_time'] ?: null) : $b->pickup_time,
                'notes' => array_key_exists('notes', $d) ? ($d['notes'] ? mb_substr((string) $d['notes'], 0, 500) : null) : $b->notes,
                'phone' => array_key_exists('phone', $d) ? ($d['phone'] ?: null) : $b->phone])->save();
            if ($repost) {
                self::postCharge($b->fresh());
            }
            ActivityLogger::log('concierge', 'changed', "Activity {$b->number} changed: ".$start->format('d M H:i')." · {$b->paxLabel()} · ".money($b->gross), $b);
            if ($oldDep && $oldDep->id !== $dep?->id) {
                self::promoteWaitlist($oldDep);
            }
            if ($moved && $b->status === 'confirmed') {
                self::notify($b->fresh(), 'changed');
            }

            return $b->fresh();
        });
    }

    /** the vendor confirmed (or seats are held): charge the room and send the voucher */
    public static function confirm(ActivityBooking $b, ?string $vendorRef, ?User $by, bool $notify = true): ActivityBooking
    {
        if (! in_array($b->status, ['requested', 'waitlisted'], true)) {
            throw new RuntimeException("{$b->number} is {$b->statusLabel()}.");
        }
        if ($b->startsAt()->lt(now()->subHours(12))) {
            throw new RuntimeException("{$b->number} was for ".$b->startsAt()->format('d M H:i').' – it can no longer be confirmed.');
        }

        return DB::transaction(function () use ($b, $vendorRef, $by, $notify) {
            if ($b->status === 'waitlisted' && ($dep = $b->departure)) {
                $left = ActivityDeparture::whereKey($dep->id)->lockForUpdate()->first()->seatsLeft($b->id);
                if ($left !== null && $left < $b->pax()) {
                    throw new RuntimeException("The departure is still full ({$left} seat(s) left) – the guest stays on the waitlist.");
                }
            }
            $b->forceFill(['status' => 'confirmed', 'vendor_ref' => $vendorRef ? mb_substr($vendorRef, 0, 60) : $b->vendor_ref, 'confirmed_at' => now(), 'confirmed_by' => $by?->id])->save();
            self::postCharge($b);
            ActivityLogger::log('concierge', 'confirmed', "Activity {$b->number} confirmed".($b->vendor_ref ? " (vendor ref {$b->vendor_ref})" : ''), $b);
            if ($notify) {
                self::notify($b->fresh(), 'confirmed');
            }

            return $b->fresh();
        });
    }

    /** put a folio-collected booking on the room bill (once) */
    public static function postCharge(ActivityBooking $b, ?float $amount = null, string $prefix = ''): ?FolioTransaction
    {
        $amount ??= (float) $b->gross;
        if ($b->collection !== 'folio' || $amount <= 0 || $b->folio_transaction_id) {
            return null;
        }
        $stay = $b->stay;
        if (! $stay || ! in_array($stay->status, ['reserved', 'checked_in'], true)) {
            throw new RuntimeException("The stay for {$b->number} is closed – change it to \"Guest pays the vendor\".");
        }
        $folio = FolioService::openForStay($stay);
        if ($folio->status !== 'open') {
            throw new RuntimeException("Folio {$folio->folio_no} is closed.");
        }
        $b->loadMissing('activity');
        $text = $prefix.$b->activity->name.' – '.$b->date->format('d M').' '.$b->time.' ('.$b->paxLabel().')';
        $tx = FolioService::charge($folio, 'excursion', mb_substr($text, 0, 190), $amount, false, ['source_type' => 'concierge', 'source_id' => $b->id, 'reference' => $b->number]);
        $b->forceFill(['folio_id' => $folio->id, 'folio_transaction_id' => $tx->id])->save();

        return $tx;
    }

    protected static function voidCharge(ActivityBooking $b, string $reason): void
    {
        $tx = $b->folioTransaction;
        if ($tx && ! $tx->is_void) {
            if ($tx->folio?->status !== 'open') {
                throw new RuntimeException("The charge is on the settled bill {$tx->folio?->folio_no} – issue a credit note from Billing instead.");
            }
            FolioService::void($tx, "{$reason} {$b->number}");
        }
        $b->forceFill(['folio_id' => null, 'folio_transaction_id' => null])->save();
    }

    /** the fee a cancellation made now would cost (0 inside the free window, when waived, or when the guest pays the vendor) */
    public static function cancelFee(ActivityBooking $b): float
    {
        if ($b->status !== 'confirmed' || $b->collection !== 'folio' || now()->lte($b->freeCancelUntil())) {
            return 0.0;
        }

        return round((float) $b->gross * (float) ($b->activity?->cancel_fee_pct ?? 0) / 100, 2);
    }

    public static function cancel(ActivityBooking $b, string $reason, ?User $by, bool $waive = false, bool $notify = true): ActivityBooking
    {
        if (! $b->isOpen()) {
            throw new RuntimeException("{$b->number} is {$b->statusLabel()} and can't be cancelled.");
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for the cancellation.');
        }

        return DB::transaction(function () use ($b, $reason, $by, $waive, $notify) {
            $would = self::cancelFee($b);
            $fee = $waive ? 0.0 : $would;
            self::voidCharge($b, 'Activity cancelled');
            $pct = (float) $b->commission_pct;
            $commission = round($fee * $pct / 100, 2);
            $b->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 190), 'cancel_fee' => $fee,
                'commission' => $commission, 'vendor_net' => round($fee - $commission, 2)])->save();
            if ($fee > 0) {
                self::postCharge($b, $fee, 'Cancellation fee – ');
                self::recognise($b->fresh(), bdate()->toDateString());
            }
            ActivityLogger::log('concierge', 'cancelled', "Activity {$b->number} cancelled: {$reason}".($fee > 0 ? ' – fee '.money($fee) : ($waive && $would > 0 ? ' – fee of '.money($would).' waived' : '')), $b);
            if ($b->departure) {
                self::promoteWaitlist($b->departure);
            }
            if ($notify) {
                self::notify($b->fresh(), 'cancelled');
            }

            return $b->fresh();
        });
    }

    /** seats freed on a departure go to the waitlist in booking order */
    public static function promoteWaitlist(ActivityDeparture $dep): int
    {
        if ($dep->status !== 'open' || $dep->startsAt()->lte(now())) {
            return 0;
        }
        $n = 0;
        foreach (ActivityBooking::where('activity_departure_id', $dep->id)->where('status', 'waitlisted')->orderBy('id')->get() as $w) {
            $left = $dep->seatsLeft();
            if ($left !== null && $left < $w->pax()) {
                continue;
            }
            $w->forceFill(['status' => 'requested'])->save();
            ActivityLogger::log('concierge', 'promoted', "Activity {$w->number}: a seat came free – off the waitlist", $w);
            if ($dep->activity?->instant) {
                try {
                    self::confirm($w, null, null, false);
                } catch (RuntimeException $e) {
                    report($e);
                }
            }
            self::notify($w->fresh(), 'seat');
            $n++;
        }

        return $n;
    }

    public static function complete(ActivityBooking $b, ?User $by): ActivityBooking
    {
        if ($b->status !== 'confirmed') {
            throw new RuntimeException("{$b->number} is {$b->statusLabel()} – only confirmed bookings can be completed.");
        }
        if ($b->date->toDateString() > bdate()->toDateString()) {
            throw new RuntimeException("{$b->number} is on ".$b->date->format('d M Y').' – it can\'t be completed before it happens.');
        }

        return DB::transaction(function () use ($b) {
            $b->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            self::recognise($b);
            ActivityLogger::log('concierge', 'completed', "Activity {$b->number} completed – commission ".money($b->commission), $b);

            return $b->fresh();
        });
    }

    public static function noShow(ActivityBooking $b, string $reason, ?User $by): ActivityBooking
    {
        if ($b->status !== 'confirmed') {
            throw new RuntimeException("{$b->number} is {$b->statusLabel()}.");
        }
        if ($b->startsAt()->gt(now()->addMinutes(30)) && $b->date->toDateString() >= bdate()->toDateString()) {
            throw new RuntimeException('The activity hasn\'t started yet – cancel it instead.');
        }

        return DB::transaction(function () use ($b, $reason) {
            $upd = ['status' => 'no_show', 'completed_at' => now(), 'cancel_reason' => mb_substr($reason ?: 'Guest did not show', 0, 190)];
            if ($b->collection === 'vendor') {
                $upd += ['commission' => 0, 'vendor_net' => 0];   // the vendor collects nothing, so owes no commission
            }
            $b->forceFill($upd)->save();
            self::recognise($b->fresh());
            ActivityLogger::log('concierge', 'no_show', "Activity {$b->number} no-show".($b->collection === 'folio' ? ' – the charge stands' : ''), $b);

            return $b->fresh();
        });
    }

    /** commission earned: folio → Dr 2170 / Cr 4089; vendor-collected → Dr 1140 / Cr 4089 (once) */
    public static function recognise(ActivityBooking $b, ?string $date = null): void
    {
        if ($b->gl_journal_id || $b->recognised_on || (float) $b->commission <= 0) {
            return;
        }
        $date = LedgerService::openDate($date ?? min($b->date->toDateString(), bdate()->toDateString()));
        $b->loadMissing('activity', 'vendor');
        $dr = $b->collection === 'folio' ? AccountHead::sys('concierge_payable') : AccountHead::sys('concierge_receivable');
        $j = LedgerService::post(['date' => $date, 'reference' => $b->number, 'narration' => 'Concierge commission – '.$b->activity->name.' – '.$b->vendor->name,
            'lines' => [[$dr, (float) $b->commission, $b->vendor->name], [AccountHead::sys('rev_concierge'), -(float) $b->commission, $b->number]]], 'concierge', (string) $b->id);
        $b->forceFill(['recognised_on' => $date, 'gl_journal_id' => $j->id])->save();
    }

    /** vendor cancelled a departure (weather, too few guests): every booking on it is cancelled free of charge */
    public static function cancelDeparture(ActivityDeparture $dep, string $reason, ?User $by): int
    {
        if ($dep->status === 'cancelled') {
            throw new RuntimeException('That departure is already cancelled.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason – the guests will be told.');
        }

        return DB::transaction(function () use ($dep, $reason, $by) {
            $n = 0;
            $dep->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 190)])->save();
            foreach (ActivityBooking::where('activity_departure_id', $dep->id)->whereIn('status', ActivityBooking::OPEN)->get() as $b) {
                self::cancel($b, 'Departure cancelled: '.$reason, $by, true);
                $n++;
            }
            ActivityLogger::log('concierge', 'departure_cancelled', $dep->activity->name.' '.$dep->date->format('d M').' '.$dep->time." cancelled ({$reason}) – {$n} booking(s)", $dep);

            return $n;
        });
    }

    public static function setDepartureStatus(ActivityDeparture $dep, string $status, ?int $capacity = null, ?string $guide = null): ActivityDeparture
    {
        if ($dep->status === 'cancelled') {
            throw new RuntimeException('That departure is cancelled.');
        }
        if (! in_array($status, ['open', 'closed'], true)) {
            throw new RuntimeException('Unknown status.');
        }
        if ($capacity !== null && $capacity < $dep->booked()) {
            throw new RuntimeException('There are already '.$dep->booked().' guests booked – capacity can\'t go below that.');
        }
        $dep->forceFill(['status' => $status, 'capacity' => $capacity, 'guide' => $guide ? mb_substr($guide, 0, 100) : null])->save();
        if ($status === 'open') {
            self::promoteWaitlist($dep);
        }

        return $dep;
    }

    /** reservation cancelled / no-show: its open bookings go too (no fee) */
    public static function onReservationClosed(Reservation $r): int
    {
        $n = 0;
        foreach (ActivityBooking::where('reservation_id', $r->id)->whereIn('status', ActivityBooking::OPEN)->get() as $b) {
            try {
                self::cancel($b, 'Reservation '.str_replace('_', '-', $r->status), null, true);
                $n++;
            } catch (RuntimeException $e) {
                report($e);
            }
        }

        return $n;
    }

    /** room-bill bookings of a stay not yet on the bill (requested / waitlisted) – they block check-out */
    public static function pendingForStay(int $stayId): Collection
    {
        return ActivityBooking::where('reservation_room_id', $stayId)->where('collection', 'folio')->whereIn('status', ['requested', 'waitlisted'])->orderBy('id')->get();
    }

    /** night audit: confirmed bookings of the closed day are completed; unconfirmed requests and waitlists for it lapse */
    public static function nightly(string $closedDate): array
    {
        $out = ['completed' => 0, 'lapsed' => 0];
        if (self::cfg()['auto_complete'] === '1') {
            foreach (ActivityBooking::where('status', 'confirmed')->whereDate('date', '<=', $closedDate)->orderBy('id')->get() as $b) {
                try {
                    self::complete($b, null);
                    $out['completed']++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
        foreach (ActivityBooking::whereIn('status', ['requested', 'waitlisted'])->whereDate('date', '<=', $closedDate)->orderBy('id')->get() as $b) {
            try {
                self::cancel($b, $b->status === 'waitlisted' ? 'No seat came free' : 'Not confirmed by the vendor in time', null, true);
                $out['lapsed']++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $out;
    }

    // ───────────────────────── settlements ─────────────────────────

    /** bookings that are due for settlement with a vendor */
    public static function unsettled(?int $vendorId = null, ?string $upTo = null): Collection
    {
        return ActivityBooking::with('activity', 'vendor')->whereNull('activity_settlement_id')->whereIn('status', ['completed', 'no_show', 'cancelled'])
            ->where(fn ($q) => $q->where('vendor_net', '!=', 0)->orWhere('commission', '!=', 0))->when($vendorId, fn ($q) => $q->where('activity_vendor_id', $vendorId))->orderBy('date')->orderBy('id')->get()->filter(fn ($b) => ($b->status === 'completed' || $b->status === 'no_show' || ($b->status === 'cancelled' && (float) $b->cancel_fee > 0))
                && (! $vendorId || $b->activity_vendor_id === $vendorId) && (! $upTo || $b->date->toDateString() <= $upTo)
                && ((float) $b->vendor_net != 0 || (float) $b->commission != 0))
            ->values();
    }

    /** per vendor: [vendor, bookings, payable (hotel owes), receivable (vendor owes), net] */
    public static function balances(?string $upTo = null): Collection
    {
        $vendors = ActivityVendor::orderBy('name')->get()->keyBy('id');

        return self::unsettled(null, $upTo)->groupBy('activity_vendor_id')->map(function ($rows, $vid) use ($vendors) {
            $payable = round($rows->where('collection', 'folio')->sum(fn ($b) => (float) $b->vendor_net), 2);
            $receivable = round($rows->where('collection', 'vendor')->sum(fn ($b) => (float) $b->commission), 2);

            return ['vendor' => $vendors[$vid] ?? null, 'bookings' => $rows->count(), 'payable' => $payable, 'receivable' => $receivable, 'net' => round($payable - $receivable, 2),
                'oldest' => $rows->min(fn ($b) => $b->date->toDateString())];
        })->filter(fn ($r) => $r['vendor'])->sortBy(fn ($r) => $r['vendor']->name)->values();
    }

    public static function payAccounts(): Collection
    {
        return TreasuryAccount::with('head')->where('is_active', true)->whereIn('kind', ['bank', 'momo', 'cash'])->orderBy('kind')->orderBy('name')->get();
    }

    public static function settle(ActivityVendor $v, string $upTo, int $treasuryId, string $date, ?string $ref, User $by): ActivitySettlement
    {
        if ($date > bdate()->toDateString()) {
            throw new RuntimeException('The settlement date cannot be after the business date.');
        }
        if (($lock = LedgerService::lockDate()) && $date <= $lock) {
            throw new RuntimeException('The general ledger is locked up to '.dmy($lock).' – choose a later date.');
        }
        if ($upTo > $date) {
            throw new RuntimeException('Settle bookings up to the settlement date at the latest.');
        }
        $rows = self::unsettled($v->id, $upTo);
        if ($rows->isEmpty()) {
            throw new RuntimeException('Nothing to settle with '.$v->name.' up to '.dmy($upTo).'.');
        }
        $acc = self::payAccounts()->firstWhere('id', $treasuryId);
        $payable = round($rows->where('collection', 'folio')->sum(fn ($b) => (float) $b->vendor_net), 2);
        $receivable = round($rows->where('collection', 'vendor')->sum(fn ($b) => (float) $b->commission), 2);
        $net = round($payable - $receivable, 2);
        if (abs($net) > 0.004 && ! $acc) {
            throw new RuntimeException('Choose the bank, Mobile Money or cash account the money '.($net > 0 ? 'is paid from' : 'is received into').'.');
        }

        return DB::transaction(function () use ($v, $upTo, $acc, $date, $ref, $by, $rows, $payable, $receivable, $net) {
            $s = ActivitySettlement::create(['number' => Numbering::next('activity_settlement'), 'activity_vendor_id' => $v->id, 'date' => $date, 'up_to' => $upTo, 'bookings' => $rows->count(),
                'payable' => $payable, 'receivable' => $receivable, 'net' => $net, 'account_head_id' => abs($net) > 0.004 ? $acc->account_head_id : null,
                'reference' => $ref ? mb_substr($ref, 0, 80) : null, 'status' => 'posted', 'created_by' => $by->id]);
            $lines = [];
            if ($payable) {
                $lines[] = [AccountHead::sys('concierge_payable'), $payable, $v->name];
            }
            if ($receivable) {
                $lines[] = [AccountHead::sys('concierge_receivable'), -$receivable, $v->name];
            }
            if (abs($net) > 0.004) {
                $lines[] = [$acc->account_head_id, -$net, $s->number];
            }
            $j = LedgerService::post(['date' => $date, 'reference' => $ref ?: $s->number, 'narration' => 'Activity vendor settlement '.$s->number.' – '.$v->name, 'lines' => $lines], 'concierge_settle', (string) $s->id);
            $s->forceFill(['gl_journal_id' => $j->id])->save();
            ActivityBooking::whereIn('id', $rows->pluck('id'))->update(['activity_settlement_id' => $s->id]);
            ActivityLogger::log('concierge', 'settled', "Settlement {$s->number} with {$v->name}: payable ".money($payable).', commission due '.money($receivable).' – '.$s->direction().' '.money(abs($net)), $s);

            return $s;
        });
    }

    public static function voidSettlement(ActivitySettlement $s, string $reason, User $by): ActivitySettlement
    {
        if ($s->status !== 'posted') {
            throw new RuntimeException("{$s->number} is already void.");
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for voiding the settlement.');
        }
        if (ActivitySettlement::where('activity_vendor_id', $s->activity_vendor_id)->where('status', 'posted')->where('id', '>', $s->id)->exists()) {
            throw new RuntimeException('Void the later settlements with this vendor first.');
        }

        return DB::transaction(function () use ($s, $reason) {
            if ($s->journal && ! $s->journal->reversed_by_id) {
                LedgerService::reverse($s->journal, bdate()->toDateString(), 'Settlement voided: '.$reason);
            }
            ActivityBooking::where('activity_settlement_id', $s->id)->update(['activity_settlement_id' => null]);
            $s->forceFill(['status' => 'void', 'void_reason' => mb_substr($reason, 0, 190)])->save();
            ActivityLogger::log('concierge', 'settlement_void', "Settlement {$s->number} voided: {$reason}", $s);

            return $s;
        });
    }

    // ───────────────────────── sub-ledger controls ─────────────────────────

    /** 2170: activity charges on room bills (posted days) − commission moved to income − payments to vendors */
    public static function payableBalance(?string $asOf = null): float
    {
        $asOf ??= LedgerService::cutoff();
        $posted = min($asOf, LedgerService::cutoff());
        $charges = (float) FolioTransaction::where('source_type', 'concierge')->where('is_void', false)->where('type', 'charge')->whereDate('business_date', '<=', $posted)->sum('amount');
        $recognised = (float) ActivityBooking::where('collection', 'folio')->whereNotNull('recognised_on')->whereDate('recognised_on', '<=', $asOf)->sum('commission');
        $paid = (float) ActivitySettlement::where('status', 'posted')->whereDate('date', '<=', $asOf)->sum('payable');

        return round($charges - $recognised - $paid, 2);
    }

    /** 1140: commission earned on vendor-collected bookings − collected at settlement */
    public static function receivableBalance(?string $asOf = null): float
    {
        $asOf ??= LedgerService::cutoff();
        $earned = (float) ActivityBooking::where('collection', 'vendor')->whereNotNull('recognised_on')->whereDate('recognised_on', '<=', $asOf)->sum('commission');
        $got = (float) ActivitySettlement::where('status', 'posted')->whereDate('date', '<=', $asOf)->sum('receivable');

        return round($earned - $got, 2);
    }

    // ───────────────────────── board, attention & messages ─────────────────────────

    /** things the desk should act on: requests waiting too long for the vendor, departures at risk (below minimum), waitlists */
    public static function alerts(): array
    {
        return self::$memo['alerts'] ??= (function () {
            $c = self::cfg();
            $slow = ActivityBooking::where('status', 'requested')->where('created_at', '<', now()->subHours($c['confirm_hours']))->whereDate('date', '>=', bdate()->toDateString())->count();
            $soon = ActivityBooking::where('status', 'requested')->whereDate('date', '<=', bdate()->copy()->addDay()->toDateString())->whereDate('date', '>=', bdate()->toDateString())->count();
            $wait = ActivityBooking::where('status', 'waitlisted')->whereDate('date', '>=', bdate()->toDateString())->count();
            $risk = 0;
            foreach (ActivityDeparture::with('activity')->where('status', 'open')->whereDate('date', '>=', bdate()->toDateString())->whereDate('date', '<=', bdate()->copy()->addDays(2)->toDateString())->get() as $d) {
                $n = $d->booked();
                if ($n > 0 && $n < (int) $d->activity->min_pax) {
                    $risk++;
                }
            }

            return ['slow' => $slow, 'soon' => $soon, 'waitlisted' => $wait, 'at_risk' => $risk];
        })();
    }

    public static function attention(): int
    {
        $a = self::alerts();

        return $a['slow'] + $a['at_risk'] + max(0, $a['soon'] - $a['slow']);
    }

    /** the day's departures (with bookings) and on-request bookings for the concierge board */
    public static function board(Carbon $date): array
    {
        $bookings = ActivityBooking::with('activity.vendor', 'departure')->whereDate('date', $date->toDateString())->where('status', '!=', 'cancelled')->orderBy('time')->get();
        $deps = ActivityDeparture::with('activity.vendor')->whereDate('date', $date->toDateString())->orderBy('time')->get();
        $rows = [];
        foreach ($deps as $d) {
            $list = $bookings->where('activity_departure_id', $d->id);
            $rows[] = ['key' => 'd'.$d->id, 'time' => $d->time, 'activity' => $d->activity, 'departure' => $d, 'bookings' => $list->values(),
                'pax' => $list->whereIn('status', ActivityBooking::HOLDING)->sum(fn ($b) => $b->pax()), 'capacity' => $d->capacity,
                'at_risk' => $d->status === 'open' && $list->isNotEmpty() && $list->whereIn('status', ActivityBooking::HOLDING)->sum(fn ($b) => $b->pax()) < $d->activity->min_pax];
        }
        foreach ($bookings->whereNull('activity_departure_id') as $b) {
            $rows[] = ['key' => 'b'.$b->id, 'time' => $b->time, 'activity' => $b->activity, 'departure' => null, 'bookings' => collect([$b]), 'pax' => $b->pax(), 'capacity' => null, 'at_risk' => false];
        }
        usort($rows, fn ($x, $y) => [$x['time'], $x['activity']->name] <=> [$y['time'], $y['activity']->name]);

        return $rows;
    }

    public static function monthStats(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();
        $rows = ActivityBooking::whereBetween('date', [$from, $to])->get();
        $sold = $rows->whereIn('status', ['confirmed', 'completed', 'no_show']);

        return ['bookings' => $sold->count(), 'pax' => $sold->sum(fn ($b) => $b->pax()), 'gross' => round($sold->sum(fn ($b) => (float) $b->gross) + $rows->where('status', 'cancelled')->sum(fn ($b) => (float) $b->cancel_fee), 2),
            'commission' => round($rows->whereIn('status', ['completed', 'no_show', 'cancelled'])->whereNotNull('recognised_on')->sum(fn ($b) => (float) $b->commission), 2),
            'pipeline' => round($rows->where('status', 'confirmed')->sum(fn ($b) => (float) $b->commission), 2),
            'cancelled' => $rows->where('status', 'cancelled')->count()];
    }

    /** guest message. Kinds: received, confirmed, waitlisted, seat, changed, cancelled, voucher */
    public static function notify(ActivityBooking $b, string $kind, bool $force = false): bool
    {
        if (! $force && self::cfg()['notify'] !== '1') {
            return false;
        }
        $h = hotel();
        $b->loadMissing('activity.vendor');
        $first = explode(' ', trim(preg_replace('/^(Mr|Mrs|Ms|Miss|Dr|Prof)\.?\s+/i', '', $b->guest_name)))[0] ?: $b->guest_name;
        $when = $b->date->format('D d M').' at '.$b->time;
        $act = $b->activity->name;
        $pay = $b->collection === 'vendor' ? ' Please pay '.money($b->gross).' to '.$b->activity->vendor->name.' on the day.' : ' Charged to your room.';
        $pick = $b->pickup_point ? ' Pick-up: '.$b->pickup_point.($b->pickup_time ? ' at '.$b->pickup_time : '').'.' : ($b->activity->meeting_point ? ' Meet at: '.$b->activity->meeting_point.'.' : '');
        $text = match ($kind) {
            'received' => "Dear {$first}, we have your request for {$act} on {$when} ({$b->paxLabel()}), ref {$b->number}. We'll confirm with the operator shortly. – {$h->name} Concierge",
            'confirmed', 'voucher' => "Dear {$first}, {$act} on {$when} is confirmed for {$b->paxLabel()} (ref {$b->number}).{$pick}{$pay} Voucher: ".route('concierge.voucher.public', $b->voucher_token)." – {$h->name} Concierge",
            'waitlisted' => "Dear {$first}, {$act} on {$when} is full, so you are on the waitlist (ref {$b->number}). We'll tell you as soon as a seat comes free. – {$h->name} Concierge",
            'seat' => "Good news {$first} – a seat came free on {$act} on {$when} (ref {$b->number}).".($b->status === 'confirmed' ? ' You are confirmed.' : ' We are confirming with the operator.')." – {$h->name} Concierge",
            'changed' => "Dear {$first}, your booking {$b->number} is now {$act} on {$when} for {$b->paxLabel()}.{$pick} – {$h->name} Concierge",
            'cancelled' => "Dear {$first}, your booking {$b->number} ({$act}, {$when}) has been cancelled".((float) $b->cancel_fee > 0 ? '; a cancellation fee of '.money($b->cancel_fee).' applies' : '').". – {$h->name} Concierge",
            default => "{$b->number}: {$act} on {$when}",
        };
        $log = $b->phone ? MessageService::send($b->phone, $text, MessageService::defaultChannel(), 'concierge_'.$kind, $b)
            : ($b->email ? MailService::send($b->email, new GuestMessage($kind === 'cancelled' ? 'Booking cancelled – '.$b->number : $act.' – '.$b->date->format('d M'), $text, $h), 'concierge_'.$kind, $b) : null);
        if ($log && $log->status === 'sent') {
            $b->forceFill(['guest_notified_at' => now()])->saveQuietly();

            return true;
        }

        return false;
    }

    // ───────────────────────── guest portal ─────────────────────────

    public static function portalOn(): bool
    {
        return self::cfg()['portal'] === '1' && Activity::where('is_active', true)->where('portal_visible', true)->exists();
    }

    public static function portalActivities(): Collection
    {
        return Activity::with('vendor')->where('is_active', true)->where('portal_visible', true)->whereHas('vendor', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get()->reject(fn ($a) => $a->vendor->licenceExpired())->values();
    }

    public static function portalBook(ReservationRoom $stay, array $d): ActivityBooking
    {
        if (! in_array($stay->status, ['reserved', 'checked_in'], true)) {
            throw new RuntimeException('Bookings are only possible before and during your stay.');
        }
        $a = self::portalActivities()->firstWhere('id', (int) ($d['activity_id'] ?? 0)) ?? throw new RuntimeException('That activity is not available.');
        if (ActivityBooking::where('reservation_room_id', $stay->id)->whereIn('status', ActivityBooking::OPEN)->count() >= 6) {
            throw new RuntimeException('You already have several activities booked – please see the concierge for more.');
        }
        $date = (string) ($d['date'] ?? '');
        if ($date < $stay->arrival_date->toDateString() || $date > $stay->departure_date->toDateString()) {
            throw new RuntimeException('Please choose a date during your stay ('.$stay->arrival_date->format('d M').' – '.$stay->departure_date->format('d M').').');
        }

        return self::book([
            'activity_id' => $a->id, 'date' => $date, 'time' => $d['time'] ?? null, 'adults' => (int) ($d['adults'] ?? 1), 'children' => (int) ($d['children'] ?? 0),
            'reservation_room_id' => $stay->id, 'collection' => $a->collectionMode(), 'notes' => $d['notes'] ?? null, 'phone' => ($d['phone'] ?? null) ?: null,
            'waitlist' => ! empty($d['waitlist']),
        ], null, 'portal');
    }

    public static function portalCancel(ReservationRoom $stay, ActivityBooking $b): ActivityBooking
    {
        if ($b->reservation_room_id !== $stay->id) {
            throw new RuntimeException('That booking was not found.');
        }
        if (! $b->isOpen()) {
            throw new RuntimeException('That booking can no longer be cancelled.');
        }
        if (self::cancelFee($b) > 0) {
            throw new RuntimeException('Free cancellation ended at '.$b->freeCancelUntil()->format('d M H:i').' – please speak to the concierge.');
        }

        return self::cancel($b, 'Cancelled by the guest on the portal', null, true);
    }
}
