@extends('layouts.app')
@section('title', $b->budget_no.' · '.$b->name)
@section('actions')
<a class="btn" href="{{ route('budgets.index') }}"><x-icon name="chev"/> All versions</a>
<a class="btn" href="{{ route('budgets.export', $b) }}" id="exportBtn"><x-icon name="download"/> CSV</a>
@if($b->status === 'approved' && $b->kind === 'budget')<a class="btn" href="{{ route('budgets.bva', ['budget' => $b->id]) }}"><x-icon name="chart"/> Budget vs Actual</a>@endif
@if($canEdit)
  <button class="btn" data-modal="importModal" id="importBtn"><x-icon name="layers"/> Import</button>
  <button class="btn" data-modal="adjustModal" id="adjustBtn" data-f-scope="all"><x-icon name="trend"/> Adjust %</button>
  <form method="post" action="{{ route('budgets.submit', $b) }}" style="display:inline" data-confirm="Submit {{ $b->budget_no }} for approval? It can't be edited while it is waiting.">@csrf<button class="btn btn-primary" id="submitBtn"><x-icon name="check"/> Submit for approval</button></form>
@endif
@if($b->status === 'submitted' && can_do('budget.approve'))
  <button class="btn btn-warning" data-modal="returnModal" id="returnBtn"><x-icon name="swap"/> Return</button>
  @if($approval['mine'])
    {{-- v8.3 – the second signature must come from someone else, so the screen offers none: the server refuses it too.
         This holds even if the threshold has been lowered since, because the signature on the version is what counts. --}}
    <button class="btn btn-success" id="approveBtn" disabled title="You have already signed this version – someone else must give the other signature"><x-icon name="check"/> {{ $approval['needed'] === 2 ? 'Awaiting second approver' : 'Awaiting another approver' }}</button>
  @else
    <button class="btn btn-success" data-modal="approveModal" id="approveBtn"><x-icon name="check"/> {{ $approval['needed'] === 2 && $approval['signed'] === 1 ? 'Second approval' : 'Approve' }}</button>
  @endif
@endif
@if($b->status === 'approved')
  @if($b->kind === 'budget' && can_do('budget.manage') && count($closedMonths))<button class="btn" data-modal="forecastModal" id="forecastBtn"><x-icon name="refresh"/> Re-forecast</button>@endif
  @if(can_do('budget.approve'))
    @unless($b->is_active)<form method="post" action="{{ route('budgets.activate', $b) }}" style="display:inline">@csrf<button class="btn btn-primary" id="activateBtn"><x-icon name="star"/> Make active</button></form>@endunless
    <button class="btn btn-ghost" data-modal="reopenModal" id="reopenBtn"><x-icon name="edit"/> Re-open</button>
  @endif
@endif
@endsection
@section('content')
@php
  // v8.3 – the column totals and the two derived rows come from BudgetService::gridTotals(), computed once and
  // checkable, not added up in the template.
  $secLabels = \App\Services\BudgetService::SECTIONS;
  $n = fn ($v) => money($v, false);
  $raw = fn ($v) => (float) $v == round((float) $v) ? (string) (int) round((float) $v) : number_format((float) $v, 2, '.', '');
@endphp

@if($b->isDraft() && $b->return_note)
<div class="alert alert-warn" id="returnNote"><x-icon name="alert"/><div><b>Returned for changes:</b> {{ $b->return_note }}</div></div>
@endif
@if($b->status === 'submitted')
<div class="alert alert-info" id="approvalNote"><x-icon name="clock"/><div>Submitted by <b>{{ $b->submitter?->name }}</b> {{ $b->submitted_at?->diffForHumans() }} –
  @if($approval['needed'] === 2 && $approval['first'])
    <b>{{ $approval['first']->name }}</b> gave the first approval on {{ $approval['first_at']?->format('d M Y H:i') }}. This version is {{ money($approval['size']) }}, over the
    {{ money($approval['threshold']) }} threshold, so <b>a second approver</b> must sign before it counts – four-eyes applies to both signatures.
  @elseif($approval['needed'] === 2)
    waiting for approval by another user (four-eyes). It is {{ money($approval['size']) }}, over the {{ money($approval['threshold']) }} threshold, so <b>two</b> approvers must sign, one after the other.
  @else
    waiting for approval by another user (four-eyes). At {{ money($approval['size']) }} it is below the {{ $approval['threshold'] > 0 ? money($approval['threshold']) : money(0) }} threshold, so one approval is enough.
  @endif</div></div>
@endif
@if($b->department_id)
<div class="alert alert-info" id="ccNote"><x-icon name="users"/><div>This version plans the cost centre <b>{{ $b->costCentreLabel() }}</b>. Rooms statistics stay on the whole-hotel budget, so they are not shown here – the ledger actuals compared with it are only the lines tagged to this department.</div></div>
@endif
@if($b->isCapital())
<div class="alert alert-info" id="capexNote"><x-icon name="wallet"/><div>This is the <b>capital plan</b> for {{ $b->fyLabel() }}: money committed to <b>assets</b>, not to the profit and loss.
  A capital purchase buys something the hotel keeps, so it is not an expense of the year and no figure here enters revenue, expenses or net profit.
  A supplier bill coded to one of these capital asset heads is measured against this plan; an operating cost can never be coded here.</div></div>
