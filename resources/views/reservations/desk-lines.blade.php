@if(($mode ?? '') === 'arrival')
  @php
    $canViewFolio = can_do('folio.view');
    $canSeeHousekeeping = can_do('housekeeping.view') || can_do('housekeeping.manage') || can_do('housekeeping.attendant');
  @endphp
  <section class="arrival-manifest" aria-labelledby="arrivalManifestTitle">
    <div class="arrival-manifest-head">
      <div>
        <span class="arrival-manifest-kicker">CHECK-IN WORKLIST</span>
        <h3 id="arrivalManifestTitle">Arrival manifest</h3>
        <p>Each room stay is listed separately, including rooms booked as part of a group reservation.</p>
      </div>
      <span class="arrival-manifest-count"><strong>{{ number_format($rows->count()) }}</strong> {{ \Illuminate\Support\Str::plural('stay', $rows->count()) }}</span>
    </div>
    @if($rows->isNotEmpty())
      <div class="arrival-table-wrap">
        <table class="arrival-table">
          <caption class="sr-only">Arrival stays for {{ $date->format('l, d F Y') }}</caption>
          <thead><tr><th scope="col">Guest &amp; booking</th><th scope="col">Stay</th><th scope="col">Room</th><th scope="col">ETA</th><th scope="col">Check-in prep</th>@if($canViewFolio)<th scope="col">Balance</th>@endif<th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
          @foreach($rows as $s)
            @php
              $reservation = $s->reservation;
              $guest = $reservation?->guest;
              $guestName = $guest?->full_name ?? 'Guest profile unavailable';
              $guestPhone = trim((string) ($guest?->phone ?? ''));
              $callPhone = preg_match('/^\\+?[0-9][0-9 .()-]{5,}$/', $guestPhone) ? preg_replace('/[^0-9+]/', '', $guestPhone) : null;
              $eta = $reservation?->eta ? substr((string) $reservation->eta, 0, 5) : null;
              $pastEta = isset($lateEtaIds) && $lateEtaIds->contains($s->id);
              $prep = $s->status === 'reserved' ? \App\Services\StayService::checkInReady($s) : ($s->status === 'checked_in' ? 'Already checked in.' : 'Stay completed.');
              $prepTone = $s->status !== 'reserved' ? 'complete' : (str_starts_with($prep, 'Needs') ? 'attention' : (str_contains($prep, 'Room is') ? 'caution' : 'prepared'));
              $roomStatus = $s->room ? ucfirst(str_replace('_', ' ', $s->room->hk_status)) : 'Unassigned';
              $arrivalNights = max(1, (int) $s->arrival_date->diffInDays($s->departure_date));
              $balance = $canViewFolio && $s->relationLoaded('folio') && $s->folio ? $s->folio->balance() : null;
              $canManageRoom = can_do('frontdesk.roommove') || ($s->status === 'reserved' && can_do('reservations.edit'));
            @endphp
            <tr>
              <td data-label="Guest &amp; booking">
                <div class="arrival-guest-cell">
                  <a class="arrival-guest-name" href="{{ route('reservations.show', $reservation) }}">{{ $guestName }}</a>
                  @if($guest?->is_vip)<span class="arrival-vip">VIP</span>@endif
                </div>
                <a class="arrival-booking-ref" href="{{ route('reservations.show', $reservation) }}">{{ $reservation?->reservation_no ?? 'Reservation details' }}</a>
                <div class="arrival-booking-meta">
                  <span>{{ $reservation?->source?->name ?? 'Direct booking' }}</span>
                  @if($reservation?->booking_type === 'group')<span>Group · {{ $reservation->group_name ?: 'Group booking' }}</span>@endif
                  @if($reservation?->special_request)<span title="{{ $reservation->special_request }}">Special request on file</span>@endif
                  @if(can_do('guests.view') && $guestPhone)@if($callPhone)<a href="tel:{{ $callPhone }}" aria-label="Call {{ $guestName }} at {{ $guestPhone }}">{{ $guestPhone }}</a>@else<span>{{ $guestPhone }}</span>@endif @endif
                </div>
              </td>
              <td data-label="Stay">
                <div class="arrival-stay-dates"><time datetime="{{ $s->arrival_date->toDateString() }}">{{ $s->arrival_date->format('d M Y') }}</time><span aria-hidden="true">→</span><time datetime="{{ $s->departure_date->toDateString() }}">{{ $s->departure_date->format('d M Y') }}</time></div>
                <span class="arrival-stay-meta">{{ $arrivalNights }} {{ \Illuminate\Support\Str::plural('night', $arrivalNights) }} · {{ $s->adults }} {{ \Illuminate\Support\Str::plural('adult', $s->adults) }}@if($s->children) · {{ $s->children }} {{ \Illuminate\Support\Str::plural('child', $s->children) }}@endif</span>
              </td>
              <td data-label="Room">
                <strong class="arrival-room-number">{{ $s->room?->room_no ? 'Room '.$s->room->room_no : 'Room unassigned' }}</strong>
                <span class="arrival-room-type">{{ $s->roomType?->name ?? 'Room type unavailable' }}</span>
                @if($s->room && $canSeeHousekeeping)<span class="arrival-hk arrival-hk-{{ $s->room->hk_status }}">{{ $roomStatus }} for housekeeping</span>@elseif(!$s->room)<span class="arrival-hk arrival-hk-unassigned">Assign a room before check-in</span>@endif
              </td>
              <td data-label="ETA"><span class="arrival-eta {{ $eta ? 'has-eta' : '' }}">{{ $eta ?? 'Not provided' }}</span>@if($pastEta)<span class="arrival-eta-alert">Past ETA · not checked in</span>@endif</td>
              <td data-label="Check-in prep"><div class="arrival-prep-status">{!! status_badge($s->status) !!}<span class="arrival-prep arrival-prep-{{ $prepTone }}">{{ $prepTone === 'complete' ? 'Complete' : ($prepTone === 'attention' ? 'Needs attention' : ($prepTone === 'caution' ? 'Room follow-up' : 'Details on file')) }}</span></div><span class="arrival-prep-note">{{ $prep }}</span></td>
              @if($canViewFolio)<td data-label="Balance"><span class="arrival-balance">{{ $balance === null ? 'No folio' : money($balance) }}</span></td>@endif
              <td data-label="Actions" class="arrival-actions">
                <a class="btn btn-sm" href="{{ route('reservations.stay', $s) }}{{ !$s->room_id && $canManageRoom ? '#stay-room-assignment' : '' }}" aria-label="{{ !$s->room_id && $canManageRoom ? 'Assign a room to '.$guestName : 'Open stay for '.$guestName }}">{{ !$s->room_id && $canManageRoom ? 'Assign room' : 'Open stay' }}</a>
                @if($s->status === 'reserved' && $s->room_id && can_do('frontdesk.checkin'))<a class="btn btn-sm btn-primary" href="{{ route('frontdesk.checkin', $s) }}" aria-label="Check in {{ $guestName }} for reservation {{ $reservation?->reservation_no }}">Check in</a>@endif
                @if($canViewFolio && $s->folio)<a class="btn btn-sm" href="{{ route('folios.show', $s->folio) }}">Folio</a><a class="btn btn-sm" href="{{ route('folios.invoice', $s->folio) }}" target="_blank" rel="noopener">Invoice</a>@endif
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @else
      <div class="arrival-empty" role="status">
        <span class="arrival-empty-mark" aria-hidden="true">✓</span>
        <div><strong>No arrivals in this view</strong><p>{{ request('q') ? 'No stays match this search for '.$date->format('d M Y').'. Clear the search or choose another date.' : 'There are no arrivals scheduled for '.$date->format('l, d F Y').'.' }}</p></div>
        @if(request('q') || request('due') || request('focus'))<a class="btn btn-sm" href="{{ route('reservations.index', ['view' => 'arrivals', 'date' => $date->toDateString()]) }}">Clear arrival filters</a>@endif
      </div>
    @endif
  </section>
