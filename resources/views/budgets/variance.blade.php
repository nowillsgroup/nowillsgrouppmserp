@extends('layouts.app')
@section('title', 'Variance review · '.$b->budget_no)
@section('crumbs') <a href="{{ route('budgets.index') }}">Budgets</a> / <a href="{{ route('budgets.show', $b) }}">{{ $b->budget_no }}</a> / Variance review @endsection
@section('actions')
<a class="btn" href="{{ route('budgets.show', $b) }}"><x-icon name="chev"/> The version</a>
@if($b->status === 'approved')
<a class="btn" href="{{ route('budgets.bva', ['budget' => $b->id, 'month' => $month]) }}" id="bvaLink"><x-icon name="chart"/> Budget vs Actual</a>
@endif
@endsection
@section('content')
@php
  $rows = $wl['rows'];
  $meta = $wl['meta'];
  $due = $wl['due'];
  $today = bdate();
  $overdue = $wl['outstanding'] && $today->copy()->startOfDay()->gt($due);
@endphp

@if($b->department_id)
<div class="alert alert-info" id="ccNote"><x-icon name="users"/><div>This is the review of cost centre <b>{{ $b->costCentreLabel() }}</b> – only the ledger lines tagged to that department are compared.</div></div>
@endif
@if($meta['pre_live'])
<div class="alert alert-warn"><x-icon name="alert"/><div>{{ $labels[$month] }} is before the go-live date – nothing is compared or flagged for it, so there is nothing to explain.</div></div>
@elseif($meta['future'])
<div class="alert alert-info"><x-icon name="calendar"/><div>{{ $labels[$month] }} has not started yet – there are no actuals to review.</div></div>
@elseif($meta['partial'])
<div class="alert alert-info"><x-icon name="clock"/><div>The month is in progress: actuals to {{ $meta['cutoff']->format('d M') }} are compared with the budget pro-rated to <b>{{ round($meta['factor'] * 100) }} %</b> of the month.</div></div>
@endif

<div class="stats">
  <x-stat label="Variances flagged" :value="$wl['flagged']" icon="message" :tone="$wl['flagged'] ? 'amber' : 'green'" :sub="$labels[$month]"/>
  <x-stat label="Explained" :value="$wl['explained'].' / '.$wl['flagged']" icon="check" :tone="$wl['outstanding'] ? 'slate' : 'green'" sub="each with an owner"/>
  <x-stat label="Still to explain" :value="$wl['outstanding']" icon="alert" :tone="$wl['outstanding'] ? 'red' : 'green'" :sub="$overdue ? 'past the deadline' : 'due by '.$due->format('d M Y')"/>
  <x-stat label="Month" :value="$labels[$month]" icon="calendar" tone="blue"
    :sub="$signoff ? 'signed off '.$signoff->signed_at?->format('d M Y') : 'not signed off'"/>
</div>

<form method="get" class="filters card" style="padding:.8rem 1rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end" id="reviewFilter">
  <div class="field"><label>Month</label><select name="month" class="input">@foreach($labels as $i => $l)<option value="{{ $i }}" @selected($i === $month)>{{ $l }}</option>@endforeach</select></div>
  <button class="btn btn-primary">Show</button>
  <span class="muted small ml-auto">A variance is flagged at <b>≥ {{ rtrim(rtrim(number_format($cfg['var_pct'], 1), '0'), '.') }} %</b> and <b>≥ {{ money($cfg['var_amount']) }}</b>.
    Explanations are due <b>{{ $cfg['explain_due_days'] }} day(s)</b> after month end.</span>
</form>

<div class="card flush">
  <div class="card-head"><h3>What has to be explained</h3><span class="muted small">{{ $wl['flagged'] ? $wl['flagged'].' account(s) over both thresholds' : 'nothing flagged this month' }}</span></div>
  <div class="card-body table-wrap"><table class="table" id="varianceTable">
    <thead><tr><th>Account</th><th class="num">Budget to date</th><th class="num">Actual</th><th class="num">Variance</th><th>Explanation</th><th>Owner / due</th><th></th></tr></thead>
    <tbody>
    @forelse($rows as $w)
      @php $r = $w['row']; $c = $w['comment']; @endphp
      <tr data-account="{{ $r['code'] }}">
        <td class="bold">{{ $r['code'] ? $r['code'].' · ' : '' }}{{ $r['name'] }}
          <div class="tiny muted">{{ \App\Services\BudgetService::SECTIONS[$r['section']] ?? $r['section'] }}</div></td>
        <td class="num">{{ money($r['budget'], false) }}</td>
        <td class="num">{{ money($r['actual'], false) }}</td>
        <td class="num {{ $r['fav'] ? 'text-green' : 'text-red' }}">{{ ($r['var'] > 0 ? '+' : '') }}{{ money($r['var'], false) }}
          <div class="tiny muted">{{ $r['var_pct'] === null ? '—' : ($r['var_pct'] > 0 ? '+' : '').number_format($r['var_pct'], 1).' %' }}</div></td>
        <td class="small">
          @if($c && $w['explained'])
            <div>{{ $c->body }}</div>
            <div class="tiny muted">{{ $c->user?->name ?? '—' }} · {{ $c->created_at?->format('d M Y') }}
              <span class="badge badge-{{ $w['tone'] }}">{{ $w['status'] }}</span></div>
          @else
            <span class="text-amber">No explanation yet.</span>
          @endif
        </td>
        <td class="small">
          {{ $w['owner']?->name ?? ($w['explained'] ? '—' : 'nobody yet') }}
          <div class="tiny {{ $w['overdue'] ? 'text-red' : 'muted' }}">{{ $w['due_on'] ? ($w['overdue'] ? 'was due ' : 'due ').$w['due_on']->format('d M Y') : '' }}</div>
        </td>
        <td>
          @if(can_do('budget.manage'))
            <button class="btn btn-sm" data-modal="ex{{ $r['id'] }}" id="exBtn{{ $r['id'] }}"><x-icon name="{{ $w['explained'] ? 'edit' : 'plus' }}" size="sm"/> {{ $w['explained'] ? 'Replace' : 'Explain' }}</button>
          @endif
          @if($c && $w['explained'] && can_do('budget.approve'))
            <form method="post" action="{{ route('budgets.comment.accept', $c) }}">@csrf
              <input type="hidden" name="accepted" value="{{ $c->status === 'accepted' ? '0' : '1' }}">
              <button class="btn btn-sm btn-ghost">{{ $c->status === 'accepted' ? 'Reopen' : 'Accept' }}</button>
            </form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="7"><x-empty icon="check" text="Nothing is flagged for this month – no variance reached both the percentage and the amount set in Budget settings."/></td></tr>
    @endforelse
    </tbody>
  </table></div>
