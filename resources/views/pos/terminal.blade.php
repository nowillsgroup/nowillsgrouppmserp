@extends('layouts.app')
@section('title', 'Order '.$order->order_no)
@section('crumbs') <a href="{{ route('pos.index', ['outlet' => $order->outlet_id]) }}">{{ $order->outlet->name }}</a> / {{ $order->table ? 'Table '.$order->table->table_no : ($order->stay ? 'Room '.$order->stay->room?->room_no : ucwords(str_replace('_',' ',$order->order_type))) }} @endsection
@section('actions')
  {!! status_badge($order->status) !!} @if($order->source === 'qr')<span class="badge badge-purple">QR order</span>@elseif($order->source === 'portal')<span class="badge badge-purple">Guest portal · room {{ $order->stay?->room?->room_no }}</span>@elseif($order->source === 'tv')<span class="badge badge-purple">Room TV · room {{ $order->stay?->room?->room_no }}</span>@endif @if($order->bill_no)<span class="badge badge-blue">Bill {{ $order->bill_no }}</span>@endif
  @if(session('print_kot'))
    <a class="btn btn-accent" target="_blank" href="{{ route('pos.printkot', [$order, session('print_kot')]) }}"><x-icon name="print"/> KOT #{{ session('print_kot') }} — printers</a>
    @foreach($flashJobs as $j)
      @if($j->printer)<a class="btn btn-sm" target="_blank" href="{{ route('pos.printkot', [$order, session('print_kot')]) }}?job={{ $j->id }}">{{ $j->printer->name }}</a>@endif
    @endforeach
  @endif
  @if(session('print_void') && session('print_kot'))<a class="btn btn-danger" target="_blank" href="{{ route('pos.printkot', [$order, session('print_kot')]) }}?void=1">Print VOID slip</a>@endif
  @if($order->items->where('is_pending', false)->count())<a class="btn" target="_blank" href="{{ route('pos.print', $order) }}"><x-icon name="print"/> Print Bill</a>@endif
  @if($order->status === 'settled' && $order->settlement === 'payment' && can_do('pos.refund') && $order->payments->where('kind', 'payment')->isNotEmpty())<button class="btn btn-danger" data-modal="refundModal">Refund payment</button>@endif
  @if($order->order_type === 'delivery' && in_array($order->status,['open','billed']) && can_do('pos.order'))<button class="btn" data-modal="deliveryModal">Delivery: {{ ucwords(str_replace('_', ' ', $order->delivery_status)) }}</button>@endif
  @if(in_array($order->status,['open','billed']))
    @if(can_do('pos.order'))<button class="btn" data-modal="detailsModal" title="Edit guest and waiter details"><x-icon name="edit"/> Details</button><button class="btn" data-modal="moveModal" title="Move to another table"><x-icon name="swap"/> Table</button>@endif
    @if(can_do('pos.settle'))<button class="btn" data-modal="mergeModal"><x-icon name="layers"/> Merge</button>@if($order->items->where('is_cancelled', false)->where('is_pending', false)->sum('qty') > 1 && $order->items->where('is_pending', true)->where('is_cancelled', false)->isEmpty())<button class="btn" data-modal="splitModal"><x-icon name="receipt"/> Split bill</button>@endif @endif
  @endif
  @if(in_array($order->status,['open','billed']) && can_do('pos.order'))<button class="btn {{ $order->allergens ? 'btn-danger' : '' }}" data-modal="allergyModal" id="allergyBtn" title="Guest allergies"><x-icon name="alert"/> Allergies{{ $order->allergens ? ' ('.count($order->allergens).')' : '' }}</button>@endif
  @if(in_array($order->status,['open','billed']) && can_do('pos.void'))<button class="btn btn-danger" data-modal="voidModal">Void</button>@endif
@endsection
@section('content')
@include('pos._workspace-nav')
@php $contextEditable = in_array($order->status, ['open', 'billed'], true) && can_do('pos.order'); @endphp
<div class="pos-order-context" aria-label="Order context">
  <div class="pos-context-cell"><x-icon name="grid" size="sm"/><span><small>Table / service</small><b>{{ $order->table ? 'Table '.$order->table->table_no : ($order->stay ? 'Room '.$order->stay->room?->room_no : ucwords(str_replace('_', ' ', $order->order_type))) }}</b></span></div>
  <div class="pos-context-cell{{ $contextEditable ? ' pos-context-action' : '' }}" @if($contextEditable) data-modal="detailsModal" role="button" tabindex="0" title="Edit customer, pax and waiter details" @endif><x-icon name="user" size="sm"/><span><small>Customer</small><b>{{ $order->customer_name ?: 'Walk-in guest' }}</b></span></div>
  <div class="pos-context-cell{{ $contextEditable ? ' pos-context-action' : '' }}" @if($contextEditable) data-modal="detailsModal" role="button" tabindex="0" title="Edit customer, pax and waiter details" @endif><x-icon name="users" size="sm"/><span><small>Pax</small><b>{{ $order->covers ?: 1 }}</b></span></div>
  <div class="pos-context-cell{{ $contextEditable ? ' pos-context-action' : '' }}" @if($contextEditable) data-modal="detailsModal" role="button" tabindex="0" title="Edit customer, pax and waiter details" @endif><x-icon name="user" size="sm"/><span><small>Waiter</small><b>{{ $order->steward ?: 'Not assigned' }}</b></span></div>
  <div class="pos-context-cell"><x-icon name="receipt" size="sm"/><span><small>Ref. #</small><b>{{ $order->bill_no ?: $order->order_no }}</b></span></div>
</div>
@if($autoPrintUrl)<iframe src="{{ $autoPrintUrl }}" style="position:absolute;width:0;height:0;border:0" title="KOT"></iframe>@endif
@if(session('print_ask'))
<div class="modal show" id="kotAsk"><div class="modal-box" style="max-width:560px"><form method="post" action="{{ route('pos.printkot.send', [$order, session('print_kot')]) }}">@csrf
  <div class="modal-head"><h3>Which printers for KOT #{{ session('print_kot') }}?</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small muted" style="margin-top:0">Tick every printer that should get a slip. Station printers receive only their dishes unless you force a full copy.</p>
    @foreach($kotPrinters as $p)
      @php $n = $order->items->where('kot_no', session('print_kot'))->where('is_cancelled', false)->where('kot_printer_id', $p->id)->count(); @endphp
      <label class="check"><input type="checkbox" name="printers[]" value="{{ $p->id }}" @checked($p->role === 'captain' || $n)> {{ $p->name }} <span class="tiny muted">{{ $p->role === 'captain' ? 'full ticket' : $n.' item(s)' }} · {{ $p->connection === 'network' ? $p->host : 'browser' }}</span></label>
    @endforeach
    <label class="check"><input type="checkbox" name="force" value="1"> Send every item to each ticked printer</label>
  </div>
  <div class="modal-foot"><a class="btn" target="_blank" href="{{ route('pos.printkot', [$order, session('print_kot')]) }}">Preview slips</a><button class="btn btn-accent">Print selected</button></div>