@elseif(($mode ?? '') === 'departure')
  @php
    $canViewFolio = can_do('folio.view');
    $canSeeHousekeeping = can_do('housekeeping.view') || can_do('housekeeping.manage') || can_do('housekeeping.attendant');
    $canCheckout = can_do('frontdesk.checkout');
    $canSeeCheckoutPrep = can_do('frontdesk.view') || $canCheckout;
  @endphp
  <section class="departure-manifest" aria-labelledby="departureManifestTitle">
    <div class="departure-manifest-head">
      <div>
        <span class="departure-manifest-kicker">CHECK-OUT WORKLIST</span>
        <h3 id="departureManifestTitle">Departure manifest</h3>
        <p>Each room stay is listed separately, including rooms in group reservations.</p>
      </div>
      <span class="departure-manifest-count"><strong>{{ number_format($rows->count()) }}</strong> {{ \Illuminate\Support\Str::plural('stay', $rows->count()) }}</span>
    </div>
    @if($rows->isNotEmpty())
      <div class="departure-table-wrap">
        <table class="departure-table">
          <caption class="sr-only">Departure stays for {{ $date->format('l, d F Y') }}</caption>
          <thead><tr><th scope="col">Guest &amp; booking</th><th scope="col">Stay</th><th scope="col">Room</th><th scope="col">Check-out prep</th>@if($canViewFolio)<th scope="col">Folio &amp; estimate</th>@endif<th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
          @foreach($rows as $s)
            @php
              $reservation = $s->reservation;
              $guest = $reservation?->guest;
              $guestName = $guest?->full_name ?? 'Guest profile unavailable';
              $guestPhone = trim((string) ($guest?->phone ?? ''));
              $callPhone = preg_match('/^\\+?[0-9][0-9 .()-]{5,}$/', $guestPhone) ? preg_replace('/[^0-9+]/', '', $guestPhone) : null;
              $nights = max(1, (int) $s->arrival_date->diffInDays($s->departure_date));
              $folio = $canViewFolio && $s->relationLoaded('folio') ? $s->folio : null;
              $balance = $folio ? $folio->balance() : null;
              $balanceLabel = $balance === null ? 'No folio' : ($balance < -0.009 ? money(abs($balance)).' credit' : money($balance));
              $holds = $s->status === 'checked_in' && $canSeeCheckoutPrep ? \App\Services\StayService::checkoutHolds($s) : null;
              $figures = $canViewFolio && $s->status === 'checked_in' ? \App\Services\StayService::quoteCheckoutFigures($s) : null;
              $checkoutDue = $figures['due'] ?? null;
              $holdLabels = [];
              if ($holds && $holds['pos']['count']) $holdLabels[] = 'POS '.$holds['pos']['count'];
              if ($holds && $holds['trips']['count']) $holdLabels[] = 'Transport '.$holds['trips']['count'];
              if ($holds && $holds['laundry']['count']) $holdLabels[] = 'Laundry '.$holds['laundry']['count'];
              if ($holds && $holds['activities']['count']) $holdLabels[] = 'Activities '.$holds['activities']['count'];
              if ($holds && $holds['formc']['count']) $holdLabels[] = 'Form C pending';
              $canPostBills = $canCheckout && $holds && ($holds['pos']['count'] || $holds['trips']['count']);
              $roomStatus = $s->room ? ucfirst(str_replace('_', ' ', $s->room->hk_status)) : 'Unassigned';
              $prepLabel = $s->status === 'checked_in' ? (! $canSeeCheckoutPrep ? 'Front desk follow-up' : (($holds['blocking'] ?? 0) > 0 ? 'Checkout follow-up' : 'Checkout review')) : ($s->status === 'checked_out' ? 'Completed' : 'Not checked in');
              $prepTone = $s->status === 'checked_in' ? (! $canSeeCheckoutPrep ? 'pending' : (($holds['blocking'] ?? 0) > 0 ? 'attention' : 'ready')) : ($s->status === 'checked_out' ? 'complete' : 'pending');
              $checkoutDueLabel = $checkoutDue === null ? 'No open folio' : ($checkoutDue < -0.009 ? money(abs($checkoutDue)).' credit' : money($checkoutDue));
            @endphp
            <tr>
              <td data-label="Guest &amp; booking">
                <div class="departure-guest-cell">
                  <a class="departure-guest-name" href="{{ route('reservations.show', $reservation) }}">{{ $guestName }}</a>
                  @if($guest?->is_vip)<span class="departure-vip">VIP</span>@endif
                </div>
                <a class="departure-booking-ref" href="{{ route('reservations.show', $reservation) }}">{{ $reservation?->reservation_no ?? 'Reservation details' }}</a>
                <div class="departure-booking-meta">
                  <span>{{ $reservation?->source?->name ?? 'Direct booking' }}</span>
                  @if($reservation?->booking_type === 'group')<span>Group · {{ $reservation->group_name ?: 'Group booking' }}</span>@endif
                  @if(can_do('guests.view') && $guestPhone)@if($callPhone)<a href="tel:{{ $callPhone }}" aria-label="Call {{ $guestName }} at {{ $guestPhone }}">{{ $guestPhone }}</a>@else<span>{{ $guestPhone }}</span>@endif @endif
                </div>
              </td>
              <td data-label="Stay">
                <div class="departure-stay-dates"><time datetime="{{ $s->arrival_date->toDateString() }}">{{ $s->arrival_date->format('d M Y') }}</time><span aria-hidden="true">→</span><time datetime="{{ $s->departure_date->toDateString() }}">{{ $s->departure_date->format('d M Y') }}</time></div>
                <span class="departure-stay-meta">{{ $nights }} {{ \Illuminate\Support\Str::plural('night', $nights) }} · {{ $s->adults }} {{ \Illuminate\Support\Str::plural('adult', $s->adults) }}@if($s->children) · {{ $s->children }} {{ \Illuminate\Support\Str::plural('child', $s->children) }}@endif</span>
              </td>
              <td data-label="Room">
                <strong class="departure-room-number">{{ $s->room?->room_no ? 'Room '.$s->room->room_no : 'Room unassigned' }}</strong>
                <span class="departure-room-type">{{ $s->roomType?->name ?? 'Room type unavailable' }}</span>
                @if($s->room && $canSeeHousekeeping)<span class="departure-hk departure-hk-{{ $s->room->hk_status }}">{{ $roomStatus }} for housekeeping</span>@elseif(!$s->room)<span class="departure-hk departure-hk-unassigned">Room assignment needs review</span>@endif
              </td>
              <td data-label="Check-out prep">
                <div class="departure-prep-status">{!! status_badge($s->status) !!}<span class="departure-prep departure-prep-{{ $prepTone }}">{{ $prepLabel }}</span></div>
                @if($s->status === 'checked_in')
                  @if(!$canSeeCheckoutPrep)<span class="departure-prep-note">Checkout hold details are available to Front Desk staff.</span>
                  @elseif($holds['blocking'] > 0)<span class="departure-prep-note">Resolve {{ implode(' · ', $holdLabels) }} before checkout.</span>
                  @else<span class="departure-prep-note">No operational checkout holds are on file. Complete final review in the existing checkout flow.</span>@endif
                @elseif($s->status === 'checked_out')
                  <span class="departure-prep-note">Checkout is recorded. Room turnover follows the existing checkout process.</span>
                @else
                  <span class="departure-prep-note">This stay remains reserved; there is no checkout action yet.</span>
                @endif
              </td>
              @if($canViewFolio)<td data-label="Folio &amp; estimate"><span class="departure-estimate-label">Current balance</span><span class="departure-balance">{{ $balanceLabel }}</span>@if($figures)<span class="departure-estimate"><b>Estimated after checkout</b> · {{ $checkoutDueLabel }}</span>@endif</td>@endif
              <td data-label="Actions" class="departure-actions">
                <a class="btn btn-sm" href="{{ route('reservations.stay', $s) }}" aria-label="Open stay for {{ $guestName }}">Open stay</a>
                @if($canViewFolio && $folio)<a class="btn btn-sm" href="{{ route('folios.show', $folio) }}" aria-label="Open folio for {{ $guestName }}">Folio</a><a class="btn btn-sm" href="{{ route('folios.invoice', $folio) }}" target="_blank" rel="noopener" aria-label="Open invoice for {{ $guestName }}">Invoice</a>@endif
                @if($s->status === 'checked_in' && $canCheckout)
                  @if($canPostBills)<form method="post" action="{{ route('frontdesk.prepare', $s) }}" class="departure-post-bills">@csrf<button class="btn btn-sm btn-warning" title="Post eligible outlet and transport bills to the folio" aria-label="Post eligible bills for {{ $guestName }}">Post bills</button></form>@endif
                  <a class="btn btn-sm btn-primary" href="{{ route('frontdesk.checkout', $s) }}" aria-label="Check out {{ $guestName }} for reservation {{ $reservation?->reservation_no }}">Check out</a>
                @endif
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @else
      <div class="departure-empty" role="status">
        <span class="departure-empty-mark" aria-hidden="true">✓</span>
        <div><strong>No departures in this view</strong><p>{{ request('q') ? 'No stays match this search for '.$date->format('d M Y').'. Clear the search or choose another date.' : 'There are no departures scheduled for '.$date->format('l, d F Y').'.' }}</p></div>
        @if(request('q') || request('tab') || request('focus'))<a class="btn btn-sm" href="{{ route('reservations.index', ['view' => 'departures', 'date' => $date->toDateString()]) }}">Clear departure filters</a>@endif
      </div>
    @endif
  </section>
