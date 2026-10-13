<?php

namespace App\Services;

use App\Mail\KpiAlertMail;
use App\Models\AuditFinding;
use App\Models\Folio;
use App\Models\KpiAlert;
use App\Models\KpiAlertEvent;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * v5.4 KPI threshold alerts. Every metric resolves to one number for a business date:
 *  - "flash" metrics come from the manager's flash report (day or month-to-date figures for the audited date);
 *  - "now" metrics are live operational positions (on-the-books, out-of-order rooms, arrears, open exceptions).
 * Alerts are evaluated after each night audit (and on demand); a breach creates one event per alert per business
 * date, shown on the dashboard until acknowledged and optionally e-mailed / texted to the recipients.
 */
class KpiService
{
    /** key => [label, format (pct|money|int), source (flash|now), help] */
    public const METRICS = [
        'occ' => ['Occupancy %', 'pct', 'flash', 'Room nights sold ÷ rooms available'],
        'adr' => ['ADR', 'money', 'flash', 'Room revenue ÷ room nights sold'],
        'revpar' => ['RevPAR', 'money', 'flash', 'Room revenue ÷ rooms available'],
        'room' => ['Room revenue', 'money', 'flash', 'Room charges posted'],
        'fnb' => ['F&B revenue', 'money', 'flash', 'Restaurant / bar sales incl. room-charged'],
        'net' => ['Net revenue', 'money', 'flash', 'Room + F&B + other − discounts'],
        'collect' => ['Collections', 'money', 'flash', 'Cash, card, MoMo & bank received'],
        'disc' => ['Discounts & allowances', 'money', 'flash', 'Discounts and allowances posted'],
        'noshow' => ['No-shows', 'int', 'flash', 'Reservations marked no-show'],
        'cancel' => ['Cancellations', 'int', 'flash', 'Reservations cancelled'],
        'otb_tomorrow' => ['On-the-books occupancy tomorrow', 'pct', 'now', 'Rooms booked for the next night ÷ rooms'],
        'otb_next7' => ['On-the-books occupancy, next 7 nights', 'pct', 'now', 'Average of the next 7 nights'],
        'ooo_rooms' => ['Out-of-order rooms', 'int', 'now', 'Rooms currently out of order'],
        'inhouse_balance' => ['In-house guest balances', 'money', 'now', 'Unsettled balance on open folios of in-house guests'],
        'ar_over_60' => ['City ledger over 60 days', 'money', 'now', 'Company bills unpaid for more than 60 days'],
        'lease_arrears' => ['Lease rent arrears', 'money', 'now', 'Overdue serviced-apartment rent past the grace period'],
        'open_exceptions' => ['Open income-audit exceptions', 'int', 'now', 'Unresolved findings on open audit days'],
        'pending_auth' => ['Pending revenue authorisations', 'int', 'now', 'Discounts / comps waiting for approval'],
        'low_stock' => ['Items at re-order level', 'int', 'now', 'Inventory items at or below re-order level'],
    ];

    private static array $flashCache = [];

    public static function format(float $v, string $fmt): string
    {
        return match ($fmt) {
            'pct' => number_format($v, 1).' %',
            'money' => money($v),
            default => number_format($v, 0),
        };
    }

    public static function metricLabel(string $key): string
    {
        return self::METRICS[$key][0] ?? $key;
    }

    /** The date alerts are evaluated for: the last audited business date. */
    public static function defaultDate(): Carbon
    {
        return bdate()->copy()->subDay();
    }

    public static function value(string $metric, string $period, Carbon $date): float
    {
        $def = self::METRICS[$metric] ?? throw new RuntimeException("Unknown KPI metric '{$metric}'.");
        if ($def[2] === 'flash') {
            $key = \App\Support\Tenant::id().'|'.$date->toDateString();
            self::$flashCache[$key] ??= ManagementReportService::flash($date->copy())[0];

            return round((float) (self::$flashCache[$key][$period === 'mtd' ? 'mtd' : 'day'][$metric] ?? 0), 2);
        }

        return round(match ($metric) {
            'otb_tomorrow' => self::otb($date->copy()->addDay(), 1),
            'otb_next7' => self::otb($date->copy()->addDay(), 7),
            'ooo_rooms' => (float) Room::where('is_active', true)->where('status', 'out_of_order')->count(),
            'inhouse_balance' => (float) Folio::where('status', 'open')->whereHas('stay', fn ($q) => $q->where('status', 'checked_in'))
                ->get()->sum(fn ($f) => max(0, $f->balance())),
            'ar_over_60' => (float) ManagementReportService::arAgeing($date->copy())->sum(fn ($r) => $r['b60'] + $r['b90']),
            'lease_arrears' => class_exists(LeaseBillingService::class) ? (float) LeaseBillingService::arrears($date->toDateString())->sum('amount') : 0,
            'open_exceptions' => (float) AuditFinding::whereIn('status', AuditFinding::UNRESOLVED)->whereHas('day', fn ($q) => $q->where('status', 'open'))->count(),
            'pending_auth' => (float) RevenueAuthService::pendingCount(),
            'low_stock' => (float) StockTakeService::reorderSuggestions()->count(),
        }, 2);
    }

