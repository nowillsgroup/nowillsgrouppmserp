@extends('layouts.app')
@section('title', 'Guest Cases')
@section('crumbs') Guests &amp; CRM / Guest cases @endsection
@section('actions')
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'feedback_cases') }}"><x-icon name="chart"/> Case register</a>@endif
<a class="btn btn-primary" href="#" data-modal="newCase" id="newCaseBtn"><x-icon name="plus"/> New case</a>
@endsection
@section('content')
@include('reputation._nav')
<div class="stats">
  <x-stat label="Open cases" :value="$counts['open']" icon="shield" :tone="$counts['overdue'] ? 'red' : 'amber'" :sub="$counts['overdue'].' past their SLA · '.$counts['mine'].' mine'"/>
  <x-stat label="Resolved within SLA" :value="$kpi['sla'] === null ? '—' : $kpi['sla'].'%'" icon="clock" tone="green" :sub="$kpi['resolved'].' resolved · last 90 days'"/>
  <x-stat label="Average time to resolve" :value="$kpi['hours'] === null ? '—' : $kpi['hours'].' h'" icon="clock" tone="blue" sub="from opening to resolution"/>
  <x-stat label="Guests recovered" :value="$kpi['recovered'] === null ? '—' : $kpi['recovered'].'%'" icon="star" tone="purple" sub="of closed cases with a guest reply"/>
</div>
<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\CaseController::TABS as $k => $l)<a href="{{ route('rep.cases.index', array_filter(['tab' => $k, 'dept' => request('dept'), 'severity' => request('severity')])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $l }} <span class="muted">{{ $counts[$k] }}</span></a>@endforeach
  </div>
  <form class="filters" style="margin:0"><input type="hidden" name="tab" value="{{ $tab }}">
    <select name="dept" onchange="this.form.submit()"><option value="">All departments</option>@foreach(\App\Services\FeedbackService::DEPTS as $k => $l)<option value="{{ $k }}" @selected(request('dept') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="severity" onchange="this.form.submit()"><option value="">Any severity</option>@foreach(\App\Models\FeedbackCase::SEVERITIES as $k => $l)<option value="{{ $k }}" @selected(request('severity') === $k)>{{ $l }}</option>@endforeach</select>
    <input name="q" value="{{ request('q') }}" placeholder="Case no. or title"></form></div>
  <div class="card-body table-wrap"><table class="table" id="caseTable">
    <thead><tr><th>Case</th><th>Severity</th><th>Guest / issue</th><th>Department</th><th>Source</th><th>Owner</th><th>Due</th><th>Status</th></tr></thead><tbody>
    @forelse($items as $c)
    <tr class="{{ $c->isOverdue() ? 'row-alert' : '' }}"><td class="nowrap"><a href="{{ route('rep.cases.show', $c) }}" class="bold">{{ $c->case_no }}</a><div class="tiny muted">{{ $c->created_at->format('d M H:i') }}</div></td>
      <td>@include('reputation._sev', ['s' => $c->severity])@if($c->in_house) <span class="badge badge-purple">in-house</span>@endif</td>
      <td class="small" style="max-width:380px">{{ $c->title }}@if($c->guest)<div class="tiny muted">{{ $c->guest->full_name }}</div>@endif</td>
      <td class="small">{{ \App\Services\FeedbackService::DEPTS[$c->department] ?? $c->department }}</td>
      <td class="small">{{ ['feedback' => 'Feedback', 'review' => 'Online review', 'manual' => 'Staff'][$c->source] ?? $c->source }}</td>
      <td class="small">{{ $c->assignee?->name ?? '—' }}</td>
      <td class="small nowrap {{ $c->isOverdue() ? 'text-red bold' : '' }}">{{ $c->isActive() ? $c->due_at->format('d M H:i') : ($c->resolved_at ? 'done '.$c->resolved_at->format('d M') : '') }}@if($c->escalated_at) <span class="badge badge-red">escalated</span>@endif</td>
      <td>{!! status_badge($c->status) !!}@if($c->outcome)<div class="tiny muted">{{ \App\Models\FeedbackCase::OUTCOMES[$c->outcome] }}</div>@endif</td></tr>
    @empty<tr><td colspan="8"><x-empty text="No cases here." icon="shield"/></td></tr>@endforelse
  </tbody></table>{{ $items->links() }}</div>
</div>

<div class="modal" id="newCase"><div class="modal-box"><form method="post" action="{{ route('rep.cases.store') }}">@csrf
  <div class="modal-head"><h3>New guest case</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="title" label="What happened?" required class="span-all" placeholder="e.g. Guest in 305 unhappy with noise from the bar"/>
    <x-field name="details" label="Details" type="textarea" class="span-all"/>
    <x-field name="department" label="Department" type="select" :options="\App\Services\FeedbackService::DEPTS" value="front_office" required/>
    <x-field name="severity" label="Severity" type="select" :options="\App\Models\FeedbackCase::SEVERITIES" value="medium" required/>
    <x-field name="reservation_no" label="Reservation no." placeholder="RES-00012 (optional)" hint="links the guest; in-house stays get the short SLA"/>
    <x-field name="assigned_to" label="Owner" type="select" :options="['' => 'Department owner (settings)'] + $staff->all()"/>
    <label class="check span-all"><input type="checkbox" name="in_house" value="1"> The guest is still in the hotel</label>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveCase">Open case</button></div>
</form></div></div>
@endsection