@elseif(($mode ?? '') === 'inhouse')
  @php
    $canViewFolio = can_do('folio.view');
    $canSeeHousekeeping = can_do('housekeeping.view') || can_do('housekeeping.manage') || can_do('housekeeping.attendant');
    $canCheckout = can_do('frontdesk.checkout');
    $canMoveRoom = can_do('frontdesk.roommove');
    $businessDate = bdate();
    $businessDay = $businessDate->toDateString();
  @endphp
  <section class="inhouse-manifest" aria-labelledby="inhouseManifestTitle">
    <div class="inhouse-manifest-head">
      <div>
        <span class="inhouse-manifest-kicker">LIVE STAY ROSTER</span>
        <h3 id="inhouseManifestTitle">In-house manifest</h3>
        <p>One row per room stay. Checkout and room-move actions continue through their existing permission-checked flows.</p>
      </div>
      <span class="inhouse-manifest-count"><strong>{{ number_format($rows->count()) }}</strong> {{ \Illuminate\Support\Str::plural('stay', $rows->count()) }}</span>
    </div>
    @if($rows->isNotEmpty())
      <div class="inhouse-table-wrap">
        <table class="inhouse-table">
          <caption class="sr-only">Currently checked-in stays for business date {{ bdate()->format('l, d F Y') }}</caption>
          <thead><tr><th scope="col">Guest &amp; booking</th><th scope="col">Stay &amp; departure</th><th scope="col">Room</th>@if($canViewFolio)<th scope="col">Folio balance</th>@endif<th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
          @foreach($rows as $s)
            @php
              $reservation = $s->reservation;
              $guest = $reservation?->guest;
              $guestName = $guest?->full_name ?? 'Guest profile unavailable';
              $guestPhone = trim((string) ($guest?->phone ?? ''));
              $callPhone = preg_match('/^\\+?[0-9][0-9 .()-]{5,}$/', $guestPhone) ? preg_replace('/[^0-9+]/', '', $guestPhone) : null;
              $folio = $canViewFolio && $s->relationLoaded('folio') ? $s->folio : null;
              $balance = $folio ? (float) ($inhouseMoneyFlags[$s->id]['balance'] ?? 0) : null;
              $balanceLabel = $balance === null ? 'No folio' : ($balance < -0.009 ? money(abs($balance)).' credit' : ($balance > 0.009 ? money($balance).' due' : money(0)));
              $departureDate = $s->departure_date->toDateString();
              $departureState = $departureDate < $businessDay ? 'Past due' : ($departureDate === $businessDay ? 'Due out today' : 'Stayover');
              $departureTone = $departureDate < $businessDay ? 'overdue' : ($departureDate === $businessDay ? 'due' : 'stayover');
              $nights = max(1, (int) $s->arrival_date->diffInDays($s->departure_date));
              $nightsRemaining = $departureDate > $businessDay ? max(1, (int) $businessDate->diffInDays($s->departure_date)) : 0;
              $roomStatus = $s->room ? ucfirst(str_replace('_', ' ', $s->room->hk_status)) : 'Unassigned';
            @endphp
            <tr>
              <td data-label="Guest &amp; booking">
                <div class="inhouse-guest-cell"><a class="inhouse-guest-name" href="{{ route('reservations.show', $reservation) }}">{{ $guestName }}</a>@if($guest?->is_vip)<span class="inhouse-vip">VIP</span>@endif</div>
                <a class="inhouse-booking-ref" href="{{ route('reservations.show', $reservation) }}">{{ $reservation?->reservation_no ?? 'Reservation details' }}</a>
                <div class="inhouse-booking-meta">
                  <span>{{ $reservation?->source?->name ?? 'Direct booking' }}</span>
                  @if($reservation?->booking_type === 'group')<span>Group · {{ $reservation->group_name ?: 'Group booking' }}</span>@endif
                  @if($reservation?->company)<span>Company · {{ $reservation->company->name }}</span>@endif
                  @if(can_do('guests.view') && $guestPhone)@if($callPhone)<a href="tel:{{ $callPhone }}" aria-label="Call {{ $guestName }} at {{ $guestPhone }}">{{ $guestPhone }}</a>@else<span>{{ $guestPhone }}</span>@endif @endif
                </div>
              </td>
              <td data-label="Stay &amp; departure">
                <div class="inhouse-stay-dates"><time datetime="{{ $s->arrival_date->toDateString() }}">{{ $s->arrival_date->format('d M Y') }}</time><span aria-hidden="true">→</span><time datetime="{{ $s->departure_date->toDateString() }}">{{ $s->departure_date->format('d M Y') }}</time></div>
                <span class="inhouse-stay-meta">{{ $nights }} {{ \Illuminate\Support\Str::plural('night', $nights) }} · {{ $s->adults }} {{ \Illuminate\Support\Str::plural('adult', $s->adults) }}@if($s->children) · {{ $s->children }} {{ \Illuminate\Support\Str::plural('child', $s->children) }}@endif</span>
                <span class="inhouse-departure inhouse-departure-{{ $departureTone }}">{{ $departureState }}@if($departureTone === 'stayover') · {{ $nightsRemaining }} {{ \Illuminate\Support\Str::plural('night', $nightsRemaining) }} left @endif</span>
              </td>
              <td data-label="Room">
                @if($s->room)<a class="inhouse-room-number" href="{{ route('reservations.room', $s->room) }}" aria-label="Open room {{ $s->room->room_no }}">Room {{ $s->room->room_no }}</a>@else<strong class="inhouse-room-number">Room unassigned</strong>@endif
                <span class="inhouse-room-type">{{ $s->roomType?->name ?? 'Room type unavailable' }}</span>
                @if($s->room && $canSeeHousekeeping)<span class="inhouse-hk inhouse-hk-{{ $s->room->hk_status }}">{{ $roomStatus }} · housekeeping</span>@elseif(!$s->room)<span class="inhouse-hk inhouse-hk-unassigned">Room assignment needs review</span>@endif
              </td>
              @if($canViewFolio)<td data-label="Folio balance"><span class="inhouse-balance-label">Current balance</span><span class="inhouse-balance">{{ $balanceLabel }}</span>@if($folio)<a class="inhouse-folio-link" href="{{ route('folios.show', $folio) }}" aria-label="Open folio for {{ $guestName }}">Open folio</a>@endif</td>@endif
              <td data-label="Actions" class="inhouse-actions">
                <a class="btn btn-sm" href="{{ route('reservations.stay', $s) }}" aria-label="Open stay for {{ $guestName }}">Open stay</a>
                @if($canMoveRoom)<a class="btn btn-sm" href="{{ route('frontdesk.move', $s) }}" aria-label="Move room for {{ $guestName }}">Move room</a>@endif
                @if($canViewFolio && $folio)<a class="btn btn-sm" href="{{ route('folios.invoice', $folio) }}" target="_blank" rel="noopener" aria-label="Open invoice for {{ $guestName }}">Invoice</a>@endif
                @if($canCheckout)<a class="btn btn-sm btn-primary" href="{{ route('frontdesk.checkout', $s) }}" aria-label="Check out {{ $guestName }} for reservation {{ $reservation?->reservation_no }}">Check out</a>@endif
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @else
      <div class="inhouse-empty" role="status">
        <span class="inhouse-empty-mark" aria-hidden="true">⌂</span>
        <div><strong>{{ request('q') || request('focus') ? 'No in-house stays match this view' : 'No checked-in stays right now' }}</strong><p>{{ request('q') ? 'Try another guest, booking, company or room search.' : (request('focus') ? 'There are no stays in this follow-up group at the moment.' : 'When a guest checks in, their active stay will appear here.') }}</p></div>
        @if(request('q') || request('focus'))<a class="btn btn-sm" href="{{ route('reservations.index', ['view' => 'inhouse']) }}">Clear in-house filters</a>@endif
      </div>
    @endif
  </section>
