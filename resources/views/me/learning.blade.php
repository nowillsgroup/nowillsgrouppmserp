@extends('layouts.app')
@section('title', 'My Training & SOPs')
@section('crumbs') {{ $e->name }} · {{ $e->emp_code }} @endsection
@section('content')
@if($mine['pending']->count())<div class="alert alert-warn" id="mySopAlert"><x-icon name="book"/> You have {{ $mine['pending']->count() }} procedure(s) to read and sign.</div>@endif
<div class="stats tr-stats" id="myStats">
  <x-stat label="My training compliance" :value="$mine['pct'] === null ? '—' : $mine['pct'].'%'" icon="shield" :tone="($mine['pct'] ?? 100) >= 100 ? 'green' : 'amber'" :sub="$mine['required']->count().' required course(s)'"/>
  <x-stat label="Procedures to sign" :value="$mine['pending']->count()" icon="edit" :tone="$mine['pending']->count() ? 'amber' : 'green'" :sub="$mine['signed']->count().' signed'"/>
  <x-stat label="Next training" :value="$mine['upcoming']->first()?->session->date->format('D d M') ?? '—'" icon="calendar" tone="purple" :sub="$mine['upcoming']->first()?->course->name ?? 'nothing booked'"/>
</div>
<div class="g-2 mt-1">
<div class="card flush" id="mySops"><div class="card-head"><h3><x-icon name="book"/> Procedures to read & sign</h3></div>
<div class="card-body">@forelse($mine['pending'] as $d)
  <div class="between-row" data-sop="{{ $d->code }}"><div><b>{{ $d->code }}</b> {{ $d->title }}<div class="tiny muted">version {{ $d->version }} · {{ $d->department?->name ?? 'all staff' }}</div></div><a class="btn btn-sm btn-primary" href="{{ route('me.sop', $d) }}">Read & sign</a></div>
@empty<div class="muted small">You are up to date with every procedure. ✓</div>@endforelse</div></div>

<div class="card flush" id="myUpcoming"><div class="card-head"><h3><x-icon name="calendar"/> My booked training</h3></div>
<div class="card-body">@forelse($mine['upcoming'] as $r)
  <div class="between-row"><div><b>{{ $r->course->name }}</b><div class="tiny muted">{{ $r->session->date->format('l d M') }} · {{ $r->session->start_time }}{{ $r->session->end_time ? '–'.$r->session->end_time : '' }} · {{ $r->session->venue }}</div></div><span class="badge badge-blue">booked</span></div>
@empty<div class="muted small">No training booked.</div>@endforelse</div></div>
</div>
<div class="card flush mt-1" id="myRequired"><div class="card-head"><h3><x-icon name="shield"/> Training my job requires</h3></div>
<div class="card-body table-wrap"><table class="table table-compact">
  <thead><tr><th>Course</th><th>State</th><th>Certificate</th></tr></thead>
  <tbody>@forelse($mine['required'] as $r) @php $rec = $r['cell']['record']; @endphp
    <tr data-course="{{ $r['course']->code }}"><td><b>{{ $r['course']->name }}</b><div class="tiny muted">{{ $r['course']->validityLabel() }}</div></td><td>@include('learning._cell', ['cell' => $r['cell']])</td>
      <td class="small">@if($rec && $rec->status === 'passed')<a href="{{ route('me.certificate', $rec) }}" target="_blank">{{ $rec->certificate_no ?: 'view' }}</a>@else — @endif</td></tr>
  @empty<tr><td colspan="3" class="empty">No training is required for your role.</td></tr>@endforelse</tbody>
</table></div></div>
@if($mine['records']->count())
<div class="card flush mt-1"><div class="card-head"><h3><x-icon name="receipt"/> My training history</h3></div>
<div class="card-body table-wrap"><table class="table table-compact"><tbody>@foreach($mine['records'] as $r)
  <tr><td class="small">{{ $r->completed_on?->format('d M Y') }}</td><td>{{ $r->course->name }}</td><td><span class="badge badge-{{ $r->statusColor() }}">{{ $r->statusLabel() }}</span></td><td class="small">{{ $r->expires_on ? 'valid to '.$r->expires_on->format('d M Y') : '' }}</td></tr>
@endforeach</tbody></table></div></div>
@endif
@endsection
