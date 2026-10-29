<?php

namespace App\Services;

use App\Mail\GuestMessage;
use App\Models\Driver;
use App\Models\Guest;
use App\Models\GuestRequest;
use App\Models\PaymentMode;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Models\TransportTariff;
use App\Models\TransportTrip;
use App\Models\Vehicle;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * v3.3 guest transport & fleet desk.
 *
 * A trip is an airport / station pick-up, a drop or a local transfer. Each trip blocks its vehicle and driver for a
 * "busy window": pick-ups leave the hotel `transport_pickup_lead` minutes before the flight lands, drops and transfers
 * leave at the scheduled time; the window lasts the tariff's round-trip duration. Assigning checks seats, the
 * vehicle's papers (insurance / permit / fitness / PUC) and the driver's licence on the trip date (hard stops) and
 * overlapping windows incl. a turnaround buffer (a warning the dispatcher can override).
 *
 * Billing: "room" trips post one Transport charge (tax by category, GL 4070) to the stay's folio when the trip is
 * completed – or at check-out if the drop happens after the bill is settled; "direct" trips are paid at completion
 * on a closed walk-in invoice; complimentary trips are tracked at their fare value but never charged.
 */
class TransportService
{
    public const DEFAULTS = [
        'transport_airport' => 'Airport',              // default pick-up / drop point
        'transport_station' => 'Railway station',
        'transport_pickup_lead' => '60',               // minutes a pick-up leaves before the flight lands
        'transport_buffer' => '15',                    // turnaround minutes between two trips of a vehicle / driver
        'transport_driver_reminder' => '45',           // SMS the driver N minutes before leaving (0 = off)
        'transport_alert_hours' => '3',                // unassigned trips leaving within N hours need attention
        'transport_notify_guest' => '1',               // send the guest driver & vehicle details when assigned
        'transport_notify_driver' => '1',              // send the driver the trip sheet when assigned
        'transport_paper_days' => '30',                // warn N days before papers / licences expire
        'transport_default_billing' => 'room',
        'transport_request_expiry_hours' => '6',       // unconfirmed requests are closed N hours after their time
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        if (! TransportTariff::exists()) {
            $rows = [
                ['Airport transfer – Sedan', 'airport', 'sedan', 1200, 40, 18, 150, 150], ['Airport transfer – SUV', 'airport', 'suv', 1800, 40, 24, 200, 150],
                ['Airport transfer – Van / Tempo Traveller', 'airport', 'minibus', 3200, 40, 32, 300, 150], ['Airport transfer – Luxury', 'airport', 'luxury', 4500, 40, 45, 500, 150],
                ['Railway station – Sedan', 'railway', 'sedan', 600, 15, 18, 150, 60], ['Railway station – SUV', 'railway', 'suv', 900, 15, 24, 200, 60],
                ['Local 4 h / 40 km – Sedan', 'local', 'sedan', 1800, 40, 16, 180, 240], ['City sightseeing 8 h / 80 km – SUV', 'tour', 'suv', 3800, 80, 22, 250, 480],
            ];
            foreach ($rows as $i => [$n, $k, $vt, $rate, $km, $pkm, $wait, $dur]) {
                TransportTariff::create(['name' => $n, 'kind' => $k, 'vehicle_type' => $vt, 'flat_rate' => $rate, 'included_km' => $km, 'per_km' => $pkm,
                    'wait_per_hour' => $wait, 'duration_min' => $dur, 'sort_order' => $i + 1]);
            }
        }
    }

