@extends('layouts.app')
@section('title', 'CRO Desk')
@section('crumbs') Central Reservations / Desk @endsection
@php $E = \App\Models\CroEnquiry::class; $mgr = \App\Services\CroService::canManage(auth()->user()); @endphp
@section('actions')
  <a class="btn btn-icon" href="{{ route('cro.guests') }}" aria-label="Guest lookup" title="Guest lookup"><x-icon name="search"/></a>
  @if($canBook)<button class="btn btn-primary btn-icon" data-modal="croLogModal" data-fasked_hotel_id="{{ hotel()->id }}" data-foutcome="open" id="logEnquiryBtn" aria-label="Log an enquiry" title="Log enquiry"><x-icon name="phone"/></button>@endif
@endsection
@section('content')
<div class="cro-workspace">
@include('cro._nav')
@if($booked)
<div class="alert alert-success" id="croBooked"><x-icon name="check"/>
  <span>{{ $booked['referral'] ? 'Referral booked at' : 'Booked at' }} <b>{{ $booked['hotel'] }}</b>:
  @if($booked['hotel_id'] === hotel()->id)<a href="{{ route('reservations.show', $booked['id']) }}"><b>{{ $booked['no'] }}</b></a>@else<b>{{ $booked['no'] }}</b>@endif
  · {{ money($booked['value']) }} · enquiry {{ $booked['enquiry'] }}{{ $booked['referral'] ? ' – kept in the group' : '' }}</span>
</div>
@endif
<header class="cro-hero">
  <div class="cro-hero-copy">
    <span class="cro-eyebrow"><x-icon name="hotel" size="sm"/> CENTRAL RESERVATIONS <i aria-hidden="true">/</i> CRO DESK</span>
    <h2>Turn every room enquiry into a clear next step.</h2>
    <p>Check live availability, quote the stay, book at the requested hotel—or refer the guest to a sister property and keep the revenue in the group.</p>
    <div class="cro-hero-meta"><span><x-icon name="building" size="sm"/> {{ hotel()->name }}</span><span><x-icon name="calendar" size="sm"/> Business date · {{ bdate()->format('D, d M Y') }}</span></div>
  </div>
  <div class="cro-hero-side"><span class="cro-hero-side-label">SEARCH NETWORK</span><strong>{{ $group ? $hotels->count().' properties' : 'This property' }}</strong><small>{{ $group ? 'Availability compared across your group' : 'Availability and rates for this hotel' }}</small></div>
</header>
<div class="stats cro-stats" id="croStats">
  <x-stat label="Enquiries {{ bdate()->format('M') }}" :value="$kpi['total']" icon="phone" tone="blue" :sub="$kpi['open'].' open · '.$kpi['booked'].' booked · '.$kpi['referred'].' referred'" :href="route('cro.enquiries')"/>
  <x-stat label="Conversion" :value="$kpi['conversion'] !== null ? $kpi['conversion'].'%' : '—'" icon="trend" :tone="$kpi['conversion'] === null ? 'slate' : ($kpi['conversion'] >= $kpi['target'] ? 'green' : 'amber')" :sub="'target '.rtrim(rtrim(number_format($kpi['target'], 1), '0'), '.').'% · group retention '.($kpi['retention'] !== null ? $kpi['retention'].'%' : '—')" :href="route('cro.performance')"/>
  <x-stat label="Booked by CRO" :value="money_short($kpi['booked_revenue'])" icon="money" tone="green" :sub="number_format($kpi['booked_nights']).' room nights'.($kpi['referred_revenue'] ? ' · '.money_short($kpi['referred_revenue']).' to sisters' : '')"/>
  <x-stat label="Lost business" :value="money_short($kpi['lost_revenue'])" icon="alert" tone="red" :sub="$kpi['denials'].' denials · '.$kpi['regrets'].' regrets'" :href="route('cro.lost')"/>
  <x-stat label="Follow-ups due" :value="$alerts['follow_ups']" icon="clock" :tone="$alerts['follow_ups'] ? 'amber' : 'slate'" :sub="$alerts['arriving'].' open, arriving within 3 days'" :href="route('cro.enquiries', ['status' => 'open'])"/>
