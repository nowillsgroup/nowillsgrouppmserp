<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduler — add ONE cron entry on the server:
|   * * * * * cd /path/to/nowills-pms && php artisan schedule:run >> /dev/null 2>&1
|
| On cPanel use the Cron Jobs screen with an absolute path instead:
|   * * * * * /usr/local/bin/php /home/USER/pms/artisan schedule:run >> /dev/null 2>&1
|
| runInBackground() detaches a worker process, which needs proc_open(). Many
| shared hosts list proc_open in disable_functions, and PHP then removes the
| function entirely — so calling it would make the scheduler throw on every
| run. Detect that and keep the command in the foreground instead. All three
| affected commands are also withoutOverlapping(), so a slow run cannot stack
| up; the only cost is that one cron run may take a little longer.
*/
$background = static fn ($event) => function_exists('proc_open') ? $event->runInBackground() : $event;

Schedule::command('pms:backup --keep=14')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('model:prune')->daily();
$background(Schedule::command('pms:ical-sync'))->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('pms:pre-arrival')->dailyAt('10:00')->withoutOverlapping();
Schedule::command('pms:gl-sync')->dailyAt('03:15')->withoutOverlapping(); // safety net; the night audit also syncs
Schedule::command('pms:rm-snapshot')->dailyAt('03:30')->withoutOverlapping(); // safety net; the night audit also snapshots
Schedule::command('pms:payments-expire')->everyFiveMinutes()->withoutOverlapping(); // v2.1: unpaid links + online-booking holds
Schedule::command('pms:pm-generate')->dailyAt('06:00')->withoutOverlapping(); // v2.3: preventive-maintenance tickets (the night audit also raises them)
Schedule::command('pms:banquet-release')->dailyAt('06:05')->withoutOverlapping(); // v2.5: release tentative banquet holds past their option date
Schedule::command('pms:crm-run')->everyFiveMinutes()->withoutOverlapping(); // v2.6: scheduled marketing campaigns
Schedule::command('pms:crm-run --journeys')->dailyAt('09:00')->withoutOverlapping(); // v2.6: guest stats + birthday / pre-arrival / post-stay / win-back automations (the night audit also runs them)
Schedule::command('pms:credit-review')->dailyAt('06:10')->withoutOverlapping(); // v2.7: auto credit hold / release (the night audit also reviews)
Schedule::command('pms:privacy-retention')->dailyAt('03:30')->withoutOverlapping(); // v2.9: data retention policies (DPDP / GDPR)
$background(Schedule::command('pms:channel-push'))->everyMinute()->withoutOverlapping(); // v2.8: ARI deltas / full refreshes to OTAs (with retry back-off)
Schedule::command('pms:einvoice-retry')->everyFiveMinutes()->withoutOverlapping(); // v4.3: GRA E-VAT submission retries with back-off
Schedule::command('pms:feedback-run')->everyFifteenMinutes()->withoutOverlapping(); // v3.1: post-stay surveys (send / remind / expire) + escalate overdue guest cases
Schedule::command('pms:tables-run')->everyFiveMinutes()->withoutOverlapping(); // v3.2: table booking reminders, automatic no-shows, end-of-day waitlist
Schedule::command('pms:transport-run')->everyFiveMinutes()->withoutOverlapping(); // v3.3: driver reminders, expire unconfirmed transfer requests
Schedule::command('pms:foreign-run')->hourly()->withoutOverlapping(); // v3.4: Form C (FRRO) due / overdue alerts, forex surrender check
Schedule::command('pms:frontdesk-run')->everyFiveMinutes()->withoutOverlapping(); // v3.5: late / missed wake-up call escalation
Schedule::command('pms:interfaces')->everyFiveMinutes()->withoutOverlapping(); // v3.7: expire key cards, re-send failed PABX commands, re-run the call review queue
Schedule::command('pms:vouchers')->dailyAt('06:30')->withoutOverlapping(); // v3.8: expire gift vouchers (breakage), e-mail expiry reminders
Schedule::command('pms:engineering')->dailyAt('07:00')->withoutOverlapping(); // v3.9: licence / inspection renewal reminders, meter-reading status
$background(Schedule::command('pms:webhooks'))->everyMinute()->withoutOverlapping(); // v4.0: deliver webhook events (retry back-off, auto-disable)
Schedule::command('pms:webhooks --prune --limit=1')->dailyAt('03:45')->withoutOverlapping(); // v4.0: API request-log / delivery retention
Schedule::command('pms:upsell')->hourlyAt(20)->withoutOverlapping(); // v4.1: pre-arrival offer e-mails, decline stale early / late requests
Schedule::command('pms:security')->everyFifteenMinutes()->withoutOverlapping(); // v4.2: visitor overstays, no-shows, overdue gate passes, ID retention
Schedule::command('pms:reports-run')->everyFiveMinutes()->withoutOverlapping(); // v5.4: e-mail scheduled reports that are due
Schedule::command('pms:cro-expire')->dailyAt('06:20')->withoutOverlapping(); // v6.4: lapse stale reservations enquiries (lost – no response)
Schedule::command('pms:hr-run')->dailyAt('06:40')->withoutOverlapping(); // v7.43: public-holiday attendance, leave / swap SLAs, roster rest checks, payroll + SSNIT / Tier 2 / PAYE deadlines
