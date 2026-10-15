@extends('layouts.app')
@section('title', 'Check-out · Room '.$stay->room?->room_no)
@section('crumbs') <a href="{{ route('frontdesk.departures') }}">Departures</a> / Check-out @endsection
@section('actions')<a class="btn" href="{{ route('folios.show', $folio) }}"><x-icon name="receipt"/> Open full folio</a>@endsection
@section('content')
@if($openPos->count())<div class="alert alert-warn"><x-icon name="alert"/><div><b>{{ $openPos->count() }} unsettled POS order(s)</b> for this room: @foreach($openPos as $o)<a href="{{ route('pos.terminal', $o) }}">{{ $o->order_no }} ({{ $o->outlet->name }})</a> @endforeach — settle or post to room before check-out.</div></div>@endif
@if(($openLaundry ?? collect())->count())<div class="alert alert-warn" id="laundryWarn"><x-icon name="shirt"/><div><b>{{ $openLaundry->count() }} laundry order(s) still open</b> for this room: @foreach($openLaundry as $lo)<a href="{{ route('laundry.show', $lo) }}">{{ $lo->order_no }}</a> ({{ $lo->statusLabel() }}{{ (float) $lo->total ? ', '.money($lo->total) : '' }}) @endforeach — deliver them now so the charge lands on this bill, or cancel them.</div></div>@endif
@if(($openParking ?? collect())->count())<div class="alert alert-info" id="parkingWarn"><x-icon name="key"/><div><b>Vehicle in the car park:</b> @foreach($openParking as $pk)<a href="{{ route('parking.session', $pk) }}">{{ $pk->plate }}</a> ({{ $pk->number }}{{ $pk->key_tag ? ', key tag '.$pk->key_tag : '' }}) @endforeach — the parking nights so far are already on this bill, and nothing more is charged for today. @if($openParking->where('is_valet', true)->count())Ask whether to call the car up now.@endif</div></div>@endif
@if(($deskOpen ?? collect())->count() || ($deskItems ?? collect())->count())<div class="alert alert-warn" id="deskWarn"><x-icon name="mail"/><div>
  @if($deskOpen->count())<b>Hand over before the guest leaves:</b> @foreach($deskOpen as $dm){{ $dm->isParcel() ? '📦 parcel' : '✉ message' }} {{ $dm->message_no }} from {{ $dm->from_name }}@if(! $loop->last), @endif @endforeach. @if(can_do('desk.messages'))<a href="{{ route('desk.messages', ['q' => $stay->room?->room_no]) }}">Open messages</a> – undelivered messages lapse at check-out; parcels stay on the list to be forwarded.@endif @endif
  @if($deskItems->count())<div><b>Lost & found:</b> @foreach($deskItems as $li)@if(can_do('lostfound.manage'))<a href="{{ route('lostfound.show', $li) }}">{{ $li->item_no }} {{ $li->item_name }}</a>@else{{ $li->item_no }} {{ $li->item_name }}@endif @if(! $loop->last), @endif @endforeach was found in the room during this stay – return it now.</div>@endif
</div></div>@endif
@if(($deskWakeups ?? collect())->count())<div class="alert alert-info" id="deskWakeWarn"><x-icon name="clock"/><div>{{ $deskWakeups->count() }} wake-up call(s) booked ({{ $deskWakeups->map(fn ($w) => $w->call_at->format('d M H:i'))->implode(', ') }}) will be cancelled at check-out.</div></div>@endif
@if(($formcOpen ?? collect())->count())<div class="alert alert-{{ \App\Services\FormCService::cfg()['block_checkout'] === '1' ? 'danger' : 'warn' }}" id="formcWarn"><x-icon name="globe"/><div><b>Form C not filed with the FRRO</b> for @foreach($formcOpen as $fc)@if(can_do('formc.view'))<a href="{{ route('fg.formc.show', $fc) }}">{{ $fc->form_no }} {{ $fc->name() }}</a>@else{{ $fc->form_no }} {{ $fc->name() }}@endif{{ $fc->isOverdue() ? ' (overdue)' : '' }} @endforeach
  – {{ \App\Services\FormCService::cfg()['block_checkout'] === '1' ? 'check-out is blocked until it is filed.' : 'the arrival report is still due; the departure must be reported after check-out too.' }}</div></div>@endif
@if(($openTrips ?? collect())->count())<div class="alert alert-warn" id="transportWarn"><x-icon name="car"/><div><b>{{ $openTrips->count() }} transfer(s) billed to this room</b> are not on the bill yet: @foreach($openTrips as $tt)@if(can_do('transport.view'))<a href="{{ route('tr.show', $tt) }}">{{ $tt->trip_no }}</a>@else{{ $tt->trip_no }}@endif ({{ $tt->typeLabel() }} {{ $tt->scheduled_at->format('d M H:i') }}, {{ money($tt->total) }}) @endforeach
  @if(can_do('transport.book'))<form method="post" action="{{ route('tr.stay.charge', $stay) }}" class="inline">@csrf<button class="btn btn-sm btn-primary" id="postTransportBtn">Add to this bill</button></form>@endif — or change them to direct payment / complimentary before check-out.</div></div>@endif
