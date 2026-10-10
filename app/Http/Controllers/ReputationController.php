<?php

namespace App\Http\Controllers;

use App\Models\FeedbackCase;
use App\Models\GuestFeedback;
use App\Models\OnlineReview;
use App\Models\ReviewTemplate;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\FeedbackService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** v3.1 reputation overview, feedback responses, settings and review-response templates. */
class ReputationController extends Controller
{
    public const PERIODS = [30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'];

    public static function range(Request $request): array
    {
        $days = array_key_exists((int) $request->input('days'), self::PERIODS) ? (int) $request->input('days') : 90;
        $to = now()->max(bdate())->copy()->startOfDay();

        return [$to->copy()->subDays($days - 1), $to, $days];
    }

    public function index(Request $request)
    {
        [$from, $to, $days] = self::range($request);
        $stats = FeedbackService::stats($from, $to);
        $prev = FeedbackService::stats($from->copy()->subDays($days), $from->copy()->subDay());

        return view('reputation.index', [
            'stats' => $stats, 'prev' => $prev, 'days' => $days, 'from' => $from, 'to' => $to, 'trend' => FeedbackService::trend(6), 'alerts' => FeedbackService::alerts(),
            'attention' => GuestFeedback::with('recoveryCase', 'guest')->where('sentiment', 'negative')->whereBetween('created_at', [$from, $to->copy()->endOfDay()])->latest()->limit(6)->get(),
            'cases' => FeedbackCase::active()->with('assignee')->orderByRaw("field(severity, 'high', 'medium', 'low')")->orderBy('due_at')->limit(6)->get(),
            'waiting' => OnlineReview::whereIn('status', ['new', 'draft', 'pending'])->orderBy('review_date')->limit(6)->get(),
        ]);
    }

    public function responses(Request $request)
    {
        $q = GuestFeedback::with('guest', 'recoveryCase', 'stay.room')
            ->when($request->source, fn ($q, $v) => $q->where('source', $v))
            ->when($request->sentiment, fn ($q, $v) => $q->where('sentiment', $v))
            ->when($request->group, fn ($q, $v) => $q->where('nps_group', $v))
            ->when($request->dept, fn ($q, $v) => $q->whereRaw('json_unquote(json_extract(aspects, ?)) in (\'negative\', \'mixed\')', ['$.'.preg_replace('/[^a-z_]/', '', $v)]))
            ->when($request->status === 'new', fn ($q) => $q->where('status', 'new'))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('guest_name', 'like', "%$t%")->orWhere('comments', 'like', "%$t%")))
            ->when($request->from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
        $rows = (clone $q)->get(['nps', 'rating_overall']);

        return view('reputation.responses', ['items' => $q->latest()->paginate(25)->withQueryString(), 'nps' => FeedbackService::npsOf($rows),
            'csat' => $rows->count() ? round($rows->avg('rating_overall'), 2) : null, 'count' => $rows->count(), 'unread' => GuestFeedback::where('status', 'new')->count()]);
    }

    public function response(GuestFeedback $feedback)
    {
        $feedback->load('answers.question', 'guest', 'reservation', 'stay.room', 'survey', 'invitation', 'recoveryCase.assignee', 'reviewer');

        return view('reputation.response', ['f' => $feedback, 'ratings' => FeedbackService::ratings($feedback)]);
    }

    public function markReviewed(GuestFeedback $feedback)
    {
        $feedback->forceFill(['status' => 'reviewed', 'reviewed_by' => auth()->id(), 'reviewed_at' => now()])->save();

        return back()->with('success', 'Marked as read.');
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        return view('reputation.settings', ['s' => collect(FeedbackService::DEFAULTS)->mapWithKeys(fn ($v, $k) => [$k => setting($k, $v)])->all(),
            'owners' => FeedbackService::owners(), 'staff' => FeedbackService::caseStaff(), 'templates' => ReviewTemplate::orderBy('sentiment')->orderBy('name')->get()]);
    }

