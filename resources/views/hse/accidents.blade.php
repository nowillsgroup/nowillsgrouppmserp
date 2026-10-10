@extends('layouts.app')
@section('title', 'Accidents & Near Misses')
@section('crumbs') Health &amp; Safety / Accidents &amp; near misses @endsection
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'hse_accidents']) }}"><x-icon name="download"/> Register</a>
  @if(\App\Services\HseService::canCheck(auth()->user()))<button class="btn btn-primary" data-modal="hseAccidentModal" id="hseReportBtn"><x-icon name="alert"/> Report</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Days since a lost-time injury" :value="$k['days_since_lti'] ?? '—'" icon="shield" tone="green"/>
  <x-stat label="LTIFR · 12 months" :value="$k['ltifr'] ?? '—'" icon="trend" tone="slate" :sub="$k['ltis'].' LTI per '.number_format($k['hours']).' hours × 1,000,000'"/>
  <x-stat label="Injuries · 12 months" :value="$k['accidents']" icon="users" tone="amber" :sub="$k['lost_days'].' days lost'"/>
  <x-stat label="Near misses · 12 months" :value="$k['near_misses']" icon="eye" tone="blue"/>
  <x-stat label="To notify" :value="$alerts['unnotified']" icon="alert" :tone="$alerts['unnotified'] ? 'red' : 'green'" sub="Act 328 ss. 10–11" :href="route('hse.accidents', ['reportable' => 1])"/>
</div>
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input type="date" name="from" value="{{ $from }}" aria-label="From"><input type="date" name="to" value="{{ $to }}" aria-label="To">
  <select name="kind" aria-label="Kind"><option value="">All kinds</option>@foreach(\App\Models\HseAccident::KINDS as $k2 => $l)<option value="{{ $k2 }}" @selected(request('kind') === $k2)>{{ $l }}</option>@endforeach</select>
  <select name="status" aria-label="Status"><option value="">Any status</option>@foreach(\App\Models\HseAccident::STATUSES as $k2 => $l)<option value="{{ $k2 }}" @selected(request('status') === $k2)>{{ $l }}</option>@endforeach</select>
  <label class="small"><input type="checkbox" name="reportable" value="1" @checked(request('reportable'))> notifiable only</label>
  <button class="btn btn-sm">Filter</button>
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="hseAccidentsTable"><thead><tr><th>Ref</th><th>When</th><th>Kind</th><th>Where</th><th>Person</th><th>Injury</th><th class="num">Days lost</th><th>Outcome</th><th>Status</th></tr></thead><tbody>
  @forelse($rows as $a)<tr class="{{ $a->reportable && ! $a->notified_on ? 'row-red' : '' }}">
    <td><a href="{{ route('hse.accident', $a) }}"><b>{{ $a->number }}</b></a></td><td class="small nowrap">{{ $a->occurred_at->format('d M Y H:i') }}</td>
    <td class="small">{{ $a->kindLabel() }}{{ $a->occurrence ? ' – '.\App\Models\HseAccident::OCCURRENCES[$a->occurrence] : '' }}</td><td class="small">{{ $a->location }}</td>
    <td class="small">{{ $a->personLabel() }}@if($a->person_type)<div class="tiny muted">{{ \App\Models\HseAccident::PERSONS[$a->person_type] }}</div>@endif</td>
    <td class="small">{{ $a->injury_type ? \App\Models\HseAccident::INJURIES[$a->injury_type] : '' }}{{ $a->body_part ? ' · '.\App\Models\HseAccident::BODY[$a->body_part] : '' }}</td>
    <td class="num">{{ $a->lost_days ?: '' }}</td><td>@include('hse._severity', ['a' => $a])</td><td class="small">{{ \App\Models\HseAccident::STATUSES[$a->status] }}</td></tr>
  @empty<tr><td colspan="9" class="muted">Nothing reported in this period.</td></tr>@endforelse
</tbody></table></div></div>
{{ $rows->links() }}
@if(\App\Services\HseService::canCheck(auth()->user()))@include('hse._accident_modal')@endif
@endsection
