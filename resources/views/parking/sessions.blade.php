@extends('layouts.app')
@section('title', 'Parking Tickets')
@section('crumbs') Parking &amp; Valet / Tickets @endsection
@section('actions')<a class="btn" href="{{ route('reports.show', ['report' => 'parking_sessions', 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}"><x-icon name="download"/> Export</a>@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input type="date" name="from" value="{{ $from->toDateString() }}" aria-label="From"><input type="date" name="to" value="{{ $to->toDateString() }}" aria-label="To">
  <input name="q" value="{{ request('q') }}" placeholder="ticket, plate, name" style="max-width:12rem" aria-label="Search">
  <select name="status" aria-label="Status"><option value="">Any status</option>@foreach(\App\Models\ParkingSession::STATUSES as $k => [$l])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="kind" aria-label="Kind"><option value="">Everyone</option>@foreach(\App\Models\ParkingSession::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="zone" aria-label="Zone"><option value="">All zones</option>@foreach($zones as $z)<option value="{{ $z->id }}" @selected((int) request('zone') === $z->id)>{{ $z->code }}</option>@endforeach</select>
  <label class="check small"><input type="checkbox" name="valet" value="1" @checked(request('valet') === '1')> valet only</label>
  <button class="btn btn-sm">Apply</button>
  <span class="tiny muted">{{ $sum->n }} ticket(s) · charged {{ money($sum->fee ?? 0) }}{{ (float) $sum->waived > 0 ? ' · waived '.money($sum->waived) : '' }}</span>
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="pkSessionsTable"><thead><tr><th>Ticket</th><th>Plate</th><th>Type</th><th>Driver / room</th><th>Zone</th><th>In</th><th>Out</th><th>Duration</th><th class="num">Charged</th><th>Status</th></tr></thead><tbody>
  @forelse($sessions as $s)<tr>
    <td><a href="{{ route('parking.session', $s) }}"><b>{{ $s->number }}</b></a></td><td class="mono">{{ $s->plate }}</td><td class="small">{{ $s->kindLabel() }}</td>
    <td class="small">{{ $s->stay?->room ? 'Rm '.$s->stay->room->room_no.' · ' : '' }}{{ $s->guest_name }}</td><td class="small">{{ $s->zone->code }}</td>
    <td class="small">{{ $s->entered_at->format('d M H:i') }}</td><td class="small">{{ $s->exited_at?->format('d M H:i') }}</td><td class="small">{{ $s->durationLabel() }}</td>
    <td class="num">{{ (float) $s->fee > 0 ? money($s->fee) : '' }}@if((float) $s->fee_waived > 0)<div class="tiny muted">waived {{ money($s->fee_waived) }}</div>@endif @if($s->lost_ticket)<span class="badge badge-red">lost</span>@endif</td>
    <td><span class="badge badge-{{ $s->statusTone() }}">{{ $s->statusLabel() }}</span></td></tr>
  @empty<tr><td colspan="10" class="muted">No tickets in this period.</td></tr>@endforelse
</tbody></table></div></div>
{{ $sessions->links() }}
@endsection
