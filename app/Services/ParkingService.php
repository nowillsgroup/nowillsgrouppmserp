<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\ParkingCharge;
use App\Models\ParkingPermit;
use App\Models\ParkingSession;
use App\Models\ParkingVehicle;
use App\Models\ParkingZone;
use App\Models\PaymentMode;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * v6.6 Parking & valet.
 *  - Entry: a ticket per vehicle in a zone (capacity enforced). In-house guests self-park or use the valet; visitors pay
 *    by the hour at the exit; permit holders (staff, tenants, contractors) park free while the permit is valid.
 *  - Valet: key tag + intake condition check (marked panels, fuel, valuables); the guest calls for the car at the desk,
 *    by phone or by scanning the QR on the ticket → requested → ready at the door → handed over, timed against the SLA.
 *  - Charges: in-house guest parking posts per night to the room folio with the night audit (idempotent per night);
 *    at the exit any night not yet posted, the visitor tariff (free minutes, hourly rate, daily cap, less outlet
 *    validation hours, plus a valet fee) and a lost-ticket fee go to the room folio or are paid there and then.
 *    Category "parking" → Parking & Valet Revenue (USALI other operated department "Parking").
 */
class ParkingService
{
    public const DEFAULTS = [
        'parking_guest_rate' => '0', 'parking_valet_rate' => '50', 'parking_free_minutes' => '30', 'parking_hour_rate' => '10', 'parking_daily_max' => '60',
        'parking_valet_fee' => '20', 'parking_lost_fee' => '100', 'parking_validation_max' => '3', 'parking_sla_minutes' => '10', 'parking_overstay_hours' => '24',
        'parking_full_pct' => '90', 'parking_audit_post' => '1',
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
        self::flush();
        if (! ParkingZone::exists()) {
            foreach ([['P1', 'Basement car park', 'guest', 40, true, 2], ['V1', 'Valet compound', 'valet', 25, true, 0], ['P2', 'Visitor car park', 'visitor', 30, false, 0], ['S1', 'Staff yard', 'staff', 20, false, 0]] as $i => [$c, $n, $t, $cap, $cov, $ev]) {
                ParkingZone::create(['code' => $c, 'name' => $n, 'type' => $t, 'capacity' => $cap, 'is_covered' => $cov, 'ev_points' => $ev, 'sort' => $i + 1]);
            }
        }
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'.Tenant::id()] ??= [
            'guest_rate' => (float) setting('parking_guest_rate', '0'),
            'valet_rate' => (float) setting('parking_valet_rate', '50'),
            'free_minutes' => (int) setting('parking_free_minutes', '30'),
            'hour_rate' => (float) setting('parking_hour_rate', '10'),
            'daily_max' => (float) setting('parking_daily_max', '60'),
            'valet_fee' => (float) setting('parking_valet_fee', '20'),
            'lost_fee' => (float) setting('parking_lost_fee', '100'),
            'validation_max' => (int) setting('parking_validation_max', '3'),
            'sla_minutes' => (int) setting('parking_sla_minutes', '10'),
            'overstay_hours' => (int) setting('parking_overstay_hours', '24'),
            'full_pct' => (int) setting('parking_full_pct', '90'),
            'audit_post' => setting('parking_audit_post', '1') === '1',
        ];
    }

    public static function saveSettings(array $d): void
    {
        $money = ['guest_rate' => 'Guest self-park rate', 'valet_rate' => 'Guest valet rate', 'hour_rate' => 'Visitor hourly rate', 'daily_max' => 'Visitor daily maximum', 'valet_fee' => 'Visitor valet fee', 'lost_fee' => 'Lost-ticket fee'];
        $out = [];
        foreach ($money as $k => $label) {
            $v = $d[$k] ?? null;
            self::need(is_numeric($v) && $v >= 0 && $v <= 100000, "{$label} must be 0 or more.");
            $out['parking_'.$k] = (string) round((float) $v, 2);
        }
        $ints = ['free_minutes' => [0, 240, 'Free minutes are 0 to 240.'], 'validation_max' => [0, 24, 'Validation is 0 to 24 hours.'], 'sla_minutes' => [1, 120, 'The valet retrieval target is 1 to 120 minutes.'],
            'overstay_hours' => [1, 720, 'The visitor overstay alert is 1 to 720 hours.'], 'full_pct' => [50, 100, 'The "nearly full" threshold is 50 to 100 %.']];
        foreach ($ints as $k => [$lo, $hi, $msg]) {
            $v = $d[$k] ?? null;
            self::need(is_numeric($v) && (int) $v == $v && $v >= $lo && $v <= $hi, $msg);
            $out['parking_'.$k] = (string) (int) $v;
        }
        self::need((float) $out['parking_daily_max'] == 0 || (float) $out['parking_daily_max'] >= (float) $out['parking_hour_rate'], 'The daily maximum cannot be below one hour of parking (0 = no cap).');
        $out['parking_audit_post'] = ! empty($d['audit_post']) ? '1' : '0';
        foreach ($out as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('parking', 'settings', 'Parking tariff & valet settings updated');
    }

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    public static function canManage(?User $u): bool
    {
        return (bool) $u?->hasPermission('parking.manage');
    }

    public static function canOperate(?User $u): bool
    {
        return (bool) ($u?->hasPermission('parking.operate') || $u?->hasPermission('parking.manage'));
    }

    /** "gr 1234-21" → GR123421 */
    public static function normalisePlate(?string $p): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $p));
    }

    public static function runners(): Collection
    {
        return User::where('hotel_id', hotel()->id)->where('is_active', true)->orderBy('name')->get()->filter(fn ($u) => self::canOperate($u))->values();
    }

    public static function walkInModes(): Collection
    {
        return PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->orderBy('id')->get();
    }

    // ------------------------------------------------------------------ vehicles & lookups

    protected static function vehicleFor(array $d): ParkingVehicle
    {
        $display = trim(preg_replace('/\s+/', ' ', strtoupper((string) ($d['plate'] ?? ''))));
        $plate = self::normalisePlate($display);
        self::need(strlen($plate) >= 2 && strlen($plate) <= 12, 'Enter the number plate (2 to 12 letters and digits).');
        $v = ParkingVehicle::firstOrNew(['plate' => $plate]);
        $v->plate_display = mb_substr($display, 0, 20);
        foreach (['make' => 40, 'model' => 40, 'colour' => 30, 'owner_name' => 120, 'phone' => 30] as $k => $len) {
            if (filled($d[$k] ?? null)) {
                $v->{$k} = mb_substr(trim((string) $d[$k]), 0, $len);
            }
        }
        if (filled($d['vehicle_type'] ?? null) && isset(ParkingVehicle::TYPES[$d['vehicle_type']])) {
            $v->type = $d['vehicle_type'];
        }
        if (! empty($d['guest_id'])) {
            $v->guest_id = (int) $d['guest_id'];
        }
        $v->save();

        return $v;
    }

    public static function validPermit(ParkingVehicle $v, ?string $date = null): ?ParkingPermit
    {
        $date ??= bdate()->toDateString();

        return ParkingPermit::where('parking_vehicle_id', $v->id)->where('status', 'active')->whereDate('valid_from', '<=', $date)->whereDate('valid_to', '>=', $date)->orderByDesc('valid_to')->first();
    }

    /** Everything the gate needs to know about a plate. */
    public static function lookup(string $plate): array
    {
        $p = self::normalisePlate($plate);
        $v = $p !== '' ? ParkingVehicle::with('guest')->where('plate', $p)->first() : null;
        if (! $v && strlen($p) >= 3) {
            $v = ParkingVehicle::with('guest')->where('plate', 'like', '%'.$p.'%')->orderByDesc('last_seen_at')->first();
        }

        return ['query' => $plate, 'vehicle' => $v, 'active' => $v ? ParkingSession::with('zone')->where('parking_vehicle_id', $v->id)->whereIn('status', ParkingSession::ACTIVE)->first() : null,
            'permit' => $v ? self::validPermit($v) : null, 'visits' => $v ? ParkingSession::where('parking_vehicle_id', $v->id)->where('status', 'out')->count() : 0,
            'last' => $v ? ParkingSession::where('parking_vehicle_id', $v->id)->where('status', 'out')->orderByDesc('exited_at')->first() : null];
    }

    public static function saveVehicle(ParkingVehicle $v, array $d): ParkingVehicle
    {
        foreach (['make' => 40, 'model' => 40, 'colour' => 30, 'owner_name' => 120, 'phone' => 30, 'notes' => 255] as $k => $len) {
            $v->{$k} = filled($d[$k] ?? null) ? mb_substr(trim((string) $d[$k]), 0, $len) : null;
        }
        if (isset(ParkingVehicle::TYPES[$d['vehicle_type'] ?? ''])) {
            $v->type = $d['vehicle_type'];
        }
        $v->save();

        return $v;
    }

    public static function ban(ParkingVehicle $v, bool $ban, ?string $reason, User $u): ParkingVehicle
    {
        self::need(self::canManage($u));
        if ($ban) {
            self::need(filled($reason), 'Give the reason for banning the vehicle.');
        }
        $v->forceFill(['is_banned' => $ban, 'ban_reason' => $ban ? mb_substr((string) $reason, 0, 255) : null])->save();
        ActivityLogger::log('parking', $ban ? 'ban' : 'unban', "Vehicle {$v->plate_display} ".($ban ? "banned: {$reason}" : 'allowed again'), $v);

        return $v;
    }

    // ------------------------------------------------------------------ zones & occupancy

    public static function occupancy(): Collection
    {
        $full = self::cfg()['full_pct'];

        return ParkingZone::withCount('activeSessions')->withCount(['activeSessions as valet_count' => fn ($q) => $q->where('is_valet', true)])
            ->where('is_active', true)->orderBy('sort')->orderBy('code')->get()->map(function ($z) use ($full) {
                $pct = $z->capacity ? round($z->active_sessions_count / $z->capacity * 100, 1) : null;

                return ['zone' => $z, 'parked' => $z->active_sessions_count, 'valet' => $z->valet_count, 'capacity' => $z->capacity, 'free' => max(0, $z->capacity - $z->active_sessions_count),
                    'pct' => $pct, 'state' => $pct === null ? 'open' : ($pct >= 100 ? 'full' : ($pct >= $full ? 'nearly' : 'ok'))];
            });
    }

    public static function saveZone(array $d, ?ParkingZone $z = null): ParkingZone
    {
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        self::need(preg_match('/^[A-Z0-9-]{1,10}$/', $code) === 1, 'The zone code is 1 to 10 letters, digits or dashes.');
        self::need(filled($d['name'] ?? null), 'Name the zone.');
        self::need(isset(ParkingZone::TYPES[$d['type'] ?? '']), 'Choose the zone type.');
        $cap = $d['capacity'] ?? null;
        self::need(is_numeric($cap) && $cap >= 0 && $cap <= 5000, 'Capacity is 0 to 5,000 bays.');
        self::need(! ParkingZone::where('code', $code)->when($z, fn ($q) => $q->whereKeyNot($z->id))->exists(), "Zone code {$code} is already used.");
        $z ??= new ParkingZone;
        $z->fill(['code' => $code, 'name' => mb_substr(trim($d['name']), 0, 80), 'type' => $d['type'], 'capacity' => (int) $cap, 'is_covered' => ! empty($d['is_covered']),
            'ev_points' => max(0, min(500, (int) ($d['ev_points'] ?? 0))), 'notes' => filled($d['notes'] ?? null) ? mb_substr($d['notes'], 0, 255) : null,
            'is_active' => ! array_key_exists('is_active', $d) || ! empty($d['is_active']), 'sort' => (int) ($d['sort'] ?? ($z->sort ?? 0))]);
        if ($z->exists && ! $z->is_active) {
            self::need(! $z->activeSessions()->exists(), 'Move the vehicles out before closing the zone.');
        }
        $z->save();
        ActivityLogger::log('parking', 'zone', "Parking zone {$z->code} {$z->name} saved ({$z->capacity} bays)", $z);

        return $z;
    }

    protected static function assertSpace(ParkingZone $z, ?int $exceptSession = null): void
    {
        self::need($z->is_active, "Zone {$z->code} is closed.");
        if ($z->capacity > 0) {
            $n = ParkingSession::where('parking_zone_id', $z->id)->whereIn('status', ParkingSession::ACTIVE)->when($exceptSession, fn ($q) => $q->whereKeyNot($exceptSession))->count();
            self::need($n < $z->capacity, "{$z->name} is full ({$n} of {$z->capacity} bays) – choose another zone.");
        }
    }

    // ------------------------------------------------------------------ entry

    /**
     * Park a vehicle. $d: zone_id, kind (guest|visitor|staff), plate (+ make, model, colour, vehicle_type, phone),
     * reservation_room_id (guests), is_valet, key_tag, bay, damage[], condition_notes, fuel, valuables, guest_name, notes.
     */
    public static function checkIn(array $d, User $u): ParkingSession
    {
        self::need(self::canOperate($u));
        $kind = $d['kind'] ?? 'visitor';
        self::need(isset(ParkingSession::KINDS[$kind]), 'Choose who is parking.');
        $zone = ParkingZone::find((int) ($d['parking_zone_id'] ?? 0));
        self::need((bool) $zone, 'Choose the zone.');
        $valet = ! empty($d['is_valet']);
        self::need(! ($valet && $kind === 'staff'), 'Permit holders park their own vehicles.');
        self::need($zone->type !== 'staff' || $kind === 'staff', "{$zone->name} is reserved for permit holders.");
        self::need($zone->type !== 'valet' || $valet, "{$zone->name} is the valet compound – tick valet and take the keys.");

        $stay = null;
        if ($kind === 'guest') {
            $stay = ReservationRoom::with(['guest', 'room'])->find((int) ($d['reservation_room_id'] ?? 0));
            self::need((bool) $stay, 'Choose the guest\'s room.');
            self::need($stay->status === 'checked_in' || ($stay->status === 'reserved' && $stay->arrival_date->toDateString() <= bdate()->toDateString()),
                'Guest parking is for in-house guests and today\'s arrivals – park other vehicles as visitors.');
        }
        $key = filled($d['key_tag'] ?? null) ? strtoupper(trim((string) $d['key_tag'])) : null;
        if ($valet) {
            self::need(filled($key), 'Enter the valet key tag number.');
            self::need(! ParkingSession::whereIn('status', ParkingSession::ACTIVE)->where('key_tag', $key)->exists(), "Key tag {$key} is already on a parked car.");
        }
        $damage = array_values(array_intersect(array_keys(ParkingSession::PANELS), (array) ($d['damage'] ?? [])));
        $fuel = filled($d['fuel'] ?? null) ? (string) $d['fuel'] : null;
        self::need($fuel === null || in_array($fuel, ParkingSession::FUEL, true), 'Choose the fuel level.');
        $entered = filled($d['entered_at'] ?? null) ? Carbon::parse($d['entered_at']) : now();
        self::need($entered->lte(now()->addMinute()) && $entered->gte(now()->subDays(30)), 'The entry time cannot be in the future or more than 30 days ago.');

        return DB::transaction(function () use ($d, $u, $kind, $zone, $valet, $stay, $key, $damage, $fuel, $entered) {
            $zone = ParkingZone::lockForUpdate()->findOrFail($zone->id);
            self::assertSpace($zone);
            $v = self::vehicleFor($d + ['guest_id' => $stay?->guest_id]);
            self::need(! $v->is_banned, "Vehicle {$v->plate_display} is banned from the car park".($v->ban_reason ? ": {$v->ban_reason}" : '.').' Call the duty manager.');
            $open = ParkingSession::where('parking_vehicle_id', $v->id)->whereIn('status', ParkingSession::ACTIVE)->first();
            self::need(! $open, "{$v->plate_display} is already parked on ticket {$open?->number} – close that ticket first.");
            $permit = null;
            if ($kind === 'staff') {
                $permit = self::validPermit($v, $entered->toDateString());
                self::need((bool) $permit, "{$v->plate_display} has no valid parking permit – park it as a visitor.");
            }
            $name = filled($d['guest_name'] ?? null) ? trim((string) $d['guest_name']) : ($stay?->guest?->full_name ?? $permit?->holder_name ?? $v->owner_name);
            $s = ParkingSession::create([
                'number' => Numbering::next('parking_ticket'), 'parking_zone_id' => $zone->id, 'parking_vehicle_id' => $v->id, 'plate' => $v->plate_display, 'kind' => $kind,
                'is_valet' => $valet, 'reservation_room_id' => $stay?->id, 'parking_permit_id' => $permit?->id, 'guest_name' => $name ? mb_substr($name, 0, 120) : null,
                'phone' => filled($d['phone'] ?? null) ? mb_substr($d['phone'], 0, 30) : ($stay?->guest?->phone ?? $v->phone),
                'bay' => filled($d['bay'] ?? null) ? mb_substr(strtoupper(trim($d['bay'])), 0, 20) : null, 'key_tag' => $valet ? $key : null,
                'damage' => $valet ? $damage : null, 'condition_notes' => filled($d['condition_notes'] ?? null) ? mb_substr($d['condition_notes'], 0, 500) : null,
                'fuel' => $valet ? $fuel : null, 'valuables' => filled($d['valuables'] ?? null) ? mb_substr($d['valuables'], 0, 200) : null,
                'entered_at' => $entered, 'status' => 'parked', 'parked_by' => $u->id, 'token' => $valet ? Str::random(40) : null,
                'notes' => filled($d['notes'] ?? null) ? mb_substr($d['notes'], 0, 255) : null,
            ]);
            $v->forceFill(['last_seen_at' => $entered])->save();
            ActivityLogger::log('parking', 'in', "{$s->number}: {$v->plate_display} parked in {$zone->code}".($valet ? " by valet, key tag {$key}" : '').($stay ? ' – room '.($stay->room?->room_no ?? '?') : ''), $s);

            return $s;
        });
    }

    public static function move(ParkingSession $s, int $zoneId, ?string $bay, User $u): ParkingSession
    {
        self::need(self::canOperate($u));
        self::need($s->isActive(), "Ticket {$s->number} is closed.");
        $z = ParkingZone::findOrFail($zoneId);
        self::need($z->type !== 'staff' || $s->kind === 'staff', "{$z->name} is reserved for permit holders.");
        self::need($z->type !== 'valet' || $s->is_valet, "{$z->name} is the valet compound.");
        DB::transaction(function () use ($s, $z, $bay) {
            ParkingZone::lockForUpdate()->find($z->id);
            if ($z->id !== $s->parking_zone_id) {
                self::assertSpace($z, $s->id);
            }
            $s->forceFill(['parking_zone_id' => $z->id, 'bay' => filled($bay) ? mb_substr(strtoupper(trim($bay)), 0, 20) : null])->save();
        });
        ActivityLogger::log('parking', 'move', "{$s->number} moved to {$z->code}".($s->bay ? " bay {$s->bay}" : ''), $s);

        return $s->fresh();
    }

    // ------------------------------------------------------------------ valet retrieval

    public static function request(ParkingSession $s, string $via = 'desk', ?User $u = null): ParkingSession
    {
        self::need($s->is_valet, "{$s->plate} is self-parked – no valet retrieval needed.");
        self::need(in_array($via, ['desk', 'phone', 'qr'], true), 'Unknown request channel.');
        if ($s->status === 'requested' || $s->status === 'ready') {
            return $s; // already on its way – the QR page may be pressed twice
        }
        self::need($s->status === 'parked', "Ticket {$s->number} is {$s->statusLabel()}.");
        $s->forceFill(['status' => 'requested', 'requested_at' => now(), 'requested_via' => $via, 'ready_at' => null])->save();
        ActivityLogger::log('parking', 'request', "{$s->number}: {$s->plate} requested".($via === 'qr' ? ' by the guest (QR)' : " ({$via})").($s->key_tag ? " – key tag {$s->key_tag}" : ''), $s);

        return $s;
    }

    public static function ready(ParkingSession $s, User $u, ?int $runnerId = null): ParkingSession
    {
        self::need(self::canOperate($u));
        self::need($s->is_valet, 'Only valet cars are brought round.');
        self::need(in_array($s->status, ['parked', 'requested'], true), "Ticket {$s->number} is {$s->statusLabel()}.");
        $runner = $runnerId ? User::where('hotel_id', hotel()->id)->find($runnerId) : $u;
        $s->forceFill(['status' => 'ready', 'requested_at' => $s->requested_at ?? now(), 'requested_via' => $s->requested_via ?? 'desk', 'ready_at' => now(), 'runner_id' => $runner?->id ?? $u->id])->save();
        ActivityLogger::log('parking', 'ready', "{$s->number}: {$s->plate} at the door after {$s->waitMinutes()} min", $s);

        return $s;
    }

    /** The guest changed their mind – the car goes back to its bay. */
    public static function repark(ParkingSession $s, User $u): ParkingSession
    {
        self::need(self::canOperate($u));
        self::need(in_array($s->status, ['requested', 'ready'], true), "Ticket {$s->number} is {$s->statusLabel()}.");
        $s->forceFill(['status' => 'parked', 'requested_at' => null, 'requested_via' => null, 'ready_at' => null, 'runner_id' => null])->save();
        ActivityLogger::log('parking', 'repark', "{$s->number}: {$s->plate} returned to its bay", $s);

        return $s;
    }

    /** Outlet validation (restaurant, spa, banquet): the first hours of a visitor ticket are free. */
    public static function validate(ParkingSession $s, int $hours, ?string $ref, User $u): ParkingSession
    {
        self::need($u->hasPermission('parking.validate') || self::canOperate($u));
        self::need($s->isActive(), "Ticket {$s->number} is closed.");
        self::need($s->kind === 'visitor', 'Only visitor tickets are validated – guests and permit holders are billed differently.');
        $max = self::cfg()['validation_max'];
        self::need($max > 0, 'Parking validation is switched off in the settings.');
        self::need($hours >= 1 && $hours <= $max, "Validate 1 to {$max} hour(s).");
        self::need(filled($ref), 'Enter the bill / outlet reference being validated.');
        $s->forceFill(['validation_hours' => $hours, 'validation_ref' => mb_substr(trim($ref), 0, 60), 'validated_by' => $u->id])->save();
        ActivityLogger::log('parking', 'validate', "{$s->number}: {$hours} h validated against {$ref}", $s);

        return $s;
    }

    // ------------------------------------------------------------------ charges

    /** Nights already charged for a ticket (Y-m-d list). */
    public static function postedNights(ParkingSession $s): array
    {
        return ParkingCharge::where('parking_session_id', $s->id)->where('kind', 'night')->pluck('night')->map(fn ($n) => Carbon::parse($n)->toDateString())->all();
    }

    public static function nightRate(ParkingSession $s): float
    {
        $c = self::cfg();

        return $s->kind === 'guest' ? ($s->is_valet ? $c['valet_rate'] : $c['guest_rate']) : 0.0;
    }

    /** Visitor tariff for a number of minutes after validation. */
    public static function tariff(int $minutes, int $validationHours = 0): array
    {
        $c = self::cfg();
        $billable = max(0, $minutes - $validationHours * 60);
        if ($billable <= $c['free_minutes']) {
            return ['billable' => $billable, 'hours' => 0, 'amount' => 0.0];
        }
        $days = intdiv($billable, 1440);
        $hours = (int) ceil(($billable % 1440) / 60);
        $cap = $c['daily_max'] > 0 ? $c['daily_max'] : INF;
        $amount = $days * ($c['daily_max'] > 0 ? $c['daily_max'] : 24 * $c['hour_rate']) + min($hours * $c['hour_rate'], $cap);

        return ['billable' => $billable, 'hours' => $days * 24 + $hours, 'amount' => round($amount, 2)];
    }

    /** What the vehicle owes if it leaves at $at. Lines: kind night|exit|lost, night, label, amount. */
    public static function quote(ParkingSession $s, ?Carbon $at = null, bool $lost = false): array
    {
        $c = self::cfg();
        $at ??= now();
        $minutes = (int) floor($s->entered_at->diffInSeconds($at, true) / 60);
        $lines = [];
        if ($s->kind === 'visitor') {
            $t = self::tariff($minutes, (int) $s->validation_hours);
            if ($t['amount'] > 0) {
                $lines[] = ['kind' => 'exit', 'night' => null, 'label' => "Parking {$s->plate} – {$t['hours']} h".($s->validation_hours ? " (after {$s->validation_hours} h validated)" : ''), 'amount' => $t['amount']];
            }
            if ($s->is_valet && $c['valet_fee'] > 0) {
                $lines[] = ['kind' => 'exit', 'night' => null, 'label' => "Valet service {$s->plate}", 'amount' => $c['valet_fee']];
            }
        } elseif ($s->kind === 'guest' && ($rate = self::nightRate($s)) > 0) {
            $posted = self::postedNights($s);
            for ($d = $s->entered_at->copy()->startOfDay(); $d->toDateString() < $at->toDateString(); $d->addDay()) {
                if (! in_array($d->toDateString(), $posted, true)) {
                    $lines[] = ['kind' => 'night', 'night' => $d->toDateString(), 'label' => ($s->is_valet ? 'Valet parking' : 'Parking')." {$s->plate} – night of {$d->format('d M')}", 'amount' => $rate];
                }
            }
        }
        if ($lost && $s->kind !== 'staff' && $c['lost_fee'] > 0) {
            $lines[] = ['kind' => 'lost', 'night' => null, 'label' => "Lost parking ticket {$s->number}", 'amount' => $c['lost_fee']];
        }
        $tax = 0.0;
        foreach ($lines as $l) {
            $tax += TaxService::forCategory('parking', $l['amount'])['amount'];
        }
        $net = round(array_sum(array_column($lines, 'amount')), 2);

        return ['minutes' => $minutes, 'lines' => $lines, 'net' => $net, 'tax' => round($tax, 2), 'total' => round($net + $tax, 2)];
    }

    /** Night audit: tonight's parking for in-house guests goes to their room folio (once per ticket per night). */
    public static function postNightly(?Carbon $date = null): array
    {
        $date ??= bdate()->copy();
        $n = 0;
        $sum = 0.0;
        if (! self::cfg()['audit_post']) {
            return ['posted' => 0, 'amount' => 0.0];
        }
        $rows = ParkingSession::with('stay')->where('kind', 'guest')->whereIn('status', ParkingSession::ACTIVE)->where('entered_at', '<', $date->copy()->addDay()->startOfDay())->orderBy('id')->get();
        foreach ($rows as $s) {
            $rate = self::nightRate($s);
            $stay = $s->stay;
            if ($rate <= 0 || ! $stay || $stay->status !== 'checked_in' || ParkingCharge::where('parking_session_id', $s->id)->where('kind', 'night')->whereDate('night', $date->toDateString())->exists()) {
                continue;
            }
            $folio = FolioService::openForStay($stay);
            $tx = FolioService::charge($folio, 'parking', ($s->is_valet ? 'Valet parking' : 'Parking')." {$s->plate} – night of {$date->format('d M')}", $rate, true,
                ['source_type' => 'parking', 'source_id' => $s->id, 'reference' => $s->number]);
            ParkingCharge::create(['parking_session_id' => $s->id, 'kind' => 'night', 'night' => $date->toDateString(), 'business_date' => bdate()->toDateString(), 'amount' => $rate,
                'folio_id' => $folio->id, 'folio_transaction_id' => $tx->id]);
            $n++;
            $sum += $rate;
        }
        if ($n) {
            ActivityLogger::log('parking', 'nightly', "Parking for {$date->format('d M Y')}: {$n} vehicle(s) charged to room folios – ".money($sum));
        }

        return ['posted' => $n, 'amount' => round($sum, 2)];
    }

    /**
     * The vehicle leaves. $d: lost_ticket, settle (room|pay), payment_mode_id, reference, waive, waive_reason.
     * In-house guests with an open folio default to the room; everyone else pays there and then (walk-in invoice).
     */
    public static function checkOut(ParkingSession $s, array $d, User $u): ParkingSession
    {
        self::need(self::canOperate($u));
        self::need($s->isActive(), "Ticket {$s->number} is already {$s->statusLabel()}.");
        $lost = ! empty($d['lost_ticket']);
        $waive = ! empty($d['waive']);
        if ($waive) {
            self::need(self::canManage($u), 'Only a parking manager can waive parking charges.');
            self::need(filled($d['waive_reason'] ?? null), 'Give the reason for waiving the charge.');
        }

        $payments = array_values($d['payments'] ?? []);
        if ($payments && (count($payments) < 2 || count($payments) > 10 || ! empty($d['payment_mode_id']))) {
            throw ValidationException::withMessages(['payments' => 'Use two to ten tender rows and do not combine split tender with a single payment mode.']);
        }

        DB::transaction(function () use ($s, $d, $u, $lost, $waive, $payments) {
            $s = ParkingSession::with(['stay.guest', 'vehicle'])->lockForUpdate()->findOrFail($s->id);
            self::need($s->isActive(), "Ticket {$s->number} is already {$s->statusLabel()}.");
            $q = self::quote($s, now(), $lost);
            if ($payments && ($waive || $q['net'] <= 0)) {
                throw ValidationException::withMessages(['payments' => 'Split tender is available only when a parking charge is due.']);
            }
            $folio = null;
            $mode = null;
            if ($q['net'] > 0 && ! $waive) {
                $stay = $s->stay;
                $toRoom = $s->kind === 'guest' && $stay && $stay->status === 'checked_in' && ($d['settle'] ?? 'room') === 'room';
                if ($payments && $toRoom) {
                    throw ValidationException::withMessages(['payments' => 'Split tender is available only when settling directly, not when posting to a guest room.']);
                }
                if ($toRoom) {
                    $folio = FolioService::openForStay($stay);
                } else {
                    $mode = (int) ($d['payment_mode_id'] ?? 0);
                    if (! $payments) {
                        self::need($mode && self::walkInModes()->contains('id', $mode), 'Choose a cash, card, mobile money or bank payment mode.');
                    } else {
                        foreach ($payments as $i => $line) {
                            if (! self::walkInModes()->contains('id', (int) ($line['payment_mode_id'] ?? 0))) {
                                throw ValidationException::withMessages(["payments.{$i}.payment_mode_id" => 'Choose a cash, card, mobile money or bank payment mode.']);
                            }
                        }
                    }
                    $gid = $stay?->guest_id ?? $s->vehicle?->guest_id;
                    if (! $gid) {
                        $parts = preg_split('/\s+/', trim((string) ($s->guest_name ?: 'Parking visitor')), 2);
                        $gid = Guest::create(['first_name' => $parts[0], 'last_name' => $parts[1] ?? $s->plate, 'phone' => $s->phone])->id;
                    }
                    $folio = FolioService::openStandalone(['guest_id' => $gid, 'type' => 'walk_in']);
                }
                foreach ($q['lines'] as $l) {
                    $tx = FolioService::charge($folio, 'parking', $l['label'], $l['amount'], true, ['source_type' => 'parking', 'source_id' => $s->id, 'reference' => $s->number]);
                    ParkingCharge::create(['parking_session_id' => $s->id, 'kind' => $l['kind'], 'night' => $l['night'], 'business_date' => bdate()->toDateString(), 'amount' => $l['amount'],
                        'folio_id' => $folio->id, 'folio_transaction_id' => $tx->id]);
                }
                if (! $toRoom) {
                    $amountDue = round($folio->fresh()->balance(), 2);
                    if ($payments) {
                        $allocated = round(array_sum(array_map(fn ($line) => round((float) $line['amount'], 2), $payments)), 2);
                        if (abs($allocated - $amountDue) > 0.005) {
                            throw ValidationException::withMessages(['payments' => 'Payment tenders must total '.money($amountDue).' to settle the parking charge.']);
                        }
                        foreach ($payments as $line) {
                            $lineReference = trim((string) ($line['reference'] ?? '')) ?: $s->number;
                            if (filled($line['momo_network'] ?? null) || filled($line['momo_phone'] ?? null)) {
                                $lineReference = mb_substr(trim(implode(' · ', array_filter([
                                    $line['momo_network'] ?? null, $line['momo_phone'] ?? null, $lineReference,
                                ]))), 0, 80);
                            }
                            FolioService::payment($folio, round((float) $line['amount'], 2), (int) $line['payment_mode_id'], $lineReference, 'settlement', 'payment', [
                                'bank_name' => $line['cheque_bank_name'] ?? null, 'number' => $line['cheque_number'] ?? null, 'bank_branch' => $line['cheque_bank_branch'] ?? null,
                            ]);
                        }
                        $mode = null;
                    } else {
                        $reference = trim(implode(' · ', array_filter([
                            $d['momo_network'] ?? null, $d['momo_phone'] ?? null, filled($d['reference'] ?? null) ? mb_substr($d['reference'], 0, 80) : null,
                        ]))) ?: $s->number;
                        FolioService::payment($folio, $amountDue, $mode, $reference, 'settlement', 'payment', [
                            'bank_name' => $d['cheque_bank_name'] ?? null, 'number' => $d['cheque_number'] ?? null, 'bank_branch' => $d['cheque_bank_branch'] ?? null,
                        ]);
                    }
                    FolioService::close($folio->fresh());
                }
            }
            $s->forceFill(['status' => 'out', 'exited_at' => now(), 'exited_by' => $u->id, 'lost_ticket' => $lost, 'fee' => $waive ? 0 : $q['net'], 'fee_waived' => $waive ? $q['net'] : 0,
                'waive_reason' => $waive ? mb_substr($d['waive_reason'], 0, 190) : null, 'folio_id' => $folio?->id, 'payment_mode_id' => $mode ?: null,
                'ready_at' => $s->is_valet ? ($s->ready_at ?? now()) : null])->save();
            $s->vehicle?->forceFill(['last_seen_at' => now()])->save();
            ActivityLogger::log('parking', 'out', "{$s->number}: {$s->plate} left after {$s->durationLabel()}".($q['net'] > 0 ? ($waive ? ' – '.money($q['net']).' waived: '.$d['waive_reason'] : ' – '.money($q['total']).($folio?->status === 'open' ? " to {$folio->folio_no}" : ' paid')) : ''), $s);
        });

        return $s->fresh(['folio', 'charges']);
    }

    public static function cancel(ParkingSession $s, string $reason, User $u): ParkingSession
    {
        self::need(self::canManage($u), 'Only a parking manager can cancel a ticket.');
        self::need($s->isActive(), "Ticket {$s->number} is already {$s->statusLabel()}.");
        self::need(trim($reason) !== '', 'Give the reason for cancelling the ticket.');
        self::need(! $s->charges()->exists(), 'Nights have already been charged on this ticket – check the vehicle out instead.');
        $s->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 190), 'exited_at' => now(), 'exited_by' => $u->id])->save();
        ActivityLogger::log('parking', 'cancel', "{$s->number} cancelled: {$reason}", $s);

        return $s;
    }

    // ------------------------------------------------------------------ permits

    public static function savePermit(array $d, User $u, ?ParkingPermit $p = null): ParkingPermit
    {
        self::need(self::canManage($u));
        self::need(filled($d['holder_name'] ?? null), 'Enter the permit holder.');
        self::need(isset(ParkingPermit::HOLDERS[$d['holder_type'] ?? '']), 'Choose the holder type.');
        $from = filled($d['valid_from'] ?? null) ? rescue(fn () => Carbon::parse($d['valid_from'])->startOfDay(), null, false) : null;
        $to = filled($d['valid_to'] ?? null) ? rescue(fn () => Carbon::parse($d['valid_to'])->startOfDay(), null, false) : null;
        self::need($from && $to, 'Enter the validity dates.');
        self::need($to->gte($from), 'The permit cannot end before it starts.');
        self::need($from->diffInDays($to) <= 731, 'Permits run for two years at most.');
        $fee = $d['fee'] ?? 0;
        self::need(is_numeric($fee) && $fee >= 0, 'The fee must be 0 or more.');
        $zone = filled($d['parking_zone_id'] ?? null) ? ParkingZone::find((int) $d['parking_zone_id']) : null;

        return DB::transaction(function () use ($d, $u, $p, $from, $to, $fee, $zone) {
            $v = $p ? $p->vehicle : self::vehicleFor($d + ['owner_name' => $d['holder_name']]);
            if ($p && filled($d['plate'] ?? null) && self::normalisePlate($d['plate']) !== $v->plate) {
                $v = self::vehicleFor($d + ['owner_name' => $d['holder_name']]);
            }
            $clash = ParkingPermit::where('parking_vehicle_id', $v->id)->where('status', 'active')->when($p, fn ($q) => $q->whereKeyNot($p->id))
                ->whereDate('valid_from', '<=', $to->toDateString())->whereDate('valid_to', '>=', $from->toDateString())->first();
            self::need(! $clash, "{$v->plate_display} already has permit {$clash?->number} for those dates.");
            $emp = filled($d['employee_id'] ?? null) ? \App\Models\Employee::find((int) $d['employee_id']) : null;
            $p ??= new ParkingPermit(['number' => Numbering::next('parking_permit'), 'created_by' => $u->id, 'status' => 'active']);
            $p->fill(['parking_vehicle_id' => $v->id, 'holder_name' => mb_substr(trim($d['holder_name']), 0, 120), 'holder_type' => $d['holder_type'], 'employee_id' => $emp?->id,
                'parking_zone_id' => $zone?->id, 'valid_from' => $from->toDateString(), 'valid_to' => $to->toDateString(), 'fee' => round((float) $fee, 2),
                'notes' => filled($d['notes'] ?? null) ? mb_substr($d['notes'], 0, 255) : null])->save();
            ActivityLogger::log('parking', 'permit', "Parking permit {$p->number} for {$p->holder_name} ({$v->plate_display}) valid {$from->format('d M Y')} – {$to->format('d M Y')}", $p);

            return $p;
        });
    }

    public static function revokePermit(ParkingPermit $p, string $reason, User $u): ParkingPermit
    {
        self::need(self::canManage($u));
        self::need($p->status === 'active', "Permit {$p->number} is already revoked.");
        self::need(trim($reason) !== '', 'Give the reason for revoking the permit.');
        $p->forceFill(['status' => 'revoked', 'revoke_reason' => mb_substr($reason, 0, 190)])->save();
        ActivityLogger::log('parking', 'revoke', "Parking permit {$p->number} revoked: {$reason}", $p);

        return $p;
    }

    // ------------------------------------------------------------------ dashboard

    public static function kpis(): array
    {
        return self::$memo['kpi'.Tenant::id()] ??= (function () {
            $occ = self::occupancy();
            $cap = $occ->sum('capacity');
            $parked = $occ->sum('parked');
            $b = bdate()->toDateString();
            $dayStart = now()->startOfDay();
            $done = ParkingSession::where('is_valet', true)->whereNotNull('requested_at')->whereNotNull('ready_at')->where('requested_at', '>=', now()->subDays(30))->get(['requested_at', 'ready_at']);
            $waits = $done->map(fn ($s) => $s->requested_at->diffInSeconds($s->ready_at, true) / 60);
            $sla = self::cfg()['sla_minutes'];

            return ['parked' => $parked, 'capacity' => $cap, 'pct' => $cap ? round($parked / $cap * 100, 1) : null,
                'valet' => ParkingSession::whereIn('status', ParkingSession::ACTIVE)->where('is_valet', true)->count(),
                'guests' => ParkingSession::whereIn('status', ParkingSession::ACTIVE)->where('kind', 'guest')->count(),
                'visitors' => ParkingSession::whereIn('status', ParkingSession::ACTIVE)->where('kind', 'visitor')->count(),
                'requested' => ParkingSession::where('status', 'requested')->count(), 'ready' => ParkingSession::where('status', 'ready')->count(),
                'in_today' => ParkingSession::where('status', '!=', 'cancelled')->where('entered_at', '>=', $dayStart)->count(),
                'out_today' => ParkingSession::where('status', 'out')->where('exited_at', '>=', $dayStart)->count(),
                'revenue_today' => (float) ParkingCharge::whereDate('business_date', $b)->sum('amount'),
                'revenue_mtd' => (float) ParkingCharge::whereDate('business_date', '>=', bdate()->copy()->startOfMonth()->toDateString())->whereDate('business_date', '<=', $b)->sum('amount'),
                'avg_wait' => $waits->count() ? round($waits->avg(), 1) : null, 'within_sla' => $waits->count() ? round($waits->filter(fn ($w) => $w <= $sla)->count() / $waits->count() * 100, 1) : null,
                'retrievals' => $waits->count()];
        })();
    }

    public static function revenueTrend(int $days = 14): array
    {
        $from = bdate()->copy()->subDays($days - 1);
        $rows = ParkingCharge::selectRaw('business_date d, kind, sum(amount) a')->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', bdate()->toDateString())->groupBy('business_date', 'kind')->get();
        $out = [];
        for ($d = $from->copy(); $d->lte(bdate()); $d->addDay()) {
            $k = $d->toDateString();
            $day = $rows->filter(fn ($r) => Carbon::parse($r->d)->toDateString() === $k);
            $out[] = ['date' => $d->copy(), 'night' => (float) $day->where('kind', 'night')->sum('a'), 'exit' => (float) $day->whereIn('kind', ['exit', 'lost'])->sum('a'), 'total' => (float) $day->sum('a')];
        }

        return $out;
    }

    public static function alerts(): array
    {
        return self::$memo['alerts'.Tenant::id()] ??= (function () {
            $c = self::cfg();
            $occ = self::occupancy();

            return [
                'overdue' => ParkingSession::where('status', 'requested')->where('requested_at', '<', now()->subMinutes($c['sla_minutes']))->count(),
                'overstay' => ParkingSession::whereIn('status', ParkingSession::ACTIVE)->where('kind', 'visitor')->where('entered_at', '<', now()->subHours($c['overstay_hours']))->count(),
                'departed' => ParkingSession::whereIn('status', ParkingSession::ACTIVE)->where('kind', 'guest')->whereHas('stay', fn ($q) => $q->whereIn('status', ['checked_out', 'cancelled', 'no_show']))->count(),
                'full' => $occ->whereIn('state', ['full', 'nearly'])->map(fn ($o) => $o['zone']->name.' '.$o['pct'].'%')->values()->all(),
                'permits_expiring' => ParkingPermit::where('status', 'active')->whereDate('valid_to', '>=', bdate()->toDateString())->whereDate('valid_to', '<=', bdate()->copy()->addDays(7)->toDateString())->count(),
            ];
        })();
    }

    /** Badge: cars waiting to be brought round + guests who checked out with the car still here + visitor overstays. */
    public static function attention(): int
    {
        if (! can_do('parking.view')) {
            return 0;
        }
        try {
            $a = self::alerts();

            return ParkingSession::where('status', 'requested')->count() + $a['departed'] + $a['overstay'];
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Vehicles of an in-house stay still in the car park – shown at check-out. */
    public static function activeForStay(int $stayId): Collection
    {
        return ParkingSession::where('reservation_room_id', $stayId)->whereIn('status', ParkingSession::ACTIVE)->orderBy('id')->get();
    }
}
