<?php

namespace App\Services;

use App\Models\GuestRequest;
use App\Models\ReservationRoom;
use App\Models\WakeupAttempt;
use App\Models\WakeupCall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** v3.5 – wake-up calls: schedule (once / daily until check-out), log attempts, retry, escalate, roll the series. */
class WakeupService
{
    /** a scheduled call this long after its time without any attempt is closed as missed by the scheduler */
    public const MISSED_AFTER_MINUTES = 120;

    public static function schedule(ReservationRoom $stay, Carbon $at, bool $daily = false, ?string $note = null, string $source = 'desk', ?int $requestId = null, ?int $parentId = null): WakeupCall
    {
        if (! in_array($stay->status, ['reserved', 'checked_in'], true)) {
            throw new RuntimeException('Wake-up calls can only be booked for in-house or arriving guests.');
        }
        if ($at->lt(now()->subMinute())) {
            throw new RuntimeException('The wake-up time must be in the future.');
        }
        $last = Carbon::parse($stay->departure_date->toDateString().' 23:59:59');
        if ($at->gt($last)) {
            throw new RuntimeException('The wake-up time is after the guest\'s departure ('.$stay->departure_date->format('d M').').');
        }
        if ($stay->status === 'reserved' && $at->lt(Carbon::parse($stay->arrival_date->toDateString()))) {
            throw new RuntimeException('The wake-up time is before the guest arrives.');
        }

        return DB::transaction(function () use ($stay, $at, $daily, $note, $source, $requestId, $parentId) {
            $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            if (! in_array($stay->status, ['reserved', 'checked_in'], true)) {
                throw new RuntimeException('Wake-up calls can only be booked for in-house or arriving guests.');
            }
            if ($at->lt(now()->subMinute())) {
                throw new RuntimeException('The wake-up time must be in the future.');
            }
            $last = Carbon::parse($stay->departure_date->toDateString().' 23:59:59');
            if ($at->gt($last)) {
                throw new RuntimeException('The wake-up time is after the guest\'s departure ('.$stay->departure_date->format('d M').').');
            }
            if ($stay->status === 'reserved' && $at->lt(Carbon::parse($stay->arrival_date->toDateString()))) {
                throw new RuntimeException('The wake-up time is before the guest arrives.');
            }
            $clash = WakeupCall::where('reservation_room_id', $stay->id)->where('status', 'scheduled')
                ->whereBetween('call_at', [$at->copy()->subMinutes(10), $at->copy()->addMinutes(10)])->first();
            if ($clash) {
                throw new RuntimeException('A wake-up call is already booked for '.$clash->call_at->format('d M H:i').'.');
            }
            $c = WakeupCall::create([
                'reservation_room_id' => $stay->id, 'room_id' => $stay->room_id, 'call_at' => $at, 'daily' => $daily,
                'note' => trim((string) $note) ? mb_substr(trim($note), 0, 190) : null, 'source' => array_key_exists($source, WakeupCall::SOURCES) ? $source : 'desk',
                'guest_request_id' => $requestId, 'parent_id' => $parentId, 'status' => 'scheduled', 'created_by' => auth()->id(),
            ]);
            if (! $parentId) {
                ActivityLogger::log('wakeup', 'create', 'Wake-up '.$at->format('d M H:i').($daily ? ' (daily)' : '').' for room '.($stay->room?->room_no ?? '—'), $c);
            }

            return $c;
        });
    }

