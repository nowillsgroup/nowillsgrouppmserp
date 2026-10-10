@extends('layouts.app')
@section('title', 'Incident '.$i->incident_no)
@section('crumbs') Security / <a href="{{ route('security.incidents') }}">Incident register</a> / {{ $i->incident_no }} @endsection
@section('actions')
  @if($i->status !== 'closed' && can_do('security.incidents'))<button class="btn" data-modal="editIncidentModal" id="editIncidentBtn"><x-icon name="edit"/> Edit details</button>@endif
  @if(can_do('security.manage'))
    @if($i->status !== 'closed')<button class="btn btn-success" data-modal="closeIncidentModal" id="closeIncidentBtn"><x-icon name="check"/> Close incident</button>
    @else<button class="btn" data-modal="reopenIncidentModal" id="reopenIncidentBtn">Reopen</button>@endif
  @endif
@endsection
@section('content')
<div class="grid g-2-1" style="gap:1.2rem">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $i->title }}</h3><div><span class="badge badge-{{ $i->severityTone() }}">{{ $i->severityLabel() }}</span> <span class="badge badge-{{ $i->statusTone() }}" id="incStatus">{{ $i->statusLabel() }}</span></div></div>
      <div class="card-body">
        <dl class="kv">
          <dt>Incident</dt><dd class="mono">{{ $i->incident_no }} · {{ $i->typeLabel() }}</dd>
          <dt>When</dt><dd>{{ $i->occurred_at->format('D d M Y, H:i') }} <span class="tiny muted">(recorded {{ $i->created_at->format('d M H:i') }} by {{ $i->creator?->name ?? 'system' }})</span></dd>
          <dt>Where</dt><dd>{{ $i->location }}{{ $i->room ? ' · room '.$i->room->room_no : '' }}</dd>
          @if($i->guest)<dt>Guest</dt><dd>{{ $i->guest->full_name }}@if($i->stay?->reservation) · <a href="{{ route('reservations.show', $i->stay->reservation) }}">{{ $i->stay->reservation->reservation_no }}</a>@endif</dd>@endif
          <dt>Injuries</dt><dd>{{ $i->injuries ? 'Yes' : 'No' }}</dd>
          @if($i->loss_amount)<dt>Loss / damage</dt><dd>{{ money($i->loss_amount) }}</dd>@endif
          <dt>Police</dt><dd>@if($i->police_informed)Informed{{ $i->police_station ? ' – '.$i->police_station : '' }}{{ $i->police_ref ? ' · ref '.$i->police_ref : '' }}@else Not informed @endif</dd>
          <dt>Officer</dt><dd>{{ $i->assignee?->name ?? 'Unassigned' }}</dd>
        </dl>
        <h4 class="mt-2 mb-1">What happened</h4><p style="white-space:pre-line" id="incDescription">{{ $i->description }}</p>
        @if($i->persons_involved)<h4 class="mb-1">Persons involved</h4><p style="white-space:pre-line">{{ $i->persons_involved }}</p>@endif
        @if($i->action_taken)<h4 class="mb-1">Action taken</h4><p style="white-space:pre-line">{{ $i->action_taken }}</p>@endif
        @if($i->resolution)<h4 class="mb-1">Resolution</h4><p style="white-space:pre-line" id="incResolution">{{ $i->resolution }}</p>@endif
      </div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Follow-up</h3></div><div class="card-body">
      <ul class="list small" id="incUpdates">
        @forelse($i->updates as $u)<li style="display:block"><div class="flex justify-between"><b>{{ $u->user?->name ?? 'System' }}</b><span class="muted">{{ $u->created_at->format('d M H:i') }}</span></div><div style="white-space:pre-line">{{ $u->note }}</div>@if($u->status_to)<span class="badge badge-{{ \App\Models\SecurityIncident::STATUSES[$u->status_to][1] ?? 'slate' }}">→ {{ \App\Models\SecurityIncident::STATUSES[$u->status_to][0] ?? $u->status_to }}</span>@endif</li>
        @empty<li class="muted">No follow-up notes yet.</li>@endforelse
      </ul>
      @if($i->status !== 'closed' && can_do('security.incidents'))
      <form method="post" action="{{ route('security.incidents.note', $i) }}" class="mt-1" id="incNoteForm">@csrf
        <x-field name="note" type="textarea" rows="3" label="Add a note" required placeholder="CCTV reviewed 14:00–15:00 – no one entered the room"/>
        <div class="flex gap-1 items-center justify-between"><select name="status" class="input" style="width:auto"><option value="">Keep status</option><option value="investigating">Mark investigating</option><option value="open">Back to open</option></select><button class="btn btn-primary" type="submit">Add note</button></div>
      </form>
      @endif
      @if($i->log_entry_id && can_do('logbook.view'))<p class="tiny muted mt-1 m-0">Posted to the log book for the duty manager.</p>@endif
    </div></div>
  </div>
</div>

@if($i->status !== 'closed' && can_do('security.incidents'))
<div class="modal" id="editIncidentModal"><div class="modal-box" style="max-width:880px"><form method="post" action="{{ route('security.incidents.update', $i) }}">@csrf
  <div class="modal-head"><h3>Edit {{ $i->incident_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">@include('security._incident_form', ['i' => $i])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Save</button></div>
</form></div></div>
@endif
@if(can_do('security.manage'))
<div class="modal" id="closeIncidentModal"><div class="modal-box" style="max-width:520px"><form method="post" action="{{ route('security.incidents.close', $i) }}">@csrf
  <div class="modal-head"><h3>Close {{ $i->incident_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="resolution" type="textarea" rows="4" label="Outcome / resolution" required placeholder="Laptop found in the guest's car – no theft. Guest apologised."/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button type="submit" class="btn btn-success">Close incident</button></div>
</form></div></div>
<div class="modal" id="reopenIncidentModal"><div class="modal-box" style="max-width:480px"><form method="post" action="{{ route('security.incidents.reopen', $i) }}">@csrf
  <div class="modal-head"><h3>Reopen {{ $i->incident_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Why" required maxlength="255"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button type="submit" class="btn btn-primary">Reopen</button></div>
</form></div></div>
@endif
@endsection
