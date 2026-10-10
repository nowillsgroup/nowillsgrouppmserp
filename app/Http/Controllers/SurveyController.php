<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Models\SurveyQuestion;
use App\Services\ActivityLogger;
use App\Services\FeedbackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** v3.1 survey builder and invitations. */
class SurveyController extends Controller
{
    public function index()
    {
        $surveys = Survey::withCount(['questions as active_questions' => fn ($q) => $q->where('is_active', true), 'responses'])->orderByDesc('is_default')->orderBy('name')->get();
        $inv = SurveyInvitation::selectRaw("survey_id, sum(status in ('sent','opened','completed','expired')) sent, sum(status = 'completed') done")->groupBy('survey_id')->get()->keyBy('survey_id');
        $nps = [];
        foreach ($surveys as $s) {
            $nps[$s->id] = FeedbackService::npsOf($s->responses()->get(['nps']))['nps'];
        }

        return view('reputation.surveys', compact('surveys', 'inv', 'nps'));
    }

    public function store(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|max:120']);
        $s = Survey::create($d + ['is_active' => false, 'is_default' => false, 'intro' => 'Tell us about your experience – it takes about one minute.', 'thank_you' => 'Thank you for your feedback!']);
        $s->questions()->create(['sort' => 10, 'type' => 'nps', 'label' => 'How likely are you to recommend us to a friend or colleague?', 'required' => true]);
        $s->questions()->create(['sort' => 20, 'type' => 'text', 'label' => 'What is the main reason for your score?']);

        return redirect()->route('rep.surveys.edit', $s)->with('success', 'Survey created (inactive) – add your questions, then activate it.');
    }

    public function edit(Survey $survey)
    {
        $survey->load(['questions' => fn ($q) => $q->withCount('answers')]);

        return view('reputation.survey-edit', ['survey' => $survey]);
    }

