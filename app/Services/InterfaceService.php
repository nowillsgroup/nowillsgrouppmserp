<?php

namespace App\Services;

use App\Models\CallRecord;
use App\Models\InterfaceCommand;
use App\Models\KeyCard;
use App\Models\KeyLoan;
use App\Models\Setting;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v3.7 – hotel system interfaces: settings shared by call accounting and the door-lock system, the PMS → PABX / lock
 * command log with its (demo / offline) driver, and the counters used by the dashboard and navigation badges.
 *
 * Real sites plug a vendor driver (FIAS / Micros-Fidelio, Assa Abloy Vision, Salto, Dormakaba, Onity …) in place of the
 * demo driver; the rest of the PMS only ever talks to send().
 */
class InterfaceService
{
    public const DEFAULTS = [
        // PABX / call accounting
        'pbx_enabled' => '1',
        'pbx_driver' => 'demo',                         // demo (acknowledges at once) | offline (commands queue until retried)
        'pbx_auto_post' => '1',                         // post rated guest calls to the folio as they arrive
        'pbx_min_secs' => '5',                          // shorter calls count as unanswered – never charged
        'pbx_free_numbers' => '100,101,102,108,112,1091,1098,1800*',  // emergency & toll-free; * = prefix
        'pbx_strip_prefix' => '',                       // outside-line access digit to strip before rating (e.g. 9)
        'pbx_format' => 'date,time,ext,trunk,number,duration',        // SMDR column order for imports / pushes
        'pbx_mask_digits' => '1',                       // hide the last 4 digits of the number on the guest bill
        'pbx_default_cos' => 'full',                    // class of service given at check-in
        'pbx_token' => '',                              // push endpoint token (generated)
        // door locks
        'lock_enabled' => '1',
        'lock_driver' => 'demo',                        // demo | offline
        'lock_system' => 'Demo encoder (RFID)',
        'lock_max_cards' => '4',                        // active cards per stay
        'lock_lost_fee' => '250',                       // default lost-card fee (0 = none)
        'lock_checkout_time' => '12:00',                // cards stop working at this time on the departure date …
        'lock_grace_minutes' => '60',                   // … plus this grace
        'lock_auto_issue' => '0',                       // cards encoded automatically at check-in (0 = front desk encodes)
        'lock_areas' => 'Lift,Pool,Gym,Spa,Parking',    // common-area access that can be granted
        'lock_default_areas' => 'Lift,Pool,Gym',
        'lock_master_hours' => '9',                     // default master-key sign-out (one shift)
    ];

