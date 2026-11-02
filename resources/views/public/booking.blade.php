@extends('public.layout')
@section('title', 'Book your stay')
@push('head')
<style>
.pbe-page{min-width:0}.pbe-intro{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin:0 0 .8rem}.pbe-kicker{display:flex;align-items:center;gap:.35rem;color:#176239;font-size:.66rem;font-weight:850;letter-spacing:.12em;text-transform:uppercase}.pbe-kicker svg{width:15px;height:15px}.pbe-intro h2{margin:.2rem 0 0;font-size:1.35rem}.pbe-intro p{margin:.2rem 0 0;color:var(--c-text-2);font-size:.79rem}.pbe-direct-tag{flex:none;padding:.42rem .65rem;border:1px solid #cfe3d3;border-radius:999px;background:#eff8f1;color:#1c5a32;font-size:.68rem;font-weight:750}
.pbe-search{margin-bottom:.85rem;border-color:#d6e6d9;box-shadow:0 8px 24px rgba(16,58,35,.06)}.pbe-search-head{display:flex;align-items:center;justify-content:space-between;gap:.7rem;margin-bottom:.75rem}.pbe-search-head h3{margin:0;font-size:.95rem}.pbe-search-head p{margin:.15rem 0 0;color:var(--c-text-2);font-size:.68rem}.pbe-search-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:.65rem}.pbe-search-grid .field{min-width:0}.pbe-search-grid .field label{font-size:.7rem;font-weight:750}.pbe-search-grid input{min-height:39px;width:100%;padding:.45rem .5rem;font-size:.79rem}.pbe-search-grid .pbe-search-submit{grid-column:1/-1;display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}.pbe-search-submit .btn{min-height:39px}.pbe-search-submit .small{font-size:.69rem}.pbe-search-grid .pbe-code{text-transform:uppercase}
.pbe-alert{margin:.6rem 0;padding:.7rem .85rem;border-radius:10px;font-size:.78rem}.pbe-room-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.8rem}.pbe-room-card{min-width:0;overflow:hidden;border:1px solid var(--c-border);box-shadow:0 6px 18px rgba(15,23,42,.045)}.pbe-room-card .card-body{height:100%;display:grid;grid-template-columns:minmax(0,1fr) minmax(190px,.75fr);align-items:start;gap:.9rem;padding:1rem}.pbe-room-copy{min-width:0}.pbe-room-copy h3{margin:0;font-size:1.02rem;line-height:1.3}.pbe-room-description{margin:.35rem 0;color:var(--c-text-2);font-size:.74rem;line-height:1.5;white-space:pre-line}.pbe-amenities{margin:.45rem 0 0;color:var(--c-text-2);font-size:.7rem;line-height:1.45}.pbe-capacity{display:inline-flex;align-items:center;gap:.3rem;margin-top:.55rem;padding:.3rem .45rem;border-radius:7px;background:var(--c-bg);color:var(--c-text-2);font-size:.66rem}.pbe-capacity svg{width:13px;height:13px}.pbe-rate-box{min-width:0;padding:.75rem;border:1px solid #d7e8da;border-radius:11px;background:#f7fbf8;text-align:right}.pbe-rate-tag{display:inline-flex;max-width:100%;padding:.24rem .42rem;border:1px solid #cce3d1;border-radius:999px;background:#edf8ef;color:#1b5430;font-size:.61rem;font-weight:800}.pbe-rate-tag.contract{border-color:#c5d7ef;background:#eff6ff;color:#285482}.pbe-rate-tag.promo{border-color:#cce3d1;background:#edf8ef;color:#1b5430}.pbe-rate-old{margin-top:.35rem;color:var(--c-text-2);font-size:.69rem}.pbe-average{margin-top:.15rem;color:#176239;font-size:1.3rem;font-weight:850;letter-spacing:-.025em;font-variant-numeric:tabular-nums}.pbe-average-label{color:var(--c-text-2);font-size:.64rem}.pbe-total{margin-top:.38rem;padding-top:.4rem;border-top:1px solid #dce9de;text-align:right}.pbe-total strong{display:block;font-size:.9rem;font-variant-numeric:tabular-nums}.pbe-total span{display:block;margin-top:.1rem;color:var(--c-text-2);font-size:.63rem;line-height:1.35}.pbe-breakdown{margin-top:.5rem;text-align:left}.pbe-breakdown summary{color:#176239;font-size:.68rem;font-weight:750;cursor:pointer}.pbe-breakdown summary:focus-visible{outline:2px solid #176239;outline-offset:2px;border-radius:3px}.pbe-breakdown-inner{max-height:210px;margin-top:.4rem;overflow:auto;border:1px solid var(--c-border);border-radius:8px;background:var(--c-surface)}.pbe-breakdown table{width:100%;border-collapse:collapse;font-size:.66rem}.pbe-breakdown th,.pbe-breakdown td{padding:.35rem .42rem;border-bottom:1px solid var(--c-border)}.pbe-breakdown tr:last-child th,.pbe-breakdown tr:last-child td{border-bottom:0}.pbe-breakdown th{text-align:left;font-weight:650}.pbe-breakdown td{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.pbe-season{display:block;color:var(--c-text-2);font-size:.59rem;font-weight:500}.pbe-rate-note{margin:.35rem 0 0;color:var(--c-text-2);font-size:.6rem;line-height:1.4}.pbe-availability{margin:.5rem 0 0;color:#176239;font-size:.69rem;font-weight:750}.pbe-availability.warn{color:#8a4b0b}.pbe-availability.closed{color:#9f2d2d}.pbe-book-btn{width:100%;min-height:38px;justify-content:center;margin-top:.5rem}.pbe-empty{padding:1rem;border:1px dashed var(--c-border);border-radius:12px;background:var(--c-surface);color:var(--c-text-2);text-align:center;font-size:.8rem}.pbe-modal .modal-box{max-height:90vh;overflow:auto}.pbe-modal .modal-body{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.85rem}.pbe-modal .field.span-all{grid-column:1/-1}.pbe-modal .modal-head{position:sticky;top:0;z-index:2;background:var(--c-surface)}.pbe-modal .modal-foot{position:sticky;bottom:0;z-index:2;align-items:center;background:var(--c-surface)}.pbe-modal-foot-note{margin-right:auto;color:var(--c-text-2);font-size:.68rem;line-height:1.4}.pbe-policy{padding:.65rem .75rem;border:1px solid var(--c-border);border-radius:9px;background:var(--c-bg);line-height:1.5}.pbe-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media(max-width:900px){.pbe-search-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.pbe-room-list{grid-template-columns:1fr}}
@media(max-width:600px){.pbe-modal{padding:2vh .5rem}.pbe-intro{align-items:flex-start;flex-direction:column}.pbe-search-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem}.pbe-room-card .card-body{grid-template-columns:1fr}.pbe-rate-box{text-align:left}.pbe-total{text-align:left}.pbe-modal .modal-box{max-height:96vh}.pbe-modal .modal-body{grid-template-columns:1fr;gap:.7rem;padding:.9rem}.pbe-modal .field.span-2,.pbe-modal .field.span-all,.pbe-modal .check.span-all{grid-column:auto}.pbe-modal .modal-foot{align-items:stretch;flex-direction:column;padding:.75rem}.pbe-modal-foot-note{margin:0}.pbe-search-submit{align-items:stretch!important;flex-direction:column}.pbe-search-submit .btn{width:100%;justify-content:center}}
@media(max-width:360px){.pbe-search-grid{grid-template-columns:1fr}.pbe-search-head{align-items:flex-start;flex-direction:column}}
@media(prefers-reduced-motion:reduce){.pbe-page *,.pbe-modal *{scroll-behavior:auto!important;transition:none!important;animation:none!important}}
</style>
@endpush
@section('content')
@php
  $departureMin = \Illuminate\Support\Carbon::parse($arrival)->addDay()->toDateString();
@endphp
<div class="pbe-page">
  <header class="pbe-intro"><div><span class="pbe-kicker"><x-icon name="calendar"/> Direct reservations</span><h2>Book your stay</h2><p>Choose your dates and compare live room rates for {{ $hotel->name }}.</p></div><span class="pbe-direct-tag">Direct booking</span></header>

  <form class="card pbe-search" method="get" action="{{ route('booking.show', $hotel->code) }}" aria-label="Search dates and room prices"><div class="card-body">
    <div class="pbe-search-head"><div><h3>Stay details</h3><p>Availability and prices update for your selected dates.</p></div><span class="small muted">{{ $nights }} {{ $nights === 1 ? 'night' : 'nights' }}</span></div>
    <div class="pbe-search-grid">
      <div class="field"><label for="pbeArrival">Check-in</label><input id="pbeArrival" type="date" name="arrival" value="{{ $arrival }}" min="{{ $hotel->business_date->toDateString() }}" required></div>
      <div class="field"><label for="pbeDeparture">Check-out</label><input id="pbeDeparture" type="date" name="departure" value="{{ $departure }}" min="{{ $departureMin }}" required></div>
      <div class="field"><label for="pbeRooms">Rooms</label><input id="pbeRooms" type="number" name="rooms" value="{{ $rooms }}" min="1" max="5" inputmode="numeric" required></div>
      <div class="field"><label for="pbeAdults">Adults</label><input id="pbeAdults" type="number" name="adults" value="{{ $adults }}" min="1" max="20" inputmode="numeric" required></div>
      <div class="field"><label for="promoInput">Promo code</label><input class="pbe-code" id="promoInput" type="text" name="promo" value="{{ $promoCode }}" placeholder="Optional" maxlength="30" autocomplete="off"></div>
      <div class="field"><label for="corpInput">Corporate code</label><input class="pbe-code" id="corpInput" type="text" name="corp" value="{{ $corpCode }}" placeholder="Optional" maxlength="20" autocomplete="off"></div>
      @if($token)<input type="hidden" name="m" value="{{ $token }}">@endif @if(! empty($dl))<input type="hidden" name="dl" value="{{ $dl }}">@endif
      <div class="pbe-search-submit"><button class="btn btn-primary" type="submit"><x-icon name="search" size="sm"/> Check availability</button><span class="small muted">Rate totals are shown before applicable taxes.</span></div>
    </div>
  </div></form>

  @if($promoCode !== '' && ! $promoError)<div class="alert alert-success pbe-alert" id="promoOk" role="status"><x-icon name="check"/><div>Promo <b>{{ $promoCode }}</b> applied – {{ $types->first(fn ($t) => $t->promo)?->promo?->offerLabel() }}. Discounted rates are shown below.</div></div>
  @elseif($promoError)<div class="alert alert-error pbe-alert" id="promoBad" role="alert"><x-icon name="alert"/><div>{{ $promoError }}</div></div>@endif
  @if($contract)<div class="alert alert-success pbe-alert" id="corpOk" role="status"><x-icon name="building"/><div><b>{{ $contract->company->name }}</b> corporate rates ({{ \App\Models\RateContract::MEAL_PLANS[$contract->meal_plan] ?? $contract->meal_plan }}{{ $contract->inclusions ? ' · '.$contract->inclusions : '' }}). Free cancellation until {{ $contract->cancel_days }} day(s) before arrival.</div></div>
  @elseif($corpError)<div class="alert alert-error pbe-alert" id="corpBad" role="alert"><x-icon name="alert"/><div>{{ $corpError }}</div></div>@endif

  @if($types->isEmpty())
    <div class="pbe-empty">No room types are currently available to book online. Please contact {{ $hotel->name }} for assistance.</div>
  @else
    <div class="pbe-room-list" role="list" aria-label="Available room types">
      @foreach($types as $t)
        <article class="card pbe-room-card" role="listitem" aria-label="{{ $t->name }} room type">
          <div class="card-body">
            <div class="pbe-room-copy"><h3>{{ $t->name }}</h3>
              @if($t->description)<p class="pbe-room-description">{{ $t->description }}</p>@endif
              @if($t->amenities)<div class="pbe-amenities">{{ $t->amenities }}</div>@endif
              <span class="pbe-capacity"><x-icon name="users"/> Up to {{ $t->max_adults }} adults per room</span>
            </div>
            <div class="pbe-rate-box" role="group" aria-label="Pricing and availability for {{ $t->name }}">
              @if($contract && ($t->quote['contract_nights'] ?? 0))
                <span class="pbe-rate-tag contract">{{ $contract->code }} corporate rate</span>
                @if($t->quote['saving'] > 0)<div class="pbe-rate-old">Public average <s>{{ money($t->quote['public_total'] / max(1, $t->quote['count'])) }}</s></div>@endif
              @elseif($t->promo)
                <span class="pbe-rate-tag promo">{{ $t->promo->code }} · {{ $t->promo->offerLabel() }}</span>
                <div class="pbe-rate-old">Regular average <s>{{ money($t->quote['original_average']) }}</s></div>
              @endif
              <div class="pbe-average">{{ money($t->quote['average']) }}</div><div class="pbe-average-label">average per room · per night</div>
              <div class="pbe-total"><strong>{{ money($t->quote['total'] * $rooms) }}</strong><span>Total for {{ $rooms }} {{ $rooms === 1 ? 'room' : 'rooms' }} · {{ $nights }} {{ $nights === 1 ? 'night' : 'nights' }} · before taxes</span><span>Quote based on {{ $adultsPerRoom }} {{ $adultsPerRoom === 1 ? 'adult' : 'adults' }} per room</span></div>
              <details class="pbe-breakdown"><summary>Nightly rate breakdown · {{ $t->quote['count'] }} {{ $t->quote['count'] === 1 ? 'night' : 'nights' }}</summary><div class="pbe-breakdown-inner"><table><caption class="pbe-sr-only">Nightly room rates for {{ $t->name }}</caption><tbody>
                @foreach($t->quote['nights'] as $night)
                  @php $nightDate = \Illuminate\Support\Carbon::parse($night['date']); $compareRate = $night['full_rate'] ?? ((($night['basis'] ?? 'public') !== 'public' && ($night['public_rate'] ?? $night['rate']) > $night['rate']) ? $night['public_rate'] : null); @endphp
                  <tr><th scope="row"><time datetime="{{ $nightDate->toDateString() }}">{{ $nightDate->format('D, j M Y') }}</time>@if($night['season'] ?? null)<span class="pbe-season">{{ $night['season'] }}</span>@endif</th><td>@if($compareRate !== null)<s>{{ money($compareRate) }}</s> @endif{{ money($night['rate']) }}</td></tr>
                @endforeach
              </tbody></table></div><p class="pbe-rate-note">Per-room rates for the selected dates. Taxes and other applicable charges are calculated separately.</p></details>
              @if($t->available > 0)
                @if($t->available >= $rooms)<div class="pbe-availability">{{ $t->available }} {{ $t->available === 1 ? 'room' : 'rooms' }} available for these dates</div>
                @else<div class="pbe-availability warn">Only {{ $t->available }} {{ $t->available === 1 ? 'room' : 'rooms' }} available; reduce the room count to continue</div>@endif
                <button type="button" class="btn btn-primary pbe-book-btn" data-modal="bookModal" data-title="{{ $t->name }}" data-froom_type_id="{{ $t->id }}" data-fpromo="{{ $t->promo?->code }}" data-frooms="{{ $rooms }}" data-fadults="{{ $adults }}" aria-label="Book {{ $t->name }}"><x-icon name="check" size="sm"/> Continue with this room</button>
              @elseif($t->quote['stop_sell'])<div class="pbe-availability closed">Closed for these dates</div>
              @elseif(! $t->quote['min_stay_ok'])<div class="pbe-availability warn">Minimum stay: {{ $t->quote['min_stay'] }} {{ $t->quote['min_stay'] === 1 ? 'night' : 'nights' }}</div>
              @else<div class="pbe-availability closed">No rooms available for these dates</div>@endif
            </div>
          </div>
        </article>
      @endforeach
    </div>
  @endif

  <div class="modal pbe-modal" id="bookModal"><div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="pbeModalTitle" tabindex="-1"><form method="post" action="{{ route('booking.store', $hotel->code) }}">@csrf
    <input type="hidden" name="arrival" value="{{ old('arrival', $arrival) }}"><input type="hidden" name="departure" value="{{ old('departure', $departure) }}"><input type="hidden" name="room_type_id" value="{{ old('room_type_id') }}">
    <input type="text" name="website" value="" class="pbe-sr-only" tabindex="-1" autocomplete="off" aria-hidden="true">
    <input type="hidden" name="promo" value="{{ $promoError ? '' : $promoCode }}">@if($contract)<input type="hidden" name="corp" value="{{ $contract->access_code }}">@endif
    @if($token)<input type="hidden" name="m" value="{{ $token }}">@endif @if(! empty($dl))<input type="hidden" name="dl" value="{{ $dl }}">@endif
    <div class="modal-head"><h3 id="pbeModalTitle">Book <span id="bkType" data-title>your stay</span></h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close booking form" title="Close"><x-icon name="x"/></button></div>
    <div class="modal-body">
      <x-field name="rooms" label="Rooms" type="number" min="1" max="5" :value="$rooms" required/><x-field name="adults" label="Adults" type="number" min="1" max="20" :value="$adults" required/><x-field name="children" label="Children" type="number" min="0" max="10" value="0"/>
      <x-field name="first_name" label="First name" required/><x-field name="last_name" label="Last name"/><x-field name="phone" label="Mobile" required/>
      <x-field name="email" label="Email" type="email" required class="span-2"/><x-field name="special_request" label="Special requests" type="textarea" class="span-all"/>
      <label class="check span-all small"><input type="checkbox" name="marketing_opt_in" value="1" id="mktOptIn" @checked(old('marketing_opt_in'))> {{ setting('crm_consent_text', \App\Services\CrmService::DEFAULTS['crm_consent_text']) }}</label>
      @if(setting('booking_policy'))<div class="pbe-policy span-all small"><b>Cancellation policy:</b> {!! nl2br(e(setting('booking_policy'))) !!}</div>@endif
    </div>
    <div class="modal-foot"><span class="pbe-modal-foot-note">@if($deposit !== 'none')<x-icon name="lock"/> Secure online deposit: <b>{{ $deposit === 'percent' ? rtrim(rtrim(number_format((float) setting('pay_booking_deposit_pct', 30), 2), '0'), '.').'% of the stay' : \App\Services\OnlinePaymentService::DEPOSIT_POLICIES[$deposit] }}</b>{{ $deposit === 'full' ? '' : ' – the rest at the hotel' }} · {{ $arrival }} → {{ $departure }}@else Pay at hotel · {{ $arrival }} → {{ $departure }}@endif</span><button class="btn btn-primary" type="submit">{{ $deposit !== 'none' ? 'Continue to payment' : 'Confirm booking' }}</button></div>
  </form></div></div>
</div>
<script src="{{ asset('js/app.js') }}"></script>
@if($errors->any() && old('room_type_id'))
  @php $oldTypeName = $types->firstWhere('id', (int) old('room_type_id'))?->name; @endphp
  <script>window.addEventListener('load', () => { const input = document.querySelector('#bookModal [name="room_type_id"]'); if (input) input.value = @json((string) old('room_type_id')); const label = document.getElementById('bkType'); if (label) label.textContent = @json($oldTypeName ?? 'your stay'); window.openModal?.('bookModal'); });</script>
@endif
@endsection