    public function update(Request $request, Survey $survey)
    {
        $d = $request->validate([
            'name' => 'required|string|max:120', 'intro' => 'nullable|string|max:1000', 'thank_you' => 'nullable|string|max:1000',
            'channel' => 'required|in:'.implode(',', array_keys(Survey::CHANNELS)), 'send_delay_hours' => 'required|integer|min:0|max:168',
            'reminder_hours' => 'nullable|integer|min:1|max:336', 'expiry_days' => 'required|integer|min:1|max:60',
            'q' => 'required|array|min:1|max:30', 'q.*.id' => 'nullable|integer', 'q.*.label' => 'nullable|string|max:255', 'q.*.type' => 'required|in:'.implode(',', array_keys(SurveyQuestion::TYPES)),
            'q.*.department' => 'nullable|in:'.implode(',', array_keys(FeedbackService::DEPTS)), 'q.*.options' => 'nullable|string|max:500',
        ], [], ['q.*.label' => 'question text']);
        if ($d['reminder_hours'] && $d['reminder_hours'] >= $d['expiry_days'] * 24) {
            throw ValidationException::withMessages(['reminder_hours' => 'The reminder must go out before the survey expires.']);
        }
        $existing = $survey->questions()->withCount('answers')->get()->keyBy('id');
        $rows = [];
        $errors = [];
        foreach (array_values($request->input('q', [])) as $i => $row) {
            $id = (int) ($row['id'] ?? 0);
            $old = $id ? $existing->get($id) : null;
            if ($id && ! $old) {
                abort(404);
            }
            $remove = ! empty($row['remove']);
            $label = trim((string) ($row['label'] ?? ''));
            if (! $old && ($remove || $label === '')) {
                continue; // an empty new row
            }
            $type = $row['type'];
            if ($old && $old->answers_count && $type !== $old->type) {
                $errors["q.{$i}.type"] = "\"{$old->label}\" already has answers – its type can't change (add a new question instead).";
            }
            if (! $remove && $label === '') {
                $errors["q.{$i}.label"] = 'Question '.($i + 1).' needs a text.';
            }
            $opts = null;
            if ($type === 'choice') {
                $opts = collect(explode(',', (string) ($row['options'] ?? '')))->map(fn ($o) => trim($o))->filter()->unique()->values()->all();
                if (! $remove && (count($opts) < 2 || count($opts) > 10 || collect($opts)->contains(fn ($o) => mb_strlen($o) > 60))) {
                    $errors["q.{$i}.options"] = 'Question '.($i + 1).': give 2-10 comma-separated answers (max 60 characters each).';
                }
            }
            $rows[] = ['old' => $old, 'remove' => $remove, 'data' => ['sort' => ($i + 1) * 10, 'type' => $type, 'label' => $label ?: $old?->label,
                'department' => $type === 'rating' ? ($row['department'] ?? null) : null, 'options' => $opts,
                'required' => ! empty($row['required']), 'is_active' => ! $remove && ! empty($row['active'])]];
        }
        $active = collect($rows)->filter(fn ($r) => ! $r['remove'] && $r['data']['is_active']);
        if ($active->isEmpty()) {
            $errors['q'] = 'Keep at least one active question.';
        }
        if ($active->where('data.type', 'nps')->count() > 1) {
            $errors['q'] = 'Only one recommend (NPS) question per survey.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $kept = 0;
        DB::transaction(function () use ($survey, $d, $rows, $request, &$kept) {
            $survey->update(collect($d)->except('q')->all() + ['is_active' => $request->boolean('is_active'), 'is_default' => $request->boolean('is_default')]);
            if ($survey->is_default) {
                Survey::where('id', '!=', $survey->id)->update(['is_default' => false]);
            }
            foreach ($rows as $r) {
                if ($r['old'] && $r['remove']) {
                    if ($r['old']->answers_count) {
                        $r['old']->update(['is_active' => false, 'sort' => $r['data']['sort']]);
                        $kept++;
                    } else {
                        $r['old']->delete();
                    }
                } elseif ($r['old']) {
                    $r['old']->update($r['data']);
                } else {
                    $survey->questions()->create($r['data']);
                }
            }
        });
        ActivityLogger::log('feedback', 'survey', "Updated survey \"{$survey->name}\"", $survey);

        return redirect()->route('rep.surveys.edit', $survey)->with('success', 'Survey saved.'.($kept ? " {$kept} answered question(s) were switched off instead of deleted, so past answers stay readable." : '')
            .(! Survey::where('is_default', true)->where('is_active', true)->exists() ? ' Note: no active default survey – nothing is sent at check-out.' : ''));
    }

    public function preview(Survey $survey)
    {
        return view('public.survey', ['hotel' => hotel(), 'inv' => null, 'survey' => $survey, 'questions' => $survey->activeQuestions()->get(), 'token' => str_repeat('0', 40), 'preNps' => null, 'preview' => true]);
    }

    public function invitations(Request $request)
    {
        $status = array_key_exists((string) $request->status, SurveyInvitation::STATUSES) ? $request->status : null;
        $base = SurveyInvitation::query()->when($request->survey, fn ($q, $v) => $q->where('survey_id', $v));
        $counts = (clone $base)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        $items = (clone $base)->with('guest', 'reservation', 'survey', 'response')->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("field(status, 'scheduled', 'failed') desc")->orderByDesc('id')->paginate(25)->withQueryString();

        return view('reputation.invitations', ['items' => $items, 'counts' => $counts, 'status' => $status, 'surveys' => Survey::orderBy('name')->pluck('name', 'id')]);
    }

    public function invite(Request $request)
    {
        $d = $request->validate(['reservation_no' => 'required|string|max:30', 'survey_id' => 'required|integer']);
        $survey = Survey::where('is_active', true)->findOrFail($d['survey_id']);
        $r = Reservation::where('reservation_no', trim($d['reservation_no']))->first();
        if (! $r || ! in_array($r->status, ['checked_out', 'checked_in'])) {
            throw ValidationException::withMessages(['reservation_no' => 'Enter a reservation that is in-house or checked out.']);
        }
        $existed = SurveyInvitation::where('survey_id', $survey->id)->where('reservation_id', $r->id)->exists();
        $inv = FeedbackService::scheduleFor($r, true, $survey);
        if ($existed) {
            return back()->with('warning', "{$r->reservation_no} already has this survey (".strtolower(SurveyInvitation::STATUSES[$inv->status]).').');
        }

        return back()->with($inv->status === 'sent' ? 'success' : 'warning', match ($inv->status) {
            'sent' => "Survey sent for {$r->reservation_no}.",
            'skipped' => "Not sent: {$inv->skip_reason}.",
            default => 'Not sent: '.($inv->error ?: 'delivery failed').'.',
        });
    }

    public function sendNow(SurveyInvitation $invitation)
    {
        if (! in_array($invitation->status, ['scheduled', 'failed'])) {
            return back()->with('warning', 'Only scheduled or failed invitations can be sent.');
        }
        $ok = FeedbackService::send($invitation);

        return back()->with($ok ? 'success' : 'error', $ok ? 'Survey sent.' : 'Not sent: '.$invitation->fresh()->error);
    }
}
