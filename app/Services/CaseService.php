<?php

namespace App\Services;

use App\Mail\GuestMessage;
use App\Models\FeedbackCase;
use App\Models\FeedbackCaseEvent;
use App\Models\Guest;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v3.1 service recovery: a case per unhappy guest (from poor survey / portal feedback, a bad online review or staff),
 * with owner, SLA, timeline, guest contact, resolution (incl. goodwill loyalty points) and the recovery outcome.
 */
class CaseService
{
    public static function slaHours(string $severity, bool $inHouse = false): int
    {
        if ($inHouse) {
            return max(1, (int) setting('fb_sla_inhouse', FeedbackService::DEFAULTS['fb_sla_inhouse']));
        }

        return max(1, (int) setting('fb_sla_'.$severity, FeedbackService::DEFAULTS['fb_sla_'.$severity] ?? '24'));
    }

    public static function open(array $a): FeedbackCase
    {
        return DB::transaction(function () use ($a) {
            $owner = $a['assigned_to'] ?? FeedbackService::ownerFor($a['department']);
            $a['title'] = mb_strlen($a['title']) > 160 ? mb_substr($a['title'], 0, 159).'…' : $a['title']; // column is 160 characters
            $case = FeedbackCase::create($a + [
                'case_no' => Numbering::next('feedback_case'), 'status' => 'open', 'assigned_to' => $owner, 'opened_by' => auth()->id(),
                'due_at' => now()->addHours(self::slaHours($a['severity'], (bool) ($a['in_house'] ?? false))),
            ]);
            $src = ['feedback' => 'guest feedback', 'review' => 'an online review', 'manual' => 'staff'][$case->source] ?? $case->source;
            self::event($case, 'opened', "Opened from {$src} · ".FeedbackCase::SEVERITIES[$case->severity].' severity · '.FeedbackService::DEPTS[$case->department]
                .' · resolve by '.$case->due_at->format('d M H:i').($case->assigned_to ? ' · owner '.$case->assignee?->name : ' · unassigned'));
            ActivityLogger::log('feedback', 'case_open', "Service-recovery case {$case->case_no}: {$case->title}", $case);

            return $case;
        });
    }

    public static function event(FeedbackCase $case, string $type, ?string $note = null): FeedbackCaseEvent
    {
        return FeedbackCaseEvent::create(['case_id' => $case->id, 'user_id' => auth()->id(), 'type' => $type, 'note' => $note]);
    }

    protected static function assertActive(FeedbackCase $case): void
    {
        if (! $case->isActive()) {
            throw ValidationException::withMessages(['case' => "Case {$case->case_no} is ".strtolower(FeedbackCase::STATUSES[$case->status]).' – re-open it first.']);
        }
    }

