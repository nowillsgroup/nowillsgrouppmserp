@extends('layouts.app')
@section('title', 'Trips')
@section('crumbs') <a href="{{ route('tr.index') }}">Transport</a> / Trips @endsection
@section('actions')
  <a class="btn" href="{{ route('tr.index') }}"><x-icon name="calendar"/> Dispatch board</a>
  @if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'transport_trips') }}"><x-icon name="chart"/> Register</a>@endif
  @if(can_do('transport.book'))<a class="btn btn-primary" href="{{ route('tr.create') }}"><x-icon name="plus"/> New trip</a>@endif
@endsection
@section('content')
@include('transport._clash')
<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\TransportController::TABS as $k => $l)<a href="{{ route('tr.trips', array_filter(['tab' => $k, 'q' => request('q'), 'type' => request('type')])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $l }}</a>@endforeach
  </div></div>
  <form class="filters" style="padding:.6rem 1rem 0">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <input name="q" value="{{ request('q') }}" placeholder="Trip, guest, phone or flight" id="tripSearch">
    <select name="type" data-autosubmit aria-label="Type"><option value="">All types</option>@foreach(\App\Models\TransportTrip::TYPES as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="vehicle" data-autosubmit aria-label="Vehicle"><option value="">All vehicles</option>@foreach($vehicles as $id => $c)<option value="{{ $id }}" @selected(request('vehicle') == $id)>{{ $c }}</option>@endforeach</select>
    <select name="driver" data-autosubmit aria-label="Driver"><option value="">All drivers</option>@foreach($drivers as $id => $n)<option value="{{ $id }}" @selected(request('driver') == $id)>{{ $n }}</option>@endforeach</select>
    <button class="btn btn-sm">Filter</button>
  </form>
  <div class="card-body table-wrap"><table class="table" id="tripsTable">
    <thead><tr><th>When</th><th>Trip</th><th>Guest</th><th>Route</th><th class="num">Pax</th><th>Car · driver</th><th>Billing</th><th class="num">Fare</th><th>Status</th><th class="right"></th></tr></thead><tbody>
    @forelse($trips as $t)
      <tr class="{{ in_array($t->status, ['cancelled', 'no_show']) ? 'muted-row' : '' }}" data-trip="{{ $t->trip_no }}">
        <td class="nowrap"><b>{{ $t->scheduled_at->format('d M H:i') }}</b><div class="tiny muted">{{ $t->typeLabel() }}</div></td>
        <td class="nowrap"><a href="{{ route('tr.show', $t) }}" class="bold">{{ $t->trip_no }}</a>@if($t->flight_no)<div class="tiny muted"><x-icon name="plane" size="sm"/> {{ $t->flight_no }}</div>@endif</td>
        <td>{{ $t->passenger_name }}@if($t->stay?->room) <span class="badge badge-purple">Rm {{ $t->stay->room->room_no }}</span>@endif<div class="tiny muted">{{ $t->phone }} · {{ \App\Models\TransportTrip::SOURCES[$t->source] ?? $t->source }}</div></td>
        <td class="small">{{ $t->route() }}</td>
        <td class="num">{{ $t->pax }}</td>
        <td class="small nowrap">{{ $t->vehicle?->code ?? '—' }}<div class="tiny muted">{{ $t->driver?->name }}</div></td>
        <td class="small">{{ $t->billing === 'room' ? 'Room bill' : ($t->billing === 'direct' ? 'Direct' : 'Complimentary') }}@if($t->unbilled() && $t->status === 'completed')<div class="tiny text-red">not on bill</div>@endif</td>
        <td class="num">{{ money($t->total) }}</td>
        <td>{!! tr_badge($t->status) !!}</td>
        <td class="right nowrap">@if(can_do('transport.book') && $t->isPlanned())<button class="btn btn-sm assign-btn" data-assign="{{ route('tr.options', $t) }}">{{ $t->vehicle_id ? 'Re-assign' : 'Assign' }}</button>@endif</td>
      </tr>
    @empty<tr><td colspan="10"><x-empty text="No trips here." icon="car"/></td></tr>@endforelse
  </tbody></table></div>
  <div class="card-foot">{{ $trips->links() }}</div>
</div>
@include('transport._assign')
@endsection
