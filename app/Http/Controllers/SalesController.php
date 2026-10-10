<?php

namespace App\Http\Controllers;

use App\Models\BanquetEvent;
use App\Models\Company;
use App\Models\RateContract;
use App\Models\RoomBlock;
use App\Models\SalesActivity;
use App\Models\SalesContact;
use App\Models\SalesOpportunity;
use App\Services\SalesService as SS;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

/** v6.1 Sales & account management: accounts, contacts, opportunity pipeline, sales diary, targets, production. */
class SalesController extends Controller
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

    protected function mineOnly(Request $r): ?int
    {
        return $r->query('owner') === 'me' ? auth()->id() : ($r->filled('owner') ? (int) $r->owner : null);
    }

    protected function formData(): array
    {
        return ['mevents' => \App\Services\MarketingPlanService::attributable(), 'sellers' => SS::sellers(), 'accounts' => Company::where('is_active', true)->whereIn('type', ['corporate', 'travel_agent'])->orderBy('name')->get(['id', 'name', 'type'])];
    }

    // ───────────────────────── dashboard ─────────────────────────

    public function index(Request $r)
    {
        SS::ensureDefaults();
        $owner = $this->mineOnly($r);
        $t = bdate()->copy();
        $won = SalesOpportunity::where('stage', 'won')->when($owner, fn ($q) => $q->where('owner_id', $owner));
        $decided = SalesOpportunity::whereIn('stage', ['won', 'lost'])->where('closed_on', '>=', $t->copy()->subDays(90)->toDateString())->when($owner, fn ($q) => $q->where('owner_id', $owner))->get();
        $prod = SS::production($t->copy()->startOfYear(), $t->copy());
        $top = Company::whereIn('id', $prod->keys())->get()->keyBy('id');

        return view('sales.index', $this->formData() + [
            'owner' => $owner, 'pipe' => SS::pipeline($owner), 'alerts' => SS::alerts($owner), 'cfg' => SS::cfg(),
            'wonMonth' => (clone $won)->where('closed_on', '>=', $t->copy()->startOfMonth()->toDateString())->get(),
            'wonYear' => (clone $won)->where('closed_on', '>=', $t->copy()->startOfYear()->toDateString())->get(),
            'winRate' => $decided->count() ? round($decided->where('stage', 'won')->count() * 100 / $decided->count()) : null, 'decided' => $decided->count(),
            'agenda' => SalesActivity::with('company', 'owner', 'opportunity')->where('status', 'planned')->where('due_at', '<=', $t->copy()->addDays(7)->endOfDay())
                ->when($owner, fn ($q) => $q->where('owner_id', $owner))->orderBy('due_at')->limit(12)->get(),
            'closing' => SalesOpportunity::with('company', 'owner')->whereIn('stage', SalesOpportunity::OPEN)->where('expected_close', '<=', $t->copy()->addDays(30)->toDateString())
                ->when($owner, fn ($q) => $q->where('owner_id', $owner))->orderBy('expected_close')->limit(8)->get(),
            'stale' => SS::staleOpportunities($owner)->take(6), 'dormant' => SS::dormantAccounts($owner)->take(6), 'birthdays' => SS::birthdays()->take(6),
            'top' => $prod->sortByDesc('revenue')->take(8)->map(fn ($p, $id) => $p + ['company' => $top[$id] ?? null])->filter(fn ($p) => $p['company']),
        ]);
    }

    // ───────────────────────── accounts ─────────────────────────

    public function accounts(Request $r)
    {
        $q = Company::with('accountManager')->withCount(['opportunities as open_count' => fn ($q) => $q->whereIn('stage', SalesOpportunity::OPEN)])
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$r->q.'%')->orWhere('contact_person', 'like', '%'.$r->q.'%')->orWhere('city', 'like', '%'.$r->q.'%')))
            ->when($r->filled('type'), fn ($q) => $q->where('type', $r->type))->when(! $r->filled('type'), fn ($q) => $q->whereIn('type', ['corporate', 'travel_agent']))
            ->when($r->filled('tier'), fn ($q) => $q->where('tier', $r->tier))->when($r->filled('status'), fn ($q) => $q->where('sales_status', $r->status))
            ->when($r->filled('industry'), fn ($q) => $q->where('industry', $r->industry))
            ->when($r->query('manager') === 'none', fn ($q) => $q->whereNull('account_manager_id'))
            ->when($r->filled('manager') && $r->manager !== 'none', fn ($q) => $q->where('account_manager_id', $r->manager === 'me' ? auth()->id() : (int) $r->manager));
        $list = $q->orderByRaw("FIELD(tier, 'key', 'major', 'standard')")->orderBy('name')->get();
        $t = bdate()->copy();
        $prod = SS::production($t->copy()->startOfYear(), $t->copy(), $list->pluck('id')->all());
        $pipe = SalesOpportunity::whereIn('stage', SalesOpportunity::OPEN)->whereIn('company_id', $list->pluck('id'))->get()->groupBy('company_id')->map(fn ($g) => $g->sum(fn ($o) => $o->weighted()));

        return view('sales.accounts', $this->formData() + ['list' => $list, 'prod' => $prod, 'pipe' => $pipe, 'touch' => SS::lastTouch(), 'next' => SS::nextPlanned(),
            'dormantCut' => $t->copy()->subDays(SS::cfg()['dormant_days'])]);
    }

    public function account(Company $saccount)
    {
        $c = $saccount->load('accountManager', 'contacts');

        return view('sales.account', $this->formData() + ['c' => $c, 'ytd' => SS::ytd($c), 'monthly' => SS::monthly($c), 'canEdit' => SS::canEdit(auth()->user(), $c->account_manager_id),
            'opps' => $c->opportunities()->with('owner')->orderByRaw("FIELD(stage, 'negotiation', 'proposal', 'qualified', 'prospect', 'won', 'lost')")->latest('id')->get(),
            'acts' => $c->salesActivities()->with('owner', 'contact', 'opportunity')->orderByRaw("status = 'planned' DESC")->orderByDesc('due_at')->limit(40)->get(),
            'contracts' => RateContract::where('company_id', $c->id)->latest('valid_from')->get(),
            'credit' => \App\Services\CreditService::position($c)]);
    }

    public function accountStore(Request $r)
    {
        try {
            $c = SS::saveAccount($r->all(), null, $r->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('sales.account', $c)->with('success', "{$c->name} added as a {$c->salesStatusLabel()}.");
    }

    public function accountUpdate(Request $r, Company $saccount)
    {
        return $this->attempt(fn () => SS::saveAccount($r->all(), $saccount, $r->user()), 'Account updated.');
    }

    public function contactStore(Request $r, Company $saccount)
    {
        return $this->attempt(fn () => SS::saveContact($saccount, $r->all() + ['is_primary' => $r->boolean('is_primary'), 'decision_maker' => $r->boolean('decision_maker')], null, $r->user()), 'Contact added.');
    }

    public function contactUpdate(Request $r, SalesContact $scontact)
    {
        return $this->attempt(fn () => SS::saveContact($scontact->company, $r->all() + ['is_primary' => $r->boolean('is_primary'), 'decision_maker' => $r->boolean('decision_maker'), 'is_active' => $r->boolean('is_active')], $scontact, $r->user()), 'Contact updated.');
    }

    public function contactDelete(Request $r, SalesContact $scontact)
    {
        return $this->attempt(fn () => SS::deleteContact($scontact, $r->user()), 'Contact removed.');
    }

    // ───────────────────────── opportunities ─────────────────────────

    public function opportunities(Request $r)
    {
        $owner = $this->mineOnly($r);
        $list = $r->query('view') === 'list';
        $q = SalesOpportunity::with('company', 'owner', 'contact')->when($owner, fn ($q) => $q->where('owner_id', $owner))
            ->when($r->filled('type'), fn ($q) => $q->where('type', $r->type))
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$r->q.'%')->orWhere('number', 'like', '%'.$r->q.'%')->orWhereHas('company', fn ($c) => $c->where('name', 'like', '%'.$r->q.'%'))));
        if ($list) {
            $q->when($r->query('stage') === 'open', fn ($q) => $q->whereIn('stage', SalesOpportunity::OPEN))
                ->when($r->filled('stage') && $r->stage !== 'open', fn ($q) => $q->where('stage', $r->stage))
                ->when($r->filled('from'), fn ($q) => $q->where('expected_close', '>=', $r->from))->when($r->filled('to'), fn ($q) => $q->where('expected_close', '<=', $r->to));
            $opps = $q->orderByRaw("FIELD(stage, 'negotiation', 'proposal', 'qualified', 'prospect', 'won', 'lost')")->orderBy('expected_close')->paginate(40)->withQueryString();
        } else {
            $since = bdate()->copy()->subDays(60)->toDateString();
            $opps = $q->where(fn ($w) => $w->whereIn('stage', SalesOpportunity::OPEN)->orWhere('closed_on', '>=', $since))->orderBy('expected_close')->get()->groupBy('stage');
        }

        return view('sales.opportunities', $this->formData() + ['opps' => $opps, 'list' => $list, 'owner' => $owner, 'pipe' => SS::pipeline($owner), 'cfg' => SS::cfg()]);
    }

    public function opportunity(SalesOpportunity $opp)
    {
        $o = $opp->load('company.contacts', 'contact', 'owner', 'contract', 'block', 'event');

        return view('sales.opportunity', $this->formData() + ['o' => $o, 'canEdit' => SS::canEdit(auth()->user(), $o->owner_id),
            'acts' => $o->activities()->with('owner', 'contact')->orderByRaw("status = 'planned' DESC")->orderByDesc('due_at')->get(),
            'blocks' => $o->stage === 'won' ? RoomBlock::where(fn ($q) => $q->where('company_id', $o->company_id)->orWhereNull('company_id'))->latest('arrival_date')->limit(30)->get() : collect(),
            'events' => $o->stage === 'won' ? BanquetEvent::where(fn ($q) => $q->where('company_id', $o->company_id)->orWhereNull('company_id'))->latest('event_date')->limit(30)->get() : collect(),
            'contracts' => $o->stage === 'won' ? RateContract::where('company_id', $o->company_id)->latest('valid_from')->get() : collect()]);
    }

    public function proposal(SalesOpportunity $opp)
    {
        return view('sales.proposal', ['o' => $opp->load('company', 'contact', 'owner')]);
    }

    public function opportunityStore(Request $r)
    {
        try {
            $o = SS::saveOpportunity($r->all(), null, $r->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('sales.opportunity', $o)->with('success', "{$o->number} opened.");
    }

    public function opportunityUpdate(Request $r, SalesOpportunity $opp)
    {
        return $this->attempt(fn () => SS::saveOpportunity($r->all(), $opp, $r->user()), 'Opportunity updated.');
    }

    public function stage(Request $r, SalesOpportunity $opp)
    {
        return $this->attempt(fn () => SS::moveStage($opp, (string) $r->stage, $r->user(), $r->only('lost_reason', 'lost_note', 'probability')) ? "{$opp->number} moved to {$opp->stageLabel()}." : null, 'Stage changed.');
    }

    public function reopen(Request $r, SalesOpportunity $opp)
    {
        return $this->attempt(fn () => SS::reopen($opp, $r->user()) ? "{$opp->number} reopened in negotiation." : null, 'Reopened.');
    }

    public function link(Request $r, SalesOpportunity $opp)
    {
        return $this->attempt(fn () => SS::link($opp, (string) $r->kind, $r->filled('id') ? (int) $r->id : null, $r->user()), 'Link saved.');
    }

    public function draftContract(Request $r, SalesOpportunity $opp)
    {
        try {
            $k = SS::draftContract($opp, $r->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        if ($r->user()->hasPermission('contracts.view')) {
            return redirect()->route('contracts.show', $k)->with('success', "Draft contract {$k->code} created – add the room rates and send it for approval.");
        }

        return back()->with('success', "Draft contract {$k->code} created – the contracts team will add the rates.");
    }

    // ───────────────────────── diary ─────────────────────────

    public function activities(Request $r)
    {
        $owner = $this->mineOnly($r);
        $today = bdate()->toDateString();
        $status = $r->query('status', 'planned');
        $q = SalesActivity::with('company', 'contact', 'opportunity', 'owner')->when($owner, fn ($q) => $q->where('owner_id', $owner))
            ->when($r->filled('type'), fn ($q) => $q->where('type', $r->type))
            ->when($r->filled('company'), fn ($q) => $q->where('company_id', (int) $r->company))
            ->when($status === 'overdue', fn ($q) => $q->where('status', 'planned')->where('due_at', '<', $today.' 00:00:00'))
            ->when(in_array($status, ['planned', 'done', 'cancelled'], true), fn ($q) => $q->where('status', $status))
            ->when($r->filled('from'), fn ($q) => $q->where('due_at', '>=', $r->from.' 00:00:00'))->when($r->filled('to'), fn ($q) => $q->where('due_at', '<=', $r->to.' 23:59:59'));
        $list = ($status === 'planned' || $status === 'overdue' ? $q->orderBy('due_at') : $q->orderByDesc('due_at'))->paginate(50)->withQueryString();

        return view('sales.activities', $this->formData() + ['list' => $list, 'status' => $status, 'owner' => $owner, 'alerts' => SS::alerts($owner), 'cfg' => SS::cfg(),
            'contacts' => SalesContact::where('is_active', true)->orderBy('name')->get(['id', 'company_id', 'name']),
            'openOpps' => SalesOpportunity::whereIn('stage', SalesOpportunity::OPEN)->orderBy('number')->get(['id', 'company_id', 'number', 'name']),
            'spend' => (float) SalesActivity::where('status', 'done')->where('done_at', '>=', bdate()->copy()->startOfMonth())->when($owner, fn ($q) => $q->where('owner_id', $owner))->sum('cost')]);
    }

    public function activityStore(Request $r)
    {
        return $this->attempt(fn () => SS::saveActivity($r->all(), null, $r->user()) ? ($r->input('status') === 'done' ? 'Activity logged.' : 'Activity planned.') : null, 'Saved.');
    }

    public function activityUpdate(Request $r, SalesActivity $sact)
    {
        return $this->attempt(fn () => SS::saveActivity($r->all(), $sact, $r->user()), 'Activity updated.');
    }

    public function complete(Request $r, SalesActivity $sact)
    {
        return $this->attempt(fn () => SS::complete($sact, $r->all(), $r->user()) ? 'Activity completed'.($r->filled('follow_type') ? ' and the follow-up planned.' : '.') : null, 'Done.');
    }

    public function cancel(Request $r, SalesActivity $sact)
    {
        return $this->attempt(fn () => SS::cancelActivity($sact, (string) $r->reason, $r->user()), 'Activity cancelled.');
    }

    // ───────────────────────── targets & settings ─────────────────────────

    public function targets(Request $r)
    {
        $period = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $r->query('period')) ? $r->query('period') : bdate()->format('Y-m');
        $p = Carbon::parse($period.'-01');

        return view('sales.targets', ['period' => $period, 'rows' => SS::targets($period), 'sellers' => SS::sellers(),
            'prev' => $p->copy()->subMonthNoOverflow()->format('Y-m'), 'next' => $p->copy()->addMonthNoOverflow()->format('Y-m'), 'label' => $p->format('F Y'),
            'current' => $period === bdate()->format('Y-m'), 'future' => $period > bdate()->format('Y-m')]);
    }

    public function saveTargets(Request $r)
    {
        return $this->attempt(fn () => 'Targets for '.$r->period.' saved ('.SS::saveTargets((string) $r->period, (array) $r->input('t', []), $r->user()).' salesperson(s)).', 'Saved.');
    }

    public function settings()
    {
        SS::ensureDefaults();

        return view('sales.settings', ['cfg' => SS::cfg()]);
    }

    public function saveSettings(Request $r)
    {
        return $this->attempt(fn () => SS::saveSettings($r->all()), 'Sales settings saved.');
    }
}
