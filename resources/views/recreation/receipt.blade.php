@extends('layouts.app')
@section('title', 'Receipt '.$sale->sale_no)
@section('crumbs') <a href="{{ route('club.index') }}">Wellness &amp; Recreation</a> / <a href="{{ route('recreation.sales') }}">Ticket sales</a> / {{ $sale->sale_no }} @endsection
@section('actions')
<button class="btn btn-primary" onclick="window.print()"><x-icon name="print"/> Print</button>
@endsection
@section('content')
<div class="card" style="max-width:560px;margin:0 auto">
  <div class="card-body" style="font-family:ui-monospace,Menlo,Consolas,monospace">
    <div style="text-align:center">
      <div class="bold" style="font-size:17px">Nowills PMS — Recreation Outlet</div>
      <div class="tiny muted">Wellness &amp; Recreation · Ticket receipt</div>
      <div class="tiny muted">{{ bdate()->format('l, d F Y') }}</div>
    </div>
    <div style="border-top:1px dashed var(--line);margin:10px 0;padding-top:10px">
      <div class="flex gap-2" style="justify-content:space-between"><span>Sale</span><b>{{ $sale->sale_no }}</b></div>
      <div class="flex gap-2" style="justify-content:space-between"><span>Time</span><span>{{ $sale->sold_at->format('d M Y H:i') }}</span></div>
      <div class="flex gap-2" style="justify-content:space-between"><span>Guest</span><span>{{ $sale->guest_name ?: 'Walk-in' }}</span></div>
      @if($sale->phone)<div class="flex gap-2" style="justify-content:space-between"><span>Phone</span><span>{{ $sale->phone }}</span></div>@endif
      @if($sale->host)<div class="flex gap-2" style="justify-content:space-between"><span>Host member</span><span>{{ $sale->host->name }}</span></div>@endif
      <div class="flex gap-2" style="justify-content:space-between"><span>Served by</span><span>{{ $sale->user?->name ?? '—' }}</span></div>
    </div>
    <table class="table" style="width:100%">
      <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead>
      <tbody>
        @foreach($sale->items as $it)
          <tr><td>{{ $it->description }}</td><td class="num">{{ $it->qty }}</td><td class="num">{{ money($it->line_total + $it->tax, false) }}</td></tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr><td colspan="2">Subtotal</td><td class="num">{{ money($sale->subtotal, false) }}</td></tr>
        <tr><td colspan="2">Tax</td><td class="num">{{ money($sale->tax, false) }}</td></tr>
        <tr><td colspan="2" class="bold">TOTAL</td><td class="num bold">{{ money($sale->total, false) }}</td></tr>
      </tfoot>
    </table>
    <div style="border-top:1px dashed var(--line);margin:10px 0;padding-top:10px">
      @if($sale->stay)
        <div class="flex gap-2" style="justify-content:space-between"><span>Charged to room</span><b>{{ $sale->stay->room?->room_no }}</b></div>
      @else
        @foreach($sale->payments as $pay)
          <div class="flex gap-2" style="justify-content:space-between"><span>{{ $pay->paymentMode?->name }}{{ $pay->reference ? ' · '.$pay->reference : '' }}</span><b>{{ money($pay->amount, false) }}</b></div>
        @endforeach
        @if($sale->change_due > 0)<div class="flex gap-2" style="justify-content:space-between"><span>Change</span><b>{{ money($sale->change_due, false) }}</b></div>@endif
      @endif
    </div>

    @if($sale->passes->count())
      <div style="border-top:1px dashed var(--line);margin:10px 0;padding-top:10px">
        <div class="bold" style="text-align:center">— ENTRY TICKETS —</div>
        @foreach($sale->passes as $p)
          <div style="border:1px dashed var(--line);margin:8px 0;padding:10px;text-align:center">
            <div style="font-size:19px;letter-spacing:2px"><b>{{ $p->pass_no }}</b></div>
            <div class="tiny">{{ $p->name }} · {{ $p->adults }} adult(s){{ $p->children ? ' + '.$p->children.' child(ren)' : '' }} · valid {{ dmy($p->date) }}</div>
            <div class="tiny muted">Show this code at the check-in desk</div>
          </div>
        @endforeach
      </div>
    @endif

    <div style="text-align:center" class="tiny muted">
      @if($sale->isVoid())<div class="bold text-red">*** VOID — {{ $sale->cancel_reason }} ***</div>@endif
      Thank you — enjoy your visit · {{ money($sale->total, false) }}
    </div>
  </div>
</div>
@endsection
