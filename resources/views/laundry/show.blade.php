@extends('layouts.app')
@section('title', 'Laundry '.$o->order_no)
@section('crumbs') <a href="{{ route('laundry.index') }}">Laundry orders</a> / {{ $o->order_no }} @endsection
@section('actions')
<span class="badge badge-{{ \App\Models\LaundryOrder::TONES[$o->status] }}" id="loStatus">{{ $o->statusLabel() }}</span>@if($o->isOverdue()) <span class="badge badge-red">Overdue</span>@endif
<a class="btn" href="{{ route('laundry.docket', $o) }}" target="_blank"><x-icon name="print"/> Bag docket</a>
@if(can_do('laundry.orders'))
  @if(in_array($o->status, ['collected', 'processing']) && $o->pieces)<form method="post" action="{{ route('laundry.ready', $o) }}" class="inline">@csrf<button class="btn btn-accent" id="loReady"><x-icon name="check"/> Mark ready</button></form>@endif
  @if(in_array($o->status, ['processing', 'ready']))<button class="btn btn-success" data-modal="deliverModal" id="loDeliver"><x-icon name="money"/> Deliver{{ $o->type === 'guest' ? ' & charge room' : ' & take payment' }}</button>@endif
  @if($o->isOpen())<button class="btn btn-danger" data-modal="cancelModal"><x-icon name="x"/> Cancel</button>@endif
@endif
@endsection
@section('content')
<div class="grid g-2-1">
<div>
  <div class="card"><div class="card-body">
    <div class="flex justify-between items-center"><div><div class="tiny muted">{{ $o->type === 'walk_in' ? 'WALK-IN CUSTOMER' : 'IN-HOUSE GUEST' }}</div>
      <h2 class="m-0">{{ $o->type === 'walk_in' ? $o->guest_name : 'Room '.$o->room?->room_no.' · '.$o->guest_name }}</h2></div>
      <div class="right"><div class="tiny muted">AMOUNT (BEFORE TAX)</div><div class="bold" style="font-size:1.6rem">{{ money($o->total) }}</div>@if((float) $o->express_charge > 0)<div class="tiny muted">incl. express {{ money($o->express_charge) }}</div>@endif</div></div>
    <div class="kv-grid mt-2">
      <div><span>Service</span><b>{{ \App\Services\LaundryService::SPEEDS[$o->speed] }}</b>@if($o->speed === 'express')<span class="tiny muted">+{{ rtrim(rtrim(number_format($expressPct, 2), '0'), '.') }} %</span>@endif</div>
      <div><span>Bag / tag</span><b>{{ $o->bag_no ?: '—' }}</b>@if($o->bag_pieces)<span class="tiny muted">{{ $o->bag_pieces }} pcs counted at pick-up{{ $o->pieces && $o->pieces !== $o->bag_pieces ? ' – itemised '.$o->pieces : '' }}</span>@endif</div>
      <div><span>Collected</span><b>{{ $o->collected_at?->format('d M Y, H:i') }}</b><span class="tiny muted">by {{ $o->creator?->name ?? '—' }}</span></div>
      <div><span>Promised</span><b class="{{ $o->isOverdue() ? 'text-red' : '' }}">{{ $o->promised_at?->format('d M Y, H:i') }}</b></div>
      @if($o->ready_at)<div><span>Ready</span><b>{{ $o->ready_at->format('d M Y, H:i') }}</b></div>@endif
      @if($o->delivered_at)<div><span>Delivered</span><b>{{ $o->delivered_at->format('d M Y, H:i') }}</b><span class="tiny muted">by {{ $o->deliverer?->name }}</span></div>@endif
      @if($o->folioTransaction)<div class="span-all"><span>Charged to</span><b><a href="{{ route('folios.show', $o->folio) }}">Folio {{ $o->folio->folio_no }}</a>{{ $o->folio->invoice_no ? ' · invoice '.$o->folio->invoice_no : '' }}</b>
        <span class="tiny muted">{{ money($o->folioTransaction->amount) }} + tax {{ money($o->folioTransaction->tax_amount) }}{{ $o->paymentMode ? ' · paid by '.$o->paymentMode->name : '' }}</span>@if($o->folioTransaction->is_void)<span class="badge badge-red">Charge voided on the folio</span>@endif</div>@endif
      @if($o->guestRequest)<div><span>Guest request</span><b>{{ $o->guestRequest->request_no }}</b><span class="tiny muted">{{ $o->guestRequest->item }} · {{ \App\Models\GuestRequest::STATUSES[$o->guestRequest->status] ?? $o->guestRequest->status }}</span></div>@endif
      @if($o->notes)<div class="span-all"><span>Notes</span><b>{{ $o->notes }}</b></div>@endif
      @if($o->cancel_reason)<div class="span-all"><span>Cancelled</span><b class="text-red">{{ $o->cancel_reason }}</b></div>@endif
    </div>
  </div></div>

  @if($o->isOpen() && can_do('laundry.orders'))
  <form method="post" action="{{ route('laundry.items', $o) }}" class="card" id="itemsCard">@csrf
    <div class="card-head"><h3>Items</h3><div class="flex gap-1 items-center small"><label class="check"><input type="radio" name="speed" value="normal" @checked($o->speed === 'normal')> Normal</label><label class="check"><input type="radio" name="speed" value="express" @checked($o->speed === 'express')> Express</label><button class="btn btn-primary btn-sm" id="saveItems"><x-icon name="check"/> Save items</button></div></div>
    <div class="card-body">@include('laundry._grid', ['readonly' => false])</div>
  </form>
  @else
  <div class="card flush"><div class="card-head"><h3>Items</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="loLines">
    <thead><tr><th>Item</th><th>Service</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th><th>Remark</th></tr></thead><tbody>
    @forelse($o->lines as $l)<tr><td>{{ $l->item_name }}</td><td>{{ \App\Models\LaundryItem::SERVICES[$l->service] ?? $l->service }}</td><td class="num">{{ $l->qty }}</td><td class="num">{{ money($l->rate, false) }}</td><td class="num">{{ money($l->amount, false) }}</td><td class="small muted">{{ $l->remark }}</td></tr>
    @empty<tr><td colspan="6" class="muted center">No items.</td></tr>@endforelse
    </tbody><tfoot><tr><td colspan="2" class="right">{{ $o->pieces }} pcs</td><td colspan="3" class="num">Subtotal {{ money($o->subtotal, false) }}@if((float) $o->express_charge) + express {{ money($o->express_charge, false) }}@endif = <b>{{ money($o->total) }}</b></td><td></td></tr></tfoot></table></div></div>
  @endif
