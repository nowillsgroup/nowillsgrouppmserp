@extends('public.layout')
@section('title', 'Book your stay')
@section('content')
<form class="card" method="get"><div class="card-body filters">
  <div class="field"><label>Check-in</label><input type="date" name="arrival" value="{{ $arrival }}" min="{{ $hotel->business_date->toDateString() }}"></div>
  <div class="field"><label>Check-out</label><input type="date" name="departure" value="{{ $departure }}"></div>
  <div class="field"><label>Promo code</label><input type="text" name="promo" value="{{ $promoCode }}" placeholder="Optional" style="text-transform:uppercase;max-width:150px" id="promoInput"></div>
  <div class="field"><label>Corporate code</label><input type="text" name="corp" value="{{ $corpCode }}" placeholder="Optional" style="text-transform:uppercase;max-width:140px" id="corpInput"></div>
  @if($token)<input type="hidden" name="m" value="{{ $token }}">@endif @if(! empty($dl))<input type="hidden" name="dl" value="{{ $dl }}">@endif
  <button class="btn btn-primary">Check availability</button><span class="small muted">{{ $nights }} night(s) · Best rate guaranteed when booking direct</span>
</div></form>
@if($promoCode !== '' && ! $promoError)<div class="alert alert-success" id="promoOk"><x-icon name="check"/><div>Promo <b>{{ $promoCode }}</b> applied – {{ $types->first(fn ($t) => $t->promo)?->promo?->offerLabel() }}. Discounted prices are shown below.</div></div>
@elseif($promoError)<div class="alert alert-error" id="promoBad"><x-icon name="alert"/><div>{{ $promoError }}</div></div>@endif
@if($contract)<div class="alert alert-success" id="corpOk"><x-icon name="building"/><div><b>{{ $contract->company->name }}</b> corporate rates ({{ \App\Models\RateContract::MEAL_PLANS[$contract->meal_plan] ?? $contract->meal_plan }}{{ $contract->inclusions ? ' · '.$contract->inclusions : '' }}). Free cancellation until {{ $contract->cancel_days }} day(s) before arrival.</div></div>
@elseif($corpError)<div class="alert alert-error" id="corpBad"><x-icon name="alert"/><div>{{ $corpError }}</div></div>@endif
@foreach($types as $t)
<div class="card"><div class="card-body flex gap-2 wrap items-center">
  <div style="flex:1;min-width:260px"><h3>{{ $t->name }}</h3><p class="text-2 small mb-1">{{ $t->description }}</p><div class="small">{{ $t->amenities }}</div><div class="tiny muted mt-1">Up to {{ $t->max_adults }} adults</div></div>
  <div class="right">@if($contract && ($t->quote['contract_nights'] ?? 0))<div class="badge badge-blue">{{ $contract->code }} rate</div>@if($t->quote['saving'] > 0)<div class="tiny muted" style="text-decoration:line-through">{{ money($t->quote['public_total'] / max(1, $t->quote['count'])) }}</div>@endif @elseif($t->promo)<div class="badge badge-green">{{ $t->promo->code }} · {{ $t->promo->offerLabel() }}</div><div class="tiny muted" style="text-decoration:line-through">{{ money($t->quote['original_average']) }}</div>@elseif($t->quote['average'] < $t->base_rate)<div class="tiny muted" style="text-decoration:line-through">{{ money($t->base_rate) }}</div>@endif<div style="font-size:1.4rem" class="bold">{{ money($t->quote['average']) }}</div><div class="tiny muted">avg per night + taxes · {{ money($t->quote['total']) }} total</div>
    @if($t->available > 0)<div class="small text-green">{{ $t->available }} left</div><button type="button" class="btn btn-primary mt-1" data-modal="bookModal" data-froom_type_id="{{ $t->id }}" data-fpromo="{{ $t->promo?->code }}" onclick="document.getElementById('bkType').textContent='{{ $t->name }}'">Book now</button>
    @elseif($t->quote['stop_sell'])<div class="badge badge-red mt-1">Closed for these dates</div>
    @elseif(! $t->quote['min_stay_ok'])<div class="badge badge-amber mt-1">Min. stay {{ $t->quote['min_stay'] }} nights</div>
    @else<div class="badge badge-red mt-1">Sold out</div>@endif</div>
</div></div>
@endforeach
<div class="modal" id="bookModal"><div class="modal-box"><form method="post" action="{{ route('booking.store', $hotel->code) }}">@csrf
  <input type="hidden" name="arrival" value="{{ $arrival }}"><input type="hidden" name="departure" value="{{ $departure }}"><input type="hidden" name="room_type_id">
  <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
  <input type="hidden" name="promo" value="{{ $promoError ? '' : $promoCode }}">@if($contract)<input type="hidden" name="corp" value="{{ $contract->access_code }}">@endif
  @if($token)<input type="hidden" name="m" value="{{ $token }}">@endif @if(! empty($dl))<input type="hidden" name="dl" value="{{ $dl }}">@endif
  <div class="modal-head"><h3>Book <span id="bkType"></span></h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="rooms" label="Rooms" type="number" min="1" max="5" value="1" required/><x-field name="adults" label="Adults" type="number" min="1" value="2" required/><x-field name="children" label="Children" type="number" min="0" value="0"/>
    <x-field name="first_name" label="First name" required/><x-field name="last_name" label="Last name"/><x-field name="phone" label="Mobile" required/>
    <x-field name="email" label="Email" type="email" required class="span-2"/><x-field name="special_request" label="Special requests" type="textarea" class="span-all"/>
    <label class="check span-all small"><input type="checkbox" name="marketing_opt_in" value="1" id="mktOptIn"> {{ setting('crm_consent_text', \App\Services\CrmService::DEFAULTS['crm_consent_text']) }}</label>
    @if(setting('booking_policy'))<div class="span-all small muted" style="background:#f8fafc;border:1px solid var(--c-border);border-radius:8px;padding:.6rem .8rem"><b>Cancellation policy:</b> {!! nl2br(e(setting('booking_policy'))) !!}</div>@endif
  </div>
  <div class="modal-foot">@if($deposit !== 'none')<span class="small muted" style="margin-right:auto"><x-icon name="lock"/> Secure online deposit: <b>{{ $deposit === 'percent' ? rtrim(rtrim(number_format((float) setting('pay_booking_deposit_pct', 30), 2), '0'), '.').'% of the stay' : \App\Services\OnlinePaymentService::DEPOSIT_POLICIES[$deposit] }}</b>{{ $deposit === 'full' ? '' : ' – the rest at the hotel' }} · {{ $arrival }} → {{ $departure }}</span><button class="btn btn-primary">Continue to payment</button>@else<span class="small muted" style="margin-right:auto">Pay at hotel · {{ $arrival }} → {{ $departure }}</span><button class="btn btn-primary">Confirm booking</button>@endif</div>
</form></div></div>
<script src="{{ asset('js/app.js') }}"></script>
@endsection