</form></div></div>
@endif
@php $editable = in_array($order->status, ['open','billed']) && can_do('pos.order'); $paidAmount = $order->paidAmount(); $balanceDue = $order->balanceDue(); $A = \App\Services\AllergenService::class; $guestAll = $A::clean($order->allergens); $openStations = ($kotPrinters ?? collect())->where('role', 'station')->pluck('station')->filter()->unique()->values(); if (! $openStations->contains(fn ($s) => strcasecmp((string) $s, 'Kitchen') === 0)) { $openStations->push('Kitchen'); } @endphp
@if($guestAll)<div class="alert alert-error" id="allergyBanner"><x-icon name="alert"/><div><b>Guest allergies: {{ $A::names($guestAll) }}</b>@if($order->allergy_note) – {{ $order->allergy_note }}@endif<div class="tiny">Items containing these are blocked until you confirm them with the chef; the KOT and kitchen display carry the warning.</div></div></div>@endif
<div class="pos">
  <div>
    @if($editable)
    <div class="flex gap-1 mb-1"><input class="input" id="itemSearch" placeholder="Search item name or code…" style="max-width:320px"><button type="button" class="btn btn-accent" data-modal="openModal"><x-icon name="plus"/> Add open item</button></div>
    <div class="pos-catalog">
      <div class="pos-cats"><button type="button" class="active" data-cat="">All</button>@foreach($cats as $c)<button type="button" data-cat="{{ $c->id }}">{{ $c->name }}</button>@endforeach</div>
      <div class="pos-items">
      @foreach($items as $i)
        @php $al = $allergenMap[$i->id] ?? ['contains' => [], 'may' => []]; $hit = array_intersect(array_merge($al['contains'], $al['may']), $guestAll); @endphp
        <button type="button" class="pos-item{{ $hit ? ' allergy-hit' : '' }}" data-id="{{ $i->id }}" data-name="{{ $i->name }}" data-price="{{ $i->price }}" data-cat="{{ $i->menu_category_id }}" data-code="{{ strtolower($i->code) }}" data-img="{{ $i->imageUrl() }}" data-printer="{{ $printerMap[$i->id] ?? '' }}" data-allergens="{{ implode(',', $al['contains']) }}" data-may="{{ implode(',', $al['may']) }}" data-modifiers="{{ e(json_encode($i->modifierGroups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'min' => $g->is_required ? max(1, $g->min_selections) : $g->min_selections, 'max' => $g->max_selections, 'required' => $g->is_required, 'options' => $g->modifiers->where('is_available', true)->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price_delta' => (float) $m->price_delta])->values()])->values())) }}">
          <span class="pos-photo{{ $i->imageUrl() ? '' : ' pos-photo-empty' }}">@if($i->imageUrl())<img src="{{ $i->imageUrl() }}" alt="{{ $i->name }}">@else<span class="veg-dot {{ $i->food_type }}"></span>@endif</span>
          <span class="pos-meta flex items-center gap-1"><span class="veg-dot {{ $i->food_type }}"></span><span class="tiny muted">{{ $i->code }}</span>@if($al['contains'])<span class="allergen-codes" title="Contains: {{ $A::names($al['contains']) }}">{{ implode(' ', array_map([$A, 'code'], $al['contains'])) }}</span>@endif</span>
          <b>{{ $i->name }}</b><span class="p">{{ money($i->price) }}</span></button>
      @endforeach
        <div id="menuEmpty" class="pos-menu-empty hidden"><x-icon name="search"/><b>No matching menu items</b><span>Try another item name, code, or category.</span><button type="button" class="btn btn-sm btn-ghost" id="clearMenuFilters">Clear filters</button></div>
      </div>
    </div>
    @else
      <div class="alert alert-info"><x-icon name="receipt"/><div>This order is {{ $order->status }}.@if($order->merged_into_id) <a href="{{ route('pos.terminal', $order->merged_into_id) }}">Open the combined bill</a>.@endif @if($order->settlement) Settled via {{ str_replace('_',' ',$order->settlement) }}@if($order->settlement === 'payment') – {{ $order->payments->where('kind', 'payment')->map(fn ($p) => $p->paymentMode?->name)->filter()->unique()->join(', ') ?: ($order->paymentMode?->name ?? 'Payment') }}@endif. @endif</div></div>
    @endif
  </div>

  <div class="cart">
    @if($editable)
    <form method="post" action="{{ route('pos.kot', $order) }}" class="card" id="kotForm">@csrf
      <div class="card-head"><h3>New items</h3><button type="button" class="btn btn-sm btn-ghost" onclick="cart={};render()">Clear</button></div>
      <div class="cart-table-head" aria-hidden="true"><span>Item / rate</span><span>Qty</span><span>Amount</span></div>
      <div class="card-body cart-lines" id="cartLines"><div class="muted small">Tap a menu item, or add an open item. Tick Hold if the kitchen should wait.</div></div>
      <div class="card-foot flex justify-between items-center"><b id="cartTotal">0.00</b><button class="btn btn-accent" id="kotBtn" disabled><x-icon name="bell"/> Send KOT</button></div>
      @if($kotPrinters->isNotEmpty())
      <details class="small" style="padding:.4rem .8rem .7rem">
        <summary class="muted">Printer options</summary>
        <div id="kotRouteHint" class="tiny muted" style="margin:.3rem 0"></div>
        <label class="check"><input type="radio" name="print_to" value="auto" checked> Auto-route each dish to its station printer</label>
        <label class="check"><input type="radio" name="print_to" value="combined"> One combined sheet</label>
        <label class="check"><input type="radio" name="print_to" value="selected"> Only the printers I tick</label>
        <div class="kot-picks" style="margin-top:.35rem">
          @foreach($kotPrinters as $p)
            <label class="check"><input type="checkbox" name="printers[]" value="{{ $p->id }}"> {{ $p->name }} <span class="tiny muted">{{ $p->role === 'captain' ? 'captain' : ($p->station ?: 'station') }}</span></label>
          @endforeach
        </div>
        <label class="check"><input type="checkbox" name="force_printers" value="1"> Send every item to each ticked printer (use when a station printer is down)</label>
      </details>
      @endif
    </form>
    @endif

    <div class="card">
      <div class="card-head"><h3>Order {{ $order->order_no }}@if($order->split_from_id) <span class="tiny muted">(split)</span>@endif</h3><span class="small muted">{{ $order->covers }} cover(s) {{ $order->customer_name ? '· '.$order->customer_name : '' }}</span>@if($order->items->where('kitchen_status', 'held')->where('is_cancelled', false)->isNotEmpty() && can_do('pos.order'))<form method="post" action="{{ route('pos.fire', $order) }}" class="inline">@csrf<button class="btn btn-sm btn-accent">Fire held</button></form>@endif</div>
      <div class="card-body cart-lines" style="padding-top:.2rem">
        @if($order->order_type === 'delivery')<div class="alert alert-info small"><b>Delivery: {{ ucwords(str_replace('_', ' ', $order->delivery_status)) }}</b> · {{ $order->customer_name ?: 'Customer' }} · {{ $order->customer_phone ?: 'No phone' }}<br>{{ $order->delivery_address }}@if($order->delivery_fee > 0) · Fee {{ money($order->delivery_fee) }}@endif @if($order->deliveryDriver) · Driver: {{ $order->deliveryDriver->name }}@endif</div>@endif
        @php $pendingLines = $order->items->where('is_pending', true)->where('is_cancelled', false); @endphp
        @if($pendingLines->isNotEmpty())
          <div class="alert alert-warn mt-1" style="display:block">
            <div class="bold small mb-1"><x-icon name="bell" size="sm"/> Guest QR order – awaiting confirmation</div>
            @foreach($pendingLines as $l)
              <div class="cart-line"><div><b class="small">{{ $l->item_name }}</b><div class="tiny muted">{{ (float)$l->qty }} × {{ money($l->rate, false) }} {{ $l->notes }}</div></div><b class="small">{{ money($l->amount, false) }}</b><span></span></div>
            @endforeach
            @if(in_array($order->status, ['open','billed']))
            <div class="flex gap-1 mt-1">
              @if(can_do('pos.order'))<form method="post" action="{{ route('pos.accept', $order) }}" style="flex:1">@csrf<button class="btn btn-sm btn-success btn-block"><x-icon name="check" size="sm"/> Accept &amp; send KOT</button></form>@endif
              @if(can_do('pos.void'))<form method="post" action="{{ route('pos.reject', $order) }}" data-confirm="Reject the guest's pending items?">@csrf<button class="btn btn-sm btn-danger">Reject</button></form>@endif
            </div>
            @endif
          </div>
        @endif
        @forelse($order->items->where('is_pending', false)->groupBy('kot_no') as $kot => $lines)
          <div class="tiny muted mt-1">KOT #{{ $kot }}
            <a href="{{ route('pos.printkot', [$order, $kot]) }}" target="_blank">all printers</a>
            @foreach($lines->groupBy('kot_printer_id') as $pid => $pl)
              @if($pid)<a href="{{ route('pos.printkot', [$order, $kot]) }}?printer={{ $pid }}" target="_blank">{{ $printerNames[$pid] ?? 'printer' }}</a>@endif
            @endforeach
          </div>
          @foreach($lines as $l)
            <div class="cart-line" style="{{ $l->is_cancelled ? 'text-decoration:line-through;opacity:.5' : '' }}">
              <div><b class="small">{{ $l->item_name }}</b> @if($l->is_open)<span class="badge badge-amber">SPECIAL</span>@endif @if(!$l->is_cancelled && in_array($order->status,['open','billed']))<span class="badge badge-{{ ['new'=>'blue','preparing'=>'amber','ready'=>'green','served'=>'slate','held'=>'slate'][$l->kitchen_status] ?? 'slate' }}" style="font-size:.62rem" title="Kitchen status">{{ $l->kitchen_status === 'held' ? 'HOLD' : $l->kitchen_status }}</span>@if($l->kitchen_status === 'held' && can_do('pos.order'))<form method="post" action="{{ route('pos.fire', $order) }}" class="inline">@csrf<input type="hidden" name="item_id" value="{{ $l->id }}"><button class="btn btn-sm btn-accent" style="padding:0 .35rem;font-size:.7rem" title="Tell the kitchen to start this dish">Fire</button></form>@endif @if($l->kitchen_status === 'ready' && can_do('pos.order'))<form method="post" action="{{ route('pos.item.served', [$order, $l]) }}" class="inline">@csrf<button class="btn btn-sm btn-ghost" style="padding:0 .3rem;font-size:.7rem" title="Mark served">✓ served</button></form>@endif @endif<div class="tiny muted">{{ (float)$l->qty }} × {{ money($l->rate, false) }} @if($l->is_open){{ $l->kitchen_station }} @endif {{ $l->notes }}</div>@if($l->modifiers)<div class="tiny text-blue">{{ collect($l->modifiers)->map(fn ($m) => $m['name'].((float)($m['price_delta'] ?? 0) != 0 ? ' '.((float)$m['price_delta'] > 0 ? '+' : '').money($m['price_delta'], false) : ''))->implode(' · ') }}</div>@endif
                @if($l->is_complimentary)<div class="tiny"><span class="badge badge-purple">COMP</span> {{ $l->comp_reason }}</div>@endif
                @if($l->allergy_flag && ! $l->is_cancelled)<div class="tiny text-red bold" data-allergy-flag>⚠ {{ $l->allergy_flag }}</div>@endif</div>
              <b class="small">{{ $l->is_complimentary ? 'COMP' : money($l->amount, false) }}</b>
              <span class="nowrap">@if(!$l->is_cancelled && in_array($order->status,['open','billed']) && can_do('pos.settle'))
                @if($l->is_complimentary)<form method="post" action="{{ route('pos.item.comp', [$order, $l]) }}" class="inline">@csrf<input type="hidden" name="undo" value="1"><button class="btn btn-sm btn-ghost" title="Charge this dish again">Undo</button></form>
                @else<button type="button" class="btn btn-sm btn-ghost" data-modal="compModal" data-comp-url="{{ route('pos.item.comp', [$order, $l]) }}" data-comp-name="{{ $l->item_name }}" title="Give this dish away">Comp</button>@endif
              @endif @if(!$l->is_cancelled && in_array($order->status,['open','billed']) && can_do('pos.void'))<button type="button" class="btn btn-sm btn-ghost btn-icon" data-modal="cancelItemModal" data-action="{{ route('pos.item.cancel', [$order, $l]) }}" data-cancel-name="{{ $l->item_name }}" title="Cancel item"><x-icon name="x" size="sm"/></button>@endif</span>
            </div>
          @endforeach
        @empty <div class="muted small">No items yet.</div> @endforelse
      </div>
      <div class="card-body" style="border-top:1px solid var(--c-border)"><ul class="list">
        <li><span>Subtotal</span><span>{{ money($order->subtotal) }}</span></li>
        @if($order->club_member_id)<li class="small"><span>Club member</span><span>{{ $order->clubMember?->name }}</span></li>@endif
        @if($order->compValue() > 0)<li><span>Complimentary</span><span>{{ money($order->compValue()) }} not charged</span></li>@endif
        @if($order->discount > 0)<li><span>Discount</span><span>- {{ money($order->discount) }}</span></li>@endif
        @if($order->service_charge > 0)<li><span>Service charge</span><span>{{ money($order->service_charge) }}</span></li>@endif
        <li><span>Tax</span><span>{{ money($order->tax) }}</span></li>
        @if($order->round_off != 0)<li><span>Round off</span><span>{{ money($order->round_off) }}</span></li>@endif
        <li style="font-size:1.15rem"><b>Total</b><b>{{ money($order->total) }}</b></li>
        @if($paidAmount > 0)<li class="text-green"><span>Paid so far</span><span>{{ money($paidAmount) }}</span></li><li class="bold"><span>Balance due</span><span>{{ money($balanceDue) }}</span></li>@endif
        @if($order->payments->isNotEmpty())<li class="small bold" style="border-top:1px solid var(--c-border);padding-top:.6rem;margin-top:.35rem"><span>Payment history</span><span>{{ $order->payments->count() }}</span></li>
          @foreach($order->payments->sortBy('id') as $p) @php $isRefund = $p->kind === 'refund'; @endphp
            <li class="tiny"><span>{{ $isRefund ? 'Refund' : ($p->paymentMode?->name ?? 'Payment') }}@if($p->reference) · {{ $p->reference }}@endif</span><span class="{{ $isRefund ? 'text-red' : 'text-green' }}">{{ $isRefund ? '- ' : '' }}{{ money($p->amount) }}@if(!$isRefund && $p->change_amount > 0)<small class="muted"> · change {{ money($p->change_amount) }}</small>@endif</span></li>
          @endforeach
        @endif
      </ul></div>
      @if(in_array($order->status,['open','billed']) && can_do('pos.settle') && $order->items->where('is_cancelled',false)->where('is_pending', false)->count() && $pendingLines->isEmpty())
      <div class="card-foot">
        <form method="post" action="{{ route('pos.bill', $order) }}" class="flex gap-1 mb-1" aria-label="Apply discount and generate bill">@csrf
          <span class="tiny bold muted" style="align-self:center;white-space:nowrap">Apply discount</span>
          <input class="input input-sm" name="discount" type="number" step="0.01" min="0" placeholder="Amount" value="{{ $order->discount > 0 ? $order->discount : '' }}" style="width:110px" aria-label="Discount amount">
          <select class="input input-sm" name="discount_type" style="width:80px"><option value="amount">Amt</option><option value="percent">%</option></select>
          <input class="input input-sm" name="member_code" placeholder="Club card" title="Health club card / membership no. – applies the member's F&amp;B discount" value="{{ $order->clubMember?->card_no }}" style="width:110px" id="memberCode">
          <button class="btn btn-sm btn-primary" style="flex:1">{{ $order->status === 'billed' ? 'Re-bill' : 'Generate Bill' }}</button>
        </form>
        <button class="btn btn-success btn-block" data-modal="settleModal" data-settle="payment"><x-icon name="money"/> Take payment {{ money($balanceDue > 0 ? $balanceDue : $order->total) }}</button>
        <button type="button" class="btn btn-block" data-modal="settleModal" data-settle="complimentary" style="margin-top:.35rem">Complimentary / NC</button>
      </div>@endif
    </div>
  </div>
