<?php

namespace App\Services;

use App\Http\Controllers\ReportController;
use App\Mail\ScheduledReportMail;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\ReportView;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * v5.4 Report automation.
 *  - Relative date ranges ("yesterday", "month to date", "next 30 days") resolved against the business date, so a
 *    saved view or schedule always shows the current period.
 *  - Saved views: report + range + chosen columns + text filter; personal, or shared with everyone who may see the report.
 *  - Schedules: e-mail a report daily / weekly / monthly or straight after the night audit, as an HTML table and/or CSV.
 *    The report runs with the schedule owner's permissions, re-checked at every run; the recipients' domains are
 *    checked against an allow-list; repeated failures pause the schedule. Every run is logged as a delivery.
 */
class ReportScheduleService
{
    public const RANGES = [
        'today' => 'Today', 'yesterday' => 'Yesterday (last audited day)', 'last_7' => 'Last 7 days', 'last_30' => 'Last 30 days',
        'wtd' => 'Week to date', 'mtd' => 'Month to date', 'last_month' => 'Last month', 'qtd' => 'Quarter to date', 'ytd' => 'Year to date',
        'last_year' => 'Last year', 'next_7' => 'Next 7 days', 'next_30' => 'Next 30 days', 'next_90' => 'Next 90 days', 'custom' => 'Fixed dates',
    ];

    /** Ranges that make sense for an "as on" report (only the end date is used). */
    public const DATE_RANGES = ['today', 'yesterday', 'last_month'];

    public const SETTINGS = [
        'rpt_email_domains' => ['', 'Allowed e-mail domains for report recipients (comma-separated, blank = any)'],
        'rpt_max_recipients' => ['20', 'Maximum recipients per schedule'],
        'rpt_fail_limit' => ['5', 'Pause a schedule after this many failed runs in a row'],
        'rpt_inline_rows' => ['200', 'Rows shown in the e-mail body (the CSV always has every row)'],
    ];

    public static function cfg(string $key): string
    {
        return (string) setting($key, self::SETTINGS[$key][0]);
    }

    public static function saveSettings(array $d): void
    {
        foreach (self::SETTINGS as $k => [$def]) {
            if (array_key_exists($k, $d)) {
                $v = trim((string) $d[$k]);
                if ($k === 'rpt_email_domains') {
                    $v = implode(', ', array_unique(array_filter(array_map(fn ($x) => strtolower(ltrim(trim($x), '@')), preg_split('/[\s,;]+/', $v)))));
                } else {
                    $v = (string) max(1, min($k === 'rpt_inline_rows' ? 2000 : 100, (int) $v));
                }
                Setting::updateOrCreate(['hotel_id' => \App\Support\Tenant::id(), 'key' => $k], ['value' => $v]);
            }
        }
        Setting::$version++;
    }

    // ───────────────────────── ranges & generation ─────────────────────────

    public static function report(string $key): array
    {
        return ReportController::REPORTS[$key] ?? throw new RuntimeException("Unknown report '{$key}'.");
    }

    public static function mode(string $key): string
    {
        return self::report($key)[3] ?? 'none';
    }

    public static function perm(string $key): string
    {
        return self::report($key)[4] ?? 'reports.view';
    }

    public static function defaultRange(string $key): string
    {
        return match (true) {
            in_array($key, ['flash', 'trial_balance', 'gl_pnl', 'balance_sheet', 'journal_register'], true) => 'yesterday',
            in_array($key, ['rm_forecast', 'channel_parity'], true) => 'next_30',
            $key === 'lease_expiry' => 'next_90',
            self::mode($key) === 'date' => 'yesterday',
            default => 'mtd',
        };
    }

