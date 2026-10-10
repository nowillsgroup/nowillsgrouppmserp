@extends('layouts.app')
@section('title', 'Discipline & Grievances')
@section('crumbs') Talent Management / Discipline &amp; Grievances @endsection
@section('actions')
  <button class="btn" data-modal="talGrvModal" id="talGrvNew"><x-icon name="message"/> Log a grievance</button>
  <button class="btn btn-primary" data-modal="talCaseModal" id="talCaseNew"><x-icon name="alert"/> Open a disciplinary case</button>
@endsection
@section('content')
<div class="tabs" id="talCaseTabs">
  <a href="{{ route('talent.cases') }}" class="{{ ! $kind && $open ? 'active' : '' }}">Open</a>
  <a href="{{ route('talent.cases', ['kind' => 'discipline']) }}" class="{{ $kind === 'discipline' ? 'active' : '' }}">Discipline</a>
  <a href="{{ route('talent.cases', ['kind' => 'grievance']) }}" class="{{ $kind === 'grievance' ? 'active' : '' }}">Grievances</a>
  <a href="{{ route('talent.cases', ['show' => 'all']) }}" class="{{ ! $kind && ! $open ? 'active' : '' }}">All</a>
</div>
<div class="g-2-1">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="talCaseTable"><thead><tr><th>Case</th><th>Employee</th><th>Category</th><th>Incident</th><th>Status</th><th>Outcome</th></tr></thead><tbody>
  @forelse($rows as $c)@php $late = $c->status === 'query' && ! $c->replied_on && $c->reply_due?->toDateString() < $today; @endphp
  <tr class="{{ $late ? 'row-amber' : '' }}"><td><a href="{{ route('talent.case', $c) }}"><b>{{ $c->number }}</b></a>@if($c->confidential)<div class="tiny text-red">confidential</div>@endif</td>
    <td>{{ $c->employee->name }}<div class="tiny muted">{{ $c->employee->department?->name }}</div></td>
    <td class="small">{{ $c->categoryLabel() }}@if($c->severity)<div class="tiny muted">{{ \App\Models\TalentCase::SEVERITY[$c->severity] }}</div>@endif</td><td class="small nowrap">{{ $c->incident_on->format('d M Y') }}</td>
    <td>@include('talent._badge', ['s' => $c->status, 'l' => $c->statusLabel()])@if($late)<div class="tiny text-red">reply overdue</div>@endif</td><td class="small">{{ $c->outcomeLabel() ?? '—' }}</td></tr>
  @empty<tr><td colspan="6" class="muted">No cases.</td></tr>@endforelse
  </tbody></table></div></div>
  <div class="card flush"><div class="card-head"><h3>Warnings on file</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="talWarnings"><thead><tr><th>Employee</th><th>Warning</th><th>Expires</th></tr></thead><tbody>
    @forelse($warnings as $w)<tr><td class="small">{{ $w->employee->name }}</td><td class="small">{{ $w->outcomeLabel() }}</td><td class="small nowrap">{{ $w->expires_on->format('d M Y') }}</td></tr>
    @empty<tr><td colspan="3" class="muted small">No live warnings.</td></tr>@endforelse
  </tbody></table></div></div>
</div>
<div class="modal" id="talCaseModal"><div class="modal-box"><form method="post" action="{{ route('talent.cases.store') }}" data-reset>@csrf<input type="hidden" name="kind" value="discipline">
  <div class="modal-head"><h3>Open a disciplinary case</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="employee_id" label="Employee" type="select" :options="$employees->mapWithKeys(fn($e) => [$e->id => $e->name.' – '.$e->designation])" required class="span-all"/>
    <x-field name="category" label="Alleged misconduct" type="select" :options="\App\Models\TalentCase::DISCIPLINE" required/>
    <x-field name="severity" label="Seriousness" type="select" :options="\App\Models\TalentCase::SEVERITY" required/>
    <x-field name="incident_on" label="Date of the incident" type="date" :value="bdate()->toDateString()" required/>
    <x-field name="description" label="What happened (facts, witnesses, evidence)" type="textarea" required class="span-all"/>
  </div><p class="tiny muted">The employee must be told the allegation in writing (query) and heard before any sanction. Dismissal is only for proven misconduct (Labour Act s. 62).</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Open case</button></div>
</form></div></div>
<div class="modal" id="talGrvModal"><div class="modal-box"><form method="post" action="{{ route('talent.cases.store') }}" data-reset>@csrf<input type="hidden" name="kind" value="grievance">
  <div class="modal-head"><h3>Log a grievance</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="employee_id" label="Raised by (employee)" type="select" :options="$employees->pluck('name', 'id')" required class="span-all"/>
    <x-field name="category" label="About" type="select" :options="\App\Models\TalentCase::GRIEVANCE" required/>
    <x-field name="incident_on" label="Date raised" type="date" :value="bdate()->toDateString()" required/>
    <x-field name="description" label="The grievance" type="textarea" required class="span-all"/>
    <label class="check span-all small"><input type="checkbox" name="confidential" value="1"> Confidential – visible only to the person logging it, HR management and approvers (always for harassment, bullying and discrimination)</label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Log grievance</button></div>
</form></div></div>
@endsection
