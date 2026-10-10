@extends('portal.layout')
@section('title', 'My stay')
@if($state === 'departed')
  @section('heading', 'Thank you'.($guest?->first_name ? ', '.$guest->first_name : '').'!')
  @section('sub', 'We hope you enjoyed your stay.')
@elseif($state === 'upcoming')
  @section('sub', 'We look forward to welcoming you on '.$stay->arrival_date->format('l, d M Y'))
@endif
@section('content')
@php $upsellOn = $state !== 'departed' && \App\Services\UpsellService::enabled() && \App\Services\UpsellService::offersFor($stay, $state === 'upcoming' ? 'pre_arrival' : 'portal')->isNotEmpty(); @endphp

@if($state === 'upcoming')
  <div class="gp-card"><h3>Your booking</h3>
    <div class="gp-kv"><span>Booking</span><b>{{ $stay->reservation->reservation_no }}</b></div>
    <div class="gp-kv"><span>Arrival</span><span>{{ $stay->arrival_date->format('D d M Y') }} · from {{ substr($hotel->checkin_time, 0, 5) }}</span></div>
    <div class="gp-kv"><span>Departure</span><span>{{ $stay->departure_date->format('D d M Y') }} · by {{ substr($hotel->checkout_time, 0, 5) }}</span></div>
    <div class="gp-kv"><span>Room type</span><span>{{ $stay->roomType?->name }}</span></div>
    <div class="gp-kv"><span>Guests</span><span>{{ $stay->adults }} adult(s){{ $stay->children ? ', '.$stay->children.' child(ren)' : '' }}</span></div>
  </div>
  @if(setting('online_checkin_enabled', '1') === '1' && $stay->reservation->onlineCheckinOpen())
    <a class="btn btn-primary btn-lg btn-block mb-1" href="{{ $stay->reservation->checkinUrl() }}"><x-icon name="login"/> Check in online – skip the queue</a>
  @endif
  @if($upsellOn)<a class="btn btn-block mb-1" href="{{ $stay->reservation->offersUrl() }}" id="gpUpgradeBtn"><x-icon name="layers"/> Upgrade &amp; enhance your stay</a>@endif
  @if($transportOn)<a class="btn btn-block mb-1" href="{{ route('portal.transport', $token) }}" id="gpBookPickup"><x-icon name="plane"/> Book an airport pick-up</a>@endif
  <a class="btn btn-block mb-1" href="{{ route('portal.requests', $token) }}"><x-icon name="message"/> Message the front desk</a>
  @if($exploreOn ?? false)<a class="btn btn-block mb-1" href="{{ route('portal.explore', $token) }}" id="gpUpcomingExplore"><x-icon name="globe"/> Plan tours &amp; excursions for your stay</a>@endif
  <a class="btn btn-block mb-1" href="{{ route('portal.visitors', $token) }}" id="gpUpcomingVisitors"><x-icon name="users"/> Expecting visitors? Register them</a>
@elseif($state === 'departed')
  <div class="gp-card"><p class="m-0">Your final bill and invoice are available for a few more days. We'd love to hear how we did.</p></div>
  <a class="btn btn-primary btn-lg btn-block mb-1" href="{{ route('portal.bill', $token) }}#feedback"><x-icon name="star"/> Rate your stay</a>
  <a class="btn btn-block mb-1" href="{{ route('portal.bill', $token) }}"><x-icon name="receipt"/> View my bill</a>