@endif
@if($b->kind === 'forecast' && count($locked))
<div class="alert alert-info"><x-icon name="lock"/><div>Months up to <b>{{ $b->actuals_through->format('M Y') }}</b> hold posted actuals from the general ledger and are locked. Re-estimate the remaining months.</div></div>
@endif

<div class="stats">
  @if($b->isCapital())
  <x-stat label="Capital budget" :value="money($totals['capital'])" icon="wallet" tone="blue" :sub="$b->fyLabel().' · '.$b->kindLabel()"/>
  <x-stat label="Spent to date" :value="money($capital['actual'])" icon="trend" :tone="$capital['plan'] > 0 && $capital['actual'] > $capital['plan'] ? 'red' : 'slate'"
    :sub="$capital['pct'] === null ? 'no plan to measure' : $capital['pct'].' % of the plan for the year'"/>
  <x-stat label="Committed" :value="money($capital['committed'])" icon="clock" :tone="$capital['committed'] > 0 ? 'amber' : 'slate'"
    :sub="$capital['committed'] > 0 ? 'approved projects, not yet spent'.($funding['short'] > 0 ? ' · '.money($funding['short']).' of it has no cover' : '') : 'no approved project promises money'"/>
  <x-stat label="Remaining" :value="money(abs($capital['remaining']))" icon="chart" :tone="$capital['remaining'] < 0 ? 'red' : 'green'"
    :sub="$capital['remaining'] < 0 ? 'over the plan once commitments are counted' : 'still to commit'"/>
  @else
  <x-stat label="Revenue" :value="money($totals['income'])" icon="trend" tone="blue" :sub="$b->fyLabel().' · '.$b->kindLabel()"/>
  <x-stat label="Expenses" :value="money($totals['expense'])" icon="wallet" tone="slate"/>
  <x-stat label="Net profit" :value="money($totals['net'])" icon="chart" :tone="$totals['net'] < 0 ? 'red' : 'green'" :sub="$totals['income'] ? round($totals['net'] * 100 / $totals['income'], 1).' % margin' : null"/>
  @endif
  <x-stat label="Status" :value="$b->statusLabel()" icon="check" :tone="$b->statusTone() === 'slate' ? 'slate' : ($b->statusTone() === 'amber' ? 'amber' : 'green')" {{-- v8.3 – a version with one of two signatures is not "approved by" anyone yet --}}
  :sub="$b->is_active ? 'Active version' : ($b->status === 'submitted' && $b->approver ? 'first approval by '.$b->approver->name.($approval['needed'] === 2 ? ' · second needed' : '') : ($b->status === 'approved' && $b->approver ? 'approved by '.$b->approver->name : 'prepared by '.($b->creator?->name ?? '—')))"/>
</div>

<div data-tabwrap>
<div class="tabs" data-tabs>
  <button class="active" data-tab="grid">{{ $b->isCapital() ? 'Capital by month' : 'Monthly budget' }}</button>
  @unless($b->department_id || $b->isCapital())<button data-tab="stats">Rooms statistics</button>@endunless
  <button data-tab="history">History ({{ $b->events->count() }})</button>
</div>

