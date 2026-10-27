<?php

namespace App\Services;

use App\Models\GuestMessage;
use App\Models\LogEntry;
use App\Models\LostFoundItem;
use App\Models\LostReport;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Models\Trace;
use App\Models\WakeupCall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v3.5 – front desk operations: settings, shifts and the "what needs attention" counters shared by the dashboard,
 * the navigation badges, the hand-over snapshot and the night-audit pre-check.
 */
class FrontDeskOpsService
{
    public const DEFAULTS = [
        'desk_shift_morning' => '07:00', 'desk_shift_evening' => '15:00', 'desk_shift_night' => '23:00',
        'desk_log_unread_days' => '3',              // log entries by others from the last N days count as unread until acknowledged
        'desk_wakeup_lead' => '15',                 // minutes before the call time a wake-up shows as "due"
        'desk_wakeup_grace' => '10',                // minutes after the call time with no call logged → escalate to the duty manager
        'desk_wakeup_retry' => '5',                 // minutes between attempts after no answer
        'desk_wakeup_max_attempts' => '3',          // attempts before the call is closed as "no answer" and someone is sent to the room
        'desk_escalate_phone' => '',                // duty manager mobile for SMS escalations (blank = log book + dashboard only)
        'desk_msg_notify' => 'sms',                 // none | sms | whatsapp – notify in-house guests automatically when a message / parcel arrives
        'desk_msg_include_text' => '0',             // include the message text in the SMS (off = "you have a message at the front desk")
        'desk_msg_remind_minutes' => '60',          // messages waiting longer than this are flagged
        'desk_lf_retention' => '90',                // days found items are kept before they may be disposed
        'desk_lf_retention_valuable' => '180',
        'desk_lf_retention_perishable' => '1',
        'desk_lf_valuable_amount' => '5000',        // estimated value at or above which an item is treated as a valuable (safe, ID check, witness)
        'desk_lf_notify_guest' => '0',              // tell the probable owner (last guest in the room) that something was found
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
    }

