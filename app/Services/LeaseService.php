<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\LeaseCharge;
use App\Models\LeaseInspection;
use App\Models\LeaseMeterReading;
use App\Models\LeaseUnit;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.2 Serviced apartments & long-stay leases: units, the lease life cycle (draft → move-in → notice → move-out →
 * deposit settlement), periodic rent & service-charge invoicing with escalation and pro-rating, metered utilities,
 * late fees, renewals, inspections, and the hotel rooms blocked from nightly sale while leased.
 */
class LeaseService
{
    public const SETTINGS = [
        'lease_bill_ahead_days' => ['Raise rent invoices this many days before the period starts', 7],
        'lease_grace_days' => ['Grace days after the due date before an invoice is in arrears', 5],
        'lease_late_fee_pct' => ['Late payment fee on overdue rent (% of the overdue amount, once; 0 = off)', 0],
        'lease_enforce_rent_cap' => ['Enforce the Rent Act advance-rent cap on residential tenancies (1 = yes)', 1],
        'lease_notice_days' => ['Default notice period (days)', 30],
        'lease_expiry_alert_days' => ['Warn about leases ending within (days)', 60],
        'lease_elec_tariff' => ['Electricity recharge (GH₵ per kWh)', 2.10],
        'lease_water_tariff' => ['Water recharge (GH₵ per m³)', 14.50],
        'lease_wht_rate' => ['Withholding tax a company tenant deducts from rent (%)', 8],
        'lease_deposit_months' => ['Default security deposit (months of rent)', 2],
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::SETTINGS as $k => [$label, $v]) {
            \App\Models\Setting::firstOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        \App\Models\Setting::$version++;
    }

    public static function cfg(string $key): mixed
    {
        return setting($key, self::SETTINGS[$key][1] ?? null);
    }

    public static function today(): string
    {
        return bdate()->toDateString();
    }

    // ───────────────────────── units ─────────────────────────

    public static function saveUnit(array $d, ?LeaseUnit $unit = null): LeaseUnit
    {
        $code = strtoupper(trim($d['code'] ?? ''));
        if ($code === '' || trim($d['name'] ?? '') === '') {
            throw new RuntimeException('Code and name are required.');
        }
        if (LeaseUnit::where('code', $code)->when($unit, fn ($q) => $q->whereKeyNot($unit->id))->exists()) {
            throw new RuntimeException("Unit code {$code} is already used.");
        }
        if (! array_key_exists($d['type'] ?? '', LeaseUnit::TYPES) || ! array_key_exists($d['tax_class'] ?? '', Lease::TAX_CLASSES)) {
            throw new RuntimeException('Choose the unit type and tax class.');
        }
        $roomId = ! empty($d['room_id']) ? (int) $d['room_id'] : null;
        if ($roomId) {
            if (! Room::whereKey($roomId)->exists()) {
                throw new RuntimeException('Choose a room of this property.');
            }
            if (LeaseUnit::where('room_id', $roomId)->where('is_active', true)->when($unit, fn ($q) => $q->whereKeyNot($unit->id))->exists()) {
                throw new RuntimeException('That room is already set up as a lettable unit.');
            }
        }
        if ($unit && $unit->room_id !== $roomId && Lease::where('lease_unit_id', $unit->id)->whereIn('status', ['draft', 'active', 'notice'])->exists()) {
            throw new RuntimeException('The linked room cannot change while the unit has a live lease.');
        }
        $attrs = ['code' => $code, 'name' => mb_substr(trim($d['name']), 0, 100), 'type' => $d['type'], 'room_id' => $roomId, 'block' => $d['block'] ?? null, 'floor' => $d['floor'] ?? null,
            'bedrooms' => (int) ($d['bedrooms'] ?? 1), 'size_sqm' => $d['size_sqm'] ?? null, 'furnished' => (bool) ($d['furnished'] ?? false), 'tax_class' => $d['tax_class'],
            'list_rent' => round((float) ($d['list_rent'] ?? 0), 2), 'service_charge' => round((float) ($d['service_charge'] ?? 0), 2),
            'elec_meter' => $d['elec_meter'] ?? null, 'water_meter' => $d['water_meter'] ?? null, 'amenities' => $d['amenities'] ?? null, 'is_active' => (bool) ($d['is_active'] ?? true)];
        if (isset($d['status']) && in_array($d['status'], ['vacant', 'maintenance'], true) && (! $unit || in_array($unit->status, ['vacant', 'maintenance'], true))) {
            $attrs['status'] = $d['status'];
        }
        if ($unit) {
            $unit->update($attrs);

            return $unit;
        }

        return LeaseUnit::create($attrs);
    }

    // ───────────────────────── hotel rooms blocked by leases ─────────────────────────