<div class="tab-pane active" id="grid">
@if($b->isCapital())
<div class="card" id="capitalSpendCard"><div class="card-head"><h3>Plan against capital spend</h3>
  <span class="muted small">spent = capital asset accounts debited this fiscal year ({{ $capital['through'] ? 'through fiscal month '.$capital['through'].' of 12' : 'no closed month yet' }}); committed = approved capital projects not yet spent</span></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="capitalTable">
    <thead><tr><th>Code</th><th>Capital asset account</th><th class="num">Plan for the year</th><th class="num">Plan to date</th><th class="num">Spent to date</th><th class="num">Committed</th><th class="num">Remaining</th><th class="num">% of plan</th></tr></thead>
    <tbody>
    @forelse($capital['rows'] as $r)
      <tr data-acct="{{ $r['account']->code }}">
        <td class="muted small">{{ $r['account']->code }}</td>
        <td>{{ $r['account']->name }}</td>
        <td class="num">{{ money($r['plan'], false) }}</td>
        <td class="num muted">{{ money($r['to_date'], false) }}</td>
        <td class="num">{{ money($r['actual'], false) }}</td>
        <td class="num">{{ $r['committed'] ? money($r['committed'], false) : '—' }}
          @if(! empty($r['projects']))<div class="tiny muted">{{ implode(', ', array_column($r['projects'], 'number')) }}</div>@endif</td>
        <td class="num {{ $r['remaining'] < 0 ? 'text-red' : '' }}">{{ money(abs($r['remaining']), false) }} {{ $r['remaining'] < 0 ? 'over' : 'left' }}</td>
        <td class="num">{{ $r['pct'] === null ? '—' : $r['pct'].' %' }}</td>
      </tr>
    @empty
      <tr><td colspan="8"><x-empty icon="wallet" text="No capital asset account carries a plan, any spend or an approved project this year."/></td></tr>
    @endforelse
    </tbody>
    <tfoot><tr class="bold-row"><td></td><td>Total capital</td>
      <td class="num">{{ money($capital['plan'], false) }}</td><td class="num">{{ money($capital['to_date'], false) }}</td>
      <td class="num">{{ money($capital['actual'], false) }}</td>
      <td class="num">{{ money($capital['committed'], false) }}</td>
      <td class="num {{ $capital['remaining'] < 0 ? 'text-red' : '' }}">{{ money(abs($capital['remaining']), false) }} {{ $capital['remaining'] < 0 ? 'over' : 'left' }}</td>
      <td class="num">{{ $capital['pct'] === null ? '—' : $capital['pct'].' %' }}</td></tr></tfoot>
  </table></div>
  @php $allProjects = collect($capital['rows'])->flatMap(fn ($r) => $r['projects'])->values(); @endphp
  @if($allProjects->count())
  <div class="card-body" style="border-top:1px solid var(--c-border)">
    <h4 style="margin-bottom:.4rem">Approved capital projects drawing on this plan</h4>
    <div class="table-wrap"><table class="table table-compact" id="capitalProjects">
      <thead><tr><th>Project</th><th>What it buys</th><th class="num">Approved budget</th><th class="num">Spent</th><th class="num">Still to spend</th></tr></thead>
      <tbody>
      @foreach($allProjects as $proj)
        <tr data-project="{{ $proj['number'] }}"><td class="bold">{{ $proj['number'] }}</td><td>{{ $proj['title'] }}</td>
          <td class="num">{{ money($proj['budget'], false) }}</td><td class="num">{{ money($proj['spent'], false) }}</td>
          <td class="num">{{ money($proj['open'], false) }}</td></tr>
      @endforeach
      </tbody>
    </table></div>
    <p class="tiny muted">A project counts here because it is approved and capitalised to one of these accounts; the money it has
      already spent is in the ledger and so is counted once, as spent. Completing or cancelling a project releases what is left.</p>
  </div>
  @endif
  <div class="card-body" id="capitalDue" style="border-top:1px solid var(--c-border)">
    <h4 style="margin-bottom:.15rem">When it falls due</h4>
    <p class="tiny muted" style="margin-bottom:.5rem">The money those projects have promised, in the fiscal month each one
      says it is needed. A project sits in one month only — its date — so moving the date moves the money; nothing is
      spread across the year on a guess.</p>
    <div style="display:flex;gap:5px;align-items:stretch" id="capitalDueStrip">
      @foreach($timeline['months'] as $tm)
        <div class="due-cell{{ $tm['late'] ? ' late' : '' }}{{ $tm['past'] ? ' past' : '' }}" data-month="{{ $tm['label'] }}"
             data-open="{{ $tm['open'] }}" title="{{ $tm['open'] > 0 ? implode(', ', array_column($tm['projects'], 'number')) : 'nothing falls due' }}"
             style="flex:1;min-width:0;text-align:center;padding:4px 2px;border:1px solid var(--c-border);border-radius:6px;{{ $tm['past'] ? 'background:var(--c-bg)' : '' }}">
          <div class="tiny muted" style="white-space:nowrap">{{ $tm['label'] }}</div>
          <div style="height:44px;display:flex;align-items:flex-end;justify-content:center">
            <div class="due-bar" style="width:64%;height:{{ $tm['height'] }}%;border-radius:3px 3px 0 0;background:{{ $tm['late'] ? 'var(--c-red)' : 'var(--c-blue)' }}"></div>
          </div>
          <div class="tiny {{ $tm['open'] > 0 ? 'bold' : 'muted' }}">{{ $tm['open'] > 0 ? money($tm['open'], false) : '—' }}</div>
        </div>
      @endforeach
    </div>
    @if($timeline['overdue']['open'] > 0)
      <p class="small" id="capitalOverdue" style="margin:.55rem 0 0"><x-icon name="alert"/>
        <b>{{ money($timeline['overdue']['open']) }}</b> is still promised by {{ implode(', ', array_column($timeline['overdue']['projects'], 'number')) }}
        and its date has passed — a commitment the year's cash plan has already got wrong. Spend it, move the date, or complete the project.</p>
    @endif
    @if($timeline['undated']['open'] > 0)
      <p class="small" id="capitalUndated" style="margin:.55rem 0 0"><x-icon name="clock"/>
        <b>{{ money($timeline['undated']['open']) }}</b> has no date yet ({{ implode(', ', array_column($timeline['undated']['projects'], 'number')) }}).
        It is counted in the plan but in no month, so the strip understates every month it may land in.</p>
    @else
      <p class="tiny muted" id="capitalDated" style="margin:.55rem 0 0">Every approved project carries a date, so the strip accounts for
        all {{ money($timeline['total']) }} of the money promised.</p>
    @endif
  </div>
