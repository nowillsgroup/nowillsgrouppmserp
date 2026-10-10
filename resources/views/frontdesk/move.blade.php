@extends('layouts.app')
@section('title', 'Room Move / Amend Stay')
@section('crumbs') <a href="{{ route('reservations.show', $stay->reservation_id) }}">{{ $stay->reservation->reservation_no }}</a> / Room move @endsection
@section('content')
<div class="grid g-2">
  <form method="post" action="{{ route('frontdesk.move.store', $stay) }}" class="card">@csrf
    <div class="card-head"><h3>Move guest from room {{ $stay->room->room_no }} ({{ $stay->room->roomType->name }})</h3></div>
    <div class="card-body form-grid">
      <div class="field"><label>New room <span class="req">*</span></label><select name="to_room_id" required><option value="">Select</option>@foreach($rooms as $r)<option value="{{ $r->id }}" data-rate="{{ $r->roomType->base_rate }}">{{ $r->room_no }} – {{ $r->roomType->name }}</option>@endforeach</select></div>
      <x-field name="new_rate" label="New rate (optional)" type="number" step="0.01" :placeholder="'Keep '.$stay->rate"/>
      <x-field name="reason" label="Reason" type="select" :options="['Guest request'=>'Guest request','Upgrade'=>'Upgrade','Maintenance issue'=>'Maintenance issue','Noise complaint'=>'Noise complaint','Overbooking'=>'Overbooking']" required class="span-all"/>
    </div>
    <div class="card-foot"><button class="btn btn-primary"><x-icon name="swap"/> Move Room</button></div>
  </form>
  <form method="post" action="{{ route('frontdesk.amend', $stay) }}" class="card">@csrf
    <div class="card-head"><h3>Amend stay (extend / early departure)</h3></div>
    <div class="card-body form-grid">
      <x-field name="departure_date" label="New departure date" type="date" :value="$stay->departure_date->toDateString()" required/>
      <x-field name="rate" label="Rate / night" type="number" step="0.01" :value="$stay->rate"/>
    </div>
    <div class="card-foot"><button class="btn btn-accent"><x-icon name="calendar"/> Save Amendment</button></div>
  </form>
</div>
<div class="card flush"><div class="card-head"><h3>Move history</h3></div><div class="card-body table-wrap">
<table class="table"><thead><tr><th>When</th><th>From</th><th>To</th><th class="num">Old rate</th><th class="num">New rate</th><th>Reason</th><th>By</th></tr></thead><tbody>
@forelse($stay->moves as $m)<tr><td>{{ $m->created_at->format('d M H:i') }}</td><td>{{ $m->fromRoom?->room_no }}</td><td>{{ $m->toRoom?->room_no }}</td><td class="num">{{ money($m->old_rate) }}</td><td class="num">{{ money($m->new_rate) }}</td><td>{{ $m->reason }}</td><td>{{ $m->user?->name }}</td></tr>
@empty<tr><td colspan="7" class="muted center">No moves.</td></tr>@endforelse</tbody></table></div></div>
@endsection
