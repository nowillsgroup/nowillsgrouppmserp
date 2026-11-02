@extends('layouts.app')
@section('title', 'Folios')
@section('actions')<a href="{{ route('folios.transactions', ['date' => bdate()->toDateString()]) }}" class="btn"><x-icon name="receipt"/> Transactions</a> @if(can_do('folio.post'))<a href="{{ route('folios.walkin') }}" class="btn btn-primary"><x-icon name="plus"/> Non-Resident Bill</a>@endif @endsection
@section('content')
<div class="card"><div class="card-body">
@if($reservationDeskScope)<p class="tiny muted">Reservation-linked folios only; banquet and non-reservation folios are excluded.</p>@endif
<form class="filters">
  @if($reservationDeskScope)<input type="hidden" name="scope" value="reservation_desk">@endif
  <div class="field" style="flex:1"><label for="folioSearch">Search</label><input id="folioSearch" name="q" value="{{ request('q') }}" placeholder="Folio, invoice or reservation no, guest or company…"></div>
  <div class="field"><label for="folioStatus">Status</label><select id="folioStatus" name="status" data-autosubmit>@foreach(['open'=>'Open','closed'=>'Closed','all'=>'All'] as $k=>$v)<option value="{{ $k }}" @selected(request('status','open')==$k)>{{ $v }}</option>@endforeach</select></div>
  <button class="btn btn-primary">Search</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<caption class="sr-only">Folio register results</caption>
<thead><tr><th scope="col">Folio</th><th scope="col">Guest</th><th scope="col">Room</th><th scope="col">Type</th><th scope="col">Reservation</th><th scope="col">Invoice</th><th scope="col" class="num">Balance</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
@forelse($folios as $f)
@php $linkedReservation = $f->reservation ?? $f->stay?->reservation; $balance = (float) $f->listing_balance; @endphp
<tr><td><a href="{{ route('folios.show', $f) }}" class="bold">{{ $f->folio_no }}</a></td><td>{{ $f->guest?->full_name ?? $f->stay?->guest?->full_name ?? $linkedReservation?->guest?->full_name ?? '—' }}<div class="tiny muted">{{ $f->company?->name ?? $linkedReservation?->company?->name }}</div></td><td>{{ $f->stay?->room?->room_no ?? '—' }}</td><td>{{ ucwords(str_replace('_',' ',$f->type)) }}</td>
<td>@if($linkedReservation)<a href="{{ route('reservations.show', $linkedReservation) }}">{{ $linkedReservation->reservation_no }}</a>@else — @endif</td><td>{{ $f->invoice_no ?? '—' }}</td>
<td class="num {{ $balance > 0 ? 'text-red' : ($balance < 0 ? 'text-green' : '') }}">{{ money($balance) }}</td><td>{!! status_badge($f->status) !!}</td><td><a class="btn btn-sm" href="{{ route('folios.show', $f) }}" aria-label="Open folio {{ $f->folio_no }}" title="Open folio"><x-icon name="eye" size="sm"/></a></td></tr>
@empty<tr><td colspan="9"><x-empty text="No folios." icon="receipt"/></td></tr>@endforelse
</tbody></table>{{ $folios->links() }}</div></div>
@endsection
