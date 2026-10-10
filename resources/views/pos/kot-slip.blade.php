{{-- One thermal KOT slip. $slip: printer, kind, items, copies, sections, void. $order, $kot, $copy. --}}
@php
  $p = $slip['printer'] ?? null;
  $void = ! empty($slip['void']) || ($slip['kind'] ?? '') === 'cancel';
  $kind = $slip['kind'] ?? 'station';
  $fire = $kind === 'fire';
  $title = $void ? 'CANCELLED — DO NOT MAKE' : ($fire ? 'FIRE — START COOKING' : ($kind === 'captain' ? 'CAPTAIN COPY' : ($kind === 'unrouted' ? 'UNROUTED KOT' : 'KITCHEN ORDER TICKET')));
  $where = $where ?? ($order->table ? 'TABLE '.$order->table->table_no : ($order->stay ? 'ROOM '.($order->stay->room?->room_no ?? '') : strtoupper(str_replace('_', ' ', $order->order_type))));
@endphp
<div class="kot-slip{{ $void ? ' kot-void' : '' }}{{ $fire ? ' kot-fire' : '' }}{{ ($p->paper ?? '80') === '58' ? ' w58' : '' }}" data-printer="{{ $p->id ?? 'combined' }}">
  <div class="center"><b style="font-size:15px">{{ $title }}</b>
    @if($kind === 'captain' && $void)<br><b>CAPTAIN COPY</b>@endif
    <br>{{ $p->name ?? ($order->outlet->name ?? '') }}
    @if($p?->header)<br><b>{{ $p->header }}</b>@endif
    @if(($slip['copies'] ?? 1) > 1)<br>Copy {{ $copy }} of {{ $slip['copies'] }}@endif
  </div>
  <hr>
  <div><b>KOT #{{ $kot }}</b> · {{ isset($when) ? $when : now()->format('d/m H:i') }}<br>{{ $where }} · Order {{ $order->order_no }}
    @if($order->covers) · {{ $order->covers }} cover(s)@endif
    @if($order->steward)<br>Steward {{ $order->steward }}@endif
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