    /** Leased hotel rooms overlapping [from, to): collection of [room_id, room_type_id, from, to (exclusive)]. */
    public static function roomBlocks($from, $to): Collection
    {
        $f = Carbon::parse($from)->toDateString();
        $t = Carbon::parse($to)->toDateString();

        return Lease::with('unit.room')->whereIn('status', ['draft', 'active', 'notice'])->whereHas('unit', fn ($q) => $q->whereNotNull('room_id'))
            ->whereDate('start_date', '<', $t)->get()
            ->filter(fn ($l) => $l->lastDay()->copy()->addDay()->toDateString() > $f)
            ->map(fn ($l) => ['room_id' => $l->unit->room_id, 'room_type_id' => $l->unit->room?->room_type_id, 'from' => $l->start_date->toDateString(),
                'to' => $l->lastDay()->copy()->addDay()->toDateString(), 'lease_no' => $l->lease_no])->values();
    }

    public static function blockedRoomIds($from, $to): array
    {
        return self::roomBlocks($from, $to)->pluck('room_id')->unique()->values()->all();
    }

    /** Stays already booked on the unit's hotel room in [from, to] (inclusive last day). */
    protected static function roomConflicts(LeaseUnit $unit, string $from, string $lastDay, ?int $ignoreLeaseId = null): array
    {
        if (! $unit->room_id) {
            return [];
        }
        $to = Carbon::parse($lastDay)->addDay()->toDateString();

        return ReservationRoom::with('reservation')->where('room_id', $unit->room_id)->whereNotIn('status', ['cancelled', 'no_show', 'checked_out'])
            ->whereDate('arrival_date', '<', $to)->whereDate('departure_date', '>', $from)->get()
            ->map(fn ($s) => ($s->reservation?->reservation_no ?? 'stay #'.$s->id).' ('.dmy($s->arrival_date).'–'.dmy($s->departure_date).')')->all();
    }

    // ───────────────────────── lease life cycle ─────────────────────────

    public static function periodEnd(string $start, int $months): string
    {
        return Carbon::parse($start)->addMonthsNoOverflow($months)->subDay()->toDateString();
    }

    /** The most months a residential tenancy may be billed (and paid) ahead: 6, or 1 for tenancies of 6 months or less. */
    public static function residentialMaxCycle(int $termMonths): int
    {
        return $termMonths > 6 ? 6 : 1;
    }

    /**
     * Draft a lease. $d: lease_unit_id, company_id, guest_id, tenant_name, phone, email, id_type, id_no, occupants,
     * tax_class, start_date, term_months, rent, service_charge, cycle_months, deposit_amount, escalation_pct,
     * escalation_months, notice_days, elec_mode, water_mode, elec_tariff, water_tariff, wht_pct, inclusions, auto_renew, notes.
     */
    public static function create(array $d, ?User $u = null, ?string $asOf = null): Lease
    {
        return DB::transaction(function () use ($d, $u, $asOf) {
            // Serialize lease drafting with another lease and with hotel
            // reservation writes for a room-backed unit. The overlap check must
            // run after these locks, not before the transaction starts.
            $unitId = (int) ($d['lease_unit_id'] ?? 0);
            $unit = LeaseUnit::whereKey($unitId)->where('is_active', true)->lockForUpdate()->firstOrFail();
            if ($unit->room_id) {
                Room::whereKey($unit->room_id)->lockForUpdate()->first();
            }
            [$unit, $attrs] = self::terms($d, null, $asOf);
            $l = Lease::create(['lease_no' => Numbering::next('lease'), 'status' => 'draft', 'created_by' => $u?->id] + $attrs);
            if ($unit->status === 'vacant') {
                $unit->update(['status' => 'reserved']);
            }
            ActivityLogger::log('leases', 'created', "Lease {$l->lease_no} drafted – {$l->tenant_name}, {$unit->code} from ".dmy($l->start_date).' for '.$l->term_months.' month(s)', $l);

            return $l;
        });
    }

    /** Terms can be edited while the lease is a draft (the unit stays the same). */
    public static function updateDraft(Lease $l, array $d): void
    {
        if ($l->status !== 'draft') {
            throw new RuntimeException('Only a draft lease can be edited – use renew, notice or a credit note.');
        }
        [, $attrs] = self::terms(['lease_unit_id' => $l->lease_unit_id] + $d, $l);
        $l->update($attrs);
        ActivityLogger::log('leases', 'updated', "Draft lease {$l->lease_no} terms updated", $l);
    }

