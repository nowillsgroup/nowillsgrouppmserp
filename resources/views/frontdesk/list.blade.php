@extends('layouts.app')
@section('title', $title)
@section('crumbs') Front Office / {{ $title }} @endsection
@section('actions')
  @if($mode !== 'inhouse')<form class="filters" method="get"><div class="field"><input type="date" name="date" value="{{ request('date', bdate()->toDateString()) }}" data-autosubmit></div></form>@endif
  <input class="input" style="width:220px" placeholder="Filter list…" data-filter-table="#stayTable">
  <button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>
@endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap">
<table class="table" id="stayTable">
  <thead><tr><th>Room</th><th>Guest</th><th>Res. No</th><th>Arrival</th><th>Departure</th><th>Pax</th><th class="num">Rate</th>@if($mode !== 'arrivals')<th class="num">Balance</th>@endif<th class="right no-print">Action</th></tr></thead>
  <tbody>
  @forelse($stays as $s)
    <tr>
      <td class="bold">{{ $s->room?->room_no ?? '—' }}<div class="tiny muted">{{ $s->roomType->code }}</div></td>
      <td>{{ $s->reservation->guest->full_name }} @if($s->reservation->guest->is_vip)<span class="badge badge-purple">VIP</span>@endif @if($s->reservation->prechecked_in_at && $s->status === 'reserved')<span class="badge badge-green" title="Online check-in completed {{ $s->reservation->prechecked_in_at->format('d M H:i') }}">Pre-checked-in</span>@endif<div class="tiny muted">{{ $s->reservation->guest->phone }} {{ $s->reservation->company ? '· '.$s->reservation->company->name : '' }}</div></td>
      <td><a href="{{ route('reservations.show', $s->reservation_id) }}">{{ $s->reservation->reservation_no }}</a></td>
      <td class="nowrap">{{ $s->arrival_date->format('d M') }} @if($s->status==='reserved' && $s->arrival_date->lt(bdate()))<span class="badge badge-red">Overdue</span>@endif</td>
      <td class="nowrap">{{ $s->departure_date->format('d M') }}</td>
      <td>{{ $s->adults }}/{{ $s->children }}</td>
      <td class="num">{{ money($s->rate) }}</td>
      @if($mode !== 'arrivals')<td class="num {{ ($s->folio?->balance() ?? 0) > 0 ? 'text-red' : 'text-green' }}">{{ money($s->folio?->balance() ?? 0) }}</td>@endif
      <td class="right nowrap no-print">
        @if($mode === 'arrivals' && can_do('frontdesk.checkin'))<a class="btn btn-sm btn-success" href="{{ route('frontdesk.checkin', $s) }}"><x-icon name="login" size="sm"/> Check-in</a>@endif
        @if($mode !== 'arrivals')
          @if($s->folio)<a class="btn btn-sm" href="{{ route('folios.show', $s->folio) }}">Folio</a>@endif
          @if(can_do('requests.manage'))<a class="btn btn-sm btn-ghost" href="{{ route('requests.portal', $s) }}" title="Guest portal link / QR"><x-icon name="qr" size="sm"/></a>@endif
          @if(can_do('frontdesk.checkout'))<a class="btn btn-sm btn-warning" href="{{ route('frontdesk.checkout', $s) }}">Check-out</a>@endif
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="9"><x-empty :text="'No '.strtolower($title).' for this date.'" icon="bed"/></td></tr>
  @endforelse
  </tbody>
</table>
</div></div>
@endsection