<div class="grid g-2-1">
  <div class="card flush">
    <div class="card-head"><h3>Folio {{ $folio->folio_no }} — {{ $stay->reservation->guest->full_name }}</h3><span class="small muted">{{ $stay->arrival_date->format('d M') }} → {{ bdate()->format('d M Y') }}</span></div>
    <div class="card-body table-wrap">
      <table class="table"><thead><tr><th>Date</th><th>Description</th><th class="num">Amount</th><th class="num">Tax</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
      @foreach($transactions as $t)
        <tr class="{{ $t->is_void ? 'void' : '' }}"><td class="nowrap">{{ $t->business_date->format('d M') }}</td><td>{{ $t->description }} @if($t->paymentMode)<span class="tiny muted">({{ $t->paymentMode->name }})</span>@endif</td>
        <td class="num">{{ $t->type === 'charge' ? money($t->amount, false) : '' }}</td><td class="num">{{ $t->type === 'charge' ? money($t->tax_amount, false) : '' }}</td>
        <td class="num">{{ in_array($t->type, ['charge','refund']) ? money($t->total, false) : '' }}</td><td class="num">{{ in_array($t->type, ['payment','discount','allowance']) ? money($t->amount, false) : '' }}</td></tr>
      @endforeach
      </tbody></table>
    </div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Summary</h3></div><div class="card-body">
      <p class="small">Opening this screen did not post a night. {{ $checkoutQuote }}</p>
      @php $holds = \App\Services\StayService::checkoutHolds($stay); $postable = $holds['pos']['count'] || $holds['trips']['count'] || $holds['laundry']['count']; @endphp
      @if ($holds['blocking'] > 0)
      <div class="alert alert-warning" style="margin:8px 0">
        <b>Check-out is refused until these reach the bill:</b>
        <ul class="small" style="margin:4px 0 6px 18px">
          @if ($holds['pos']['count'])<li>{{ $holds['pos']['count'] }} unsettled outlet bill(s) — {{ money($holds['pos']['total']) }}</li>@endif
          @if ($holds['trips']['count'])<li>{{ $holds['trips']['count'] }} transport trip(s) billed to the room — {{ money($holds['trips']['total']) }}</li>@endif
          @if ($holds['laundry']['count'])<li>{{ $holds['laundry']['count'] }} open laundry order(s) — {{ money($holds['laundry']['total']) }}</li>@endif
          @if ($holds['activities']['count'])<li>{{ $holds['activities']['count'] }} activity booking(s) awaiting operator confirmation — {{ money($holds['activities']['total']) }}</li>@endif
          @if ($holds['formc']['count'])<li>Form C {{ $holds['formc']['form_no'] }} not filed with the FRRO</li>@endif
        </ul>
        @if ($postable)
        <form method="post" action="{{ route('frontdesk.prepare', $stay) }}">@csrf
          <button class="btn btn-warning btn-sm">Post outstanding charges to this bill</button>
          <span class="small muted">Outlet bills, laundry and transport go onto the folio at their own totals. Activities need the operator; Form C is filed from GRC.</span>
        </form>
        @else
        <span class="small muted">Nothing here can be posted automatically — clear the items above in their own screens first.</span>
        @endif
      </div>
      @endif
      <ul class="list">
      <li><span>Total charges (incl. tax)</span><b>{{ money($totals['charges']) }}</b></li>
      <li><span>of which tax</span><span>{{ money($totals['tax']) }}</span></li>
      <li><span>Discounts</span><b>- {{ money($totals['discount']) }}</b></li>
      <li><span>Payments received</span><b class="text-green">- {{ money($totals['payments']) }}</b></li>
      @if($totals['refunds'] > 0)<li><span>Refunds</span><b>{{ money($totals['refunds']) }}</b></li>@endif
      <li style="font-size:1.1rem"><span class="bold">Balance {{ $totals['balance'] < 0 ? '(refund due)' : 'due' }}</span><b class="{{ $totals['balance'] > 0 ? 'text-red' : 'text-green' }}">{{ money(abs($totals['balance'])) }}</b></li>
    </ul></div></div>
    <form method="post" action="{{ route('frontdesk.checkout.store', $stay) }}" class="card" data-confirm="Confirm check-out and generate invoice?">@csrf
      <div class="card-head"><h3>Settlement</h3></div>
      <div class="card-body">
        <div class="field mb-1"><label>Settle by</label>
          <select name="settle" id="settleBy">
            <option value="payment">Direct payment</option>
            <option value="company" @selected($stay->reservation->billing_instruction === 'company')>Bill to company (City Ledger)</option>
            <option value="none">No balance / already settled</option>
          </select></div>
        <div id="payBox">
          <x-field name="amount" label="{{ $totals['balance'] < 0 ? 'Refund amount' : 'Amount' }}" type="number" step="0.01" hint="Leave blank to settle the balance after unposted nights are added. A typed amount is a part payment and will not close the folio if anything remains."/><div class="mt-1"></div>
          <x-field name="payment_mode_id" label="Payment mode" type="select" :options="$paymentModes" data-payment-mode/><div class="mt-1"></div>
          <x-field name="reference" label="Reference / Txn ID" hint="Gift Voucher: the voucher code"/>
          <x-cheque-fields/>
        </div>
        <div id="coBox" class="hidden">@include('credit._picker', ['companies' => $companies, 'value' => $stay->reservation->company_id, 'amount' => max(0, $checkoutDue ?? $totals['balance'])])</div>
      </div>
      @if(($forexOk ?? false) && $totals['balance'] > 0)<div class="card-body" style="padding-top:0"><a href="{{ route('fg.fx.create', ['stay' => $stay->id, 'payout' => 'folio']) }}" class="small" id="payForexLink"><x-icon name="globe" size="sm"/> Guest wants to pay in foreign currency</a></div>@endif
      <div class="card-foot"><button class="btn btn-warning btn-lg btn-block"><x-icon name="logout"/> Settle & Check-out</button></div>
    </form>
  </div>
</div>
@endsection
@push('scripts')<script>
const s = document.getElementById('settleBy'); const f = () => { document.getElementById('payBox').classList.toggle('hidden', s.value !== 'payment'); document.getElementById('coBox').classList.toggle('hidden', s.value !== 'company'); }; s.addEventListener('change', f); f();
</script>@endpush