</div>

@if(in_array($order->status,['open','billed']) && can_do('pos.settle'))
<div class="modal" id="compModal"><div class="modal-box" style="max-width:480px"><form method="post" id="compForm" action="">@csrf
  <div class="modal-head"><h3 id="compTitle">Complimentary</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small muted" style="margin-top:0">The dish stays on the kitchen ticket. It is removed from the guest's bill.</p>
    <div class="field"><label>Reason</label><select name="reason" required>@foreach(\App\Models\PosOrder::COMP_REASONS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div class="field"><label>Note <span class="tiny muted">(optional)</span></label><input name="note" maxlength="80" placeholder="e.g. spilled at the table"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Mark complimentary</button></div>
</form></div></div>
@endif
@if(in_array($order->status,['open','billed']) && can_do('pos.void'))
<div class="modal" id="cancelItemModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3>Cancel item</h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close"><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small muted" style="margin-top:0">Cancel <b id="cancelItemName">this item</b> from the order and send a void slip if it reached the kitchen.</p><div class="field"><label for="cancelItemReason">Reason</label><input class="input" id="cancelItemReason" name="reason" required maxlength="120" placeholder="Guest request, unavailable, wrong item…"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Keep item</button><button class="btn btn-danger"><x-icon name="x"/> Cancel item</button></div>
</form></div></div>
@endif
@if($order->order_type === 'delivery' && in_array($order->status,['open','billed']) && can_do('pos.order'))
<div class="modal" id="deliveryModal"><div class="modal-box"><form method="post" action="{{ route('pos.delivery.status', $order) }}">@csrf
  <div class="modal-head"><h3>Delivery fulfilment</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Status</label><select name="delivery_status" required>@foreach(['pending','preparing','out_for_delivery','delivered','failed','cancelled'] as $st)<option value="{{ $st }}" @selected($order->delivery_status === $st)>{{ ucwords(str_replace('_', ' ', $st)) }}</option>@endforeach</select></div>
    <div class="field"><label>Driver</label><select name="delivery_driver_id"><option value="">Unassigned</option>@foreach($deliveryDrivers as $driver)<option value="{{ $driver->id }}" @selected($order->delivery_driver_id === $driver->id)>{{ $driver->name }}</option>@endforeach</select></div>
    <div class="field"><label>Delivery address</label><input name="delivery_address" required maxlength="255" value="{{ $order->delivery_address }}"></div>
    <div class="field"><label>Delivery notes</label><input name="delivery_notes" maxlength="190" value="{{ $order->delivery_notes }}"></div>
  </div><div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save status</button></div>
</form></div></div>
@endif
@if($order->status === 'settled' && $order->settlement === 'payment' && can_do('pos.refund'))
<div class="modal" id="refundModal"><div class="modal-box"><form method="post" action="{{ route('pos.refund', $order) }}">@csrf
  <div class="modal-head"><h3>Refund settled payment</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small muted" style="margin-top:0">Refunds are linked to the original tender and appear in the cashier shift and audit trail. Food stock is not automatically reversed.</p>
    <div class="field"><label>Refund from</label><select name="payment_id" required>@foreach($order->payments->where('kind', 'payment') as $p) @php $refundable = $p->refundableAmount(); @endphp @if($refundable > 0)<option value="{{ $p->id }}">{{ $p->paymentMode?->name ?? 'Payment' }} · {{ money($refundable) }} remaining{{ $p->reference ? ' · '.$p->reference : '' }}</option>@endif @endforeach</select></div>
    <div class="field"><label>Refund amount</label><input name="amount" type="number" min="0.01" step="0.01" required></div>
    <div class="field"><label>Reason</label><input name="reason" maxlength="190" required placeholder="Guest cancellation, wrong charge…"></div>
    <div class="field"><label>Reference</label><input name="reference" maxlength="80" placeholder="Refund slip / gateway reference"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Record refund</button></div>
</form></div></div>
@endif
@if(in_array($order->status,['open','billed']) && can_do('pos.settle'))
<div class="modal" id="settleModal"><div class="modal-box"><form method="post" action="{{ route('pos.settle', $order) }}" data-reset>@csrf
  <div class="modal-head"><h3>Take payment · {{ money($balanceDue > 0 ? $balanceDue : $order->total) }} due</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="field mb-1"><label>Settlement</label><select name="settlement" id="stl"><option value="payment">Payment</option><option value="room_post" @selected($order->reservation_room_id)>Post to room (guest folio)</option><option value="complimentary">Complimentary / NC</option></select></div>
    <div id="stlPay"><div class="form-grid">
      <div class="field"><label for="stlAmount">Amount applied</label><input id="stlAmount" name="amount" type="number" min="0.01" step="0.01" value="{{ number_format($balanceDue > 0 ? $balanceDue : $order->total, 2, '.', '') }}"></div>
      <x-field name="payment_mode_id" label="Payment mode" type="select" :options="$paymentModes"/>
      <div class="field"><label for="stlTendered">Amount tendered <span class="tiny muted">cash only for change</span></label><input id="stlTendered" name="tendered_amount" type="number" min="0" step="0.01" placeholder="Same as amount"></div>
      <div class="field"><label for="stlReference">Payment reference</label><input id="stlReference" name="reference" maxlength="80" placeholder="Receipt, MoMo, card, cheque…"></div>
    </div>
    <details class="mt-1" id="stlSplit"><summary class="small bold">Split this payment across multiple tenders</summary>
      <p class="tiny muted">Add two or more allocations below. When allocations are present, they replace the single payment fields above and can settle the bill in one request.</p>
      <div id="stlAllocations"></div>
      <button type="button" class="btn btn-sm mt-1" id="stlAddAllocation">Add payment allocation</button>
    </details>
    <details class="mt-1"><summary class="small bold">Tender details</summary><div class="form-grid mt-1">
      <div class="field"><label>Card authorisation</label><input name="card_authorisation" maxlength="80"></div><div class="field"><label>Mobile network</label><input name="mobile_network" maxlength="30" placeholder="MTN, Vodafone…"></div>
      <div class="field"><label>Mobile phone</label><input name="mobile_phone" maxlength="30"></div><div class="field"><label>Cheque number</label><input name="cheque_number" maxlength="30"></div>
      <div class="field"><label>Cheque bank name</label><input name="cheque_bank_name" maxlength="100"></div><div class="field"><label>Cheque bank branch</label><input name="cheque_bank_branch" maxlength="100"></div>
      <div class="field wide"><label>Payment notes</label><input name="payment_notes" maxlength="190"></div>
    </div></details>
      <div class="field mt-1" id="stlVoucher"><label for="f_voucher_code">Voucher code <span class="small muted">(Gift Voucher payments must cover the remaining balance)</span></label><input name="voucher_code" id="f_voucher_code" class="mono" placeholder="GV-XXXX-XXXX" maxlength="20" style="text-transform:uppercase"></div>
      @if(\App\Services\PosEvatService::enabled())<div class="form-grid mt-1" id="stlBuyer"><div class="field"><label for="f_buyer_tin">Customer TIN <span class="small muted">(optional – business customers)</span></label><input name="buyer_tin" id="f_buyer_tin" class="mono" maxlength="20" placeholder="C0012345678 / GHA-…" style="text-transform:uppercase"></div>
        <div class="field"><label for="f_buyer_name">Customer name</label><input name="buyer_name" id="f_buyer_name" maxlength="120"></div>
        <p class="tiny muted span-2" style="margin:0">The bill is stamped by GRA E-VAT when it is settled; the customer TIN is printed on it.</p></div>@endif</div>
    <div id="stlRoom" class="hidden"><div class="field"><label>In-house room</label><select name="reservation_room_id"><option value="">Select room</option>@foreach($inHouse as $s)<option value="{{ $s->id }}" @selected($order->reservation_room_id == $s->id)>{{ $s->room?->room_no }} – {{ $s->reservation->guest->full_name }}</option>@endforeach</select></div></div>
    <div id="stlComp" class="hidden"><div class="field"><label for="stlCompReason">Why is this bill complimentary?</label><input id="stlCompReason" name="comp_reason" list="posCompReasons" maxlength="120" minlength="3" required placeholder="Enter the reason (or choose a suggestion)"><datalist id="posCompReasons">@foreach(\App\Models\PosOrder::COMP_REASONS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</datalist></div><p class="tiny muted">The guest is not charged. Enter the approval or service reason; the kitchen still sends the food, and the reason is kept on the bill.</p></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-success">Settle</button></div>
</form></div></div>
@endif
@if(in_array($order->status,['open','billed']) && can_do('pos.order'))
<div class="modal" id="moveModal"><div class="modal-box"><form method="post" action="{{ route('pos.move', $order) }}">@csrf
  <div class="modal-head"><h3>Move to table</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">@if($freeTables->isEmpty())<p class="muted small">No free tables in {{ $order->outlet->name }}. Use Merge to combine with an occupied table.</p>@else
    <div class="field"><label>Free table</label><select name="pos_table_id" required>@foreach($freeTables as $t)<option value="{{ $t->id }}">Table {{ $t->table_no }}{{ $t->section ? ' · '.$t->section : '' }} ({{ $t->capacity }} seats)</option>@endforeach</select></div>@endif</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button>@if($freeTables->isNotEmpty())<button class="btn btn-primary">Move</button>@endif</div>
</form></div></div>
@endif
@if(in_array($order->status,['open','billed']) && can_do('pos.settle'))
<div class="modal" id="mergeModal"><div class="modal-box"><form method="post" action="{{ route('pos.merge', $order) }}">@csrf
  <div class="modal-head"><h3>Merge another order into {{ $order->order_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">@if($otherOrders->isEmpty())<p class="muted small">No other running orders in this outlet.</p>@else
    <div class="field"><label>Order to bring in (it will be closed as "merged")</label><select name="source_order_id" required>@foreach($otherOrders as $o)<option value="{{ $o->id }}">{{ $o->order_no }} · {{ $o->table ? 'Table '.$o->table->table_no : ucwords(str_replace('_',' ',$o->order_type)) }} · {{ money($o->total) }}</option>@endforeach</select></div>
    <p class="tiny muted">All items, KOTs and covers move to this bill. A generated bill must be re-generated.</p>@endif</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button>@if($otherOrders->isNotEmpty())<button class="btn btn-primary">Merge</button>@endif</div>
</form></div></div>
@if($order->items->where('is_pending', true)->where('is_cancelled', false)->isEmpty())
<div class="modal" id="splitModal"><div class="modal-box" style="max-width:640px"><form method="post" action="{{ route('pos.split', $order) }}">@csrf
  <div class="modal-head"><h3>Split bill: move items to a new bill</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <table class="table table-compact"><thead><tr><th>Item</th><th class="num">On bill</th><th class="num">Rate</th><th style="width:120px">Move qty</th></tr></thead><tbody>
    @foreach($order->items->where('is_cancelled', false)->where('is_pending', false) as $l)
      <tr><td>{{ $l->item_name }} <span class="tiny muted">KOT #{{ $l->kot_no }}</span></td><td class="num">{{ (float) $l->qty }}</td><td class="num">{{ money($l->rate, false) }}</td>
        <td><div class="flex gap-1"><input type="number" name="lines[{{ $l->id }}]" min="0" max="{{ (float) $l->qty }}" step="{{ fmod((float)$l->qty, 1) ? '0.5' : '1' }}" value="0" style="width:70px"><button type="button" class="btn btn-sm btn-ghost" onclick="this.previousElementSibling.value={{ (float) $l->qty }}">all</button></div></td></tr>
    @endforeach
    </tbody></table>
    <p class="tiny muted">The new bill keeps the same table/room and can be settled separately, for example to split by guest or by payment mode.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Create split bill</button></div>
</form></div></div>
@endif
@endif
@if(in_array($order->status,['open','billed']) && can_do('pos.order'))
<div class="modal" id="detailsModal"><div class="modal-box" style="max-width:560px"><form method="post" action="{{ route('pos.details', $order) }}">@csrf
  <div class="modal-head"><h3>Edit order details</h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close"><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small muted" style="margin-top:0">Update the guest and service details without changing the items already on the order.</p>
    <div class="field"><label for="detailsName">Guest name</label><input class="input" id="detailsName" name="customer_name" maxlength="120" value="{{ $order->customer_name }}" placeholder="Walk-in guest"></div>
    <div class="form-grid"><div class="field"><label for="detailsPhone">Phone</label><input class="input" id="detailsPhone" name="customer_phone" maxlength="30" value="{{ $order->customer_phone }}" placeholder="Phone number"></div><div class="field"><label for="detailsCovers">Pax</label><input class="input" id="detailsCovers" name="covers" type="number" min="1" max="50" required value="{{ $order->covers ?: 1 }}"></div></div>
    <div class="field"><label for="detailsWaiter">Waiter</label><input class="input" id="detailsWaiter" name="steward" maxlength="60" value="{{ $order->steward }}" placeholder="Enter waiter"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary"><x-icon name="check"/> Save details</button></div>
</form></div></div>
<div class="modal" id="allergyModal"><div class="modal-box" style="max-width:620px"><form method="post" action="{{ route('pos.allergies', $order) }}">@csrf
  <div class="modal-head"><h3>Guest allergies · {{ $order->order_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small muted" style="margin-top:0">Ask every guest at the table. Dishes are checked against what the chef declared and the recipe ingredients.</p>
    <div class="allergen-picks">@foreach($A::ALLERGENS as $k => [$lbl, $code])<label><input type="checkbox" name="allergens[]" value="{{ $k }}" @checked(in_array($k, $guestAll))> <span class="allergen-codes">{{ $code }}</span> {{ $lbl }}</label>@endforeach</div>
    <x-field name="allergy_note" label="Note for the kitchen (who, how severe)" :value="$order->allergy_note" placeholder="e.g. seat 2 – severe peanut allergy, carries EpiPen"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="allergySave">Save allergies</button></div>
</form></div></div>
<div class="modal" id="openModal"><div class="modal-box" style="max-width:520px">
  <div class="modal-head"><h3>Open item — special order</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small muted" style="margin-top:0">A dish that is not on the menu. The kitchen prepares it from your note, and the guest is billed the price you enter. It is not costed from a recipe.</p>
    <div class="field"><label for="openName">Dish <span class="req">*</span></label><input class="input" id="openName" maxlength="80" placeholder="e.g. Grilled prawns, no shell"></div>
    <div class="grid g-2">
      <div class="field"><label for="openPrice">Price <span class="req">*</span></label><input class="input" id="openPrice" type="number" min="0" max="100000" step="0.01" placeholder="0.00"></div>
      <div class="field"><label for="openQty">Qty</label><input class="input" id="openQty" type="number" min="0.5" max="99" step="0.5" value="1"></div>
    </div>
    <div class="grid g-2">
      <div class="field"><label for="openStation">Station</label><select class="input" id="openStation">@foreach($openStations as $st)<option value="{{ $st }}">{{ $st }}</option>@endforeach</select></div>
      <div class="field"><label for="openType">Type</label><select class="input" id="openType"><option value="veg">Veg</option><option value="non_veg">Non-veg</option><option value="egg">Egg</option><option value="beverage">Drink</option></select></div>
    </div>
    <div class="field"><label for="openNotes">How should the kitchen prepare it?</label><textarea class="input" id="openNotes" maxlength="200" rows="2" placeholder="No shell, extra chilli, serve with lemon"></textarea></div>
    <p class="tiny muted">Tax is added the same way as other food. If the guest has allergies, confirm with the chef before you add it.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="button" class="btn btn-accent" id="openAdd">Add to order</button></div>
</div></div>
@endif
<div class="modal" id="modifierModal"><div class="modal-box" style="max-width:560px">
  <div class="modal-head"><h3 id="modifierTitle">Choose options</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body" id="modifierChoices"></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" id="modifierAdd">Add to order</button></div>
</div></div>
@if(in_array($order->status,['open','billed']) && can_do('pos.void'))
<div class="modal" id="voidModal"><div class="modal-box"><form method="post" action="{{ route('pos.void', $order) }}">@csrf
  <div class="modal-head"><h3>Void order</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Void Order</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>
let cart = {};
const guestAllergies = @json($guestAll), allergenNames = @json(collect($A::ALLERGENS)->map(fn ($a, $k) => $A::short($k)));
document.querySelectorAll('.pos-context-action').forEach(cell => cell.addEventListener('keydown', event => {
  if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openModal(cell.dataset.modal); }
}));
const lines = document.getElementById('cartLines');
const foodLabel = { veg: 'Veg', non_veg: 'Non-veg', egg: 'Egg', beverage: 'Drink' };
const stationPrinters = @json(($kotPrinters ?? collect())->where('role', 'station')->mapWithKeys(fn ($p) => [$p->station => $p->name]));
function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
let openSeq = 1;
function render(){ if (!lines) return; const ids = Object.keys(cart); let tot = 0, mi = 0, oi = 0;
  lines.innerHTML = ids.length ? '' : '<div class="muted small">Tap a menu item, or add an open item. Tick Hold if the kitchen should wait.</div>';
  ids.forEach(id => { const c = cart[id]; tot += c.qty * c.price; const d = document.createElement('div'); d.className = 'cart-line';
    const photo = (!c.open && c.img && /^https?:\/\/|^\/uploads\/menu\//.test(c.img)) ? `<img src="${esc(c.img)}" alt="" class="cart-photo">` : '';
    let fields;
    if (c.open) {
      fields = `<span class="badge badge-amber">SPECIAL</span> <span class="tiny muted">${esc(foodLabel[c.food_type] || '')} · ${esc(c.station)}</span><input class="input input-sm mt-1" placeholder="How to prepare it" name="open[${oi}][notes]" value="${esc(c.notes||'')}" oninput="cart['${id}'].notes=this.value"><input type="hidden" name="open[${oi}][name]" value="${esc(c.name)}"><input type="hidden" name="open[${oi}][price]" value="${c.price}"><input type="hidden" name="open[${oi}][qty]" value="${c.qty}"><input type="hidden" name="open[${oi}][station]" value="${esc(c.station)}"><input type="hidden" name="open[${oi}][food_type]" value="${esc(c.food_type)}">${c.ack ? `<input type="hidden" name="open[${oi}][allergy_ack]" value="1"><div class="tiny text-red bold">⚠ Guest allergies – chef confirmed</div>` : ''}<label class="check tiny"><input type="checkbox" ${c.hold ? 'checked' : ''} onchange="cart['${id}'].hold=this.checked;render()"> Hold</label>${c.hold ? `<input type="hidden" name="open[${oi}][hold]" value="1">` : ''}`;
      oi++;
    } else {
      const modifierInputs = (c.modifiers || []).map(m => `<input type="hidden" name="items[${mi}][modifiers][]" value="${m.id}">`).join('');
      const modifierText = (c.modifiers || []).length ? `<div class="tiny text-blue">${c.modifiers.map(m => esc(m.name) + (Number(m.price_delta) ? ' ' + (Number(m.price_delta) > 0 ? '+' : '') + Number(m.price_delta).toFixed(2) : '')).join(' · ')}</div>` : '';
      fields = `${modifierText}<input class="input input-sm mt-1" placeholder="Note (less spicy…)" name="items[${mi}][notes]" value="${esc(c.notes||'')}" oninput="cart['${id}'].notes=this.value"><input type="hidden" name="items[${mi}][id]" value="${esc(c.menu_id || id)}"><input type="hidden" name="items[${mi}][qty]" value="${c.qty}">${modifierInputs}${c.flag ? `<input type="hidden" name="items[${mi}][allergy_ack]" value="1"><div class="tiny text-red bold">⚠ ${esc(c.flag)} – confirmed</div>` : ''}<label class="check tiny"><input type="checkbox" ${c.hold ? 'checked' : ''} onchange="cart['${id}'].hold=this.checked;render()"> Hold</label>${c.hold ? `<input type="hidden" name="items[${mi}][hold]" value="1">` : ''}`;
      mi++;
    }
    d.innerHTML = `<div class="flex gap-1">${photo}<div><b class="small">${esc(c.name)}</b>${fields}</div></div>
      <span class="qty"><button type="button" onclick="chg('${id}',-1)">−</button><span>${c.qty}</span><button type="button" onclick="chg('${id}',1)">+</button></span><b class="small">${(c.qty*c.price).toFixed(2)}</b>`; lines.appendChild(d); });
  document.getElementById('cartTotal').textContent = tot.toFixed(2); document.getElementById('kotBtn').disabled = !ids.length;
  const hint = document.getElementById('kotRouteHint');
  if (hint) { const names = [...new Set(Object.values(cart).map(c => c.open ? (stationPrinters[c.station] || c.station) : c.printer).filter(Boolean))]; hint.textContent = names.length ? 'Auto-route: ' + names.join(', ') : ''; } }
function chg(id, d){ if (!cart[id]) return; cart[id].qty = Math.round((cart[id].qty + d) * 2) / 2; if (cart[id].open && cart[id].qty > 99) cart[id].qty = 99; if (cart[id].qty <= 0) delete cart[id]; render(); }
document.getElementById('openAdd')?.addEventListener('click', () => {
  const name = document.getElementById('openName').value.trim().replace(/\s+/g, ' ');
  const price = +document.getElementById('openPrice').value;
  const qty = +document.getElementById('openQty').value;
  const station = document.getElementById('openStation').value;
  const food_type = document.getElementById('openType').value;
  const notes = document.getElementById('openNotes').value.trim();
  if (name.length < 2) { alert('Enter the dish the kitchen should prepare.'); return; }
  if (!(price >= 0) || price > 100000) { alert('Enter a price.'); return; }
  if (!(qty >= 0.5) || qty > 99) { alert('Quantity must be between 0.5 and 99.'); return; }
  if (!station) { alert('Choose a kitchen station.'); return; }
  let ack = false;
  if (guestAllergies.length) {
    const who = guestAllergies.map(a => allergenNames[a] || a).join(', ');
    if (!confirm('This guest has allergies (' + who + ').\n\nA special order has no allergen list. Only add it if the chef has confirmed it is safe. Add it anyway?')) return;
    ack = true;
  }
  cart['o' + (openSeq++)] = { open: true, name, price, qty, notes, station, food_type, ack, printer: stationPrinters[station] || station };
  document.getElementById('openName').value = '';
  document.getElementById('openPrice').value = '';
  document.getElementById('openNotes').value = '';
  document.getElementById('openQty').value = '1';
  render();
  document.querySelector('#openModal [data-close]')?.click();
});
let modifierButton = null;
let modifierGroups = [];
function addMenuItem(button, modifiers = []) {
  const has = (button.dataset.allergens ? button.dataset.allergens.split(',') : []).filter(a => guestAllergies.includes(a));
  const may = (button.dataset.may ? button.dataset.may.split(',') : []).filter(a => guestAllergies.includes(a));
  let flag = '';
  if (has.length || may.length) {
    flag = [has.length ? 'CONTAINS ' + has.map(a => allergenNames[a]).join(', ').toUpperCase() : '', may.length ? 'MAY CONTAIN ' + may.map(a => allergenNames[a]).join(', ').toUpperCase() : ''].filter(Boolean).join(' · ');
    if (!confirm(`⚠ ${button.dataset.name}: ${flag}.\n\nThe guest declared this allergy. Only add it if the chef has confirmed a safe version. Add it anyway?`)) return;
  }
  const ids = modifiers.map(m => m.id).sort((a, b) => a - b);
  const key = button.dataset.id + ':' + ids.join(',');
  if (!cart[key]) {
    const delta = modifiers.reduce((sum, m) => sum + Number(m.price_delta || 0), 0);
    cart[key] = { menu_id: button.dataset.id, name: button.dataset.name, price: +button.dataset.price + delta, qty: 0, flag, modifiers, printer: button.dataset.printer || '', img: button.dataset.img || '' };
  }
  cart[key].qty++;
  render();
}
function openModifierPicker(button, groups) {
  modifierButton = button;
  modifierGroups = groups;
  document.getElementById('modifierTitle').textContent = 'Options for ' + button.dataset.name;
  document.getElementById('modifierChoices').innerHTML = groups.map(group => `<fieldset class="field" style="border:0;padding:0;margin:0 0 1rem"><legend class="bold small">${esc(group.name)} <span class="tiny muted">${group.min}–${group.max}${group.required ? ' · required' : ''}</span></legend>${group.options.map(option => `<label class="check"><input type="checkbox" data-modifier-group="${group.id}" data-modifier-id="${option.id}" data-modifier-name="${esc(option.name)}" data-price-delta="${option.price_delta}" value="${option.id}"> ${esc(option.name)} <span class="tiny muted">${Number(option.price_delta) ? (Number(option.price_delta) > 0 ? '+' : '') + Number(option.price_delta).toFixed(2) : 'included'}</span></label>`).join('')}</fieldset>`).join('');
  document.getElementById('modifierModal').classList.add('show');
}
document.getElementById('modifierAdd')?.addEventListener('click', () => {
  const selected = [...document.querySelectorAll('#modifierChoices [data-modifier-id]:checked')];
  for (const group of modifierGroups) {
    const count = selected.filter(input => Number(input.dataset.modifierGroup) === Number(group.id)).length;
    if (count < Number(group.min) || count > Number(group.max)) { alert(`Choose between ${group.min} and ${group.max} option(s) for ${group.name}.`); return; }
  }
  addMenuItem(modifierButton, selected.map(input => ({ id: Number(input.dataset.modifierId), name: input.dataset.modifierName, price_delta: Number(input.dataset.priceDelta), group_id: Number(input.dataset.modifierGroup) })));
  document.getElementById('modifierModal').classList.remove('show');
});
document.querySelectorAll('.pos-item').forEach(button => button.addEventListener('click', () => {
  let groups = [];
  try { groups = JSON.parse(button.dataset.modifiers || '[]'); } catch (e) { groups = []; }
  if (groups.length) { openModifierPicker(button, groups); } else { addMenuItem(button); }
}));
let activePosCategory = '';
function filterMenu() {
  const q = (document.getElementById('itemSearch')?.value || '').trim().toLowerCase();
  let visible = 0;
  document.querySelectorAll('.pos-item').forEach(item => {
    const categoryMatch = !activePosCategory || item.dataset.cat === activePosCategory;
    const searchMatch = !q || item.dataset.name.toLowerCase().includes(q) || item.dataset.code.includes(q);
    const match = categoryMatch && searchMatch;
    item.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  document.getElementById('menuEmpty')?.classList.toggle('hidden', visible > 0);
}
document.querySelectorAll('.pos-cats button').forEach(b => b.addEventListener('click', () => {
  activePosCategory = b.dataset.cat || '';
  document.querySelectorAll('.pos-cats button').forEach(x => x.classList.toggle('active', x === b));
  filterMenu();
}));
const s = document.getElementById('itemSearch'); s && s.addEventListener('input', filterMenu);
document.getElementById('clearMenuFilters')?.addEventListener('click', () => {
  if (s) s.value = '';
  activePosCategory = '';
  document.querySelectorAll('.pos-cats button').forEach(x => x.classList.toggle('active', !x.dataset.cat));
  filterMenu();
  s?.focus();
});
filterMenu();
const paymentModes = @json($paymentModes);
let allocationSeq = 0;
function addAllocation() {
  const i = allocationSeq++;
  const options = Object.entries(paymentModes).map(([id, name]) => `<option value="${id}">${esc(name)}</option>`).join('');
  document.getElementById('stlAllocations').insertAdjacentHTML('beforeend', `<div class="allocation-row form-grid mt-1" data-allocation="${i}" style="border:1px solid var(--c-border);padding:.5rem;border-radius:var(--radius)">
    <div class="field"><label>Payment mode</label><select name="allocations[${i}][payment_mode_id]" required>${options}</select></div>
    <div class="field"><label>Amount applied</label><input name="allocations[${i}][amount]" type="number" min="0.01" step="0.01" required></div>
    <div class="field"><label>Tendered <span class="tiny muted">cash</span></label><input name="allocations[${i}][tendered_amount]" type="number" min="0" step="0.01" placeholder="Same as amount"></div>
    <div class="field"><label>Reference</label><input name="allocations[${i}][reference]" maxlength="80" placeholder="Receipt / authorisation"></div>
    <details class="span-2"><summary class="tiny bold">Cheque, card or mobile details</summary><div class="form-grid mt-1">
      <div class="field"><label>Card authorisation</label><input name="allocations[${i}][card_authorisation]" maxlength="80"></div><div class="field"><label>Mobile network</label><input name="allocations[${i}][mobile_network]" maxlength="30"></div>
      <div class="field"><label>Mobile phone</label><input name="allocations[${i}][mobile_phone]" maxlength="30"></div><div class="field"><label>Cheque number</label><input name="allocations[${i}][cheque_number]" maxlength="30"></div>
      <div class="field"><label>Cheque bank name</label><input name="allocations[${i}][cheque_bank_name]" maxlength="100"></div><div class="field"><label>Cheque branch</label><input name="allocations[${i}][cheque_bank_branch]" maxlength="100"></div>
      <div class="field wide"><label>Payment notes</label><input name="allocations[${i}][payment_notes]" maxlength="190"></div>
    </div></details>
    <button type="button" class="btn btn-sm btn-ghost" onclick="this.closest('[data-allocation]').remove()">Remove allocation</button>
  </div>`);
}
document.getElementById('stlAddAllocation')?.addEventListener('click', addAllocation);
const stl = document.getElementById('stl'); const sf = () => {
  if (!stl) return;
  document.getElementById('stlPay').classList.toggle('hidden', stl.value !== 'payment');
  document.getElementById('stlRoom').classList.toggle('hidden', stl.value !== 'room_post');
  const comp = document.getElementById('stlComp');
  if (comp) { comp.classList.toggle('hidden', stl.value !== 'complimentary'); comp.querySelector('[name="comp_reason"]').disabled = stl.value !== 'complimentary'; }
}; stl && (stl.addEventListener('change', sf), sf());
document.addEventListener('click', e => {
  const open = e.target.closest('[data-settle]');
  if (open && stl) {
    const allocations = document.getElementById('stlAllocations');
    if (allocations) allocations.replaceChildren();
    allocationSeq = 0;
    stl.value = open.dataset.settle;
    sf();
  }
  const compBtn = e.target.closest('[data-comp-url]');
  if (compBtn) { const f = document.getElementById('compForm'); f.action = compBtn.dataset.compUrl; document.getElementById('compTitle').textContent = 'Complimentary – ' + compBtn.dataset.compName; }
});
if (new URLSearchParams(window.location.search).get('modal') === 'split') openModal('splitModal');
document.addEventListener('click', event => {
  const cancel = event.target.closest('[data-cancel-name]');
  if (cancel) document.getElementById('cancelItemName').textContent = cancel.dataset.cancelName || 'this item';
});
</script>
@endpush