    /** @return array{0:Carbon,1:Carbon} */
    public static function resolve(string $range, $from = null, $to = null): array
    {
        $b = bdate()->copy()->startOfDay();
        $y = $b->copy()->subDay();
        [$f, $t] = match ($range) {
            'today' => [$b, $b],
            'yesterday' => [$y, $y],
            'last_7' => [$y->copy()->subDays(6), $y],
            'last_30' => [$y->copy()->subDays(29), $y],
            'wtd' => [$b->copy()->startOfWeek(), $b],
            'mtd' => [$b->copy()->startOfMonth(), $b],
            'last_month' => [$b->copy()->subMonthNoOverflow()->startOfMonth(), $b->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'qtd' => [$b->copy()->firstOfQuarter(), $b],
            'ytd' => [$b->copy()->startOfYear(), $b],
            'last_year' => [$b->copy()->subYear()->startOfYear(), $b->copy()->subYear()->endOfYear()->startOfDay()],
            'next_7' => [$b, $b->copy()->addDays(6)],
            'next_30' => [$b, $b->copy()->addDays(29)],
            'next_90' => [$b, $b->copy()->addDays(89)],
            'custom' => [Carbon::parse($from ?? $b)->startOfDay(), Carbon::parse($to ?? $from ?? $b)->startOfDay()],
            default => throw new RuntimeException("Unknown date range '{$range}'."),
        };

        return [$f->copy(), $t->copy()];
    }

    /** The preset whose dates match (for "save view" from an ad-hoc report), else 'custom'. */
    public static function guess(string $key, Carbon $from, Carbon $to): string
    {
        foreach (array_keys(self::RANGES) as $r) {
            if ($r === 'custom') {
                continue;
            }
            [$f, $t] = self::resolve($r);
            if (self::mode($key) === 'date' ? $t->isSameDay($to) : ($f->isSameDay($from) && $t->isSameDay($to))) {
                return $r;
            }
        }

        return 'custom';
    }

    public static function rangeLabel(string $key, string $range, Carbon $from, Carbon $to): string
    {
        $mode = self::mode($key);
        if ($mode === 'none') {
            return 'as on '.bdate()->format('d M Y');
        }
        if ($mode === 'date') {
            return 'as on '.$to->format('d M Y');
        }

        return ($range !== 'custom' ? (self::RANGES[$range] ?? $range).' · ' : '').($from->equalTo($to) ? $from->format('d M Y') : $from->format('d M Y').' – '.$to->format('d M Y'));
    }

    /** Run a report exactly as the report screen does (same clamping) – the caller has checked permission. */
    public static function generate(string $key, Carbon $from, Carbon $to): array
    {
        self::report($key);
        if (self::mode($key) === 'date') {
            $from = $to->copy();
        }
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }
        $ctl = app(ReportController::class);
        $m = new \ReflectionMethod($ctl, 'r_'.$key);
        $data = $m->invoke($ctl, $from, $to) + ['money' => [], 'sum' => [], 'summary' => [], 'note' => null];
        $data['rows'] = collect($data['rows'])->values();

        return $data + ['from' => $from, 'to' => $to];
    }