    /** Validate lease terms; returns [unit, attributes]. */
    protected static function terms(array $d, ?Lease $existing = null, ?string $asOf = null): array
    {
        $unit = LeaseUnit::whereKey($d['lease_unit_id'] ?? 0)->where('is_active', true)->first() ?? throw new RuntimeException('Choose an active unit.');
        if ($unit->status === 'maintenance') {
            throw new RuntimeException("{$unit->code} is under maintenance.");
        }
        $name = trim($d['tenant_name'] ?? '');
        if ($name === '') {
            throw new RuntimeException("The tenant's name is required.");
        }
        $today = $asOf ?? self::today();
        $start = Carbon::parse($d['start_date'] ?? $today)->toDateString();
        if (! $asOf && $start < Carbon::parse($today)->subDays(7)->toDateString()) {
            throw new RuntimeException('A lease cannot start more than 7 days back.');
        }
        $term = (int) ($d['term_months'] ?? 0);
        if ($term < 1 || $term > 60) {
            throw new RuntimeException('The term must be 1 to 60 months.');
        }
        $class = ($d['tax_class'] ?? '') ?: $unit->tax_class;
        if (! array_key_exists($class, Lease::TAX_CLASSES)) {
            throw new RuntimeException('Choose the tax class.');
        }
        $cycle = (int) ($d['cycle_months'] ?? 1);
        if (! array_key_exists($cycle, Lease::CYCLES) || $cycle > $term) {
            throw new RuntimeException('Choose a billing cycle no longer than the term.');
        }
        if ($class === 'residential' && (int) self::cfg('lease_enforce_rent_cap') && $cycle > self::residentialMaxCycle($term)) {
            throw new RuntimeException('The Rent Act 1963 (Act 220) s.25(5) limits advance rent on a residential tenancy '
                .($term > 6 ? 'of more than 6 months to 6 months' : 'of 6 months or less to 1 month').' – bill at most '.strtolower(Lease::CYCLES[self::residentialMaxCycle($term)]).'.');
        }
        $rent = round((float) ($d['rent'] ?? 0), 2);
        if ($rent <= 0) {
            throw new RuntimeException('Enter the monthly rent.');
        }
        foreach (['elec_mode', 'water_mode'] as $k) {
            if (isset($d[$k]) && ! array_key_exists($d[$k], Lease::UTILITY_MODES)) {
                throw new RuntimeException('Choose how utilities are paid.');
            }
        }
        $end = self::periodEnd($start, $term);
        $overlap = Lease::where('lease_unit_id', $unit->id)->whereIn('status', ['draft', 'active', 'notice'])->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->get()
            ->first(fn ($o) => $o->start_date->toDateString() <= $end && $o->lastDay()->toDateString() >= $start);
        if ($overlap) {
            throw new RuntimeException("{$unit->code} is already let from ".dmy($overlap->start_date).' to '.dmy($overlap->lastDay())." ({$overlap->lease_no}).");
        }
        if ($c = self::roomConflicts($unit, $start, $end)) {
            throw new RuntimeException("Room {$unit->room?->room_no} has hotel bookings in that period: ".implode(', ', $c).' – move them first.');
        }
        $esc = round((float) ($d['escalation_pct'] ?? 0), 2);
        $escM = max(1, (int) ($d['escalation_months'] ?? 12));
        $companyId = ! empty($d['company_id']) ? (int) $d['company_id'] : null;

        return [$unit, [
            'lease_unit_id' => $unit->id,
            'company_id' => $companyId,
            'guest_id' => ! empty($d['guest_id']) ? (int) $d['guest_id'] : null,
            'tenant_name' => mb_substr($name, 0, 120),
            'phone' => $d['phone'] ?? null,
            'email' => $d['email'] ?? null,
            'id_type' => $d['id_type'] ?? null,
            'id_no' => $d['id_no'] ?? null,
            'occupants' => $d['occupants'] ?? null,
            'tax_class' => $class,
            'start_date' => $start,
            'end_date' => $end,
            'term_months' => $term,
            'rent' => $rent,
            'service_charge' => round((float) ($d['service_charge'] ?? 0), 2),
            'cycle_months' => $cycle,
            'deposit_amount' => round((float) (($d['deposit_amount'] ?? '') !== '' ? $d['deposit_amount'] : $rent * (int) self::cfg('lease_deposit_months')), 2),
            'escalation_pct' => $esc,
            'escalation_months' => $escM,
            'next_review_on' => $esc > 0 ? Carbon::parse($start)->addMonthsNoOverflow($escM)->toDateString() : null,
            'notice_days' => (int) (($d['notice_days'] ?? '') !== '' ? $d['notice_days'] : self::cfg('lease_notice_days')),
            'elec_mode' => $d['elec_mode'] ?? 'included',
            'water_mode' => $d['water_mode'] ?? 'included',
            'elec_tariff' => ! empty($d['elec_tariff']) ? $d['elec_tariff'] : null,
            'water_tariff' => ! empty($d['water_tariff']) ? $d['water_tariff'] : null,
            'wht_pct' => ($d['wht_pct'] ?? '') !== '' ? (float) $d['wht_pct'] : ($companyId ? (float) self::cfg('lease_wht_rate') : 0),
            'inclusions' => $d['inclusions'] ?? null,
            'auto_renew' => (bool) ($d['auto_renew'] ?? false),
            'notes' => $d['notes'] ?? null,
        ]];
    }

    public static function sign(Lease $l, string $signedBy): void
    {
        if (! in_array($l->status, ['draft', 'active', 'notice'], true)) {
            throw new RuntimeException('The lease is closed.');
        }
        if (trim($signedBy) === '') {
            throw new RuntimeException('Type the name of the person signing for the tenant.');
        }
        $l->update(['signed_by' => mb_substr(trim($signedBy), 0, 120), 'signed_at' => now()]);
        ActivityLogger::log('leases', 'signed', "Lease {$l->lease_no} signed by {$signedBy}", $l);
    }