    public const COS = ['full' => 'Full (incl. international)', 'national' => 'National – no international', 'local' => 'Local only', 'barred' => 'Barred – internal & emergency only'];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        if (! setting('pbx_token')) {
            Setting::updateOrCreate(['key' => 'pbx_token'], ['value' => Str::random(40)]);
        }
        Setting::$version++;
    }

    public static function cfg(): array
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $v) {
            $s[$k] = (string) setting($k, $v);
        }
        foreach (['pbx_min_secs', 'lock_max_cards', 'lock_grace_minutes', 'lock_auto_issue', 'lock_master_hours'] as $k) {
            $s[$k] = max(0, (int) $s[$k]);
        }
        $s['lock_max_cards'] = max(1, $s['lock_max_cards']);
        $s['lock_master_hours'] = max(1, $s['lock_master_hours']);
        $s['lock_lost_fee'] = max(0, round((float) $s['lock_lost_fee'], 2));
        $s['lock_checkout_time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s['lock_checkout_time']) ? $s['lock_checkout_time'] : '12:00';
        $s['pbx_default_cos'] = array_key_exists($s['pbx_default_cos'], self::COS) ? $s['pbx_default_cos'] : 'full';
        $s['areas'] = array_values(array_filter(array_map('trim', explode(',', $s['lock_areas']))));
        $s['default_areas'] = array_values(array_intersect(array_map('trim', explode(',', $s['lock_default_areas'])), $s['areas']));

        return $s;
    }

    public static function saveSettings(array $d): void
    {
        $fmt = implode(',', array_map('trim', explode(',', strtolower((string) ($d['pbx_format'] ?? self::DEFAULTS['pbx_format'])))));
        $cols = explode(',', $fmt);
        foreach (['ext', 'number', 'duration'] as $need) {
            if (! in_array($need, $cols, true)) {
                throw new RuntimeException("The SMDR format must include the “{$need}” column.");
            }
        }
        if (! in_array('datetime', $cols, true) && ! (in_array('date', $cols, true) && in_array('time', $cols, true))) {
            throw new RuntimeException('The SMDR format needs either “datetime” or both “date” and “time”.');
        }
        if ($bad = array_diff($cols, ['date', 'time', 'datetime', 'ext', 'trunk', 'number', 'duration', 'skip'])) {
            throw new RuntimeException('Unknown SMDR column(s): '.implode(', ', $bad).'. Use date, time, datetime, ext, trunk, number, duration or skip.');
        }
        $free = implode(',', array_filter(array_map(fn ($n) => preg_replace('/[^0-9*+]/', '', $n), explode(',', (string) ($d['pbx_free_numbers'] ?? '')))));
        $areas = implode(',', array_unique(array_filter(array_map(fn ($a) => mb_substr(trim($a), 0, 20), explode(',', (string) ($d['lock_areas'] ?? ''))))));
        $defaults = implode(',', array_intersect((array) ($d['lock_default_areas'] ?? []), explode(',', $areas)));
        $values = [
            'pbx_enabled' => ! empty($d['pbx_enabled']) ? '1' : '0',
            'pbx_driver' => in_array($d['pbx_driver'] ?? '', ['demo', 'offline'], true) ? $d['pbx_driver'] : 'demo',
            'pbx_auto_post' => ! empty($d['pbx_auto_post']) ? '1' : '0',
            'pbx_min_secs' => (string) max(0, min(60, (int) ($d['pbx_min_secs'] ?? 5))),
            'pbx_free_numbers' => $free,
            'pbx_strip_prefix' => preg_replace('/\D/', '', (string) ($d['pbx_strip_prefix'] ?? '')),
            'pbx_format' => $fmt,
            'pbx_mask_digits' => ! empty($d['pbx_mask_digits']) ? '1' : '0',
            'pbx_default_cos' => array_key_exists($d['pbx_default_cos'] ?? '', self::COS) ? $d['pbx_default_cos'] : 'full',
            'lock_enabled' => ! empty($d['lock_enabled']) ? '1' : '0',
            'lock_driver' => in_array($d['lock_driver'] ?? '', ['demo', 'offline'], true) ? $d['lock_driver'] : 'demo',
            'lock_system' => mb_substr(trim((string) ($d['lock_system'] ?? '')) ?: 'Door-lock encoder', 0, 60),
            'lock_max_cards' => (string) max(1, min(10, (int) ($d['lock_max_cards'] ?? 4))),
            'lock_lost_fee' => (string) max(0, round((float) ($d['lock_lost_fee'] ?? 0), 2)),
            'lock_checkout_time' => (string) ($d['lock_checkout_time'] ?? '12:00'),
            'lock_grace_minutes' => (string) max(0, min(720, (int) ($d['lock_grace_minutes'] ?? 60))),
            'lock_auto_issue' => (string) max(0, min(4, (int) ($d['lock_auto_issue'] ?? 0))),
            'lock_areas' => $areas,
            'lock_default_areas' => $defaults,
            'lock_master_hours' => (string) max(1, min(24, (int) ($d['lock_master_hours'] ?? 9))),
        ];
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $values['lock_checkout_time'])) {
            throw new RuntimeException('Key expiry time must be HH:MM.');
        }
        foreach ($values as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        CallAccountingService::flush();
        ActivityLogger::log('interfaces', 'settings', 'Interface settings updated');
    }

    public static function regenerateToken(): string
    {
        $t = Str::random(40);
        Setting::updateOrCreate(['key' => 'pbx_token'], ['value' => $t]);
        Setting::$version++;
        ActivityLogger::log('interfaces', 'token', 'PBX push token regenerated – the old token stops working');

        return $t;
    }

    // ───────────────────────── command log / driver ─────────────────────────

    /**
     * Send a command to the PABX or the lock encoder and log it. The demo driver acknowledges at once (and, for card
     * encodes, returns the card serial); the offline driver leaves the command failed so it can be retried.
     */
    public static function send(string $system, string $command, array $payload = [], ?int $roomId = null, ?int $stayId = null, ?int $cardId = null): InterfaceCommand
    {
        $cmd = InterfaceCommand::create([
            'system' => $system, 'command' => $command, 'payload' => $payload, 'room_id' => $roomId, 'reservation_room_id' => $stayId,
            'key_card_id' => $cardId, 'status' => 'queued', 'user_id' => auth()->id(),
        ]);

        return self::dispatch($cmd);
    }

    protected static function dispatch(InterfaceCommand $cmd): InterfaceCommand
    {
        $c = self::cfg();
        $enabled = $c[$cmd->system.'_enabled'] === '1';
        $driver = $c[$cmd->system.'_driver'];
        $cmd->attempts++;
        if (! $enabled) {
            $cmd->fill(['status' => 'failed', 'response' => strtoupper($cmd->system).' interface is switched off']);
        } elseif ($driver === 'offline') {
            $cmd->fill(['status' => 'failed', 'response' => ($cmd->system === 'lock' ? 'Encoder' : 'PABX').' not reachable – check the interface PC / cable and retry']);
        } else {
            $payload = $cmd->payload ?? [];
            if ($cmd->system === 'lock' && in_array($cmd->command, ['issue', 'duplicate'], true)) {
                $payload['serial'] = strtoupper(bin2hex(random_bytes(4)));   // the encoder reports the UID of the card it wrote
            }
            $cmd->fill(['status' => 'done', 'payload' => $payload, 'response' => 'OK ('.$driver.')']);
        }
        $cmd->save();

        return $cmd;
    }

    public static function retry(InterfaceCommand $cmd): InterfaceCommand
    {
        if ($cmd->status === 'done') {
            throw new RuntimeException('This command has already been acknowledged.');
        }
        if ($cmd->system === 'lock' && in_array($cmd->command, ['issue', 'duplicate'], true)) {
            throw new RuntimeException('Card encodes are not retried from the log – encode the card again from Key Cards.');
        }

        return self::dispatch($cmd);
    }

    // ───────────────────────── counters ─────────────────────────

    public static function counts(): array
    {
        return [
            'calls_pending' => CallRecord::where('status', 'pending')->count(),
            'keys_overdue' => KeyLoan::where('status', 'out')->where('due_at', '<', now())->count(),
            'keys_out' => KeyLoan::where('status', 'out')->count(),
            'cards_active' => KeyCard::where('status', 'active')->where('valid_until', '>', now())->count(),
            'commands_failed' => InterfaceCommand::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    /** dashboard card */
    public static function snapshot(): array
    {
        $today = bdate()->toDateString();
        $calls = CallRecord::whereDate('started_at', $today);

        return self::counts() + [
            'calls_today' => (clone $calls)->count(),
            'charge_today' => round((float) (clone $calls)->where('status', 'posted')->sum('charge'), 2),
            'admin_cost_today' => round((float) (clone $calls)->where('status', 'admin')->sum('cost'), 2),
        ];
    }
}