</div>

@if($enq)
<div class="alert alert-info mt-1" id="croEnqBanner"><x-icon name="search"/><span>Finding rooms for enquiry <a href="{{ route('cro.enquiry', $enq) }}"><b>{{ $enq->number }}</b></a> – {{ $enq->caller_name }}
  ({{ $enq->statusLabel() }}{{ $enq->reasonLabel() ? ': '.$enq->reasonLabel() : '' }}). Booking at {{ hotel()->name }} closes it as booked; booking at a sister hotel records a referral.</span></div>
@endif

<section class="card cro-search-panel" id="croSearch" aria-labelledby="croSearchTitle">
  <div class="cro-search-heading"><div><span class="cro-step-label">01 · SEARCH THE STAY</span><h3 id="croSearchTitle">Find available rooms</h3><p>{{ $group ? 'Compare room types and live rates across your group.' : 'Check live room availability and rates for this property.' }}</p></div><span class="cro-search-network"><x-icon name="globe" size="sm"/> {{ $group ? $hotels->count().' hotels' : hotel()->name }}</span></div>
  <div class="card-body">
    <form method="get" class="cro-search-form" id="croSearchForm" role="search" aria-label="Search CRO room availability">@if($enq)<input type="hidden" name="enquiry" value="{{ $enq->id }}">@endif
      <div class="field cro-date-field"><label for="croArrival">Arrival</label><input id="croArrival" type="date" name="arrival" value="{{ $q['arrival'] }}" required></div>
      <div class="field cro-date-field"><label for="croDeparture">Departure</label><input id="croDeparture" type="date" name="departure" value="{{ $q['departure'] }}" required></div>
      <div class="field cro-count-field"><label for="croRooms">Rooms</label><input id="croRooms" type="number" name="rooms" min="1" max="20" value="{{ $q['rooms'] }}" required></div>
      <div class="field cro-count-field"><label for="croAdults">Adults</label><input id="croAdults" type="number" name="adults" min="1" max="80" value="{{ $q['adults'] }}" required></div>
      <div class="field cro-count-field"><label for="croChildren">Children</label><input id="croChildren" type="number" name="children" min="0" max="40" value="{{ $q['children'] }}"></div>
      @if($group && ! $enq)<div class="field cro-asked-field"><label for="croAskedHotel">Caller asked for</label><select id="croAskedHotel" name="asked">@foreach($hotels as $h)<option value="{{ $h->id }}" @selected($q['asked'] === $h->id)>{{ $h->name }}</option>@endforeach</select></div>@endif
      <button class="btn btn-primary cro-search-submit" type="submit"><x-icon name="search"/> Compare availability</button>
    </form>
    <div class="cro-search-summary" aria-label="Current requested stay" role="group"><span><b>{{ $nights }}</b> {{ \Illuminate\Support\Str::plural('night', $nights) }}</span><span><b>{{ $q['rooms'] }}</b> {{ \Illuminate\Support\Str::plural('room', $q['rooms']) }}</span><span><b>{{ (int) $q['adults'] + (int) $q['children'] }}</b> {{ \Illuminate\Support\Str::plural('guest', (int) $q['adults'] + (int) $q['children']) }}</span><span>{{ $group ? 'Group comparison' : 'Single property' }}</span></div>
    <p class="cro-search-help"><x-icon name="check" size="sm"/> Search uses live sellable inventory, rate rules, minimum stays and stop-sell restrictions.</p>
    @if($error)<div class="alert alert-error mt-1" role="alert">{{ $error }}</div>@endif
    @if($prefill)<p class="cro-prefill" id="croPrefill"><x-icon name="user"/> Booking for <b>{{ $prefill['name'] }}</b>{{ $prefill['hotel'] ? ' (profile at '.$prefill['hotel'].')' : '' }}
      @if($prefill['vip'])<span class="badge badge-purple">VIP</span>@endif @if($prefill['blacklisted'])<span class="badge badge-red">Blacklisted</span>@endif</p>@endif
  </div>
