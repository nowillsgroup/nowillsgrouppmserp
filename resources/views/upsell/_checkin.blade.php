{{-- v4.1 "Enhance this stay" on the check-in form – $stay, $upOffers, $upOrders --}}
@if($upOffers->isNotEmpty() || $upOrders->isNotEmpty())
<div class="card mt-2" id="checkinUpsell"><div class="card-head"><h3><x-icon name="star"/> Enhance this stay</h3><span class="small muted">Suggest these while the guest registers</span></div>
  <div class="card-body">
    @foreach($upOrders as $o)
      <div class="upsell-row small"><div><b>{{ $o->offer->name }}</b> <span class="tiny muted">{{ $o->order_no }} · {{ $o->channelLabel() }}</span></div><span>{{ money($o->amount) }}</span><span class="badge badge-{{ $o->statusTone() }}">{{ $o->statusLabel() }}</span></div>
    @endforeach
    @foreach($upOffers as $x)
      @php $o = $x['offer']; @endphp
      <form method="post" action="{{ route('upsell.sell', $stay) }}" class="upsell-row" data-offer="{{ $o->code }}">@csrf<input type="hidden" name="offer_id" value="{{ $o->id }}">
        <div><x-icon name="{{ $o->icon }}" size="sm"/> <b>{{ $o->name }}</b> <span class="tiny muted">{{ $o->kind === 'upgrade' ? '→ '.$o->toRoomType?->name.' · ' : '' }}{{ $x['quote']['breakdown'] }}@if($o->incentive_type !== 'none') · <span class="text-purple">you earn {{ $o->incentive_type === 'pct' ? rtrim(rtrim(number_format((float) $o->incentive_value, 2), '0'), '.').'%' : money($o->incentive_value) }}</span>@endif</span></div>
        <b>{{ money($x['quote']['amount']) }}</b>
        <button class="btn btn-sm btn-primary sell-btn">Add</button>
      </form>
    @endforeach
  </div></div>
@endif
