@extends('layouts.app')
@section('title', 'Concierge Desk')
@section('crumbs') Concierge / Desk @endsection
@php $B = \App\Models\ActivityBooking::class; $canBook = can_do('concierge.book'); $isToday = $date->isSameDay(bdate()); @endphp
@section('actions')
  <a class="btn" href="{{ route('concierge.guide.print') }}" target="_blank"><x-icon name="print"/> Guest guide sheet</a>
  @if($canBook)<a class="btn btn-primary" href="{{ route('concierge.create') }}" id="newBookingBtn"><x-icon name="plus"/> New booking</a>@endif
@endsection
@section('content')
@if($alerts['at_risk'])<div class="alert alert-warn" id="riskAlert"><x-icon name="alert"/> {{ $alerts['at_risk'] }} departure(s) in the next 3 days are below the operator's minimum number of guests – check with the vendor whether they will run.</div>@endif

<div class="stats tr-stats" id="cgStats">
  <x-stat label="Sold this month" :value="$stats['bookings'].' · '.$stats['pax'].' guests'" icon="globe" tone="blue" :sub="money($stats['gross']).' sold for vendors'"/>
  <x-stat label="Commission earned (month)" :value="money($stats['commission'])" icon="money" tone="green" :sub="money($stats['pipeline']).' more on confirmed bookings'"/>
  <x-stat label="Awaiting the vendor" :value="$waiting->count()" icon="clock" :tone="$alerts['slow'] ? 'red' : 'amber'" :sub="$alerts['slow'] ? $alerts['slow'].' waiting over '.$cfg['confirm_hours'].' h' : 'requests to confirm'"/>
  <x-stat label="Vendor balances" :value="money($payable - $receivable)" icon="wallet" tone="purple" :sub="'owe vendors '.money($payable).' · commission due '.money($receivable)" :href="route('concierge.settlements')"/>
</div>

<div class="g-2-1 mt-1">
<div class="card flush" id="boardCard"><div class="card-head"><h3><x-icon name="calendar"/> {{ $isToday ? 'Today' : $date->format('l') }} · {{ $date->format('d M Y') }}</h3>
  <form method="get" class="flex gap-1" style="align-items:center">
    <a class="btn btn-sm btn-ghost" href="{{ route('concierge.index', ['date' => $date->copy()->subDay()->toDateString()]) }}" aria-label="Previous day">‹</a>
    <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()" id="boardDate" aria-label="Board date">
    <a class="btn btn-sm btn-ghost" href="{{ route('concierge.index', ['date' => $date->copy()->addDay()->toDateString()]) }}" aria-label="Next day">›</a>
  </form></div>
<div class="card-body table-wrap"><table class="table table-compact" id="boardTable">
  <thead><tr><th>Time</th><th>Activity</th><th>Guests</th><th class="num">Seats</th><th></th></tr></thead>
  <tbody>@forelse($board as $row) @php $dep = $row['departure']; @endphp
    <tr data-row="{{ $row['key'] }}" class="{{ $row['at_risk'] ? 'row-due' : '' }} {{ $dep?->status === 'cancelled' ? 'muted' : '' }}">
      <td class="bold">{{ $row['time'] }}</td>
      <td><b>{{ $row['activity']->name }}</b><div class="tiny muted">{{ $row['activity']->vendor?->name }}{{ $dep?->guide ? ' · guide '.$dep->guide : '' }}@if($dep && $dep->status !== 'open') · <span class="badge badge-{{ $dep->status === 'cancelled' ? 'red' : 'slate' }}">{{ \App\Models\ActivityDeparture::STATUSES[$dep->status] }}</span>@endif{{ $row['at_risk'] ? ' · below minimum of '.$row['activity']->min_pax : '' }}</div></td>
      <td class="small">@foreach($row['bookings'] as $b)<div><a href="{{ route('concierge.booking', $b) }}">{{ $b->number }}</a> {{ $b->guest_name }}{{ $b->room_no ? ' · rm '.$b->room_no : '' }} · {{ $b->pax() }} <span class="badge badge-{{ $B::TONES[$b->status] }}">{{ $b->statusLabel() }}</span></div>@endforeach</td>
      <td class="num">{{ $row['pax'] }}{{ $row['capacity'] ? ' / '.$row['capacity'] : '' }}</td>
      <td class="right">@if($dep)<a class="btn btn-sm" href="{{ route('concierge.manifest', $dep) }}" target="_blank" title="Manifest for the operator"><x-icon name="print"/></a>@endif</td>
    </tr>
  @empty<tr><td colspan="5" class="empty">No activities booked for this day.</td></tr>@endforelse</tbody>
</table></div></div>

<div>
<div class="card flush" id="waitingCard"><div class="card-head"><h3><x-icon name="clock"/> Awaiting vendor confirmation</h3></div>
<div class="card-body table-wrap"><table class="table table-compact">
  <tbody>@forelse($waiting as $b)
    <tr data-waiting="{{ $b->number }}" class="{{ $b->created_at->lt(now()->subHours($cfg['confirm_hours'])) ? 'row-due' : '' }}"><td class="small"><a href="{{ route('concierge.booking', $b) }}"><b>{{ $b->number }}</b></a> {{ $b->activity->name }}
      <div class="tiny muted">{{ $b->date->format('D d M') }} {{ $b->time }} · {{ $b->guest_name }} · {{ $b->pax() }} pax · {{ $b->activity->vendor->name }}{{ $b->activity->vendor->phone ? ' '.$b->activity->vendor->phone : '' }}</div></td>
      <td class="right">@if($canBook)<form method="post" action="{{ route('concierge.confirm', $b) }}">@csrf<button class="btn btn-sm" title="Vendor confirmed">Confirm</button></form>@endif</td></tr>
  @empty<tr><td class="empty">Nothing waiting.</td></tr>@endforelse</tbody>
</table></div></div>

<div class="card flush mt-1" id="waitlistCard"><div class="card-head"><h3><x-icon name="users"/> Waitlist</h3></div>
<div class="card-body table-wrap"><table class="table table-compact">
  <tbody>@forelse($waitlist as $b)
    <tr data-wait="{{ $b->number }}"><td class="small"><a href="{{ route('concierge.booking', $b) }}"><b>{{ $b->number }}</b></a> {{ $b->activity->name }}<div class="tiny muted">{{ $b->date->format('D d M') }} {{ $b->time }} · {{ $b->guest_name }} · {{ $b->pax() }} pax</div></td></tr>
  @empty<tr><td class="empty">Nobody on the waitlist.</td></tr>@endforelse</tbody>
</table></div></div>
<p class="tiny muted mt-1">{{ $upcoming }} booking(s) in the next 7 days. Confirmed activities are completed at the night audit and the commission booked; requests the vendor never confirmed lapse.</p>
</div>
</div>
@endsection