</div>

<div class="card" id="capitalFunding"><div class="card-head"><h3>Can it be paid?</h3>
  <span class="muted small">each open commitment has one funding source; only the FF&amp;E reserve is a balance this hotel holds</span></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="fundingTable">
    <thead><tr><th>Funding source</th><th class="num">Promised</th><th>Available now</th><th class="num">Short</th><th>Projects</th></tr></thead>
    <tbody>
    @foreach($funding['rows'] as $fr)
      <tr data-source="{{ $fr['key'] }}" class="{{ ($fr['short'] ?? 0) > 0 ? 'text-red' : '' }}">
        <td class="bold">{{ $fr['label'] }}</td>
        <td class="num">{{ $fr['open'] > 0 ? money($fr['open'], false) : '—' }}</td>
        <td>@if($fr['cover'] !== null){{ money($fr['cover']) }} accrued @if($fr['cash'] !== null)<span class="tiny muted">· {{ money($fr['cash']) }} in cash</span>@endif @else<span class="small muted">{{ $fr['note'] }}</span>@endif</td>
        <td class="num">{{ $fr['short'] === null ? '—' : money($fr['short'], false) }}</td>
        <td class="small">{{ empty($fr['projects']) ? '—' : implode(', ', array_column($fr['projects'], 'number')) }}</td>
      </tr>
    @endforeach
    </tbody>
    <tfoot><tr class="bold-row"><td>Total promised</td><td class="num">{{ money($funding['open'], false) }}</td>
      <td class="small">{{ $funding['untracked'] > 0 ? money($funding['untracked']).' comes from a source with no balance to read' : 'all of it comes from a balance the hotel holds' }}</td>
      <td class="num {{ $funding['short'] > 0 ? 'text-red' : '' }}">{{ money($funding['short'], false) }}</td><td></td></tr></tfoot>
  </table></div>
  <div class="card-body" style="border-top:1px solid var(--c-border)">
    @if($funding['short'] > 0)
      <p class="small" id="fundingShort" style="margin:0"><x-icon name="alert"/>
        <b>{{ money($funding['short']) }}</b> of the money promised from the FF&amp;E reserve is more than the reserve holds.
        @if($funding['cash'] !== null)It holds <b>{{ money($funding['cash']) }}</b> in cash, so even the cash does not cover what the
        reserve has already promised. @endif
        Fund the reserve, move the project to a later year, or ask the owner for a capital call.</p>
    @elseif($funding['accrued'] !== null)
      <p class="small" id="fundingCovered" style="margin:0"><x-icon name="check"/>The reserve holds
        {{ money($funding['accrued']) }} accrued @if($funding['cash'] !== null) and {{ money($funding['cash']) }} in cash @endif, which covers
        everything promised from it.</p>
    @endif
    @if($funding['untracked'] > 0)
      <p class="small" id="fundingUntracked" style="margin:{{ $funding['short'] > 0 || $funding['accrued'] !== null ? '.45rem' : '0' }} 0 0"><x-icon name="info"/>
        <b>{{ money($funding['untracked']) }}</b> is promised from the owner or from operating cash. There is no balance here to read
        against it: the screen says who owes the money, not that it has been found.</p>
    @endif
  </div>
