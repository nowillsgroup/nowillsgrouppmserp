<?php

namespace App\Http\Controllers;

use App\Models\BdBenchmark;
use App\Models\BdCompetitor;
use App\Models\BdInitiative;
use App\Models\BdMilestone;
use App\Models\BdPartner;
use App\Models\BdRateShop;
use App\Models\BusinessSource;
use App\Models\Company;
use App\Models\PromoCode;
use App\Models\RoomType;
use App\Services\BusinessDevService as BD;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

/** v6.3 Business development: dashboard, competitive set (rate shops, benchmarks), market mix, partnerships, initiatives. */
class BusinessDevController extends Controller
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

    protected function range(Request $r): array
    {
        $from = ($r->filled('from') ? rescue(fn () => Carbon::parse((string) $r->query('from'))->startOfDay(), null, false) : null) ?? bdate()->copy()->startOfYear();
        $to = ($r->filled('to') ? rescue(fn () => Carbon::parse((string) $r->query('to'))->startOfDay(), null, false) : null) ?? bdate()->copy()->endOfYear()->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 731) {
            $to = $from->copy()->addDays(731);
        }

        return [$from, $to];
    }

    protected function lookups(): array
    {
        return ['people' => BD::people(), 'partnerList' => BdPartner::whereNotIn('status', ['terminated'])->orderBy('name')->get(),
            'sources' => BusinessSource::where('is_active', true)->orderBy('name')->get(), 'promos' => PromoCode::orderBy('code')->get(),
            'companies' => Company::orderBy('name')->get(['id', 'name'])];
    }

    // ------------------------------------------------------------------ dashboard

    public function index()
    {
        $last = bdate()->copy()->startOfMonth()->subMonthNoOverflow();
        $ytdFrom = bdate()->copy()->startOfYear();

        return view('bd.index', ['alerts' => BD::alerts(), 'idx' => BD::indices($last), 'series' => BD::benchmarkSeries(6), 'position' => BD::ratePosition(bdate()->copy(), 14),
            'pipeline' => BD::pipeline(), 'partners' => BD::partnerTotals($ytdFrom, bdate()->copy()->endOfYear()),
            'mix' => BD::marketMix($ytdFrom, bdate()->copy(), 'nationality')->take(6), 'segments' => BD::marketMix($ytdFrom, bdate()->copy(), 'segment'),
            'awaiting' => BdInitiative::with('owner')->where('stage', 'business_case')->whereNotNull('submitted_at')->orderBy('submitted_at')->get(),
            'milestones' => BdMilestone::with('initiative', 'owner')->whereNull('done_at')->whereDate('due_date', '<=', bdate()->copy()->addDays(14)->toDateString())
                ->whereHas('initiative', fn ($q) => $q->whereIn('stage', ['idea', 'assessment', 'business_case', 'approved', 'implementation']))->orderBy('due_date')->limit(8)->get(),
            'lastShop' => BD::lastShop(), 'competitorCount' => BdCompetitor::where('is_active', true)->count()]);
    }

    // ------------------------------------------------------------------ competitive set

    public function compset(Request $r)
    {
        $from = ($r->filled('from') ? rescue(fn () => Carbon::parse((string) $r->query('from'))->startOfDay(), null, false) : null) ?? bdate()->copy();
        $channel = array_key_exists((string) $r->query('channel'), BdRateShop::CHANNELS) ? $r->query('channel') : 'brand_site';
        $days = collect(range(0, 13))->map(fn ($k) => $from->copy()->addDays($k));

        return view('bd.compset', ['from' => $from, 'channel' => $channel, 'days' => $days, 'grid' => BD::shopGrid($from, 14, $channel), 'competitors' => BD::competitors(false),
            'active' => BD::competitors(), 'series' => BD::benchmarkSeries(12), 'position' => BD::ratePosition($from, 14), 'type' => BD::benchmarkType(),
            'ownRates' => $days->mapWithKeys(fn ($d) => [$d->toDateString() => BD::ownRate($d)]), 'lastShop' => BD::lastShop()]);
    }

    public function competitor(BdCompetitor $bdcompetitor)
    {
        $lines = $bdcompetitor->lines()->with('shop')->get()->sortByDesc(fn ($l) => $l->shop->stay_date)->take(30);

        return view('bd.competitor', ['c' => $bdcompetitor, 'lines' => $lines]);
    }

    public function competitorStore(Request $r)
    {
        return $this->attempt(fn () => redirect()->route('bd.compset')->with('success', 'Hotel '.BD::saveCompetitor($r->all(), null, $r->user())->name.' added to the competitive set.'), 'Saved.');
    }

    public function competitorUpdate(Request $r, BdCompetitor $bdcompetitor)
    {
        return $this->attempt(fn () => BD::saveCompetitor($r->all() + ['is_active' => $r->boolean('is_active')], $bdcompetitor, $r->user()) ? 'Competitor updated.' : '', 'Saved.');
    }

    public function shopStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $s = BD::saveShop($r->all(), $r->user());
            $rank = $s->ownRank();

            return 'Rate shop for '.$s->stay_date->format('D d M').' saved'.($rank ? " – we are #{$rank} of ".($s->lines->where('sold_out', false)->whereNotNull('rate')->count() + 1).' by price.' : '.');
        }, 'Saved.');
    }

    public function shopDelete(Request $r, BdRateShop $bdshop)
    {
        return $this->attempt(fn () => BD::deleteShop($bdshop, $r->user()), 'Rate shop removed.');
    }

    public function benchmarkSave(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $b = BD::saveBenchmark($r->all(), $r->user());
            $i = BD::indices($b->month, $b);

            return 'Benchmark for '.$b->month->format('M Y').' saved'.($i['rgi'] !== null ? " – MPI {$i['mpi']} · ARI {$i['ari']} · RGI {$i['rgi']}." : '.');
        }, 'Saved.');
    }

    // ------------------------------------------------------------------ market mix

    public function market(Request $r)
    {
        [$from, $to] = $this->range($r);
        $by = in_array($r->query('by'), ['nationality', 'source', 'segment'], true) ? $r->query('by') : 'nationality';

        return view('bd.market', ['from' => $from, 'to' => $to, 'by' => $by, 'rows' => BD::marketMix($from, $to, $by)]);
    }

    // ------------------------------------------------------------------ partnerships

    public function partners(Request $r)
    {
        $status = array_key_exists((string) $r->query('status'), BdPartner::STATUSES) ? $r->query('status') : null;
        $list = BdPartner::with('owner', 'source', 'promo', 'company')->when($status, fn ($q) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', 'terminated'))
            ->orderByRaw("FIELD(status, 'active', 'negotiating', 'prospect', 'expired', 'terminated')")->orderBy('name')->get();

        return view('bd.partners', ['status' => $status, 'rows' => $list->map(fn ($p) => [$p, BD::partnerProduction($p), BD::partnerFlags($p)]),
            'totals' => BD::partnerTotals()] + $this->lookups());
    }

    public function partner(BdPartner $bdpartner)
    {
        $p = $bdpartner->load('owner', 'source', 'promo', 'company', 'initiatives');
        $year = bdate()->year;
        $months = collect(range(1, 12))->map(function ($m) use ($p, $year) {
            $a = Carbon::create($year, $m, 1)->startOfDay();

            return ['month' => $a] + BD::partnerProduction($p, $a, $a->copy()->endOfMonth()->startOfDay());
        });

        return view('bd.partner', ['p' => $p, 'ytd' => BD::partnerProduction($p), 'ly' => BD::partnerProduction($p, bdate()->copy()->subYear()->startOfYear(), bdate()->copy()->subYear()->endOfYear()),
            'months' => $months, 'flags' => BD::partnerFlags($p),
            'stays' => BD::partnerQuery($p)->with('guest', 'source')->where('status', '!=', 'cancelled')->orderByDesc('arrival_date')->limit(15)->get()] + $this->lookups());
    }

    public function partnerStore(Request $r)
    {
        return $this->attempt(fn () => redirect()->route('bd.partner', BD::savePartner($r->all(), null, $r->user()))->with('success', 'Partnership created.'), 'Saved.');
    }

    public function partnerUpdate(Request $r, BdPartner $bdpartner)
    {
        return $this->attempt(fn () => BD::savePartner($r->all(), $bdpartner, $r->user()) ? 'Partnership updated.' : '', 'Saved.');
    }

    public function partnerStatus(Request $r, BdPartner $bdpartner)
    {
        return $this->attempt(fn () => BD::setPartnerStatus($bdpartner, (string) $r->input('status'), $r->all(), $r->user()), 'Partnership updated.');
    }

    // ------------------------------------------------------------------ initiatives

    public function initiatives(Request $r)
    {
        $view = $r->query('view') === 'list' ? 'list' : 'board';
        $all = BdInitiative::with('owner', 'milestones', 'results')->orderByDesc('annual_revenue')->get();

        return view('bd.initiatives', ['view' => $view, 'all' => $all, 'stats' => $all->mapWithKeys(fn ($i) => [$i->id => BD::initiativeStats($i)]), 'pipeline' => BD::pipeline()] + $this->lookups());
    }

    public function initiative(BdInitiative $bdinitiative)
    {
        $i = $bdinitiative->load('owner', 'decider', 'partner', 'milestones.owner', 'results');

        return view('bd.initiative', ['i' => $i, 's' => BD::initiativeStats($i)] + $this->lookups());
    }

    public function initiativePrint(BdInitiative $bdinitiative)
    {
        $i = $bdinitiative->load('owner', 'decider', 'partner', 'milestones.owner', 'results');

        return view('bd.initiative_print', ['i' => $i, 's' => BD::initiativeStats($i)]);
    }

    public function initiativeStore(Request $r)
    {
        return $this->attempt(fn () => redirect()->route('bd.initiative', BD::saveInitiative($r->all(), null, $r->user()))->with('success', 'Initiative created.'), 'Saved.');
    }

    public function initiativeUpdate(Request $r, BdInitiative $bdinitiative)
    {
        return $this->attempt(fn () => BD::saveInitiative($r->all(), $bdinitiative, $r->user()) ? 'Initiative updated.' : '', 'Saved.');
    }

    public function initiativeStage(Request $r, BdInitiative $bdinitiative)
    {
        return $this->attempt(fn () => BD::moveStage($bdinitiative, (string) $r->input('stage'), $r->all(), $r->user()), 'Moved.');
    }

    public function initiativeSubmit(Request $r, BdInitiative $bdinitiative)
    {
        return $this->attempt(fn () => BD::submit($bdinitiative, $r->user()), 'Business case sent for approval.');
    }

    public function initiativeDecide(Request $r, BdInitiative $bdinitiative)
    {
        return $this->attempt(fn () => BD::decide($bdinitiative, (string) $r->input('decision'), $r->input('note'), $r->user()), 'Decision saved.');
    }

    public function milestoneStore(Request $r, BdInitiative $bdinitiative)
    {
        return $this->attempt(fn () => BD::addMilestone($bdinitiative, $r->all(), $r->user()), 'Milestone added.');
    }

    public function milestoneToggle(Request $r, BdMilestone $bdmilestone)
    {
        return $this->attempt(fn () => BD::toggleMilestone($bdmilestone, $r->user()) ? 'Milestone completed.' : 'Milestone re-opened.', 'Saved.');
    }

    public function milestoneDelete(Request $r, BdMilestone $bdmilestone)
    {
        return $this->attempt(fn () => BD::deleteMilestone($bdmilestone, $r->user()), 'Milestone removed.');
    }

    public function resultSave(Request $r, BdInitiative $bdinitiative)
    {
        return $this->attempt(fn () => BD::saveResult($bdinitiative, $r->all(), $r->user()), 'Results saved.');
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        return view('bd.settings', ['cfg' => BD::cfg(), 'types' => RoomType::orderBy('base_rate')->get(), 'type' => BD::benchmarkType()]);
    }

    public function settingsSave(Request $r)
    {
        return $this->attempt(fn () => BD::saveSettings($r->all()), 'Settings saved.');
    }
}
