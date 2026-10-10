<?php

namespace App\Services;

use App\Mail\GuestMessage;
use App\Models\Guest;
use App\Models\Outlet;
use App\Models\PosOrder;
use App\Models\PosTable;
use App\Models\ReservationRoom;
use App\Models\TableReservation;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v3.2 restaurant table reservations: service periods and time slots, automatic table assignment (best single table,
 * otherwise 2-3 joined tables in one section), the booking lifecycle, seating into a POS order, the waitlist with wait
 * estimates, the live floor state and the scheduled runner (reminders, automatic no-shows, end-of-day tidy-up).
 */
class TableBookingService
{
    public const DEFAULTS = [
        'slot_minutes' => 15,          // booking interval inside a service period
        'durations' => ['2' => 90, '4' => 105, '6' => 120, '99' => 150], // party size up to → minutes at the table
        'buffer' => 10,                // minutes to reset a table between parties
        'hold_minutes' => 15,          // a late party keeps its table this long, then it is a no-show
        'auto_no_show' => true,
        'min_notice' => 60,            // online bookings: minutes' notice
        'days_ahead' => 60,            // online bookings: how far ahead
        'max_online_party' => 8,       // bigger parties are asked to call
        'pacing_covers' => 0,          // max covers starting in one slot online (0 = no limit) – protects the kitchen
        'auto_confirm_online' => true, // otherwise online bookings wait for a host to confirm
        'reminder_hours' => 3,         // reminder this many hours before (0 = off)
        'noshow_flag' => 2,            // warn staff when a phone number has this many no-shows
        'online_note' => '',           // shown on the public booking page
    ];

    public const STATUS_LABELS = ['booked' => 'Awaiting confirmation', 'confirmed' => 'Confirmed', 'seated' => 'Seated', 'completed' => 'Completed',
        'cancelled' => 'Cancelled', 'no_show' => 'No-show', 'waiting' => 'Waiting', 'notified' => 'Table ready – called', 'left' => 'Left'];

    // ------------------------------------------------------------------ configuration

    public static function cfg(Outlet $o): array
    {
        $c = array_merge(self::DEFAULTS, array_intersect_key($o->tb_settings ?? [], self::DEFAULTS));
        $d = collect((array) $c['durations'])->mapWithKeys(fn ($m, $p) => [(int) $p => max(15, (int) $m)])->filter(fn ($m, $p) => $p > 0)->sortKeys();
        $c['durations'] = $d->isEmpty() ? self::DEFAULTS['durations'] : $d->all();

        return $c;
    }

    public static function durationFor(Outlet $o, int $party): int
    {
        $d = self::cfg($o)['durations'];
        foreach ($d as $upTo => $min) {
            if ($party <= $upTo) {
                return $min;
            }
        }

        return (int) end($d);
    }

    public static function enabledOutlets(bool $onlineOnly = false): Collection
    {
        return Outlet::where('is_active', true)->where('tb_enabled', true)->when($onlineOnly, fn ($q) => $q->where('tb_online', true))->orderBy('id')->get();
    }

    public static function periodsOn(Outlet $o, Carbon $date): Collection
    {
        return $o->periods()->where('is_active', true)->get()->filter(fn ($p) => $p->opensOn($date->isoWeekday()))->values();
    }

    /** Bookable start times ('H:i') on a date, from the outlet's service periods. */
    public static function slots(Outlet $o, Carbon $date): array
    {
        $step = max(5, (int) self::cfg($o)['slot_minutes']);
        $out = [];
        foreach (self::periodsOn($o, $date) as $p) {
            $t = $date->copy()->setTimeFromTimeString($p->start_time);
            $last = $date->copy()->setTimeFromTimeString($p->end_time);
            for ($i = 0; $t->lte($last) && $i < 200; $i++, $t->addMinutes($step)) {
                $out[$t->format('H:i')] = $p->name;
            }
        }
        ksort($out);

        return $out;
    }

    // ------------------------------------------------------------------ table occupancy

    /** When a holding reservation stops occupying its tables (seated parties that overrun keep them until they leave). */
    protected static function effectiveEnd(TableReservation $r): Carbon
    {
        return $r->status === 'seated' ? $r->end_at->copy()->max(now()->addMinutes(10)) : $r->end_at->copy();
    }

    /**
     * Tables that are not free for [start, end): holding reservations (plus the reset buffer), running POS orders
     * that don't belong to a reservation, and blocked tables. Returns table id => reason.
     */
    public static function busy(Outlet $o, Carbon $start, Carbon $end, ?int $excludeId = null): array
    {
        $buf = (int) self::cfg($o)['buffer'];
        $busy = [];
        $rows = TableReservation::with('tables:pos_tables.id')->where('outlet_id', $o->id)->whereIn('status', TableReservation::HOLDING)
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->where('start_at', '<', $end->copy()->addMinutes($buf))->where('end_at', '>', $start->copy()->subDay())->get();
        $linkedOrders = [];
        foreach ($rows as $r) {
            if ($r->pos_order_id) {
                $linkedOrders[] = $r->pos_order_id;
            }
            if ($r->start_at->lt($end->copy()->addMinutes($buf)) && self::effectiveEnd($r)->addMinutes($buf)->gt($start)) {
                foreach ($r->tables as $t) {
                    $busy[$t->id] ??= $r->name.' ('.$r->party_size.') '.$r->start_at->format('H:i').'–'.self::effectiveEnd($r)->format('H:i');
                }
            }
        }
        if ($excludeId && ($own = TableReservation::whereKey($excludeId)->value('pos_order_id'))) {
            $linkedOrders[] = $own;
        }
        $orders = PosOrder::where('outlet_id', $o->id)->whereIn('status', ['open', 'billed'])->whereNotNull('pos_table_id')
            ->whereNotIn('id', $linkedOrders ?: [0])->get();
        foreach ($orders as $ord) {
            $until = $ord->created_at->copy()->addMinutes(self::durationFor($o, max(1, (int) $ord->covers)))->max(now()->addMinutes(10));
            if ($ord->created_at->lt($end) && $until->copy()->addMinutes($buf)->gt($start)) {
                $busy[$ord->pos_table_id] ??= 'Running order '.$ord->order_no.' (until about '.$until->format('H:i').')';
            }
        }
        foreach (PosTable::where('outlet_id', $o->id)->whereNotNull('blocked_reason')->get() as $t) {
            $busy[$t->id] = 'Blocked: '.$t->blocked_reason;
        }

        return $busy;
    }

