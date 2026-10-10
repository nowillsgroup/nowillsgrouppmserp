@extends('layouts.app')
@section('title', $x->number.' · '.$x->typeLabel())
@section('crumbs') Health &amp; Safety / <a href="{{ route('hse.drills') }}">Drills</a> / {{ $x->number }} @endsection
@php $u = auth()->user(); $canMg = \App\Services\HseService::canManage($u); $canRec = $canMg || $x->led_by === $u->id; @endphp
@section('actions')@if($canMg && $x->status === 'planned')<button class="btn btn-danger" data-modal="hseDrillCancel">Cancel drill</button>@endif @endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $x->typeLabel() }} · {{ ucfirst($x->shift) }} shift</h3><span id="hseDrillResult">@if($x->status === 'done')<span class="badge badge-{{ $x->result === 'pass' ? 'green' : 'red' }}">{{ strtoupper($x->result) }}</span>@else<span class="badge badge-slate">{{ ucfirst($x->status) }}</span>@endif</span></div><div class="card-body">
      <div class="kv-grid"><div><span>Scheduled</span>{{ $x->scheduled_at->format('D d M Y H:i') }}{{ $x->announced ? ' · announced' : ' · unannounced' }}</div><div><span>Led by</span>{{ $x->leader?->name }}</div>
        <div><span>Assembly point</span>{{ $x->assembly_point ?? '—' }}</div><div><span>Target</span>{{ intdiv($x->target_seconds, 60) }} min {{ $x->target_seconds % 60 ? ($x->target_seconds % 60).' s' : '' }}</div>
        @if($x->status === 'done' && $x->alarm_at)<div><span>Alarm</span>{{ $x->alarm_at->format('H:i:s') }}</div><div><span>Building clear</span>{{ $x->clear_at->format('H:i:s') }} · <b>{{ $x->evacLabel() }}</b></div>
          <div><span>Roll call</span>{{ $x->staff_accounted }} / {{ $x->staff_expected }} staff{{ $x->staff_accounted < $x->staff_expected ? ' – '.($x->staff_expected - $x->staff_accounted).' unaccounted' : '' }}</div>
          <div><span>Guests evacuated</span>{{ $x->guests_evacuated ?? '—' }}</div><div><span>GNFS attended</span>{{ $x->fire_service_attended ? 'yes' : 'no' }}</div>@endif
        @if($x->cancel_reason)<div><span>Cancelled</span>{{ $x->cancel_reason }}</div>@endif</div>
      @if($x->scenario)<h4 class="mt-1">Scenario</h4><p class="small">{{ $x->scenario }}</p>@endif
      @if($x->observations)<h4>Observations</h4><p class="small" style="white-space:pre-line">{{ $x->observations }}</p>@endif
      @if($x->improvements)<h4>Improvements</h4><p class="small" style="white-space:pre-line">{{ $x->improvements }}</p>@endif
      @if($x->staff->count())<h4>Staff who took part ({{ $x->staff->count() }})</h4><p class="small">{{ $x->staff->pluck('name')->implode(', ') }}</p>@endif
    </div></div>
  </div>
  <div>
    @if($x->status === 'planned' && $canRec)<div class="card"><div class="card-head"><h3>Record the drill</h3></div><div class="card-body"><form method="post" action="{{ route('hse.drills.record', $x) }}" id="hseDrillForm">@csrf
      <div class="field"><label>Alarm sounded</label><input type="datetime-local" step="1" name="alarm_at" value="{{ $x->scheduled_at->format('Y-m-d\TH:i:s') }}" required></div>
      <div class="field"><label>Building clear</label><input type="datetime-local" step="1" name="clear_at" value="{{ $x->scheduled_at->copy()->addMinutes(4)->format('Y-m-d\TH:i:s') }}" required></div>
      <div class="field"><label>Staff on duty (roll-call list)</label><input type="number" name="staff_expected" min="1" required></div>
      <div class="field"><label>Staff accounted for</label><input type="number" name="staff_accounted" min="0" required></div>
      <div class="field"><label>Guests evacuated</label><input type="number" name="guests_evacuated" min="0"></div>
      <label class="hse-item"><input type="checkbox" name="fire_service_attended" value="1"> Ghana National Fire Service attended</label>
      <div class="field"><label>Observations</label><textarea name="observations" rows="3" required></textarea></div>
      <div class="field"><label>Improvements</label><textarea name="improvements" rows="2"></textarea></div>
      <div class="field"><label>Staff who took part</label><select name="staff[]" multiple size="6">@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></div>
      <button class="btn btn-primary">Save result</button></form></div></div>@endif
  </div>
</div>
@if($canMg)<div class="modal" id="hseDrillCancel"><div class="modal-box"><form method="post" action="{{ route('hse.drills.cancel', $x) }}">@csrf
  <div class="modal-head"><h3>Cancel {{ $x->number }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="reason" maxlength="190" required></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger">Cancel drill</button></div>
</form></div></div>@endif
@endsection
