@extends('layouts.print')
@section('title', 'Manifest '.$dep->activity->code.' '.$dep->date->format('d M'))
@section('doctitle', 'GUEST MANIFEST')
@section('docmeta')<div class="small"><b>{{ $dep->activity->name }}</b></div><div class="small">{{ $dep->date->format('D d M Y') }} · {{ $dep->time }}</div><div class="small">{{ $dep->activity->vendor->name }}</div>@endsection
@section('content')
@php $held = $list->whereIn('status', \App\Models\ActivityBooking::HOLDING); $pax = $held->sum(fn ($b) => $b->pax()); @endphp
<div class="flex gap-1 wrap mt-1 small" id="manifestHead">
  <span><b>Status:</b> {{ \App\Models\ActivityDeparture::STATUSES[$dep->status] }}</span>
  <span><b>Guests:</b> {{ $pax }}{{ $dep->capacity ? ' of '.$dep->capacity : '' }}</span>
  @if($dep->guide)<span><b>Guide:</b> {{ $dep->guide }}</span>@endif
  <span><b>Meeting:</b> {{ $dep->activity->pickup_included ? 'Hotel pick-up' : ($dep->activity->meeting_point ?: 'Hotel lobby') }}</span>
</div>
@if($dep->status === 'cancelled')<div class="alert alert-error mt-1">Cancelled – {{ $dep->cancel_reason }}</div>@endif
<table class="table table-compact mt-1" id="manifestTable">
  <thead><tr><th>#</th><th>Booking</th><th>Lead guest</th><th>Room</th><th class="num">Adults</th><th class="num">Children</th><th>Pick-up</th><th>Mobile</th><th>Paid</th><th>Status</th><th>Notes</th></tr></thead>
  <tbody>@forelse($list as $i => $b)
    <tr data-booking="{{ $b->number }}"><td>{{ $i + 1 }}</td><td>{{ $b->number }}{{ $b->vendor_ref ? ' / '.$b->vendor_ref : '' }}</td><td>{{ $b->guest_name }}</td><td>{{ $b->room_no ?: '—' }}</td>
      <td class="num">{{ $b->adults }}</td><td class="num">{{ $b->children }}</td><td class="small">{{ $b->pickup_point ? $b->pickup_point.($b->pickup_time ? ' '.$b->pickup_time : '') : '—' }}</td>
      <td class="small">{{ $b->phone }}</td><td class="small">{{ $b->collection === 'folio' ? 'Hotel' : 'Collect '.money($b->gross, false) }}</td><td class="small">{{ $b->statusLabel() }}</td><td class="small">{{ $b->notes }}</td></tr>
  @empty<tr><td colspan="11" class="empty">No bookings on this departure.</td></tr>@endforelse</tbody>
  <tfoot><tr class="bold"><td colspan="4">Holding seats</td><td class="num">{{ $held->sum('adults') }}</td><td class="num">{{ $held->sum('children') }}</td><td colspan="5"></td></tr></tfoot>
</table>
@if(can_do('concierge.book') && $dep->status !== 'cancelled')
<div class="no-print card mt-1"><div class="card-body flex gap-1 wrap" style="align-items:flex-end">
  <form method="post" action="{{ route('concierge.departure.update', $dep) }}" class="flex gap-1 wrap" style="align-items:flex-end" id="depForm">@csrf @method('PUT')
    <div class="field"><label>Sales</label><select name="status"><option value="open" @selected($dep->status === 'open')>Open</option><option value="closed" @selected($dep->status === 'closed')>Closed – no more bookings</option></select></div>
    <div class="field"><label>Seats</label><input type="number" name="capacity" min="1" max="500" value="{{ $dep->capacity }}" style="width:6rem"></div>
    <div class="field"><label>Guide</label><input name="guide" maxlength="100" value="{{ $dep->guide }}"></div>
    <button class="btn">Save</button></form>
  <form method="post" action="{{ route('concierge.departure.cancel', $dep) }}" class="flex gap-1" style="align-items:flex-end" data-confirm="Cancel this departure? All its bookings are cancelled free of charge and the guests told.">@csrf
    <div class="field"><label>Operator cancelled – reason</label><input name="reason" required maxlength="190" placeholder="Weather / too few guests"></div><button class="btn btn-danger">Cancel departure</button></form>
</div></div>
@endif
@endsection
@section('pageclass', 'landscape')
