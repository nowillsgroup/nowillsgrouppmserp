@extends('layouts.app')
@section('title', 'Budgets & Forecasts')
@section('actions')
<a class="btn" href="{{ route('budgets.bva') }}" id="bvaLink"><x-icon name="chart"/> Budget vs Actual</a>
<a class="btn" href="{{ route('budgets.costcentres', ['fy' => $fy ?: $cur]) }}" id="ccLink"><x-icon name="users"/> Cost centres @if($costCentres)<span class="badge badge-amber">{{ $costCentres }}</span>@endif</a>
@if(can_do('budget.approve'))<button class="btn" data-modal="settingsModal" id="settingsBtn"><x-icon name="tool"/> Settings</button>@endif
@if(can_do('budget.manage'))<button class="btn btn-primary" data-modal="newModal" id="newBudgetBtn"><x-icon name="plus"/> New budget</button>@endif
@endsection
@section('content')
@php
  $at = $active ? \App\Services\BudgetService::totals($active) : null;
  $methodShort = ['blank' => 'Blank', 'last_year' => 'Last year', 'run_rate' => 'Run-rate', 'copy' => 'Copy'];
@endphp
<div class="stats">
  <x-stat :label="'Active budget · '.\App\Services\BudgetService::fyLabel($cur)" :value="$active ? $active->budget_no : 'None'" icon="wallet" :tone="$active ? 'green' : 'amber'" :sub="$active ? $active->name : 'Approve a budget to track variances'" :href="$active ? route('budgets.show', $active) : null"/>
  <x-stat label="Budgeted revenue (year)" :value="$at ? money($at['income']) : '—'" icon="trend" tone="blue" :sub="$at ? 'Expenses '.money($at['expense']) : null"/>
  <x-stat label="Budgeted net profit" :value="$at ? money($at['net']) : '—'" icon="chart" :tone="$at && $at['net'] < 0 ? 'red' : 'purple'" :sub="$at && $at['income'] ? round($at['net'] * 100 / $at['income'], 1).' % margin' : null"/>
  {{-- v8.4 – capital is planned and signed separately from the P&L, so it gets its own card rather than a column nobody reads --}}
  <x-stat label="Active capital plan" :value="$capitalPlan ? $capitalPlan->budget_no : 'None'" icon="wallet"
    :tone="$capitalPlan ? 'green' : 'slate'" :sub="$capitalPlan ? 'Capital budget '.money(\App\Services\BudgetService::totals($capitalPlan)['capital']) : 'No approved capital plan – capital purchases are not controlled'"
    :href="$capitalPlan ? route('budgets.show', $capitalPlan) : null"/>
  <x-stat label="Awaiting approval" :value="$pending" icon="check" :tone="$pending ? 'amber' : 'slate'" :sub="$forecast ? 'Active forecast '.$forecast->budget_no : 'No active re-forecast'" :href="route('budgets.index', ['status' => 'submitted'])"/>
</div>

@if(! $cfg['live_from'] && can_do('budget.approve'))
<div class="alert alert-info"><x-icon name="alert"/><div>Tip: set the <b>go-live date</b> in Settings if the ledger does not hold the earlier months of the year – months before it are not compared with the budget.</div></div>
@endif

