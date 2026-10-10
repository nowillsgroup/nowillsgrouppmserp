<?php

namespace App\Http\Controllers;

use App\Models\FeedbackCase;
use App\Models\GuestFeedback;
use App\Models\OnlineReview;
use App\Models\Reservation;
use App\Services\CaseService;
use App\Services\FeedbackService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** v3.1 service-recovery cases. */
class CaseController extends Controller
{
    public const TABS = ['open' => 'Open', 'mine' => 'Mine', 'overdue' => 'Past SLA', 'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All'];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->tab, self::TABS) ? $request->tab : 'open';
        $base = FeedbackCase::query()
            ->when($request->dept, fn ($q, $v) => $q->where('department', $v))
            ->when($request->severity, fn ($q, $v) => $q->where('severity', $v))
            ->when($request->source, fn ($q, $v) => $q->where('source', $v))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('case_no', 'like', "%$t%")->orWhere('title', 'like', "%$t%")));
        $counts = [];
        foreach (array_keys(self::TABS) as $t) {
            $counts[$t] = $this->tab(clone $base, $t)->count();
        }
        $items = $this->tab(clone $base, $tab)->with('assignee', 'guest')
            ->orderByRaw(in_array($tab, ['open', 'mine', 'overdue']) ? "field(severity, 'high', 'medium', 'low'), due_at" : 'id desc')->paginate(25)->withQueryString();
        $done = FeedbackCase::whereNotNull('resolved_at')->where('resolved_at', '>=', now()->subDays(90))->get(['created_at', 'resolved_at', 'due_at', 'outcome', 'status']);

        return view('reputation.cases', ['items' => $items, 'tab' => $tab, 'counts' => $counts, 'staff' => FeedbackService::caseStaff(),
            'kpi' => ['resolved' => $done->count(), 'sla' => $done->count() ? round($done->filter(fn ($c) => $c->resolved_at->lte($c->due_at))->count() / $done->count() * 100) : null,
                'hours' => $done->count() ? round($done->avg(fn ($c) => $c->created_at->diffInMinutes($c->resolved_at) / 60), 1) : null,
                'recovered' => ($cl = $done->where('status', 'closed')->whereIn('outcome', ['recovered', 'not_recovered']))->count() ? round($cl->where('outcome', 'recovered')->count() / $cl->count() * 100) : null]]);
    }

    protected function tab($q, string $tab)
    {
        return match ($tab) {
            'open' => $q->active(),
            'mine' => $q->active()->where('assigned_to', auth()->id()),
            'overdue' => $q->overdue(),
            'all' => $q,
            default => $q->where('status', $tab),
        };
    }

    public function store(Request $request)
    {
        $staff = FeedbackService::caseStaff();
        $d = $request->validate([
            'title' => 'required|string|max:160', 'details' => 'nullable|string|max:5000', 'department' => 'required|in:'.implode(',', array_keys(FeedbackService::DEPTS)),
            'severity' => 'required|in:'.implode(',', array_keys(FeedbackCase::SEVERITIES)), 'in_house' => 'nullable|boolean', 'reservation_no' => 'nullable|string|max:30',
            'assigned_to' => ['nullable', 'integer', fn ($a, $v, $fail) => $v && ! $staff->has((int) $v) ? $fail('Choose an active colleague who can handle guest cases.') : null],
        ]);
        $res = null;
        if (! empty($d['reservation_no'])) {
            $res = Reservation::with('guest')->where('reservation_no', trim($d['reservation_no']))->first();
            if (! $res) {
                throw ValidationException::withMessages(['reservation_no' => 'No reservation with that number in this hotel.']);
            }
        }
        $d['in_house'] = $request->boolean('in_house') || $res?->status === 'checked_in';
        $case = CaseService::manual($d, $res?->guest, $res?->id);

        return redirect()->route('rep.cases.show', $case)->with('success', "Case {$case->case_no} opened.");
    }

    public function fromFeedback(GuestFeedback $feedback)
    {
        if ($c = $feedback->recoveryCase) {
            return redirect()->route('rep.cases.show', $c);
        }
        $neg = array_keys(array_filter((array) $feedback->aspects, fn ($s) => $s === 'negative'));
        $case = CaseService::open(['source' => 'feedback', 'feedback_id' => $feedback->id, 'guest_id' => $feedback->guest_id, 'reservation_id' => $feedback->reservation_id,
            'department' => $neg[0] ?? 'front_office', 'severity' => 'low', 'in_house' => $feedback->in_house,
            'title' => Str::limit(($feedback->guest_name ?: 'Guest').' – follow-up on feedback of '.$feedback->created_at->format('d M'), 160), 'details' => FeedbackService::feedbackText($feedback) ?: null]);

        return redirect()->route('rep.cases.show', $case)->with('success', "Case {$case->case_no} opened.");
    }

    public function fromReview(OnlineReview $review)
    {
        if ($c = $review->recoveryCase) {
            return redirect()->route('rep.cases.show', $c);
        }
        $neg = array_keys(array_filter((array) $review->aspects, fn ($s) => $s === 'negative'));
        $case = CaseService::open(['source' => 'review', 'review_id' => $review->id, 'guest_id' => $review->guest_id, 'reservation_id' => $review->reservation_id,
            'department' => $neg[0] ?? 'front_office', 'severity' => (float) $review->rating5 <= 2 ? 'medium' : 'low',
            'title' => Str::limit($review->sourceName().' review by '.$review->reviewer_name.($review->title ? ' – '.$review->title : ''), 160), 'details' => $review->body]);

        return redirect()->route('rep.cases.show', $case)->with('success', "Case {$case->case_no} opened.");
    }

    public function show(FeedbackCase $fcase)
    {
        $fcase->load('events.user', 'feedback.answers.question', 'feedback.stay.room', 'review', 'guest', 'reservation', 'assignee', 'opener', 'resolver');

        return view('reputation.case', ['c' => $fcase, 'staff' => FeedbackService::caseStaff(), 'goodwillMax' => (int) setting('fb_goodwill_max', FeedbackService::DEFAULTS['fb_goodwill_max']),
            'loyaltyOn' => \App\Services\LoyaltyService::enabled()]);
    }

    public function assign(Request $request, FeedbackCase $fcase)
    {
        CaseService::assign($fcase, $request->validate(['assigned_to' => 'nullable|integer'])['assigned_to'] ?? null);

        return back()->with('success', 'Owner updated.');
    }

    public function start(Request $request, FeedbackCase $fcase)
    {
        CaseService::start($fcase, $request->validate(['note' => 'nullable|string|max:2000'])['note'] ?? null);

        return back()->with('success', 'Marked as in progress – the guest has been acknowledged.');
    }

    public function note(Request $request, FeedbackCase $fcase)
    {
        CaseService::note($fcase, $request->validate(['note' => 'required|string|max:2000'])['note']);

        return back()->with('success', 'Note added.');
    }

    public function contact(Request $request, FeedbackCase $fcase)
    {
        $d = $request->validate(['channel' => 'required|in:email,sms', 'subject' => 'required_if:channel,email|nullable|string|max:150', 'body' => 'required|string|min:10|max:2000']);
        CaseService::contact($fcase, $d['channel'], $d['subject'] ?: 'A message from '.hotel()->name, $d['body']);

        return back()->with('success', 'Message sent to the guest.');
    }

    public function resolve(Request $request, FeedbackCase $fcase)
    {
        $d = $request->validate(['resolution' => 'required|string|min:5|max:3000', 'recovery_action' => 'required|in:'.implode(',', array_keys(FeedbackCase::ACTIONS)),
            'goodwill_points' => 'nullable|integer|min:0|max:1000000']);
        CaseService::resolve($fcase, $d['resolution'], $d['recovery_action'], (int) ($d['goodwill_points'] ?? 0));

        return back()->with('success', "Case {$fcase->case_no} resolved.");
    }

    public function close(Request $request, FeedbackCase $fcase)
    {
        $d = $request->validate(['outcome' => 'required|in:'.implode(',', array_keys(FeedbackCase::OUTCOMES)), 'note' => 'nullable|string|max:1000']);
        CaseService::close($fcase, $d['outcome'], $d['note'] ?? null);

        return back()->with('success', "Case {$fcase->case_no} closed.");
    }

    public function reopen(Request $request, FeedbackCase $fcase)
    {
        CaseService::reopen($fcase, $request->validate(['reason' => 'required|string|min:5|max:1000'])['reason']);

        return back()->with('success', "Case {$fcase->case_no} re-opened.");
    }
}