    /** Average occupancy on the books over $nights nights starting $from. */
    private static function otb(Carbon $from, int $nights): float
    {
        $rooms = Room::where('is_active', true)->count();
        if (! $rooms) {
            return 0;
        }
        $to = $from->copy()->addDays($nights);
        $stays = ReservationRoom::whereIn('status', ['reserved', 'checked_in'])
            ->whereDate('arrival_date', '<', $to->toDateString())->whereDate('departure_date', '>', $from->toDateString())->get(['arrival_date', 'departure_date']);
        $sold = 0;
        for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
            $sold += $stays->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d))->count();
        }

        return $sold * 100 / ($rooms * $nights);
    }

    public static function breached(KpiAlert $a, float $value): bool
    {
        return $a->operator === 'below' ? $value < (float) $a->threshold : $value > (float) $a->threshold;
    }

    /**
     * Evaluate every active alert for a business date.
     *
     * @return array{evaluated:int, triggered:int, events:KpiAlertEvent[]}
     */
    public static function evaluate(?Carbon $date = null, bool $notify = true, ?KpiAlert $only = null): array
    {
        $date ??= self::defaultDate();
        self::$flashCache = [];
        $alerts = $only ? collect([$only]) : KpiAlert::where('is_active', true)->orderBy('id')->get();
        $events = [];
        foreach ($alerts as $a) {
            try {
                $v = self::value($a->metric, $a->period, $date);
            } catch (\Throwable $e) {
                report($e);

                continue;
            }
            $a->forceFill(['last_value' => $v, 'last_date' => $date->toDateString(), 'last_evaluated_at' => now()])->save();
            if (! self::breached($a, $v) || KpiAlertEvent::where('kpi_alert_id', $a->id)->whereDate('business_date', $date)->exists()) {
                continue;
            }
            $fmt = self::METRICS[$a->metric][1] ?? 'num';
            $ev = KpiAlertEvent::create(['kpi_alert_id' => $a->id, 'business_date' => $date->toDateString(), 'value' => $v, 'threshold' => $a->threshold,
                'severity' => $a->severity,
                'message' => mb_substr($a->name.': '.self::metricLabel($a->metric).' is '.self::format($v, $fmt).' – '.$a->operator.' the '.self::format((float) $a->threshold, $fmt).' threshold ('.$date->format('d M Y').').', 0, 300)]);
            $a->forceFill(['last_triggered_at' => now(), 'triggers' => $a->triggers + 1])->save();
            if ($notify) {
                $ev->forceFill(['notified' => self::notify($a, $ev)])->save();
            }
            $events[] = $ev;
        }

        return ['evaluated' => $alerts->count(), 'triggered' => count($events), 'events' => $events];
    }

    /** E-mail and/or SMS the recipients; in-app is always on (dashboard + nav badge). Returns messages sent. */
    public static function notify(KpiAlert $a, KpiAlertEvent $ev): int
    {
        $ch = $a->channels ?? [];
        $sent = 0;
        $users = User::whereIn('id', $a->recipient_users ?? [])->where('is_active', true)->get();
        if (in_array('email', $ch, true)) {
            $emails = array_unique(array_map('strtolower', array_merge($users->pluck('email')->filter()->all(),
                preg_split('/[\s,;]+/', (string) $a->recipient_emails, -1, PREG_SPLIT_NO_EMPTY))));
            foreach ($emails as $to) {
                $log = MailService::send($to, new KpiAlertMail($a, $ev), 'kpi_alert', $ev);
                $sent += $log && $log->status === 'sent' ? 1 : 0;
            }
        }
        if (in_array('sms', $ch, true)) {
            $phones = array_unique(array_merge($users->pluck('phone')->filter()->all(), preg_split('/[\s,;]+/', (string) $a->recipient_phones, -1, PREG_SPLIT_NO_EMPTY)));
            foreach ($phones as $to) {
                $log = MessageService::send($to, mb_substr('['.strtoupper($a->severity).'] '.$ev->message, 0, 300), 'sms', 'kpi_alert', $ev);
                $sent += $log && $log->status === 'sent' ? 1 : 0;
            }
        }

        return $sent;
    }

    public static function acknowledge(KpiAlertEvent $ev, User $by, ?string $note = null): void
    {
        if ($ev->acknowledged_at) {
            throw new RuntimeException('This alert was already acknowledged by '.($ev->acknowledger?->name ?? 'someone').'.');
        }
        $ev->forceFill(['acknowledged_by' => $by->id, 'acknowledged_at' => now(), 'ack_note' => $note ? mb_substr($note, 0, 255) : null])->save();
        ActivityLogger::log('reports', 'kpi_ack', 'Acknowledged KPI alert: '.$ev->message, $ev);
    }

    /** Unacknowledged events from the last 14 days – nav badge + dashboard card. */
    public static function openEvents()
    {
        return KpiAlertEvent::with('alert')->whereNull('acknowledged_at')
            ->where('business_date', '>=', bdate()->copy()->subDays(14)->toDateString())
            ->orderByRaw("FIELD(severity,'critical','warning','info')")->orderByDesc('business_date')->get();
    }

    public static function openCount(): int
    {
        return KpiAlertEvent::whereNull('acknowledged_at')->where('business_date', '>=', bdate()->copy()->subDays(14)->toDateString())->count();
    }

    public static function save(array $d, User $by, ?KpiAlert $a = null): KpiAlert
    {
        if (! isset(self::METRICS[$d['metric'] ?? ''])) {
            throw new RuntimeException('Choose a metric.');
        }
        if (! in_array($d['operator'] ?? '', ['below', 'above'], true)) {
            throw new RuntimeException('Choose whether the alert fires below or above the threshold.');
        }
        $channels = array_values(array_intersect($d['channels'] ?? [], ['email', 'sms']));
        $users = User::whereIn('id', $d['recipient_users'] ?? [])->where('is_active', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $emails = preg_split('/[\s,;]+/', (string) ($d['recipient_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($emails as $e) {
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("'{$e}' is not a valid e-mail address.");
            }
            ReportScheduleService::checkDomain($e);
        }
        $phones = preg_split('/[\s,;]+/', (string) ($d['recipient_phones'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($phones as $p) {
            if (! preg_match('/^\+?[0-9]{9,15}$/', $p)) {
                throw new RuntimeException("'{$p}' is not a valid phone number.");
            }
        }
        if (in_array('email', $channels, true) && ! $users && ! $emails) {
            throw new RuntimeException('E-mail is ticked but there are no recipients.');
        }
        if (in_array('sms', $channels, true) && ! $phones && ! User::whereIn('id', $users)->whereNotNull('phone')->exists()) {
            throw new RuntimeException('SMS is ticked but no recipient has a phone number.');
        }
        $data = ['name' => trim($d['name'] ?? '') ?: self::metricLabel($d['metric']).' '.$d['operator'].' '.$d['threshold'],
            'metric' => $d['metric'], 'period' => ($d['period'] ?? 'day') === 'mtd' ? 'mtd' : 'day', 'operator' => $d['operator'],
            'threshold' => round((float) $d['threshold'], 2), 'severity' => isset(KpiAlert::SEVERITIES[$d['severity'] ?? '']) ? $d['severity'] : 'warning',
            'channels' => $channels, 'recipient_users' => $users, 'recipient_emails' => implode(', ', $emails) ?: null,
            'recipient_phones' => implode(', ', $phones) ?: null, 'is_active' => (bool) ($d['is_active'] ?? true)];
        if ($a) {
            $a->update($data);
        } else {
            $a = KpiAlert::create($data + ['user_id' => $by->id]);
        }
        ActivityLogger::log('reports', 'kpi_alert', 'Saved KPI alert '.$a->name.' ('.$a->rule().')', $a);

        return $a;
    }
}
