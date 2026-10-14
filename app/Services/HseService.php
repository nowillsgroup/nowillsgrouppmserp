<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Guest;
use App\Models\HseAccident;
use App\Models\HseCheck;
use App\Models\HseDrill;
use App\Models\HseEquipment;
use App\Models\HsePermit;
use App\Models\HseRisk;
use App\Models\HseWarden;
use App\Models\MaintenanceRequest;
use App\Models\SecurityIncident;
use App\Models\Setting;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v6.7 Health, safety & fire.
 *  - Fire equipment: every item has a visual check interval (weekly for the alarm panel, sprinklers and LPG; monthly for
 *    most) and an annual contractor service. A failed check marks the item defective and (optionally) raises an
 *    engineering ticket; a later passing check puts it back in order. Expired items (cylinder life, kit contents) flag.
 *  - Drills: every shift must have evacuated within the drill interval; the result compares the evacuation time with the
 *    target and the roll call with the staff on duty. Wardens: each shift needs a chief warden and a first aider with
 *    valid training.
 *  - Risk assessments: likelihood × severity (1–25) before and after controls; residual ≥ 15 cannot go ahead, residual
 *    10–14 waits for acceptance by a different manager; reviews fall due after the review interval.
 *  - Permits to work: precautions confirmed at issue by an authorised issuer who is not the requester; hot work needs a
 *    fire watch (and 60 min afterwards), confined spaces an O₂ reading of 19.5–23.5 %, electrical work the isolation
 *    points; close-out confirms the area is safe and isolated detectors are back on.
 *  - Accidents: death or more than three days' disablement of anyone employed on the premises (staff or contractors),
 *    and every dangerous occurrence (fire, explosion, collapse, lift / hoist failure…), must be notified forthwith to the
 *    Factories Inspectorate (Act 328 ss. 10–11); a death after notification is notified again (s. 10(2)).
 */
