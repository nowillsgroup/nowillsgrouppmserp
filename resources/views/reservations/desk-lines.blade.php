<div class="card flush"><div class="card-body table-wrap">
  <table class="table">
    <thead><tr><th>Reservation</th><th>Guest</th><th>Room</th><th>Ready</th><th>ETA</th><th>Balance</th>@if(($mode ?? '') === 'arrival')<th>ID &amp; phone</th>@endif @if(($mode ?? '') === 'departure')<th>Holds</th><th>To settle</th>@endif<th>Dates</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($rows as $s)
      @php
        $eta = $s->reservation->eta ?? null;
        $ready = $s->room ? ucfirst(str_replace('_', ' ', $s->room->hk_status)) : 'Unassigned';
        $bal = $s->relationLoaded('folio') && $s->folio ? $s->folio->balance() : null;
      @endphp
      <tr>
        <td><a href="{{ route('reservations.show', $s->reservation) }}">{{ $s->reservation->reservation_no }}</a>@if($s->reservation->special_request)<div class="tiny">Request on file</div>@endif</td>
        <td>{{ $s->reservation->guest->full_name }}@if($s->reservation->guest->is_vip) <span class="badge badge-purple">VIP</span>@endif</td>
        <td>{{ $s->room->room_no ?? 'Unassigned' }}<div class="tiny muted">{{ $s->roomType->name }}</div></td>
        <td>{{ $ready }}</td>
        <td>{{ $eta ? substr((string) $eta, 0, 5) : 'No ETA on file' }}</td>
        <td>{{ $bal === null ? 'No folio' : money($bal) }}</td>
        @if(($mode ?? '') === 'arrival')
          <td class="small">{{ \App\Services\StayService::checkInReady($s) }}</td>
        @endif
        @if(($mode ?? '') === 'departure')
          @php $holds = $s->status === 'checked_in' ? \App\Services\StayService::checkoutHolds($s) : null; @endphp
          <td class="small">@if(! $holds)Checked out.@elseif($holds['trips']['count'] || $holds['activities']['count'])Checkout blocked.@if($holds['trips']['count']) Transport {{ money($holds['trips']['total']) }}.@endif @if($holds['activities']['count']) Activity {{ money($holds['activities']['total']) }}.@endif @else No hold on file.@endif</td>
          <td class="small">@if($s->status !== 'checked_in')—@else @php $due = \App\Services\StayService::quoteCheckoutFigures($s)['due']; @endphp {{ $due === null ? 'No folio' : money($due) }}<div class="tiny muted">Not posted</div>@endif</td>
        @endif
        <td class="nowrap">{{ $s->arrival_date->format('d M') }} – {{ $s->departure_date->format('d M') }}</td>
        <td>{!! status_badge($s->status) !!}</td>
        <td class="nowrap">
          <a class="btn btn-sm" href="{{ route('reservations.stay', $s) }}" data-drawer="{{ route('reservations.stay', $s) }}">Open</a>
          @if($mode === 'arrival' && $s->status === 'reserved' && $s->room_id && can_do('frontdesk.checkin'))
            <a class="btn btn-sm btn-primary" href="{{ route('frontdesk.checkin', $s) }}">Check in</a>
          @endif
          @if(in_array($mode, ['departure', 'inhouse'], true) && $s->status === 'checked_in' && can_do('frontdesk.checkout'))
            <a class="btn btn-sm btn-primary" href="{{ route('frontdesk.checkout', $s) }}">Check out</a>
          @endif
          @if($s->folio)
            <a class="btn btn-sm" href="{{ route('folios.show', $s->folio) }}">Folio</a>
            @if(can_do('folio.view'))<a class="btn btn-sm" href="{{ route('folios.invoice', $s->folio) }}" target="_blank">Receipt</a>@endif
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="{{ ($mode ?? '') === 'departure' ? 11 : (($mode ?? '') === 'arrival' ? 10 : 9) }}">Nothing in this list for {{ $date->format('d M Y') }}.</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>
