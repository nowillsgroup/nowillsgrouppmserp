<?php

namespace App\Http\Controllers;

use App\Models\MarketingCollateral;
use App\Models\MarketingEvent;
use App\Services\ActivityLogger;
use App\Services\MarketingPlanService as MP;
use App\Services\SalesService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** v6.1 Marketing plan: calendar of marketing events with ROI, budget vs actual, collateral library. */
class MarketingPlanController extends Controller
{
    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', is_string($r) ? $r : $ok);
    }

    protected function owners()
    {
        return \App\Models\User::where('hotel_id', hotel()->id)->where('is_active', true)->orderBy('name')->get()
            ->filter(fn ($u) => ((! $u->is_super_admin && ! $u->is_group_admin) || $u->id === auth()->id()) && ($u->hasPermission('sales.marketing') || $u->hasPermission('sales.manage')))->values();
    }

    public function index(Request $r)
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $r->query('month')) ? Carbon::parse($r->query('month').'-01') : bdate()->copy()->startOfMonth();
        $year = (int) ($r->query('year') ?: $month->year);
        $events = MarketingEvent::with('owner')->whereYear('start_date', $year)
            ->when($r->filled('type'), fn ($q) => $q->where('type', $r->type))->when($r->filled('status'), fn ($q) => $q->where('status', $r->status))
            ->orderBy('start_date')->get();
        $roi = MP::roi($events);
        $done = $events->where('status', 'done');

        return view('sales.marketing', ['month' => $month, 'year' => $year, 'days' => MP::calendar($month), 'events' => $events, 'roi' => $roi, 'owners' => $this->owners(),
            'totals' => ['budget' => (float) $events->where('status', '!=', 'cancelled')->sum('budget'), 'spent' => (float) $events->whereIn('status', ['done', 'cancelled'])->sum('actual_cost'),
                'leads' => (int) $done->sum('leads'), 'won' => $roi->sum('won_value'), 'upcoming' => $events->whereIn('status', ['planned', 'confirmed'])->filter(fn ($e) => ($d = $e->start_date?->toDateString()) !== null && $d >= bdate()->toDateString())->count()]]);
    }

    public function show(MarketingEvent $mevent)
    {
        $e = $mevent->load('owner', 'opportunities.company', 'opportunities.owner');

        return view('sales.mevent', ['e' => $e, 'roi' => MP::roi(collect([$e]))[$e->id], 'owners' => $this->owners(), 'canEdit' => MP::canEdit(auth()->user(), $e->owner_id)]);
    }

    public function store(Request $r)
    {
        try {
            $e = MP::saveEvent($r->all(), null, $r->user());
        } catch (RuntimeException $ex) {
            return back()->with('error', $ex->getMessage())->withInput();
        }

        return redirect()->route('sales.mevent', $e)->with('success', "{$e->number} added to the marketing calendar.");
    }

    public function update(Request $r, MarketingEvent $mevent)
    {
        return $this->attempt(fn () => MP::saveEvent($r->all(), $mevent, $r->user()), 'Event updated.');
    }

    public function status(Request $r, MarketingEvent $mevent)
    {
        return $this->attempt(fn () => MP::setStatus($mevent, (string) $r->status, $r->all(), $r->user()) ? "{$mevent->number} is now ".strtolower($mevent->statusLabel()).'.' : null, 'Saved.');
    }

    public function budget(Request $r)
    {
        $year = (int) ($r->query('year') ?: bdate()->year);
        if ($year < 2000 || $year > 2100) {
            $year = bdate()->year;
        }

        return view('sales.budget', ['year' => $year, 'rows' => MP::budgetVsActual($year), 'edit' => $r->boolean('edit') && (can_do('sales.marketing') || can_do('sales.manage'))]);
    }

    public function saveBudget(Request $r)
    {
        try {
            $t = MP::saveBudget((int) $r->year, (array) $r->input('b', []), $r->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('sales.budget', ['year' => $r->year])->with('success', "Marketing budget {$r->year} saved – ".money($t).'.');
    }

    public function collateral(Request $r)
    {
        $list = MarketingCollateral::with('uploader')->when($r->filled('kind'), fn ($q) => $q->where('kind', $r->kind))
            ->when($r->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$r->q.'%'))
            ->when(! $r->boolean('inactive'), fn ($q) => $q->where('is_active', true))->orderBy('kind')->orderBy('title')->get();

        return view('sales.collateral', ['list' => $list]);
    }

    public function collateralStore(Request $r)
    {
        return $this->attempt(fn () => MP::saveCollateral($r->all(), $r->file('file'), null, $r->user()), 'Collateral added.');
    }

    public function collateralUpdate(Request $r, MarketingCollateral $mcollateral)
    {
        return $this->attempt(fn () => MP::saveCollateral($r->all() + ['is_active' => $r->boolean('is_active')], $r->file('file'), $mcollateral, $r->user()), 'Collateral updated.');
    }

    public function collateralDelete(MarketingCollateral $mcollateral)
    {
        MP::deleteCollateral($mcollateral);

        return back()->with('success', 'Collateral deleted.');
    }

    public function download(MarketingCollateral $mcollateral)
    {
        if (! $mcollateral->file_path) {
            return $mcollateral->url ? redirect()->away($mcollateral->url) : back()->with('error', 'No file attached.');
        }
        abort_unless(Storage::disk('local')->exists($mcollateral->file_path), 404);
        $mcollateral->increment('downloads');
        ActivityLogger::log('marketing', 'download', "Collateral “{$mcollateral->title}” downloaded", $mcollateral);

        return Storage::disk('local')->download($mcollateral->file_path, $mcollateral->file_name ?? basename($mcollateral->file_path));
    }
}