class HseService
{
    public const DEFAULTS = [
        'hse_drill_days' => '180', 'hse_drill_target' => '240', 'hse_review_days' => '365', 'hse_auto_ticket' => '1',
        'hse_issuer_differs' => '1', 'hse_hours_month' => '173', 'hse_inspectorate' => 'Department of Factories Inspectorate, Accra',
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
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'.Tenant::id()] ??= [
            'drill_days' => (int) setting('hse_drill_days', '180'),
            'drill_target' => (int) setting('hse_drill_target', '240'),
            'review_days' => (int) setting('hse_review_days', '365'),
            'auto_ticket' => setting('hse_auto_ticket', '1') === '1',
            'issuer_differs' => setting('hse_issuer_differs', '1') === '1',
            'hours_month' => (int) setting('hse_hours_month', '173'),
            'inspectorate' => (string) setting('hse_inspectorate', self::DEFAULTS['hse_inspectorate']),
        ];
    }

    public static function saveSettings(array $d): void
    {
        $ints = ['drill_days' => [30, 730, 'The drill interval is 30 to 730 days.'], 'drill_target' => [30, 1800, 'The evacuation target is 30 to 1,800 seconds.'],
            'review_days' => [30, 1095, 'The review interval is 30 to 1,095 days.'], 'hours_month' => [40, 320, 'Hours worked per employee per month are 40 to 320.']];
        $out = [];
        foreach ($ints as $k => [$lo, $hi, $msg]) {
            $v = $d[$k] ?? null;
            self::need(is_numeric($v) && (int) $v == $v && $v >= $lo && $v <= $hi, $msg);
            $out['hse_'.$k] = (string) (int) $v;
        }
        self::need(filled($d['inspectorate'] ?? null), 'Enter the Factories Inspectorate office the notices go to.');
        $out['hse_inspectorate'] = mb_substr(trim($d['inspectorate']), 0, 150);
        $out['hse_auto_ticket'] = ! empty($d['auto_ticket']) ? '1' : '0';
        $out['hse_issuer_differs'] = ! empty($d['issuer_differs']) ? '1' : '0';
        foreach ($out as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('hse', 'settings', 'Health & safety settings updated');
    }

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    public static function canManage(?User $u): bool
    {
        return (bool) $u?->hasPermission('hse.manage');
    }

    public static function canPermit(?User $u): bool
    {
        return (bool) ($u?->hasPermission('hse.permit') || $u?->hasPermission('hse.manage'));
    }

    public static function canCheck(?User $u): bool
    {
        return (bool) ($u?->hasPermission('hse.check') || $u?->hasPermission('hse.permit') || $u?->hasPermission('hse.manage'));
    }

    protected static function today(): string
    {
        return bdate()->toDateString();
    }

    protected static function text($v, int $max = 255): ?string
    {
        return filled($v) ? mb_substr(trim((string) $v), 0, $max) : null;
    }

    protected static function date($v, string $label): ?Carbon
    {
        if (! filled($v)) {
            return null;
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable) {
            throw new RuntimeException("{$label} is not a valid date.");
        }
    }

    protected static function ticket(string $category, string $title, ?string $description, ?string $location, string $priority, int $dueDays, User $u): MaintenanceRequest
    {
        return MaintenanceRequest::create(['ticket_no' => Numbering::next('ticket'), 'category' => $category, 'title' => mb_substr($title, 0, 150),
            'description' => $description, 'location' => $location ? mb_substr($location, 0, 120) : null, 'priority' => $priority,
            'due_date' => bdate()->copy()->addDays($dueDays)->toDateString(), 'status' => 'open', 'reported_by' => $u->id]);
    }

    // ------------------------------------------------------------------ fire equipment

    public static function nextCode(string $type): string
    {
        $prefix = HseEquipment::TYPES[$type][1] ?? 'EQ';
        $n = HseEquipment::where('code', 'like', $prefix.'-%')->pluck('code')->map(fn ($c) => (int) substr($c, strlen($prefix) + 1))->max() ?? 0;

        return $prefix.'-'.str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT);
    }

    public static function saveEquipment(array $d, User $u, ?HseEquipment $e = null): HseEquipment
    {
        self::need(self::canManage($u));
        $type = (string) ($d['type'] ?? '');
        self::need(isset(HseEquipment::TYPES[$type]), 'Choose the type of equipment.');
        self::need(filled($d['location'] ?? null), 'Enter where it is.');
        $code = strtoupper(trim((string) ($d['code'] ?? ''))) ?: ($e?->code ?? self::nextCode($type));
        self::need((bool) preg_match('/^[A-Z0-9\-]{2,20}$/', $code), 'The code is 2–20 letters, digits or dashes.');
        self::need(! HseEquipment::where('code', $code)->when($e, fn ($q) => $q->whereKeyNot($e->id))->exists(), "Code {$code} is already used.");
        [, , $checkDef, $serviceDef] = HseEquipment::TYPES[$type];
        $check = filled($d['check_days'] ?? null) ? (int) $d['check_days'] : $checkDef;
        $service = filled($d['service_days'] ?? null) ? (int) $d['service_days'] : $serviceDef;
        self::need($check >= 1 && $check <= 365, 'The check interval is 1 to 365 days.');
        self::need($service >= 0 && $service <= 1825, 'The service interval is 0 (none) to 1,825 days.');
        $status = $d['status'] ?? ($e?->status ?? 'ok');
        self::need(isset(HseEquipment::STATUSES[$status]), 'Choose the status.');
        $installed = self::date($d['installed_on'] ?? null, 'The installation date');
        $expires = self::date($d['expires_on'] ?? null, 'The expiry date');
        self::need(! $installed || $installed->toDateString() <= self::today(), 'The installation date cannot be in the future.');
        self::need(! ($installed && $expires) || $expires->gt($installed), 'The expiry date must be after installation.');
        $room = filled($d['room_id'] ?? null) ? \App\Models\Room::find((int) $d['room_id']) : null;
        self::need(! filled($d['room_id'] ?? null) || $room, 'Choose a room of this hotel.');

        $row = ['code' => $code, 'type' => $type, 'location' => self::text($d['location'], 120), 'floor' => self::text($d['floor'] ?? null, 10), 'room_id' => $room?->id,
            'capacity' => self::text($d['capacity'] ?? null, 30), 'gnfs_tag' => self::text(strtoupper((string) ($d['gnfs_tag'] ?? '')), 40), 'serial' => self::text($d['serial'] ?? null, 60),
            'installed_on' => $installed?->toDateString(), 'expires_on' => $expires?->toDateString(), 'check_days' => $check, 'service_days' => $service, 'status' => $status,
            'notes' => self::text($d['notes'] ?? null)];
        if ($e) {
            $was = $e->status;
            $e->update($row);
            if ($e->wasChanged(['check_days']) && $e->last_checked_on) {
                $e->update(['next_check_on' => $e->last_checked_on->copy()->addDays($check)->toDateString()]);
            }
            if ($e->wasChanged(['service_days'])) {
                $e->update(['next_service_on' => $service && $e->last_serviced_on ? $e->last_serviced_on->copy()->addDays($service)->toDateString() : ($service ? $e->next_service_on : null)]);
            }
            ActivityLogger::log('hse', 'equipment', "Fire equipment {$code} updated".($was !== $status ? " – {$e->statusLabel()}" : ''), $e);

            return $e;
        }
        // a new item is due its first check straight away unless a date is given; its service a year after installation
        $row['next_check_on'] = self::date($d['next_check_on'] ?? null, 'The first check date')?->toDateString() ?? self::today();
        $row['next_service_on'] = $service ? ($installed ?? bdate())->copy()->addDays($service)->toDateString() : null;
        $e = HseEquipment::create($row);
        ActivityLogger::log('hse', 'equipment', "Fire equipment {$code} ({$e->typeLabel()}) added at {$e->location}", $e);

        return $e;
    }

    /**
     * $d: kind visual|service, checked_on, ok[] (indices of checklist items found in order – visual), result pass|fail (service),
     * contractor, certificate_no, notes. Returns the check.
     */
    public static function recordCheck(HseEquipment $e, array $d, User $u): HseCheck
    {
        self::need(self::canCheck($u));
        self::need($e->status !== 'retired', "{$e->code} is retired.");
        $kind = ($d['kind'] ?? 'visual') === 'service' ? 'service' : 'visual';
        self::need($kind === 'visual' || self::canManage($u), 'Only a safety manager records the contractor\'s service.');
        self::need($kind === 'visual' || $e->service_days > 0, "{$e->typeLabel()} has no contractor service.");
        $on = self::date($d['checked_on'] ?? null, 'The check date') ?? bdate()->copy();
        self::need($on->toDateString() <= self::today() && $on->toDateString() >= bdate()->copy()->subDays(60)->toDateString(), 'The check date must be within the last 60 days.');
        $items = $e->checklist();
        if ($kind === 'visual') {
            $ok = array_map('intval', (array) ($d['ok'] ?? []));
            $faults = array_values(array_filter($items, fn ($label, $i) => ! in_array($i, $ok, true), ARRAY_FILTER_USE_BOTH));
            if (! empty($d['fault'])) {
                $faults[] = mb_substr(trim((string) $d['fault']), 0, 120);
            }
            $result = $faults ? 'fail' : 'pass';
        } else {
            $result = ($d['result'] ?? '') === 'fail' ? 'fail' : (($d['result'] ?? '') === 'pass' ? 'pass' : '');
            self::need($result !== '', 'Record whether the item passed its service.');
            self::need(filled($d['contractor'] ?? null), 'Enter the service contractor.');
            $faults = $result === 'fail' ? [self::text($d['notes'] ?? null, 120) ?? 'Failed service'] : [];
        }
        self::need($result === 'pass' || filled($d['notes'] ?? null) || count($faults) > 0, 'Describe the fault.');

        return DB::transaction(function () use ($e, $d, $u, $kind, $on, $result, $faults) {
            $e = HseEquipment::lockForUpdate()->findOrFail($e->id);
            $ticket = null;
            if ($result === 'fail' && self::cfg()['auto_ticket'] && ! $e->ticket?->isOpen()) {
                $cat = match ($e->type) {
                    'alarm_panel', 'detector', 'call_point', 'emergency_light' => 'electrical',
                    'hose_reel', 'hydrant', 'sprinkler', 'eyewash' => 'plumbing',
                    'fire_door' => 'carpentry',
                    default => 'general',
                };
                $urgent = in_array($e->type, ['alarm_panel', 'sprinkler', 'lpg', 'aed', 'hydrant'], true);
                $ticket = self::ticket($cat, "Fire safety: {$e->code} {$e->typeLabel()} defective", implode('; ', $faults).(filled($d['notes'] ?? null) ? "\n".trim($d['notes']) : ''),
                    $e->location, $urgent ? 'urgent' : 'high', $urgent ? 0 : 2, $u);
            }
            $c = HseCheck::create(['hse_equipment_id' => $e->id, 'kind' => $kind, 'checked_on' => $on->toDateString(), 'result' => $result, 'faults' => $faults ?: null,
                'notes' => self::text($d['notes'] ?? null), 'contractor' => $kind === 'service' ? self::text($d['contractor'], 120) : null,
                'certificate_no' => $kind === 'service' ? self::text($d['certificate_no'] ?? null, 60) : null, 'user_id' => $u->id, 'maintenance_request_id' => $ticket?->id]);
            $upd = ['last_checked_on' => $on->toDateString(), 'next_check_on' => $on->copy()->addDays($e->check_days)->toDateString()];
            if ($kind === 'service' && $result === 'pass') {
                $upd += ['last_serviced_on' => $on->toDateString(), 'next_service_on' => $on->copy()->addDays($e->service_days)->toDateString()];
            }
            if ($result === 'fail') {
                $upd['status'] = $e->status === 'out_of_service' ? 'out_of_service' : 'defective';
                if ($ticket) {
                    $upd['maintenance_request_id'] = $ticket->id;
                }
            } elseif (in_array($e->status, ['defective', 'out_of_service'], true)) {
                $upd['status'] = 'ok';
            }
            $e->update($upd);
            ActivityLogger::log('hse', $kind === 'service' ? 'service' : 'check', "{$e->code} {$e->typeLabel()}: ".($kind === 'service' ? 'service' : 'check').' '.($result === 'pass' ? 'passed' : 'FAILED – '.implode('; ', $faults))
                .($ticket ? " – ticket {$ticket->ticket_no}" : ''), $e);

            return $c;
        });
    }

    /** The monthly round: rows [equipment id => ['result' => pass|fail|'' , 'note' => …]]. Blank rows are skipped. */
    public static function round(string $date, array $rows, User $u): array
    {
        self::need(self::canCheck($u));
        $n = ['pass' => 0, 'fail' => 0];
        $any = collect($rows)->contains(fn ($r) => in_array($r['result'] ?? '', ['pass', 'fail'], true));
        self::need($any, 'Mark at least one item as passed or failed.');
        foreach ($rows as $id => $r) {
            $res = $r['result'] ?? '';
            if (! in_array($res, ['pass', 'fail'], true)) {
                continue;
            }
            $e = HseEquipment::find((int) $id);
            if (! $e || $e->status === 'retired') {
                continue;
            }
            $ok = $res === 'pass' ? array_keys($e->checklist()) : [];
            self::recordCheck($e, ['kind' => 'visual', 'checked_on' => $date, 'ok' => $ok, 'fault' => $res === 'fail' ? (self::text($r['note'] ?? null, 120) ?? 'Found faulty on the round') : null,
                'notes' => self::text($r['note'] ?? null)], $u);
            $n[$res]++;
        }

        return $n;
    }

    public static function equipmentStats(): array
    {
        return self::$memo['eq'.Tenant::id()] ??= (function () {
            $all = HseEquipment::where('status', '!=', 'retired')->get();
            $states = $all->map(fn ($e) => $e->state())->countBy();
            $good = ($states['ok'] ?? 0) + ($states['due'] ?? 0);

            return ['total' => $all->count(), 'in_order' => $good, 'due' => $states['due'] ?? 0, 'overdue' => $states['overdue'] ?? 0, 'defective' => $states['defective'] ?? 0,
                'expired' => $states['expired'] ?? 0, 'pct' => $all->count() ? round($good / $all->count() * 100, 1) : null,
                'service_due' => $all->filter(fn ($e) => $e->next_service_on && $e->next_service_on->toDateString() <= bdate()->copy()->addDays(30)->toDateString())->count()];
        })();
    }

    /** Items needing attention, worst first. */
    public static function dueList(int $limit = 0): Collection
    {
        $rank = ['defective' => 0, 'expired' => 1, 'overdue' => 2, 'due' => 3];
        $l = HseEquipment::with('ticket')->where('status', '!=', 'retired')->get()->map(fn ($e) => tap($e, fn ($x) => $x->setAttribute('st', $x->state())))
            ->filter(fn ($e) => isset($rank[$e->st]))->sortBy(fn ($e) => [$rank[$e->st], $e->next_check_on?->toDateString() ?? '0000'])->values();

        return $limit ? $l->take($limit)->values() : $l;
    }

    // ------------------------------------------------------------------ drills & wardens

    public static function scheduleDrill(array $d, User $u): HseDrill
    {
        self::need(self::canManage($u));
        $type = (string) ($d['type'] ?? 'fire_evacuation');
        self::need(isset(HseDrill::TYPES[$type]), 'Choose the type of drill.');
        $shift = (string) ($d['shift'] ?? '');
        self::need(isset(HseWarden::SHIFTS[$shift]), 'Choose the shift.');
        $at = self::date($d['scheduled_at'] ?? null, 'The drill time');
        self::need((bool) $at, 'Enter when the drill is.');
        self::need($at->gte(now()->subDays(1)) && $at->lte(now()->addYear()), 'Schedule the drill between today and a year ahead.');
        $target = filled($d['target_seconds'] ?? null) ? (int) $d['target_seconds'] : self::cfg()['drill_target'];
        self::need($target >= 30 && $target <= 1800, 'The evacuation target is 30 to 1,800 seconds.');
        $lead = filled($d['led_by'] ?? null) ? User::where('hotel_id', Tenant::id())->find((int) $d['led_by']) : null;
        self::need(! filled($d['led_by'] ?? null) || $lead, 'Choose a member of staff to lead the drill.');
        $x = HseDrill::create(['number' => Numbering::next('hse_drill'), 'type' => $type, 'status' => 'planned', 'scheduled_at' => $at, 'shift' => $shift,
            'scenario' => self::text($d['scenario'] ?? null, 2000), 'assembly_point' => self::text($d['assembly_point'] ?? null, 80), 'announced' => ! empty($d['announced']),
            'target_seconds' => $target, 'led_by' => $lead?->id ?? $u->id, 'created_by' => $u->id]);
        ActivityLogger::log('hse', 'drill', "{$x->number} {$x->typeLabel()} scheduled for {$at->format('d M Y H:i')} ({$shift} shift)", $x);

        return $x;
    }

    /** $d: alarm_at, clear_at, staff_expected, staff_accounted, guests_evacuated, fire_service_attended, observations, improvements, staff[] */
    public static function recordDrill(HseDrill $x, array $d, User $u): HseDrill
    {
        self::need(self::canManage($u) || $x->led_by === $u->id);
        self::need($x->status === 'planned', "{$x->number} is already {$x->status}.");
        $alarm = self::date($d['alarm_at'] ?? null, 'The alarm time');
        $clear = self::date($d['clear_at'] ?? null, 'The all-clear time');
        self::need($alarm && $clear, 'Enter when the alarm sounded and when the building was clear.');
        self::need($alarm->lte(now()->addMinute()), 'The alarm time cannot be in the future.');
        self::need($clear->gt($alarm) && $alarm->diffInSeconds($clear, true) <= 7200, 'The building-clear time must be after the alarm and within 2 hours.');
        $exp = (int) ($d['staff_expected'] ?? 0);
        $acc = (int) ($d['staff_accounted'] ?? -1);
        self::need($exp >= 1 && $exp <= 2000, 'Enter the staff on duty (roll-call list).');
        self::need($acc >= 0 && $acc <= $exp, 'Staff accounted for cannot be more than the staff on duty.');
        self::need(filled($d['observations'] ?? null), 'Record what was observed.');
        $staff = Employee::whereIn('id', array_map('intval', (array) ($d['staff'] ?? [])))->pluck('id')->all();
        $secs = (int) $alarm->diffInSeconds($clear, true);
        $pass = $secs <= $x->target_seconds && $acc === $exp;

        DB::transaction(function () use ($x, $d, $alarm, $clear, $exp, $acc, $secs, $pass, $staff) {
            $x->update(['status' => 'done', 'alarm_at' => $alarm, 'clear_at' => $clear, 'evac_seconds' => $secs, 'staff_expected' => $exp, 'staff_accounted' => $acc,
                'guests_evacuated' => filled($d['guests_evacuated'] ?? null) ? max(0, (int) $d['guests_evacuated']) : null, 'fire_service_attended' => ! empty($d['fire_service_attended']),
                'result' => $pass ? 'pass' : 'fail', 'observations' => self::text($d['observations'], 3000), 'improvements' => self::text($d['improvements'] ?? null, 3000)]);
            $x->staff()->sync($staff);
        });
        ActivityLogger::log('hse', 'drill_done', "{$x->number}: building clear in {$x->evacLabel()} (target ".intdiv($x->target_seconds, 60)." min), {$acc}/{$exp} accounted for – ".($pass ? 'PASS' : 'FAIL'), $x);

        return $x->fresh('staff');
    }

    public static function cancelDrill(HseDrill $x, string $reason, User $u): void
    {
        self::need(self::canManage($u));
        self::need($x->status === 'planned', 'Only planned drills can be cancelled.');
        self::need(trim($reason) !== '', 'Give a reason.');
        $x->update(['status' => 'cancelled', 'cancel_reason' => mb_substr(trim($reason), 0, 190)]);
        ActivityLogger::log('hse', 'drill_cancel', "{$x->number} cancelled: {$reason}", $x);
    }

    /** Per shift: last drill, next due, planned, state ok | due | overdue | never. */
    public static function drillStatus(): array
    {
        return self::$memo['drills'.Tenant::id()] ??= (function () {
            $days = self::cfg()['drill_days'];
            $today = bdate()->copy();
            $out = [];
            foreach (HseWarden::SHIFTS as $s => $label) {
                $last = HseDrill::where('status', 'done')->where('shift', $s)->whereIn('type', ['fire_evacuation', 'fire_partial'])->orderByDesc('alarm_at')->first();
                $planned = HseDrill::where('status', 'planned')->where('shift', $s)->orderBy('scheduled_at')->first();
                $due = $last ? $last->alarm_at->copy()->startOfDay()->addDays($days) : null;
                $state = ! $last ? 'never' : ($due->lt($today) ? 'overdue' : ($due->lte($today->copy()->addDays(30)) ? 'due' : 'ok'));
                $out[$s] = ['label' => $label, 'last' => $last, 'due' => $due, 'planned' => $planned, 'state' => $state];
            }

            return $out;
        })();
    }

    public static function saveWarden(array $d, User $u, ?HseWarden $w = null): HseWarden
    {
        self::need(self::canManage($u));
        $emp = Employee::where('is_active', true)->find((int) ($d['employee_id'] ?? 0));
        self::need((bool) $emp, 'Choose an active employee.');
        self::need(isset(HseWarden::ROLES[$d['role'] ?? '']), 'Choose the role.');
        self::need(isset(HseWarden::SHIFTS[$d['shift'] ?? '']), 'Choose the shift.');
        $on = self::date($d['trained_on'] ?? null, 'The training date');
        $until = self::date($d['trained_until'] ?? null, 'The training expiry');
        self::need(! $on || $on->toDateString() <= self::today(), 'The training date cannot be in the future.');
        self::need(! ($on && $until) || $until->gt($on), 'Training must expire after it was done.');
        self::need(! HseWarden::where('employee_id', $emp->id)->where('role', $d['role'])->where('shift', $d['shift'])->where('is_active', true)->when($w, fn ($q) => $q->whereKeyNot($w->id))->exists(),
            "{$emp->name} already has that role on that shift.");
        $row = ['employee_id' => $emp->id, 'role' => $d['role'], 'shift' => $d['shift'], 'area' => self::text($d['area'] ?? null, 80), 'trained_on' => $on?->toDateString(),
            'trained_until' => $until?->toDateString(), 'is_active' => $w ? ! empty($d['is_active']) : true];
        $w ? $w->update($row) : $w = HseWarden::create($row);
        ActivityLogger::log('hse', 'warden', "{$emp->name}: {$w->roleLabel()}, {$d['shift']} shift", $w);

        return $w;
    }

    /** Per shift: chief wardens, floor wardens and first aiders with valid training, plus the gaps. */
    public static function coverage(): array
    {
        return self::$memo['cover'.Tenant::id()] ??= (function () {
            $today = self::today();
            $all = HseWarden::with('employee')->where('is_active', true)->get();
            $out = [];
            foreach (HseWarden::SHIFTS as $s => $label) {
                $on = $all->where('shift', $s);
                $valid = $on->filter(fn ($w) => $w->trainingValid($today));
                $gaps = [];
                if (! $valid->where('role', 'chief')->count()) {
                    $gaps[] = 'no chief warden with valid training';
                }
                if (! $valid->where('role', 'first_aider')->count()) {
                    $gaps[] = 'no trained first aider';
                }
                $out[$s] = ['label' => $label, 'chief' => $valid->where('role', 'chief')->count(), 'floor' => $valid->where('role', 'floor')->count(),
                    'first_aider' => $valid->where('role', 'first_aider')->count(), 'expired' => $on->reject(fn ($w) => $w->trainingValid($today))->count(), 'gaps' => $gaps];
            }

            return $out;
        })();
    }

    // ------------------------------------------------------------------ risk assessments

    public static function saveRisk(array $d, User $u, ?HseRisk $r = null): HseRisk
    {
        self::need(self::canManage($u));
        self::need(filled($d['title'] ?? null) && filled($d['area'] ?? null), 'Enter the activity / title and the area.');
        self::need(filled($d['hazard'] ?? null) && filled($d['who_at_risk'] ?? null), 'Describe the hazard and who is at risk.');
        self::need(filled($d['controls'] ?? null), 'List the control measures in place.');
        $v = [];
        foreach (['likelihood', 'severity', 'residual_likelihood', 'residual_severity'] as $k) {
            $v[$k] = (int) ($d[$k] ?? 0);
            self::need($v[$k] >= 1 && $v[$k] <= 5, 'Rate likelihood and severity from 1 to 5, before and after controls.');
        }
        $init = $v['likelihood'] * $v['severity'];
        $res = $v['residual_likelihood'] * $v['residual_severity'];
        self::need($res <= $init, 'The residual risk cannot be higher than the risk before controls.');
        self::need($res < 15, "Residual risk {$res} is very high – the activity must not go ahead until further controls bring it down.");
        self::need($res < 10 || filled($d['further_actions'] ?? null), 'A high residual risk needs the further actions planned.');
        $review = self::date($d['review_on'] ?? null, 'The review date') ?? bdate()->copy()->addDays(self::cfg()['review_days']);
        self::need($review->toDateString() > self::today() && $review->lte(bdate()->copy()->addYears(3)), 'The review date must be in the next three years.');
        $owner = filled($d['owner_id'] ?? null) ? User::where('hotel_id', Tenant::id())->where('is_active', true)->find((int) $d['owner_id']) : null;
        self::need(! filled($d['owner_id'] ?? null) || $owner, 'Choose an active user as the risk owner.');
        $dept = filled($d['department_id'] ?? null) ? \App\Models\Department::find((int) $d['department_id']) : null;

        $row = ['title' => self::text($d['title'], 150), 'area' => self::text($d['area'], 80), 'department_id' => $dept?->id, 'hazard' => self::text($d['hazard'], 3000),
            'who_at_risk' => self::text($d['who_at_risk'], 120), 'controls' => self::text($d['controls'], 3000), 'further_actions' => self::text($d['further_actions'] ?? null, 3000),
            'owner_id' => $owner?->id, 'review_on' => $review->toDateString()] + $v;
        $needsAcceptance = $res >= 10;
        if ($r) {
            $rated = $r->residual_likelihood !== $v['residual_likelihood'] || $r->residual_severity !== $v['residual_severity'];
            if ($r->status !== 'archived') {
                $row['status'] = $needsAcceptance && ($rated || $r->status === 'pending') ? 'pending' : 'active';
                if ($row['status'] === 'pending') {
                    $row += ['accepted_by' => null, 'accepted_at' => null];
                }
            }
            $r->update($row);
            ActivityLogger::log('hse', 'risk', "{$r->number} {$r->title} updated – residual {$res}", $r);

            return $r;
        }
        $r = HseRisk::create($row + ['number' => Numbering::next('hse_risk'), 'status' => $needsAcceptance ? 'pending' : 'active', 'assessed_by' => $u->id, 'last_reviewed_on' => self::today(), 'reviewed_by' => $u->id]);
        ActivityLogger::log('hse', 'risk', "{$r->number} {$r->title}: risk {$init} → residual {$res}".($needsAcceptance ? ' – awaiting acceptance' : ''), $r);

        return $r;
    }

    public static function acceptRisk(HseRisk $r, User $u): HseRisk
    {
        self::need(self::canManage($u));
        self::need($r->status === 'pending', 'Only a risk awaiting acceptance can be accepted.');
        self::need($r->assessed_by !== $u->id, 'A different manager must accept the risk – not the assessor.');
        $r->update(['status' => 'active', 'accepted_by' => $u->id, 'accepted_at' => now()]);
        ActivityLogger::log('hse', 'risk_accept', "{$r->number}: residual risk {$r->residual()} accepted", $r);

        return $r;
    }

    public static function reviewRisk(HseRisk $r, array $d, User $u): HseRisk
    {
        self::need(self::canManage($u) || $r->owner_id === $u->id, 'Only the risk owner or a safety manager reviews it.');
        self::need($r->status !== 'archived', 'This assessment is archived.');
        $next = self::date($d['review_on'] ?? null, 'The next review date') ?? bdate()->copy()->addDays(self::cfg()['review_days']);
        self::need($next->toDateString() > self::today() && $next->lte(bdate()->copy()->addYears(3)), 'The next review must be in the next three years.');
        $r->update(['last_reviewed_on' => self::today(), 'reviewed_by' => $u->id, 'review_on' => $next->toDateString()]);
        ActivityLogger::log('hse', 'risk_review', "{$r->number} reviewed – still valid; next review {$next->format('d M Y')}".(filled($d['note'] ?? null) ? ': '.trim($d['note']) : ''), $r);

        return $r;
    }

    public static function archiveRisk(HseRisk $r, bool $archive, User $u): HseRisk
    {
        self::need(self::canManage($u));
        $r->update(['status' => $archive ? 'archived' : ($r->residual() >= 10 && ! $r->accepted_by ? 'pending' : 'active')]);
        ActivityLogger::log('hse', 'risk_archive', "{$r->number} ".($archive ? 'archived' : 'restored'), $r);

        return $r;
    }

    /** 5 × 5 counts of live assessments by residual likelihood (rows 5→1) and severity (cols 1→5). */
    public static function heatmap(): array
    {
        $m = [];
        foreach (HseRisk::where('status', '!=', 'archived')->get(['residual_likelihood', 'residual_severity']) as $r) {
            $m[$r->residual_likelihood][$r->residual_severity] = ($m[$r->residual_likelihood][$r->residual_severity] ?? 0) + 1;
        }

        return $m;
    }

    // ------------------------------------------------------------------ permits to work

    public static function requestPermit(array $d, User $u): HsePermit
    {
        self::need(self::canCheck($u));
        $type = (string) ($d['type'] ?? '');
        self::need(isset(HsePermit::TYPES[$type]), 'Choose the type of permit.');
        self::need(filled($d['location'] ?? null) && filled($d['work'] ?? null), 'Enter where and what the work is.');
        $from = self::date($d['starts_at'] ?? null, 'The start');
        $to = self::date($d['ends_at'] ?? null, 'The end');
        self::need($from && $to, 'Enter when the work starts and ends.');
        self::need($from->gte(now()->subHour()) && $from->lte(now()->addDays(30)), 'The work must start between now and 30 days ahead.');
        self::need($to->gt($from), 'The end must be after the start.');
        $max = HsePermit::TYPES[$type][1];
        self::need($from->diffInMinutes($to, true) <= $max * 60, "A {$type} permit covers at most {$max} hours – issue a new permit for the next shift.");
        $workers = (int) ($d['workers'] ?? 1);
        self::need($workers >= 1 && $workers <= 50, 'Workers are 1 to 50.');
        $ticket = filled($d['maintenance_request_id'] ?? null) ? MaintenanceRequest::find((int) $d['maintenance_request_id']) : null;
        $p = HsePermit::create(['number' => Numbering::next('hse_permit'), 'type' => $type, 'status' => 'requested', 'location' => self::text($d['location'], 120),
            'work' => self::text($d['work']), 'contractor' => self::text($d['contractor'] ?? null, 120), 'workers' => $workers, 'starts_at' => $from, 'ends_at' => $to,
            'maintenance_request_id' => $ticket?->id, 'requested_by' => $u->id]);
        ActivityLogger::log('hse', 'permit', "{$p->number} {$p->typeLabel()} requested – {$p->location}: {$p->work}", $p);

        return $p;
    }

    /** $d: precautions[] (keys), fire_watch, detectors_isolated, gas_o2, isolation_points */
    public static function issuePermit(HsePermit $p, array $d, User $u): HsePermit
    {
        self::need(self::canPermit($u), 'Only an authorised permit issuer can issue a permit to work.');
        self::need($p->status === 'requested', "{$p->number} is already {$p->status}.");
        self::need(! self::cfg()['issuer_differs'] || $p->requested_by !== $u->id, 'The issuer must be a different person from the requester.');
        self::need($p->ends_at->gt(now()), 'The permit period has already ended – raise a new request.');
        $ticked = array_values(array_intersect(array_keys($p->precautionList()), (array) ($d['precautions'] ?? [])));
        $missing = array_diff(array_keys($p->precautionList()), $ticked);
        self::need(! $missing, 'Confirm every precaution before issuing: '.implode('; ', array_map(fn ($k) => $p->precautionList()[$k], $missing)).'.');
        $row = ['precautions' => $ticked, 'status' => 'issued', 'issued_by' => $u->id, 'issued_at' => now()];
        if ($p->type === 'hot_work') {
            self::need(filled($d['fire_watch'] ?? null), 'Name the fire watch for the hot work.');
            $row += ['fire_watch' => self::text($d['fire_watch'], 80), 'detectors_isolated' => self::text($d['detectors_isolated'] ?? null, 120)];
        }
        if ($p->type === 'confined') {
            $o2 = $d['gas_o2'] ?? null;
            self::need(is_numeric($o2), 'Record the oxygen reading of the gas test.');
            self::need($o2 >= 19.5 && $o2 <= 23.5, "Oxygen {$o2} % is outside 19.5–23.5 % – do not enter; ventilate and re-test.");
            $row['gas_o2'] = round((float) $o2, 1);
        }
        if ($p->type === 'electrical') {
            self::need(filled($d['isolation_points'] ?? null), 'List the isolation points locked off.');
            $row['isolation_points'] = self::text($d['isolation_points']);
        }
        $p->update($row);
        ActivityLogger::log('hse', 'permit_issue', "{$p->number} issued until {$p->ends_at->format('d M H:i')}".($p->detectors_isolated ? " – detectors isolated: {$p->detectors_isolated}" : ''), $p);

        return $p;
    }

    /** $d: area_safe, fire_watch_done, detectors_restored, close_note */
    public static function closePermit(HsePermit $p, array $d, User $u): HsePermit
    {
        self::need(self::canPermit($u), 'Only an authorised permit issuer closes out a permit.');
        self::need($p->status === 'issued', 'Only an issued permit can be closed out.');
        self::need(! empty($d['area_safe']), 'Confirm the area has been inspected and left safe.');
        self::need($p->type !== 'hot_work' || ! empty($d['fire_watch_done']), 'Confirm the fire watch was kept for 60 minutes after the hot work.');
        self::need(! $p->detectors_isolated || ! empty($d['detectors_restored']), 'Confirm the isolated detectors are back in service.');
        $p->update(['status' => 'closed', 'closed_by' => $u->id, 'closed_at' => now(), 'close_note' => self::text($d['close_note'] ?? null)]);
        ActivityLogger::log('hse', 'permit_close', "{$p->number} closed out".($p->closed_at->gt($p->ends_at) ? ' (late)' : ''), $p);

        return $p;
    }

    public static function cancelPermit(HsePermit $p, string $reason, User $u): HsePermit
    {
        self::need(in_array($p->status, ['requested', 'issued'], true), 'This permit is already '.$p->status.'.');
        self::need(self::canPermit($u) || ($p->status === 'requested' && $p->requested_by === $u->id), 'Only the requester (before issue) or a permit issuer can cancel.');
        self::need(trim($reason) !== '', 'Give a reason.');
        self::need($p->status !== 'issued' || ! $p->detectors_isolated, 'Close the permit out instead – the isolated detectors must be confirmed back on.');
        $p->update(['status' => 'cancelled', 'cancel_reason' => mb_substr(trim($reason), 0, 190), 'closed_by' => $u->id, 'closed_at' => now()]);
        ActivityLogger::log('hse', 'permit_cancel', "{$p->number} cancelled: {$reason}", $p);

        return $p;
    }

    // ------------------------------------------------------------------ accidents & statutory notices

    /** Act 328: death or > 3 days' disablement of a person employed on the premises (staff or a contractor's worker); every dangerous occurrence. */
    public static function isReportable(string $kind, ?string $personType, string $severity, int $lostDays): bool
    {
        if ($kind === 'dangerous') {
            return true;
        }

        return $kind === 'injury' && in_array($personType, ['staff', 'contractor'], true) && ($severity === 'fatal' || ($severity === 'lost_time' && $lostDays > 3));
    }

    public static function reportAccident(array $d, User $u): HseAccident
    {
        self::need(self::canCheck($u));
        $kind = (string) ($d['kind'] ?? '');
        self::need(isset(HseAccident::KINDS[$kind]), 'Choose what happened: accident, near miss or dangerous occurrence.');
        $at = self::date($d['occurred_at'] ?? null, 'The time');
        self::need((bool) $at, 'Enter when it happened.');
        self::need($at->lte(now()->addMinute()) && $at->gte(now()->subDays(30)), 'Report within 30 days – the time cannot be in the future.');
        self::need(filled($d['location'] ?? null), 'Enter where it happened.');
        self::need(mb_strlen(trim((string) ($d['description'] ?? ''))) >= 10, 'Describe what happened (at least a sentence).');
        $severity = $kind === 'near_miss' ? 'near_miss' : (string) ($d['severity'] ?? '');
        self::need(isset(HseAccident::SEVERITIES[$severity]), 'Choose the outcome.');
        self::need($kind !== 'injury' || $severity !== 'near_miss', 'An injury needs its outcome – first aid, medical treatment, lost time or fatal.');
        $occ = $kind === 'dangerous' ? (string) ($d['occurrence'] ?? '') : null;
        self::need($kind !== 'dangerous' || isset(HseAccident::OCCURRENCES[$occ]), 'Choose the dangerous occurrence.');
        $pt = filled($d['person_type'] ?? null) ? (string) $d['person_type'] : null;
        $hurt = $severity !== 'near_miss';
        self::need(! $hurt || isset(HseAccident::PERSONS[$pt]), 'Say who was hurt: staff, contractor, guest or visitor.');
        self::need($pt === null || isset(HseAccident::PERSONS[$pt]), 'Choose who was involved.');
        $emp = $pt === 'staff' ? Employee::find((int) ($d['employee_id'] ?? 0)) : null;
        self::need($pt !== 'staff' || $emp, 'Choose the staff member.');
        $guest = $pt === 'guest' && filled($d['guest_id'] ?? null) ? Guest::find((int) $d['guest_id']) : null;
        self::need(! in_array($pt, ['contractor', 'visitor', 'guest'], true) || $guest || filled($d['person_name'] ?? null), 'Enter the name of the person.');
        if ($hurt) {
            self::need(isset(HseAccident::INJURIES[$d['injury_type'] ?? '']) && isset(HseAccident::BODY[$d['body_part'] ?? '']), 'Choose the type of injury and the part of the body.');
        }
        $lost = $severity === 'lost_time' ? max(0, (int) ($d['lost_days'] ?? 0)) : 0;
        $inc = filled($d['security_incident_id'] ?? null) ? SecurityIncident::find((int) $d['security_incident_id']) : null;
        $room = filled($d['room_id'] ?? null) ? \App\Models\Room::find((int) $d['room_id']) : null;

        $a = HseAccident::create(['number' => Numbering::next('hse_accident'), 'kind' => $kind, 'occurred_at' => $at, 'location' => self::text($d['location'], 120), 'room_id' => $room?->id,
            'person_type' => $pt, 'employee_id' => $emp?->id, 'guest_id' => $guest?->id, 'person_name' => $emp || $guest ? null : self::text($d['person_name'] ?? null, 120),
            'injury_type' => $hurt ? $d['injury_type'] : null, 'body_part' => $hurt ? $d['body_part'] : null, 'severity' => $severity, 'occurrence' => $occ,
            'description' => self::text($d['description'], 3000), 'first_aid' => self::text($d['first_aid'] ?? null), 'hospital' => self::text($d['hospital'] ?? null, 120),
            'lost_days' => $lost, 'witnesses' => self::text($d['witnesses'] ?? null), 'security_incident_id' => $inc?->id,
            'reportable' => self::isReportable($kind, $pt, $severity, $lost), 'status' => 'open', 'reported_by' => $u->id]);
        ActivityLogger::log('hse', 'accident', "{$a->number} {$a->kindLabel()} at {$a->location}: {$a->severityLabel()}".($a->reportable ? ' – NOTIFY THE FACTORIES INSPECTORATE' : ''), $a);

        return $a;
    }

    /** Follow-up: severity, lost days, return to work, hospital. A death after notification needs a fresh notice (s. 10(2)). */
    public static function updateAccident(HseAccident $a, array $d, User $u): HseAccident
    {
        self::need(self::canManage($u), 'Only a safety manager updates the outcome.');
        self::need($a->status !== 'closed', "{$a->number} is closed.");
        $severity = $a->kind === 'near_miss' ? 'near_miss' : (string) ($d['severity'] ?? $a->severity);
        self::need(isset(HseAccident::SEVERITIES[$severity]), 'Choose the outcome.');
        self::need($a->kind !== 'injury' || $severity !== 'near_miss', 'An injury needs its outcome.');
        $lost = $severity === 'lost_time' ? max(0, (int) ($d['lost_days'] ?? $a->lost_days)) : 0;
        self::need($lost <= 3650, 'Lost days look wrong.');
        $back = self::date($d['returned_on'] ?? null, 'The return-to-work date');
        self::need(! $back || ($back->toDateString() >= $a->occurred_at->toDateString() && $back->toDateString() <= self::today()), 'The return to work must be between the accident and today.');
        $wasFatal = $a->severity === 'fatal';
        $reportable = self::isReportable($a->kind, $a->person_type, $severity, $lost);
        $row = ['severity' => $severity, 'lost_days' => $lost, 'returned_on' => $back?->toDateString(), 'hospital' => self::text($d['hospital'] ?? $a->hospital, 120), 'reportable' => $reportable];
        $renotify = $severity === 'fatal' && ! $wasFatal && $a->notified_on;
        if ($renotify) {
            $row += ['notified_on' => null, 'notified_ref' => null];
        }
        $a->update($row);
        ActivityLogger::log('hse', 'accident_update', "{$a->number}: {$a->severityLabel()}".($lost ? ", {$lost} day(s) lost" : '').($renotify ? ' – the death must be notified to the Inspectorate (s. 10(2))' : ''), $a);

        return $a;
    }

    public static function notifyAccident(HseAccident $a, array $d, User $u): HseAccident
    {
        self::need(self::canManage($u), 'Only a safety manager records the statutory notice.');
        self::need($a->reportable, 'This accident is not notifiable under Act 328.');
        $on = self::date($d['notified_on'] ?? null, 'The notice date') ?? bdate()->copy();
        self::need($on->toDateString() >= $a->occurred_at->toDateString() && $on->toDateString() <= self::today(), 'The notice date must be between the accident and today.');
        $a->update(['notified_on' => $on->toDateString(), 'notified_ref' => self::text($d['notified_ref'] ?? null, 60), 'notified_by' => $u->id]);
        ActivityLogger::log('hse', 'accident_notify', "{$a->number} notified to the Factories Inspectorate on {$on->format('d M Y')}".($a->notified_ref ? " (ref {$a->notified_ref})" : ''), $a);

        return $a;
    }

    public static function investigateAccident(HseAccident $a, array $d, User $u): HseAccident
    {
        self::need(self::canManage($u), 'Only a safety manager records the investigation.');
        self::need($a->status !== 'closed', "{$a->number} is closed.");
        self::need(filled($d['root_cause'] ?? null), 'Record the root cause.');
        $row = ['root_cause' => self::text($d['root_cause'], 3000), 'actions' => self::text($d['actions'] ?? null, 3000), 'status' => 'investigating', 'investigated_by' => $u->id];
        if (! empty($d['raise_ticket']) && ! $a->maintenance_request_id) {
            $t = self::ticket('general', "Safety {$a->number}: ".mb_substr($a->location, 0, 80), $row['actions'] ?? $row['root_cause'], $a->location, $a->reportable || $a->isLostTime() ? 'high' : 'normal', 3, $u);
            $row['maintenance_request_id'] = $t->id;
        }
        $a->update($row);
        ActivityLogger::log('hse', 'accident_investigate', "{$a->number} investigated: {$a->root_cause}", $a);

        return $a;
    }

    public static function closeAccident(HseAccident $a, User $u): HseAccident
    {
        self::need(self::canManage($u), 'Only a safety manager closes an accident.');
        self::need($a->status !== 'closed', 'Already closed.');
        self::need($a->severity === 'near_miss' && $a->kind === 'near_miss' || filled($a->root_cause), 'Investigate first – record the root cause.');
        self::need(! $a->reportable || $a->notified_on, 'Record the notice to the Factories Inspectorate before closing.');
        self::need(! $a->isLostTime() || $a->severity === 'fatal' || $a->returned_on, 'Record the return-to-work date before closing a lost-time injury.');
        $a->update(['status' => 'closed', 'closed_by' => $u->id, 'closed_at' => now()]);
        ActivityLogger::log('hse', 'accident_close', "{$a->number} closed", $a);

        return $a;
    }

    // ------------------------------------------------------------------ dashboard

    public static function kpis(): array
    {
        return self::$memo['kpi'.Tenant::id()] ??= (function () {
            $today = bdate()->copy();
            $lti = HseAccident::where('kind', 'injury')->whereIn('person_type', ['staff', 'contractor'])->whereIn('severity', ['lost_time', 'fatal']);
            $last = (clone $lti)->max('occurred_at');
            $from = $today->copy()->subMonths(12)->startOfDay();
            $ltis = (clone $lti)->where('occurred_at', '>=', $from)->count();
            $hours = Employee::where('is_active', true)->count() * self::cfg()['hours_month'] * 12;
            $yr = HseAccident::where('occurred_at', '>=', $from);

            return [
                'days_since_lti' => $last ? (int) Carbon::parse($last)->startOfDay()->diffInDays($today->copy()->startOfDay(), true) : null,
                'ltis' => $ltis, 'hours' => $hours, 'ltifr' => $hours ? round($ltis * 1000000 / $hours, 2) : null,
                'accidents' => (clone $yr)->where('kind', 'injury')->count(), 'near_misses' => (clone $yr)->where('kind', 'near_miss')->count(),
                'lost_days' => (int) (clone $yr)->sum('lost_days'), 'open' => HseAccident::where('status', '!=', 'closed')->count(),
                'permits_live' => HsePermit::where('status', 'issued')->count(), 'permits_requested' => HsePermit::where('status', 'requested')->count(),
                'risks_high' => HseRisk::where('status', '!=', 'archived')->get()->filter(fn ($r) => $r->residual() >= 10)->count(),
            ];
        })();
    }

    public static function alerts(): array
    {
        return self::$memo['alerts'.Tenant::id()] ??= (function () {
            $eq = self::equipmentStats();
            $today = self::today();

            return [
                'defective' => $eq['defective'], 'overdue' => $eq['overdue'], 'expired' => $eq['expired'],
                'permits_overdue' => HsePermit::where('status', 'issued')->where('ends_at', '<', now())->count(),
                'detectors_isolated' => HsePermit::where('status', 'issued')->whereNotNull('detectors_isolated')->count(),
                'unnotified' => HseAccident::where('reportable', true)->whereNull('notified_on')->count(),
                'reviews' => HseRisk::where('status', '!=', 'archived')->whereDate('review_on', '<', $today)->count(),
                'pending' => HseRisk::where('status', 'pending')->count(),
                'drills' => collect(self::drillStatus())->whereIn('state', ['overdue', 'never'])->count(),
                'warden_gaps' => collect(self::coverage())->sum(fn ($c) => count($c['gaps'])),
            ];
        })();
    }

    /** Nav badge: things that are unsafe or unlawful right now. */
    public static function attention(): int
    {
        $a = self::alerts();

        return $a['defective'] + $a['overdue'] + $a['expired'] + $a['permits_overdue'] + $a['unnotified'] + $a['reviews'] + $a['drills'];
    }
}
