<?php

namespace App\Services;

use App\Models\BanquetEvent;
use App\Models\GatePass;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\SecurityIncident;
use App\Models\SecurityWatch;
use App\Models\Setting;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v4.2 – Security & Gate Management: visitor register, watch list screening, visiting hours / overstays,
 * guest privacy ("no visitors"), portal pre-registration and ID-data retention.
 * Gate passes live in GatePassService, incidents in IncidentService.
 */
class SecurityService
{
    public const DEFAULTS = [
        'sec_visiting_until' => '22:00',   // visitors to guest rooms must leave by
        'sec_approval' => 'always',        // always = desk confirms every visit with the guest; policy = only when the guest asked "call me first"
        'sec_pass_hours' => '4',
        'sec_require_id' => '1',
        'sec_retention_days' => '180',
        'sec_gp_approval' => '1',
        'sec_incident_alert' => 'high',    // severity from which an incident goes to the log book (and the duty manager's phone)
        'sec_alert_phone' => '',
    ];

    public const POLICIES = ['allow' => 'Visitors welcome', 'call' => 'Call the guest first', 'none' => 'No visitors – do not confirm the guest is staying'];

    public const ANON_NAME = 'Visitor (anonymised)';

    public static function cfg(): array
    {
        $g = fn ($k) => (string) setting($k, self::DEFAULTS[$k]);
        $until = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $g('sec_visiting_until')) ? $g('sec_visiting_until') : '22:00';

