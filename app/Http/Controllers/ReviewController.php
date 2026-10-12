<?php

namespace App\Http\Controllers;

use App\Models\OnlineReview;
use App\Models\ReviewTemplate;
use App\Services\ReviewService;
use Illuminate\Http\Request;

/** v3.1 online reviews: inbox, import / manual entry, managed responses with approval. */
class ReviewController extends Controller
{
    public const TABS = ['respond' => 'To respond', 'pending' => 'Awaiting approval', 'approved' => 'Ready to post', 'posted' => 'Responded', 'ignored' => 'No response', 'all' => 'All'];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->tab, self::TABS) ? $request->tab : 'respond';
        $base = OnlineReview::query()
            ->when($request->source, fn ($q, $v) => $q->where('source', $v))
            ->when($request->stars, fn ($q, $v) => $v === 'low' ? $q->where('rating5', '<=', 3) : $q->where('rating5', '>=', 4))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('reviewer_name', 'like', "%$t%")->orWhere('title', 'like', "%$t%")->orWhere('body', 'like', "%$t%")));
        $counts = [];
        foreach (array_keys(self::TABS) as $t) {
            $counts[$t] = $this->tab(clone $base, $t)->count();
        }
        $items = $this->tab(clone $base, $tab)->with('guest', 'recoveryCase')->orderByRaw($tab === 'respond' ? 'rating5 asc, review_date asc' : 'review_date desc')->paginate(20)->withQueryString();
        $sources = OnlineReview::selectRaw('source, count(*) n, avg(rating5) a, max(review_date) last')->groupBy('source')->orderByDesc('n')->get();

        return view('reputation.reviews', compact('items', 'tab', 'counts', 'sources'));
    }

    protected function tab($q, string $tab)
    {
        return match ($tab) {
            'respond' => $q->whereIn('status', ['new', 'draft']),
            'all' => $q,
            default => $q->where('status', $tab),
        };
    }

    public function show(OnlineReview $review)
    {
        $review->load('guest', 'reservation', 'recoveryCase.assignee', 'drafter', 'approver', 'poster');
        $templates = ReviewTemplate::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($t) => [$t->id => ['name' => $t->name, 'sentiment' => $t->sentiment, 'text' => ReviewService::render($t, $review)]]);
        $suggested = ReviewService::suggestedTemplate($review);

        return view('reputation.review', ['r' => $review, 'templates' => $templates, 'suggested' => $suggested?->id, 'needsApproval' => ReviewService::needsApproval($review)]);
    }

    protected function reviewData(Request $request): array
    {
        $scale = (int) $request->input('rating_scale') === 10 ? 10 : 5;

        return $request->validate([
            'source' => 'required|in:'.implode(',', array_keys(OnlineReview::SOURCES)), 'external_id' => 'nullable|string|max:80',
            'reviewer_name' => 'required|string|max:120', 'reviewer_location' => 'nullable|string|max:120', 'review_date' => 'required|date|before_or_equal:today',
            'rating_scale' => 'required|in:5,10', 'rating' => "required|numeric|min:1|max:{$scale}", 'title' => 'nullable|string|max:200', 'body' => 'required|string|min:3|max:10000',
            'url' => ['nullable', 'url:http,https', 'max:500'], 'trip_type' => 'nullable|in:'.implode(',', array_keys(OnlineReview::TRIP_TYPES)),
        ]);
    }

    public function store(Request $request)
    {
        [$r, $created] = ReviewService::store($this->reviewData($request));

        return redirect()->route('rep.reviews.show', $r)->with($created ? 'success' : 'warning', $created
            ? 'Review added.'.($r->recoveryCase ? " Service-recovery case {$r->recoveryCase->case_no} opened." : '')
            : 'This review is already in the inbox.');
    }

    /** v7.39 Pull new reviews from the platform feeds (Booking.com / Google / TripAdvisor …). */
    public function pull()
    {
        try {
            $s = \App\Services\SocialPublishService::pullReviews();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($s['errors'] ? 'warning' : 'success',
            "Feed pull: {$s['pulled']} review(s) seen – {$s['new']} new, {$s['duplicates']} already in the inbox."
            .($s['errors'] ? ' Problems: '.implode('; ', $s['errors']) : ''));
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|max:4096|mimes:csv,txt', 'source' => 'required|in:'.implode(',', array_keys(OnlineReview::SOURCES))]);
        $res = ReviewService::importCsv($request->file('file')->getRealPath(), $request->source);
        $msg = "Imported {$res['imported']} review(s)".($res['duplicates'] ? ", {$res['duplicates']} already in the inbox" : '').'.';
        if ($res['errors']) {
            $list = collect($res['errors'])->take(5)->map(fn ($e, $line) => "row {$line}: {$e}")->implode('; ');

            return back()->with('warning', $msg.' '.count($res['errors'])." row(s) skipped – {$list}".(count($res['errors']) > 5 ? ' …' : ''));
        }

        return back()->with('success', $msg);
    }

    protected function text(Request $request): string
    {
        return (string) $request->validate(['response_text' => 'required|string|max:'.(ReviewService::MAX_RESPONSE + 100)])['response_text'];
    }

    public function draft(Request $request, OnlineReview $review)
    {
        ReviewService::saveDraft($review, $this->text($request));

        return back()->with('success', 'Draft saved.');
    }

    public function submit(Request $request, OnlineReview $review)
    {
        $status = ReviewService::submit($review, $this->text($request));

        return back()->with('success', $status === 'pending' ? 'Sent for approval – a manager must approve responses to critical reviews.' : 'Response ready – copy it to '.$review->sourceName().', then mark it as posted.');
    }

    public function approve(OnlineReview $review)
    {
        ReviewService::approve($review);

        return back()->with('success', 'Response approved – ready to post.');
    }

    public function returnDraft(Request $request, OnlineReview $review)
    {
        ReviewService::returnDraft($review, $request->validate(['return_note' => 'required|string|max:255'])['return_note']);

        return back()->with('success', 'Returned to the author with your note.');
    }

    public function posted(OnlineReview $review)
    {
        ReviewService::markPosted($review);

        return back()->with('success', 'Marked as posted on '.$review->sourceName().'.');
    }

    public function ignore(Request $request, OnlineReview $review)
    {
        ReviewService::ignore($review, $request->validate(['ignore_reason' => 'required|string|max:160'])['ignore_reason']);

        return back()->with('success', 'Marked as "no response".');
    }

    public function restore(OnlineReview $review)
    {
        ReviewService::restore($review);

        return back()->with('success', 'Back in the inbox.');
    }

    public function link(Request $request, OnlineReview $review)
    {
        ReviewService::linkToReservation($review, $request->validate(['reservation_no' => 'nullable|string|max:30'])['reservation_no'] ?? null);

        return back()->with('success', $review->fresh()->guest_id ? 'Linked to the guest\'s stay.' : 'Link removed.');
    }
}
