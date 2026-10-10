@extends('portal.layout')
@section('title', 'Tours & things to do')
@section('heading', 'Tours & things to do')
@section('sub', 'Excursions our concierge recommends, and a local guide to '.($hotel->city ?: 'the city').'.')
@section('content')
@php $A = \App\Models\Activity::class; $P = \App\Models\ConciergePlace::class; $B = \App\Models\ActivityBooking::class; @endphp
<style>
.st.requested{background:#fef3c7;color:#92400e}.st.waitlisted{background:#ede9fe;color:#5b21b6}.st.confirmed{background:#e0e7ff;color:#3730a3}.st.completed{background:#dcfce7;color:#166534}.st.cancelled,.st.no_show{background:#f1f5f9;color:#475569}
.gp-act{border:1px solid #e2e8f0;border-radius:14px;padding:.75rem .85rem;margin-bottom:.6rem;background:#fff}
.gp-act h4{margin:0 0 .15rem;font-size:1rem}.gp-act .meta{font-size:.78rem;color:#64748b}.gp-act .price{font-weight:700;color:#1d4ed8;font-size:.9rem;margin-top:.25rem}
.gp-act summary{cursor:pointer;color:#1d4ed8;font-weight:600;font-size:.88rem;margin-top:.45rem;list-style:none}.gp-act summary::-webkit-details-marker{display:none}
.gp-place{padding:.5rem 0;border-bottom:1px dashed #e2e8f0}.gp-place:last-child{border:0}.gp-place b{font-size:.92rem}
.gp-cat{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin:.8rem 0 .2rem;font-weight:700}
</style>

@if($mine->isNotEmpty())
<div class="gp-card" id="myActivities"><h3>My activities</h3>
@foreach($mine as $b)
  <div class="gp-row" data-booking="{{ $b->number }}"><div style="flex:1">
    <b>{{ $b->activity->name }}</b>
    <div class="tiny muted">{{ $b->date->format('D d M') }} · {{ $b->time }} · {{ $b->paxLabel() }} · ref {{ $b->number }}</div>
    @if($b->status === 'confirmed')
      <div class="tiny">{{ $b->pickup_point ? 'Pick-up: '.$b->pickup_point.($b->pickup_time ? ' at '.$b->pickup_time : '') : ($b->activity->meeting_point ? 'Meet at: '.$b->activity->meeting_point : '') }}</div>
      <div class="tiny muted">{{ money($b->gross) }} · {{ $b->collection === 'folio' ? 'on your room bill' : 'pay '.$b->activity->vendor->name.' on the day' }} · <a href="{{ route('concierge.voucher.public', $b->voucher_token) }}">Voucher</a></div>
    @elseif($b->status === 'requested')
      <div class="tiny muted mt-1">Waiting for the operator to confirm – we'll send you a message.</div>
    @elseif($b->status === 'waitlisted')
      <div class="tiny muted mt-1">On the waitlist – we'll tell you if a seat comes free.</div>
    @elseif($b->status === 'cancelled' && (float) $b->cancel_fee > 0)
      <div class="tiny muted">Cancellation fee {{ money($b->cancel_fee) }}</div>
    @endif
    @if($b->isOpen() && ($fees[$b->id] ?? 0) <= 0)
      <form method="post" action="{{ route('portal.explore.cancel', [$token, $b->id]) }}" class="mt-1" onsubmit="return confirm('Cancel this booking?')">@csrf<button class="btn btn-sm">Cancel</button>
        @if($b->status === 'confirmed')<span class="tiny muted">free until {{ $b->freeCancelUntil()->format('d M H:i') }}</span>@endif</form>
    @elseif($b->isOpen())
      <div class="tiny muted mt-1">Free cancellation has ended – please speak to the concierge.</div>
    @endif
  </div><span class="st {{ $b->status }}">{{ $b->statusLabel() }}</span></div>
@endforeach
</div>
@endif

@if($bookable && $activities->isNotEmpty())
<div class="gp-card" id="activityList"><h3>Book an excursion</h3>
  <p class="tiny muted mt-0">Run by licensed local operators and booked through our concierge. Prices are per person unless stated.@if($cfg['desk_phone']) Questions? Call the concierge on <a href="tel:{{ $cfg['desk_phone'] }}">{{ $cfg['desk_phone'] }}</a>.@endif</p>
  @foreach($activities as $a)
  <div class="gp-act" data-activity="{{ $a->code }}">
    <h4>{{ $a->name }}</h4>
    <div class="meta">{{ $A::CATEGORIES[$a->category] ?? '' }} · {{ $a->durationLabel() }} · {{ $a->daysLabel() }}@if($a->isScheduled()) at {{ implode(', ', $a->timesList()) }}@endif{{ $a->pickup_included ? ' · hotel pick-up included' : '' }}</div>
    @if($a->highlights)<div class="small mt-1">{{ $a->highlights }}</div>@endif
    <div class="price">{{ $a->priceLabel() }}</div>
    <details @if(old('activity_id') == $a->id) open @endif><summary>Book this ›</summary>
      @if($a->description)<p class="tiny muted">{{ \Illuminate\Support\Str::limit($a->description, 320) }}</p>@endif
      @if($a->bring)<p class="tiny"><b>Bring:</b> {{ $a->bring }}</p>@endif
      <form method="post" action="{{ route('portal.explore.book', $token) }}" class="mt-1">@csrf
        <input type="hidden" name="activity_id" value="{{ $a->id }}"><input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
          <div class="field"><label for="d{{ $a->id }}">Date</label><input type="date" id="d{{ $a->id }}" name="date" class="input" required min="{{ max(now()->toDateString(), $stay->arrival_date->toDateString()) }}" max="{{ $stay->departure_date->toDateString() }}" value="{{ old('activity_id') == $a->id ? old('date') : '' }}"></div>
          <div class="field"><label for="t{{ $a->id }}">Time</label>
            @if($a->isScheduled())<select id="t{{ $a->id }}" name="time" class="input">@foreach($a->timesList() as $t)<option @selected(old('time') === $t)>{{ $t }}</option>@endforeach</select>
            @else<input type="time" id="t{{ $a->id }}" name="time" class="input" value="{{ old('activity_id') == $a->id ? old('time', '09:00') : '09:00' }}">@endif</div>
          <div class="field"><label for="a{{ $a->id }}">Adults</label><select id="a{{ $a->id }}" name="adults" class="input">@for($i = 1; $i <= min(12, $a->pricing === 'group' ? ($a->capacity ?: 12) : 12); $i++)<option @selected((int) old('adults', min(2, $stay->adults ?: 1)) === $i)>{{ $i }}</option>@endfor</select></div>
          <div class="field"><label for="c{{ $a->id }}">Children{{ $a->pricing === 'person' && (float) $a->price_child > 0 ? ' (to '.$a->child_max_age.')' : '' }}</label><select id="c{{ $a->id }}" name="children" class="input">@for($i = 0; $i <= 8; $i++)<option @selected((int) old('children', 0) === $i)>{{ $i }}</option>@endfor</select></div>
        </div>
        <div class="field"><label for="p{{ $a->id }}">Mobile on the day</label><input id="p{{ $a->id }}" name="phone" class="input" maxlength="30" value="{{ old('phone', $guest?->phone) }}"></div>
        <div class="field"><label for="n{{ $a->id }}">Anything we should know? (optional)</label><input id="n{{ $a->id }}" name="notes" class="input" maxlength="300" placeholder="Dietary needs, mobility, child seats…" value="{{ old('activity_id') == $a->id ? old('notes') : '' }}"></div>
        @if($a->isScheduled() && $a->capacity)<label class="tiny flex gap-1" style="align-items:center;margin-bottom:.5rem"><input type="checkbox" name="waitlist" value="1" checked> If it's full, put me on the waitlist</label>@endif
        <button class="btn btn-primary btn-block">{{ $a->instant ? 'Book now' : 'Request booking' }} · {{ $a->collectionMode() === 'folio' ? 'charged to my room' : 'pay the operator' }}</button>
        <p class="tiny muted m-0 mt-1">Book at least {{ max($a->cutoff_hours, $cfg['portal_lead']) }} h ahead. Free cancellation until {{ $a->free_cancel_hours }} h before{{ (float) $a->cancel_fee_pct > 0 ? '; after that '.rtrim(rtrim(number_format((float) $a->cancel_fee_pct, 1), '0'), '.').'% is charged' : '' }}.</p>
      </form>
    </details>
  </div>
  @endforeach
</div>
@endif

@if($places->isNotEmpty())
<div class="gp-card" id="localGuide"><h3>Local guide</h3>
@foreach($P::CATEGORIES as $cat => $label) @continue(! isset($places[$cat]))
  <div class="gp-cat">{{ $label }}</div>
  @foreach($places[$cat] as $pl)
  <div class="gp-place" data-place="{{ $pl->id }}"><b>{{ $pl->name }}</b>@if($pl->price_level) <span class="tiny muted">{{ str_repeat('₵', $pl->price_level) }}</span>@endif
    <div class="tiny muted">{{ collect([$pl->area, $pl->distance_km !== null ? rtrim(rtrim(number_format((float) $pl->distance_km, 1), '0'), '.').' km' : null, $pl->hours])->filter()->implode(' · ') }}</div>
    @if($pl->description)<div class="small">{{ $pl->description }}</div>@endif
    @if($pl->tip)<div class="tiny" style="color:#1d4ed8">Concierge tip: {{ $pl->tip }}</div>@endif
    @if($pl->phone)<div class="tiny"><a href="tel:{{ preg_replace('/[^0-9+]/', '', $pl->phone) }}">{{ $pl->phone }}</a></div>@endif
  </div>
  @endforeach
@endforeach
</div>
@endif
@endsection