    /**
     * Move-in: the lease becomes active, opening meter readings are taken for metered utilities, an optional move-in
     * inspection is recorded, and the first period is invoiced.
     */
    public static function activate(Lease $l, array $readings = [], ?array $items = null, ?User $u = null, ?string $asOf = null): void
    {
        $today = $asOf ?? self::today();
        if ($l->status !== 'draft') {
            throw new RuntimeException('Only a draft lease can move in.');
        }
        if ($today < Carbon::parse($l->start_date)->subDays(3)->toDateString()) {
            throw new RuntimeException('Move-in opens 3 days before the start date ('.dmy($l->start_date).').');
        }
        if (! $l->signed_at && ! $asOf) {
            throw new RuntimeException('Record the signed agreement first.');
        }
        $unit = $l->unit;
        if ($c = self::roomConflicts($unit, $l->start_date->toDateString(), $l->end_date->toDateString())) {
            throw new RuntimeException("Room {$unit->room?->room_no} has hotel bookings in the lease period: ".implode(', ', $c).'.');
        }
        foreach (['electricity' => 'elec_mode', 'water' => 'water_mode'] as $meter => $mode) {
            if ($l->{$mode} === 'metered' && ! isset($readings[$meter])) {
                throw new RuntimeException('Take the opening '.strtolower(LeaseMeterReading::METERS[$meter][0]).' meter reading.');
            }
        }
        DB::transaction(function () use ($l, $readings, $items, $u, $today, $unit) {
            $l->update(['status' => 'active', 'moved_in_on' => $today]);
            $unit->update(['status' => 'leased']);
            foreach ($readings as $meter => $value) {
                if ($value !== null && $value !== '') {
                    self::recordReading($unit, $meter, (float) $value, $u, $today, 'opening');
                }
            }
            if ($items) {
                self::inspect($l->refresh(), 'move_in', $items, null, null, $u, $today);
            }
            self::bill($l->refresh(), $u, $today);
        });
        ActivityLogger::log('leases', 'moved_in', "Lease {$l->lease_no} – {$l->tenant_name} moved in to {$unit->code}", $l);
    }