</div>

<div class="grid g-2-1">
  <div class="card"><div class="card-head"><h3>Sign the month off</h3></div><div class="card-body">
    @if($signoff)
      <ul class="list" id="signoffInfo">
        <li><span>Signed off by</span><b>{{ $signoff->signer?->name ?? '—' }}</b></li>
        <li><span>On</span><b>{{ $signoff->signed_at?->format('d M Y H:i') ?? '—' }}</b></li>
        <li><span>Variances at the time</span><b>{{ $signoff->explained }} of {{ $signoff->flagged }} explained</b></li>
        @if($signoff->note)<li><span>Note</span><b>{{ $signoff->note }}</b></li>@endif
      </ul>
      <p class="small muted m-0">The month is signed off. A later version or a re-forecast is reviewed on its own.</p>
    @else
      @if($blockers)
        <p class="small m-0 mb-1"><b>The month cannot be signed off yet:</b></p>
        <ul class="list" id="signoffBlockers">
          @foreach($blockers as $why)<li><span class="small">{{ $why }}</span></li>@endforeach
        </ul>
      @endif
      @if(can_do('budget.approve'))
        <form method="post" action="{{ route('budgets.signoff', $b) }}" id="signoffForm" class="mt-2">@csrf
          <input type="hidden" name="month" value="{{ $month }}">
          <div class="field"><label>Note (optional)</label><input name="note" maxlength="255" class="input" placeholder="e.g. reviewed with the HOD on 12 Nov"></div>
          <button class="btn btn-primary mt-1" id="signoffBtn" @if($blockers) disabled @endif><x-icon name="check"/> Sign off {{ $labels[$month] }}</button>
        </form>
      @else
        <p class="small muted m-0">Only someone with the budget approval permission can sign the month off.</p>
      @endif
    @endif
  </div></div>
  <div class="card"><div class="card-head"><h3>How this works</h3></div><div class="card-body small muted">
    <p class="m-0 mb-1">The list is exactly what the comparison flags – the same rule, read from one place. An explanation is written against <b>this version</b> and <b>this month</b>, and it says who owes it and by when.</p>
    <p class="m-0 mb-1">A month is signed off only when every flagged variance has an explanation; the server refuses otherwise and names the accounts still open. Signing off does not change the budget or the ledger.</p>
    <p class="m-0">The deadline is the month end plus <b>{{ $cfg['explain_due_days'] }} day(s)</b>, set in Budget settings (0–90).</p>
  </div></div>
</div>

@if(can_do('budget.manage'))
  @foreach($rows as $w)
    @php $r = $w['row']; $c = $w['comment']; @endphp
    <div class="modal" id="ex{{ $r['id'] }}"><div class="modal-box"><form method="post" action="{{ route('budgets.explain', $b) }}">@csrf
      <div class="modal-head"><h3>Explain {{ $r['code'] ? $r['code'].' · ' : '' }}{{ $r['name'] }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
      <div class="modal-body"><div class="form-grid">
        <input type="hidden" name="month" value="{{ $month }}">
        <input type="hidden" name="account_head_id" value="{{ $r['id'] }}">
        <div class="field span-all"><label>What happened <span class="req">*</span></label>
          <textarea name="body" rows="3" maxlength="2000" required placeholder="e.g. Two extra agency housekeepers for the conference block; the roster returns to normal in November.">{{ $w['explained'] ? $c?->body : '' }}</textarea>
          <span class="hint">{{ money($r['budget'], false) }} budgeted, {{ money($r['actual'], false) }} spent – {{ $r['var'] > 0 ? 'over' : 'under' }} by {{ money(abs($r['var']), false) }}.</span></div>
        <div class="field"><label>Owner</label><select name="assigned_to" class="input"><option value="">— whoever is preparing the budget —</option>@foreach($people as $p)<option value="{{ $p->id }}" @selected($w['owner'] && $w['owner']->id === $p->id)>{{ $p->name }}</option>@endforeach</select></div>
        <div class="field"><label>Due</label><input type="date" name="due_on" class="input" value="{{ $w['due_on'] ? $w['due_on']->toDateString() : $due->toDateString() }}"><span class="hint">blank = month end + {{ $cfg['explain_due_days'] }} days</span></div>
      </div></div>
      <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save explanation</button></div>
    </form></div></div>
  @endforeach
@endif
@endsection