@elseif(($mode ?? '') === 'unassigned')
  @php $canAssignRoom = can_do('frontdesk.roommove') || can_do('reservations.edit'); @endphp
  <section class="unassigned-manifest" aria-labelledby="unassignedManifestTitle">
    <div class="unassigned-manifest-head">
      <div>
        <span class="unassigned-manifest-kicker">ROOM ALLOCATION WORKLIST</span>
        <h3 id="unassignedManifestTitle">Unassigned stay manifest</h3>
        <p>Each reservation room is listed separately. Room availability is checked in the existing assignment flow before it is saved.</p>
      </div>
      <span class="unassigned-manifest-count"><strong>{{ number_format($rows->count()) }}</strong> {{ \Illuminate\Support\Str::plural('stay', $rows->count()) }}</span>
    </div>
    @if($rows->isNotEmpty())
      <div class="unassigned-table-wrap">
        <table class="unassigned-table">
          <caption class="sr-only">Reserved stays without a room for desk date {{ $date->format('l, d F Y') }}</caption>
          <thead><tr><th scope="col">Guest &amp; booking</th><th scope="col">Stay &amp; arrival</th><th scope="col">Requested room</th><th scope="col">Assignment</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
          @foreach($rows as $s)
            @php
              $reservation = $s->reservation;
              $guest = $reservation?->guest;
              $guestName = $guest?->full_name ?? 'Guest profile unavailable';
              $guestPhone = trim((string) ($guest?->phone ?? ''));
              $callPhone = preg_match('/^\\+?[0-9][0-9 .()-]{5,}$/', $guestPhone) ? preg_replace('/[^0-9+]/', '', $guestPhone) : null;
              $arrivalDate = $s->arrival_date->toDateString();
              $arrivalLabel = $arrivalDate < $date->toDateString() ? 'Past arrival' : ($arrivalDate === $date->toDateString() ? 'Arrives on desk date' : 'Arrives in '.(int) $date->diffInDays($s->arrival_date).' days');
              $arrivalTone = $arrivalDate < $date->toDateString() ? 'overdue' : ($arrivalDate === $date->toDateString() ? 'due' : 'upcoming');
              $nights = max(1, (int) $s->arrival_date->diffInDays($s->departure_date));
            @endphp
            <tr>
              <td data-label="Guest &amp; booking">
                <div class="unassigned-guest-cell"><span class="unassigned-guest-name">{{ $guestName }}</span>@if($guest?->is_vip)<span class="unassigned-vip">VIP</span>@endif</div>
                <span class="unassigned-booking-ref">{{ $reservation?->reservation_no ?? 'Reservation details' }}</span>
                <div class="unassigned-booking-meta">
                  <span>{{ $reservation?->source?->name ?? 'Direct booking' }}</span>
                  @if($reservation?->booking_type === 'group')<span>Group · {{ $reservation->group_name ?: 'Group booking' }}</span>@endif
                  @if($reservation?->company)<span>Company · {{ $reservation->company->name }}</span>@endif
                  @if(can_do('guests.view') && $guestPhone)@if($callPhone)<a href="tel:{{ $callPhone }}" aria-label="Call {{ $guestName }} at {{ $guestPhone }}">{{ $guestPhone }}</a>@else<span>{{ $guestPhone }}</span>@endif @endif
                </div>
              </td>
              <td data-label="Stay &amp; arrival">
                <div class="unassigned-stay-dates"><time datetime="{{ $s->arrival_date->toDateString() }}">{{ $s->arrival_date->format('d M Y') }}</time><span aria-hidden="true">→</span><time datetime="{{ $s->departure_date->toDateString() }}">{{ $s->departure_date->format('d M Y') }}</time></div>
                <span class="unassigned-stay-meta">{{ $nights }} {{ \Illuminate\Support\Str::plural('night', $nights) }} · {{ $s->adults }} {{ \Illuminate\Support\Str::plural('adult', $s->adults) }}@if($s->children) · {{ $s->children }} {{ \Illuminate\Support\Str::plural('child', $s->children) }}@endif</span>
                <span class="unassigned-arrival unassigned-arrival-{{ $arrivalTone }}">{{ $arrivalLabel }}</span>
              </td>
              <td data-label="Requested room"><strong class="unassigned-room-type">{{ $s->roomType?->name ?? 'Room type unavailable' }}</strong><span class="unassigned-room-code">{{ $s->roomType?->code ?? 'No room-type code' }}</span></td>
              <td data-label="Assignment"><span class="unassigned-status">Room not assigned</span><span class="unassigned-assignment-note">Choose a room from the stay page. Availability is rechecked before assignment.</span></td>
              <td data-label="Actions" class="unassigned-actions">
                <a class="btn btn-sm" href="{{ route('reservations.stay', $s) }}" aria-label="Open stay for {{ $guestName }}">Open stay</a>
                @if($canAssignRoom)<a class="btn btn-sm btn-primary" href="{{ route('reservations.stay', $s) }}#stay-room-assignment" aria-label="Assign a room to {{ $guestName }} for reservation {{ $reservation?->reservation_no }}">Assign room</a>@endif
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @else
      <div class="unassigned-empty" role="status">
        <span class="unassigned-empty-mark" aria-hidden="true">✓</span>
        <div><strong>{{ request('q') || request('focus') ? 'No unassigned stays match this view' : 'All reserved stays have a room' }}</strong><p>{{ request('q') ? 'Try another guest, booking, company or room-type search.' : (request('focus') ? 'There are no stays in this arrival group at the moment.' : 'There are no reserved room stays without an assignment for this desk date.') }}</p></div>
        @if(request('q') || request('focus'))<a class="btn btn-sm" href="{{ route('reservations.index', ['view' => 'unassigned', 'date' => $date->toDateString()]) }}">Clear unassigned filters</a>@endif
      </div>
    @endif
  </section>