</div>
@endif
<form method="post" action="{{ route('budgets.grid', $b) }}" id="gridForm">@csrf
<div class="card flush">
  <div class="card-head"><h3>{{ $b->name }}</h3>
    <span class="muted small">Amounts in {{ hotel()->currency_symbol }} · {{ $b->isCapital() ? 'money committed to assets, positive; it never enters the profit and loss' : 'income positive, expenses positive' }} @if($canEdit) · Tab across the months; use ⋯ on a row to spread an annual total @endif</span>
    @if($canEdit)<button class="btn btn-primary btn-sm ml-auto" id="saveGridBtn"><x-icon name="check" size="sm"/> Save changes</button>@endif
  </div>
  <div class="card-body table-wrap"><table class="table table-compact bg-grid" id="budgetGrid">
    <thead><tr><th>Code</th><th style="min-width:180px">Account</th>
      @foreach($labels as $m => $l)<th class="num {{ in_array($m, $locked) ? 'bg-locked' : '' }}">{{ $l }} @if(in_array($m, $locked))<div class="tiny muted">actual</div>@endif</th>@endforeach
      <th class="num">Total</th><th class="num" title="Posted actuals for the previous fiscal year">Last year</th><th class="num" title="Posted actuals this year to date">Actual YTD</th>@if($canEdit)<th></th>@endif</tr></thead>
    <tbody>
    @foreach($secLabels as $sec => $label)
      @continue(! isset($lines[$sec]))
      <tr class="gl-type"><td colspan="{{ 17 + ($canEdit ? 1 : 0) }}">{{ $label }}</td></tr>
      @foreach($lines[$sec] as $l)
        @php $a = $accounts[$l->account_head_id]; $ly = array_sum($lastYear[$a->id] ?? []); $ytd = array_sum($actuals[$a->id] ?? []); @endphp
        <tr data-acct="{{ $a->code }}" data-sec="{{ $sec }}">
          <td class="muted small">{{ $a->code }}</td>
          <td>{{ $a->name }} @if($a->id === $roomAccount) <span class="tiny muted" title="Can be driven by the rooms statistics">⚙ stats</span>@endif
            @if($canEdit)<input class="input input-sm bg-note" name="note[{{ $a->id }}]" value="{{ $l->note }}" placeholder="assumption…" maxlength="190">@elseif($l->note)<div class="tiny muted">{{ $l->note }}</div>@endif</td>
          @foreach(range(1, 12) as $m)
            @if($canEdit && ! in_array($m, $locked))
              <td class="num" data-m="{{ $m }}"><input class="input input-sm bg-cell" name="l[{{ $a->id }}][{{ $m }}]" value="{{ $raw($l->month($m)) }}" inputmode="decimal"></td>
            @else
              <td class="num {{ in_array($m, $locked) ? 'bg-locked' : '' }}" data-m="{{ $m }}" data-v="{{ $l->month($m) }}">{{ $n($l->month($m)) }}</td>
            @endif
          @endforeach
          <td class="num bold row-total">{{ $n($l->total()) }}</td>
          <td class="num muted small">{{ $ly ? $n($ly) : '—' }}</td>
          <td class="num muted small">{{ $ytd ? $n($ytd) : '—' }}</td>
          @if($canEdit)<td><button type="button" class="btn btn-sm btn-ghost" data-modal="spreadModal" data-title="{{ $a->code }} {{ $a->name }}" data-f-account_head_id="{{ $a->id }}" data-f-total="{{ $raw($l->total()) }}" title="Spread / adjust">⋯</button></td>@endif
        </tr>
      @endforeach
      <tr class="bold-row sec-total" data-sectotal="{{ $sec }}"><td></td><td>Total {{ strtolower($label) }}</td>
        @foreach(range(1, 12) as $m)<td class="num" data-m="{{ $m }}">{{ $n($grid['cols'][$sec][$m] ?? 0) }}</td>@endforeach
        <td class="num" data-m="t">{{ $n($grid['cols'][$sec]['t'] ?? 0) }}</td><td colspan="{{ 2 + ($canEdit ? 1 : 0) }}"></td></tr>
      @if($sec === 'opex' && ! $b->isCapital())
        <tr class="bold-row derived" data-derived="gop"><td></td><td>Gross operating profit (GOP)</td>
          @foreach(range(1, 12) as $m)<td class="num" data-m="{{ $m }}">{{ $n($grid['gop'][$m] ?? 0) }}</td>@endforeach
          <td class="num" data-m="t">{{ $n($grid['gop']['t'] ?? 0) }}</td><td colspan="{{ 2 + ($canEdit ? 1 : 0) }}"></td></tr>
      @endif
    @endforeach
    @unless($b->isCapital())
    <tr class="bold-row derived" data-derived="net" style="border-top:2px solid var(--c-border)"><td></td><td>Net profit</td>
      @foreach(range(1, 12) as $m)<td class="num" data-m="{{ $m }}">{{ $n($grid['net'][$m] ?? 0) }}</td>@endforeach
      <td class="num" data-m="t">{{ $n($grid['net']['t'] ?? 0) }}</td><td colspan="{{ 2 + ($canEdit ? 1 : 0) }}"></td></tr>
    @endunless
    </tbody>
  </table></div>
</div>
</form>
</div>

