@extends('layouts.app')
@section('title', 'Learning & Development')
@section('crumbs') Learning & SOPs / Dashboard @endsection
@php $L = \App\Services\LearningService::STATES; $t = $m['totals']; @endphp
@section('actions')
  <a class="btn" href="{{ route('learning.matrix') }}"><x-icon name="grid"/> Training matrix</a>
  @if(can_do('learning.train'))<a class="btn btn-primary" href="{{ route('learning.sessions') }}#schedule" id="scheduleBtn"><x-icon name="plus"/> Schedule training</a>@endif
@endsection
@section('content')
@if($alerts['to_complete'])<div class="alert alert-warn" id="toCompleteAlert"><x-icon name="alert"/> {{ $alerts['to_complete'] }} training session(s) have taken place but the attendance and results are not recorded yet – <a href="{{ route('learning.sessions', ['status' => 'scheduled']) }}">record them</a>.</div>@endif

<div class="stats tr-stats" id="ldStats">
  <x-stat label="Training compliance" :value="$t['pct'] === null ? '—' : $t['pct'].'%'" icon="shield" :tone="($t['pct'] ?? 100) >= 90 ? 'green' : (($t['pct'] ?? 100) >= 75 ? 'amber' : 'red')" :sub="($t['current'] + $t['expiring']).' of '.($t['required'] - $t['due']).' required certificates held · '.$m['employees']->count().' staff'" :href="route('learning.matrix')"/>
  <x-stat label="Overdue or expired" :value="$t['overdue'] + $t['expired']" icon="alert" :tone="$t['overdue'] + $t['expired'] ? 'red' : 'green'" :sub="$t['overdue'].' never trained · '.$t['expired'].' lapsed · '.$t['due'].' new starters due'"/>
  <x-stat label="Expiring soon" :value="$t['expiring']" icon="clock" tone="amber" :sub="'within '.$cfg['expiry_warn_days'].' days'" :href="route('learning.records', ['expiring' => $cfg['expiry_warn_days']])"/>
  <x-stat label="Trained this year" :value="number_format($yearHours, 1).' h'" icon="book" tone="purple" :sub="money($yearCost).' spent on training'"/>
</div>

<div class="g-2-1 mt-1">
<div>
<div class="card flush" id="deptCard"><div class="card-head"><h3><x-icon name="building"/> Compliance by department</h3></div>
<div class="card-body table-wrap"><table class="table table-compact" id="deptTable">
  <thead><tr><th>Department</th><th class="num">Staff</th>@foreach($L as $k => [$l, $c])<th class="num">{{ $l }}</th>@endforeach<th style="width:30%">Compliance</th></tr></thead>
  <tbody>@forelse($depts as $d)
    <tr><td><b>{{ $d['department'] }}</b></td><td class="num">{{ $d['staff'] }}</td>@foreach($L as $k => $x)<td class="num {{ in_array($k, ['overdue', 'expired']) && $d[$k] ? 'text-red bold' : '' }}">{{ $d[$k] ?: '·' }}</td>@endforeach
      <td>@if($d['pct'] !== null)<div class="flex gap-1" style="align-items:center"><div class="cp-bar" style="flex:1"><span style="width:{{ $d['pct'] }}%;background:{{ $d['pct'] >= 90 ? '#16a34a' : ($d['pct'] >= 75 ? '#f59e0b' : '#dc2626') }}"></span></div><b class="small">{{ $d['pct'] }}%</b></div>@else<span class="muted small">nothing due yet</span>@endif</td></tr>
  @empty<tr><td colspan="9" class="empty">No staff yet.</td></tr>@endforelse</tbody>
</table></div></div>

<div class="card flush mt-1" id="gapsCard"><div class="card-head"><h3><x-icon name="alert"/> Most urgent gaps</h3><a class="btn btn-sm btn-ghost" href="{{ route('learning.matrix') }}">Full matrix →</a></div>
<div class="card-body table-wrap"><table class="table table-compact" id="gapsTable">
  <thead><tr><th>Employee</th><th>Course</th><th>State</th><th>Booked</th></tr></thead>
  <tbody>@forelse($gaps as $g)
    <tr data-gap="{{ $g['employee']->id }}-{{ $g['course']->id }}"><td><a href="{{ route('learning.employee', $g['employee']) }}"><b>{{ $g['employee']->name }}</b></a><div class="tiny muted">{{ $g['employee']->designation }} · {{ $g['employee']->department?->name }}</div></td>
      <td class="small"><a href="{{ route('learning.course', $g['course']) }}">{{ $g['course']->name }}</a></td><td>@include('learning._cell', ['cell' => $g['cell']])</td>
      <td class="small">@if($g['cell']['session'])<a href="{{ route('learning.session', $g['cell']['session']) }}">{{ $g['cell']['booked']->format('d M') }}</a>@else<a class="btn btn-sm btn-ghost" href="{{ route('learning.course', $g['course']) }}">Book a seat</a>@endif</td></tr>
  @empty<tr><td colspan="4" class="empty">Everyone is up to date. 🎉</td></tr>@endforelse</tbody>
</table></div></div>
</div>

<div>
<div class="card flush" id="upcomingCard"><div class="card-head"><h3><x-icon name="calendar"/> Upcoming sessions</h3></div>
<div class="card-body">@forelse($upcoming as $s)
  <div class="between-row" data-session="{{ $s->number }}"><div><a href="{{ route('learning.session', $s) }}"><b>{{ $s->course->name }}</b></a><div class="tiny muted">{{ $s->date->format('D d M') }} · {{ $s->start_time }} · {{ $s->venue }}</div></div>
    <span class="badge badge-{{ $s->date->lt(bdate()) ? 'amber' : 'blue' }}">{{ $s->records_count }}{{ $s->capacity ? ' / '.$s->capacity : '' }}</span></div>
@empty<div class="muted small">No sessions scheduled.</div>@endforelse</div></div>

<div class="card flush mt-1" id="reviewCard"><div class="card-head"><h3><x-icon name="book"/> SOPs due for review</h3><a class="btn btn-sm btn-ghost" href="{{ route('learning.sops') }}">Library →</a></div>
<div class="card-body">@forelse($reviews as $d)
  <div class="between-row"><div><a href="{{ route('learning.sop', $d) }}"><b>{{ $d->code }}</b> {{ $d->title }}</a><div class="tiny muted">v{{ $d->version }} · review {{ $d->review_due->format('d M Y') }}</div></div>
    <span class="badge badge-{{ $d->reviewOverdue() ? 'red' : 'amber' }}">{{ $d->reviewOverdue() ? 'overdue' : 'soon' }}</span></div>
@empty<div class="muted small">No SOP reviews due in the next {{ $cfg['review_warn_days'] }} days.</div>@endforelse</div></div>

<div class="card flush mt-1" id="signCard"><div class="card-head"><h3><x-icon name="edit"/> SOP sign-off outstanding</h3></div>
<div class="card-body">@forelse($sops as $r)
  <div class="between-row"><div><a href="{{ route('learning.sop', $r['doc']) }}"><b>{{ $r['doc']->code }}</b> {{ $r['doc']->title }}</a><div class="tiny muted">{{ $r['required'] - $r['signed'] }} still to sign v{{ $r['version'] }}</div></div>
    <b class="small">{{ $r['pct'] }}%</b></div>
@empty<div class="muted small">Every SOP has been signed by the staff it applies to.</div>@endforelse</div></div>
</div>
</div>
@endsection