    public static function assign(FeedbackCase $case, ?int $userId): void
    {
        self::assertActive($case);
        if ($userId && ! FeedbackService::caseStaff()->has($userId)) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose an active colleague who can handle guest cases.']);
        }
        $case->update(['assigned_to' => $userId]);
        self::event($case, 'assigned', $userId ? 'Assigned to '.$case->fresh()->assignee->name : 'Unassigned');
    }

    /** First response: the guest has been acknowledged (stops the "no response" clock). */
    public static function start(FeedbackCase $case, ?string $note = null): void
    {
        self::assertActive($case);
        $case->update(['status' => 'in_progress', 'first_response_at' => $case->first_response_at ?? now(), 'assigned_to' => $case->assigned_to ?? auth()->id()]);
        self::event($case, 'started', $note);
    }

    public static function note(FeedbackCase $case, string $note): void
    {
        self::event($case, 'note', $note);
    }

    /** Write to the guest by e-mail or SMS / WhatsApp; logged on the case and in the message logs. */
    public static function contact(FeedbackCase $case, string $channel, string $subject, string $body): bool
    {
        self::assertActive($case);
        $g = $case->guest;
        if (! $g || $g->privacy_status) {
            throw ValidationException::withMessages(['body' => $g ? 'The guest\'s data is restricted – contact is not allowed.' : 'This case has no guest profile to contact.']);
        }
        if ($channel === 'email') {
            $log = MailService::send($g->email, new GuestMessage($subject, $body, hotel()), 'case_reply', $case);
        } else {
            $log = MessageService::send($g->phone, $body, MessageService::defaultChannel(), 'case_reply', $case);
        }
        $ok = $log->status === 'sent';
        if (! $ok) {
            throw ValidationException::withMessages(['body' => 'Not sent: '.$log->error]);
        }
        $case->update(['status' => 'in_progress', 'first_response_at' => $case->first_response_at ?? now()]);
        self::event($case, 'contacted', ($channel === 'email' ? "E-mail to {$g->email}" : 'SMS / WhatsApp to '.$log->to).": {$subject}\n{$body}");

        return true;
    }

    public static function resolve(FeedbackCase $case, string $resolution, string $action, int $points = 0): void
    {
        self::assertActive($case);
        if (! isset(FeedbackCase::ACTIONS[$action])) {
            throw ValidationException::withMessages(['recovery_action' => 'Choose the recovery action taken.']);
        }
        if ($points > 0) {
            $max = (int) setting('fb_goodwill_max', FeedbackService::DEFAULTS['fb_goodwill_max']);
            if (! $case->guest) {
                throw ValidationException::withMessages(['goodwill_points' => 'Goodwill points need a guest profile on the case.']);
            }
            if ($case->guest->privacy_status) {
                throw ValidationException::withMessages(['goodwill_points' => 'The guest\'s data is restricted – points can\'t be credited.']);
            }
            if (! LoyaltyService::enabled()) {
                throw ValidationException::withMessages(['goodwill_points' => 'The loyalty programme is switched off.']);
            }
            if ($points > $max && ! can_do('feedback.manage')) {
                throw ValidationException::withMessages(['goodwill_points' => "More than {$max} goodwill points needs a manager (Feedback settings permission)."]);
            }
        }
        DB::transaction(function () use ($case, $resolution, $action, $points) {
            if ($points > 0) {
                LoyaltyService::adjust($case->guest, $points, "Goodwill – case {$case->case_no}");
            }
            $case->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => auth()->id(), 'resolution' => $resolution, 'recovery_action' => $action,
                'goodwill_points' => $case->goodwill_points + max(0, $points), 'first_response_at' => $case->first_response_at ?? now()]);
            self::event($case, 'resolved', FeedbackCase::ACTIONS[$action].($points > 0 ? " · {$points} goodwill points credited" : '')."\n".$resolution);
            ActivityLogger::log('feedback', 'case_resolve', "Resolved case {$case->case_no}", $case, ['points' => $points]);
        });
    }

    public static function close(FeedbackCase $case, string $outcome, ?string $note = null): void
    {
        if ($case->status !== 'resolved') {
            throw ValidationException::withMessages(['outcome' => 'Resolve the case before closing it.']);
        }
        if (! isset(FeedbackCase::OUTCOMES[$outcome])) {
            throw ValidationException::withMessages(['outcome' => 'Choose how the guest responded.']);
        }
        $case->update(['status' => 'closed', 'closed_at' => now(), 'outcome' => $outcome]);
        self::event($case, 'closed', FeedbackCase::OUTCOMES[$outcome].($note ? "\n".$note : ''));
    }

    public static function reopen(FeedbackCase $case, string $reason): void
    {
        if ($case->isActive()) {
            throw ValidationException::withMessages(['reason' => 'The case is still open.']);
        }
        $case->update(['status' => 'in_progress', 'resolved_at' => null, 'closed_at' => null, 'outcome' => null,
            'due_at' => now()->addHours(self::slaHours($case->severity))]);
        self::event($case, 'reopened', $reason);
    }

    /** Scheduler: flag cases past their SLA once (dashboard + timeline) and hand unassigned ones to the front-office owner. */
    public static function escalateOverdue(): int
    {
        $n = 0;
        foreach (FeedbackCase::overdue()->whereNull('escalated_at')->get() as $c) {
            $owner = $c->assigned_to ?? FeedbackService::ownerFor('front_office');
            $c->forceFill(['escalated_at' => now(), 'assigned_to' => $owner])->save();
            self::event($c, 'escalated', 'Due '.$c->due_at->format('d M H:i').($owner ? ' · owner '.$c->fresh()->assignee?->name : ''));
            $n++;
        }

        return $n;
    }

    /** Manual case (e.g. a complaint at the desk). */
    public static function manual(array $d, ?Guest $guest, ?int $reservationId): FeedbackCase
    {
        return self::open(['source' => 'manual', 'guest_id' => $guest?->id, 'reservation_id' => $reservationId, 'department' => $d['department'], 'severity' => $d['severity'],
            'in_house' => (bool) ($d['in_house'] ?? false), 'title' => $d['title'], 'details' => $d['details'] ?? null]
            + (array_key_exists('assigned_to', $d) && $d['assigned_to'] ? ['assigned_to' => (int) $d['assigned_to']] : []));
    }
}