@unless($b->department_id)
<div class="tab-pane" id="stats">
<form method="post" action="{{ route('budgets.stats', $b) }}" id="statsForm">@csrf
<div class="card flush">
  <div class="card-head"><h3>Rooms statistics</h3><span class="muted small">Room revenue = rooms available × occupancy × ADR</span>
    @if($canEdit)<label class="flex ml-auto" style="gap:.35rem;align-items:center"><input type="checkbox" name="apply" value="1" checked id="applyRoomRev"> Recalculate the room revenue line</label>
    <button class="btn btn-primary btn-sm" id="saveStatsBtn"><x-icon name="check" size="sm"/> Save statistics</button>@endif</div>
  <div class="card-body table-wrap"><table class="table table-compact" id="statsTable">
    <thead><tr><th>Month</th><th class="num">Rooms available</th><th class="num">Occupancy %</th><th class="num">ADR</th><th class="num">Room-nights</th><th class="num">Room revenue</th><th class="num">RevPAR</th>
      <th class="num" title="Posted actuals">Actual occ.</th><th class="num">Actual ADR</th><th class="num">Actual RevPAR</th></tr></thead>
    <tbody>
    @php $sumA = $sumS = $sumR = 0; @endphp
    @foreach($labels as $m => $l)
      @php $s = $stats[$m] ?? null; $as = $actualStats[$m]; $edit = $canEdit && ! in_array($m, $locked); if ($s) { $sumA += $s->rooms_available; $sumS += $s->roomsSold(); $sumR += $s->roomRevenue(); } @endphp
      <tr data-month="{{ $m }}">
        <td>{{ $l }} @if(in_array($m, $locked)) <span class="tiny muted">actual</span>@endif</td>
        @if($edit)
          <td class="num"><input class="input input-sm bg-cell" name="s[{{ $m }}][rooms_available]" value="{{ $s?->rooms_available }}" inputmode="numeric"></td>
          <td class="num"><input class="input input-sm bg-cell" name="s[{{ $m }}][occupancy]" value="{{ $s ? $raw($s->occupancy) : '' }}" inputmode="decimal"></td>
          <td class="num"><input class="input input-sm bg-cell" name="s[{{ $m }}][adr]" value="{{ $s ? $raw($s->adr) : '' }}" inputmode="decimal"></td>
        @else
          <td class="num">{{ number_format($s?->rooms_available ?? 0) }}</td><td class="num">{{ number_format($s?->occupancy ?? 0, 1) }}</td><td class="num">{{ $n($s?->adr ?? 0) }}</td>
        @endif
        <td class="num">{{ number_format($s?->roomsSold() ?? 0) }}</td><td class="num">{{ $n($s?->roomRevenue() ?? 0) }}</td><td class="num">{{ $n($s?->revpar() ?? 0) }}</td>
        <td class="num muted">{{ $as['available'] ? number_format($as['occupancy'], 1) : '—' }}</td><td class="num muted">{{ $as['sold'] ? $n($as['adr']) : '—' }}</td><td class="num muted">{{ $as['available'] ? $n($as['revpar']) : '—' }}</td>
      </tr>
    @endforeach
    <tr class="bold-row"><td>Year</td><td class="num">{{ number_format($sumA) }}</td><td class="num">{{ $sumA ? number_format($sumS * 100 / $sumA, 1) : '0.0' }}</td><td class="num">{{ $sumS ? $n($sumR / $sumS) : '—' }}</td>
      <td class="num">{{ number_format($sumS) }}</td><td class="num">{{ $n($sumR) }}</td><td class="num">{{ $sumA ? $n($sumR / $sumA) : '—' }}</td><td colspan="3"></td></tr>
    </tbody>
  </table></div>
</div>
</form>
</div>
@endunless

<div class="tab-pane" id="history">
<div class="grid g-2">
<div class="card"><div class="card-head"><h3>Audit trail</h3></div><div class="card-body">
  <ul class="timeline" id="budgetEvents">
  @foreach($b->events->sortByDesc('id') as $e)
    <li><b>{{ \App\Models\BudgetEvent::label($e->action) }}</b> · {{ $e->user?->name ?? 'system' }} <span class="muted small">{{ $e->created_at?->format('d M Y H:i') }}</span>@if($e->note)<div class="small muted">{{ $e->note }}</div>@endif</li>
  @endforeach
  </ul>
</div></div>
<div class="card"><div class="card-head"><h3>Details</h3></div><div class="card-body">
  <div class="kv-grid small">
    <div class="kv"><span>Version</span><b>{{ $b->budget_no }} · {{ $b->kindLabel() }}</b></div>
    <div class="kv"><span>Fiscal year</span><b>{{ $b->fyLabel() }}</b></div>
    <div class="kv"><span>Built from</span><b>{{ \App\Models\Budget::METHODS[$b->method] ?? $b->method }} @if($b->parent) – {{ $b->parent->budget_no }} @endif</b></div>
    <div class="kv"><span>Growth</span><b>{{ sprintf('revenue %+.1f %% · expenses %+.1f %%', $b->growth_income, $b->growth_expense) }}</b></div>
    <div class="kv"><span>Prepared by</span><b>{{ $b->creator?->name ?? '—' }}</b></div>
    <div class="kv"><span>Approved by</span><b>{{ $b->approver?->name ?? '—' }} @if($b->approved_at) · {{ $b->approved_at->format('d M Y') }} @endif @if($approval['needed'] === 2)<span class="tiny muted">(first)</span>@endif</b></div>
    @if($approval['needed'] === 2 || $b->second_approved_by)
    <div class="kv"><span>Second approval</span><b>{{ $b->secondApprover?->name ?? ($b->status === 'submitted' ? 'awaiting the second approver' : '—') }} @if($b->second_approved_at) · {{ $b->second_approved_at->format('d M Y') }} @endif</b></div>
    @endif
    @if($approval['threshold'] > 0)
    <div class="kv"><span>Approval threshold</span><b>{{ money($approval['threshold']) }} – {{ $approval['size'] >= $approval['threshold'] ? 'two approvers' : 'one approver' }}</b></div>
    @endif
  </div>
  @if($b->notes)<p class="small mt-2"><b>Assumptions:</b> {{ $b->notes }}</p>@endif
  @if($forecasts->count())<h4 class="mt-2">Re-forecasts</h4><ul class="small">@foreach($forecasts as $f)<li><a href="{{ route('budgets.show', $f) }}">{{ $f->budget_no }} – {{ $f->name }}</a> <span class="badge badge-{{ $f->statusTone() }}">{{ $f->statusLabel() }}</span>@if($f->is_active) <span class="badge badge-green">Active</span>@endif</li>@endforeach</ul>@endif
  @if($canEdit)<form method="post" action="{{ route('budgets.destroy', $b) }}" class="mt-2" data-confirm="Delete draft {{ $b->budget_no }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" id="deleteBtn"><x-icon name="trash" size="sm"/> Delete draft</button></form>@endif