    public static function cancelDraft(Lease $l, string $reason): void
    {
        if ($l->status !== 'draft') {
            throw new RuntimeException('Only a draft lease can be cancelled – live leases end with a move-out.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }
        if (\App\Models\LeasePayment::where('lease_id', $l->id)->whereNull('voided_at')->exists()) {
            throw new RuntimeException('Money has been received on this lease – refund or void it first.');
        }
        $l->update(['status' => 'cancelled', 'end_reason' => mb_substr($reason, 0, 255)]);
        self::syncUnitStatus($l->unit);
        ActivityLogger::log('leases', 'cancelled', "Draft lease {$l->lease_no} cancelled – {$reason}", $l);
    }

    public static function syncUnitStatus(LeaseUnit $unit): void
    {
        if ($unit->status === 'maintenance') {
            return;
        }
        $live = Lease::where('lease_unit_id', $unit->id)->whereIn('status', ['active', 'notice'])->exists();
        $draft = Lease::where('lease_unit_id', $unit->id)->where('status', 'draft')->exists();
        $unit->update(['status' => $live ? 'leased' : ($draft ? 'reserved' : 'vacant')]);
    }

    // ───────────────────────── billing ─────────────────────────

    /** Net amount for a period: whole cycles at the monthly rate; a short final period is pro-rated by days. */
    public static function periodAmount(float $monthly, string $ps, string $pe, int $cycle): float
    {
        $fullEnd = self::periodEnd($ps, $cycle);
        if ($pe >= $fullEnd) {
            return round($monthly * $cycle, 2);
        }
        $full = Carbon::parse($ps)->diffInDays(Carbon::parse($fullEnd)) + 1;
        $days = Carbon::parse($ps)->diffInDays(Carbon::parse($pe)) + 1;

        return round($monthly * $cycle * $days / $full, 2);
    }

    /** Rent review: escalate the rent for periods starting on or after the review date. */
    protected static function escalateFor(Lease $l, string $periodStart, ?User $u): void
    {
        while ((float) $l->escalation_pct > 0 && $l->next_review_on && $periodStart >= $l->next_review_on->toDateString()) {
            $old = (float) $l->rent;
            $new = round($old * (1 + (float) $l->escalation_pct / 100), 2);
            $l->update(['rent' => $new, 'next_review_on' => $l->next_review_on->copy()->addMonthsNoOverflow($l->escalation_months)->toDateString()]);
            ActivityLogger::log('leases', 'escalated', "Lease {$l->lease_no} rent reviewed +{$l->escalation_pct}%: ".money($old).' → '.money($new).' a month', $l);
        }
    }

    /** Invoice every period that starts within the bill-ahead window. Returns the rent charges raised. */
    public static function bill(Lease $l, ?User $u = null, ?string $asOf = null): array
    {
        $today = $asOf ?? self::today();
        if (! $l->isLive()) {
            return [];
        }
        $limit = min(Carbon::parse($today)->addDays((int) self::cfg('lease_bill_ahead_days'))->toDateString(), $l->lastDay()->toDateString());
        $out = [];
        while (true) {
            $ps = $l->billed_to ? $l->billed_to->copy()->addDay()->toDateString() : $l->start_date->toDateString();
            if ($ps > $limit || $ps > $l->lastDay()->toDateString()) {
                break;
            }
            self::escalateFor($l, $ps, $u);
            $pe = min(self::periodEnd($ps, $l->cycle_months), $l->lastDay()->toDateString());
            $label = dmy($ps).' – '.dmy($pe);
            $o = ['date' => min($today, max($today, Carbon::parse($ps)->subDays((int) self::cfg('lease_bill_ahead_days'))->toDateString())), 'period_start' => $ps, 'period_end' => $pe, 'due_date' => $ps];
            DB::transaction(function () use ($l, $u, $ps, $pe, $label, $o, &$out) {
                $out[] = LeaseBillingService::raise($l, 'rent', "Rent {$l->unit->code} – {$label}", self::periodAmount((float) $l->rent, $ps, $pe, $l->cycle_months), $o, $u);
                if ((float) $l->service_charge > 0) {
                    LeaseBillingService::raise($l, 'service_charge', "Service charge {$l->unit->code} – {$label}", self::periodAmount((float) $l->service_charge, $ps, $pe, $l->cycle_months), $o, $u);
                }
                $l->update(['billed_to' => $pe]);
            });
            $l->refresh();
        }

        return $out;
    }

    /** One late fee per overdue rent invoice (setting lease_late_fee_pct). */
    public static function lateFees(?User $u = null, ?string $asOf = null): int
    {
        $pct = (float) self::cfg('lease_late_fee_pct');
        if ($pct <= 0) {
            return 0;
        }
        $today = $asOf ?? self::today();
        $cut = Carbon::parse($today)->subDays((int) self::cfg('lease_grace_days'))->toDateString();
        $n = 0;
        $charged = LeaseCharge::whereNotNull('late_fee_of_id')->whereNull('cancelled_at')->pluck('late_fee_of_id')->all();
        foreach (LeaseCharge::with('lease.unit')->where('type', 'rent')->whereNull('cancelled_at')->whereDate('due_date', '<', $cut)->whereRaw('total - paid > 0.004')->whereNotIn('id', $charged ?: [0])->get() as $c) {
            if (! $c->lease->isLive() && $c->lease->status !== 'ended') {
                continue;
            }
            $fee = round($c->outstanding() * $pct / 100, 2);
            if ($fee >= 0.01) {
                LeaseBillingService::raise($c->lease, 'late_fee', "Late payment fee {$pct}% on {$c->charge_no}", $fee, ['date' => $today, 'due_date' => $today, 'late_fee_of_id' => $c->id], $u);
                $n++;
            }
        }

        return $n;
    }

    // ───────────────────────── utilities ─────────────────────────

    public static function tariff(Lease $l, string $meter): float
    {
        return $meter === 'electricity' ? (float) ($l->elec_tariff ?? self::cfg('lease_elec_tariff')) : (float) ($l->water_tariff ?? self::cfg('lease_water_tariff'));
    }

    /** Record a meter reading; a metered lease is recharged for the consumption since the previous reading. */
    public static function recordReading(LeaseUnit $unit, string $meter, float $reading, ?User $u = null, ?string $date = null, string $kind = 'regular'): LeaseMeterReading
    {
        if (! array_key_exists($meter, LeaseMeterReading::METERS)) {
            throw new RuntimeException('Unknown meter.');
        }
        $date ??= self::today();
        $prev = LeaseMeterReading::where('lease_unit_id', $unit->id)->where('meter', $meter)->where('date', '<=', $date)->orderByDesc('date')->orderByDesc('id')->first();
        if ($prev && $reading < (float) $prev->reading - 0.004) {
            throw new RuntimeException("{$unit->code}: the ".strtolower(LeaseMeterReading::METERS[$meter][0]).' reading '.number_format($reading, 2).' is below the previous one ('.number_format((float) $prev->reading, 2).' on '.dmy($prev->date).').');
        }
        $lease = Lease::where('lease_unit_id', $unit->id)->whereIn('status', ['active', 'notice', 'ended'])->where('start_date', '<=', $date)
            ->orderByDesc('start_date')->get()->first(fn ($l) => $l->status !== 'ended' || $l->lastDay()->toDateString() >= Carbon::parse($date)->subDays(3)->toDateString());
        $cons = $kind === 'opening' || ! $prev ? 0.0 : round($reading - (float) $prev->reading, 2);
        $mode = $meter === 'electricity' ? 'elec_mode' : 'water_mode';
        $rate = $lease ? self::tariff($lease, $meter) : 0;

        return DB::transaction(function () use ($unit, $meter, $reading, $u, $date, $kind, $prev, $lease, $cons, $mode, $rate) {
            $r = LeaseMeterReading::create(['lease_unit_id' => $unit->id, 'lease_id' => $lease?->id, 'meter' => $meter, 'date' => $date, 'reading' => $reading,
                'previous' => $prev?->reading, 'consumption' => $cons, 'rate' => $rate, 'kind' => $kind, 'user_id' => $u?->id]);
            if ($lease && $lease->{$mode} === 'metered' && $cons > 0 && $kind !== 'opening') {
                [$label, $uom] = LeaseMeterReading::METERS[$meter];
                $c = LeaseBillingService::raise($lease, $meter, "{$label} {$unit->code}: ".number_format((float) $prev->reading, 2).' → '.number_format($reading, 2).' = '.number_format($cons, 2)." {$uom} @ GH₵ ".rtrim(rtrim(number_format($rate, 4), '0'), '.'),
                    round($cons * $rate, 2), ['date' => $date, 'due_date' => Carbon::parse($date)->addDays(7)->toDateString(), 'period_start' => $prev->date->toDateString(), 'period_end' => $date], $u);
                $r->update(['lease_charge_id' => $c->id]);
            }

            return $r;
        });
    }

    // ───────────────────────── inspections ─────────────────────────

    /** $items: [{area, item, condition, note, charge}]. Damages on a move-out / periodic inspection are charged to the tenant. */
    public static function inspect(Lease $l, string $kind, array $items, ?string $ack = null, ?string $notes = null, ?User $u = null, ?string $date = null): LeaseInspection
    {
        if (! array_key_exists($kind, LeaseInspection::KINDS)) {
            throw new RuntimeException('Unknown inspection.');
        }
        $rows = collect($items)->filter(fn ($i) => trim($i['item'] ?? '') !== '')->map(fn ($i) => [
            'area' => mb_substr($i['area'] ?? '', 0, 40), 'item' => mb_substr($i['item'], 0, 60),
            'condition' => array_key_exists($i['condition'] ?? '', LeaseInspection::CONDITIONS) ? $i['condition'] : 'good',
            'note' => mb_substr($i['note'] ?? '', 0, 160), 'charge' => round(max(0, (float) ($i['charge'] ?? 0)), 2)])->values();
        if ($rows->isEmpty()) {
            throw new RuntimeException('Record at least one item.');
        }
        if ($rows->contains(fn ($r) => in_array($r['condition'], ['damaged', 'missing'], true) && $r['note'] === '')) {
            throw new RuntimeException('Describe every damaged or missing item.');
        }
        $damages = round($rows->sum('charge'), 2);
        $date ??= self::today();

        return DB::transaction(function () use ($l, $kind, $rows, $ack, $notes, $u, $date, $damages) {
            $ins = LeaseInspection::create(['lease_id' => $l->id, 'kind' => $kind, 'date' => $date, 'items' => $rows->all(), 'damages' => $damages,
                'tenant_ack' => $ack ? mb_substr($ack, 0, 120) : null, 'notes' => $notes ? mb_substr($notes, 0, 500) : null, 'user_id' => $u?->id]);
            if ($damages > 0 && $kind !== 'move_in') {
                $list = $rows->filter(fn ($r) => $r['charge'] > 0)->map(fn ($r) => $r['item'])->implode(', ');
                $c = LeaseBillingService::raise($l, 'damage', LeaseInspection::KINDS[$kind].' inspection – '.mb_substr($list, 0, 110), $damages, ['date' => $date, 'due_date' => $date], $u);
                $ins->update(['lease_charge_id' => $c->id]);
            }
            ActivityLogger::log('leases', 'inspection', LeaseInspection::KINDS[$kind]." inspection {$l->lease_no}".($damages > 0 ? ' – damages '.money($damages) : ''), $ins);

            return $ins;
        });
    }

    // ───────────────────────── notice, renewal, move-out ─────────────────────────

    public static function giveNotice(Lease $l, string $by, string $moveOut, string $reason, ?User $u = null, ?string $noticeOn = null): void
    {
        if ($l->status !== 'active') {
            throw new RuntimeException('Notice can only be given on an active lease.');
        }
        if (! in_array($by, ['tenant', 'landlord'], true) || trim($reason) === '') {
            throw new RuntimeException('Say who gives notice and why.');
        }
        $noticeOn ??= self::today();
        if ($moveOut < $noticeOn) {
            throw new RuntimeException('The move-out date cannot be before the notice date.');
        }
        $min = Carbon::parse($noticeOn)->addDays($l->notice_days)->toDateString();
        if ($moveOut < $min && ! $u?->hasPermission('leases.approve')) {
            throw new RuntimeException("The lease requires {$l->notice_days} days' notice (move-out from ".dmy($min).') – a manager can accept shorter notice.');
        }
        if ($moveOut > $l->end_date->toDateString()) {
            throw new RuntimeException('The move-out date is after the end of the term ('.dmy($l->end_date).').');
        }
        $l->update(['status' => 'notice', 'notice_on' => $noticeOn, 'notice_by' => $by, 'move_out_on' => $moveOut, 'end_reason' => mb_substr($reason, 0, 255), 'auto_renew' => false]);
        ActivityLogger::log('leases', 'notice', "Lease {$l->lease_no}: {$by} gave notice – move-out ".dmy($moveOut).". {$reason}", $l);
    }

    public static function withdrawNotice(Lease $l): void
    {
        if ($l->status !== 'notice') {
            throw new RuntimeException('There is no notice to withdraw.');
        }
        $l->update(['status' => 'active', 'notice_on' => null, 'notice_by' => null, 'move_out_on' => null, 'end_reason' => null]);
        ActivityLogger::log('leases', 'notice_withdrawn', "Lease {$l->lease_no} – notice withdrawn", $l);
    }

    /** Extend the term; a new rent applies to periods not yet invoiced. */
    public static function renew(Lease $l, int $months, ?float $newRent = null, ?User $u = null): void
    {
        if ($l->status !== 'active') {
            throw new RuntimeException($l->status === 'notice' ? 'Notice has been given – withdraw it first.' : 'Only an active lease can be renewed.');
        }
        if ($months < 1 || $months > 60) {
            throw new RuntimeException('Renew for 1 to 60 months.');
        }
        $from = $l->end_date->copy()->addDay()->toDateString();
        $end = self::periodEnd($from, $months);
        if ($c = self::roomConflicts($l->unit, $from, $end)) {
            throw new RuntimeException("Room {$l->unit->room?->room_no} has hotel bookings in the renewal period: ".implode(', ', $c).'.');
        }
        if (Lease::where('lease_unit_id', $l->lease_unit_id)->whereKeyNot($l->id)->whereIn('status', ['draft', 'active', 'notice'])->where('start_date', '<=', $end)->exists()) {
            throw new RuntimeException('Another lease on this unit starts before '.dmy($end).'.');
        }
        $newTerm = $l->term_months + $months;
        if ($l->tax_class === 'residential' && (int) self::cfg('lease_enforce_rent_cap') && $l->cycle_months > self::residentialMaxCycle($newTerm)) {
            throw new RuntimeException('Billing cycle exceeds the Rent Act cap.');
        }
        $old = (float) $l->rent;
        $l->update(['end_date' => $end, 'term_months' => $newTerm] + ($newRent && $newRent > 0 ? ['rent' => round($newRent, 2)] : []));
        ActivityLogger::log('leases', 'renewed', "Lease {$l->lease_no} renewed to ".dmy($end).($newRent ? ' – rent '.money($old).' → '.money($newRent) : ''), $l);
    }

    public static function autoRenewRun(?User $u = null, ?string $asOf = null): array
    {
        $today = $asOf ?? self::today();
        $done = [];
        foreach (Lease::with('unit')->where('status', 'active')->where('auto_renew', true)->where('end_date', '<=', Carbon::parse($today)->addDays(30)->toDateString())->get() as $l) {
            try {
                self::renew($l, min(12, $l->term_months), null, $u);
                $done[] = $l->lease_no;
            } catch (RuntimeException) {
            }
        }

        return $done;
    }

    /**
     * Move-out: closing meter readings (recharged), move-out inspection (damages charged), final rent – periods billed
     * past the move-out date are credited pro-rata, a short final period is billed pro-rata. The deposit is settled next.
     */
    public static function moveOut(Lease $l, string $date, array $readings = [], ?array $items = null, ?string $ack = null, ?User $u = null): void
    {
        if (! $l->isLive()) {
            throw new RuntimeException('The tenant is not in occupation.');
        }
        if ($date < $l->start_date->toDateString() || $date > self::today()) {
            throw new RuntimeException('The move-out date must be between the start of the lease and today.');
        }
        foreach (['electricity' => 'elec_mode', 'water' => 'water_mode'] as $meter => $mode) {
            if ($l->{$mode} === 'metered' && ! isset($readings[$meter])) {
                throw new RuntimeException('Take the closing '.strtolower(LeaseMeterReading::METERS[$meter][0]).' meter reading.');
            }
        }
        DB::transaction(function () use ($l, $date, $readings, $items, $ack, $u) {
            $early = $date < $l->end_date->toDateString();
            $l->update(['move_out_on' => $date]);
            foreach ($readings as $meter => $value) {
                if ($value !== null && $value !== '') {
                    self::recordReading($l->unit, $meter, (float) $value, $u, $date, 'closing');
                }
            }
            if ($items) {
                self::inspect($l->refresh(), 'move_out', $items, $ack, null, $u, $date);
            }
            // bill up to the move-out date, then credit any rent / service charge billed beyond it
            self::bill($l->refresh(), $u, $date);
            foreach (LeaseCharge::where('lease_id', $l->id)->whereIn('type', ['rent', 'service_charge'])->whereNull('cancelled_at')->where('period_end', '>', $date)->get() as $c) {
                $total = $c->period_start->diffInDays($c->period_end) + 1;
                $unused = $c->period_start->toDateString() > $date ? $total : Carbon::parse($date)->diffInDays($c->period_end);
                $amt = min(LeaseBillingService::creditable($c), round((float) $c->amount * $unused / $total, 2));
                if ($amt > 0) {
                    LeaseBillingService::creditNote($c, $amt, 'move-out '.dmy($date).' – '.(int) $unused.' unused day(s)', $u);
                }
            }
            $l->update(['status' => $early && $l->notice_by === 'landlord' ? 'terminated' : 'ended', 'billed_to' => min($l->billed_to?->toDateString() ?? $date, $date)]);
            self::syncUnitStatus($l->unit);
            if ($l->unit->room_id) {
                Room::whereKey($l->unit->room_id)->update(['hk_status' => 'dirty']);
            }
        });
        ActivityLogger::log('leases', 'moved_out', "Lease {$l->lease_no} – {$l->tenant_name} moved out of {$l->unit->code} on ".dmy($date), $l);
    }

    /** Deposit settlement statement at move-out. */
    public static function settlement(Lease $l): array
    {
        $bal = LeaseBillingService::balance($l);
        $held = LeaseBillingService::depositHeld($l);
        $apply = round(max(0, min($held, $bal)), 2);

        // a credit on the account (unused rent credited at move-out, overpayments) is refunded with the deposit
        $credit = round(max(0, -$bal), 2);

        return ['balance' => $bal, 'held' => $held, 'apply' => $apply, 'refund' => round($held - $apply, 2), 'credit' => $credit,
            'total_refund' => round($held - $apply + $credit, 2), 'due' => round(max(0, $bal - $apply), 2)];
    }

    /** Apply the deposit to the balance, refund the rest, close the lease. */
    public static function settle(Lease $l, ?int $refundModeId, ?string $reference, ?User $u = null): array
    {
        if (! in_array($l->status, ['ended', 'terminated'], true)) {
            throw new RuntimeException('Settle the deposit after the move-out.');
        }
        if ($l->settled_at) {
            throw new RuntimeException('Already settled.');
        }
        $s = self::settlement($l);
        if ($s['total_refund'] > 0 && ! $refundModeId) {
            throw new RuntimeException('Choose how the deposit balance of '.money($s['total_refund']).' is refunded.');
        }
        DB::transaction(function () use ($l, $s, $refundModeId, $reference, $u) {
            if ($s['apply'] > 0) {
                LeaseBillingService::pay($l, 'deposit_applied', $s['apply'], null, 'Move-out settlement', $u);
            }
            if ($s['refund'] > 0) {
                LeaseBillingService::pay($l, 'deposit_refund', $s['refund'], $refundModeId, $reference, $u);
            }
            if ($s['credit'] > 0) {
                LeaseBillingService::pay($l, 'refund', $s['credit'], $refundModeId, $reference, $u, null, 0, null, 'Account credit refunded at move-out');
            }
            $l->update(['settled_at' => now()]);
        });
        ActivityLogger::log('leases', 'settled', "Lease {$l->lease_no} deposit settled – applied ".money($s['apply']).', refunded '.money($s['refund']).($s['due'] > 0 ? ', still due '.money($s['due']) : ''), $l);

        return $s;
    }

    // ───────────────────────── nightly & dashboard ─────────────────────────

    /** Night audit: rent invoices for the coming periods, late fees, auto-renewals. */
    public static function nightly(): array
    {
        $out = ['invoices' => 0, 'late_fees' => 0, 'renewed' => []];
        foreach (Lease::with('unit')->whereIn('status', ['active', 'notice'])->get() as $l) {
            try {
                $out['invoices'] += count(self::bill($l));
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $out['late_fees'] = self::lateFees();
        $out['renewed'] = self::autoRenewRun();

        return $out;
    }

    public static function stats(): array
    {
        $today = self::today();
        $units = LeaseUnit::where('is_active', true)->get();
        $live = Lease::whereIn('status', ['active', 'notice'])->get();

        return [
            'units' => $units->count(),
            'leased' => $units->where('status', 'leased')->count(),
            'vacant' => $units->where('status', 'vacant')->count(),
            'occupancy' => $units->count() ? round($units->where('status', 'leased')->count() * 100 / $units->count(), 1) : 0,
            'rent_roll' => round($live->sum(fn ($l) => (float) $l->rent + (float) $l->service_charge), 2),
            'arrears' => LeaseBillingService::arrears(),
            'deposits' => LeaseBillingService::depositControl($today),
            'expiring' => $live->filter(fn ($l) => $l->status === 'active' && ! $l->auto_renew && $l->end_date->toDateString() <= Carbon::parse($today)->addDays((int) self::cfg('lease_expiry_alert_days'))->toDateString()),
            'overdue_moveout' => $live->filter(fn ($l) => $l->lastDay()->toDateString() < $today),
            'unsettled' => Lease::whereIn('status', ['ended', 'terminated'])->whereNull('settled_at')->count(),
        ];
    }

    /** Sidebar badge: leases in arrears + term ended / move-out date passed without a move-out + deposits not settled. */
    public static function attention(): int
    {
        $today = self::today();

        return LeaseBillingService::arrears()->count()
            + Lease::whereIn('status', ['active', 'notice'])->get()->filter(fn ($l) => $l->lastDay()->toDateString() < $today)->count()
            + Lease::whereIn('status', ['ended', 'terminated'])->whereNull('settled_at')->count();
    }
}
