@extends('layouts.app')
@section('title', 'Permit '.$p->number)
@section('crumbs') Health &amp; Safety / <a href="{{ route('hse.permits') }}">Permits to work</a> / {{ $p->number }} @endsection
@php $u = auth()->user(); $canIssue = \App\Services\HseService::canPermit($u); @endphp
@section('actions')
  <a class="btn" href="{{ route('hse.permit.print', $p) }}?autoprint=1" target="_blank"><x-icon name="print"/> Print permit</a>
  @if(in_array($p->status, ['requested', 'issued'], true) && ($canIssue || ($p->status === 'requested' && $p->requested_by === $u->id)))<button class="btn btn-danger" data-modal="hsePermitCancel">Cancel</button>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $p->typeLabel() }}</h3><span id="hsePermitStatus">@include('hse._permit_badge', ['p' => $p])</span></div><div class="card-body"><div class="kv-grid">
      <div><span>Location</span>{{ $p->location }}</div><div><span>Work</span>{{ $p->work }}</div>
      <div><span>Contractor / team</span>{{ $p->contractor ?? 'in-house' }} · {{ $p->workers }} worker(s)</div>
      <div><span>Period</span>{{ $p->starts_at->format('D d M H:i') }} – {{ $p->ends_at->format('D d M H:i') }}</div>
      <div><span>Requested by</span>{{ $p->requester?->name }} · {{ $p->created_at->format('d M H:i') }}</div>
      @if($p->issued_at)<div><span>Issued by</span>{{ $p->issuer?->name }} · {{ $p->issued_at->format('d M H:i') }}</div>@endif
      @if($p->fire_watch)<div><span>Fire watch</span>{{ $p->fire_watch }}</div>@endif
      @if($p->detectors_isolated)<div><span>Detectors isolated</span>{{ $p->detectors_isolated }}</div>@endif
      @if($p->gas_o2 !== null)<div><span>Gas test O₂</span>{{ number_format($p->gas_o2, 1) }} %</div>@endif
      @if($p->isolation_points)<div><span>Isolation points</span>{{ $p->isolation_points }}</div>@endif
      @if($p->ticket)<div><span>Engineering ticket</span>{{ $p->ticket->ticket_no }} · {{ $p->ticket->title }}</div>@endif
      @if($p->closed_at)<div><span>{{ $p->status === 'cancelled' ? 'Cancelled' : 'Closed out' }}</span>{{ $p->closer?->name }} · {{ $p->closed_at->format('d M H:i') }}{{ $p->close_note ? ' – '.$p->close_note : '' }}{{ $p->cancel_reason ? ' – '.$p->cancel_reason : '' }}</div>@endif
    </div></div></div>
    <div class="card"><div class="card-head"><h3>Precautions</h3></div><div class="card-body"><ul class="hse-prec">
      @foreach($p->precautionList() as $k => $l)<li class="{{ in_array($k, $p->precautions ?? [], true) ? 'ok' : '' }}">{{ $l }}</li>@endforeach
    </ul></div></div>
  </div>
  <div>
    @if($p->status === 'requested' && $canIssue)
    <div class="card"><div class="card-head"><h3>Issue the permit</h3></div><div class="card-body">
      <form method="post" action="{{ route('hse.permits.issue', $p) }}" id="hseIssueForm">@csrf
        <p class="tiny muted">Inspect the work area with the person in charge and confirm each precaution.</p>
        @foreach($p->precautionList() as $k => $l)<label class="hse-item"><input type="checkbox" name="precautions[]" value="{{ $k }}"> {{ $l }}</label>@endforeach
        @if($p->type === 'hot_work')<div class="field mt-1"><label>Fire watch (name)</label><input name="fire_watch" maxlength="80" required></div>
          <div class="field"><label>Smoke detectors isolated (zone) – leave blank if none</label><input name="detectors_isolated" maxlength="120" placeholder="Zone 4 – kitchen extract"></div>@endif
        @if($p->type === 'confined')<div class="field mt-1"><label>Gas test – oxygen %</label><input type="number" name="gas_o2" step="0.1" min="0" max="30" required></div>@endif
        @if($p->type === 'electrical')<div class="field mt-1"><label>Isolation points locked off</label><input name="isolation_points" maxlength="255" required placeholder="DB-2 breaker 14, MCC panel 3"></div>@endif
        <button class="btn btn-primary mt-1">Issue – work may start</button>
      </form></div></div>
    @elseif($p->status === 'issued' && $canIssue)
    <div class="card"><div class="card-head"><h3>Close out</h3></div><div class="card-body">
      <form method="post" action="{{ route('hse.permits.close', $p) }}" id="hseCloseForm">@csrf
        <label class="hse-item"><input type="checkbox" name="area_safe" value="1"> Work finished, area inspected, tools and waste removed, left safe</label>
        @if($p->type === 'hot_work')<label class="hse-item"><input type="checkbox" name="fire_watch_done" value="1"> Fire watch kept for 60 minutes after the hot work – no smouldering</label>@endif
        @if($p->detectors_isolated)<label class="hse-item"><input type="checkbox" name="detectors_restored" value="1"> Isolated detectors ({{ $p->detectors_isolated }}) back in service and the panel is normal</label>@endif
        <div class="field mt-1"><label>Note</label><input name="close_note" maxlength="255"></div>
        <button class="btn btn-primary mt-1">Close out permit</button>
      </form></div></div>
    @endif
  </div>
</div>
<div class="modal" id="hsePermitCancel"><div class="modal-box"><form method="post" action="{{ route('hse.permits.cancel', $p) }}">@csrf
  <div class="modal-head"><h3>Cancel {{ $p->number }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="reason" maxlength="190" required></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger">Cancel permit</button></div>
</form></div></div>
@endsection
