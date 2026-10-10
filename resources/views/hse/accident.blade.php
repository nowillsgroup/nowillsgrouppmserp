@extends('layouts.app')
@section('title', $a->number.' · '.$a->kindLabel())
@section('crumbs') Health &amp; Safety / <a href="{{ route('hse.accidents') }}">Accidents</a> / {{ $a->number }} @endsection
@php $canMg = \App\Services\HseService::canManage(auth()->user()); $open = $a->status !== 'closed'; @endphp
@section('actions')
  @if($a->reportable && $canMg)<a class="btn" href="{{ route('hse.accident.notice', $a) }}?autoprint=1" target="_blank" id="hseNoticeBtn"><x-icon name="print"/> Inspectorate notice</a>@endif
  @if($canMg && $open)<form method="post" action="{{ route('hse.accidents.close', $a) }}" class="inline" data-confirm="Close {{ $a->number }}?">@csrf<button class="btn btn-primary" id="hseCloseAcc"><x-icon name="check"/> Close</button></form>@endif
@endsection
@section('content')
@if($a->reportable && ! $a->notified_on)<div class="alert alert-error" id="hseNotifyAlert"><x-icon name="alert"/><div><b>Notifiable.</b> {{ $a->kind === 'dangerous' ? 'Every dangerous occurrence (s. 11)' : ($a->severity === 'fatal' ? 'A death (s. 10)' : 'An injury disabling a person employed here for more than three days (s. 10)') }} must be notified in writing, forthwith, to the {{ $cfg['inspectorate'] }}. Print the notice, send it, then record the date below.</div></div>@endif
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $a->kindLabel() }}{{ $a->occurrence ? ' – '.\App\Models\HseAccident::OCCURRENCES[$a->occurrence] : '' }}</h3><span id="hseAccStatus">@include('hse._severity', ['a' => $a]) <span class="badge badge-slate">{{ \App\Models\HseAccident::STATUSES[$a->status] }}</span></span></div>
      <div class="card-body"><div class="kv-grid">
        <div><span>When</span>{{ $a->occurred_at->format('D d M Y H:i') }}</div><div><span>Where</span>{{ $a->location }}{{ $a->room ? ' · room '.$a->room->room_no : '' }}</div>
        <div><span>Person</span>{{ $a->personLabel() }}{{ $a->person_type ? ' ('.\App\Models\HseAccident::PERSONS[$a->person_type].')' : '' }}{{ $a->employee?->department ? ' · '.$a->employee->department->name : '' }}</div>
        @if($a->injury_type)<div><span>Injury</span>{{ \App\Models\HseAccident::INJURIES[$a->injury_type] }} · {{ \App\Models\HseAccident::BODY[$a->body_part] ?? '' }}</div>@endif
        <div><span>Days lost</span>{{ $a->lost_days }}{{ $a->returned_on ? ' · back at work '.$a->returned_on->format('d M Y') : '' }}</div>
        @if($a->hospital)<div><span>Hospital / clinic</span>{{ $a->hospital }}</div>@endif
        @if($a->witnesses)<div><span>Witnesses</span>{{ $a->witnesses }}</div>@endif
        @if($a->incident)<div><span>Security incident</span>{{ $a->incident->incident_no }} · {{ $a->incident->title }}</div>@endif
        <div><span>Reported by</span>{{ $a->reporter?->name }} · {{ $a->created_at->format('d M H:i') }}</div>
        <div><span>Act 328 notice</span>{{ $a->reportable ? ($a->notified_on ? 'sent '.$a->notified_on->format('d M Y').($a->notified_ref ? ' · ref '.$a->notified_ref : '') : 'REQUIRED – not yet sent') : 'not notifiable' }}</div>
      </div>
      <h4 class="mt-1">What happened</h4><p class="small" style="white-space:pre-line">{{ $a->description }}</p>
      @if($a->first_aid)<h4>Immediate action</h4><p class="small">{{ $a->first_aid }}</p>@endif
      @if($a->root_cause)<h4>Root cause</h4><p class="small" style="white-space:pre-line">{{ $a->root_cause }}</p>@endif
      @if($a->actions)<h4>Corrective actions</h4><p class="small" style="white-space:pre-line">{{ $a->actions }}</p>@endif
      @if($a->ticket)<p class="small">Engineering ticket <b>{{ $a->ticket->ticket_no }}</b> · {{ $a->ticket->status }}</p>@endif
    </div></div>
  </div>
  <div>
    @if($canMg && $open)
      @if($a->reportable)<div class="card"><div class="card-head"><h3>Statutory notice</h3></div><div class="card-body"><form method="post" action="{{ route('hse.accidents.notify', $a) }}" id="hseNotifyForm">@csrf
        <div class="field"><label>Notice sent on</label><input type="date" name="notified_on" value="{{ $a->notified_on?->toDateString() ?? bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}" required></div>
        <div class="field"><label>Reference / receipt</label><input name="notified_ref" maxlength="60" value="{{ $a->notified_ref }}"></div>
        <button class="btn btn-primary">Record notice</button></form></div></div>@endif
      @if($a->kind !== 'near_miss')<div class="card"><div class="card-head"><h3>Outcome &amp; lost time</h3></div><div class="card-body"><form method="post" action="{{ route('hse.accidents.update', $a) }}" id="hseOutcomeForm">@csrf
        <div class="field"><label>Outcome</label><select name="severity">@foreach(\App\Models\HseAccident::SEVERITIES as $k => $l)<option value="{{ $k }}" @selected($a->severity === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="field"><label>Days lost</label><input type="number" name="lost_days" min="0" max="3650" value="{{ $a->lost_days }}"></div>
        <div class="field"><label>Back at work on</label><input type="date" name="returned_on" value="{{ $a->returned_on?->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
        <div class="field"><label>Hospital / clinic</label><input name="hospital" maxlength="120" value="{{ $a->hospital }}"></div>
        <button class="btn">Update</button></form></div></div>@endif
      <div class="card"><div class="card-head"><h3>Investigation</h3></div><div class="card-body"><form method="post" action="{{ route('hse.accidents.investigate', $a) }}" id="hseInvestigateForm">@csrf
        <div class="field"><label>Root cause</label><textarea name="root_cause" rows="3" required>{{ $a->root_cause }}</textarea></div>
        <div class="field"><label>Corrective actions</label><textarea name="actions" rows="3">{{ $a->actions }}</textarea></div>
        @if(! $a->maintenance_request_id)<label class="hse-item"><input type="checkbox" name="raise_ticket" value="1"> Raise an engineering ticket for the fix</label>@endif
        <button class="btn">Save investigation</button></form></div></div>
    @endif
  </div>
</div>
@endsection