@else
  @if($deskMessages)<a class="gp-card" href="{{ route('portal.messages', $token) }}" id="gpMsgBanner" style="display:flex;gap:.7rem;align-items:center;background:#fffbeb;border-color:#fcd34d;text-decoration:none;color:inherit"><x-icon name="mail"/><div><b>{{ $deskMessages }} new message{{ $deskMessages > 1 ? 's' : '' }} / parcel{{ $deskMessages > 1 ? 's' : '' }} for you</b><div class="tiny muted">Held at the front desk – tap to read</div></div></a>@endif
  <div class="gp-tiles">
    @if($chatOn ?? false)<a class="gp-tile" href="{{ route('portal.chat', $token) }}" id="gpTileChat"><span class="ic"><x-icon name="message"/></span><b>Chat with us</b><span>{{ ($chatUnread ?? 0) ? $chatUnread.' new message'.($chatUnread > 1 ? 's' : '').' from the hotel' : 'Message the front desk – we reply in minutes' }}</span></a>@endif
    <a class="gp-tile" href="{{ route('portal.requests', $token) }}"><span class="ic"><x-icon name="bell"/></span><b>Request service</b><span>Towels, cleaning, repairs, wake-up…</span></a>
    @if($diningOn)<a class="gp-tile" href="{{ route('portal.dining', $token) }}"><span class="ic"><x-icon name="cup"/></span><b>In-room dining</b><span>Order to your room</span></a>@endif
    @if($spaOn)<a class="gp-tile" href="{{ route('portal.spa', $token) }}"><span class="ic"><x-icon name="spa"/></span><b>Spa &amp; activities</b><span>Book a time slot</span></a>@endif
    @if($forexOn ?? false)<a class="gp-tile" href="{{ route('portal.exchange', $token) }}" id="gpTileExchange"><span class="ic"><x-icon name="globe"/></span><b>Currency exchange</b><span>Today's rates at the front desk</span></a>@endif
    @if($transportOn)<a class="gp-tile" href="{{ route('portal.transport', $token) }}" id="gpTileTransport"><span class="ic"><x-icon name="car"/></span><b>Airport drop &amp; cabs</b><span>Book a car with our driver</span></a>@endif
    @if($upsellOn)<a class="gp-tile" href="{{ $stay->reservation->offersUrl() }}" id="gpTileOffers"><span class="ic"><x-icon name="star"/></span><b>Extras &amp; late check-out</b><span>Add to your stay</span></a>@endif
    @if($exploreOn ?? false)<a class="gp-tile" href="{{ route('portal.explore', $token) }}" id="gpTileExplore"><span class="ic"><x-icon name="globe"/></span><b>Tours &amp; things to do</b><span>Book excursions · local guide</span></a>@endif
    <a class="gp-tile" href="{{ route('portal.visitors', $token) }}" id="gpTileVisitors"><span class="ic"><x-icon name="users"/></span><b>Visitors</b><span>Expecting someone? Register them</span></a>
    <a class="gp-tile" href="{{ route('portal.bill', $token) }}"><span class="ic"><x-icon name="receipt"/></span><b>My bill</b><span>Balance {{ money($balance) }}</span></a>
  </div>

  @php $esgC = \App\Services\EsgService::cfg(); $esgG = $esgC['green_enabled'] ? \App\Services\EsgService::activeGreen($stay) : null; @endphp
  @if($esgC['green_enabled'] && ($esgG || $stay->departure_date->toDateString() > bdate()->copy()->addDay()->toDateString()))
  <div class="gp-card" id="gpGreen" style="border-color:#86efac;background:#f0fdf4"><h3 class="flex items-center gap-1"><x-icon name="tree"/> Green stay</h3>
    @if($esgG)
      <p class="small m-0">You're on the green stay – thank you! We skip the daily clean{{ $esgC['green_every'] ? ' and give the room a full refresh every '.$esgC['green_every'].' nights' : '' }}. Fresh towels or a clean are still just a request away.</p>
      <form method="post" action="{{ route('portal.green', $token) }}" class="mt-1">@csrf<input type="hidden" name="choice" value="off"><button class="btn btn-sm" id="gpGreenOff">Switch daily housekeeping back on</button></form>
    @else
      <p class="small m-0">Skip the daily room clean and save water, energy and chemicals.@if(\App\Services\LoyaltyService::enabled() && $esgC['green_points']) Earn <b>{{ $esgC['green_points'] }} loyalty points</b> for every night.@endif We'll still refresh your room every {{ $esgC['green_every'] }} nights.</p>
      <form method="post" action="{{ route('portal.green', $token) }}" class="mt-1">@csrf<input type="hidden" name="choice" value="on"><button class="btn btn-primary btn-sm" id="gpGreenOn">Choose the green stay</button></form>
    @endif
  </div>
  @endif

  @if(setting('portal_wifi_name'))
  <div class="gp-card"><h3 class="flex items-center gap-1"><x-icon name="wifi"/> Wi-Fi</h3>
    <div class="gp-kv"><span>Network</span><b>{{ setting('portal_wifi_name') }}</b></div>
    @if(setting('portal_wifi_password'))<div class="gp-kv"><span>Password</span><span class="wifi-pass" id="wifiPass">{{ setting('portal_wifi_password') }}</span></div>@endif
  </div>
  @endif

  <div class="gp-card"><h3>Your stay</h3>
    <div class="gp-kv"><span>Room</span><b>{{ $stay->room?->room_no }} · {{ $stay->roomType?->name }}</b></div>
    <div class="gp-kv"><span>Check-out</span><span>{{ $stay->departure_date->format('D d M') }} by {{ substr($hotel->checkout_time, 0, 5) }}</span></div>
    <div class="gp-kv"><span>Booking</span><span>{{ $stay->reservation->reservation_no }}</span></div>
    @if($stay->departure_date->lte(bdate()->copy()->addDay()))
      <div class="mt-1 flex gap-1 wrap"><a class="btn btn-sm" href="{{ route('portal.bill', $token) }}#checkout"><x-icon name="logout" size="sm"/> Express check-out</a>
      <a class="btn btn-sm" href="{{ route('portal.requests', $token) }}?c=late_checkout"><x-icon name="moon" size="sm"/> Late check-out</a></div>
    @endif
  </div>

  @if($recent->isNotEmpty())
  <div class="gp-card"><h3 class="flex justify-between">Recent requests <a class="small" href="{{ route('portal.requests', $token) }}">All</a></h3>
    @foreach($recent as $r)<div class="gp-row"><div><b class="small">{{ $r->quantity > 1 ? $r->quantity.' × ' : '' }}{{ $r->item }}</b><div class="tiny muted">{{ $r->created_at->diffForHumans() }}</div></div><span class="st {{ $r->status }}">{{ \App\Models\GuestRequest::STATUSES[$r->status] }}</span></div>@endforeach
  </div>
  @endif

  @if(($wakeups ?? collect())->isNotEmpty())
  <div class="gp-card" id="gpWakeups"><h3>Wake-up calls</h3>
    @foreach($wakeups as $w)<div class="gp-row"><div><b class="small">{{ $w->call_at->format('D d M · H:i') }}</b>@if($w->daily)<div class="tiny muted">every day until check-out</div>@endif</div><span class="st acknowledged">Booked</span></div>@endforeach
  </div>
  @endif
  @if($spa->isNotEmpty())
  <div class="gp-card"><h3>Upcoming spa &amp; activities</h3>
    @foreach($spa as $a)<div class="gp-row"><div><b class="small">{{ $a->service?->name }}</b><div class="tiny muted">{{ $a->date->format('D d M') }} · {{ substr($a->start_time, 0, 5) }}–{{ substr($a->end_time, 0, 5) }}</div></div><span class="st booked">Booked</span></div>@endforeach
  </div>
  @endif
@endif

@if($info->isNotEmpty())
<div class="gp-card"><h3>Hotel information</h3>
  @foreach($info as $line)
    @if(str_contains($line, ':'))<div class="gp-kv"><span>{{ trim(\Illuminate\Support\Str::before($line, ':')) }}</span><span class="right">{{ trim(\Illuminate\Support\Str::after($line, ':')) }}</span></div>
    @else<p class="small m-0 mb-1">{{ $line }}</p>@endif
  @endforeach
</div>
@endif
@endsection
