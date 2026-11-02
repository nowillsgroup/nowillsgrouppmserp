{{-- One thermal KOT slip. $slip: printer, kind, items, copies, sections, void. $order, $kot, $copy. --}}
@php
  $p = $slip['printer'] ?? null;
  $void = ! empty($slip['void']) || ($slip['kind'] ?? '') === 'cancel';
  $kind = $slip['kind'] ?? 'station';
  $fire = $kind === 'fire';
  $title = $void ? 'CANCELLED — DO NOT MAKE' : ($fire ? 'FIRE — START COOKING' : ($kind === 'captain' ? 'CAPTAIN COPY' : ($kind === 'unrouted' ? 'UNROUTED KOT' : 'KITCHEN ORDER TICKET')));
  $where = $where ?? ($order->table ? 'TABLE '.$order->table->table_no : ($order->stay ? 'ROOM '.($order->stay->room?->room_no ?? '') : strtoupper(str_replace('_', ' ', $order->order_type))));
  $kotPrintedAt = isset($when) ? \Illuminate\Support\Carbon::parse($when) : now();
@endphp
<div class="kot-slip{{ $void ? ' kot-void' : '' }}{{ $fire ? ' kot-fire' : '' }}{{ ($p->paper ?? '80') === '58' ? ' w58' : '' }}" data-printer="{{ $p->id ?? 'combined' }}">
  <div class="center kot-slip-head"><b class="kot-slip-title">{{ $title }}</b>
    @if($kind === 'captain' && $void)<br><b class="kot-slip-copy-title">CAPTAIN COPY</b>@endif
    <br><span class="kot-slip-outlet">{{ $p->name ?? ($order->outlet->name ?? '') }}</span>
    @if($p?->header)<br><b class="kot-slip-custom-header">{{ $p->header }}</b>@endif
    @if(($slip['copies'] ?? 1) > 1)<br><span class="kot-slip-copy-count">Copy {{ $copy }} of {{ $slip['copies'] }}</span>@endif
  </div>
  <hr>
  <div class="kot-slip-meta">
    <div class="kot-slip-meta-grid kot-slip-meta-primary">
      <div class="kot-slip-meta-cell"><span class="kot-slip-meta-label">KOT No.</span><b class="kot-slip-meta-value">#{{ $kot }}</b></div>
      <div class="kot-slip-meta-cell"><span class="kot-slip-meta-label">Date</span><b class="kot-slip-meta-value">{{ $kotPrintedAt->format('d/m/Y') }}</b></div>
      <div class="kot-slip-meta-cell"><span class="kot-slip-meta-label">Time</span><b class="kot-slip-meta-value">{{ $kotPrintedAt->format('H:i') }}</b></div>
    </div>
    <div class="kot-slip-meta-grid kot-slip-meta-order">
      <div class="kot-slip-meta-cell"><span class="kot-slip-meta-label">Table No.</span><b class="kot-slip-meta-value">{{ $order->table?->table_no ?? '—' }}</b></div>
      <div class="kot-slip-meta-cell"><span class="kot-slip-meta-label">Order No.</span><b class="kot-slip-meta-value">{{ $order->order_no }}</b></div>
    </div>
    @if(!$order->table && $order->stay)<div class="kot-slip-location"><b>Room No.:</b> {{ $order->stay->room?->room_no ?? '—' }}</div>@elseif(!$order->table)<div class="kot-slip-location"><b>Type:</b> {{ $where }}</div>@endif
    @if($order->covers)<div class="kot-slip-covers"><b>Covers / Pax:</b> {{ $order->covers }}</div>@endif
    @if($order->steward)<div class="kot-slip-steward">Waiter {{ $order->steward }}</div>@endif
  </div>
  @if($order->allergens)
    <hr>
    <div class="center" @if($copy === 1 && ($firstAllergy ?? false)) id="kotAllergy" @endif style="border:2px solid #000;padding:4px;margin:4px 0;font-weight:800;font-size:14px">*** ALLERGY: {{ strtoupper(\App\Services\AllergenService::names($order->allergens)) }} ***@if($order->allergy_note)<br><span style="font-size:12px">{{ $order->allergy_note }}</span>@endif</div>
  @endif
  <hr>
  @if(!empty($slip['sections']))
    @foreach($slip['sections'] as $sec)
      <div style="margin-top:.35rem"><b>{{ $sec['label'] }}</b></div>
      @include('pos.kot-lines', ['items' => $sec['items']])
    @endforeach
  @else
    @include('pos.kot-lines', ['items' => $slip['items']])
  @endif
  <hr>
  <div class="center">— {{ $by ?? auth()->user()->name }} —</div>
</div>
