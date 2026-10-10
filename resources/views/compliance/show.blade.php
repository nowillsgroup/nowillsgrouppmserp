@extends('layouts.app')
@section('title', $i->name)
@section('crumbs') <a href="{{ route('compliance.index') }}">Licences & Compliance</a> / {{ $i->name }} @endsection
@section('actions')
  @if(can_do('compliance.manage'))
    @if($i->status !== 'archived')
      @if($i->category === 'inspection')<button class="btn btn-primary" data-modal="inspectModal" id="inspectBtn"><x-icon name="check"/> Record inspection</button>
      @else
        @if($i->status !== 'renewing' && $i->expires_on)<button class="btn" data-modal="applyModal" id="applyBtn"><x-icon name="clock"/> Renewal applied</button>@endif
        <button class="btn btn-primary" data-modal="renewModal" id="renewBtn"><x-icon name="refresh"/> Record renewal</button>
      @endif
    @endif
    <button class="btn" data-modal="editModal" id="editBtn"><x-icon name="edit"/> Edit</button>
  @endif
@endsection
@section('content')
@php $st = $i->state(); $dl = $i->daysLeft(); @endphp
<div class="grid g-1-2">
  <div class="card">
    <div class="card-head"><h3>{{ $i->categoryLabel() }}</h3><span class="badge badge-{{ $i->stateColor() }}" id="compState">{{ $i->stateLabel() }}</span></div>
    <div class="card-body">
      @if($dl !== null && $st !== 'archived')
        <div style="font-size:2rem;font-weight:700" class="{{ $dl < 0 ? 'text-red' : ($dl <= $i->lead_days ? 'text-amber' : 'text-green') }}" id="compDays">{{ $dl < 0 ? abs($dl).' days overdue' : $dl.' days left' }}</div>
        <div class="muted small" style="margin-bottom:.8rem">{{ $i->category === 'inspection' ? 'next due' : 'valid until' }} {{ $i->expires_on->format('l, d M Y') }} · reminder {{ $i->lead_days }} days before</div>
      @endif
      <dl class="kv" id="compInfo">
        <dt>Authority</dt><dd>{{ $i->authority ?: '—' }}</dd>
        <dt>Reference no.</dt><dd class="mono">{{ $i->reference_no ?: '—' }}</dd>
        <dt>Department</dt><dd>{{ $i->department ?: '—' }}</dd>
        <dt>Responsible</dt><dd>{{ $i->owner?->name ?? '—' }}</dd>
        <dt>{{ $i->category === 'inspection' ? 'Last done' : 'Issued / renewed' }}</dt><dd>{{ $i->issued_on?->format('d M Y') ?? '—' }}</dd>
        <dt>{{ $i->category === 'inspection' ? 'Next due' : 'Valid until' }}</dt><dd>{{ $i->expires_on?->format('d M Y') ?? 'No expiry' }}</dd>
        @if($i->isRecurring())<dt>Frequency</dt><dd>Every {{ $i->frequency_months }} month(s)</dd>@endif
        <dt>Fee / premium</dt><dd>{{ $i->fee ? money($i->fee) : '—' }}</dd>
        <dt>Critical</dt><dd>{{ $i->is_critical ? 'Yes – cannot operate without it' : 'No' }}</dd>
        @if($i->status === 'renewing')<dt>Renewal applied</dt><dd>{{ $i->applied_on?->format('d M Y') }}@if($i->application_ref) · ref <span class="mono">{{ $i->application_ref }}</span>@endif</dd>@endif
        <dt>Current document</dt><dd>@if($i->document_path)<a href="{{ route('compliance.document', $i) }}" target="_blank" id="docLink"><x-icon name="download"/> {{ $i->document_name }}</a>@else<span class="muted">none uploaded</span>@endif</dd>
        @if($i->notes)<dt>Notes</dt><dd>{{ $i->notes }}</dd>@endif
      </dl>
    </div>
    @if(can_do('compliance.manage'))
    <div class="card-body" style="border-top:1px solid var(--c-border)">
      <form method="post" action="{{ route('compliance.upload', $i) }}" enctype="multipart/form-data" class="flex" style="gap:.4rem;flex-wrap:wrap" id="uploadForm">@csrf
        <input type="file" name="file" accept=".pdf,image/*" required style="max-width:230px"><input class="input" name="note" placeholder="Note" style="max-width:160px"><button class="btn btn-sm"><x-icon name="download"/> Upload</button></form>
      <form method="post" action="{{ route('compliance.archive', $i) }}" style="margin-top:.6rem" data-confirm="{{ $i->status === 'archived' ? 'Restore this item to the register?' : 'Archive this item? It will no longer be tracked or reminded.' }}">@csrf
        <button class="btn btn-sm btn-ghost" id="archiveBtn">{{ $i->status === 'archived' ? 'Restore to register' : 'Archive (no longer required)' }}</button></form>
    </div>
    @endif
  </div>

  <div class="card flush">
    <div class="card-head"><h3><x-icon name="clock"/> History</h3></div>
    <div class="table-wrap"><table class="table" id="eventTable">
      <thead><tr><th>Date</th><th>Event</th><th>Details</th><th class="right">Fee</th><th>By</th></tr></thead>
      <tbody>
      @forelse($i->events as $e)
        <tr>
          <td class="nowrap">{{ $e->event_date->format('d M Y') }}</td>
          <td><b>{{ \App\Models\ComplianceEvent::TYPES[$e->type] ?? $e->type }}</b>@if($e->result)<span class="badge badge-{{ $e->result === 'pass' ? 'green' : 'red' }}">{{ strtoupper($e->result) }}</span>@endif</td>
          <td class="small">@if($e->new_expiry)valid until / next due <b>{{ $e->new_expiry->format('d M Y') }}</b>@endif @if($e->reference){{ $e->new_expiry ? ' · ' : '' }}ref <span class="mono">{{ $e->reference }}</span>@endif
            @if($e->note)<div>{{ $e->note }}</div>@endif @if($e->document_path)<div><a href="{{ route('compliance.event.document', $e) }}" target="_blank"><x-icon name="download"/> {{ $e->document_name }}</a></div>@endif</td>
          <td class="right">{{ $e->fee ? money($e->fee) : '' }}</td>
          <td class="small">{{ $e->user?->name ?? 'System' }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="muted center">No history.</td></tr>
      @endforelse
      </tbody>
    </table></div>
  </div>
</div>

@if(can_do('compliance.manage'))
<div class="modal" id="editModal"><div class="modal-box" style="max-width:760px"><form method="post" action="{{ route('compliance.update', $i) }}">@csrf
  <div class="modal-head"><h3>Edit {{ $i->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">@include('compliance._form', ['i' => $i])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Save</button></div>
</form></div></div>

<div class="modal" id="applyModal"><div class="modal-box" style="max-width:480px"><form method="post" action="{{ route('compliance.apply', $i) }}">@csrf
  <div class="modal-head"><h3>Renewal application submitted</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="applied_on" label="Applied on" type="date" required :value="bdate()->toDateString()"/>
    <x-field name="reference" label="Application / acknowledgement no." maxlength="80"/>
    <x-field name="fee" label="Fee paid" type="number" step="0.01" min="0" :value="$i->fee"/>
    <x-field name="note" label="Note" class="span-2" maxlength="500"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Save</button></div>
</form></div></div>

<div class="modal" id="renewModal"><div class="modal-box" style="max-width:520px"><form method="post" action="{{ route('compliance.renew', $i) }}" enctype="multipart/form-data">@csrf
  <div class="modal-head"><h3>Record renewal</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="renewed_on" label="Renewed / issued on" type="date" required :value="bdate()->toDateString()"/>
    <x-field name="new_expiry" label="New expiry" type="date" :value="$i->expires_on ? $i->expires_on->copy()->addYear()->toDateString() : ''"/>
    <x-field name="reference_no" label="New licence / policy no." :value="$i->reference_no" maxlength="80"/>
    <x-field name="fee" label="Fee / premium" type="number" step="0.01" min="0" :value="$i->fee"/>
    <x-field name="file" label="Renewed certificate (PDF / image)" type="file" accept=".pdf,image/*" class="span-2"/>
    <x-field name="note" label="Note" class="span-2" maxlength="500"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-success">Save renewal</button></div>
</form></div></div>

<div class="modal" id="inspectModal"><div class="modal-box" style="max-width:520px"><form method="post" action="{{ route('compliance.inspect', $i) }}" enctype="multipart/form-data">@csrf
  <div class="modal-head"><h3>Record inspection / test</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="done_on" label="Done on" type="date" required :value="bdate()->toDateString()"/>
    <x-field name="result" label="Result" type="select" :options="['pass' => 'Pass / satisfactory', 'fail' => 'Fail – action needed']" required :placeholder="false"/>
    <x-field name="next_due" label="Next due" type="date" :hint="$i->frequency_months ? 'Default: '.$i->frequency_months.' month(s) after the inspection' : null"/>
    <x-field name="reference" label="Report / certificate no." maxlength="80"/>
    <x-field name="fee" label="Cost" type="number" step="0.01" min="0" :value="$i->fee"/>
    <x-field name="file" label="Report (PDF / image)" type="file" accept=".pdf,image/*"/>
    <x-field name="note" label="Findings" type="textarea" rows="2" class="span-2" maxlength="500"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-success">Save</button></div>
</form></div></div>
@endif
@endsection
