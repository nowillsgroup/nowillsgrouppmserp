@extends('partner.layout')
@section('title', 'Welcome, '.\Illuminate\Support\Str::before($partner->name, ' '))
@section('content')
<div class="ptn-hero" id="ptnHero">
  <div><h2>{{ $company->name }}</h2><p>{{ $welcome }}</p>
    @if($contract)<p class="small mt-1" id="ptnContract"><x-icon name="shield" size="sm"/> Contract <b>{{ $contract->code }}</b> · valid to {{ $contract->valid_to->format('d M Y') }} · {{ $contract->pricing === 'net' ? 'net rates' : rtrim(rtrim(number_format($contract->discount_pct, 2), '0'), '.').'% off the public rate' }}{{ $contract->meal_plan ? ' · '.$contract->meal_plan : '' }} · free cancellation up to {{ $contract->cancel_days }} day(s) before arrival</p>
    @elseif((float) $company->discount_pct > 0)<p class="small mt-1"><x-icon name="star" size="sm"/> Your rate: best available rate less {{ rtrim(rtrim(number_format($company->discount_pct, 2), '0'), '.') }}%</p>@endif
  </div>
  @if($partner->can('book'))<a class="btn btn-lg" href="{{ route('partner.book') }}" id="ptnBookCta"><x-icon name="calendar"/> Book a stay</a>@endif
</div>

<div class="stats" id="ptnStats">
  <x-stat label="Arrivals next 60 days" :value="$arrivals->count()" icon="calendar" tone="blue" :href="route('partner.bookings')"/>
  <x-stat label="Travellers in house" :value="$inHouse->count()" icon="bed" tone="teal" :href="route('partner.bookings', ['tab' => 'inhouse'])"/>
  <x-stat label="Room nights {{ bdate()->year }}" :value="number_format($ytd['nights'])" icon="moon" tone="purple" :sub="$ytd['stays'].' stay(s)'"/>
  <x-stat label="Stay value {{ bdate()->year }}" :value="money($ytd['value'])" icon="trend" tone="green"/>
  @if($credit && $partner->can('finance'))
    <x-stat label="Outstanding balance" :value="money($credit['outstanding'])" icon="receipt" :tone="$credit['overdue'] > 0 ? 'red' : 'amber'" :sub="$credit['overdue'] > 0 ? money($credit['overdue']).' overdue' : 'nothing overdue'" :href="route('partner.statement')"/>
    @if($credit['limit'] > 0)<x-stat label="Credit available" :value="money($credit['available'])" icon="wallet" tone="slate" :sub="'of '.money($credit['limit']).' limit'"/>@endif
  @endif
  @if($commission_due !== null && $partner->can('finance'))<x-stat label="Commission due to you" :value="money($commission_due)" icon="money" tone="green" :href="route('partner.commissions')"/>@endif
  @if($pending)<x-stat label="Open requests" :value="$pending" icon="message" tone="amber" :href="route('partner.requests')"/>@endif
</div>

<div class="g-2">
  <div class="card" id="ptnArrivals"><div class="card-head"><h3><x-icon name="calendar"/> Upcoming arrivals</h3><a class="small" href="{{ route('partner.bookings') }}">All bookings</a></div><div class="card-body table-wrap">
    @if($arrivals->isEmpty())<p class="muted m-0">No arrivals in the next 60 days.</p>@else
    <table class="table table-compact"><thead><tr><th>Booking</th><th>Traveller</th><th>Dates</th><th>Rooms</th><th>Status</th></tr></thead><tbody>
    @foreach($arrivals as $r)
      <tr><td><a href="{{ route('partner.booking', $r->id) }}"><b>{{ $r->reservation_no }}</b></a>@if($r->partner_ref)<div class="tiny muted">{{ $r->partner_ref }}</div>@endif</td>
        <td>{{ $r->guest?->full_name }}</td><td class="nowrap">{{ $r->arrival_date->format('d M') }} – {{ $r->departure_date->format('d M') }}</td>
        <td>{{ $r->rooms->where('status', '!=', 'cancelled')->count() }} × {{ $r->rooms->first()?->roomType?->name }}</td>
        <td><span class="badge {{ $r->status === 'tentative' ? 'badge-amber' : 'badge-green' }}">{{ $r->status === 'tentative' ? 'Awaiting hotel' : 'Confirmed' }}</span></td></tr>
    @endforeach
    </tbody></table>@endif
  </div></div>
  <div class="card" id="ptnInHouse"><div class="card-head"><h3><x-icon name="bed"/> In house now</h3></div><div class="card-body table-wrap">
    @if($inHouse->isEmpty())<p class="muted m-0">None of your travellers is staying tonight.</p>@else
    <table class="table table-compact"><thead><tr><th>Traveller</th><th>Room</th><th>Departs</th><th>Booking</th></tr></thead><tbody>
    @foreach($inHouse as $s)
      <tr><td><b>{{ $s->guest?->full_name }}</b></td><td>{{ $s->room?->room_no ?? '—' }}</td><td>{{ $s->departure_date->format('d M') }}</td><td><a href="{{ route('partner.booking', $s->reservation_id) }}">{{ $s->reservation?->reservation_no }}</a></td></tr>
    @endforeach
    </tbody></table>@endif
  </div></div>
</div>
@endsection