@else
<div class="card flush"><div class="card-body table-wrap">
  <table class="table">
    <thead><tr><th>Reservation</th><th>Guest</th><th>Room</th><th>Ready</th><th>ETA</th><th>Balance</th>@if(($mode ?? '') === 'departure')<th>Holds</th><th>To settle</th>@endif<th>Dates</th><th>Status</th><th></th></tr></thead>
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
        @if(($mode ?? '') === 'departure')
          @php $holds = $s->status === 'checked_in' ? \App\Services\StayService::checkoutHolds($s) : null; @endphp
          <td class="small">@if($s->status === 'reserved')Not checked in.@elseif($s->status === 'checked_out')Checked out.@elseif($holds['blocking'] > 0)Checkout blocked.@if($holds['pos']['count']) POS {{ $holds['pos']['count'] }} ({{ money($holds['pos']['total']) }}).@endif @if($holds['trips']['count']) Transport {{ money($holds['trips']['total']) }}.@endif @if($holds['laundry']['count']) Laundry {{ $holds['laundry']['count'] }} order(s).@endif @if($holds['activities']['count']) Activity {{ money($holds['activities']['total']) }}.@endif @if($holds['formc']['count']) Form C {{ $holds['formc']['form_no'] }}.@endif
            @if($holds['pos']['count'] || $holds['trips']['count'])
            <form method="post" action="{{ route('frontdesk.prepare', $s) }}" style="display:inline">@csrf<button class="btn btn-sm btn-warning" title="Post outlet bills and transport onto the folio">Post to bill</button></form>
            @endif @else No hold on file.@endif</td>
          <td class="small">@if($s->status !== 'checked_in')—@else @php $due = \App\Services\StayService::quoteCheckoutFigures($s)['due']; @endphp {{ $due === null ? 'No folio' : money($due) }}<div class="tiny muted">Not posted</div>@endif</td>
        @endif
        <td class="nowrap">{{ $s->arrival_date->format('d M') }} – {{ $s->departure_date->format('d M') }}</td>
        <td>{!! status_badge($s->status) !!}</td>
        <td class="nowrap">
          <a class="btn btn-sm" href="{{ route('reservations.stay', $s) }}">Open</a>
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
      <tr><td colspan="{{ ($mode ?? '') === 'departure' ? 11 : 9 }}">Nothing in this list for {{ $date->format('d M Y') }}.</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>
@endif