</div></div>
</div>
</div>
</div>

@if($canEdit)
<div class="modal" id="spreadModal"><div class="modal-box" style="max-width:520px">
  <div class="modal-head"><h3 data-title>Spread</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <form method="post" action="{{ route('budgets.spread', $b) }}" id="spreadForm">@csrf<input type="hidden" name="account_head_id">
  <div class="modal-body"><div class="form-grid">
    <x-field name="total" :label="'Annual total '.hotel()->currency_symbol" type="number" step="1" required/>
    <x-field name="how" label="Spread" type="select" :options="['even' => 'Evenly', 'days' => 'By days in month', 'seasonal' => 'Seasonal (budgeted room-nights)']" value="even" :placeholder="false"/>
    <p class="small muted span-all">@if(count($locked))Locked actual months are kept – the rest of the total is spread over the open months. @endif Save or lose unsaved grid edits first.</p>
  </div></div>
  <div class="modal-foot"><button class="btn btn-primary" id="spreadBtn">Spread</button></div></form>
  <form method="post" action="{{ route('budgets.adjust', $b) }}" id="rowAdjustForm" style="border-top:1px solid var(--c-border)">@csrf<input type="hidden" name="scope" value="account"><input type="hidden" name="account_head_id">
  <div class="modal-body flex" style="gap:.6rem;align-items:flex-end"><x-field name="pct" label="…or change this line by %" type="number" step="0.1" placeholder="e.g. 5 or -3"/><button class="btn" id="rowAdjustBtn">Apply</button></div></form>
</div></div>

<div class="modal" id="adjustModal"><div class="modal-box" style="max-width:480px"><form method="post" action="{{ route('budgets.adjust', $b) }}" id="adjustForm">@csrf
  <div class="modal-head"><h3>Adjust by percentage</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="scope" label="Apply to" type="select" :options="['all' => 'Every line', 'income' => 'All revenue lines', 'expense' => 'All expense lines']" value="all" :placeholder="false"/>
    <x-field name="pct" label="Change %" type="number" step="0.1" required placeholder="e.g. 5 or -2.5"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="applyAdjustBtn">Apply</button></div>
</form></div></div>

<div class="modal" id="importModal"><div class="modal-box" style="max-width:560px"><form method="post" action="{{ route('budgets.import', $b) }}" enctype="multipart/form-data" id="importForm">@csrf
  <div class="modal-head"><h3>Import from a spreadsheet</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small muted">CSV with the <b>account code</b> in the first column, optionally the account name, then <b>twelve monthly amounts</b> ({{ reset($labels) }} … {{ end($labels) }}). Header and total rows are ignored. Tip: download the CSV of this version, edit it in Excel and save as CSV. If any row is wrong nothing is imported.</p>
    <input type="file" name="file" accept=".csv,text/csv" required class="input">
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="importSubmit">Import</button></div>
</form></div></div>
@endif