    /** Apply a saved column selection and text filter to generated report data. */
    public static function shape(array $data, ?array $columns, ?string $filter): array
    {
        if ($columns) {
            $keep = array_intersect_key($data['cols'], array_flip($columns));
            if ($keep) {
                $data['cols'] = $keep;
                $data['money'] = array_values(array_intersect($data['money'], array_keys($keep)));
                $data['sum'] = array_values(array_intersect($data['sum'], array_keys($keep)));
            }
        }
        $filter = trim((string) $filter);
        if ($filter !== '') {
            $needle = mb_strtolower($filter);
            $keys = array_keys($data['cols']);
            $data['rows'] = $data['rows']->filter(function ($r) use ($needle, $keys) {
                foreach ($keys as $k) {
                    if (isset($r[$k]) && is_scalar($r[$k]) && str_contains(mb_strtolower((string) $r[$k]), $needle)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        return $data;
    }

    public static function csvSafe($v): string
    {
        $v = (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v;
    }

    public static function csv(array $data): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_values($data['cols']), ',', '"', '');
        foreach ($data['rows'] as $row) {
            fputcsv($out, array_map(fn ($k) => self::csvSafe($row[$k] ?? ''), array_keys($data['cols'])), ',', '"', '');
        }
        if ($data['rows']->count() && $data['sum']) {
            fputcsv($out, array_map(fn ($k) => in_array($k, $data['sum'], true) ? round((float) $data['rows']->sum($k), 2) : ($k === array_key_first($data['cols']) ? 'Total' : ''), array_keys($data['cols'])), ',', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    // ───────────────────────── saved views ─────────────────────────

    /** Views of a report the user may open: their own + shared ones (if they may see the report). */
    public static function viewsFor(User $u, ?string $key = null): Collection
    {
        return ReportView::with('user')->when($key, fn ($q) => $q->where('report_key', $key))
            ->where(fn ($q) => $q->where('user_id', $u->id)->orWhere('is_shared', true))
            ->orderByDesc('is_pinned')->orderBy('name')->get()
            ->filter(fn ($v) => isset(ReportController::REPORTS[$v->report_key]) && $u->hasPermission(self::perm($v->report_key)))->values();
    }

    public static function canOpen(ReportView $v, User $u): bool
    {
        return ($v->user_id === $u->id || $v->is_shared) && $u->hasPermission(self::perm($v->report_key));
    }

    public static function saveView(array $d, User $u, ?ReportView $v = null): ReportView
    {
        $key = $v?->report_key ?? ($d['report_key'] ?? '');
        self::report($key);
        if (! $u->hasPermission(self::perm($key))) {
            throw new RuntimeException('You do not have access to this report.');
        }
        if ($v && $v->user_id !== $u->id) {
            throw new RuntimeException('Only the owner can change a saved view.');
        }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Give the view a name.');
        }
        if (ReportView::where('user_id', $u->id)->where('name', $name)->when($v, fn ($q) => $q->where('id', '!=', $v->id))->exists()) {
            throw new RuntimeException("You already have a view called '{$name}'.");
        }
        $range = $d['range'] ?? self::defaultRange($key);
        if (! isset(self::RANGES[$range])) {
            throw new RuntimeException('Choose a date range.');
        }
        $shared = (bool) ($d['is_shared'] ?? false);
        if ($shared && ! $u->hasPermission('reports.schedule')) {
            throw new RuntimeException('Sharing views needs the “Schedule & share reports” permission.');
        }
        $cols = array_values(array_filter((array) ($d['columns'] ?? []), 'is_string')) ?: null;
        $data = ['name' => mb_substr($name, 0, 120), 'range' => $range,
            'date_from' => $range === 'custom' ? ($d['date_from'] ?? null) : null, 'date_to' => $range === 'custom' ? ($d['date_to'] ?? $d['date_from'] ?? null) : null,
            'columns' => $cols, 'filter' => mb_substr(trim((string) ($d['filter'] ?? '')), 0, 100) ?: null,
            'is_shared' => $shared, 'is_pinned' => (bool) ($d['is_pinned'] ?? false)];
        if ($range === 'custom' && ! $data['date_from']) {
            throw new RuntimeException('Fixed dates need a from date.');
        }
        if ($v) {
            $v->update($data);
        } else {
            $v = ReportView::create($data + ['user_id' => $u->id, 'report_key' => $key]);
        }
        ActivityLogger::log('reports', 'view', 'Saved report view '.$v->name.' ('.$v->title().')', $v);

        return $v;
    }

    // ───────────────────────── schedules ─────────────────────────

    public static function checkDomain(string $email): void
    {
        $allowed = array_filter(array_map('trim', explode(',', strtolower(self::cfg('rpt_email_domains')))));
        if (! $allowed) {
            return;
        }
        $dom = strtolower(substr(strrchr($email, '@') ?: '', 1));
        foreach ($allowed as $a) {
            if ($dom === $a || str_ends_with($dom, '.'.$a)) {
                return;
            }
        }
        throw new RuntimeException("{$email} is outside the allowed report domains (".implode(', ', $allowed).').');
    }

    public static function save(array $d, User $by, ?ReportSchedule $s = null): ReportSchedule
    {
        $view = null;
        if (! empty($d['report_view_id'])) {
            $view = ReportView::find($d['report_view_id']);
            if (! $view || ! self::canOpen($view, $by)) {
                throw new RuntimeException('That saved view is not available.');
            }
            $d['report_key'] = $view->report_key;
        }
        $key = $d['report_key'] ?? $s?->report_key ?? '';
        self::report($key);
        $owner = $s?->user ?? $by;
        if (! $owner->hasPermission(self::perm($key))) {
            throw new RuntimeException('The schedule owner does not have access to this report.');
        }
        $freq = $d['frequency'] ?? '';
        if (! isset(ReportSchedule::FREQUENCIES[$freq])) {
            throw new RuntimeException('Choose how often to send the report.');
        }
        $time = $d['send_time'] ?? '07:00';
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new RuntimeException('Send time must be HH:MM (24-hour).');
        }
        $weekday = $freq === 'weekly' ? (int) ($d['weekday'] ?? 1) : null;
        if ($freq === 'weekly' && ($weekday < 1 || $weekday > 7)) {
            throw new RuntimeException('Choose a weekday.');
        }
        $mday = $freq === 'monthly' ? (int) ($d['month_day'] ?? 1) : null;
        if ($freq === 'monthly' && ($mday < 0 || $mday > 28)) {
            throw new RuntimeException('Day of month must be 1–28, or “last day”.');
        }
        $range = $view ? $view->range : ($d['range'] ?? self::defaultRange($key));
        if (! isset(self::RANGES[$range]) || $range === 'custom' && ! $view) {
            throw new RuntimeException('Choose a relative date range – a schedule always reports on the current period.');
        }
        $users = User::whereIn('id', array_map('intval', (array) ($d['recipient_users'] ?? [])))->where('is_active', true)->get();
        foreach ($users as $u) {
            if (! $u->email) {
                throw new RuntimeException("{$u->name} has no e-mail address.");
            }
            if (! $u->hasPermission(self::perm($key))) {
                throw new RuntimeException("{$u->name} is not allowed to see this report – send it as an external e-mail if intended.");
            }
            self::checkDomain($u->email);
        }
        $emails = array_values(array_unique(array_map('strtolower', preg_split('/[\s,;]+/', (string) ($d['recipient_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))));
        foreach ($emails as $e) {
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("'{$e}' is not a valid e-mail address.");
            }
            self::checkDomain($e);
        }
        $count = count(array_unique(array_merge($users->pluck('email')->map(fn ($x) => strtolower($x))->all(), $emails)));
        if (! $count) {
            throw new RuntimeException('Add at least one recipient.');
        }
        if ($count > (int) self::cfg('rpt_max_recipients')) {
            throw new RuntimeException('Too many recipients (limit '.self::cfg('rpt_max_recipients').').');
        }
        $data = ['name' => mb_substr(trim($d['name'] ?? '') ?: (ReportController::REPORTS[$key][1]), 0, 120),
            'report_view_id' => $view?->id, 'report_key' => $key, 'range' => $range,
            'columns' => $view ? $view->columns : (array_values(array_filter((array) ($d['columns'] ?? []), 'is_string')) ?: null),
            'frequency' => $freq, 'weekday' => $weekday, 'month_day' => $mday, 'send_time' => $time,
            'format' => isset(ReportSchedule::FORMATS[$d['format'] ?? '']) ? $d['format'] : 'both',
            'recipient_users' => $users->pluck('id')->all(), 'recipient_emails' => implode(', ', $emails) ?: null,
            'message' => mb_substr(trim((string) ($d['message'] ?? '')), 0, 500) ?: null, 'skip_empty' => (bool) ($d['skip_empty'] ?? false)];
        if ($s) {
            $s->fill($data);
        } else {
            $s = new ReportSchedule($data + ['user_id' => $by->id, 'is_active' => true]);
        }
        $s->next_run_at = $s->is_active ? self::nextRun($s) : null;
        $s->save();
        ActivityLogger::log('reports', 'schedule', 'Saved report schedule '.$s->name.' – '.$s->when().' to '.$count.' recipient(s)', $s);

        return $s;
    }

    public static function nextRun(ReportSchedule $s, ?Carbon $after = null): ?Carbon
    {
        if ($s->frequency === 'after_audit') {
            return null;
        }
        $after ??= now();
        [$h, $i] = array_map('intval', explode(':', $s->send_time));
        $at = fn (Carbon $d) => $d->copy()->setTime($h, $i, 0);
        if ($s->frequency === 'daily') {
            $c = $at($after);

            return $c->gt($after) ? $c : $c->addDay();
        }
        if ($s->frequency === 'weekly') {
            $c = $at($after);
            for ($n = 0; $n < 8; $n++, $c->addDay()) {
                if ($c->isoWeekday() === (int) $s->weekday && $c->gt($after)) {
                    return $c;
                }
            }
        }
        // monthly: day 1–28, or 0 = last day of the month
        $month = $after->copy()->startOfMonth();
        for ($n = 0; $n < 3; $n++, $month->addMonthNoOverflow()) {
            $c = $at($s->month_day ? $month->copy()->day($s->month_day) : $month->copy()->endOfMonth());
            if ($c->gt($after)) {
                return $c;
            }
        }

        return null;
    }

    public static function setActive(ReportSchedule $s, bool $on): void
    {
        $s->forceFill(['is_active' => $on, 'fail_count' => $on ? 0 : $s->fail_count, 'next_run_at' => $on ? self::nextRun($s) : null])->save();
        ActivityLogger::log('reports', $on ? 'schedule_resume' : 'schedule_pause', ($on ? 'Resumed' : 'Paused').' report schedule '.$s->name, $s);
    }

    /** Run one schedule now and e-mail the result. Never throws – failures are logged on the delivery. */
    public static function run(ReportSchedule $s, string $trigger = 'schedule', ?User $by = null): ReportDelivery
    {
        $t0 = microtime(true);
        $owner = $s->user;
        $prev = Auth::user();
        $d = new ReportDelivery(['report_schedule_id' => $s->id, 'report_key' => $s->report_key, 'title' => $s->name, 'trigger' => $trigger, 'user_id' => $by?->id]);
        $sent = [];
        $failed = [];
        $emails = [];
        try {
            if (! $owner || ! $owner->is_active) {
                throw new RuntimeException('The schedule owner is inactive – reassign or delete the schedule.');
            }
            if (! $owner->hasPermission(self::perm($s->report_key))) {
                throw new RuntimeException("The owner {$owner->name} no longer has access to this report.");
            }
            Auth::setUser($owner); // the report runs with the owner's rights
            $view = $s->view;
            [$from, $to] = self::resolve($s->range, $view?->date_from, $view?->date_to);
            $data = self::shape(self::generate($s->report_key, $from, $to), $s->columns, $view?->filter);
            [$from, $to] = [$data['from'], $data['to']];
            $d->fill(['period_from' => $from->toDateString(), 'period_to' => $to->toDateString(), 'rows' => $data['rows']->count()]);
            $emails = $s->emails();
            foreach ($emails as $e) {
                self::checkDomain($e); // the allow-list may have been tightened since the schedule was saved
            }
            if (! $emails) {
                throw new RuntimeException('No active recipients with an e-mail address.');
            }
            if ($s->skip_empty && ! $data['rows']->count()) {
                $d->fill(['status' => 'skipped', 'error' => 'No rows for the period – not sent (skip when empty).']);
            } else {
                $csv = $s->format !== 'html' ? self::csv($data) : null;
                $file = $s->report_key.'_'.$from->format('Ymd').($from->equalTo($to) ? '' : '_'.$to->format('Ymd')).'.csv';
                foreach ($emails as $e) {
                    $log = MailService::send($e, new ScheduledReportMail(hotel(), $s, $data, self::rangeLabel($s->report_key, $s->range, $from, $to), $csv, $file), 'scheduled_report', $s);
                    if ($log->status === 'sent') {
                        $sent[] = $e;
                    } else {
                        $failed[] = $e.' ('.($log->error ?: 'failed').')';
                    }
                }
                $d->fill(['status' => ! $failed ? 'sent' : ($sent ? 'partial' : 'failed'), 'error' => $failed ? mb_substr('Not delivered: '.implode('; ', $failed), 0, 500) : null]);
            }
        } catch (\Throwable $e) {
            $d->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            if (! $e instanceof RuntimeException) {
                report($e);
            }
        } finally {
            $prev ? Auth::setUser($prev) : Auth::guard()->forgetUser();
        }
        $d->fill(['recipients' => count($emails), 'delivered' => count($sent), 'sent_to' => implode(', ', $sent) ?: null, 'duration_ms' => (int) ((microtime(true) - $t0) * 1000)]);
        $d->save();

        $fails = $d->status === 'failed' ? $s->fail_count + 1 : 0;
        $limit = (int) self::cfg('rpt_fail_limit');
        $pause = $fails >= $limit;
        $s->forceFill(['last_run_at' => now(), 'last_status' => $d->status, 'last_error' => $d->error, 'fail_count' => min(255, $fails), 'runs' => $s->runs + 1,
            'is_active' => $s->is_active && ! $pause,
            'next_run_at' => $s->is_active && ! $pause ? ($trigger === 'schedule' ? self::nextRun($s) : ($s->next_run_at ?? self::nextRun($s))) : null])->save();
        if ($pause && $s->wasChanged('is_active')) {
            ActivityLogger::log('reports', 'schedule_pause', "Report schedule {$s->name} paused after {$fails} failed runs: {$d->error}", $s);
        }

        return $d;
    }

    /** Scheduler entry point (every 5 minutes): run what is due for the current hotel. */
    public static function due(?Carbon $now = null): array
    {
        $now ??= now();
        $done = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'partial' => 0];
        foreach (ReportSchedule::with('user', 'view')->where('is_active', true)->where('frequency', '!=', 'after_audit')
            ->whereNotNull('next_run_at')->where('next_run_at', '<=', $now)->orderBy('next_run_at')->get() as $s) {
            $done[self::run($s)->status]++;
        }

        return $done;
    }

    /** Called by the night audit once the day is closed. */
    public static function afterAudit(): array
    {
        $done = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'partial' => 0];
        foreach (ReportSchedule::with('user', 'view')->where('is_active', true)->where('frequency', 'after_audit')->orderBy('id')->get() as $s) {
            $done[self::run($s, 'after_audit')->status]++;
        }

        return $done;
    }

    /** Re-generate the data a delivery was based on (deliveries do not store files). */
    public static function regenerate(ReportDelivery $d, User $u): array
    {
        if (! $u->hasPermission(self::perm($d->report_key))) {
            throw new RuntimeException('You do not have access to this report.');
        }
        $s = $d->schedule;
        $data = self::generate($d->report_key, $d->period_from ?? bdate()->copy(), $d->period_to ?? bdate()->copy());

        return self::shape($data, $s?->columns, $s?->view?->filter);
    }

    public static function stats(): array
    {
        $since = now()->subDays(7);

        return [
            'views' => ReportView::count(),
            'active' => ReportSchedule::where('is_active', true)->count(),
            'paused' => ReportSchedule::where('is_active', false)->count(),
            'failing' => ReportSchedule::where('fail_count', '>', 0)->count(),
            'sent_7d' => ReportDelivery::where('created_at', '>=', $since)->whereIn('status', ['sent', 'partial'])->count(),
            'failed_7d' => ReportDelivery::where('created_at', '>=', $since)->where('status', 'failed')->count(),
            'alerts' => \App\Models\KpiAlert::where('is_active', true)->count(),
            'open_events' => KpiService::openCount(),
        ];
    }
}
