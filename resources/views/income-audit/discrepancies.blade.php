@extends('layouts.app')
@section('title', 'Room Status Discrepancies')
@section('crumbs') <a href="{{ route('audit.index') }}">Income Audit</a> / Room discrepancies @endsection
@section('actions')@if(can_do('income_audit.count'))<a class="btn" href="{{ route('audit.count') }}"><x-icon name="broom"/> Room count sheet</a>@endif @endsection
@php $canResolve = can_do('income_audit.authorise') || can_do('income_audit.audit'); @endphp
@section('content')
<div class="stats">
  <x-stat label="Counted today" :value="array_sum($summary->all()).' / '.$total" icon="broom" tone="blue"/>
  <x-stat label="Sleepers" :value="$summary['sleeper'] ?? 0" icon="bed" :tone="($summary['sleeper'] ?? 0) ? 'red' : 'green'" sub="FO occupied – room empty"/>
  <x-stat label="Unregistered" :value="$summary['unregistered'] ?? 0" icon="alert" :tone="($summary['unregistered'] ?? 0) ? 'red' : 'green'" sub="occupied – not in the system"/>
  <x-stat label="Out-of-order mismatch" :value="$summary['ooo_mismatch'] ?? 0" icon="tool" tone="amber"/>
</div>
<form class="filters mt-1" method="get"><div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>Show</label><select name="show"><option value="open">To resolve</option><option value="all" @selected(request('show') === 'all')>All discrepancies</option></select></div><button class="btn">Show</button></form>
<div class="card flush" id="discList"><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Date</th><th>Room</th><th>Front office</th><th>Housekeeping</th><th>Result</th><th>Counted by</th><th>Resolution</th><th></th></tr></thead>
  <tbody>@forelse($rows as $r)
    <tr data-room="{{ $r->room?->room_no }}"><td class="small">{{ dmy($r->business_date, 'D d M') }}</td><td class="bold">{{ $r->room?->room_no }} <span class="tiny muted">{{ $r->room?->roomType?->name }}</span></td>
      <td class="small">{{ str_replace('_', ' ', $r->fo_status) }}</td><td class="small">{{ strtolower(\App\Models\RoomCount::HK[$r->hk_status]) }}{{ $r->luggage ? ' · luggage' : '' }}</td>
      <td><span class="badge badge-{{ $r->result === 'ooo_mismatch' ? 'amber' : 'red' }}">{{ \App\Models\RoomCount::RESULTS[$r->result] }}</span></td>
      <td class="small">{{ $r->counter?->name }}</td>
      <td class="small">@if($r->resolution)<b>{{ \App\Models\RoomCount::RESOLUTIONS[$r->resolution] }}</b>@if($r->note)<div class="tiny">{{ $r->note }}</div>@endif<div class="tiny muted">{{ $r->resolver?->name }} · {{ $r->resolved_at?->format('d M H:i') }}</div>@else<span class="muted">open</span>@endif</td>
      <td class="num">@if($canResolve)<button class="btn btn-sm" data-modal="resolveCountModal" data-title="Room {{ $r->room?->room_no }} – {{ \App\Models\RoomCount::RESULTS[$r->result] }}" data-action="{{ route('audit.count.resolve', $r) }}">{{ $r->resolution ? 'Change' : 'Resolve' }}</button>@endif</td></tr>
  @empty <tr><td colspan="8"><x-empty text="No discrepancies to resolve." icon="check"/></td></tr> @endforelse</tbody>
</table></div></div>
<p class="tiny muted">Sleeper: the system shows the room occupied but it is empty – check the guest has not left without checking out. Unregistered: someone is in a room the system shows vacant – check for an unrecorded check-in or room move, and charge it. Unresolved discrepancies appear as exceptions in the income audit.</p>
<div class="modal" id="resolveCountModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3>Resolve</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-2"><label for="rc_res">What front office found <span class="req">*</span></label><select name="resolution" id="rc_res" required>@foreach(\App\Models\RoomCount::RESOLUTIONS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <x-field name="note" label="Note" class="span-2" placeholder="e.g. Guest confirmed by phone – out on a day tour"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="rcSave">Save</button></div>
</form></div></div>
@endsection
