@extends('layouts.app')
@section('title', 'Incident Register')
@section('crumbs') Security / Incident register @endsection
@section('actions')
  @if(can_do('security.incidents'))<button class="btn btn-primary" data-modal="incidentModal" id="addIncidentBtn"><x-icon name="plus"/> Report incident</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Open" :value="$stats['open']" icon="alert" :tone="$stats['open'] ? 'red' : 'green'" :href="route('security.incidents', ['status' => 'open'])"/>
  <x-stat label="Under investigation" :value="$stats['investigating']" icon="search" tone="amber" :href="route('security.incidents', ['status' => 'investigating'])"/>
  <x-stat label="High / critical still open" :value="$stats['serious']" icon="shield" :tone="$stats['serious'] ? 'red' : 'green'"/>
  <x-stat label="Incidents this month" :value="$stats['month']" icon="chart" tone="blue" :sub="$stats['loss_month'] > 0 ? money($stats['loss_month']).' loss / damage' : 'no loss recorded'"/>
</div>
<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(['active' => 'Open & investigating', 'open' => 'Open', 'investigating' => 'Investigating', 'closed' => 'Closed', 'all' => 'All'] as $k => $l)<a href="{{ route('security.incidents', ['status' => $k] + array_filter(['type' => $type, 'severity' => $severity])) }}" class="{{ $status === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}</a>@endforeach
  </div>
  <form method="get" class="flex gap-1"><input type="hidden" name="status" value="{{ $status }}">
    <select name="type" class="input" style="width:auto" onchange="this.form.submit()"><option value="">All types</option>@foreach(\App\Models\SecurityIncident::TYPES as $k => $l)<option value="{{ $k }}" @selected($type === $k)>{{ $l }}</option>@endforeach</select>
    <select name="severity" class="input" style="width:auto" onchange="this.form.submit()"><option value="">Any severity</option>@foreach(\App\Models\SecurityIncident::SEVERITIES as $k => [$l])<option value="{{ $k }}" @selected($severity === $k)>{{ $l }}</option>@endforeach</select>
  </form></div>
  <div class="table-wrap"><table class="table" id="incidentTable"><thead><tr><th>Incident</th><th>What</th><th>Where / who</th><th>When</th><th>Officer</th><th>Status</th></tr></thead><tbody>
  @forelse($list as $x)
    <tr data-inc="{{ $x->incident_no }}"><td class="nowrap"><a class="mono" href="{{ route('security.incidents.show', $x) }}"><b>{{ $x->incident_no }}</b></a><div><span class="badge badge-{{ $x->severityTone() }}">{{ $x->severityLabel() }}</span></div></td>
      <td><a href="{{ route('security.incidents.show', $x) }}">{{ $x->title }}</a><div class="tiny muted">{{ $x->typeLabel() }}@if($x->injuries) · <span class="text-red">injury</span>@endif @if($x->police_informed) · police{{ $x->police_ref ? ' '.$x->police_ref : '' }}@endif</div></td>
      <td class="small">{{ $x->location }}{{ $x->room ? ' · room '.$x->room->room_no : '' }}@if($x->guest)<div class="tiny muted">{{ $x->guest->full_name }}</div>@endif</td>
      <td class="nowrap small">{{ $x->occurred_at->format('d M Y H:i') }}</td><td class="small">{{ $x->assignee?->name ?? '–' }}</td>
      <td><span class="badge badge-{{ $x->statusTone() }}">{{ $x->statusLabel() }}</span></td></tr>
  @empty<tr><td colspan="6"><x-empty icon="shield" text="No incidents here."/></td></tr>@endforelse
  </tbody></table></div>
  <div class="card-body pad">{{ $list->links() }}
    @if($stats['by_type']->isNotEmpty())<p class="small muted m-0">Last 90 days: @foreach($stats['by_type'] as $t => $n){{ \App\Models\SecurityIncident::TYPES[$t] ?? $t }} <b>{{ $n }}</b>@if(! $loop->last) · @endif @endforeach</p>@endif</div>
</div>

@if(can_do('security.incidents'))
<div class="modal" id="incidentModal"><div class="modal-box" style="max-width:880px"><form method="post" action="{{ route('security.incidents.store') }}" id="incidentForm">@csrf
  <div class="modal-head"><h3>Report a security incident</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">@include('security._incident_form', ['i' => null])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Record incident</button></div>
</form></div></div>
@if($errors->any() || (session('error') && old('title')))@push('scripts')<script>openModal('incidentModal');</script>@endpush @endif
@endif
@endsection
