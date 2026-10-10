@extends('partner.layout')
@section('title', 'Bookings')
@section('actions') @if($partner->can('book'))<a class="btn btn-primary" href="{{ route('partner.book') }}"><x-icon name="plus"/> New booking</a>@endif @endsection
@section('content')
<div class="flex justify-between items-center mb-2" style="flex-wrap:wrap;gap:10px">
  <div class="tabs" id="ptnTabs">@foreach(['upcoming' => 'Upcoming', 'inhouse' => 'In house', 'past' => 'Past stays', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l)<a class="{{ $tab === $k ? 'active' : '' }}" href="{{ route('partner.bookings', ['tab' => $k] + request()->only('q', 'mine')) }}">{{ $l }}</a> @endforeach</div>
  <form method="get" class="flex gap-1 items-center"><input type="hidden" name="tab" value="{{ $tab }}"><input class="input" name="q" value="{{ request('q') }}" placeholder="Booking no., traveller, reference" style="min-width:240px">
    <label class="check small"><input type="checkbox" name="mine" value="1" @checked(request('mine'))> Mine only</label><button class="btn btn-sm" type="submit"><x-icon name="search" size="sm"/></button></form>
</div>
<div class="card"><div class="card-body table-wrap">
  @if($rows->isEmpty())<x-empty text="No bookings here." icon="book"/>@else
  <table class="table" id="ptnBookings"><thead><tr><th>Booking</th><th>Traveller</th><th>Arrival</th><th>Nights</th><th>Rooms</th><th class="right">Value</th><th>Bill to</th><th>Booked by</th><th>Status</th></tr></thead><tbody>
  @foreach($rows as $r)
    @php $live = $r->rooms->where('status', '!=', 'cancelled'); $val = ($live->isEmpty() ? $r->rooms : $live)->sum(fn ($s) => \App\Services\PartnerService::stayValue($s)); @endphp
    <tr><td><a href="{{ route('partner.booking', $r->id) }}"><b>{{ $r->reservation_no }}</b></a>@if($r->partner_ref)<div class="tiny muted">{{ $r->partner_ref }}</div>@endif</td>
      <td>{{ $r->guest?->full_name }}</td><td class="nowrap">{{ $r->arrival_date->format('d M Y') }}</td><td>{{ $r->nights }}</td>
      <td>{{ ($live->isEmpty() ? $r->rooms : $live)->count() }} × {{ $r->rooms->first()?->roomType?->name }}</td><td class="right">{{ money($val) }}</td>
      <td>{{ $r->billing_instruction === 'company' ? 'Company' : 'Traveller' }}</td><td class="small">{{ $r->partnerUser?->name ?? 'Hotel' }}</td>
      <td>@include('partner._status', ['r' => $r])</td></tr>
  @endforeach
  </tbody></table>
  {{ $rows->links('partials.pagination') }}
  @endif
</div></div>
@endsection