@if($b->status === 'submitted' && can_do('budget.approve'))
<div class="modal" id="approveModal"><div class="modal-box" style="max-width:480px"><form method="post" action="{{ route('budgets.approve', $b) }}" id="approveForm">@csrf
  <div class="modal-head"><h3>Approve {{ $b->budget_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    @if($approval['needed'] === 2)
    <div class="alert {{ $approval['signed'] === 1 ? 'alert-success' : 'alert-warn' }} span-all" id="twoApproverNote" style="margin:0"><x-icon name="users"/><div>
      This version is <b>{{ money($approval['size']) }}</b>, at or above the <b>{{ money($approval['threshold']) }}</b> threshold, so it needs <b>two approvers</b>.
      @if($approval['signed'] === 1) Your approval is the <b>second and final</b> one – it goes live straight away. @else Your approval is the <b>first</b>; a second approver must then sign before it counts. @endif
    </div></div>
    @endif
    <x-field name="note" label="Note (optional)" class="span-all"/>
    <input type="hidden" name="activate" value="0">
    <label class="flex span-all" style="gap:.4rem;align-items:center"><input type="checkbox" name="activate" value="1" checked> Make it the active {{ strtolower($b->kindLabel()) }} for {{ $b->fyLabel() }}</label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-success" id="confirmApprove">{{ $approval['needed'] === 2 && $approval['signed'] === 1 ? 'Give the second approval' : 'Approve' }}</button></div>
</form></div></div>
<div class="modal" id="returnModal"><div class="modal-box" style="max-width:480px"><form method="post" action="{{ route('budgets.return', $b) }}" id="returnForm">@csrf
  <div class="modal-head"><h3>Return for changes</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="note" label="What needs to change?" type="textarea" rows="3" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-warning" id="confirmReturn">Return</button></div>
</form></div></div>
@endif

@if($b->status === 'approved' && can_do('budget.approve'))
<div class="modal" id="reopenModal"><div class="modal-box" style="max-width:480px"><form method="post" action="{{ route('budgets.reopen', $b) }}" id="reopenForm">@csrf
  <div class="modal-head"><h3>Re-open {{ $b->budget_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small muted">The version becomes a draft and stops being the active one until it is approved again.</p><x-field name="reason" label="Reason" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-warning" id="confirmReopen">Re-open</button></div>
</form></div></div>
@endif

@if($b->status === 'approved' && $b->kind === 'budget' && can_do('budget.manage') && count($closedMonths))
<div class="modal" id="forecastModal"><div class="modal-box" style="max-width:480px"><form method="post" action="{{ route('budgets.forecast', $b) }}" id="forecastForm">@csrf
  <div class="modal-head"><h3>Re-forecast {{ $b->fyLabel() }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="through" label="Actuals up to the end of" type="select" :options="$closedMonths" :value="array_key_last($closedMonths)" :placeholder="false"/>
    <x-field name="name" label="Name" placeholder="Re-forecast after …" maxlength="80"/>
    <p class="small muted span-all">Closed months take the posted actuals (months before go-live keep the budget); later months start from this budget.</p>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="createForecast">Create re-forecast</button></div>
</form></div></div>
@endif

@if($canEdit)
<script>
(function () {
  const grid = document.getElementById('budgetGrid'); if (!grid) return;
  const num = v => { v = String(v).replace(/[,₹₵GHS¢\s]/g, ''); return v === '' || isNaN(v) ? 0 : parseFloat(v); };
  const fmt = v => (Math.round(v * 100) / 100).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const cellVal = td => { const i = td.querySelector('input'); return i ? num(i.value) : num(td.dataset.v || 0); };
  function recalc() {
    const sec = {};
    grid.querySelectorAll('tr[data-acct]').forEach(tr => {
      let t = 0; const s = tr.dataset.sec; sec[s] = sec[s] || Array(13).fill(0);
      tr.querySelectorAll('td[data-m]').forEach(td => {
        const m = +td.dataset.m; const v = cellVal(td); t += v; sec[s][m] += v;
      });
      tr.querySelector('.row-total').textContent = fmt(t);
    });
    const put = (tr, arr) => { if (!tr) return; let t = 0; for (let m = 1; m <= 12; m++) { t += arr[m]; tr.querySelector('td[data-m="' + m + '"]').textContent = fmt(arr[m]); } tr.querySelector('td[data-m="t"]').textContent = fmt(t); };
    const z = () => Array(13).fill(0), g = k => sec[k] || z();
    Object.keys(sec).forEach(k => put(grid.querySelector('tr[data-sectotal="' + k + '"]'), sec[k]));
    const gop = z(), net = z();
    for (let m = 1; m <= 12; m++) { gop[m] = g('revenue')[m] - g('direct')[m] - g('opex')[m]; net[m] = gop[m] + g('other_income')[m] - g('nonop')[m]; }
    put(grid.querySelector('tr[data-derived="gop"]'), gop); put(grid.querySelector('tr[data-derived="net"]'), net);
  }
  grid.addEventListener('input', e => { if (e.target.matches('.bg-cell')) { e.target.classList.add('bg-dirty'); recalc(); } else if (e.target.matches('.bg-note')) e.target.classList.add('bg-dirty'); });
  // post only what changed (keeps large charts of accounts under max_input_vars)
  document.getElementById('gridForm').addEventListener('submit', () => {
    grid.querySelectorAll('input.bg-cell, input.bg-note').forEach(i => { if (i.value === i.defaultValue) i.disabled = true; });
  });
  // the row menu fills the % form's account too
  document.querySelectorAll('[data-modal="spreadModal"]').forEach(b => b.addEventListener('click', () => {
    document.querySelector('#rowAdjustForm [name="account_head_id"]').value = b.dataset.fAccount_head_id || b.getAttribute('data-f-account_head_id');
  }));
})();
</script>
@endif
@endsection
