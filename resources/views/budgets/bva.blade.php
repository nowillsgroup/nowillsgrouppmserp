@extends('layouts.app')
@section('title', 'Budget vs Actual')
@section('actions')
@if($budget)
<a class="btn" href="{{ route('reports.show', ['budget_vs_actual', 'budget' => $budget->id, 'month' => $month, 'to' => min($data['meta']['start']->copy()->endOfMonth()->toDateString(), bdate()->toDateString())]) }}"><x-icon name="print"/> Print / CSV</a>
<a class="btn" href="{{ route('budgets.show', $budget) }}"><x-icon name="eye"/> {{ $budget->budget_no }}</a>
@endif
<a class="btn" href="{{ route('budgets.index') }}"><x-icon name="wallet"/> Budgets</a>
@endsection
@section('content')
@if(! $budget)
  <div class="card"><div class="card-body"><x-empty icon="chart" text="No approved budget yet. Budget vs Actual compares the general ledger with the approved, active budget for the year."><a class="btn btn-primary mt-2" href="{{ route('budgets.index') }}">Go to budgets</a></x-empty></div></div>
@else
@php
  $m = $data['meta']; $T = $data['totals']; $S = $data['sections']; $K = $data['kpis'];
  $n = fn ($v) => money($v, false);
  $var = fn ($v, $fav) => '<span class="'.(abs($v) < 0.005 ? 'muted' : ($fav ? 'text-green' : 'text-red')).'">'.($v > 0 ? '+' : '').money($v, false).'</span>';
  $pct = fn ($p, $fav) => $p === null ? '<span class="muted">—</span>' : '<span class="'.($fav ? 'text-green' : 'text-red').'">'.($p > 0 ? '+' : '').number_format($p, 1).' %</span>';
  $rowsBySec = $data['rows']->groupBy('section');
  $hasF = (bool) $forecast;
  $cols = 11 + ($hasF ? 2 : 0);
  $canComment = can_do('budget.view');
  $general = $comments[''] ?? collect();
@endphp

<form method="get" class="filters card" style="padding:.8rem 1rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end" id="bvaFilter">
  <div class="field"><label>Budget</label><select name="budget" class="input">@foreach($approved as $a)<option value="{{ $a->id }}" @selected($a->id === $budget->id)>{{ $a->budget_no }} – {{ $a->name }} ({{ $a->fyLabel() }})@if($a->is_active) ★@endif</option>@endforeach</select></div>
  <div class="field"><label>Month</label><select name="month" class="input">@foreach($labels as $i => $l)<option value="{{ $i }}" @selected($i === $month)>{{ $l }}</option>@endforeach</select></div>
  <button class="btn btn-primary">Show</button>
  <span class="muted small ml-auto">Actuals from the general ledger up to <b>{{ $m['cutoff']->format('d M Y') }}</b> (closed days)@if($forecast) · forecast {{ $forecast->budget_no }}@endif</span>
</form>

@if($m['pre_live'])
<div class="alert alert-warn" id="preLive"><x-icon name="alert"/><div>{{ $labels[$month] }} is before the go-live date ({{ \Illuminate\Support\Carbon::parse($cfg['live_from'])->format('d M Y') }}) – the ledger does not hold complete figures for it, so variances are not flagged and the month is excluded from YTD.</div></div>
@elseif($m['partial'])
<div class="alert alert-info" id="partialMonth"><x-icon name="clock"/><div>Month in progress: actuals to {{ $m['cutoff']->format('d M') }} are compared with the budget <b>pro-rated to {{ round($m['factor'] * 100) }} %</b> of the month ({{ round($m['factor'] * $m['start']->daysInMonth) }} of {{ $m['start']->daysInMonth }} days).</div></div>
@elseif($m['future'])
<div class="alert alert-info"><x-icon name="calendar"/><div>{{ $labels[$month] }} has not started – only the budget is shown.</div></div>
@endif

