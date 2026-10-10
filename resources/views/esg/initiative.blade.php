@extends('layouts.app')
@section('title', 'Initiative '.$i->number)
@section('crumbs') Sustainability &amp; ESG / <a href="{{ route('esg.initiatives') }}">Initiatives</a> / {{ $i->number }} @endsection
@php $u = auth()->user(); $ES = \App\Services\EsgService::class; $canMg = $ES::canManage($u); $canRec = $ES::canRecord($u); $open = in_array($i->status, ['idea', 'approved', 'in_progress'], true);
  $mayDecide = $canMg && $i->status === 'idea' && (! $ES::cfg()['four_eyes'] || $i->raised_by !== $u->id); @endphp
@section('actions')
  @if($open && $canRec && ($i->status === 'idea' || $canMg))<button class="btn" data-modal="esgInitEdit" id="esgInitEditBtn"><x-icon name="edit"/> Edit</button>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $i->title }}</h3><span class="badge badge-{{ $i->statusTone() }}" id="esgInitStatus">{{ $i->statusLabel() }}</span></div><div class="card-body">
      <dl class="kv"><dt>Category</dt><dd>{{ \App\Models\EsgInitiative::CATEGORIES[$i->category] }}</dd><dt>Owner</dt><dd>{{ $i->owner?->name ?? '—' }}</dd>
        <dt>Raised by</dt><dd>{{ $i->raiser?->name ?? '—' }} · {{ $i->created_at->format('d M Y') }}</dd>
        @if($i->approver)<dt>{{ $i->status === 'rejected' ? 'Rejected' : 'Approved' }} by</dt><dd>{{ $i->approver->name }} · {{ $i->approved_at?->format('d M Y H:i') }}</dd>@endif
        @if($i->decision_note)<dt>Decision note</dt><dd>{{ $i->decision_note }}</dd>@endif
        <dt>Start / due</dt><dd>@if($i->start_on || $i->due_on){{ $i->start_on?->format('d M Y') ?? '—' }} → {{ $i->due_on?->format('d M Y') ?? '—' }}@else<span class="muted">not scheduled</span>@endif @if($i->isLate()) <span class="badge badge-red">overdue</span>@endif</dd>
        @if($i->completed_on)<dt>Completed</dt><dd>{{ $i->completed_on->format('d M Y') }}</dd><dt>Outcome</dt><dd>{{ $i->outcome }}</dd>@endif
      </dl>
      @if($i->description)<p class="small" style="white-space:pre-line;margin-top:.8rem">{{ $i->description }}</p>@endif
    </div></div>
    @if($open)
    <div class="card"><div class="card-head"><h3>Next step</h3></div><div class="card-body" id="esgInitActions">
      @if($i->status === 'idea')
        @if($mayDecide)
          <form method="post" action="{{ route('esg.initiatives.move', $i) }}" class="flex gap-1 wrap" style="align-items:flex-end">@csrf
            <div class="field" style="flex:1;min-width:220px"><label>Decision note (required to reject)</label><input name="note" maxlength="255"></div>
            <button class="btn btn-primary" name="action" value="approve" id="esgApprove">Approve</button><button class="btn btn-danger" name="action" value="reject" id="esgReject">Reject</button></form>
        @elseif($canMg)<p class="small muted m-0">You proposed this initiative – another ESG manager must approve or reject it.</p>
        @else<p class="small muted m-0">Waiting for an ESG manager to approve it.</p>@endif
      @endif
      @if($i->status === 'approved' && $canRec)<form method="post" action="{{ route('esg.initiatives.move', $i) }}" class="mb-1">@csrf<button class="btn btn-primary" name="action" value="start" id="esgStart">Start work</button></form>@endif
      @if(in_array($i->status, ['approved', 'in_progress'], true) && ($canMg || $i->owner_user_id === $u->id))
        <form method="post" action="{{ route('esg.initiatives.move', $i) }}" class="form-grid mt-1" id="esgCompleteForm">@csrf<input type="hidden" name="action" value="complete">
          <div class="field"><label>Completed on</label><input type="date" name="completed_on" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
          <div class="field span-all"><label>Outcome achieved</label><input name="outcome" maxlength="500" required placeholder="e.g. 240 LED lamps fitted; lighting kWh down 38 % on the sub-meter"></div>
          <div class="span-all"><button class="btn btn-primary">Mark completed</button></div></form>
      @endif
      @if($canMg)<form method="post" action="{{ route('esg.initiatives.move', $i) }}" class="flex gap-1 wrap mt-1" style="align-items:flex-end">@csrf<input type="hidden" name="action" value="cancel">
        <div class="field" style="flex:1;min-width:220px"><label>Reason for cancelling</label><input name="note" maxlength="255"></div><button class="btn">Cancel initiative</button></form>@endif
    </div></div>
    @endif
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Business case</h3></div><div class="card-body"><dl class="kv" id="esgCase">
      <dt>Capital cost</dt><dd>{{ money($i->capex) }}</dd><dt>Saving per year</dt><dd>{{ money($i->saving_ghs) }}</dd>
      <dt>Simple payback</dt><dd><b>{{ $i->payback() !== null ? $i->payback().' years' : '—' }}</b></dd>
      <dt>Energy</dt><dd>{{ number_format($i->saving_kwh) }} kWh / yr</dd><dt>Water</dt><dd>{{ number_format($i->saving_m3) }} m³ / yr</dd>
      <dt>Waste diverted</dt><dd>{{ number_format($i->saving_waste_kg) }} kg / yr</dd><dt>Carbon</dt><dd><b>{{ number_format($i->saving_co2_kg / 1000, 2) }} t CO₂e / yr</b></dd>
    </dl></div></div>
  </div>
</div>
@if($open && $canRec && ($i->status === 'idea' || $canMg))
<div class="modal" id="esgInitEdit"><div class="modal-box lg"><form method="post" action="{{ route('esg.initiatives.update', $i) }}">@csrf
  <div class="modal-head"><h3>Edit {{ $i->number }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body">@include('esg._initiative_fields', ['x' => $i])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endif
@endsection