</div>
<div>
  <div class="card"><div class="card-head"><h3>Progress</h3></div><div class="card-body"><ul class="timeline" id="loTimeline">
    <li class="tl-info"><b>Collected</b><span>{{ $o->collected_at?->format('d M H:i') }}{{ $o->bag_pieces ? ' · '.$o->bag_pieces.' pcs' : '' }}</span></li>
    @if($o->pieces)<li class="tl-info"><b>Itemised</b><span>{{ $o->pieces }} pcs · {{ money($o->total) }}</span></li>@endif
    @if($o->ready_at)<li class="tl-ok"><b>Ready</b><span>{{ $o->ready_at->format('d M H:i') }}</span></li>@endif
    @if($o->delivered_at)<li class="tl-ok"><b>Delivered</b><span>{{ $o->delivered_at->format('d M H:i') }} – {{ $o->type === 'guest' ? 'charged to the room' : 'paid' }}</span></li>@endif
    @if($o->status === 'cancelled')<li class="tl-bad"><b>Cancelled</b><span>{{ $o->updated_at->format('d M H:i') }}</span></li>@endif
    @if($o->isOpen())<li class="{{ $o->isOverdue() ? 'tl-bad' : 'tl-info' }}"><b>{{ $o->isOverdue() ? 'Overdue' : 'Due back' }}</b><span>{{ $o->promised_at?->format('d M H:i') }} ({{ $o->promised_at?->diffForHumans() }})</span></li>@endif
  </ul></div></div>
  <div class="card"><div class="card-body small muted">
    <b>How it's charged:</b> on delivery, one <i>Laundry</i> line is posted to the guest's room folio (laundry tax applies, revenue account 4060). Walk-in customers pay on delivery and get a tax invoice. Prices always come from the price list.
  </div></div>
</div>
</div>

<div class="modal" id="deliverModal"><div class="modal-box"><form method="post" action="{{ route('laundry.deliver', $o) }}">@csrf
  <div class="modal-head"><h3>Deliver {{ $o->order_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="m-0 mb-1">{{ $o->pieces }} pcs · <b>{{ money($o->total) }}</b> + laundry tax.</p>
    @if($o->type === 'guest')<p class="small muted m-0">This is charged to room {{ $o->room?->room_no }}'s folio.</p>
    @else
      <div id="laundrySingleTender-{{ $o->id }}" class="form-grid">
        <div class="field"><label>Paid by</label><select name="payment_mode_id" class="input" required data-payment-mode>@foreach($modes as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
        <x-field name="momo_network" label="MoMo network"/><x-field name="momo_phone" label="MoMo phone"/>
        <div class="field span-all"><label>Reference</label><input name="reference" class="input" maxlength="80" placeholder="optional"></div>
        <x-cheque-fields class="span-all"/>
      </div>
      <x-payment-split-tender widget-id="laundrySplitTender-{{ $o->id }}" single-id="laundrySingleTender-{{ $o->id }}" :modes="$modes" label="payment"/>
    @endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-success"><x-icon name="check"/> Deliver</button></div>
</form></div></div>
<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('laundry.cancel', $o) }}">@csrf
  <div class="modal-head"><h3>Cancel {{ $o->order_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="reason" class="input" required maxlength="190" placeholder="e.g. guest collected the bag back"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger">Cancel order</button></div>
</form></div></div>
@endsection