<div class="stats">
  <x-stat label="Revenue – {{ $labels[$month] }}" :value="money($T['revenue']['actual'])" icon="trend" :tone="$T['revenue']['fav'] ? 'green' : 'red'" :sub="'budget '.money($T['revenue']['budget']).' · '.($T['revenue']['var'] >= 0 ? '+' : '').money($T['revenue']['var'])"/>
  <x-stat label="GOP – {{ $labels[$month] }}" :value="money($T['gop']['actual'])" icon="chart" :tone="$T['gop']['fav'] ? 'green' : 'red'" :sub="'budget '.money($T['gop']['budget']).($T['revenue']['actual'] ? ' · '.round($T['gop']['actual'] * 100 / $T['revenue']['actual'], 1).' % margin' : '')"/>
  <x-stat label="Net profit YTD" :value="money($T['net']['ytd_actual'])" icon="wallet" :tone="$T['net']['ytd_fav'] ? 'green' : 'red'" :sub="'budget '.money($T['net']['ytd_budget']).($m['ytd_from'] ? ' · from '.$m['ytd_from']->format('M y') : '')"/>
  <x-stat label="Variances to explain" :value="$m['flagged']" icon="message" :tone="$m['flagged'] ? 'amber' : 'green'" :sub="'≥ '.rtrim(rtrim(number_format($cfg['var_pct'], 1), '0'), '.').' % and ≥ '.money($cfg['var_amount'])"/>
</div>

<div class="card flush">
  <div class="card-head"><h3>Rooms KPIs</h3><span class="muted small">budget pro-rated like the P&L</span></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="kpiTable">
    <thead><tr><th></th><th class="num">Occupancy %</th><th class="num">ADR</th><th class="num">RevPAR</th><th class="num">Room-nights</th></tr></thead>
    <tbody>
    @foreach(['month' => $labels[$month], 'ytd' => 'Year to date'] as $k => $l)
      @php $a = $K[$k]['actual']; $bb = $K[$k]['budget']; @endphp
      <tr><td class="bold">{{ $l }} – actual</td><td class="num">{{ number_format($a['occupancy'], 1) }}</td><td class="num">{{ $n($a['adr']) }}</td><td class="num">{{ $n($a['revpar']) }}</td><td class="num">{{ number_format($a['sold']) }}</td></tr>
      <tr class="muted-row"><td>{{ $l }} – budget</td><td class="num">{{ number_format($bb['occupancy'], 1) }}</td><td class="num">{{ $n($bb['adr']) }}</td><td class="num">{{ $n($bb['revpar']) }}</td><td class="num">{{ number_format($bb['sold']) }}</td></tr>
      <tr><td class="small">Variance</td><td class="num">{!! $var(round($a['occupancy'] - $bb['occupancy'], 1), $a['occupancy'] >= $bb['occupancy']) !!} pts</td><td class="num">{!! $var($a['adr'] - $bb['adr'], $a['adr'] >= $bb['adr']) !!}</td><td class="num">{!! $var($a['revpar'] - $bb['revpar'], $a['revpar'] >= $bb['revpar']) !!}</td><td class="num">{!! $var($a['sold'] - $bb['sold'], $a['sold'] >= $bb['sold']) !!}</td></tr>
    @endforeach
    </tbody></table></div>
</div>

