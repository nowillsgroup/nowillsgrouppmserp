@extends('layouts.app')
@section('title', 'Feedback & Reputation')
@section('crumbs') Guests &amp; CRM / Feedback &amp; Reputation @endsection
@section('actions')
<form method="get" class="inline"><select name="days" onchange="this.form.submit()" id="periodSel">@foreach(\App\Http\Controllers\ReputationController::PERIODS as $k => $l)<option value="{{ $k }}" @selected($days === $k)>{{ $l }}</option>@endforeach</select></form>
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['nps_trend', 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}"><x-icon name="chart"/> NPS report</a>@endif
@endsection
@section('content')
@include('reputation._nav')
@php
  $n = $stats['nps']; $pn = $prev['nps']['nps'];
  $delta = fn ($a, $b, $suffix = '') => $a === null || $b === null ? '' : (($a - $b) >= 0 ? '▲ ' : '▼ ').abs(round($a - $b, 2)).$suffix.' vs previous period';
@endphp
<div class="stats req5">
  <x-stat label="Net Promoter Score" :value="$n['nps'] === null ? '—' : ($n['nps'] > 0 ? '+' : '').$n['nps']" icon="trend" :tone="$n['nps'] === null ? 'slate' : ($n['nps'] >= 50 ? 'green' : ($n['nps'] >= 0 ? 'amber' : 'red'))" :sub="$n['n'] ? $n['n'].' answers · '.$delta($n['nps'], $pn) : 'no answers yet'"/>
  <x-stat label="Guest satisfaction" :value="$stats['csat'] === null ? '—' : number_format($stats['csat'], 2).' ★'" icon="star" tone="amber" :sub="$stats['responses'].' responses · '.$delta($stats['csat'], $prev['csat'])"/>
  <x-stat label="Survey response rate" :value="$stats['response_rate'] === null ? '—' : $stats['response_rate'].'%'" icon="mail" tone="blue" :sub="$stats['completed'].' of '.$stats['invited'].' invited guests answered'" :href="route('rep.invitations')"/>
  <x-stat label="Online rating" :value="$stats['review_avg'] === null ? '—' : number_format($stats['review_avg'], 2).' / 5'" icon="globe" tone="purple" :sub="$stats['reviews'].' reviews · '.($stats['review_response_rate'] === null ? 'none to answer' : $stats['review_response_rate'].'% answered')" :href="route('rep.reviews.index', ['tab' => 'all'])"/>
  <x-stat label="Open guest cases" :value="$alerts['open']" icon="alert" :tone="$alerts['overdue'] ? 'red' : ($alerts['open'] ? 'amber' : 'green')" :sub="$alerts['overdue'].' past SLA · '.$alerts['high'].' high severity'" :href="can_do('feedback.cases') ? route('rep.cases.index') : null"/>
</div>

<div class="grid g-2-1">
  <div class="card">
    <div class="card-head"><h3><x-icon name="trend"/> Six-month trend</h3><span class="tiny muted">NPS (bars) · satisfaction and online rating out of 5</span></div>
    <div class="card-body">
      @php $maxN = 100; @endphp
      <div class="bar-chart" id="npsTrend" style="height:170px">
        @foreach($trend as $ym => $t)
          @php $v = $t['nps']; $h = $v === null ? 0 : max(3, abs($v) / $maxN * 100); @endphp
          <div class="col"><span class="tiny bold {{ $v === null ? 'muted' : ($v >= 0 ? 'text-green' : 'text-red') }}">{{ $v === null ? '–' : ($v > 0 ? '+' : '').$v }}</span>
            <div class="b" data-v="{{ $t['responses'] }} responses · CSAT {{ $t['csat'] ?? '–' }} · online {{ $t['reviews'] ?? '–' }}" style="height: {{ $h }}%; {{ $v !== null && $v < 0 ? 'background:linear-gradient(180deg,#f87171,#dc2626)' : '' }}"></div>
            <small>{{ $t['label'] }}</small><small class="muted">{{ $t['csat'] ? $t['csat'].'★' : '' }} {{ $t['reviews'] ? '· '.$t['reviews'] : '' }}</small></div>
        @endforeach
      </div>
      <div class="flex gap-2 mt-1 small" id="npsSplit">
        @php $tot = max(1, $n['n']); @endphp
        <div style="flex:1"><div class="progress" style="display:flex;height:12px;border-radius:6px;overflow:hidden">
          <span style="width:{{ $n['promoters'] / $tot * 100 }}%;background:#16a34a"></span><span style="width:{{ $n['passives'] / $tot * 100 }}%;background:#f59e0b"></span><span style="width:{{ $n['detractors'] / $tot * 100 }}%;background:#dc2626"></span></div>
          <div class="flex justify-between tiny mt-1"><span class="text-green">Promoters {{ $n['promoters'] }} ({{ round($n['promoters'] / $tot * 100) }}%)</span><span class="text-amber">Passives {{ $n['passives'] }}</span><span class="text-red">Detractors {{ $n['detractors'] }} ({{ round($n['detractors'] / $tot * 100) }}%)</span></div></div>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3><x-icon name="globe"/> Review sites</h3><a class="small" href="{{ route('rep.reviews.index') }}">Inbox →</a></div>
    <div class="card-body table-wrap"><table class="table" id="sourceTable"><thead><tr><th>Site</th><th class="num">Reviews</th><th>Average</th></tr></thead><tbody>
      @forelse($stats['by_source'] as $src => $v)<tr><td>{{ \App\Models\OnlineReview::SOURCES[$src] ?? $src }}</td><td class="num">{{ $v['n'] }}</td><td class="nowrap">{!! stars($v['avg']) !!} <b class="small">{{ number_format($v['avg'], 1) }}</b></td></tr>
      @empty<tr><td colspan="3"><x-empty text="No reviews in this period." icon="globe"/></td></tr>@endforelse
    </tbody></table>
    @if($stats['review_response_hours'] !== null)<p class="tiny muted mt-1">Average time to respond: <b>{{ $stats['review_response_hours'] }} h</b></p>@endif
    </div>
  </div>
