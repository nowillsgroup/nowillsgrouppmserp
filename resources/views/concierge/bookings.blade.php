@extends('layouts.app')
@section('title', 'Activity Bookings')
@section('crumbs') Concierge / Bookings @endsection
@php $B = \App\Models\ActivityBooking::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'concierge_bookings']) }}"><x-icon name="download"/> Report</a>
  @if(can_do('concierge.book'))<a class="btn btn-primary" href="{{ route('concierge.create') }}"><x-icon name="plus"/> New booking</a>@endif
@endsection
@section('content')
<form method="get" class="filters card"><div class="card-body flex gap-1 wrap" style="align-items:flex-end">
  <div class="field"><label for="fq">Search</label><input name="q" id="fq" value="{{ request('q') }}" placeholder="No., guest, room, vendor ref"></div>
  <div class="field"><label for="fs">Status</label><select name="status" id="fs"><option value="">All</option><option value="open" @selected(request('status') === 'open')>Open</option>@foreach($B::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label for="fv">Vendor</label><select name="vendor" id="fv"><option value="">All</option>@foreach($vendors as $v)<option value="{{ $v->id }}" @selected(request('vendor') == $v->id)>{{ $v->name }}</option>@endforeach</select></div>
  <div class="field"><label for="fsrc">Source</label><select name="source" id="fsrc"><option value="">All</option>@foreach($B::SOURCES as $k => $l)<option value="{{ $k }}" @selected(request('source') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label for="ff">From</label><input type="date" name="from" id="ff" value="{{ request('from') }}"></div>
  <div class="field"><label for="ft">To</label><input type="date" name="to" id="ft" value="{{ request('to') }}"></div>
  <button class="btn">Filter</button>
</div></form>
<div class="card flush mt-1"><div class="card-body table-wrap"><table class="table table-compact" id="bookingsTable">
  <thead><tr><th>Booking</th><th>Date</th><th>Activity</th><th>Guest</th><th class="num">Pax</th><th class="num">Price</th><th class="num">Commission</th><th>Paid</th><th>Status</th></tr></thead>
  <tbody>@forelse($bookings as $b)
    <tr data-booking="{{ $b->number }}" data-status="{{ $b->status }}">
      <td><a href="{{ route('concierge.booking', $b) }}"><b>{{ $b->number }}</b></a><div class="tiny muted">{{ $B::SOURCES[$b->source] ?? $b->source }}</div></td>
      <td class="small">{{ $b->date->format('D d M Y') }}<div class="tiny muted">{{ $b->time }}</div></td>
      <td class="small">{{ $b->activity->name }}<div class="tiny muted">{{ $b->vendor->name }}</div></td>
      <td class="small">{{ $b->guest_name }}<div class="tiny muted">{{ $b->room_no ? 'Room '.$b->room_no : 'Non-resident' }}</div></td>
      <td class="num">{{ $b->pax() }}</td>
      <td class="num">{{ money($b->status === 'cancelled' ? $b->cancel_fee : $b->gross, false) }}</td>
      <td class="num">{{ money($b->commission, false) }}</td>
      <td class="small">{{ $b->collectionLabel() }}</td>
      <td><span class="badge badge-{{ $B::TONES[$b->status] }}">{{ $b->statusLabel() }}</span>@if($b->activity_settlement_id)<div class="tiny muted">settled</div>@endif</td>
    </tr>
  @empty<tr><td colspan="9" class="empty">No bookings match.</td></tr>@endforelse</tbody>
</table></div></div>
<div class="mt-1">{{ $bookings->links() }}</div>
@endsection