    public function saveSettings(Request $request)
    {
        $staff = FeedbackService::caseStaff();
        $data = $request->validate([
            'fb_survey_auto' => 'nullable|boolean', 'fb_review_approval' => 'nullable|boolean',
            'fb_cooldown_days' => 'required|integer|min:0|max:365', 'fb_alert_nps' => 'required|integer|min:0|max:10', 'fb_alert_rating' => 'required|integer|min:1|max:4',
            'fb_alert_review' => 'required|numeric|min:1|max:4.5', 'fb_sla_high' => 'required|integer|min:1|max:168', 'fb_sla_medium' => 'required|integer|min:1|max:336',
            'fb_sla_low' => 'required|integer|min:1|max:720', 'fb_sla_inhouse' => 'required|integer|min:1|max:48', 'fb_goodwill_max' => 'required|integer|min:0|max:1000000',
            'fb_review_link_google' => ['nullable', 'url:https', 'max:500'], 'fb_review_link_tripadvisor' => ['nullable', 'url:https', 'max:500'],
            'fb_signature' => 'required|string|max:120', 'fb_severe_words' => 'nullable|string|max:1000',
            'owners' => 'nullable|array', 'owners.*' => ['nullable', 'integer', fn ($a, $v, $fail) => $v && ! $staff->has((int) $v) ? $fail('Case owners must be active staff who can handle guest cases.') : null],
        ], [], ['fb_review_link_google' => 'Google review link', 'fb_review_link_tripadvisor' => 'TripAdvisor review link']);
        if ((int) $data['fb_sla_high'] > (int) $data['fb_sla_medium'] || (int) $data['fb_sla_medium'] > (int) $data['fb_sla_low']) {
            return back()->withInput()->withErrors(['fb_sla_high' => 'SLA hours must grow with lower severity (high ≤ medium ≤ low).']);
        }
        $data['fb_survey_auto'] = $request->boolean('fb_survey_auto') ? '1' : '0';
        $data['fb_review_approval'] = $request->boolean('fb_review_approval') ? '1' : '0';
        $data['fb_severe_words'] = collect(explode(',', (string) ($data['fb_severe_words'] ?? '')))->map(fn ($w) => mb_strtolower(trim($w)))->filter()->unique()->implode(', ');
        $owners = collect($data['owners'] ?? [])->filter()->only(array_keys(FeedbackService::DEPTS))->map(fn ($v) => (int) $v)->all();
        unset($data['owners']);
        $data['fb_owners'] = json_encode((object) $owners);
        foreach ($data as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) ($v ?? '')]);
        }
        Setting::$version++;
        ActivityLogger::log('feedback', 'settings', 'Updated feedback & reputation settings');

        return back()->with('success', 'Feedback settings saved.');
    }

    protected function templateData(Request $request): array
    {
        return $request->validate(['name' => 'required|string|max:80', 'sentiment' => 'required|in:'.implode(',', array_keys(ReviewTemplate::SENTIMENTS)),
            'body' => 'required|string|min:20|max:4000', 'is_active' => 'nullable|boolean']) + ['is_active' => $request->boolean('is_active', true)];
    }

    public function storeTemplate(Request $request)
    {
        ReviewTemplate::create($this->templateData($request));

        return back()->with('success', 'Template added.');
    }

    public function updateTemplate(Request $request, ReviewTemplate $rtpl)
    {
        $rtpl->update(array_merge($this->templateData($request), ['is_active' => $request->boolean('is_active')]));

        return back()->with('success', 'Template saved.');
    }

    public function destroyTemplate(ReviewTemplate $rtpl)
    {
        $rtpl->delete();

        return back()->with('success', 'Template deleted.');
    }

    public static function monthLabel(string $ym): string
    {
        return Carbon::parse($ym.'-01')->format('M Y');
    }
}