<div class="card flush">
  <div class="card-head">
    <div class="tabs" style="margin:0;border:0">
      <a href="{{ route('budgets.index') }}" class="{{ ! $fy && ! request('status') ? 'active' : '' }}">All years</a>
      @foreach($years as $y => $l)<a href="{{ route('budgets.index', ['fy' => $y]) }}" class="{{ $fy === $y ? 'active' : '' }}">{{ $l }}</a>@endforeach
      <a href="{{ route('budgets.index', ['status' => 'submitted']) }}" class="{{ request('status') === 'submitted' ? 'active' : '' }}">Awaiting approval @if($pending)<span class="badge badge-amber">{{ $pending }}</span>@endif</a>
    </div>
    <span class="muted small">Fiscal year starts in {{ \Illuminate\Support\Carbon::create(2000, $cfg['fy_start'], 1)->format('F') }}@if($cfg['live_from']) · compared from {{ \Illuminate\Support\Carbon::parse($cfg['live_from'])->format('d M Y') }}@endif</span>
  </div>
  <div class="card-body table-wrap"><table class="table" id="budgetsTable">
    <thead><tr><th>No.</th><th>Version</th><th>Cost centre</th><th>Year</th><th>Built from</th><th class="num">Revenue</th><th class="num">Expenses</th><th class="num">Net</th><th class="num">Capital</th><th>Status</th><th>Prepared</th><th>Approved</th><th></th></tr></thead>
    <tbody>
    @forelse($versions as $b)
      @php $t = $totals[$b->id]; @endphp
      <tr data-budget="{{ $b->budget_no }}">
        <td><a href="{{ route('budgets.show', $b) }}" class="bold">{{ $b->budget_no }}</a></td>
        <td>{{ $b->name }} <span class="badge {{ $b->kind === 'forecast' ? 'badge-purple' : ($b->isCapital() ? 'badge-amber' : 'badge-blue') }}">{{ $b->kindLabel() }}</span>@if($b->is_active) <span class="badge badge-green">Active</span>@endif
          @if($b->parent)<div class="tiny muted">from {{ $b->parent->budget_no }}@if($b->actuals_through) · actuals to {{ $b->actuals_through->format('d M Y') }}@endif</div>@endif</td>
        <td>@if($b->department_id)<span class="badge badge-purple" data-dept="{{ $b->department?->name }}">{{ $b->costCentreLabel() }}</span>@else<span class="tiny muted">Whole hotel</span>@endif</td>
        <td>{{ $b->fyLabel() }}</td>
        <td class="small">{{ $methodShort[$b->method] ?? $b->method }}@if($b->growth_income || $b->growth_expense)<div class="tiny muted">{{ sprintf('%+.1f %% / %+.1f %%', $b->growth_income, $b->growth_expense) }}</div>@endif</td>
        {{-- a capital plan has no revenue, expense or profit: those cells say so instead of printing three zeros --}}
        <td class="num">{{ $b->isCapital() ? '—' : money($t['income'], false) }}</td>
        <td class="num">{{ $b->isCapital() ? '—' : money($t['expense'], false) }}</td>
        <td class="num {{ ! $b->isCapital() && $t['net'] < 0 ? 'text-red' : '' }}">{{ $b->isCapital() ? '—' : money($t['net'], false) }}</td>
        <td class="num">{{ $t['capital'] ? money($t['capital'], false) : '—' }}</td>
        <td><span class="badge badge-{{ $b->statusTone() }}">{{ $b->statusLabel() }}</span>@php $ap = $approvals[$b->id] ?? null; @endphp
          @if($ap && $ap['need'] === 2)
            @if($b->status === 'submitted' && $ap['signed'] === 1)<span class="badge badge-amber" data-approval="1-of-2">1 of 2 approved</span>
            @elseif($b->status === 'submitted')<span class="badge badge-slate" data-approval="needs-2">2 approvers needed</span>
            @elseif($ap['signed'] === 2)<span class="badge badge-green" data-approval="2-of-2">2 of 2 approved</span>@endif
          @endif
          @if($b->isDraft() && $b->return_note)<div class="tiny text-red" title="{{ $b->return_note }}">returned</div>@endif</td>
        <td class="small">{{ $b->creator?->name ?? '—' }}<div class="tiny muted">{{ $b->created_at?->format('d M') }}</div></td>
        <td class="small">@if($b->approver){{ $b->approver->name }}<div class="tiny muted">{{ $b->approved_at?->format('d M') }}</div>@endif
          @if($b->secondApprover)<div class="tiny muted">2nd: {{ $b->secondApprover->name }} · {{ $b->second_approved_at?->format('d M') }}</div>@endif
          @if(! $b->approver) — @endif</td>
        <td><a class="btn btn-sm" href="{{ route('budgets.show', $b) }}"><x-icon name="eye" size="sm"/> Open</a></td>
      </tr>
    @empty
      <tr><td colspan="13"><x-empty icon="wallet" text="No budget versions yet. Create the budget for the year – start blank, from last year's actuals, from the current run-rate or by copying a version."/></td></tr>
    @endforelse
    </tbody>
  </table></div>
</div>

<div class="card"><div class="card-body small muted">
  <b>How it works.</b> A version holds twelve monthly amounts for every income and expense account plus rooms statistics (occupancy × ADR drives room revenue).
  The preparer submits it; a different user approves it (four-eyes) and the approved version becomes the <b>active</b> one used by Budget vs Actual, the dashboard and spend control on supplier bills.
  @if($cfg['dual_approval_above'] > 0)A version of <b>{{ money($cfg['dual_approval_above']) }}</b> or more – income or expenditure, whichever is larger – needs <b>two</b> approvers, one after the other, from two different people; it stays <b>awaiting approval</b> until both have signed.@else One approver is enough at every value; set a threshold in settings to require a second signature.{{-- 0 means off, and the figure printed is GH₵0.00 rather than a blank --}}@endif
  During the year create a <b>re-forecast</b> from the approved budget: closed months take the actuals from the general ledger, the rest can be re-estimated.
  A version can belong to one <b>cost centre</b> (a department) as well as to the whole hotel; with a departmental version in place it is the department's own figure that caps its requisitions,
  and <a href="{{ route('budgets.costcentres') }}">Cost centres</a> shows every department's plan against what the ledger charged to it.
  <br><b>Capital is not an expense.</b> A <b>capital plan</b> is a version of the same year that holds <b>capital asset accounts</b> – {{ $capexPrefixes ? 'the asset heads coded '.$capexPrefixes : 'none, until a capital code prefix is set' }} in settings –
  and nothing else: buying a generator puts an asset on the balance sheet, it does not reduce the year's profit, so no capital figure is added to revenue, expense or net.
  A supplier bill coded to one of those heads is measured against the active capital plan ({{ $cfg['capex_control'] === 'off' ? 'control is off' : $cfg['capex_control'] }}), and the P&L grid refuses a capital account while the capital plan refuses an income or expense one,
  so neither can be used to shelter the other.
</div></div>