</section>

<div class="g-2-1 mt-1 cro-desk-columns">
  <section id="croResults" aria-label="Live availability results">
  @if($results)
    @php $askedId = $enq ? hotel()->id : $q['asked']; @endphp
    @foreach($results as $res)@php $h = $res['hotel']; $isAsked = $h->id === $askedId; $anyOk = $res['best'] !== null; @endphp
    <article class="card flush mb-1 cro-hotel{{ $isAsked ? ' cro-asked' : '' }}" data-hotel="{{ $h->code }}" aria-labelledby="croHotel{{ $h->id }}Title">
      <div class="card-head cro-hotel-head"><div class="cro-hotel-heading"><span class="cro-hotel-icon"><x-icon name="hotel"/></span><div><h3 id="croHotel{{ $h->id }}Title">{{ $h->name }}</h3><span class="badge {{ $isAsked ? 'badge-blue' : 'badge-purple' }}">{{ $isAsked ? 'Requested property' : 'Sister property' }}</span></div></div>
        <div class="cro-hotel-signal">@if($res['error'])<span class="badge badge-red">Search unavailable</span><small>{{ $res['error'] }}</small>@elseif($anyOk)<span>Best available from <b>{{ money($res['best']['average']) }}</b> / room / night</span><small>{{ $res['free'] }} room(s) currently free</small>@else<span class="badge badge-red">No availability</span><small>No requested room type can be booked for these dates.</small>@endif</div></div>
      @if(! $res['error'])
      <div class="card-body table-wrap cro-hotel-table-wrap"><table class="table table-compact cro-rate-table"><caption class="sr-only">Live room types, sellable inventory, nightly rates and stay totals at {{ $h->name }}.</caption><thead><tr><th scope="col">Room type</th><th scope="col" class="num">Free</th><th scope="col" class="num">Avg / night</th><th scope="col" class="num">{{ $nights }}-night stay · {{ $q['rooms'] }} room(s)</th><th scope="col"><span class="sr-only">Booking action</span></th></tr></thead><tbody>
        @foreach($res['types'] as $t)<tr data-type="{{ $t['type']->code }}"><td><b>{{ $t['type']->name }}</b><div class="tiny muted">Up to {{ $t['type']->max_adults ?: '—' }} adults</div></td>
          <td class="num"><span class="cro-free-count {{ $t['free'] ? 'has-rooms' : '' }}">{{ $t['free'] }}</span></td><td class="num">{{ money($t['average']) }}</td><td class="num cro-stay-total">{{ money($t['total']) }}</td>
          <td class="nowrap">@if($t['bookable'])@if($canBook)<button type="button" class="btn btn-sm {{ $isAsked ? 'btn-primary' : 'cro-refer-button' }}" aria-label="{{ $isAsked ? 'Book' : 'Refer and book' }} {{ $t['type']->name }} at {{ $h->name }} for {{ $nights }} nights" data-modal="croBookModal" data-title="{{ ($isAsked ? 'Book ' : 'Refer to ').$h->name.' – '.$t['type']->name }}"
              data-fhotel_id="{{ $h->id }}" data-froom_type_id="{{ $t['type']->id }}" data-fquote="{{ money($t['average']).' / night · '.money($t['total']).' total' }}">{{ $isAsked ? 'Book' : 'Refer & book' }}</button>@else<span class="badge badge-green">Available</span>@endif
            @else<span class="badge badge-slate">{{ $t['reason'] }}</span>@endif</td></tr>@endforeach
      </tbody></table>
      @if($canBook && $isAsked)<div class="pad-sm flex gap-1 wrap" style="padding:.5rem .9rem">@php $den = collect($res['types'])->pluck('denial')->filter()->first() ?? 'sold_out'; @endphp
        <button type="button" class="btn btn-sm btn-ghost" data-modal="croLogModal" data-fasked_hotel_id="{{ $h->id }}" data-foutcome="lost" data-floss_reason="{{ $anyOk ? 'price' : $den }}" id="logTurnaway{{ $h->id }}"><x-icon name="minus"/> Log {{ $anyOk ? 'regret' : 'denial' }}</button>
        @if(! $anyOk && $group)<span class="tiny muted" style="align-self:center">No rooms here – offer a sister hotel below to keep the guest in the group.</span>@endif
      </div>@endif
      </div>
      @endif
    </article>
    @endforeach
  @elseif($results === [])
    <div class="card cro-empty-state"><div class="card-body"><span class="cro-empty-icon"><x-icon name="hotel"/></span><div><h3>No properties to compare</h3><p>There are no hotels in the current CRO search scope.</p></div></div></div>
  @elseif($error)
    <div class="card cro-empty-state"><div class="card-body"><span class="cro-empty-icon"><x-icon name="alert"/></span><div><h3>Availability search needs attention</h3><p>Review the message above, adjust the stay details, and try again.</p></div></div></div>
  @else
    <div class="card cro-empty-state"><div class="card-body"><span class="cro-empty-icon"><x-icon name="search"/></span><div><h3>Search live availability</h3><p>Enter the guest’s stay above to compare sellable room types and rates{{ $group ? ' across every hotel in the group' : '' }}.</p></div></div></div>
  @endif
  </section>
  <aside class="cro-queue-column" aria-label="Enquiry follow-up workspace">
    <div class="card flush" id="croDue"><div class="card-head"><div><span class="eyebrow">Follow-up queue</span><h3><x-icon name="clock"/> Open enquiries</h3></div><a class="btn btn-sm btn-ghost" href="{{ route('cro.enquiries', ['status' => 'open']) }}">View all <span aria-hidden="true">→</span></a></div>
      <div class="card-body"><table class="table table-compact cro-queue-table"><caption class="sr-only">Open enquiries and their next follow-up times.</caption><tbody>
      @forelse($due as $d)<tr class="{{ $d->isFollowUpDue() ? 'row-alert' : '' }}"><td><a href="{{ route('cro.enquiry', $d) }}"><b>{{ $d->number }}</b> {{ $d->caller_name }}</a>
        <div class="tiny muted">{{ $d->arrival_date->format($d->arrival_date->year === $d->departure_date->year ? 'd M' : 'd M Y') }} – {{ $d->departure_date->format($d->arrival_date->year === $d->departure_date->year ? 'd M' : 'd M Y') }} · {{ $d->rooms }} rm · {{ $d->agent?->name }}</div></td>
        <td class="cro-follow-up-time">@if($d->follow_up_at)<span class="badge {{ $d->isFollowUpDue() ? 'badge-amber' : 'badge-slate' }}">{{ $d->isFollowUpDue() ? 'Due' : 'Next' }}</span><small>{{ $d->follow_up_at->format('d M · H:i') }}</small>@else<span class="muted">No time set</span>@endif</td></tr>
      @empty<tr><td colspan="2" class="cro-queue-empty"><x-icon name="check"/><span><b>All clear</b><small>No open enquiries in the follow-up queue.</small></span></td></tr>@endforelse
      </tbody></table></div></div>
    <div class="card mt-1 cro-outcome-note"><div class="card-body"><span class="cro-outcome-icon"><x-icon name="check"/></span><p>Close each room enquiry with a clear outcome: <b>booked</b>, <b>referred</b> to a sister property, or <b>lost</b>. Record a denial when we could not offer, or a regret when the guest declined. <a href="{{ route('cro.lost') }}">Review lost business →</a></p></div></div>
  </aside>