    /** Is the table physically taken right now (running order or seated party)? Returns the reason or null. */
    public static function occupiedNow(PosTable $t, ?int $excludeReservationId = null, ?int $excludeOrderId = null): ?string
    {
        if ($t->blocked_reason) {
            return 'blocked ('.$t->blocked_reason.')';
        }
        $seated = TableReservation::where('status', 'seated')->when($excludeReservationId, fn ($q) => $q->whereKeyNot($excludeReservationId))
            ->whereHas('tables', fn ($q) => $q->where('pos_tables.id', $t->id))->first();
        if ($seated) {
            return 'occupied by '.$seated->name.' ('.$seated->party_size.')';
        }
        $ord = PosOrder::where('pos_table_id', $t->id)->whereIn('status', ['open', 'billed'])->when($excludeOrderId, fn ($q) => $q->whereKeyNot($excludeOrderId))->first();

        return $ord ? 'running order '.$ord->order_no : null;
    }

    /**
     * Best tables for a party: the smallest single table that fits (and isn't too big for the party), otherwise the
     * tightest combination of 2-3 combinable tables in one section. Online bookings only use reservable tables.
     */
    public static function candidates(Outlet $o, int $party, Carbon $start, Carbon $end, ?int $excludeId = null, bool $online = false, ?string $section = null, bool $strictMin = true): array
    {
        $busy = self::busy($o, $start, $end, $excludeId);
        $free = PosTable::where('outlet_id', $o->id)->where('is_active', true)->whereNull('blocked_reason')
            ->when($online, fn ($q) => $q->where('reservable', true))->orderBy('id')->get()
            ->reject(fn ($t) => isset($busy[$t->id]))->values();
        $miss = fn ($t) => $section && $t->section !== $section ? 1 : 0;
        $single = $free->filter(fn ($t) => $t->capacity >= $party && (! $strictMin || $t->min_capacity <= $party))
            ->sortBy(fn ($t) => (($miss($t) * 2 + ($t->reservable ? 0 : 1)) * 1000 + $t->capacity) * 10000 + $t->id)->first();
        if ($single) {
            return [$single];
        }
        // a bigger table than the minimum party allows: staff may use it (online guests may not – it's kept for bigger groups)
        $roomy = $free->filter(fn ($t) => $t->capacity >= $party)
            ->sortBy(fn ($t) => (($miss($t) * 2 + ($t->reservable ? 0 : 1)) * 1000 + $t->capacity) * 10000 + $t->id)->first();
        if ($roomy) {
            return $online ? [] : [$roomy];
        }
        // nobody's single table is big enough → join neighbouring combinable tables of one section
        $best = null;
        $bestKey = null;
        foreach ($free->where('combinable', true)->groupBy(fn ($t) => (string) $t->section) as $group) {
            $g = $group->values()->all();
            $n = count($g);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $sets = [[$g[$i], $g[$j]]];
                    for ($k = $j + 1; $k < $n; $k++) {
                        $sets[] = [$g[$i], $g[$j], $g[$k]];
                    }
                    foreach ($sets as $set) {
                        $cap = array_sum(array_map(fn ($t) => $t->capacity, $set));
                        if ($cap < $party || $cap - min(array_map(fn ($t) => $t->capacity, $set)) >= $party) {
                            continue;   // too small, or one of the tables isn't needed
                        }
                        $key = [$miss($set[0]), count(array_filter($set, fn ($t) => ! $t->reservable)), count($set), $cap - $party, $set[0]->id];
                        if ($bestKey === null || $key < $bestKey) {
                            [$best, $bestKey] = [$set, $key];
                        }
                    }
                }
            }
        }

        return $best ?? [];
    }

    protected static function coversStarting(Outlet $o, Carbon $start, int $slot, ?int $excludeId = null): int
    {
        return (int) TableReservation::where('outlet_id', $o->id)->whereIn('status', TableReservation::HOLDING)
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->where('start_at', '>=', $start)->where('start_at', '<', $start->copy()->addMinutes($slot))->sum('party_size');
    }

    /**
     * Every slot on a date with whether it can be booked for this party: [time => [period, ok, reason, tables]].
     * Reasons: past, notice, pacing, full.
     */
    public static function availability(Outlet $o, string $date, int $party, ?int $excludeId = null, bool $online = false): array
    {
        $c = self::cfg($o);
        $day = Carbon::parse($date)->startOfDay();
        $dur = self::durationFor($o, $party);
        $out = [];
        foreach (self::slots($o, $day) as $time => $period) {
            $start = $day->copy()->setTimeFromTimeString($time);
            $end = $start->copy()->addMinutes($dur);
            $reason = null;
            $tables = [];
            if ($start->lt(now())) {
                $reason = 'past';
            } elseif ($online && $start->lt(now()->addMinutes((int) $c['min_notice']))) {
                $reason = 'notice';
            } elseif ($online && $c['pacing_covers'] > 0 && self::coversStarting($o, $start, (int) $c['slot_minutes'], $excludeId) + $party > $c['pacing_covers']) {
                $reason = 'pacing';
            } else {
                $tables = self::candidates($o, $party, $start, $end, $excludeId, $online);
                $reason = $tables ? null : 'full';
            }
            $out[$time] = ['period' => $period, 'ok' => $reason === null, 'reason' => $reason, 'tables' => array_map(fn ($t) => $t->table_no, $tables)];
        }

        return $out;
    }

    /** Explicit table choice (staff) or automatic assignment; throws with a helpful message when nothing fits. */
    protected static function resolveTables(Outlet $o, int $party, Carbon $start, Carbon $end, array $ids, ?int $excludeId, bool $online, ?string $section): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            $tables = self::candidates($o, $party, $start, $end, $excludeId, $online, $section);
            if (! $tables) {
                throw ValidationException::withMessages(['time' => "No table for {$party} is free at ".$start->format('H:i').' on '.$start->format('d M').'. Try another time'.($online ? '.' : ' or add the party to the waitlist.')]);
            }

            return $tables;
        }
        $tables = PosTable::where('outlet_id', $o->id)->where('is_active', true)->whereIn('id', $ids)->orderBy('id')->get();
        if ($tables->count() !== count($ids)) {
            throw ValidationException::withMessages(['table_ids' => 'Choose active tables of this outlet.']);
        }
        $busy = self::busy($o, $start, $end, $excludeId);
        foreach ($tables as $t) {
            if (isset($busy[$t->id])) {
                throw ValidationException::withMessages(['table_ids' => "Table {$t->table_no} is not free: {$busy[$t->id]}."]);
            }
        }
        if (($cap = $tables->sum('capacity')) < $party) {
            throw ValidationException::withMessages(['table_ids' => 'Table '.$tables->pluck('table_no')->implode(' + ')." seats {$cap} – the party is {$party}. Add another table."]);
        }

        return $tables->all();
    }

    // ------------------------------------------------------------------ guest matching & history

    /** Link to a guest profile only when exactly one unrestricted profile has this phone / e-mail. */
    public static function matchGuest(?string $phone, ?string $email): ?Guest
    {
        if (! $phone && ! $email) {
            return null;
        }
        $norm = MessageService::normalise($phone);
        $rows = Guest::whereNull('privacy_status')->where(function ($q) use ($phone, $norm, $email) {
            if ($phone) {
                $q->orWhere('phone', $phone);
            }
            if ($norm) {   // guest profiles keep the number as typed ("+91 98450 12345")
                $q->orWhere('phone', $norm)->orWhereRaw("REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', '') = ?", [$norm]);
            }
            if ($email) {
                $q->orWhere('email', $email);
            }
        })->limit(2)->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }

    /** Visits / no-shows / cancellations for a phone number or guest profile. */
    public static function history(?string $phone, ?int $guestId = null, ?int $excludeId = null): array
    {
        if (! $phone && ! $guestId) {
            return ['visits' => 0, 'no_shows' => 0, 'cancelled' => 0, 'last' => null];
        }
        $phone = $phone ? (MessageService::normalise($phone) ?? $phone) : null;
        $q = TableReservation::where(function ($w) use ($phone, $guestId) {
            if ($phone) {
                $w->orWhere('phone', $phone);
            }
            if ($guestId) {
                $w->orWhere('guest_id', $guestId);
            }
        })->when($excludeId, fn ($x) => $x->whereKeyNot($excludeId));

        return ['visits' => (clone $q)->where('status', 'completed')->count(), 'no_shows' => (clone $q)->where('status', 'no_show')->count(),
            'cancelled' => (clone $q)->where('status', 'cancelled')->count(), 'last' => (clone $q)->where('status', 'completed')->max('date')];
    }

    public static function flagged(TableReservation $r): bool
    {
        $flag = (int) self::cfg($r->outlet)['noshow_flag'];

        return $flag > 0 && self::history($r->phone, $r->guest_id, $r->id)['no_shows'] >= $flag;
    }

    // ------------------------------------------------------------------ booking lifecycle

    public static function tokenFor(TableReservation $r): string
    {
        return substr(hash_hmac('sha256', 'tres|'.$r->id.'|'.$r->booking_no.'|'.$r->created_at->timestamp, (string) config('app.key')), 0, 40);
    }

    public static function findByToken(string $token): ?TableReservation
    {
        if (! preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }

        return TableReservation::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();
    }

    protected static function assertOutlet(Outlet $o): void
    {
        if (! $o->tb_enabled || ! $o->is_active) {
            throw ValidationException::withMessages(['outlet_id' => "Table reservations are not switched on for {$o->name}."]);
        }
    }

    /**
     * Create a booking. $d: date, time, party_size, name, phone, email, guest_id, reservation_room_id, occasion, tags,
     * section_pref, notes, table_ids[], duration. $source: phone, email, walkin, online, concierge, staff.
     */
    public static function book(Outlet $o, array $d, string $source = 'phone'): TableReservation
    {
        self::assertOutlet($o);
        $c = self::cfg($o);
        $online = $source === 'online';
        $party = (int) $d['party_size'];
        $start = Carbon::parse($d['date'].' '.$d['time']);
        if ($start->lt(now()->subMinutes(5))) {
            throw ValidationException::withMessages(['time' => 'That time has already passed.']);
        }
        if ($online) {
            if ($party > $c['max_online_party']) {
                throw ValidationException::withMessages(['party_size' => "For parties of more than {$c['max_online_party']} please call us – we'll arrange the tables."]);
            }
            if ($start->gt(now()->addDays((int) $c['days_ahead'])->endOfDay())) {
                throw ValidationException::withMessages(['date' => "Bookings open {$c['days_ahead']} days ahead."]);
            }
            $slot = self::availability($o, $start->toDateString(), $party, null, true)[$start->format('H:i')] ?? null;
            if (! $slot || ! $slot['ok']) {
                $why = ['past' => 'That time has already passed.', 'notice' => "Online bookings need {$c['min_notice']} minutes' notice – please call us.",
                    'pacing' => 'That time is fully booked. Please pick another time.', 'full' => 'Sorry, no table is free at that time. Please pick another time.'];
                throw ValidationException::withMessages(['time' => $slot ? $why[$slot['reason']] : 'Please choose one of the available times.']);
            }
        }
        $dur = (int) (($d['duration'] ?? null) ?: self::durationFor($o, $party));
        $end = $start->copy()->addMinutes($dur);
        $tables = self::resolveTables($o, $party, $start, $end, (array) ($d['table_ids'] ?? []), null, $online, $d['section_pref'] ?? null);

        $guest = null;
        $stayId = null;
        if (! empty($d['reservation_room_id'])) {
            $stay = ReservationRoom::with('reservation.guest')->findOrFail($d['reservation_room_id']);
            if (! in_array($stay->status, ['checked_in', 'reserved'], true)) {
                throw ValidationException::withMessages(['reservation_room_id' => 'That stay is not in-house.']);
            }
            $stayId = $stay->id;
            $guest = $stay->reservation?->guest;
        } elseif (! empty($d['guest_id'])) {
            $guest = Guest::findOrFail($d['guest_id']);
        } else {
            $guest = self::matchGuest($d['phone'] ?? null, $d['email'] ?? null);
        }
        if ($guest && $guest->privacy_status) {
            $guest = null;
        }
        $status = $online && ! $c['auto_confirm_online'] ? 'booked' : 'confirmed';

        $r = DB::transaction(function () use ($o, $d, $source, $party, $start, $end, $dur, $tables, $guest, $stayId, $status) {
            $r = TableReservation::create([
                'outlet_id' => $o->id, 'booking_no' => Numbering::next('table_booking'), 'type' => $source === 'walkin' ? 'walkin' : 'booking', 'status' => $status,
                'guest_id' => $guest?->id, 'reservation_room_id' => $stayId,
                'name' => trim((string) ($d['name'] ?? '')) ?: ($guest ? trim($guest->first_name.' '.$guest->last_name) : 'Guest'),
                'phone' => ($d['phone'] ?? null) ?: $guest?->phone, 'email' => ($d['email'] ?? null) ?: $guest?->email,
                'party_size' => $party, 'date' => $start->toDateString(), 'start_at' => $start, 'end_at' => $end, 'duration' => $dur,
                'source' => $source, 'occasion' => $d['occasion'] ?? null, 'tags' => array_values(array_intersect((array) ($d['tags'] ?? []), array_keys(TableReservation::TAGS))) ?: null,
                'section_pref' => $d['section_pref'] ?? null, 'notes' => $d['notes'] ?? null,
                'confirmed_at' => $status === 'confirmed' ? now() : null, 'created_by' => auth()->id(),
            ]);
            $r->forceFill(['token_hash' => hash('sha256', self::tokenFor($r))])->save();
            $r->tables()->sync(array_map(fn ($t) => $t->id, $tables));
            ActivityLogger::log('tables', 'book', "Table booking {$r->booking_no}: {$r->name}, {$party} pax, ".$start->format('d M H:i').' – table '.implode(' + ', array_map(fn ($t) => $t->table_no, $tables)), $r);

            return $r;
        });
        if ($source !== 'walkin') {
            self::message($r, $status === 'confirmed' ? 'confirmation' : 'received');
        }

        return $r->fresh('tables');
    }

    /** Change time / party / tables / details of an upcoming booking. */
    public static function update(TableReservation $r, array $d, bool $notify = false): TableReservation
    {
        if (! $r->isUpcoming()) {
            throw ValidationException::withMessages(['status' => 'Only upcoming bookings can be changed.']);
        }
        $o = $r->outlet;
        $party = (int) ($d['party_size'] ?? $r->party_size);
        $start = Carbon::parse(($d['date'] ?? $r->date->toDateString()).' '.($d['time'] ?? $r->start_at->format('H:i')));
        $moved = ! $start->eq($r->start_at) || $party !== (int) $r->party_size;
        if ($moved && $start->lt(now()->subMinutes(5))) {
            throw ValidationException::withMessages(['time' => 'That time has already passed.']);
        }
        $dur = (int) (($d['duration'] ?? null) ?: ($party !== (int) $r->party_size ? self::durationFor($o, $party) : $r->duration));
        $end = $start->copy()->addMinutes($dur);
        $ids = (array) ($d['table_ids'] ?? []);
        if (! $ids && ! $moved && $dur === (int) $r->duration) {
            $ids = $r->tables->pluck('id')->all(); // same slot – keep the tables (re-checked below)
        }
        $tables = self::resolveTables($o, $party, $start, $end, $ids, $r->id, false, $d['section_pref'] ?? $r->section_pref);
        DB::transaction(function () use ($r, $d, $party, $start, $end, $dur, $tables) {
            $r->fill(array_intersect_key($d, array_flip(['name', 'phone', 'email', 'occasion', 'section_pref', 'notes'])));
            if (array_key_exists('tags', $d)) {
                $r->tags = array_values(array_intersect((array) $d['tags'], array_keys(TableReservation::TAGS))) ?: null;
            }
            $r->fill(['party_size' => $party, 'date' => $start->toDateString(), 'start_at' => $start, 'end_at' => $end, 'duration' => $dur]);
            if ($r->isDirty('start_at')) {
                $r->reminder_sent_at = null;
            }
            $r->save();
            $r->tables()->sync(array_map(fn ($t) => $t->id, $tables));
            ActivityLogger::log('tables', 'update', "Changed table booking {$r->booking_no}: {$party} pax, ".$start->format('d M H:i').' – table '.implode(' + ', array_map(fn ($t) => $t->table_no, $tables)), $r);
        });
        if ($notify && $moved) {
            self::message($r, 'changed');
        }

        return $r->fresh('tables');
    }

    public static function confirm(TableReservation $r): void
    {
        if ($r->status !== 'booked') {
            throw ValidationException::withMessages(['status' => 'Only bookings awaiting confirmation can be confirmed.']);
        }
        $r->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        ActivityLogger::log('tables', 'confirm', "Confirmed table booking {$r->booking_no}", $r);
        self::message($r, 'confirmation');
    }

    public static function cancel(TableReservation $r, string $reason, bool $byGuest = false, bool $notify = false): void
    {
        if ($r->isWaiting()) {
            $r->update(['status' => 'left', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            ActivityLogger::log('tables', 'waitlist_left', "{$r->name} left the waitlist ({$r->booking_no}): {$reason}", $r);

            return;
        }
        if (! $r->isUpcoming()) {
            throw ValidationException::withMessages(['status' => 'This booking can no longer be cancelled.']);
        }
        if ($byGuest && $r->start_at->lte(now())) {
            throw ValidationException::withMessages(['status' => 'The booking time has passed – please call the restaurant.']);
        }
        $r->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $byGuest ? 'Cancelled by guest online'.($reason ? ': '.$reason : '') : $reason]);
        ActivityLogger::log('tables', 'cancel', "Cancelled table booking {$r->booking_no}".($byGuest ? ' (by the guest)' : '').": {$reason}", $r);
        if ($byGuest || $notify) {
            self::message($r, 'cancelled');
        }
    }

    public static function noShow(TableReservation $r, bool $auto = false): void
    {
        if (! $r->isUpcoming()) {
            throw ValidationException::withMessages(['status' => 'Only upcoming bookings can be marked as no-show.']);
        }
        if ($r->start_at->gt(now())) {
            throw ValidationException::withMessages(['status' => 'The booking time has not come yet.']);
        }
        $r->update(['status' => 'no_show', 'no_show_at' => now()]);
        ActivityLogger::log('tables', 'no_show', "Table booking {$r->booking_no} ({$r->name}) marked no-show".($auto ? ' automatically' : ''), $r);
    }

    /** Undo a cancellation / no-show / "left" – the tables are checked again. */
    public static function reinstate(TableReservation $r): TableReservation
    {
        if ($r->status === 'left') {
            $r->update(['status' => 'waiting', 'cancelled_at' => null, 'cancel_reason' => null]);
            ActivityLogger::log('tables', 'reinstate', "{$r->name} back on the waitlist ({$r->booking_no})", $r);

            return $r;
        }
        if (! in_array($r->status, ['cancelled', 'no_show'], true)) {
            throw ValidationException::withMessages(['status' => 'Only cancelled or no-show bookings can be reinstated.']);
        }
        $start = $r->start_at->copy()->max(now()->subMinutes(5)->startOfMinute());
        if (! $start->isSameDay($r->start_at)) {
            throw ValidationException::withMessages(['status' => 'That day is over – make a new booking instead.']);
        }
        $end = $start->copy()->addMinutes($r->duration);
        try {
            $tables = self::resolveTables($r->outlet, $r->party_size, $start, $end, $r->tables->pluck('id')->all(), $r->id, false, $r->section_pref);
        } catch (ValidationException) {
            $tables = self::resolveTables($r->outlet, $r->party_size, $start, $end, [], $r->id, false, $r->section_pref);
        }
        DB::transaction(function () use ($r, $start, $end, $tables) {
            $r->update(['status' => 'confirmed', 'start_at' => $start, 'end_at' => $end, 'cancelled_at' => null, 'cancel_reason' => null, 'no_show_at' => null]);
            $r->tables()->sync(array_map(fn ($t) => $t->id, $tables));
            ActivityLogger::log('tables', 'reinstate', "Reinstated table booking {$r->booking_no} – table ".implode(' + ', array_map(fn ($t) => $t->table_no, $tables)), $r);
        });

        return $r->fresh('tables');
    }

    /**
     * Seat a booking / waitlist party: checks the tables are physically free, then opens (or adopts) the POS order.
     * An unlinked running order already on the first table (the steward opened it) is adopted instead of duplicated.
     */
    public static function seat(TableReservation $r, array $tableIds = []): PosOrder
    {
        if (! in_array($r->status, ['booked', 'confirmed', 'waiting', 'notified'], true)) {
            throw ValidationException::withMessages(['status' => 'This party cannot be seated ('.(self::STATUS_LABELS[$r->status] ?? $r->status).').']);
        }
        $o = $r->outlet;
        self::assertOutlet($o);
        $dur = $r->type === 'booking' ? (int) $r->duration : self::durationFor($o, $r->party_size);
        $ids = array_values(array_unique(array_filter(array_map('intval', $tableIds))));
        if ($ids) {
            $tables = PosTable::where('outlet_id', $o->id)->where('is_active', true)->whereIn('id', $ids)->orderBy('id')->get();
            if ($tables->count() !== count($ids)) {
                throw ValidationException::withMessages(['table_ids' => 'Choose active tables of this outlet.']);
            }
            if (($cap = $tables->sum('capacity')) < $r->party_size) {
                throw ValidationException::withMessages(['table_ids' => 'Table '.$tables->pluck('table_no')->implode(' + ')." seats {$cap} – the party is {$r->party_size}."]);
            }
        } elseif ($r->tables()->exists()) {
            $tables = $r->tables()->get();
        } else {
            $tables = collect(self::candidates($o, $r->party_size, now(), now()->addMinutes($dur), $r->id));
            if ($tables->isEmpty()) {   // walk-in / waitlist: a host would rather use a bigger table than keep the party waiting
                $tables = collect(self::candidates($o, $r->party_size, now(), now()->addMinutes($dur), $r->id, false, null, false));
            }
            if ($tables->isEmpty()) {
                throw ValidationException::withMessages(['table_ids' => "No table for {$r->party_size} is free right now – choose tables."]);
            }
        }
        $first = $tables->first();
        $adopt = PosOrder::where('pos_table_id', $first->id)->whereIn('status', ['open', 'billed'])
            ->whereNotIn('id', TableReservation::whereNotNull('pos_order_id')->whereKeyNot($r->id)->whereIn('status', ['seated'])->select('pos_order_id'))->first();
        foreach ($tables as $t) {
            if ($why = self::occupiedNow($t, $r->id, $t->id === $first->id ? $adopt?->id : null)) {
                throw ValidationException::withMessages(['table_ids' => "Table {$t->table_no} is {$why} – choose other tables."]);
            }
        }

        return DB::transaction(function () use ($r, $o, $tables, $first, $adopt, $dur) {
            $label = $r->name.($r->stay?->room ? ' (Rm '.$r->stay->room->room_no.')' : '');
            if ($adopt) {
                $adopt->update(['covers' => max((int) $adopt->covers, (int) $r->party_size), 'customer_name' => $adopt->customer_name ?: $label, 'customer_phone' => $adopt->customer_phone ?: $r->phone]);
                $order = $adopt;
            } else {
                $order = PosOrder::create(['order_no' => Numbering::next('pos_order'), 'outlet_id' => $o->id, 'pos_table_id' => $first->id, 'order_type' => 'dine_in',
                    'covers' => $r->party_size, 'customer_name' => $label, 'customer_phone' => $r->phone, 'business_date' => bdate(), 'status' => 'open', 'user_id' => auth()->id()]);
            }
            $now = now();
            $upd = ['status' => 'seated', 'seated_at' => $now, 'pos_order_id' => $order->id, 'end_at' => $now->copy()->addMinutes($dur), 'duration' => $dur];
            if ($r->type !== 'booking') {
                $upd += ['start_at' => $r->type === 'walkin' ? $now : $r->start_at, 'date' => $now->toDateString()];
            }
            $r->update($upd);
            $r->tables()->sync($tables->pluck('id')->all());
            PosTable::whereIn('id', $tables->pluck('id'))->update(['needs_clearing' => false]);
            ActivityLogger::log('tables', 'seat', "Seated {$r->name} ({$r->party_size}) at ".$tables->pluck('table_no')->implode(' + ')." – order {$order->order_no}", $r);

            return $order;
        });
    }

    /** A walk-in seated straight from the floor plan (recorded so covers and table turns are complete). */
    public static function walkIn(Outlet $o, int $party, array $tableIds, ?string $name = null, ?string $phone = null): TableReservation
    {
        self::assertOutlet($o);
        $now = now();
        $r = TableReservation::create(['outlet_id' => $o->id, 'booking_no' => Numbering::next('table_booking'), 'type' => 'walkin', 'status' => 'confirmed',
            'name' => trim((string) $name) ?: 'Walk-in', 'phone' => $phone ?: null, 'party_size' => $party, 'date' => $now->toDateString(), 'start_at' => $now,
            'end_at' => $now->copy()->addMinutes(self::durationFor($o, $party)), 'duration' => self::durationFor($o, $party), 'source' => 'walkin', 'created_by' => auth()->id()]);
        try {
            self::seat($r, $tableIds);
        } catch (ValidationException $e) {
            $r->delete();
            throw $e;
        }

        return $r->fresh('tables');
    }

    // ------------------------------------------------------------------ waitlist

    public static function addToWaitlist(Outlet $o, array $d): TableReservation
    {
        self::assertOutlet($o);
        $party = (int) $d['party_size'];
        $quote = isset($d['quoted_minutes']) && $d['quoted_minutes'] !== '' && $d['quoted_minutes'] !== null ? (int) $d['quoted_minutes'] : self::estimateWait($o, $party);
        $now = now();
        $r = TableReservation::create(['outlet_id' => $o->id, 'booking_no' => Numbering::next('table_booking'), 'type' => 'waitlist', 'status' => 'waiting',
            'name' => $d['name'], 'phone' => $d['phone'] ?? null, 'party_size' => $party, 'date' => $now->toDateString(), 'start_at' => $now,
            'end_at' => $now->copy()->addMinutes(self::durationFor($o, $party)), 'duration' => self::durationFor($o, $party), 'source' => 'walkin',
            'tags' => array_values(array_intersect((array) ($d['tags'] ?? []), array_keys(TableReservation::TAGS))) ?: null,
            'section_pref' => $d['section_pref'] ?? null, 'notes' => $d['notes'] ?? null, 'quoted_minutes' => $quote, 'created_by' => auth()->id()]);
        $r->forceFill(['token_hash' => hash('sha256', self::tokenFor($r))])->save();
        ActivityLogger::log('tables', 'waitlist_add', "{$r->name} ({$party}) added to the waitlist – quoted {$quote} min", $r);
        if ($r->phone && ! empty($d['send_sms'])) {
            self::message($r, 'waitlisted');
        }

        return $r;
    }

    /** "Your table is ready" – SMS / WhatsApp if we have a number. */
    public static function notifyReady(TableReservation $r): bool
    {
        if (! $r->isWaiting()) {
            throw ValidationException::withMessages(['status' => 'Only waiting parties can be called.']);
        }
        $sent = $r->phone ? self::message($r, 'ready') : false;
        $r->update(['status' => 'notified', 'notified_at' => now()]);
        ActivityLogger::log('tables', 'waitlist_notify', "Called {$r->name} ({$r->booking_no}) – table ready".($sent ? ' (SMS sent)' : ''), $r);

        return $sent;
    }

    /**
     * Rough wait for a walk-in party: when each table that fits becomes free (running orders and seated parties
     * at their expected finish, upcoming bookings respected), taking the parties already waiting into account.
     */
    public static function estimateWait(Outlet $o, int $party, ?int $excludeId = null): int
    {
        $now = now();
        $dur = self::durationFor($o, $party);
        $buf = (int) self::cfg($o)['buffer'];
        $tables = PosTable::with('openOrder')->where('outlet_id', $o->id)->where('is_active', true)->whereNull('blocked_reason')->get();
        $fit = $tables->filter(fn ($t) => $t->capacity >= $party); // walk-ins may take a bigger table
        if ($fit->isEmpty()) {
            $fit = $tables->sortByDesc('capacity')->take(2); // big party – joined tables; approximate with the largest
        }
        $holding = TableReservation::with('tables:pos_tables.id')->where('outlet_id', $o->id)->whereIn('status', TableReservation::HOLDING)
            ->where('end_at', '>', $now->copy()->subHours(6))->where('start_at', '<', $now->copy()->addHours(12))->get();
        $freeAt = [];
        foreach ($fit as $t) {
            $at = $now->copy();
            foreach ($holding->where('status', 'seated') as $r) {
                if ($r->tables->contains('id', $t->id)) {
                    $at = $at->max(self::effectiveEnd($r)->addMinutes($buf));
                }
            }
            if ($t->openOrder && ! $holding->contains('pos_order_id', $t->openOrder->id)) {
                $at = $at->max($t->openOrder->created_at->copy()->addMinutes(self::durationFor($o, max(1, (int) $t->openOrder->covers)))->max($now->copy()->addMinutes(10))->addMinutes($buf));
            }
            foreach ($holding->whereIn('status', TableReservation::UPCOMING)->sortBy('start_at') as $r) {
                if ($r->tables->contains('id', $t->id) && $r->start_at->lt($at->copy()->addMinutes($dur + $buf)) && $r->end_at->gt($at)) {
                    $at = $r->end_at->copy()->addMinutes($buf);
                }
            }
            $freeAt[] = $at;
        }
        usort($freeAt, fn ($a, $b) => $a <=> $b);
        $ahead = TableReservation::where('outlet_id', $o->id)->whereIn('status', TableReservation::WAITING)->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->whereBetween('party_size', [max(1, $party - 2), $party + 2])->count();
        $n = count($freeAt);
        if ($n === 0) {
            return 0;
        }
        $at = $freeAt[$ahead % $n]->copy()->addMinutes(intdiv($ahead, $n) * ($dur + $buf));
        $min = max(0, (int) ceil($now->diffInMinutes($at, false)));

        return $min <= 0 ? 0 : (int) (ceil($min / 5) * 5);
    }

    // ------------------------------------------------------------------ POS hooks

    /** Called when a POS order is settled / voided: the party has left – complete the reservation, flag the tables for clearing. */
    public static function orderClosed(PosOrder $order): void
    {
        $r = TableReservation::where('pos_order_id', $order->id)->where('status', 'seated')->first();
        $tableIds = $r ? $r->tables()->pluck('pos_tables.id')->all() : array_filter([$order->pos_table_id]);
        if ($r) {
            $r->update(['status' => 'completed', 'completed_at' => now()]);
        }
        if ($tableIds && Outlet::whereKey($order->outlet_id)->value('tb_enabled')) {
            PosTable::whereIn('id', $tableIds)->update(['needs_clearing' => true]);
        }
    }

    /** POS merge: the party follows the order it was merged into. */
    public static function orderMerged(PosOrder $source): void
    {
        if ($source->merged_into_id) {
            TableReservation::where('pos_order_id', $source->id)->where('status', 'seated')->update(['pos_order_id' => $source->merged_into_id]);
        }
    }

    /** POS "move table": the reservation's table moves with the order. */
    public static function orderMoved(PosOrder $order, ?int $fromTableId): void
    {
        $r = TableReservation::where('pos_order_id', $order->id)->where('status', 'seated')->first();
        if (! $r || ! $order->pos_table_id) {
            return;
        }
        $ids = $r->tables()->pluck('pos_tables.id')->all();
        $ids = array_values(array_unique(array_map(fn ($id) => $id === $fromTableId ? $order->pos_table_id : $id, $ids) ?: [$order->pos_table_id]));
        if (! in_array($order->pos_table_id, $ids, true)) {
            $ids = [$order->pos_table_id];
        }
        $r->tables()->sync($ids);
    }

    public static function clearTable(PosTable $t): void
    {
        $t->update(['needs_clearing' => false]);
    }

    public static function block(PosTable $t, ?string $reason): void
    {
        $t->update(['blocked_reason' => $reason ? mb_substr($reason, 0, 120) : null]);
        ActivityLogger::log('tables', $reason ? 'block' : 'unblock', ($reason ? "Blocked table {$t->table_no}: {$reason}" : "Table {$t->table_no} back in service"), $t);
    }

    // ------------------------------------------------------------------ live floor & statistics

    /** Current state of every table in an outlet for the floor plan. */
    public static function live(Outlet $o): array
    {
        $now = now();
        $tables = PosTable::with('openOrder')->where('outlet_id', $o->id)->where('is_active', true)->orderBy('id')->get();
        $seated = TableReservation::with('tables:pos_tables.id,table_no', 'order')->where('outlet_id', $o->id)->where('status', 'seated')->get();
        $upcoming = TableReservation::with('tables:pos_tables.id,table_no')->where('outlet_id', $o->id)->whereIn('status', TableReservation::UPCOMING)
            ->whereBetween('start_at', [$now->copy()->subHours(3), $now->copy()->endOfDay()->addHours(3)])->orderBy('start_at')->get();
        $out = [];
        foreach ($tables as $t) {
            $sr = $seated->first(fn ($r) => $r->tables->contains('id', $t->id));
            // joined tables: the order sits on the first table – the others show it too
            $ord = $t->openOrder ?? ($sr?->order && in_array($sr->order->status, ['open', 'billed'], true) ? $sr->order : null);
            $next = $upcoming->first(fn ($r) => $r->tables->contains('id', $t->id));
            $state = match (true) {
                (bool) $t->blocked_reason => 'blocked',
                $ord && $ord->status === 'billed' => 'billed',
                $ord || $sr => 'seated',
                $t->needs_clearing => 'clearing',
                $next && $next->start_at->lte($now->copy()->addMinutes(60)) => $next->start_at->lt($now) ? 'late' : 'reserved',
                default => 'free',
            };
            $since = $sr?->seated_at ?? $ord?->created_at;
            $expected = $sr ? $sr->end_at : ($ord ? $ord->created_at->copy()->addMinutes(self::durationFor($o, max(1, (int) $ord->covers))) : null);
            $out[] = [
                'id' => $t->id, 'no' => $t->table_no, 'section' => $t->section, 'capacity' => $t->capacity, 'min' => $t->min_capacity, 'shape' => $t->shape,
                'x' => $t->pos_x, 'y' => $t->pos_y, 'state' => $state, 'blocked' => $t->blocked_reason, 'reservable' => $t->reservable, 'combinable' => $t->combinable,
                'party' => $sr?->party_size ?? $ord?->covers, 'name' => $sr?->name ?? $ord?->customer_name,
                'since' => $since ? (int) $since->diffInMinutes($now) : null, 'expected' => $expected?->format('H:i'), 'overdue' => $expected && $expected->lt($now),
                'order_id' => $ord?->id, 'order_no' => $ord?->order_no, 'total' => $ord ? (float) $ord->total : null,
                'res_id' => $sr?->id, 'joined' => $sr ? $sr->tables->where('id', '!=', $t->id)->pluck('table_no')->values()->all() : [],
                'next' => $next ? ['id' => $next->id, 'time' => $next->start_at->format('H:i'), 'name' => $next->name, 'party' => $next->party_size, 'no' => $next->booking_no,
                    'late' => $next->start_at->lt($now), 'status' => $next->status] : null,
            ];
        }

        return $out;
    }

    public static function dayStats(Outlet $o, Carbon $date): array
    {
        $q = TableReservation::where('outlet_id', $o->id)->whereDate('date', $date->toDateString());
        $rows = $q->get();
        $bookings = $rows->where('type', 'booking');
        $live = $bookings->whereIn('status', ['booked', 'confirmed', 'seated', 'completed']);
        $waited = $rows->where('type', 'waitlist')->map->waitedMinutes()->filter(fn ($m) => $m !== null);

        return [
            'bookings' => $live->count(), 'covers' => (int) $live->sum('party_size'),
            'to_come' => $bookings->whereIn('status', TableReservation::UPCOMING)->count(), 'to_come_covers' => (int) $bookings->whereIn('status', TableReservation::UPCOMING)->sum('party_size'),
            'unconfirmed' => $bookings->where('status', 'booked')->count(),
            'seated' => $rows->whereIn('status', ['seated'])->count(), 'seated_covers' => (int) $rows->where('status', 'seated')->sum('party_size'),
            'served_covers' => (int) $rows->whereIn('status', ['seated', 'completed'])->sum('party_size'),
            'walkins' => $rows->where('type', 'walkin')->count(), 'no_shows' => $bookings->where('status', 'no_show')->count(),
            'cancelled' => $bookings->where('status', 'cancelled')->count(), 'waiting' => $rows->whereIn('status', TableReservation::WAITING)->count(),
            'avg_wait' => $waited->isNotEmpty() ? (int) round($waited->avg()) : null,
        ];
    }

    /** Sidebar badge: parties on the waitlist + bookings awaiting confirmation today and tomorrow. */
    public static function attentionCount(): int
    {
        return TableReservation::whereIn('status', TableReservation::WAITING)->whereDate('date', now()->toDateString())->count()
            + TableReservation::where('status', 'booked')->where('start_at', '>=', now())->where('start_at', '<', now()->addDays(2)->startOfDay())->count();
    }

    // ------------------------------------------------------------------ messages & runner

    /** Confirmation / reminder / table-ready messages: e-mail when we have one (ready → SMS), otherwise SMS / WhatsApp. */
    public static function message(TableReservation $r, string $kind): bool
    {
        $h = hotel();
        $o = $r->outlet;
        $when = $r->start_at->format('D d M').' at '.$r->start_at->format('H:i');
        $link = $r->token_hash ? self::tokenFor($r) : null;
        $url = $link ? route('dine.manage', $link) : null;
        $text = match ($kind) {
            'confirmation' => "Your table for {$r->party_size} at {$o->name}, {$h->name} on {$when} is confirmed (ref {$r->booking_no}).".($url ? " View or cancel: {$url}" : ''),
            'received' => "Thank you – we have received your table request for {$r->party_size} at {$o->name} on {$when} (ref {$r->booking_no}). We will confirm shortly.".($url ? " {$url}" : ''),
            'changed' => "Your table booking {$r->booking_no} at {$o->name} has been changed: {$r->party_size} guests on {$when}.".($url ? " {$url}" : ''),
            'reminder' => "Reminder: your table for {$r->party_size} at {$o->name} today at ".$r->start_at->format('H:i').'. Running late or can\'t make it? '.($url ?: 'Please call us.'),
            'cancelled' => "Your table booking {$r->booking_no} at {$o->name} for {$when} has been cancelled. We hope to welcome you another time.",
            'waitlisted' => "Hi {$r->name}, you're on the waitlist at {$o->name} for {$r->party_size}. Estimated wait about {$r->quoted_minutes} min – we'll text you when your table is ready.",
            'ready' => "{$r->name}, your table at {$o->name} is ready! Please come to the host stand within 10 minutes.",
        };
        $subject = match ($kind) {
            'confirmation' => "Table confirmed – {$o->name}, {$when}", 'received' => "Table request received – {$o->name}", 'changed' => "Table booking changed – {$o->name}",
            'reminder' => "Reminder: your table today at {$o->name}", 'cancelled' => "Table booking cancelled – {$o->name}", default => $o->name,
        };
        $useMail = $r->email && ! in_array($kind, ['ready', 'waitlisted'], true);
        if (! $useMail && ! $r->phone) {
            return false;
        }
        $log = $useMail
            ? MailService::send($r->email, new GuestMessage($subject, "Dear {$r->name},\n\n{$text}\n\n{$o->name}\n{$h->name}".($h->phone ? " · {$h->phone}" : ''), $h), 'table_'.$kind, $r)
            : MessageService::send($r->phone, $text, MessageService::defaultChannel(), 'table_'.$kind, $r);

        return $log->status === 'sent';
    }

    /** Scheduled every 5 minutes: reminders, automatic no-shows, yesterday's waitlist and forgotten seated parties. */
    public static function runDue(): array
    {
        $out = ['reminders' => 0, 'no_shows' => 0, 'waitlist_closed' => 0, 'closed_seated' => 0];
        $now = now();
        foreach (self::enabledOutlets() as $o) {
            $c = self::cfg($o);
            if ($c['reminder_hours'] > 0) {
                $due = TableReservation::where('outlet_id', $o->id)->whereIn('status', TableReservation::UPCOMING)->whereNull('reminder_sent_at')
                    ->where('start_at', '>', $now)->where('start_at', '<=', $now->copy()->addHours((int) $c['reminder_hours']))
                    ->where('created_at', '<', $now->copy()->subMinutes(60))->get();
                foreach ($due as $r) {
                    self::message($r, 'reminder');
                    $r->update(['reminder_sent_at' => now()]);
                    $out['reminders']++;
                }
            }
            if ($c['auto_no_show']) {
                foreach (TableReservation::where('outlet_id', $o->id)->whereIn('status', TableReservation::UPCOMING)
                    ->where('start_at', '<', $now->copy()->subMinutes((int) $c['hold_minutes']))->get() as $r) {
                    self::noShow($r, true);
                    $out['no_shows']++;
                }
            }
            $out['waitlist_closed'] += TableReservation::where('outlet_id', $o->id)->whereIn('status', TableReservation::WAITING)->whereDate('date', '<', $now->toDateString())
                ->update(['status' => 'left', 'cancelled_at' => $now, 'cancel_reason' => 'Waitlist closed at the end of the day']);
            // seated parties whose POS order disappeared or that were never closed – finish them after 6 hours
            foreach (TableReservation::with('order')->where('outlet_id', $o->id)->where('status', 'seated')->where('end_at', '<', $now->copy()->subHours(6))->get() as $r) {
                if (! $r->order || ! in_array($r->order->status, ['open', 'billed'], true)) {
                    $r->update(['status' => 'completed', 'completed_at' => $r->order?->settled_at ?? $r->end_at]);
                    $out['closed_seated']++;
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ settings

    public static function saveSettings(Outlet $o, array $d): void
    {
        $durations = [];
        foreach ((array) ($d['durations'] ?? []) as $row) {
            if (! empty($row['party']) && ! empty($row['minutes'])) {
                $durations[(string) (int) $row['party']] = (int) $row['minutes'];
            }
        }
        if (! $durations) {
            throw ValidationException::withMessages(['durations' => 'Add at least one dining time.']);
        }
        ksort($durations, SORT_NUMERIC);
        $cfg = [
            'slot_minutes' => (int) $d['slot_minutes'], 'durations' => $durations, 'buffer' => (int) $d['buffer'], 'hold_minutes' => (int) $d['hold_minutes'],
            'auto_no_show' => (bool) ($d['auto_no_show'] ?? false), 'min_notice' => (int) $d['min_notice'], 'days_ahead' => (int) $d['days_ahead'],
            'max_online_party' => (int) $d['max_online_party'], 'pacing_covers' => (int) ($d['pacing_covers'] ?? 0), 'auto_confirm_online' => (bool) ($d['auto_confirm_online'] ?? false),
            'reminder_hours' => (int) ($d['reminder_hours'] ?? 0), 'noshow_flag' => (int) ($d['noshow_flag'] ?? 0), 'online_note' => (string) ($d['online_note'] ?? ''),
        ];
        DB::transaction(function () use ($o, $d, $cfg) {
            $o->update(['tb_enabled' => (bool) ($d['tb_enabled'] ?? false), 'tb_online' => (bool) ($d['tb_enabled'] ?? false) && (bool) ($d['tb_online'] ?? false), 'tb_settings' => $cfg]);
            $keep = [];
            foreach ((array) ($d['periods'] ?? []) as $row) {
                if (empty($row['name']) && empty($row['start_time'])) {
                    continue;
                }
                $attrs = ['name' => $row['name'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time'],
                    'days' => implode('', array_values(array_intersect(['1', '2', '3', '4', '5', '6', '7'], array_map('strval', (array) ($row['days'] ?? []))))) ?: '1234567',
                    'is_active' => (bool) ($row['is_active'] ?? true)];
                $p = ! empty($row['id']) ? $o->periods()->whereKey($row['id'])->first() : null;
                $p ? $p->update($attrs) : $p = $o->periods()->create($attrs);
                $keep[] = $p->id;
            }
            $o->periods()->whereNotIn('id', $keep ?: [0])->delete();
            foreach ((array) ($d['tables'] ?? []) as $id => $row) {
                $t = PosTable::where('outlet_id', $o->id)->find($id);
                $t?->update(['capacity' => (int) $row['capacity'], 'min_capacity' => min((int) $row['min_capacity'], (int) $row['capacity']), 'shape' => $row['shape'],
                    'section' => ($row['section'] ?? null) ?: null, 'combinable' => (bool) ($row['combinable'] ?? false), 'reservable' => (bool) ($row['reservable'] ?? false)]);
            }
            ActivityLogger::log('tables', 'settings', "Updated table reservation settings for {$o->name}", $o);
        });
    }

    /** Floor-plan editor: table positions (percent of the canvas). */
    public static function saveLayout(Outlet $o, array $rows): int
    {
        $n = 0;
        foreach ($rows as $row) {
            $t = PosTable::where('outlet_id', $o->id)->find((int) ($row['id'] ?? 0));
            if ($t) {
                $t->update(['pos_x' => max(0, min(96, (int) $row['x'])), 'pos_y' => max(0, min(94, (int) $row['y']))] + (in_array($row['shape'] ?? null, ['square', 'round', 'rect', 'booth'], true) ? ['shape' => $row['shape']] : []));
                $n++;
            }
        }
        ActivityLogger::log('tables', 'layout', "Saved the floor plan for {$o->name} ({$n} tables)", $o);

        return $n;
    }

    /** Default positions for tables that have none yet: sections side by side, a grid inside each. */
    public static function autoLayout(Outlet $o): void
    {
        $tables = PosTable::where('outlet_id', $o->id)->where('is_active', true)->orderBy('id')->get();
        if ($tables->every(fn ($t) => $t->pos_x !== null)) {
            return;
        }
        $groups = $tables->groupBy(fn ($t) => (string) $t->section)->values();
        $w = 100 / max(1, $groups->count());
        foreach ($groups as $gi => $g) {
            $cols = max(1, min(3, (int) ceil(sqrt($g->count()))));
            foreach ($g->values() as $i => $t) {
                if ($t->pos_x === null) {
                    $t->update(['pos_x' => (int) round($gi * $w + 6 + ($i % $cols) * (($w - 12) / $cols)), 'pos_y' => (int) min(88, 12 + intdiv($i, $cols) * 26)]);
                }
            }
        }
    }
}
