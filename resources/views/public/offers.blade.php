@extends('public.layout')
@section('title', 'Enhance your stay')
@section('content')
<div class="card mb-2"><div class="card-body flex justify-between items-center wrap gap-2">
  <div><div class="small muted">{{ $channel === 'portal' ? 'During your stay' : 'Before you arrive' }}</div><h2 style="margin:.1rem 0">Enhance your stay</h2>
    <div class="text-2">Reservation {{ $r->reservation_no }} · {{ $r->arrival_date->format('D d M') }} → {{ $r->departure_date->format('D d M Y') }} · {{ $r->nights }} night{{ $r->nights > 1 ? 's' : '' }}</div></div>
  <div class="small muted" style="max-width:320px">Nothing is charged now – what you choose is added to your room bill{{ $channel === 'portal' ? '' : ' and settled at check-out' }}.</div>
</div></div>

@if($closed)
  <div class="card"><div class="card-body center" style="padding:2.5rem"><h3 id="offersClosed">{{ $closed }}</h3><p class="muted">Questions? Call us on {{ $hotel->phone }}.</p></div></div>
@else
  @foreach($groups as $g)
    @php $s = $g['stay']; @endphp
    @if($groups->count() > 1)<h3 class="mt-2 mb-1">Room {{ $loop->iteration }} · {{ $s->roomType?->name }}{{ $s->room ? ' · '.$s->room->room_no : '' }}</h3>@endif
    @if($g['offers']->isEmpty())
      <div class="card mb-2"><div class="card-body muted small">No further offers are available for {{ $groups->count() > 1 ? 'this room' : 'your stay' }} right now.</div></div>
    @else
    <div class="offer-grid mb-2">
      @foreach($g['offers'] as $x)
        @php $o = $x['offer']; $q = $x['quote']; $needsOk = $o->isTimed() && ! $o->auto_confirm; @endphp
        <div class="offer-card" data-offer="{{ $o->code }}">
          <div class="offer-ic"><x-icon name="{{ $o->icon }}"/></div>
          <h3>{{ $o->name }}</h3>
          <div class="tiny muted mb-1">{{ $o->kind === 'upgrade' ? 'Room upgrade to '.$o->toRoomType?->name : ($o->isTimed() ? 'Subject to confirmation' : ucfirst(\App\Models\UpsellOffer::PRICING[$o->pricing][1] ?? '')) }}</div>
          @if($o->description)<p class="small" style="margin:0 0 .6rem">{{ $o->description }}</p>@endif
          <div class="offer-price"><b>{{ money($q['amount']) }}</b> <span class="tiny muted">+ taxes{{ str_contains($q['breakdown'], '×') ? ' · '.$q['breakdown'] : '' }}</span></div>
          <form method="post" action="{{ route('offers.order', $token) }}" class="mt-1">@csrf
            <input type="hidden" name="offer_id" value="{{ $o->id }}"><input type="hidden" name="stay_id" value="{{ $s->id }}">
            @if($o->isTimed())<input class="input input-sm mb-1" name="note" maxlength="255" placeholder="{{ $o->kind === 'early_checkin' ? 'Expected arrival time / flight (optional)' : 'Anything we should know? (optional)' }}">@endif
            <button class="btn btn-primary btn-block add-offer" type="submit">{{ $needsOk ? 'Request' : 'Add to my stay' }}</button>
            @if($needsOk)<div class="tiny muted mt-1">Subject to availability – the front desk confirms before you arrive.</div>@endif
          </form>
        </div>
      @endforeach
    </div>
    @endif
  @endforeach
@endif

@if($orders->isNotEmpty())
<div class="card"><div class="card-head"><h3><x-icon name="check"/> Your selections</h3></div>
  <div class="table-wrap"><table class="table" id="mySelections"><tbody>
  @foreach($orders as $o)
    @php $block = \App\Services\UpsellService::guestCancelBlock($o); @endphp
    <tr data-order="{{ $o->order_no }}"><td><b>{{ $o->offer->name }}</b><div class="tiny muted">{{ $o->service_date->format('D d M') }}{{ $groups->count() > 1 ? ' · '.$o->stay?->roomType?->name : '' }}</div></td>
      <td class="right nowrap">{{ money($o->amount) }}</td>
      <td><span class="badge badge-{{ $o->statusTone() }}">{{ $o->statusLabel() }}</span>@if($o->status === 'declined' && $o->staff_note)<div class="tiny muted">{{ $o->staff_note }}</div>@endif</td>
      <td class="right">@if($o->isOpen() && ! $block)<form method="post" action="{{ route('offers.cancel', [$token, $o->id]) }}" class="inline" data-confirm="Remove {{ $o->offer->name }} from your stay?">@csrf<button class="btn btn-sm btn-ghost remove-offer">Remove</button></form>
        @elseif($o->isOpen())<span class="tiny muted">{{ $block }}</span>@endif</td></tr>
  @endforeach
  </tbody></table></div>
  @if($cfg['guest_cancel_hours'])<div class="card-body tiny muted">You can remove confirmed items online until {{ $cfg['guest_cancel_hours'] }} hours before arrival.</div>@endif
</div>
@endif
<script>document.querySelectorAll('form[data-confirm]').forEach(function (f) { f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); }); });</script>
@endsection