<div class="card flush">
  <div class="card-head"><h3>Profit & loss – {{ $labels[$month] }}</h3><span class="muted small">favourable = more revenue / less cost · <span class="text-green">green</span> favourable, <span class="text-red">red</span> adverse</span></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="bvaTable">
    <thead>
      <tr><th rowspan="2">Account</th><th colspan="4" class="num" style="text-align:center">{{ $labels[$month] }}</th><th colspan="4" class="num" style="text-align:center">Year to date</th><th colspan="{{ 1 + ($hasF ? 2 : 0) }}" class="num" style="text-align:center">Full year</th><th rowspan="2"></th></tr>
      <tr><th class="num">Actual</th><th class="num">Budget</th><th class="num">Var</th><th class="num">%</th><th class="num">Actual</th><th class="num">Budget</th><th class="num">Var</th><th class="num">%</th><th class="num">Budget</th>@if($hasF)<th class="num">Forecast</th><th class="num">Fc − Bud</th>@endif</tr>
    </thead>
    <tbody>
    @php
      $line = function ($r, $cls = '') use ($n, $var, $pct, $hasF) {
        $h = '<td class="num">'.$n($r['actual']).'</td><td class="num">'.$n($r['budget']).'</td><td class="num">'.$var($r['var'], $r['fav']).'</td><td class="num">'.$pct($r['var_pct'], $r['fav']).'</td>'
          .'<td class="num">'.$n($r['ytd_actual']).'</td><td class="num">'.$n($r['ytd_budget']).'</td><td class="num">'.$var($r['ytd_var'], $r['ytd_fav']).'</td><td class="num">'.$pct($r['ytd_var_pct'], $r['ytd_fav']).'</td>'
          .'<td class="num">'.$n($r['year_budget']).'</td>';
        if ($hasF) {
          $sign = ($r['type'] ?? 'income') === 'income' ? 1 : -1;
          $h .= '<td class="num">'.$n((float) $r['forecast']).'</td><td class="num">'.$var((float) $r['fc_var'], (float) $r['fc_var'] * $sign >= 0).'</td>';
        }
        return $h;
      };
    @endphp
    @foreach(\App\Services\BudgetService::SECTIONS as $sec => $label)
      @continue(! isset($rowsBySec[$sec]))
      <tr class="gl-type"><td colspan="{{ $cols }}">{{ $label }}</td></tr>
      @foreach($rowsBySec[$sec] as $r)
        @php $cm = $comments[(string) $r['id']] ?? collect(); @endphp
        <tr data-acct="{{ $r['code'] }}" class="{{ $r['flag'] ? ($cm->count() ? 'flag-done' : 'row-alert') : '' }}">
          <td><span class="muted small">{{ $r['code'] }}</span> {{ $r['name'] }}
            @if($r['flag'] && ! $cm->count())<span class="badge badge-amber" title="Variance needs an explanation">explain</span>@endif
            @foreach($cm as $c)<div class="bva-comment small" data-comment>💬 {{ $c->body }} <span class="muted tiny">– {{ $c->user?->name }}, {{ $c->created_at->format('d M') }}</span>
              @if($c->user_id === auth()->id() || can_do('budget.approve'))<form method="post" action="{{ route('budgets.comment.delete', $c) }}" style="display:inline" data-confirm="Remove this comment?">@csrf @method('DELETE')<button class="btn-link tiny muted" title="Remove">✕</button></form>@endif</div>@endforeach
          </td>
          {!! $line($r) !!}
          <td>@if($canComment)<button class="btn btn-sm btn-ghost" data-modal="commentModal" data-title="{{ $r['code'] }} {{ $r['name'] }} – {{ $labels[$month] }}" data-f-account_head_id="{{ $r['id'] }}" data-f-actual="{{ $r['actual'] }}" data-f-budget="{{ $r['budget'] }}" title="Comment"><x-icon name="message" size="sm"/></button>@endif</td>
        </tr>
      @endforeach
      <tr class="bold-row" data-sectotal="{{ $sec }}"><td>{{ $S[$sec]['name'] }}</td>{!! $line($S[$sec]) !!}<td></td></tr>
      @if($sec === 'opex')
        <tr class="bold-row derived" data-total="gop" style="border-top:2px solid var(--c-border)"><td>{{ $T['gop']['name'] }}</td>{!! $line($T['gop']) !!}<td></td></tr>
      @endif
    @endforeach
    <tr class="bold-row derived" data-total="net" style="border-top:2px solid var(--c-border)"><td>{{ $T['net']['name'] }}</td>{!! $line($T['net']) !!}<td></td></tr>
    </tbody>
  </table></div>
</div>

<div class="card" id="generalComments"><div class="card-head"><h3>Month commentary – {{ $labels[$month] }}</h3></div><div class="card-body">
  @forelse($general as $c)
    <div class="bva-comment" data-comment><b>{{ $c->user?->name }}</b> <span class="muted small">{{ $c->created_at->format('d M Y H:i') }}</span><div>{{ $c->body }}</div></div>
  @empty
    <p class="muted small">No general commentary for this month yet.</p>
  @endforelse
  @if($canComment)
  <form method="post" action="{{ route('budgets.comment') }}" class="mt-2" id="generalCommentForm">@csrf
    <input type="hidden" name="budget_id" value="{{ $budget->id }}"><input type="hidden" name="period" value="{{ $period }}">
    <textarea name="body" class="input" rows="2" placeholder="Overall explanation for the month – e.g. occupancy below budget because of the monsoon, banquets ahead…" required></textarea>
    <button class="btn btn-primary btn-sm mt-1"><x-icon name="message" size="sm"/> Add commentary</button>
  </form>
  @endif
</div></div>

<div class="modal" id="commentModal"><div class="modal-box" style="max-width:520px"><form method="post" action="{{ route('budgets.comment') }}" id="commentForm">@csrf
  <div class="modal-head"><h3 data-title>Explain variance</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <input type="hidden" name="budget_id" value="{{ $budget->id }}"><input type="hidden" name="period" value="{{ $period }}">
    <input type="hidden" name="account_head_id"><input type="hidden" name="actual"><input type="hidden" name="budget">
    <x-field name="body" label="Explanation" type="textarea" rows="3" required placeholder="What caused the difference and what is being done about it?"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveComment">Save</button></div>
</form></div></div>
@endif
@endsection