    public static function cfg(): array
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $v) {
            $s[substr($k, 5)] = (string) setting($k, $v);
        }
        foreach (['log_unread_days', 'wakeup_lead', 'wakeup_grace', 'wakeup_retry', 'wakeup_max_attempts', 'msg_remind_minutes', 'lf_retention', 'lf_retention_valuable', 'lf_retention_perishable', 'lf_valuable_amount'] as $k) {
            $s[$k] = max(0, (int) $s[$k]);
        }
        $s['wakeup_max_attempts'] = max(1, $s['wakeup_max_attempts']);
        $s['lf_retention'] = max(1, $s['lf_retention']);
        $s['lf_retention_valuable'] = max(1, $s['lf_retention_valuable']);
        foreach (['shift_morning' => '07:00', 'shift_evening' => '15:00', 'shift_night' => '23:00'] as $k => $def) {
            $s[$k] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s[$k]) ? $s[$k] : $def;
        }

        return $s;
    }

    public static function saveSettings(array $d): void
    {
        DB::transaction(function () use ($d) {
            foreach (self::DEFAULTS as $k => $v) {
                $short = substr($k, 5);
                if (array_key_exists($short, $d)) {
                    Setting::updateOrCreate(['key' => $k], ['value' => is_bool($d[$short]) ? ($d[$short] ? '1' : '0') : trim((string) $d[$short])]);
                }
            }
            ActivityLogger::log('frontdesk', 'settings', 'Front desk operations settings updated');
        });
        Setting::$version++;
    }

    // ───────────────────────── shifts ─────────────────────────

    /** shift => start "H:i", in day order */
    public static function shifts(): array
    {
        $c = self::cfg();

        return ['morning' => $c['shift_morning'], 'evening' => $c['shift_evening'], 'night' => $c['shift_night']];
    }

    public static function currentShift(?\Carbon\CarbonInterface $at = null): string
    {
        $t = ($at ?? now())->format('H:i');
        $sh = self::shifts();
        if ($t >= $sh['morning'] && $t < $sh['evening']) {
            return 'morning';
        }
        if ($t >= $sh['evening'] && $t < $sh['night']) {
            return 'evening';
        }

        return 'night';
    }

    /** when the shift that is running at $at started */
    public static function shiftStart(?\Carbon\CarbonInterface $at = null): Carbon
    {
        $at = ($at ?? now())->copy();
        $shift = self::currentShift($at);
        $start = Carbon::parse($at->toDateString().' '.self::shifts()[$shift], $at->timezone);
        if ($start->gt($at)) {
            $start->subDay(); // night shift after midnight
        }

        return $start;
    }

    /** start of the shift before the current one – the hand-over window */
    public static function previousShiftStart(?\Carbon\CarbonInterface $at = null): Carbon
    {
        return self::shiftStart(self::shiftStart($at)->subMinute());
    }

    // ───────────────────────── counters ─────────────────────────

    /** the numbers behind dashboard lines, nav badges and the hand-over snapshot (cheap queries only) */
    public static function counts(?int $userId = null): array
    {
        $c = self::cfg();
        $userId ??= auth()->id();
        $today = bdate()->toDateString();
        $wake = WakeupCall::where('status', 'scheduled');

        return [
            'wakeups_due' => (clone $wake)->where('call_at', '<=', now()->addMinutes($c['wakeup_lead']))->count(),
            'wakeups_late' => (clone $wake)->where('call_at', '<', now()->subMinutes($c['wakeup_grace']))->count(),
            'wakeups_next12' => (clone $wake)->whereBetween('call_at', [now(), now()->addHours(12)])->count(),
            'messages' => GuestMessage::whereIn('status', GuestMessage::OPEN)->where('kind', 'message')->count(),
            'parcels' => GuestMessage::whereIn('status', GuestMessage::OPEN)->where('kind', 'parcel')->count(),
            'messages_old' => GuestMessage::whereIn('status', GuestMessage::OPEN)->where('created_at', '<', now()->subMinutes(max(1, $c['msg_remind_minutes'])))->count(),
            'departed_parcels' => GuestMessage::whereIn('status', GuestMessage::OPEN)->whereHas('stay', fn ($q) => $q->whereIn('status', ['checked_out', 'cancelled', 'no_show']))->count(),
            'traces_today' => Trace::where('status', 'open')->whereDate('trace_date', '<=', $today)->count(),
            'log_unread' => $userId ? LogBookService::unreadCount($userId) : 0,
            'log_open' => LogEntry::where('status', 'open')->count(),
            'log_urgent' => LogEntry::where('status', 'open')->where('priority', 'urgent')->count(),
            'log_follow_ups' => LogEntry::where('status', 'open')->whereNotNull('follow_up_at')->where('follow_up_at', '<=', now())->count(),
            'lf_stored' => LostFoundItem::where('status', 'stored')->count(),
            'lf_due' => LostFoundItem::where('status', 'stored')->whereNotNull('retain_until')->whereDate('retain_until', '<=', $today)->count(),
            'lf_reports' => LostReport::where('status', 'open')->count(),
        ];
    }

    /** operational snapshot for the shift hand-over page */
    public static function snapshot(): array
    {
        $today = bdate()->toDateString();

        return self::counts() + [
            'inhouse' => ReservationRoom::where('status', 'checked_in')->count(),
            'arrivals_pending' => ReservationRoom::where('status', 'reserved')->whereDate('arrival_date', $today)
                ->whereHas('reservation', fn ($q) => $q->whereIn('status', ['confirmed', 'tentative']))->count(),
            'departures_pending' => ReservationRoom::where('status', 'checked_in')->whereDate('departure_date', '<=', $today)->count(),
            'lf_today' => LostFoundItem::whereDate('found_date', $today)->count(),
        ];
    }

    /** in-house stays + arrivals of the next 7 days, for the desk pickers: id => "305 · Guest name (in-house → 29 Sep)" */
    public static function stayOptions(bool $withArrivals = true): array
    {
        $today = bdate()->toDateString();

        return ReservationRoom::with('room', 'guest', 'reservation.guest')
            ->where(fn ($q) => $q->where('status', 'checked_in')->when($withArrivals, fn ($q) => $q->orWhere(fn ($q) => $q->where('status', 'reserved')
                ->whereDate('arrival_date', '>=', $today)->whereDate('arrival_date', '<=', bdate()->copy()->addDays(7)->toDateString())
                ->whereHas('reservation', fn ($r) => $r->whereIn('status', ['confirmed', 'tentative'])))))
            ->get()->sortBy(fn ($s) => [$s->status === 'checked_in' ? 0 : 1, $s->room?->room_no ?? 'zzz', $s->arrival_date->toDateString()])
            ->mapWithKeys(fn ($s) => [$s->id => ($s->room?->room_no ?? 'Unassigned').' · '.(($s->guest ?? $s->reservation?->guest)?->full_name ?? 'Guest')
                .($s->status === 'checked_in' ? ' (in-house → '.$s->departure_date->format('d M').')' : ' (arriving '.$s->arrival_date->format('d M').')')])->all();
    }
}