    /** log a call attempt – answered closes it; no answer / busy retries until the attempt limit, then escalates */
    public static function attempt(WakeupCall $c, string $outcome, ?string $note = null): WakeupCall
    {
        if (! $c->isScheduled()) {
            throw new RuntimeException('This wake-up call is already '.strtolower($c->statusLabel()).'.');
        }
        if (! in_array($outcome, ['answered', 'no_answer', 'busy'], true)) {
            throw new RuntimeException('Choose the outcome of the call.');
        }
        if ($c->call_at->gt(now()->addMinutes(max(15, FrontDeskOpsService::cfg()['wakeup_lead'])))) {
            throw new RuntimeException('This call is not due until '.$c->call_at->format('d M H:i').'.');
        }
        $cfg = FrontDeskOpsService::cfg();

        return DB::transaction(function () use ($c, $outcome, $note, $cfg) {
            $c = WakeupCall::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if (! $c->isScheduled()) {
                throw new RuntimeException('This wake-up call is already '.strtolower($c->statusLabel()).'.');
            }
            if ($c->call_at->gt(now()->addMinutes(max(15, $cfg['wakeup_lead'])))) {
                throw new RuntimeException('This call is not due until '.$c->call_at->format('d M H:i').'.');
            }
            $note = trim((string) $note) ? mb_substr(trim($note), 0, 190) : null;
            WakeupAttempt::create(['wakeup_call_id' => $c->id, 'outcome' => $outcome, 'note' => $note, 'user_id' => auth()->id(), 'attempted_at' => now()]);
            $c->attempts++;
            $c->last_attempt_at = now();
            if ($outcome === 'answered') {
                $c->forceFill(['status' => 'done', 'completed_at' => now(), 'completed_by' => auth()->id(), 'outcome_note' => $note ?? 'Answered'])->save();
                self::closeRequest($c, 'done', 'Wake-up call made at '.now()->format('H:i'));
                self::rollNext($c);
            } elseif ($c->attempts >= $cfg['wakeup_max_attempts']) {
                $c->forceFill(['status' => 'no_answer', 'completed_at' => now(), 'completed_by' => auth()->id(),
                    'outcome_note' => $note ?? "No answer after {$c->attempts} attempts"])->save();
                self::escalate($c, 'no_answer');
                self::closeRequest($c, 'done', 'We called at '.$c->call_at->format('H:i').' but there was no answer.');
                self::rollNext($c);
            } else {
                $c->save();
            }
            ActivityLogger::log('wakeup', 'attempt', 'Wake-up room '.($c->stay?->room?->room_no ?? '—').' '.$c->call_at->format('H:i').': '.str_replace('_', ' ', $outcome), $c);

            return $c;
        });
    }

    /** when the next retry is due (null if not waiting for a retry) */
    public static function retryAt(WakeupCall $c): ?Carbon
    {
        return $c->isScheduled() && $c->attempts > 0 && $c->last_attempt_at ? $c->last_attempt_at->copy()->addMinutes(FrontDeskOpsService::cfg()['wakeup_retry']) : null;
    }

    public static function cancel(WakeupCall $c, ?string $reason = null, bool $fromGuest = false): WakeupCall
    {
        if (! $c->isScheduled()) {
            throw new RuntimeException('This wake-up call is already '.strtolower($c->statusLabel()).'.');
        }

        return DB::transaction(function () use ($c, $reason, $fromGuest) {
            $c = WakeupCall::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if (! $c->isScheduled()) {
                throw new RuntimeException('This wake-up call is already '.strtolower($c->statusLabel()).'.');
            }
            $c->forceFill(['status' => 'cancelled', 'completed_at' => now(), 'completed_by' => auth()->id(), 'outcome_note' => trim((string) $reason) ?: ($fromGuest ? 'Cancelled by the guest' : 'Cancelled')])->save();
            self::closeRequest($c, 'cancelled', 'Wake-up call cancelled');
            ActivityLogger::log('wakeup', 'cancel', 'Wake-up '.$c->call_at->format('d M H:i').' cancelled'.($reason ? ": {$reason}" : ''), $c);

            return $c;
        });
    }

    /** daily calls: book the next morning while the guest is still staying */
    public static function rollNext(WakeupCall $c): ?WakeupCall
    {
        if (! $c->daily) {
            return null;
        }
        $stay = $c->stay()->first();
        $next = $c->call_at->copy()->addDay();
        while ($next->lt(now())) {
            $next->addDay();
        }
        if (! $stay || ! in_array($stay->status, ['reserved', 'checked_in'], true) || $next->toDateString() > $stay->departure_date->toDateString()) {
            return null;
        }
        if (WakeupCall::where('reservation_room_id', $stay->id)->where('status', 'scheduled')->where('call_at', '>', now())->where('daily', true)->exists()) {
            return null;
        }
        try {
            return self::schedule($stay, $next, true, $c->note, $c->source, null, $c->parent_id ?? $c->id);
        } catch (RuntimeException) {
            return null;
        }
    }

    protected static function closeRequest(WakeupCall $c, string $status, string $note): void
    {
        if ($c->guest_request_id && ($r = GuestRequest::find($c->guest_request_id)) && in_array($r->status, GuestRequest::OPEN, true)) {
            $r->forceFill(['status' => $status, 'staff_note' => $note, 'acknowledged_at' => $r->acknowledged_at ?? now(), 'completed_at' => now(), 'handled_by' => auth()->id() ?? $r->handled_by])->save();
        }
    }

