@extends('layouts.app')
@section('title', 'Search results')
@section('crumbs') Showing matches for “{{ $q }}” @endsection
@section('content')
<div class="grid g-2">
  <div class="card flush"><div class="card-head"><h3>Guests ({{ $guests->count() }})</h3></div><div class="card-body table-wrap">
    <table class="table"><thead><tr><th>Name</th><th>Phone</th><th>Email</th></tr></thead><tbody>
    @forelse($guests as $g)<tr><td><a href="{{ route('guests.show', $g) }}">{{ $g->full_name }}</a></td><td>{{ $g->phone }}</td><td>{{ $g->email }}</td></tr>
    @empty<tr><td colspan="3" class="muted">No guests</td></tr>@endforelse</tbody></table></div></div>
  <div class="card flush"><div class="card-head"><h3>Reservations ({{ $reservations->count() }})</h3></div><div class="card-body table-wrap">
    <table class="table"><thead><tr><th>No.</th><th>Guest</th><th>Dates</th><th>Status</th></tr></thead><tbody>
    @forelse($reservations as $r)<tr><td><a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a></td><td>{{ $r->guest->full_name }}</td><td class="small">{{ $r->arrival_date->format('d M') }} → {{ $r->departure_date->format('d M') }}</td><td>{!! status_badge($r->status) !!}</td></tr>
    @empty<tr><td colspan="4" class="muted">No reservations</td></tr>@endforelse</tbody></table></div></div>
</div>
@if($rooms->count())<div class="card"><div class="card-head"><h3>Rooms ({{ $rooms->count() }})</h3></div><div class="card-body flex gap-1 wrap">@foreach($rooms as $r)<a class="btn" href="{{ route('reservations.index', ['view' => 'timeline', 'q' => $r->room_no]) }}">{{ $r->room_no }} · {{ $r->roomType->name }} {!! status_badge($r->status) !!}</a>@endforeach</div></div>@endif
@if($companies->count())<div class="card flush"><div class="card-head"><h3>Companies ({{ $companies->count() }})</h3></div><div class="card-body table-wrap"><table class="table"><thead><tr><th>Name</th><th>Phone</th></tr></thead><tbody>
@foreach($companies as $c)<tr><td><a href="{{ route('reservations.index', ['view' => 'list', 'q' => $c->name]) }}">{{ $c->name }}</a></td><td>{{ $c->phone }}</td></tr>@endforeach
</tbody></table></div></div>@endif
@if($folios->count())<div class="card flush"><div class="card-head"><h3>Folios ({{ $folios->count() }})</h3></div><div class="card-body table-wrap"><table class="table"><thead><tr><th>Folio</th><th>Guest</th><th>Status</th></tr></thead><tbody>
@foreach($folios as $f)<tr><td><a href="{{ route('folios.show', $f) }}">{{ $f->folio_no }}</a></td><td>{{ $f->guest->full_name ?? '—' }}</td><td>{!! status_badge($f->status) !!}</td></tr>@endforeach
</tbody></table></div></div>@endif
@if(($waitlist ?? collect())->count())<div class="card flush"><div class="card-head"><h3>Waitlist ({{ $waitlist->count() }})</h3></div><div class="card-body table-wrap"><table class="table"><thead><tr><th>Name</th><th>Dates</th><th>Status</th></tr></thead><tbody>
@foreach($waitlist as $w)<tr><td><a href="{{ route('reservations.index', ['view' => 'waitlist', 'q' => $w->contact_name]) }}">{{ $w->contact_name }}</a></td><td>{{ $w->arrival_date->format('d M') }} – {{ $w->departure_date->format('d M') }}</td><td>{{ $w->status }}</td></tr>@endforeach
</tbody></table></div></div>@endif
@endsection
