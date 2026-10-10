{{-- v4.1 Upsells card on the reservation page – $r = Reservation --}}
@php
  $upOrders = $r->upsellOrders()->with('offer', 'stay.roomType')->orderByDesc('id')->get();
  $upStay = $r->rooms->first(fn ($x) => in_array($x->status, ['reserved', 'checked_in']));
  $upOffers = $upStay && can_do('upsell.sell') ? \App\Services\UpsellService::offersFor($upStay, 'desk') : collect();
  $upGuestLink = $upStay && ! in_array($r->status, ['cancelled', 'no_show', 'checked_out']);
@endphp
@if($upOrders->isNotEmpty() || $upOffers->isNotEmpty())
<div class="card" id="resUpsell"><div class="card-head"><h3><x-icon name="star"/> Upsells</h3>
  @if(can_do('upsell.sell') && $upStay?->status === 'reserved' && $r->guest?->email)<form method="post" action="{{ route('upsell.send', $r) }}" class="inline">@csrf<button class="btn btn-sm" id="resSendOffers"><x-icon name="mail" size="sm"/> E-mail offers</button></form>@endif
</div><div class="card-body">
  @foreach($upOrders as $o)
    <div class="upsell-row small" data-order="{{ $o->order_no }}"><div><b>{{ $o->offer->name }}</b> <span class="tiny muted">{{ $o->order_no }} · {{ $o->channelLabel() }}{{ $r->rooms->count() > 1 ? ' · '.$o->stay?->roomType?->name : '' }}</span></div>
      <span>{{ money($o->amount) }} <span class="badge badge-{{ $o->statusTone() }}">{{ $o->statusLabel() }}</span></span>
      <span>@if($o->isOpen() && can_do('upsell.sell'))@if($o->status === 'requested')<form method="post" action="{{ route('upsell.confirm', $o) }}" class="inline">@csrf<button class="btn btn-sm btn-ghost" title="Confirm"><x-icon name="check" size="sm"/></button></form>@endif<button class="btn btn-sm btn-ghost btn-icon" title="Cancel" data-modal="resUpCancel" data-action="{{ route('upsell.cancel', $o) }}" data-title="Cancel {{ $o->order_no }} – {{ $o->offer->name }}"><x-icon name="x" size="sm"/></button>@endif</span></div>
  @endforeach
  @if($upOffers->isNotEmpty())
    <form method="post" action="{{ route('upsell.sell', $upStay) }}" class="flex gap-1 mt-1" id="resSellForm">@csrf
      <select class="input input-sm" name="offer_id" required style="flex:1">@foreach($upOffers as $x)<option value="{{ $x['offer']->id }}">{{ $x['offer']->name }} – {{ money($x['quote']['amount']) }}</option>@endforeach</select>
      <button class="btn btn-sm btn-primary">Add</button>
    </form>
  @endif
  @if($upGuestLink && can_do('upsell.sell'))
    <div class="flex gap-1 mt-1"><input class="input input-sm" readonly value="{{ $r->offersUrl() }}" onclick="this.select()" style="flex:1;font-size:.72rem" id="resOffersLink">
      <button type="button" class="btn btn-sm" onclick="navigator.clipboard?.writeText(this.previousElementSibling.value);this.textContent='Copied'">Copy</button></div>
    <div class="tiny muted mt-1">The guest's own offers page – share it by WhatsApp or SMS.</div>
  @endif
</div></div>
<div class="modal" id="resUpCancel"><div class="modal-box" style="max-width:460px"><form method="post" action="">@csrf
  <div class="modal-head"><h3 data-title>Cancel upsell</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required maxlength="255" placeholder="Guest changed their mind"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-danger">Cancel upsell</button></div>
</form></div></div>
@endif