    /** log-book entry (+ optional SMS to the duty manager): late call or no answer – someone must go to the room */
    public static function escalate(WakeupCall $c, string $why): bool
    {
        $claimed = DB::transaction(function () use ($c, $why) {
            $locked = WakeupCall::whereKey($c->id)->lockForUpdate()->first();
            if (! $locked) {
                return null;
            }
            if ($why === 'late') {
                $grace = max(1, FrontDeskOpsService::cfg()['wakeup_grace']);
                if ($locked->status !== 'scheduled' || $locked->escalated_at || (int) $locked->attempts !== 0
                    || $locked->call_at->gte(now()->subMinutes($grace))) {
                    return null;
                }
            } elseif ($why === 'no_answer' && $locked->status !== 'no_answer') {
                return null;
            }
            $locked->forceFill(['escalated_at' => now()])->save();

            return $locked;
        });
        if (! $claimed) {
            return false;
        }
        $room = $claimed->stay?->room?->room_no ?? $claimed->room?->room_no ?? '—';
        $title = $why === 'late'
            ? "Wake-up call for room {$room} at ".$claimed->call_at->format('H:i').' has not been made'
            : "Room {$room} did not answer the ".$claimed->call_at->format('H:i').' wake-up call – send someone to knock';
        LogBookService::system($title, 'Guest: '.(GuestMessageService::stayGuest($claimed->stay)?->full_name ?? '—').($claimed->note ? ". Note: {$claimed->note}" : ''), 'guest', 'urgent',
            ['reservation_room_id' => $claimed->reservation_room_id], true);
        if ($phone = FrontDeskOpsService::cfg()['escalate_phone']) {
            MessageService::send($phone, hotel()->name.': '.$title.'.', MessageService::defaultChannel(), 'wakeup_escalation', $claimed);
        }

        return true;
    }

    public static function onCheckOut(ReservationRoom $stay): int
    {
        $ids = WakeupCall::where('reservation_room_id', $stay->id)->where('status', 'scheduled')->orderBy('id')->pluck('id');
        $cancelled = 0;
        foreach ($ids as $id) {
            $didCancel = DB::transaction(function () use ($id) {
                $call = WakeupCall::whereKey($id)->lockForUpdate()->first();
                if (! $call || ! $call->isScheduled()) {
                    return false;
                }
                $call->forceFill(['status' => 'cancelled', 'completed_at' => now(), 'outcome_note' => 'Guest checked out'])->save();
                self::closeRequest($call, 'cancelled', 'Guest checked out');

                return true;
            });
            $cancelled += $didCancel ? 1 : 0;
        }

        return $cancelled;
    }

    /** room move: the call follows the guest */
    public static function onRoomMove(ReservationRoom $stay): void
    {
        WakeupCall::where('reservation_room_id', $stay->id)->where('status', 'scheduled')->update(['room_id' => $stay->room_id]);
    }

    /** scheduler: escalate late calls, close long-missed ones (and keep daily series going) */
    public static function run(): array
    {
        $cfg = FrontDeskOpsService::cfg();
        $out = ['escalated' => 0, 'missed' => 0];
        $lateIds = WakeupCall::where('status', 'scheduled')->whereNull('escalated_at')
            ->where('call_at', '<', now()->subMinutes(max(1, $cfg['wakeup_grace'])))->where('attempts', 0)->orderBy('id')->pluck('id');
        foreach ($lateIds as $id) {
            $call = WakeupCall::find($id);
            if ($call && self::escalate($call, 'late')) {
                $out['escalated']++;
            }
        }

        $missedIds = WakeupCall::where('status', 'scheduled')->where('call_at', '<', now()->subMinutes(self::MISSED_AFTER_MINUTES))->orderBy('id')->pluck('id');
        foreach ($missedIds as $id) {
            $call = DB::transaction(function () use ($id) {
                $locked = WakeupCall::whereKey($id)->lockForUpdate()->first();
                if (! $locked || ! $locked->isScheduled() || $locked->call_at->gte(now()->subMinutes(self::MISSED_AFTER_MINUTES))) {
                    return null;
                }
                $locked->forceFill(['status' => $locked->attempts ? 'no_answer' : 'missed', 'completed_at' => now(),
                    'outcome_note' => $locked->attempts ? 'Closed automatically after unanswered attempts' : 'Not called – closed automatically'])->save();
                self::closeRequest($locked, 'done', 'Wake-up call window passed');

                return $locked;
            });
            if ($call) {
                self::rollNext($call);
                $out['missed']++;
            }
        }

        return $out;
    }

}