        return ['visiting_until' => $until, 'approval' => $g('sec_approval') === 'policy' ? 'policy' : 'always',
            'pass_hours' => max(1, min(24, (int) $g('sec_pass_hours'))), 'require_id' => $g('sec_require_id') === '1',
            'retention_days' => max(30, min(3650, (int) $g('sec_retention_days'))), 'gp_approval' => $g('sec_gp_approval') === '1',
            'incident_alert' => array_key_exists($g('sec_incident_alert'), SecurityIncident::RANK) ? $g('sec_incident_alert') : 'high',
            'alert_phone' => $g('sec_alert_phone')];
    }

    public static function saveSettings(array $d): void
    {
        $d += self::cfg();   // partial updates keep the other settings
        $until = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $d['visiting_until']) ? $d['visiting_until'] : '22:00';
        $vals = ['sec_visiting_until' => $until, 'sec_approval' => $d['approval'] === 'policy' ? 'policy' : 'always',
            'sec_pass_hours' => (string) max(1, min(24, (int) $d['pass_hours'])), 'sec_require_id' => ! empty($d['require_id']) ? '1' : '0',
            'sec_retention_days' => (string) max(30, min(3650, (int) $d['retention_days'])), 'sec_gp_approval' => ! empty($d['gp_approval']) ? '1' : '0',
            'sec_incident_alert' => array_key_exists($d['incident_alert'] ?? '', SecurityIncident::RANK) ? $d['incident_alert'] : 'high',
            'sec_alert_phone' => trim((string) ($d['alert_phone'] ?? ''))];
        foreach ($vals as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('security', 'settings', 'Security settings updated');
    }

    // ───────── helpers ─────────

    /** Keyed hash of a normalised ID number – lets the watch list match IDs without storing them. */
    public static function idHash(?string $number): ?string
    {
        $n = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $number));

        return $n === '' ? null : hash_hmac('sha256', $n, (string) config('app.key'));
    }

    public static function idLast4(?string $number): ?string
    {
        $n = preg_replace('/[^A-Za-z0-9]/', '', (string) $number);

        return $n === '' ? null : strtoupper(substr($n, -4));
    }

    public static function phoneKey(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone);

        return strlen($d) >= 7 ? substr($d, -10) : null;
    }

    /** Today's visiting-hours cut-off (or the cut-off on $day). */
    public static function cutoff(?Carbon $day = null): Carbon
    {
        [$h, $m] = explode(':', self::cfg()['visiting_until']);

        return ($day ?? now())->copy()->setTime((int) $h, (int) $m);
    }

    /**
     * Screen a person against the current watch list.
     * Phone or ID match → the entry's own level; a name-only match is always just an alert ("possible match").
     *
     * @return array{entry: SecurityWatch, on: string, level: string}|null
     */
    public static function screen(?string $name, ?string $phone, ?string $idNumber): ?array
    {
        $pk = self::phoneKey($phone);
        $h = self::idHash($idNumber);
        if ($pk || $h) {
            $hit = SecurityWatch::current()->where(fn ($q) => $q->when($pk, fn ($w) => $w->orWhere('phone_key', $pk))->when($h, fn ($w) => $w->orWhere('id_hash', $h)))
                ->orderByRaw("level = 'block' desc")->first();
            if ($hit) {
                return ['entry' => $hit, 'on' => $h && $hit->id_hash === $h ? 'ID number' : 'phone number', 'level' => $hit->level];
            }
        }
        $name = trim((string) $name);
        if ($name !== '' && ($hit = SecurityWatch::current()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first())) {
            return ['entry' => $hit, 'on' => 'name only', 'level' => 'alert'];
        }

        return null;
    }

    /** Functions a visitor can be attending: yesterday (running past midnight) to tomorrow, not lost / cancelled. */
    public static function eventsNow()
    {
        $d = now()->toDateString();

        return BanquetEvent::whereIn('status', ['tentative', 'confirmed', 'completed'])
            ->whereDate('event_date', '>=', Carbon::parse($d)->subDay()->toDateString())->whereDate('event_date', '<=', Carbon::parse($d)->addDay()->toDateString())->orderBy('event_date')->orderBy('start_time');
    }

    protected static function mayOverride(): bool
    {
        return can_do('security.manage');
    }

    /** Keep visitor admission in the same reservation → stay → visitor lock order as checkout. */
    protected static function lockVisitor(Visitor $visitor): array
    {
        $snapshot = Visitor::whereKey($visitor->id)->firstOrFail();
        $stay = null;
        if ($snapshot->reservation_room_id) {
            $staySnapshot = ReservationRoom::find($snapshot->reservation_room_id);
            if ($staySnapshot) {
                Reservation::whereKey($staySnapshot->reservation_id)->lockForUpdate()->first();
                $stay = ReservationRoom::whereKey($staySnapshot->id)->lockForUpdate()->first();
            }
        }
        $locked = Visitor::whereKey($visitor->id)->lockForUpdate()->firstOrFail();

        return [$locked, $stay];
    }

    // ───────── visitors ─────────

    /**
     * Register a walk-in visitor at the desk / gate.
     * $d: name, phone, company, id_type, id_number, purpose, host_type, reservation_room_id | host_user_id | banquet_event_id,
     *     persons, vehicle_no, items_declared, notes, guest_confirmed (bool), override_reason
     */
    public static function register(array $d): Visitor
    {
        $cfg = self::cfg();
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException("Enter the visitor's name.");
        }
        if ($cfg['require_id'] && (empty($d['id_type']) || trim((string) ($d['id_number'] ?? '')) === '')) {
            throw new RuntimeException('Record the ID the visitor showed (type and number) – ID is required for every visitor.');
        }
        $override = trim((string) ($d['override_reason'] ?? ''));
        if ($override !== '' && ! self::mayOverride()) {
            throw new RuntimeException('Only a security manager can override – ask the duty manager.');
        }
        $hostType = array_key_exists($d['host_type'] ?? '', Visitor::HOSTS) ? $d['host_type'] : 'guest';
        $now = now();
        $v = new Visitor(['name' => mb_substr($name, 0, 120), 'phone' => trim((string) ($d['phone'] ?? '')) ?: null, 'company' => trim((string) ($d['company'] ?? '')) ?: null,
            'purpose' => array_key_exists($d['purpose'] ?? '', Visitor::PURPOSES) ? $d['purpose'] : ($hostType === 'event' ? 'event' : 'guest_visit'),
            'host_type' => $hostType, 'persons' => max(1, min(50, (int) ($d['persons'] ?? 1))),
            'vehicle_no' => strtoupper(trim((string) ($d['vehicle_no'] ?? ''))) ?: null, 'items_declared' => trim((string) ($d['items_declared'] ?? '')) ?: null,
            'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'source' => 'desk', 'created_by' => auth()->id()]);
        self::setIdentity($v, $d['id_type'] ?? null, $d['id_number'] ?? null);

        $status = 'in';
        $via = null;
        $until = $now->copy()->addHours($cfg['pass_hours']);
        $stayId = null;
        if ($hostType === 'guest') {
            $stay = ReservationRoom::with('guest', 'reservation.guest', 'room')->find($d['reservation_room_id'] ?? 0);
            if (! $stay || $stay->status !== 'checked_in') {
                throw new RuntimeException('Pick the in-house guest (room) being visited.');
            }
            $stayId = $stay->id;
            $g = $stay->guest ?? $stay->reservation?->guest;
            $v->fill(['reservation_room_id' => $stay->id, 'room_id' => $stay->room_id, 'guest_id' => $g?->id, 'host_name' => $g?->full_name]);
            if ($stay->visitor_policy === 'none' && $override === '') {
                throw new RuntimeException('This guest has asked for no visitors. Do not confirm that they are staying here – take a message instead.');
            }
            $cut = self::cutoff($now);
            if ($now->gte($cut) && $override === '') {
                throw new RuntimeException('Visiting hours for guest rooms end at '.$cfg['visiting_until'].'. Ask the guest to meet their visitor in the lobby, or get a manager override.');
            }
            if ($now->lt($cut)) {
                $until = $until->min($cut);
            }
            $mustCall = $cfg['approval'] === 'always' || $stay->visitor_policy === 'call';
            if ($mustCall && empty($d['guest_confirmed']) && $override === '') {
                $status = 'awaiting';
            } elseif (! empty($d['guest_confirmed'])) {
                $via = 'phone';
            }
        } elseif ($hostType === 'staff') {
            $u = User::where('hotel_id', Tenant::id())->where('is_active', true)->find($d['host_user_id'] ?? 0);
            if (! $u) {
                throw new RuntimeException('Pick the staff member being visited.');
            }
            $v->fill(['host_user_id' => $u->id, 'host_name' => $u->name]);
            $via = 'host';
        } else {
            $e = self::eventsNow()->find($d['banquet_event_id'] ?? 0);
            if (! $e) {
                throw new RuntimeException("Pick the event the visitor is attending (today's confirmed functions).");
            }
            $v->fill(['banquet_event_id' => $e->id, 'host_name' => mb_substr($e->event_no.' '.($e->title ?: $e->contact_name), 0, 150)]);
            $via = 'host';
        }

        $hit = self::screen($v->name, $v->phone, $d['id_number'] ?? null);
        if ($hit && $hit['level'] === 'block' && $override === '') {
            throw new RuntimeException("Entry refused – {$v->name} matches a barred person on the watch list ({$hit['on']}): {$hit['entry']->reason}. A security manager can override with a reason.");
        }
        if ($hit) {
            $v->watchlist_id = $hit['entry']->id;
        }
        if ($override !== '') {
            $v->override_reason = mb_substr($override, 0, 255);
            if ($status === 'in') {
                $via = 'override';
            }
        }
        $v->fill(['status' => $status, 'approved_via' => $status === 'in' ? $via : null, 'approved_by' => $status === 'in' && $via ? auth()->id() : null,
            'valid_until' => $until, 'checked_in_at' => $status === 'in' ? $now : null, 'checked_in_by' => $status === 'in' ? auth()->id() : null]);

        return DB::transaction(function () use ($v, $override, $stayId, $d, $cfg) {
            if ($stayId) {
                $staySnapshot = ReservationRoom::whereKey($stayId)->firstOrFail();
                Reservation::whereKey($staySnapshot->reservation_id)->lockForUpdate()->firstOrFail();
                $stay = ReservationRoom::whereKey($staySnapshot->id)->lockForUpdate()->firstOrFail();
                $stay->load(['guest', 'reservation.guest']);
                if ($stay->status !== 'checked_in') {
                    throw new RuntimeException('Pick the in-house guest (room) being visited.');
                }
                if ($stay->visitor_policy === 'none' && $override === '') {
                    throw new RuntimeException('This guest has asked for no visitors. Do not confirm that they are staying here – take a message instead.');
                }
                $now = now();
                $cut = self::cutoff($now);
                if ($now->gte($cut) && $override === '') {
                    throw new RuntimeException('Visiting hours for guest rooms end at '.$cfg['visiting_until'].'. Ask the guest to meet their visitor in the lobby, or get a manager override.');
                }
                $guest = $stay->guest ?? $stay->reservation?->guest;
                $until = $now->copy()->addHours($cfg['pass_hours']);
                if ($now->lt($cut)) {
                    $until = $until->min($cut);
                }
                $v->fill(['reservation_room_id' => $stay->id, 'room_id' => $stay->room_id, 'guest_id' => $guest?->id, 'host_name' => $guest?->full_name,
                    'status' => 'in', 'approved_via' => null, 'approved_by' => null, 'checked_in_at' => null, 'checked_in_by' => null,
                    'valid_until' => $until]);
                $mustCall = $cfg['approval'] === 'always' || $stay->visitor_policy === 'call';
                if ($mustCall && empty($d['guest_confirmed']) && $override === '') {
                    $v->fill(['status' => 'awaiting', 'approved_via' => null, 'approved_by' => null, 'checked_in_at' => null, 'checked_in_by' => null]);
                } else {
                    $via = $override !== '' ? 'override' : (! empty($d['guest_confirmed']) ? 'phone' : null);
                    $v->fill(['approved_via' => $via, 'approved_by' => $via ? auth()->id() : null, 'checked_in_at' => $now, 'checked_in_by' => auth()->id()]);
                }
            }
            $currentHit = self::screen($v->name, $v->phone, $d['id_number'] ?? null);
            if ($currentHit && $currentHit['level'] === 'block' && $override === '') {
                throw new RuntimeException("Entry refused – {$v->name} matches a barred person on the watch list ({$currentHit['on']}): {$currentHit['entry']->reason}. A security manager can override with a reason.");
            }
            $v->watchlist_id = $currentHit['entry']->id ?? null;
            $v->pass_no = Numbering::next('visitor');
            $v->save();
            ActivityLogger::log('security', 'visitor', "Visitor {$v->pass_no} {$v->name} → {$v->hostLabel()} (".$v->statusLabel().')'.($override !== '' ? ' – OVERRIDE: '.$override : ''), $v);
            if ($currentHit) {
                self::watchAlert($v, $currentHit);
            }

            return $v;
        });
    }

    protected static function setIdentity(Visitor $v, ?string $type, ?string $number): void
    {
        if ($type && array_key_exists($type, Visitor::ID_TYPES) && trim((string) $number) !== '') {
            $v->fill(['id_type' => $type, 'id_last4' => self::idLast4($number), 'id_hash' => self::idHash($number)]);
        }
    }

    protected static function watchAlert(Visitor $v, array $hit): void
    {
        LogBookService::system("Watch-list match: visitor {$v->name} ({$v->pass_no})",
            "Matched on {$hit['on']} – ".SecurityWatch::LEVELS[$hit['entry']->level][0].': '.$hit['entry']->reason.'. Going to '.$v->hostLabel().'.'
            .($v->override_reason ? ' Let in on manager override: '.$v->override_reason : ''),
            'security', $hit['level'] === 'block' ? 'urgent' : 'high', ['department' => 'security', 'reservation_room_id' => $v->reservation_room_id], true);
    }

    /** The desk reached the guest (or host) and they said yes. */
    public static function approve(Visitor $v, string $via = 'phone'): Visitor
    {
        return DB::transaction(function () use ($v, $via) {
            [$locked, $stay] = self::lockVisitor($v);
            if ($locked->status !== 'awaiting') {
                throw new RuntimeException('This visitor is not waiting for approval.');
            }
            if ($locked->reservation_room_id && $stay?->status !== 'checked_in') {
                throw new RuntimeException('The guest is no longer in-house.');
            }
            $now = now();
            $until = $now->copy()->addHours(self::cfg()['pass_hours']);
            if ($locked->host_type === 'guest') {
                if ($stay?->visitor_policy === 'none') {
                    throw new RuntimeException('This guest has asked for no visitors. Do not confirm that they are staying here – take a message instead.');
                }
                $cutoff = self::cutoff($now);
                if ($now->gte($cutoff)) {
                    throw new RuntimeException('Visiting hours for guest rooms have ended. Ask the visitor to meet the guest in the lobby.');
                }
                $until = $until->min($cutoff);
            }
            $locked->forceFill(['status' => 'in', 'approved_via' => array_key_exists($via, Visitor::APPROVALS) ? $via : 'phone', 'approved_by' => auth()->id(),
                'checked_in_at' => $now, 'checked_in_by' => auth()->id(), 'valid_until' => $until])->save();
            ActivityLogger::log('security', 'visitor', "Visitor {$locked->pass_no} approved – guest confirmed", $locked);

            return $locked;
        });
    }

    public static function deny(Visitor $v, string $reason): Visitor
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Say why the visitor was turned away.');
        }

        return DB::transaction(function () use ($v, $reason) {
            [$locked] = self::lockVisitor($v);
            if (! in_array($locked->status, ['awaiting', 'expected'], true)) {
                throw new RuntimeException('Only visitors waiting at the desk can be turned away.');
            }
            $locked->forceFill(['status' => 'denied', 'deny_reason' => mb_substr(trim($reason), 0, 255), 'approved_by' => auth()->id()])->save();
            ActivityLogger::log('security', 'visitor', "Visitor {$locked->pass_no} turned away: {$reason}", $locked);

            return $locked;
        });
    }

    /** A pre-registered visitor turns up: record their ID, screen, admit. */
    public static function arrive(Visitor $v, array $d): Visitor
    {
        $cfg = self::cfg();
        if ($cfg['require_id'] && (empty($d['id_type']) || trim((string) ($d['id_number'] ?? '')) === '')) {
            throw new RuntimeException('Record the ID the visitor showed (type and number).');
        }
        $override = trim((string) ($d['override_reason'] ?? ''));
        if ($override !== '' && ! self::mayOverride()) {
            throw new RuntimeException('Only a security manager can override – ask the duty manager.');
        }

        return DB::transaction(function () use ($v, $d, $cfg, $override) {
            [$locked, $stay] = self::lockVisitor($v);
            if ($locked->status !== 'expected') {
                throw new RuntimeException('This visitor is not expected.');
            }
            if ($locked->reservation_room_id && $stay?->status !== 'checked_in') {
                throw new RuntimeException('The guest who invited this visitor is no longer in-house.');
            }
            if ($locked->host_type === 'guest' && $stay?->visitor_policy === 'none' && $override === '') {
                throw new RuntimeException('This guest has asked for no visitors. Ask the duty manager before admitting anyone.');
            }
            $now = now();
            if ($locked->host_type === 'guest' && $now->gte(self::cutoff($now)) && $override === '') {
                throw new RuntimeException('Visiting hours for guest rooms end at '.$cfg['visiting_until'].'. Ask the guest to come down to the lobby, or get a manager override.');
            }
            if (! empty($d['phone'])) {
                $locked->phone = trim($d['phone']);
            }
            self::setIdentity($locked, $d['id_type'] ?? null, $d['id_number'] ?? null);
            $hit = self::screen($locked->name, $locked->phone, $d['id_number'] ?? null);
            if ($hit && $hit['level'] === 'block' && $override === '') {
                throw new RuntimeException("Entry refused – {$locked->name} matches a barred person on the watch list ({$hit['on']}): {$hit['entry']->reason}. A security manager can override with a reason.");
            }
            $until = $now->copy()->addHours($cfg['pass_hours']);
            if ($locked->host_type === 'guest' && $now->lt(self::cutoff($now))) {
                $until = $until->min(self::cutoff($now));
            }
            $locked->fill(['status' => 'in', 'checked_in_at' => $now, 'checked_in_by' => auth()->id(), 'valid_until' => $until, 'watchlist_id' => $hit['entry']->id ?? null,
                'vehicle_no' => strtoupper(trim((string) ($d['vehicle_no'] ?? ''))) ?: $locked->vehicle_no,
                'override_reason' => $override !== '' ? mb_substr($override, 0, 255) : null, 'approved_via' => $override !== '' ? 'override' : 'pre_registered'])->save();
            ActivityLogger::log('security', 'visitor', "Pre-registered visitor {$locked->pass_no} {$locked->name} arrived", $locked);
            if ($hit) {
                self::watchAlert($locked, $hit);
            }

            return $locked;
        });
    }

    public static function checkOut(Visitor $v): Visitor
    {
        return DB::transaction(function () use ($v) {
            [$locked] = self::lockVisitor($v);
            if ($locked->status !== 'in') {
                throw new RuntimeException('This visitor is not on site.');
            }
            $now = now();
            $overstayed = $locked->valid_until && $locked->valid_until->lt($now);
            $locked->forceFill(['status' => 'out', 'checked_out_at' => $now, 'checked_out_by' => auth()->id()])->save();
            ActivityLogger::log('security', 'visitor', "Visitor {$locked->pass_no} {$locked->name} left".($overstayed ? ' (overstayed)' : ''), $locked);

            return $locked;
        });
    }

    public static function cancel(Visitor $v, string $reason = 'Cancelled'): Visitor
    {
        return DB::transaction(function () use ($v, $reason) {
            [$locked] = self::lockVisitor($v);
            if ($locked->status !== 'expected') {
                throw new RuntimeException('Only expected visitors can be cancelled.');
            }
            $locked->forceFill(['status' => 'cancelled', 'deny_reason' => mb_substr($reason, 0, 255), 'pre_token' => null])->save();
            ActivityLogger::log('security', 'visitor', "Expected visitor {$locked->pass_no} cancelled: {$reason}", $locked);

            return $locked;
        });
    }

    public static function extend(Visitor $v, int $hours): Visitor
    {
        $hours = max(1, min(12, $hours));

        return DB::transaction(function () use ($v, $hours) {
            [$locked] = self::lockVisitor($v);
            if ($locked->status !== 'in') {
                throw new RuntimeException('This visitor is not on site.');
            }
            $now = now();
            $base = $locked->valid_until && $locked->valid_until->gt($now) ? $locked->valid_until : $now;
            $locked->forceFill(['valid_until' => $base->copy()->addHours($hours), 'overstay_alerted_at' => null])->save();
            ActivityLogger::log('security', 'visitor', "Visitor pass {$locked->pass_no} extended by {$hours} h", $locked);

            return $locked;
        });
    }

    public static function setPolicy(ReservationRoom $stay, string $policy, string $by = 'desk'): ReservationRoom
    {
        if (! array_key_exists($policy, self::POLICIES)) {
            throw new RuntimeException('Unknown visitor preference.');
        }

        return DB::transaction(function () use ($stay, $policy, $by) {
            $snapshot = ReservationRoom::whereKey($stay->id)->firstOrFail();
            Reservation::whereKey($snapshot->reservation_id)->lockForUpdate()->firstOrFail();
            $locked = ReservationRoom::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['reservation', 'room']);
            if (! in_array($locked->portalState(), ['inhouse', 'upcoming'], true)) {
                throw new RuntimeException('Visitor preferences can only be changed for a current or upcoming stay.');
            }
            $locked->forceFill(['visitor_policy' => $policy])->save();
            if ($policy === 'none') {
                Visitor::where('reservation_room_id', $locked->id)->whereIn('status', ['expected', 'awaiting'])
                    ->orderBy('id')->lockForUpdate()->get()->each(function (Visitor $visitor) {
                        $visitor->forceFill(['status' => 'cancelled', 'deny_reason' => 'Guest requested no visitors', 'pre_token' => null])->save();
                        ActivityLogger::log('security', 'visitor', "Visitor {$visitor->pass_no} cancelled: guest requested no visitors", $visitor);
                    });
            }
            ActivityLogger::log('security', 'policy', 'Visitor preference for room '.($locked->room?->room_no ?? '#'.$locked->id).': '.self::POLICIES[$policy]." (set by {$by})", $locked);

            return $locked;
        });
    }

    /** Guest pre-registers a visitor from the portal – gives them a pass link to share. */
    public static function preRegister(ReservationRoom $stay, array $d): Visitor
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException("Enter your visitor's name.");
        }
        $at = Carbon::parse(($d['date'] ?? '').' '.($d['time'] ?? ''), config('app.timezone'));

        return DB::transaction(function () use ($stay, $d, $name, $at) {
            $staySnapshot = ReservationRoom::whereKey($stay->id)->firstOrFail();
            Reservation::whereKey($staySnapshot->reservation_id)->lockForUpdate()->firstOrFail();
            $lockedStay = ReservationRoom::whereKey($staySnapshot->id)->lockForUpdate()->firstOrFail();
            $lockedStay->load(['guest', 'reservation.guest']);
            if (! in_array($lockedStay->portalState(), ['inhouse', 'upcoming'], true)) {
                throw new RuntimeException('Visitors can only be registered for a current or upcoming stay.');
            }
            if ($lockedStay->visitor_policy === 'none') {
                throw new RuntimeException('You asked the hotel not to accept visitors for this stay.');
            }
            $now = now();
            $from = max($now->toDateString(), $lockedStay->arrival_date->toDateString());
            if ($at->toDateString() < $from || $at->toDateString() > $lockedStay->departure_date->toDateString() || $at->lt($now->copy()->subMinutes(10))) {
                throw new RuntimeException('Choose a time during your stay (from now until your departure day).');
            }
            if ($at->gte(self::cutoff($at))) {
                throw new RuntimeException('Visiting hours end at '.self::cfg()['visiting_until'].' – please choose an earlier time, or meet your visitor in the lobby.');
            }
            if (Visitor::where('reservation_room_id', $lockedStay->id)->where('status', 'expected')->count() >= 10) {
                throw new RuntimeException('You already have 10 visitors registered – please call the front desk.');
            }
            $g = $lockedStay->guest ?? $lockedStay->reservation?->guest;
            $until = $at->copy()->addHours(self::cfg()['pass_hours'])->min(self::cutoff($at));
            $v = Visitor::create(['pass_no' => Numbering::next('visitor'), 'name' => mb_substr($name, 0, 120), 'phone' => trim((string) ($d['phone'] ?? '')) ?: null,
                'purpose' => 'guest_visit', 'host_type' => 'guest', 'reservation_room_id' => $lockedStay->id, 'room_id' => $lockedStay->room_id, 'guest_id' => $g?->id, 'host_name' => $g?->full_name,
                'persons' => max(1, min(10, (int) ($d['persons'] ?? 1))), 'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'status' => 'expected', 'source' => 'portal',
                'approved_via' => 'pre_registered', 'expected_at' => $at, 'valid_until' => $until, 'pre_token' => Str::random(40)]);
            ActivityLogger::log('security', 'visitor', "Guest pre-registered visitor {$v->pass_no} {$v->name} for ".$at->format('d M H:i'), $v);

            return $v;
        });
    }

    /** At check-out: pending visits lapse, anyone still on site is flagged to security. */
    public static function onCheckOut(ReservationRoom $stay): void
    {
        Visitor::where('reservation_room_id', $stay->id)->whereIn('status', ['expected', 'awaiting'])
            ->update(['status' => 'cancelled', 'deny_reason' => 'Guest checked out', 'pre_token' => null, 'updated_at' => now()]);
        $inside = Visitor::where('reservation_room_id', $stay->id)->where('status', 'in')->get();
        if ($inside->isNotEmpty()) {
            LogBookService::system('Visitors still on site after check-out – room '.($stay->room?->room_no ?? '?'),
                $inside->map(fn ($v) => "{$v->pass_no} {$v->name}")->join(', ').'. Their host has checked out – escort them out and close the passes.',
                'security', 'high', ['department' => 'security', 'reservation_room_id' => $stay->id], true);
        }
    }

    // ───────── watch list ─────────

    public static function saveWatch(array $d, ?SecurityWatch $w = null): SecurityWatch
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '' || trim((string) ($d['reason'] ?? '')) === '') {
            throw new RuntimeException('Name and reason are required.');
        }
        $w ??= new SecurityWatch(['created_by' => auth()->id()]);
        $w->fill(['name' => mb_substr($name, 0, 120), 'phone' => trim((string) ($d['phone'] ?? '')) ?: null, 'phone_key' => self::phoneKey($d['phone'] ?? null),
            'level' => ($d['level'] ?? 'block') === 'alert' ? 'alert' : 'block', 'reason' => mb_substr(trim($d['reason']), 0, 255),
            'valid_until' => $d['valid_until'] ?? null, 'is_active' => array_key_exists('is_active', $d) ? (bool) $d['is_active'] : true]);
        if (trim((string) ($d['id_number'] ?? '')) !== '') {
            $w->fill(['id_type' => $d['id_type'] ?? null, 'id_last4' => self::idLast4($d['id_number']), 'id_hash' => self::idHash($d['id_number'])]);
        }
        $new = ! $w->exists;
        $w->save();
        ActivityLogger::log('security', 'watchlist', ($new ? 'Added to' : 'Updated on').' the watch list: '.$w->name.' ('.$w->level.')', $w);

        return $w;
    }

    // ───────── scheduled run ─────────

    /** pms:security – overstay alerts, no-shows, overdue gate-pass returns, ID-data retention. */
    public static function run(): array
    {
        $now = now();
        $r = ['overstays' => 0, 'no_shows' => 0, 'overdue_passes' => 0, 'anonymised' => 0];
        foreach (Visitor::with('room')->where('status', 'in')->where('valid_until', '<', $now)->whereNull('overstay_alerted_at')->get() as $v) {
            LogBookService::system("Visitor overstay: {$v->name} ({$v->pass_no})", 'Pass expired at '.$v->valid_until->format('H:i').' – visiting '.$v->hostLabel()
                .'. Check whether they are still on the premises; extend the pass or check them out.', 'security', 'high',
                ['department' => 'security', 'reservation_room_id' => $v->reservation_room_id], true);
            $v->forceFill(['overstay_alerted_at' => $now])->saveQuietly();
            $r['overstays']++;
        }
        $r['no_shows'] = Visitor::where('status', 'expected')->where('valid_until', '<', $now)->update(['status' => 'no_show', 'pre_token' => null, 'updated_at' => $now]);
        $r['overdue_passes'] = GatePassService::alertOverdue();
        $r['anonymised'] = self::anonymiseOld();

        return $r;
    }

    /** Visitor identity data older than the retention period is wiped (the fact of the visit is kept). */
    public static function anonymiseOld(): int
    {
        $cut = now()->subDays(self::cfg()['retention_days']);

        return Visitor::whereNull('anonymised_at')->whereNotIn('status', Visitor::OPEN)
            ->where(fn ($q) => $q->where('checked_out_at', '<', $cut)->orWhere(fn ($w) => $w->whereNull('checked_out_at')->where('updated_at', '<', $cut)))
            ->update(['name' => self::ANON_NAME, 'phone' => null, 'company' => null, 'id_last4' => null, 'id_hash' => null, 'vehicle_no' => null,
                'items_declared' => null, 'notes' => null, 'pre_token' => null, 'anonymised_at' => now(), 'updated_at' => now()]);
    }

    // ───────── dashboards & badges ─────────

    public static function snapshot(): array
    {
        $now = now();
        $bd = bdate()->toDateString();

        return [
            'on_site' => (int) Visitor::where('status', 'in')->sum('persons'),
            'passes_open' => Visitor::where('status', 'in')->count(),
            'awaiting' => Visitor::where('status', 'awaiting')->count(),
            'overstays' => Visitor::where('status', 'in')->where('valid_until', '<', $now)->count(),
            'expected_today' => Visitor::where('status', 'expected')->whereDate('expected_at', $now->toDateString())->count(),
            'visitors_today' => Visitor::whereDate('checked_in_at', $now->toDateString())->count(),
            'gp_pending' => GatePass::where('status', 'pending')->count(),
            'gp_away' => GatePass::whereIn('status', GatePass::AWAY)->count(),
            'gp_overdue' => GatePass::where('type', 'returnable')->whereIn('status', GatePass::AWAY)->whereDate('expected_return_date', '<', $bd)->count(),
            'inc_open' => SecurityIncident::where('status', '!=', 'closed')->count(),
            'inc_serious' => SecurityIncident::where('status', '!=', 'closed')->whereIn('severity', ['high', 'critical'])->count(),
            'inc_month' => SecurityIncident::where('occurred_at', '>=', bdate()->copy()->startOfMonth())->count(),
        ];
    }

    /** Sidebar badge: visitors waiting for the guest's OK + overstays. */
    public static function visitorAttention(): int
    {
        return Visitor::where('status', 'awaiting')->count() + Visitor::where('status', 'in')->where('valid_until', '<', now())->count();
    }
}