@if(can_do('budget.manage'))
<div class="modal" id="newModal"><div class="modal-box" style="max-width:640px"><form method="post" action="{{ route('budgets.store') }}" id="newBudgetForm">@csrf
  <div class="modal-head"><h3>New budget</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="department_id" label="Cost centre" type="select" :options="$departments" placeholder="— whole hotel —" hint="a version for one department holds money only – rooms statistics stay on the whole-hotel budget" class="span-all"/>
    <x-field name="kind" label="What is being planned" type="select" :options="['budget' => 'The year\'s profit and loss', 'capital' => 'Capital expenditure (assets)']" value="budget" :placeholder="false"
      hint="a capital plan holds capital asset accounts only and never enters revenue, expense or net profit; it is what supplier bills coded to those heads are measured against"/>
    <x-field name="fiscal_year" label="Fiscal year" type="select" :options="$years" :value="$cur + (int) (bdate()->month >= 10)" :placeholder="false" required/>
    <x-field name="name" label="Name" placeholder="e.g. Budget FY 2027-28" maxlength="80"/>
    <x-field name="method" label="Start from" type="select" :options="\App\Models\Budget::METHODS" value="last_year" :placeholder="false" class="span-all"/>
    <x-field name="source_id" label="Version to copy (for “Copy another version”)" type="select" :options="$copyable->mapWithKeys(fn ($b) => [$b->id => $b->budget_no.' – '.$b->name.' ('.$b->fyLabel().')'])->all()" placeholder="— choose —" class="span-all"/>
    <x-field name="growth_income" label="Revenue growth %" type="number" step="0.1" value="0" hint="applied to the starting figures"/>
    <x-field name="growth_expense" label="Expense growth %" type="number" step="0.1" value="0"/>
    <x-field name="notes" label="Assumptions / notes" type="textarea" rows="2" class="span-all"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="createBudgetBtn"><x-icon name="plus"/> Create draft</button></div>
</form></div></div>
@endif

@if(can_do('budget.approve'))
<div class="modal" id="settingsModal"><div class="modal-box" style="max-width:640px"><form method="post" action="{{ route('budgets.settings') }}" id="settingsForm">@csrf
  <div class="modal-head"><h3>Budget settings</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="fy_start" label="Fiscal year starts in" type="select" :options="collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Illuminate\Support\Carbon::create(2000, $m, 1)->format('F')])->all()" :value="$cfg['fy_start']" :placeholder="false"/>
    <x-field name="live_from" label="Compare actuals from (go-live)" type="date" :value="$cfg['live_from']" hint="earlier months are ignored in YTD"/>
    {{-- The brief says 0–100 % and the controller used to allow 0–1000: three answers to one question.
         A threshold above 100 % means no variance is ever flagged. --}}
    <x-field name="var_pct" label="Comment on variances ≥ %" type="number" step="0.1" min="0" max="100" :value="$cfg['var_pct']" hint="0 to 100"/>
    <x-field name="var_amount" label="… and ≥ amount (GH₵)" type="number" step="1" min="0" :value="$cfg['var_amount']" hint="0 and up – no ceiling"/>
    <x-field name="control" label="Spend control on supplier expense bills" type="select" :options="['off' => 'Off', 'warn' => 'Warn when over budget', 'block' => 'Block – needs budget override + reason']" :value="$cfg['control']" :placeholder="false"/>
    <x-field name="control_basis" label="Compare spend with" type="select" :options="['month' => 'The month\'s budget', 'ytd' => 'Year-to-date budget', 'annual' => 'The full-year budget']" :value="$cfg['control_basis']" :placeholder="false"/>
    <x-field name="explain_due_days" label="Explanations due (days after month end)" type="number" step="1" min="0" max="90" :value="$cfg['explain_due_days']" class="span-all" hint="0 to 90 – a flagged variance is explained within this many days of the month end; the variance review prints the deadline"/>
    <x-field name="dual_approval_above" label="Two approvers needed from (GH₵)" type="number" step="1" min="0" :value="$cfg['dual_approval_above']" hint="0 and up – at or above this value a version needs two approvers, one after the other, from two different people; 0 means one approver is enough"/>
    <x-field name="capex_control" label="Capital purchases against the capital plan" type="select" :options="['off' => 'Off', 'warn' => 'Warn when over the plan', 'block' => 'Block – needs budget override + reason']" :value="$cfg['capex_control']" :placeholder="false" hint="a supplier bill coded to a capital asset head is measured against the active capital plan, not the P&L budget"/>
    <x-field name="capex_prefixes" label="Capital asset codes start with" :value="$capexPrefixes" maxlength="60" hint="comma separated, e.g. 15,16 – asset accounts in the chart, never income or expense heads"/>
    <x-field name="cost_centre_required" label="Cost centre on a manual journal" type="select" :options="['off' => 'Optional', 'on' => 'Required on every expense line']" :value="$cfg['cost_centre_required'] ? 'on' : 'off'" :placeholder="false" class="span-all" hint="off or on – when on, the journal screen and the server both refuse an expense line with no cost centre"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveSettingsBtn"><x-icon name="check"/> Save</button></div>
</form></div></div>
@endif
@endsection