</div>

<div class="card flush">
  <div class="card-head"><h3><x-icon name="layers"/> Departments – what guests say</h3><span class="tiny muted">ratings from surveys &amp; forms · mentions in comments and online reviews</span></div>
  <div class="card-body table-wrap"><table class="table" id="deptTable"><thead><tr><th>Department</th><th>Average rating</th><th class="num">Ratings</th><th class="num">Praised</th><th class="num">Criticised</th><th style="width:30%">Net sentiment</th></tr></thead><tbody>
  @foreach($stats['departments'] as $k => $d)
    @continue(! $d['n'] && ! $d['pos'] && ! $d['neg'])
    @php $tm = max(1, $d['pos'] + $d['neg']); @endphp
    <tr class="{{ $d['avg'] !== null && $d['avg'] < 3.5 ? 'row-alert' : '' }}"><td class="bold">{{ $d['label'] }}</td>
      <td class="nowrap">@if($d['avg'] !== null){!! stars($d['avg']) !!} <b class="small">{{ number_format($d['avg'], 2) }}</b>@else<span class="muted">–</span>@endif</td>
      <td class="num">{{ $d['n'] }}</td><td class="num text-green">{{ $d['pos'] }}</td><td class="num text-red">{{ $d['neg'] }}</td>
      <td>@if($d['pos'] + $d['neg'])<div class="progress" style="display:flex;height:10px;border-radius:5px;overflow:hidden"><span style="width:{{ $d['pos'] / $tm * 100 }}%;background:#16a34a"></span><span style="width:{{ $d['neg'] / $tm * 100 }}%;background:#dc2626"></span></div>@else<span class="tiny muted">no mentions</span>@endif</td></tr>
  @endforeach
  </tbody></table></div>
</div>

<div class="grid g-2">
  <div class="card flush">
    <div class="card-head"><h3><x-icon name="alert"/> Unhappy guests</h3><a class="small" href="{{ route('feedback.index', ['sentiment' => 'negative']) }}">All negative →</a></div>
    <div class="card-body table-wrap"><table class="table" id="attentionTable"><tbody>
    @forelse($attention as $f)<tr><td class="small nowrap">{{ $f->created_at->format('d M') }}</td><td><a href="{{ route('rep.responses.show', $f) }}" class="bold">{{ $f->guest_name ?: 'Anonymous' }}</a> {!! nps_badge($f->nps) !!} {!! stars($f->rating_overall) !!}
      <div class="tiny muted">{{ \Illuminate\Support\Str::limit($f->comments, 90) }}</div></td>
      <td class="small">@if($f->recoveryCase)<a href="{{ can_do('feedback.cases') ? route('rep.cases.show', $f->recoveryCase) : '#' }}">{{ $f->recoveryCase->case_no }}</a> {!! status_badge($f->recoveryCase->status) !!}@else<span class="muted">no case</span>@endif</td></tr>
    @empty<tr><td><x-empty text="No negative feedback in this period." icon="star"/></td></tr>@endforelse
    </tbody></table></div>
  </div>
  <div class="card flush">
    <div class="card-head"><h3><x-icon name="message"/> Reviews waiting for a response</h3><a class="small" href="{{ route('rep.reviews.index') }}">Inbox →</a></div>
    <div class="card-body table-wrap"><table class="table" id="waitingTable"><tbody>
    @forelse($waiting as $r)<tr class="{{ $r->created_at->lt(now()->subHours(48)) && $r->status !== 'pending' ? 'row-alert' : '' }}"><td class="small nowrap">{{ $r->review_date->format('d M') }}</td><td><a href="{{ route('rep.reviews.show', $r) }}" class="bold">{{ $r->reviewer_name }}</a> <span class="tiny muted">{{ $r->sourceName() }}</span>
      <div>{!! stars($r->rating5) !!} <span class="tiny">{{ \Illuminate\Support\Str::limit($r->title ?: $r->body, 70) }}</span></div></td><td>{!! status_badge($r->status) !!}</td></tr>
    @empty<tr><td><x-empty text="Every review has been answered." icon="check"/></td></tr>@endforelse
    </tbody></table></div>
  </div>
</div>
@if(can_do('feedback.cases') && $cases->count())
<div class="card flush"><div class="card-head"><h3><x-icon name="shield"/> Open guest cases</h3><a class="small" href="{{ route('rep.cases.index') }}">All cases →</a></div>
  <div class="card-body table-wrap"><table class="table" id="caseMini"><tbody>
  @foreach($cases as $c)<tr class="{{ $c->isOverdue() ? 'row-alert' : '' }}"><td class="nowrap"><a href="{{ route('rep.cases.show', $c) }}" class="bold">{{ $c->case_no }}</a></td><td>@include('reputation._sev', ['s' => $c->severity])</td><td class="small">{{ $c->title }}</td><td class="small">{{ $c->assignee?->name ?? 'Unassigned' }}</td>
    <td class="small nowrap {{ $c->isOverdue() ? 'text-red bold' : '' }}">due {{ $c->due_at->format('d M H:i') }}</td></tr>@endforeach
  </tbody></table></div></div>
@endif
@endsection
