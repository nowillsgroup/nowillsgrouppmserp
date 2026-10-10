@extends('layouts.app')
@section('title', 'Folios')
@section('actions')@if(can_do('folio.post'))<a href="{{ route('folios.walkin') }}" class="btn btn-primary"><x-icon name="plus"/> Non-Resident Bill</a>@endif @endsection
@section('content')
<div class="card"><div class="card-body"><form class="filters">
  <div class="field" style="flex:1"><label>Search</label><input name="q" value="{{ request('q') }}" placeholder="Folio / invoice no, guest…"></div>
  <div class="field"><label>Status</label><select name="status" data-autosubmit>@foreach(['open'=>'Open','closed'=>'Closed','all'=>'All'] as $k=>$v)<option value="{{ $k }}" @selected(request('status','open')==$k)>{{ $v }}</option>@endforeach</select></div>
  <button class="btn btn-primary">Search</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Folio</th><th>Guest</th><th>Room</th><th>Type</th><th>Reservation</th><th>Invoice</th><th class="num">Balance</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($folios as $f)
<tr><td><a href="{{ route('folios.show', $f) }}" class="bold">{{ $f->folio_no }}</a></td><td>{{ $f->guest?->full_name }}<div class="tiny muted">{{ $f->company?->name }}</div></td><td>{{ $f->stay?->room?->room_no ?? '—' }}</td><td>{{ ucwords(str_replace('_',' ',$f->type)) }}</td>
<td>@if($f->reservation)<a href="{{ route('reservations.show', $f->reservation) }}">{{ $f->reservation->reservation_no }}</a>@else — @endif</td><td>{{ $f->invoice_no ?? '—' }}</td>
<td class="num {{ $f->balance() > 0 ? 'text-red' : '' }}">{{ money($f->balance()) }}</td><td>{!! status_badge($f->status) !!}</td><td><a class="btn btn-sm" href="{{ route('folios.show', $f) }}"><x-icon name="eye" size="sm"/></a></td></tr>
@empty<tr><td colspan="9"><x-empty text="No folios." icon="receipt"/></td></tr>@endforelse
</tbody></table>{{ $folios->links() }}</div></div>
@endsection