    public static function cfg(): array
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $v) {
            $s[substr($k, 10)] = (string) setting($k, $v);
        }
        foreach (['pickup_lead', 'buffer', 'driver_reminder', 'alert_hours', 'paper_days', 'request_expiry_hours'] as $k) {
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
        ActivityLogger::log('transport', 'settings', 'Transport desk settings updated');
    }

    // ───────────────────────── scheduling ─────────────────────────

    /** [busy_from, busy_to] for a trip. */
    public static function window(string $type, Carbon $at, int $duration): array
    {
        $duration = max(15, $duration);
        if ($type === 'pickup') {
            $lead = min(self::cfg()['pickup_lead'], $duration - 15);
            $from = $at->copy()->subMinutes(max(0, $lead));
            $to = $from->copy()->addMinutes($duration)->max($at->copy()->addMinutes(30));
        } else {
            $from = $at->copy();
            $to = $at->copy()->addMinutes($duration);
        }

        return [$from, $to];
    }

    public static function defaultDuration(string $type, ?TransportTariff $tariff = null): int
    {
        return $tariff?->duration_min ?: ($type === 'transfer' ? 60 : 120);
    }

    /**
     * Trips that overlap a window for a vehicle / driver (turnaround buffer included). Returns human-readable
     * clashes: ['vehicle' => [...], 'driver' => [...]].
     */
    public static function clashes(?int $vehicleId, ?int $driverId, Carbon $from, Carbon $to, ?int $exceptId = null): array
    {
        $buf = self::cfg()['buffer'];
        $q = fn () => TransportTrip::whereIn('status', TransportTrip::OPEN)->when($exceptId, fn ($w) => $w->whereKeyNot($exceptId))
            ->where('busy_from', '<', $to->copy()->addMinutes($buf))->where('busy_to', '>', $from->copy()->subMinutes($buf))->orderBy('busy_from');
        $fmt = fn ($t) => "{$t->trip_no} ".$t->busy_from->format('H:i').'–'.$t->busy_to->format('H:i')." ({$t->passenger_name})";
        $out = ['vehicle' => [], 'driver' => []];
        if ($vehicleId) {
            $out['vehicle'] = $q()->where('vehicle_id', $vehicleId)->get()->map($fmt)->all();
        }
        if ($driverId) {
            $out['driver'] = $q()->where('driver_id', $driverId)->get()->map($fmt)->all();
        }

        return $out;
    }

    /** Hard reasons a vehicle / driver can't do a trip (not clashes). */
    public static function blockers(TransportTrip $trip, ?Vehicle $v, ?Driver $d): array
    {
        $out = [];
        $date = $trip->scheduled_at;
        if ($v) {
            if (! $v->is_active) {
                $out[] = "{$v->code} is retired.";
            } elseif ($v->status === 'maintenance') {
                $out[] = "{$v->code} is in maintenance.";
            }
            if ($v->seats < $trip->pax) {
                $out[] = "{$v->code} seats {$v->seats} – the party is {$trip->pax}.";
            }
            if ($exp = $v->expiredPapers($date)) {
                $out[] = "{$v->code}: ".implode(', ', array_keys($exp)).' expired on '.collect($exp)->min()->format('d M Y').' – renew before using it.';
            }
        }
        if ($d) {
            if (! $d->is_active) {
                $out[] = "{$d->name} is no longer active.";
            }
            if ($d->licenceExpired($date)) {
                $out[] = "{$d->name}'s driving licence expired on ".$d->licence_until->format('d M Y').'.';
            }
        }

        return $out;
    }

    /** Best free vehicle & driver for a trip: fits the party, papers valid, no clash; prefers the requested type and the smallest fit. */
    public static function suggest(TransportTrip $trip): array
    {
        $vehicles = Vehicle::where('is_active', true)->where('status', 'available')->where('seats', '>=', $trip->pax)->orderBy('seats')->orderBy('code')->get()
            ->filter(fn ($v) => ! self::blockers($trip, $v, null) && ! self::clashes($v->id, null, $trip->busy_from, $trip->busy_to, $trip->id)['vehicle'])
            ->sortBy(fn ($v) => [$trip->vehicle_type && $v->type === $trip->vehicle_type ? 0 : 1, $v->seats, $v->code])->values();
        $day = $trip->scheduled_at->toDateString();
        $load = TransportTrip::whereIn('status', array_merge(TransportTrip::OPEN, ['completed']))->whereDate('scheduled_at', $day)->whereNotNull('driver_id')
            ->selectRaw('driver_id, COUNT(*) n')->groupBy('driver_id')->pluck('n', 'driver_id');
        $drivers = Driver::where('is_active', true)->orderBy('name')->get()
            ->filter(fn ($d) => ! self::blockers($trip, null, $d) && ! self::clashes(null, $d->id, $trip->busy_from, $trip->busy_to, $trip->id)['driver'])
            ->sortBy(fn ($d) => [(int) ($load[$d->id] ?? 0), $d->name])->values();

        return ['vehicle' => $vehicles->first(), 'driver' => $drivers->first(), 'vehicles' => $vehicles, 'drivers' => $drivers];
    }

    // ───────────────────────── booking ─────────────────────────

    /** The stay a reservation's transport belongs to (first room not cancelled). */
    public static function stayFor(?Reservation $r): ?ReservationRoom
    {
        return $r?->rooms()->whereNotIn('status', ['cancelled', 'no_show'])->orderByRaw("CASE status WHEN 'checked_in' THEN 0 WHEN 'reserved' THEN 1 ELSE 2 END")->orderBy('id')->first();
    }

    /** Prefill for a new trip linked to a reservation / stay. */
    public static function prefill(?Reservation $r, string $type = 'pickup', ?ReservationRoom $stay = null): array
    {
        $c = self::cfg();
        $h = hotel();
        $stay ??= self::stayFor($r);
        $r ??= $stay?->reservation;
        $guest = $stay?->guest ?? $r?->guest;
        if ($type === 'pickup') {
            $at = Carbon::parse(($stay?->arrival_date ?? $r?->arrival_date ?? now())->toDateString().' '.($r?->eta ? substr($r->eta, 0, 5) : '12:00'));
        } elseif ($type === 'drop') {
            $at = Carbon::parse(($stay?->departure_date ?? $r?->departure_date ?? now())->toDateString().' '.substr((string) ($h->checkout_time ?: '11:00'), 0, 5));
        } else {
            $at = now()->addHour()->startOfHour();
        }
        $tariff = TransportTariff::where('is_active', true)->where('kind', $type === 'transfer' ? 'local' : 'airport')->orderBy('sort_order')->first();

        return [
            'type' => $type, 'reservation_id' => $r?->id, 'reservation_room_id' => $stay?->id, 'guest_id' => $guest?->id,
            'passenger_name' => $guest?->full_name ?? '', 'phone' => $guest?->phone, 'pax' => $r ? max(1, (int) $r->adults + (int) $r->children) : 1,
            'pickup_point' => $type === 'pickup' ? $c['airport'] : $h->name, 'dropoff_point' => $type === 'drop' ? $c['airport'] : ($type === 'pickup' ? $h->name : ''),
            'scheduled_at' => $at->format('Y-m-d\TH:i'), 'transport_tariff_id' => $tariff?->id, 'amount' => $tariff ? (float) $tariff->flat_rate : 0,
            'billing' => $stay || $r ? $c['default_billing'] : 'direct',
        ];
    }

    /**
     * Create or change a trip. $d: type, scheduled_at, passenger_name, phone, pax, bags, flight_no, pickup_point, dropoff_point,
     * transport_tariff_id, vehicle_type, amount, billing, reservation_id | reservation_room_id, guest_request_id, notes,
     * duration_min, source, vehicle_id + driver_id (assign straight away), force (override clashes).
     */
    public static function save(array $d, ?TransportTrip $trip = null): TransportTrip
    {
        if ($trip && ! $trip->isPlanned()) {
            throw new RuntimeException("{$trip->trip_no} is {$trip->statusLabel()} and can't be changed.");
        }
        $type = in_array($d['type'] ?? $trip?->type, array_keys(TransportTrip::TYPES), true) ? ($d['type'] ?? $trip->type) : 'pickup';
        $at = Carbon::parse($d['scheduled_at'] ?? $trip?->scheduled_at);
        if (! $trip && $at->lt(now()->subHours(12))) {
            throw new RuntimeException('That time is in the past.');
        }
        $name = trim((string) ($d['passenger_name'] ?? $trip?->passenger_name));
        if ($name === '') {
            throw new RuntimeException('Enter the passenger\'s name.');
        }
        $pax = max(1, min(60, (int) ($d['pax'] ?? $trip?->pax ?? 1)));
        $tariff = ! empty($d['transport_tariff_id']) ? TransportTariff::where('is_active', true)->find((int) $d['transport_tariff_id']) : null;
        if (! empty($d['transport_tariff_id']) && ! $tariff) {
            throw new RuntimeException('Choose an active tariff.');
        }

        // link: stay → reservation → guest
        $stay = ! empty($d['reservation_room_id']) ? ReservationRoom::with('reservation')->find((int) $d['reservation_room_id']) : null;
        $res = $stay?->reservation ?? (! empty($d['reservation_id']) ? Reservation::find((int) $d['reservation_id']) : null);
        if ($res && ! $stay) {
            $stay = self::stayFor($res);
        }
        if (! $trip && $res && in_array($res->status, ['cancelled', 'no_show', 'checked_out'], true) && $type !== 'drop') {
            throw new RuntimeException("Reservation {$res->reservation_no} is ".str_replace('_', ' ', $res->status).'.');
        }
        $billing = array_key_exists($d['billing'] ?? '', TransportTrip::BILLING) ? $d['billing'] : ($trip?->billing ?? 'direct');
        if ($billing === 'room' && (! $stay || ! in_array($stay->status, ['reserved', 'checked_in'], true))) {
            throw new RuntimeException('Only trips linked to a current reservation or in-house room can be posted to the room bill – choose direct payment or complimentary.');
        }
        $req = ! empty($d['guest_request_id']) ? GuestRequest::find((int) $d['guest_request_id']) : null;
        if (! empty($d['guest_request_id']) && (! $req || $req->category !== 'transport' || ! $req->isOpen())) {
            throw new RuntimeException('That guest request is not an open transport request.');
        }

        $duration = (int) ($d['duration_min'] ?? 0) ?: ($tariff && (! $trip || $trip->transport_tariff_id !== $tariff->id) ? self::defaultDuration($type, $tariff) : ($trip?->duration_min ?: self::defaultDuration($type, $tariff)));
        [$from, $to] = self::window($type, $at, $duration);
        $amount = array_key_exists('amount', $d) && $d['amount'] !== null && $d['amount'] !== '' ? max(0, round((float) $d['amount'], 2)) : ($tariff ? (float) $tariff->flat_rate : (float) ($trip?->amount ?? 0));
        $row = [
            'type' => $type, 'scheduled_at' => $at, 'duration_min' => $duration, 'busy_from' => $from, 'busy_to' => $to,
            'passenger_name' => mb_substr($name, 0, 150), 'phone' => ($p = trim((string) ($d['phone'] ?? $trip?->phone))) !== '' ? mb_substr($p, 0, 30) : null,
            'pax' => $pax, 'bags' => max(0, min(99, (int) ($d['bags'] ?? $trip?->bags ?? 0))),
            'flight_no' => ($f = strtoupper(trim((string) ($d['flight_no'] ?? $trip?->flight_no)))) !== '' ? mb_substr($f, 0, 20) : null,
            'pickup_point' => mb_substr(trim((string) ($d['pickup_point'] ?? $trip?->pickup_point)) ?: ($type === 'pickup' ? self::cfg()['airport'] : hotel()->name), 0, 190),
            'dropoff_point' => mb_substr(trim((string) ($d['dropoff_point'] ?? $trip?->dropoff_point)) ?: ($type === 'drop' ? self::cfg()['airport'] : hotel()->name), 0, 190),
            'transport_tariff_id' => $tariff?->id, 'vehicle_type' => ($d['vehicle_type'] ?? null) ?: ($tariff?->vehicle_type ?? $trip?->vehicle_type),
            'billing' => $billing, 'amount' => $amount, 'total' => round($amount + (float) ($trip?->extra_amount ?? 0), 2),
            'reservation_id' => $res?->id, 'reservation_room_id' => $stay?->id, 'guest_id' => $stay?->guest_id ?? $res?->guest_id ?? ($d['guest_id'] ?? $trip?->guest_id),
            'notes' => ($n = trim((string) ($d['notes'] ?? $trip?->notes))) !== '' ? mb_substr($n, 0, 500) : null,
        ];
        if ($row['vehicle_type'] && ! array_key_exists($row['vehicle_type'], Vehicle::TYPES)) {
            $row['vehicle_type'] = null;
        }

        return DB::transaction(function () use ($trip, $row, $d, $req) {
            if ($trip) {
                self::lockDispatchBoard();
                $trip = TransportTrip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
                if (! $trip->isPlanned()) {
                    throw new RuntimeException("{$trip->trip_no} is {$trip->statusLabel()} and can't be changed.");
                }
                $moved = ! $trip->scheduled_at->equalTo($row['scheduled_at']) || $trip->pax !== $row['pax'];
                $trip->fill($row);
                if ($trip->vehicle_id || $trip->driver_id) {
                    // a new time must still suit the assigned car & driver
                    $blk = self::blockers($trip, $trip->vehicle, $trip->driver);
                    $cl = self::clashes($trip->vehicle_id, $trip->driver_id, $trip->busy_from, $trip->busy_to, $trip->id);
                    if ($blk) {
                        throw new RuntimeException(implode(' ', $blk).' Un-assign first or pick another time.');
                    }
                    if (($cl['vehicle'] || $cl['driver']) && empty($d['force'])) {
                        throw new TransportClash($cl);
                    }
                }
                $trip->save();
                ActivityLogger::log('transport', 'update', "Trip {$trip->trip_no} changed: ".$trip->scheduled_at->format('d M H:i')." · {$trip->pax} pax", $trip);
                if ($moved && ! empty($d['notify']) && $trip->guest_notified_at) {
                    self::message($trip, 'changed');
                }
                if ($moved && $trip->driver_id && $trip->driver_notified_at) {
                    self::message($trip, 'changed', 'driver');
                }

                return $trip->fresh();
            }
            $source = in_array($d['source'] ?? '', array_keys(TransportTrip::SOURCES), true) ? $d['source'] : ($req ? 'request' : (! empty($row['reservation_id']) ? 'reservation' : 'staff'));
            $t = TransportTrip::create($row + [
                'trip_no' => Numbering::next('transport_trip'), 'status' => $source === 'portal' ? 'requested' : 'confirmed', 'source' => $source,
                'guest_request_id' => $req?->id, 'created_by' => auth()->id(),
            ]);
            if ($req) {
                $note = "Car booked – trip {$t->trip_no} at ".$t->scheduled_at->format('d M H:i');
                in_array($req->status, ['new', 'acknowledged'], true) ? GuestPortalService::transition($req, 'start', $note) : $req->forceFill(['staff_note' => $note])->save();
                $req->forceFill(['linked_type' => 'TransportTrip', 'linked_id' => $t->id])->save();
            }
            ActivityLogger::log('transport', 'book', "Trip {$t->trip_no} booked: {$t->typeLabel()} ".$t->scheduled_at->format('d M H:i')." · {$t->passenger_name} ({$t->pax}) · ".$t->route(), $t);
            if (! empty($d['vehicle_id']) && ! empty($d['driver_id'])) {
                return self::assign($t, (int) $d['vehicle_id'], (int) $d['driver_id'], ! empty($d['force']), ! array_key_exists('notify', $d) || ! empty($d['notify']));
            }

            return $t;
        });
    }

    /** Guest portal: a pick-up (before arrival) or drop / transfer request, confirmed by the desk. */
    public static function portalRequest(ReservationRoom $stay, array $d): TransportTrip
    {
        $open = TransportTrip::where('reservation_room_id', $stay->id)->whereIn('status', TransportTrip::PLANNED)->count();
        if ($open >= 4) {
            throw new RuntimeException('You already have several transfers booked – please call the front desk for more.');
        }
        $type = ($d['type'] ?? 'drop') === 'pickup' ? 'pickup' : (($d['type'] ?? '') === 'transfer' ? 'transfer' : 'drop');
        $at = Carbon::parse($d['date'].' '.$d['time']);
        if ($at->lt(now()->addHours(2))) {
            throw new RuntimeException('Please book at least 2 hours ahead – for anything sooner call the front desk.');
        }
        if ($type === 'pickup' && $at->toDateString() > $stay->arrival_date->toDateString()) {
            throw new RuntimeException('A pick-up has to be on or before your arrival date.');
        }
        if ($type !== 'pickup' && $at->toDateString() > $stay->departure_date->toDateString()) {
            throw new RuntimeException('That is after your check-out date.');
        }
        $p = self::prefill($stay->reservation, $type, $stay);
        $place = trim((string) ($d['place'] ?? ''));

        return self::save(array_merge($p, [
            'scheduled_at' => $at->toDateTimeString(), 'pax' => (int) ($d['pax'] ?? $p['pax']), 'bags' => (int) ($d['bags'] ?? 0), 'flight_no' => $d['flight_no'] ?? null,
            'pickup_point' => $type === 'transfer' ? hotel()->name : ($type === 'pickup' ? ($place ?: $p['pickup_point']) : $p['pickup_point']),
            'dropoff_point' => $type === 'pickup' ? $p['dropoff_point'] : ($place ?: ($type === 'drop' ? $p['dropoff_point'] : 'As advised by the guest')),
            'notes' => $d['notes'] ?? null, 'source' => 'portal', 'billing' => in_array($stay->status, ['reserved', 'checked_in'], true) ? 'room' : 'direct',
            'phone' => ($d['phone'] ?? null) ?: $p['phone'],
        ]));
    }

    public static function confirm(TransportTrip $t, bool $notify = true): TransportTrip
    {
        $t = DB::transaction(function () use ($t) {
            $t = TransportTrip::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->status !== 'requested') {
                throw new RuntimeException("{$t->trip_no} is already {$t->statusLabel()}.");
            }
            $t->forceFill(['status' => 'confirmed'])->save();
            ActivityLogger::log('transport', 'confirm', "Trip {$t->trip_no} confirmed", $t);

            return $t;
        });
        if ($notify) {
            self::message($t, 'confirmed');
        }

        return $t;
    }

    /** Serialize dispatch assignments and changes to an assigned trip's schedule. */
    protected static function lockDispatchBoard(): void
    {
        Setting::firstOrCreate(['key' => 'transport_dispatch_lock'], ['value' => '0']);
        Setting::where('key', 'transport_dispatch_lock')->lockForUpdate()->first();
    }

    /** Put a car and driver on a trip. Clashes throw TransportClash unless $force. */
    public static function assign(TransportTrip $t, int $vehicleId, int $driverId, bool $force = false, bool $notify = true): TransportTrip
    {
        [$t, $changed, $prevDriver] = DB::transaction(function () use ($t, $vehicleId, $driverId, $force) {
            self::lockDispatchBoard();
            $t = TransportTrip::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if (! $t->isPlanned()) {
                throw new RuntimeException("{$t->trip_no} is {$t->statusLabel()} – it can't be re-assigned.");
            }
            $v = Vehicle::whereKey($vehicleId)->lockForUpdate()->first();
            $d = Driver::whereKey($driverId)->lockForUpdate()->first();
            if (! $v || ! $d) {
                throw new RuntimeException('Choose a vehicle and a driver.');
            }
            if ($blk = self::blockers($t, $v, $d)) {
                throw new RuntimeException(implode(' ', $blk));
            }
            $cl = self::clashes($v->id, $d->id, $t->busy_from, $t->busy_to, $t->id);
            if (($cl['vehicle'] || $cl['driver']) && ! $force) {
                throw new TransportClash($cl);
            }
            $changed = $t->vehicle_id !== $v->id || $t->driver_id !== $d->id;
            $prevDriver = $t->driver_id && $t->driver_id !== $d->id ? Driver::find($t->driver_id) : null;
            $t->forceFill(['vehicle_id' => $v->id, 'driver_id' => $d->id, 'status' => 'assigned', 'assigned_at' => now(),
                'reminder_sent_at' => $changed ? null : $t->reminder_sent_at])->save();
            $t->setRelation('vehicle', $v)->setRelation('driver', $d);
            ActivityLogger::log('transport', 'assign', "Trip {$t->trip_no}: {$v->code} ({$v->reg_no}) with {$d->name}".($cl['vehicle'] || $cl['driver'] ? ' – clash overridden' : ''), $t);

            return [$t->fresh(['vehicle', 'driver']), $changed, $prevDriver];
        });

        if ($changed && $notify) {
            $c = self::cfg();
            if ($prevDriver && $t->driver_notified_at) {
                self::message($t, 'cancelled', 'driver', $prevDriver);
            }
            if ($c['notify_driver'] === '1') {
                self::message($t, 'assigned', 'driver');
            }
            if ($c['notify_guest'] === '1') {
                self::message($t, 'assigned');
            }
        }

        return $t;
    }

    public static function unassign(TransportTrip $t): TransportTrip
    {
        [$t, $driver] = DB::transaction(function () use ($t) {
            self::lockDispatchBoard();
            $t = TransportTrip::with('driver')->whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->status !== 'assigned') {
                throw new RuntimeException("{$t->trip_no} is {$t->statusLabel()}.");
            }
            $driver = $t->driver && $t->driver_notified_at ? $t->driver : null;
            $t->forceFill(['vehicle_id' => null, 'driver_id' => null, 'status' => 'confirmed', 'assigned_at' => null, 'driver_notified_at' => null, 'reminder_sent_at' => null])->save();
            ActivityLogger::log('transport', 'unassign', "Trip {$t->trip_no} un-assigned", $t);

            return [$t, $driver];
        });
        if ($driver) {
            self::message($t, 'cancelled', 'driver', $driver);
        }

        return $t;
    }

    // ───────────────────────── on the road ─────────────────────────

    public static function start(TransportTrip $t, ?int $odo = null): TransportTrip
    {
        if ($odo !== null && $odo < 0) {
            throw new RuntimeException('Enter the odometer reading in km.');
        }

        return DB::transaction(function () use ($t, $odo) {
            $t = TransportTrip::with('vehicle')->whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->status !== 'assigned') {
                throw new RuntimeException($t->vehicle_id ? "{$t->trip_no} is {$t->statusLabel()}." : 'Assign a vehicle and driver first.');
            }
            $t->forceFill(['status' => 'en_route', 'started_at' => now(), 'odo_start' => $odo ?? $t->vehicle?->odometer])->save();
            ActivityLogger::log('transport', 'start', "Trip {$t->trip_no} left".($odo !== null ? " – odometer {$odo} km" : ''), $t);

            return $t;
        });
    }

    public static function onboard(TransportTrip $t): TransportTrip
    {
        return DB::transaction(function () use ($t) {
            $t = TransportTrip::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->status !== 'en_route') {
                throw new RuntimeException("{$t->trip_no} is {$t->statusLabel()}.");
            }
            $t->forceFill(['status' => 'onboard', 'onboard_at' => now()])->save();
            ActivityLogger::log('transport', 'onboard', "Trip {$t->trip_no}: {$t->passenger_name} on board", $t);

            return $t;
        });
    }

    /**
     * Finish the trip: closing odometer, extras (waiting / tolls on the guest), extra km from the tariff, then
     * charge – room folio, walk-in invoice paid by one method or split tenders, or nothing for complimentary.
     */
    public static function complete(TransportTrip $t, array $d = []): TransportTrip
    {
        return DB::transaction(function () use ($t, $d) {
            // Completion posts payments/charges, so serialize on the trip before
            // validating its state or calculating any billable amount.
            $t = TransportTrip::with('tariff')->whereKey($t->id)->lockForUpdate()->firstOrFail();
            if (! in_array($t->status, ['assigned', 'en_route', 'onboard'], true)) {
                throw new RuntimeException("{$t->trip_no} is {$t->statusLabel()}.");
            }
            $odoEnd = isset($d['odo_end']) && $d['odo_end'] !== '' && $d['odo_end'] !== null ? (int) $d['odo_end'] : null;
            $odoStart = isset($d['odo_start']) && $d['odo_start'] !== '' && $d['odo_start'] !== null ? (int) $d['odo_start'] : $t->odo_start;
            if ($odoEnd !== null && $odoStart !== null && $odoEnd < $odoStart) {
                throw new RuntimeException("The closing odometer ({$odoEnd}) is below the opening reading ({$odoStart}).");
            }
            if ($odoEnd !== null && $odoStart !== null && $odoEnd - $odoStart > 2000) {
                throw new RuntimeException('More than 2,000 km on one trip – please check the odometer readings.');
            }
            $extra = max(0, round((float) ($d['extra_amount'] ?? 0), 2));
            $note = trim((string) ($d['extra_note'] ?? ''));
            $km = $odoEnd !== null && $odoStart !== null ? $odoEnd - $odoStart : null;
            $tariff = $t->tariff;
            if ($km !== null && $tariff && (float) $tariff->per_km > 0 && $km > $tariff->included_km) {
                $kmExtra = round(($km - $tariff->included_km) * (float) $tariff->per_km, 2);
                $extra = round($extra + $kmExtra, 2);
                $note = trim($note.($note ? '; ' : '').($km - $tariff->included_km).' extra km × '.money($tariff->per_km));
            }
            $total = round((float) $t->amount + $extra, 2);
            $pm = ! empty($d['payment_mode_id']) ? (int) $d['payment_mode_id'] : null;
            $payments = array_values($d['payments'] ?? []);
            if ($payments && $t->billing !== 'direct') {
                throw ValidationException::withMessages(['payments' => 'Split tender is available only for direct payment.']);
            }
            if ($payments && (count($payments) < 2 || count($payments) > 10)) {
                throw ValidationException::withMessages(['payments' => 'Use between two and ten payment tenders.']);
            }
            if ($payments && $total <= 0) {
                throw ValidationException::withMessages(['payments' => 'There is no balance to settle by direct payment.']);
            }
            if ($payments && $pm) {
                throw ValidationException::withMessages(['payments' => 'Use either one payment mode or split tender lines, not both.']);
            }
            if ($t->billing === 'direct' && $total > 0) {
                if ($payments) {
                    foreach ($payments as $i => $line) {
                        $lineAmount = round((float) ($line['amount'] ?? 0), 2);
                        if ($lineAmount <= 0) {
                            throw ValidationException::withMessages(["payments.{$i}.amount" => 'Enter a positive tender amount.']);
                        }
                        if (! PaymentMode::where('is_active', true)->whereKey((int) ($line['payment_mode_id'] ?? 0))->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->exists()) {
                            throw ValidationException::withMessages(["payments.{$i}.payment_mode_id" => 'Choose an active direct payment mode.']);
                        }
                    }
                } elseif (! $pm || ! PaymentMode::where('is_active', true)->whereKey($pm)->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->exists()) {
                    throw ValidationException::withMessages(['payment_mode_id' => 'Choose how the guest paid (cash, card, UPI / mobile money or bank).']);
                }
            }

            $t->forceFill(['status' => 'completed', 'completed_at' => now(), 'started_at' => $t->started_at ?? $t->busy_from->min(now()),
                'odo_start' => $odoStart, 'odo_end' => $odoEnd, 'extra_amount' => $extra, 'extra_note' => $note !== '' ? mb_substr($note, 0, 150) : null, 'total' => $total])->save();
            if ($odoEnd !== null && $t->vehicle_id) {
                $vehicle = Vehicle::whereKey($t->vehicle_id)->lockForUpdate()->first();
                if ($vehicle && $odoEnd > $vehicle->odometer) {
                    $vehicle->forceFill(['odometer' => $odoEnd])->save();
                }
            }
            if ($t->billing === 'room' && $total > 0) {
                if ($t->folio_transaction_id) {
                    // Fare already on the bill – only any new extras remain to post.
                    if ($extra > 0) {
                        $folio = $t->folio_id ? \App\Models\Folio::whereKey($t->folio_id)->lockForUpdate()->first() : null;
                        if (! $folio || $folio->status !== 'open') {
                            throw new RuntimeException("The guest's bill {$folio?->folio_no} is already settled – collect the extra ".money($extra).' directly (change billing isn\'t possible after check-out) or leave the extra at 0.');
                        }
                        FolioService::charge($folio, 'transport', "Transport {$t->trip_no} – extras".($t->extra_note ? " ({$t->extra_note})" : ''), $extra, true,
                            ['source_type' => 'transport', 'source_id' => $t->id, 'reference' => $t->trip_no]);
                    }
                } else {
                    self::postCharge($t);
                }
            } elseif ($t->billing === 'direct' && $total > 0) {
                $name = preg_split('/\s+/', trim($t->passenger_name), 2);
                $guestId = $t->guest_id ?? Guest::create(['first_name' => $name[0], 'last_name' => $name[1] ?? null, 'phone' => $t->phone])->id;
                $folio = FolioService::openStandalone(['guest_id' => $guestId, 'type' => 'walk_in']);
                $tx = FolioService::charge($folio, 'transport', self::chargeText($t), $total, true, ['source_type' => 'transport', 'source_id' => $t->id, 'reference' => $t->trip_no]);
                $amountDue = round((float) $tx->amount + (float) $tx->tax_amount, 2);
                if ($payments) {
                    $allocated = round(array_sum(array_map(fn ($line) => round((float) $line['amount'], 2), $payments)), 2);
                    if (abs($allocated - $amountDue) > 0.005) {
                        throw ValidationException::withMessages(['payments' => 'Payment tenders must total '.money($amountDue).' to settle the trip.']);
                    }
                    foreach ($payments as $line) {
                        $lineReference = trim((string) ($line['reference'] ?? '')) ?: $t->trip_no;
                        if (filled($line['momo_network'] ?? null) || filled($line['momo_phone'] ?? null)) {
                            $lineReference = mb_substr(trim(implode(' · ', array_filter([
                                $line['momo_network'] ?? null, $line['momo_phone'] ?? null, $lineReference,
                            ]))), 0, 80);
                        }
                        FolioService::payment($folio, round((float) $line['amount'], 2), (int) $line['payment_mode_id'], $lineReference, 'settlement', 'payment', [
                            'bank_name' => $line['cheque_bank_name'] ?? null, 'number' => $line['cheque_number'] ?? null, 'bank_branch' => $line['cheque_bank_branch'] ?? null,
                        ]);
                    }
                } else {
                    FolioService::payment($folio, $amountDue, $pm, ($d['reference'] ?? null) ?: $t->trip_no, 'settlement', 'payment', [
                        'bank_name' => $d['cheque_bank_name'] ?? null, 'number' => $d['cheque_number'] ?? null, 'bank_branch' => $d['cheque_bank_branch'] ?? null,
                    ]);
                }
                FolioService::close($folio->fresh());
                $t->forceFill(['folio_id' => $folio->id, 'folio_transaction_id' => $tx->id, 'payment_mode_id' => $payments ? null : $pm, 'guest_id' => $guestId])->save();
            }
            if ($t->guest_request_id && ($req = GuestRequest::find($t->guest_request_id)) && $req->isOpen()) {
                GuestPortalService::transition($req, 'done', "Transfer completed – {$t->trip_no}");
            }
            ActivityLogger::log('transport', 'complete', "Trip {$t->trip_no} completed".($t->km() !== null ? " – {$t->km()} km" : '').($total > 0 && $t->billing !== 'complimentary' ? ' · '.money($total).' '.($t->billing === 'room' ? 'to room bill' : 'paid') : ($t->billing === 'complimentary' ? ' · complimentary' : '')), $t);

            return $t->fresh(['vehicle', 'driver', 'folio']);
        });
    }

    protected static function chargeText(TransportTrip $t): string
    {
        return "Transport {$t->trip_no} – {$t->typeLabel()} ".$t->scheduled_at->format('d M H:i').' '.mb_substr($t->route(), 0, 90);
    }

    /** Post a room-billed trip's fare to the stay's folio (once). */
    public static function postCharge(TransportTrip $t, bool $noShowFee = false): ?\App\Models\FolioTransaction
    {
        return DB::transaction(function () use ($t, $noShowFee) {
            $t = TransportTrip::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if (! $t->unbilled() && ! ($noShowFee && $t->billing === 'room' && ! $t->folio_transaction_id && (float) $t->total > 0)) {
                return null;
            }
            $stay = $t->reservation_room_id ? ReservationRoom::whereKey($t->reservation_room_id)->first() : null;
            if (! $stay || ! in_array($stay->status, ['reserved', 'checked_in'], true)) {
                throw new RuntimeException("The stay for {$t->trip_no} is closed – bill the trip directly instead.");
            }
            $folio = FolioService::openForStay($stay);
            if ($folio->status !== 'open') {
                throw new RuntimeException("Folio {$folio->folio_no} is closed.");
            }
            $tx = FolioService::charge($folio, 'transport', ($noShowFee ? 'No-show fee – ' : '').self::chargeText($t), (float) $t->total, true, ['source_type' => 'transport', 'source_id' => $t->id, 'reference' => $t->trip_no]);
            $t->forceFill(['folio_id' => $folio->id, 'folio_transaction_id' => $tx->id])->save();
            ActivityLogger::log('transport', 'charge', "Trip {$t->trip_no}: ".money($t->total)." posted to {$folio->folio_no}", $t);

            return $tx;
        });
    }

    /** Room-billed trips of a stay that aren't on the bill yet (shown at check-out). */
    public static function pendingForStay(int $stayId): Collection
    {
        return TransportTrip::where('reservation_room_id', $stayId)->where('billing', 'room')->whereNull('folio_transaction_id')
            ->whereNotIn('status', ['cancelled', 'no_show'])->where('total', '>', 0)->orderBy('scheduled_at')->get();
    }

    /** Check-out: put every pending room-billed trip on the final bill (a departure drop is usually after check-out). */
    public static function postPendingForStay(ReservationRoom $stay): int
    {
        $n = 0;
        foreach (self::pendingForStay($stay->id) as $t) {
            if (self::postCharge($t)) {
                $n++;
            }
        }

        return $n;
    }

    public static function noShow(TransportTrip $t, string $reason, bool $charge = false): TransportTrip
    {
        return DB::transaction(function () use ($t, $reason, $charge) {
            $t = TransportTrip::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if (! in_array($t->status, ['confirmed', 'assigned', 'en_route', 'onboard'], true)) {
                throw new RuntimeException("{$t->trip_no} is {$t->statusLabel()}.");
            }
            if ($t->busy_from->gt(now()->addMinutes(30))) {
                throw new RuntimeException('The trip hasn\'t started yet – cancel it instead.');
            }
            $t->forceFill(['status' => 'no_show', 'cancel_reason' => mb_substr($reason, 0, 190), 'completed_at' => now()])->save();
            if ($charge && $t->billing === 'room' && ! $t->folio_transaction_id && (float) $t->total > 0) {
                self::postCharge($t, true);
            } elseif (! $charge && $t->folio_transaction_id && $t->billing === 'room') {
                self::voidCharge($t, 'Transport no-show');
            }
            if ($t->guest_request_id && ($req = GuestRequest::find($t->guest_request_id)) && $req->isOpen()) {
                GuestPortalService::transition($req, 'decline', 'Guest was not at the pick-up point');
            }
            ActivityLogger::log('transport', 'no_show', "Trip {$t->trip_no} no-show: {$reason}".($charge ? ' – fare charged' : ''), $t);

            return $t;
        });
    }

    public static function cancel(TransportTrip $t, string $reason, bool $notify = true): TransportTrip
    {
        [$t, $guestNotify, $driver] = DB::transaction(function () use ($t, $reason, $notify) {
            $t = TransportTrip::with('driver')->whereKey($t->id)->lockForUpdate()->firstOrFail();
            if (! $t->isPlanned()) {
                throw new RuntimeException("{$t->trip_no} is {$t->statusLabel()} and can't be cancelled.");
            }
            if ($t->folio_transaction_id) {
                self::voidCharge($t, 'Transport cancelled');
            }
            $guestNotify = $notify && ($t->guest_notified_at || $t->source === 'portal');
            $driver = $t->driver && $t->driver_notified_at ? $t->driver : null;
            $t->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 190)])->save();
            if ($t->guest_request_id && ($req = GuestRequest::find($t->guest_request_id)) && $req->isOpen()) {
                GuestPortalService::transition($req, 'decline', 'Transfer cancelled: '.$reason);
            }
            ActivityLogger::log('transport', 'cancel', "Trip {$t->trip_no} cancelled: {$reason}", $t);

            return [$t, $guestNotify, $driver];
        });
        if ($guestNotify) {
            self::message($t, 'cancelled');
        }
        if ($driver) {
            self::message($t, 'cancelled', 'driver', $driver);
        }

        return $t;
    }

    protected static function voidCharge(TransportTrip $t, string $reason): void
    {
        $tx = $t->folioTransaction;
        if (! $tx || $tx->is_void) {
            return;
        }
        if ($t->folio?->status !== 'open') {
            throw new RuntimeException("The fare is on the settled bill {$t->folio?->folio_no} – issue a credit note from Billing instead.");
        }
        FolioService::void($tx, "{$reason} {$t->trip_no}");
        $t->forceFill(['folio_id' => null, 'folio_transaction_id' => null])->save();
    }

    /** Reservation cancelled / no-show → its planned trips go too. */
    public static function onReservationClosed(Reservation $r): int
    {
        $n = 0;
        foreach (TransportTrip::where('reservation_id', $r->id)->whereIn('status', TransportTrip::PLANNED)->get() as $t) {
            try {
                self::cancel($t, 'Reservation '.str_replace('_', '-', $r->status), true);
                $n++;
            } catch (RuntimeException $e) {
                report($e);
            }
        }

        return $n;
    }

    // ───────────────────────── messages ─────────────────────────

    /** Guest / driver message. Kinds: assigned, confirmed, changed, cancelled, received, reminder (driver). */
    public static function message(TransportTrip $t, string $kind, string $to = 'guest', ?Driver $driver = null): bool
    {
        $h = hotel();
        $t->loadMissing('vehicle', 'driver');
        $when = $t->scheduled_at->format('D d M').' at '.$t->scheduled_at->format('H:i');
        $what = match ($t->type) {
            'pickup' => 'pick-up from '.$t->pickup_point, 'drop' => 'drop to '.$t->dropoff_point, default => 'car to '.$t->dropoff_point,
        };
        $car = $t->vehicle ? trim(($t->vehicle->color ? $t->vehicle->color.' ' : '').$t->vehicle->name).' '.$t->vehicle->reg_no : null;
        if ($to === 'driver') {
            $d = $driver ?? $t->driver;
            if (! $d?->phone) {
                return false;
            }
            $sheet = "{$t->trip_no} · {$t->typeLabel()} · ".$t->scheduled_at->format('D d M H:i').($t->flight_no ? " · {$t->flight_no}" : '')
                ." · {$t->passenger_name} ({$t->pax} pax".($t->bags ? ", {$t->bags} bags" : '').')'.($t->phone ? " {$t->phone}" : '')
                ." · {$t->route()}".($t->vehicle ? " · {$t->vehicle->code}" : '').($t->type === 'pickup' ? ' · Leave by '.$t->busy_from->format('H:i').'. Name board: '.$t->nameBoard() : '')
                .($t->notes ? " · Note: {$t->notes}" : '');
            $text = match ($kind) {
                'assigned' => "New trip for you: {$sheet}",
                'changed' => "Trip changed: {$sheet}",
                'reminder' => 'Reminder – leave at '.$t->busy_from->format('H:i').": {$sheet}",
                'cancelled' => "Trip {$t->trip_no} (".$t->scheduled_at->format('D d M H:i').", {$t->passenger_name}) is cancelled or given to another driver. Thank you.",
                default => $sheet,
            };
            $log = MessageService::send($d->phone, $text, MessageService::defaultChannel(), 'transport_driver_'.$kind, $t);
            if ($log->status === 'sent' && in_array($kind, ['assigned', 'changed'], true)) {
                $t->forceFill(['driver_notified_at' => now()])->saveQuietly();
            }

            return $log->status === 'sent';
        }

        $first = explode(' ', trim(preg_replace('/^(Mr|Mrs|Ms|Miss|Dr|Prof)\.?\s+/i', '', $t->passenger_name)))[0];
        $text = match ($kind) {
            'assigned' => "Dear {$first}, your {$what} on {$when}".($t->flight_no ? " (flight {$t->flight_no})" : '')." is confirmed. Driver: {$t->driver?->name}, {$t->driver?->phone}".($car ? ". Car: {$car}" : '').'.'
                .($t->type === 'pickup' ? ' Look for the name board "'.$t->nameBoard().'" at arrivals.' : ' Please be at the lobby 10 minutes before.')." Ref {$t->trip_no} – {$h->name}",
            'confirmed' => "Dear {$first}, your {$what} on {$when} is confirmed (ref {$t->trip_no}). We'll send the driver's details before the trip. – {$h->name}",
            'received' => "Dear {$first}, we have received your request for a {$what} on {$when} (ref {$t->trip_no}). We'll confirm shortly. – {$h->name}",
            'changed' => "Dear {$first}, your transfer {$t->trip_no} is now on {$when}".($t->driver ? " with {$t->driver->name}, {$t->driver->phone}" : '').". – {$h->name}",
            'cancelled' => "Dear {$first}, your {$what} on {$when} (ref {$t->trip_no}) has been cancelled. Need a car after all? Just call us. – {$h->name}",
            default => "{$t->trip_no}: {$what} on {$when}",
        };
        $email = $t->guest?->email;
        $log = $t->phone
            ? MessageService::send($t->phone, $text, MessageService::defaultChannel(), 'transport_'.$kind, $t)
            : ($email ? MailService::send($email, new GuestMessage(match ($kind) {
                'assigned' => 'Your driver details – '.$t->scheduled_at->format('d M H:i'), 'cancelled' => 'Transfer cancelled – '.$t->trip_no, default => 'Your transfer '.$t->trip_no,
            }, $text, $h), 'transport_'.$kind, $t) : null);
        if (! $log) {
            return false;
        }
        if ($log->status === 'sent' && in_array($kind, ['assigned', 'confirmed', 'received', 'changed'], true)) {
            $t->forceFill(['guest_notified_at' => now()])->saveQuietly();
        }

        return $log->status === 'sent';
    }

    // ───────────────────────── scheduled run, board & alerts ─────────────────────────

    /** Every 5 minutes: driver reminders, expire stale requests. */
    public static function runDue(): array
    {
        $c = self::cfg();
        $out = ['reminders' => 0, 'expired' => 0];
        if ($c['driver_reminder'] > 0) {
            $due = TransportTrip::with('driver', 'vehicle')->where('status', 'assigned')->whereNull('reminder_sent_at')
                ->where('busy_from', '>', now())->where('busy_from', '<=', now()->addMinutes($c['driver_reminder']))->get();
            foreach ($due as $t) {
                $t->forceFill(['reminder_sent_at' => now()])->saveQuietly();
                if (self::message($t, 'reminder', 'driver')) {
                    $out['reminders']++;
                }
            }
        }
        if ($c['request_expiry_hours'] > 0) {
            foreach (TransportTrip::where('status', 'requested')->where('scheduled_at', '<', now()->subHours($c['request_expiry_hours']))->get() as $t) {
                self::cancel($t, 'Request not confirmed in time', false);
                $out['expired']++;
            }
        }

        return $out;
    }

    /** Things the desk should act on (nav badge): requests to confirm, trips leaving soon without a car, overdue trips, guest transport requests. */
    public static function alerts(): array
    {
        $c = self::cfg();
        $soon = now()->addHours(max(1, $c['alert_hours']));
        $papers = 0;
        $expired = 0;
        foreach (Vehicle::where('is_active', true)->get() as $v) {
            $expired += count($v->expiredPapers()) ? 1 : 0;
            $papers += count($v->expiringPapers($c['paper_days'])) ? 1 : 0;
        }
        $lic = Driver::where('is_active', true)->whereNotNull('licence_until')->whereDate('licence_until', '<=', now()->addDays($c['paper_days'])->toDateString())->count();

        return [
            'requested' => TransportTrip::where('status', 'requested')->count(),
            'unassigned' => TransportTrip::where('status', 'confirmed')->where('busy_from', '<=', $soon)->where('busy_from', '>=', now()->subHours(6))->count(),
            'overdue' => TransportTrip::whereIn('status', TransportTrip::ACTIVE)->where('busy_to', '<', now()->subHour())->count()
                + TransportTrip::where('status', 'assigned')->where('busy_from', '<', now()->subMinutes(30))->where('busy_from', '>=', now()->subHours(12))->count(),
            'guest_requests' => GuestRequest::where('category', 'transport')->whereIn('status', GuestRequest::OPEN)->whereNull('linked_type')->count(),
            'papers_expired' => $expired, 'papers_due' => $papers, 'licences' => $lic,
        ];
    }

    public static function attentionCount(): int
    {
        $a = self::alerts();

        return $a['requested'] + $a['unassigned'] + $a['overdue'] + $a['guest_requests'];
    }

    public static function dayStats(Carbon $date): array
    {
        $q = TransportTrip::whereDate('scheduled_at', $date->toDateString());
        $all = (clone $q)->get(['id', 'type', 'status', 'pax', 'billing', 'total', 'vehicle_id']);
        $live = $all->whereNotIn('status', ['cancelled', 'no_show']);

        return [
            'trips' => $live->count(), 'pax' => (int) $live->sum('pax'),
            'pickups' => $live->where('type', 'pickup')->count(), 'drops' => $live->where('type', 'drop')->count(), 'transfers' => $live->where('type', 'transfer')->count(),
            'unassigned' => $live->whereIn('status', ['requested', 'confirmed'])->count(), 'on_road' => $live->whereIn('status', TransportTrip::ACTIVE)->count(),
            'done' => $live->where('status', 'completed')->count(), 'lost' => $all->whereIn('status', ['cancelled', 'no_show'])->count(),
            'revenue' => round((float) $live->where('billing', '!=', 'complimentary')->sum('total'), 2),
            'vehicles_used' => $live->whereNotNull('vehicle_id')->pluck('vehicle_id')->unique()->count(),
        ];
    }

    public static function driverFor(?int $userId): ?Driver
    {
        return $userId ? Driver::where('user_id', $userId)->where('is_active', true)->first() : null;
    }
}