</div>

@if($canBook)
<div class="modal" id="croBookModal" role="dialog" aria-modal="true" aria-labelledby="croBookTitle" tabindex="-1"><div class="modal-box" style="max-width:46rem"><form method="post" action="{{ route('cro.book') }}">@csrf
  <div class="modal-head"><h3 id="croBookTitle" data-title>Book</h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close booking form" title="Close"><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <input type="hidden" name="hotel_id" value=""><input type="hidden" name="room_type_id" value="">
    <input type="hidden" name="arrival" value="{{ $q['arrival'] }}"><input type="hidden" name="departure" value="{{ $q['departure'] }}">
    <input type="hidden" name="rooms" value="{{ $q['rooms'] }}"><input type="hidden" name="adults" value="{{ $q['adults'] }}"><input type="hidden" name="children" value="{{ $q['children'] }}">
    @if($enq)<input type="hidden" name="enquiry_id" value="{{ $enq->id }}">@else<input type="hidden" name="asked_hotel_id" value="{{ $q['asked'] }}">@endif
    <div class="field span-all"><label for="croBookingQuote">Stay</label><input id="croBookingQuote" name="quote" readonly value="" class="muted"><span class="tiny muted">{{ \Carbon\Carbon::parse($q['arrival'])->format('D d M') }} → {{ \Carbon\Carbon::parse($q['departure'])->format('D d M Y') }} · {{ $q['rooms'] }} room(s) · {{ $q['adults'] }} adult(s){{ $q['children'] ? ' · '.$q['children'].' child(ren)' : '' }}</span></div>
    <div class="field"><label for="croBookingFirst">First name</label><input id="croBookingFirst" name="first_name" required maxlength="80" value="{{ old('first_name', $prefill['first_name'] ?? '') }}"></div>
    <div class="field"><label for="croBookingLast">Last name</label><input id="croBookingLast" name="last_name" maxlength="80" value="{{ old('last_name', $prefill['last_name'] ?? '') }}"></div>
    <div class="field"><label for="croBookingPhone">Phone</label><input id="croBookingPhone" name="phone" maxlength="30" value="{{ old('phone', $prefill['phone'] ?? '') }}"></div>
    <div class="field"><label for="croBookingEmail">E-mail</label><input id="croBookingEmail" type="email" name="email" maxlength="255" value="{{ old('email', $prefill['email'] ?? '') }}"></div>
    <div class="field"><label for="croBookingNationality">Nationality</label><input id="croBookingNationality" name="nationality" maxlength="60" value="{{ old('nationality', $prefill['nationality'] ?? 'Ghanaian') }}"></div>
    <div class="field"><label for="croBookingStatus">Status</label><select id="croBookingStatus" name="status"><option value="confirmed">Confirmed</option><option value="tentative">Tentative</option></select></div>
    <div class="field"><label for="croBookingChannel">Channel</label><select id="croBookingChannel" name="channel">@foreach($E::CHANNELS as $k => $l)@continue($k === 'referral')<option value="{{ $k }}" @selected(($enq?->channel ?? 'phone') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label for="croBookingDuration">Call length (min)</label><input id="croBookingDuration" type="number" name="duration_min" min="0" max="600" step="0.5"></div>
    @if($mgr)<div class="field"><label for="croBookingRate">Rate override / night</label><input id="croBookingRate" type="number" name="rate" min="0" step="0.01" placeholder="hotel rate"></div>@endif
    @if($group)<div class="field span-all"><label for="croBookingReferralReason">If this is a sister hotel: why could the asked hotel not keep the guest?</label><select id="croBookingReferralReason" name="referral_reason">@foreach($E::DENIALS as $k => $l)<option value="{{ $k }}">Denial – {{ $l }}</option>@endforeach @foreach(['price', 'location', 'product'] as $k)<option value="{{ $k }}">Regret – {{ $E::REGRETS[$k] }}</option>@endforeach</select></div>@endif
    <div class="field span-all"><label for="croBookingRequest">Special request</label><textarea id="croBookingRequest" name="special_request" rows="2" maxlength="500"></textarea></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Confirm booking</button></div>
</form></div></div>
@include('cro._log_modal', ['q' => $q])
@endif
@endsection
