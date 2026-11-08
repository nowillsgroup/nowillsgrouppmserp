@extends('layouts.app')
@section('title', 'Check-out · Room '.$stay->room?->room_no)
@section('crumbs')
  @if(can_do('frontdesk.view'))<a href="{{ route('frontdesk.departures') }}">Departures</a>
  @elseif(can_do('reservations.view'))<a href="{{ route('reservations.workspace') }}">Reservation Workspace</a>
  @else<span>Front Office</span>@endif / Check-out
@endsection
@section('actions')
  @if($canViewFolio)<a class="btn" href="{{ route('folios.show', $folio) }}"><x-icon name="receipt"/> Open full folio</a>@endif
  @if(can_do('reservations.view'))<a class="btn btn-soft" href="{{ route('reservations.show', $stay->reservation_id) }}"><x-icon name="eye"/> Reservation</a>@endif
@endsection
@section('content')
@php
  $guestName = $stay->reservation->guest?->full_name ?? 'Guest';
  $currentBalance = (float) $totals['balance'];
  $projectedBalance = (float) ($checkoutDue ?? $currentBalance);
  $checkoutDate = bdate();
  $holds = $checkoutHolds;
@endphp
<style>
  .checkout-page { --checkout-green:#17623b; color:var(--c-text); }
  .checkout-hero { display:flex; align-items:flex-start; justify-content:space-between; gap:1.1rem; margin:0 0 1rem; padding:1.05rem 1.15rem; border:1px solid #d7e8db; border-radius:15px; background:linear-gradient(115deg,#eef8f0 0%,var(--c-surface) 72%); }
  .checkout-eyebrow { display:flex; align-items:center; gap:.4rem; color:var(--checkout-green); font-size:.72rem; font-weight:800; letter-spacing:.09em; text-transform:uppercase; }.checkout-eyebrow .i{width:15px;height:15px}
  .checkout-hero h2 { margin:.35rem 0 .25rem; color:var(--c-text); font-size:1.38rem; line-height:1.2; letter-spacing:-.02em; }
  .checkout-hero-copy>p { max-width:650px; margin:.28rem 0 0; color:var(--c-text-2); font-size:.84rem; line-height:1.45; }
  .checkout-context { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; margin-top:.55rem; color:var(--c-text-2); font-size:.77rem; }.checkout-context .i{width:13px;height:13px}
  .checkout-dates { display:grid; grid-template-columns:repeat(2,minmax(100px,1fr)); gap:.45rem; flex:0 0 auto; }
  .checkout-date { padding:.58rem .68rem; border:1px solid #dcebe0; border-radius:10px; background:rgba(255,255,255,.86); }
  .checkout-date span { display:block; color:var(--c-text-2); font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
  .checkout-date time { display:block; margin-top:.2rem; color:#24583a; font-size:.82rem; font-weight:750; white-space:nowrap; font-variant-numeric:tabular-nums; }
  .checkout-metrics { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.65rem; margin-bottom:1rem; }
  .checkout-metric { min-width:0; padding:.72rem .82rem; border:1px solid var(--c-border); border-radius:12px; background:var(--c-surface); box-shadow:var(--shadow-sm); }
  .checkout-metric b { display:block; font-size:1.1rem; line-height:1.15; font-weight:780; font-variant-numeric:tabular-nums; }
  .checkout-metric span { display:block; margin-top:.2rem; color:var(--c-text-2); font-size:.72rem; }
  .checkout-metric.projected { border-top:3px solid #17623b; }.checkout-metric.current { border-top:3px solid #2563eb; }.checkout-metric.nights { border-top:3px solid #d97706; }.checkout-metric.holds { border-top:3px solid {{ $holds['blocking'] ? '#b91c1c' : '#16a34a' }}; }
  .checkout-page .alert { border-radius:11px; line-height:1.48; }
  .checkout-page .alert a { font-weight:700; }
  .checkout-layout { align-items:start; }
  .checkout-ledger { overflow:hidden; }
  .checkout-ledger .card-head { align-items:flex-start; }
  .checkout-ledger-table { width:100%; min-width:680px; border-collapse:collapse; font-size:.8rem; }
  .checkout-ledger-table th { padding:.62rem .72rem; border-bottom:1px solid var(--c-border); background:#f5f8f6; color:#53645a; text-align:left; font-size:.67rem; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
  .checkout-ledger-table td { padding:.68rem .72rem; border-bottom:1px solid var(--c-border); vertical-align:top; }
  .checkout-ledger-table tr:last-child td { border-bottom:0; }
  .checkout-ledger-table .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .checkout-ledger-table .void td { color:var(--c-muted); }
  .checkout-ledger-empty { padding:1.4rem .8rem; color:var(--c-text-2); text-align:center; }
  .checkout-summary-note { margin:0 0 .7rem; padding:.65rem .72rem; border:1px solid var(--c-border); border-radius:9px; background:var(--c-bg); color:var(--c-text-2); font-size:.77rem; line-height:1.45; }
  .checkout-hold-list { margin:.3rem 0 .65rem 1.1rem; padding:0; color:var(--c-text-2); }
  .checkout-form .card-head h3 { display:flex; align-items:center; gap:.45rem; }
  .checkout-form .field label { display:block; }
  .checkout-form .card-foot { background:#f7faf8; }
  .checkout-form .btn-lg { min-height:46px; }
  .checkout-form .form-grid [data-checkout-payment-row] { background:var(--c-surface); }
  body.dark .checkout-hero { border-color:var(--c-border); background:linear-gradient(115deg,#172c1f 0%,var(--c-surface) 75%); }
  body.dark .checkout-eyebrow { color:#bce8c9; }
  body.dark .checkout-date { border-color:var(--c-border); background:#1b2d21; }
  body.dark .checkout-date time { color:#bce8c9; }
  body.dark .checkout-ledger-table th { background:#18271e; color:var(--c-text-2); }
  body.dark .checkout-summary-note,body.dark .checkout-form .card-foot { border-color:var(--c-border); background:#18271e; }
  @media(max-width:1100px) { .checkout-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media(max-width:700px) { .checkout-hero{display:block;padding:.9rem}.checkout-hero h2{font-size:1.2rem}.checkout-dates{margin-top:.75rem}.checkout-metrics{gap:.5rem}.checkout-metric{padding:.65rem .7rem}.checkout-metric b{font-size:1rem}.checkout-layout{grid-template-columns:1fr}.checkout-page .table-wrap{overflow-x:auto}.checkout-ledger-table{min-width:640px}.checkout-form .card-body{padding:.85rem} }
  @media(prefers-reduced-motion:reduce) { .checkout-page * { scroll-behavior:auto!important; transition:none!important; } }
  @media print { .checkout-page .checkout-hero{background:#fff;border-color:#888}.checkout-page .checkout-date{background:#fff;border-color:#888}.checkout-page .checkout-metrics{grid-template-columns:repeat(4,1fr)}.checkout-page .checkout-alert-stack,.checkout-page .checkout-form,.checkout-page .no-print{display:none!important}.checkout-layout{display:block}.checkout-ledger{box-shadow:none}.checkout-ledger-table{min-width:0;font-size:8pt}.checkout-ledger-table th,.checkout-ledger-table td{padding:4px}.checkout-page .card{break-inside:avoid} }
</style>
<div class="checkout-page">
  <section class="checkout-hero" aria-label="Check-out overview">
    <div class="checkout-hero-copy">
      <div class="checkout-eyebrow"><x-icon name="logout"/> Front office · Departure and settlement</div>
      <h2>Check out {{ $guestName }}</h2>
      <p>Review the folio, resolve outstanding room charges and service items, then confirm the payment or company settlement before closing the stay.</p>
      <div class="checkout-context"><x-icon name="calendar"/> Reservation <strong>{{ $stay->reservation->reservation_no }}</strong> · Room <strong>{{ $stay->room?->room_no ?? 'Unassigned' }}</strong>@if($canViewFolio) · Folio <strong>{{ $folio->folio_no }}</strong>@endif {!! status_badge('checked_in') !!}</div>
    </div>
    <div class="checkout-dates" aria-label="Stay dates">
      <div class="checkout-date"><span>Arrived</span><time datetime="{{ $stay->arrival_date->toDateString() }}">{{ $stay->arrival_date->format('d M Y') }}</time></div>
      <div class="checkout-date"><span>Business date</span><time datetime="{{ $checkoutDate->toDateString() }}">{{ $checkoutDate->format('d M Y') }}</time></div>
    </div>
  </section>
  <section class="checkout-metrics" aria-label="Check-out settlement summary">
    <div class="checkout-metric projected"><b>{{ money(abs($projectedBalance)) }}</b><span>{{ $projectedBalance < 0 ? 'Projected refund due' : 'Projected balance due' }}</span></div>
    <div class="checkout-metric current"><b>{{ money(abs($currentBalance)) }}</b><span>{{ $currentBalance < 0 ? 'Current folio credit' : 'Current folio balance' }}</span></div>
    <div class="checkout-metric nights"><b>{{ number_format($checkoutFigures['missing']) }}</b><span>Room nights not yet posted</span></div>
    <div class="checkout-metric holds"><b>{{ $holds['blocking'] ? 'Action needed' : 'Clear' }}</b><span>Checkout blocking items</span></div>
  </section>
  <div class="checkout-alert-stack" aria-label="Departure checklist">
@if($openPos->count())<div class="alert alert-warn"><x-icon name="alert"/><div><b>{{ $openPos->count() }} unsettled POS order(s)</b> for this room: @if(can_do('pos.view'))@foreach($openPos as $o)<a href="{{ route('pos.terminal', $o) }}">{{ $o->order_no }} ({{ $o->outlet->name }})</a> @endforeach — settle or post to room before check-out.@else Order details are restricted to POS-authorized staff; settle or post the outstanding orders before check-out.@endif</div></div>@endif
@if(($openLaundry ?? collect())->count())<div class="alert alert-warn" id="laundryWarn"><x-icon name="shirt"/><div><b>{{ $openLaundry->count() }} laundry order(s) still open</b> for this room: @if(can_do('laundry.view'))@foreach($openLaundry as $lo)<a href="{{ route('laundry.show', $lo) }}">{{ $lo->order_no }}</a> ({{ $lo->statusLabel() }}{{ (float) $lo->total ? ', '.money($lo->total) : '' }}) @endforeach — deliver and settle them, or cancel them, before check-out.@else Laundry order details are restricted to laundry-authorized staff; ask the laundry desk to deliver and settle or cancel them before check-out.@endif</div></div>@endif
@if(($openParking ?? collect())->count())<div class="alert alert-info" id="parkingWarn"><x-icon name="key"/><div><b>{{ $openParking->count() }} active parking session(s) for this stay.</b> @if(can_do('parking.view') || can_do('parking.operate') || can_do('parking.validate') || can_do('parking.manage'))@foreach($openParking as $pk)<a href="{{ route('parking.session', $pk) }}">{{ $pk->plate }}</a> ({{ $pk->number }}{{ $pk->key_tag ? ', key tag '.$pk->key_tag : '' }}) @endforeach — parking nights so far are already on this bill, and nothing more is charged for today. @if($openParking->where('is_valet', true)->count())Ask whether to call the car up now.@endif @else Session and vehicle details require parking permission. Parking nights are already included in the bill; nothing more is charged for today.@endif</div></div>@endif
@if(($deskOpen ?? collect())->count() || ($deskItems ?? collect())->count())<div class="alert alert-warn" id="deskWarn"><x-icon name="mail"/><div>
  @if($deskOpen->count())@if(can_do('desk.messages'))<b>Hand over before the guest leaves:</b> @foreach($deskOpen as $dm){{ $dm->isParcel() ? '📦 parcel' : '✉ message' }} {{ $dm->message_no }} from {{ $dm->from_name }}@if(! $loop->last), @endif @endforeach. <a href="{{ route('desk.messages', ['q' => $stay->room?->room_no]) }}">Open messages</a> – undelivered messages lapse at check-out; parcels stay on the list to be forwarded.@else{{ $deskOpen->count() }} guest message(s) or parcel(s) need desk attention before departure; message details are restricted.@endif @endif
  @if($deskItems->count())<div><b>Lost & found:</b> @if(can_do('lostfound.manage'))@foreach($deskItems as $li)<a href="{{ route('lostfound.show', $li) }}">{{ $li->item_no }} {{ $li->item_name }}</a>@if(! $loop->last), @endif @endforeach was found in the room during this stay – return it now.@else{{ $deskItems->count() }} stored found item(s) need authorized staff to review before departure.@endif</div>@endif
</div></div>@endif
@if(($deskWakeups ?? collect())->count())
<div class="alert alert-info" id="deskWakeWarn"><x-icon name="clock"/><div>{{ $deskWakeups->count() }} wake-up call(s)
  @if(can_do('desk.wakeup'))booked ({{ $deskWakeups->map(fn ($w) => $w->call_at->format('d M H:i'))->implode(', ') }})
  @else are scheduled; call details are restricted to desk-authorized staff
  @endif will be cancelled at check-out.
</div></div>
@endif
@if(($formcOpen ?? collect())->count())<div class="alert alert-{{ \App\Services\FormCService::cfg()['block_checkout'] === '1' ? 'danger' : 'warn' }}" id="formcWarn"><x-icon name="globe"/><div><b>Form C not filed with the FRRO</b> @if(can_do('formc.view'))for @foreach($formcOpen as $fc)<a href="{{ route('fg.formc.show', $fc) }}">{{ $fc->form_no }} {{ $fc->name() }}</a>{{ $fc->isOverdue() ? ' (overdue)' : '' }} @endforeach @else for {{ $formcOpen->count() }} pending guest record(s); record details require Form C permission @endif
  – {{ \App\Services\FormCService::cfg()['block_checkout'] === '1' ? 'check-out is blocked until it is filed.' : 'the arrival report is still due; the departure must be reported after check-out too.' }}</div></div>@endif
@if(($openTrips ?? collect())->count())<div class="alert alert-warn" id="transportWarn"><x-icon name="car"/><div><b>{{ $openTrips->count() }} transfer(s) billed to this room</b> are not on the bill yet: @if(can_do('transport.view'))@foreach($openTrips as $tt)<a href="{{ route('tr.show', $tt) }}">{{ $tt->trip_no }}</a> ({{ $tt->typeLabel() }} {{ $tt->scheduled_at->format('d M H:i') }}, {{ money($tt->total) }}) @endforeach @else Transfer identities and amounts require transport permission.@endif
  @if(can_do('transport.book'))<form method="post" action="{{ route('tr.stay.charge', $stay) }}" class="inline">@csrf<button class="btn btn-sm btn-primary" id="postTransportBtn">Add to this bill</button></form>@endif — or change them to direct payment / complimentary before check-out.</div></div>@endif
<div class="grid g-2-1 checkout-layout">
  @if($canViewFolio)
  <div class="card flush checkout-ledger">
    <div class="card-head"><h3>Folio {{ $folio->folio_no }} — {{ $stay->reservation->guest->full_name }}</h3><span class="small muted">{{ $stay->arrival_date->format('d M') }} → {{ bdate()->format('d M Y') }}</span></div>
    <div class="card-body table-wrap">
      <table class="checkout-ledger-table"><thead><tr><th>Date</th><th>Description</th><th class="num">Amount</th><th class="num">Tax</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
      @foreach($transactions as $t)
        <tr class="{{ $t->is_void ? 'void' : '' }}"><td class="nowrap">{{ $t->business_date->format('d M') }}</td><td>{{ $t->description }} @if($t->paymentMode)<span class="tiny muted">({{ $t->paymentMode->name }})</span>@endif</td>
        <td class="num">{{ $t->type === 'charge' ? money($t->amount, false) : '' }}</td><td class="num">{{ $t->type === 'charge' ? money($t->tax_amount, false) : '' }}</td>
        <td class="num">{{ in_array($t->type, ['charge','refund']) ? money($t->total, false) : '' }}</td><td class="num">{{ in_array($t->type, ['payment','discount','allowance']) ? money($t->amount, false) : '' }}</td></tr>
      @endforeach
      </tbody></table>
    </div>
  </div>
  @else
  <div class="card checkout-ledger"><div class="card-head"><h3>Folio detail</h3><span class="small muted">Restricted</span></div><div class="card-body"><p class="checkout-summary-note" style="margin:0">Your role can complete check-out, but does not have permission to view the itemized folio. The projected settlement total is shown here; ask an authorized folio user if you need the line-by-line breakdown.</p></div></div>
  @endif
  <div>
    <div class="card"><div class="card-head"><h3>Summary</h3></div><div class="card-body">
      <p class="small">Opening this screen did not post a night. {{ $canViewFolio ? $checkoutQuote : 'Unposted room charges are included in the projected settlement amount above.' }}</p>
      @php $postable = $holds['pos']['count'] || $holds['trips']['count']; @endphp
      @if ($holds['blocking'] > 0)
      <div class="alert alert-warning" style="margin:8px 0">
        <b>Check-out is refused until these are resolved:</b>
        <ul class="small" style="margin:4px 0 6px 18px">
          @if ($holds['pos']['count'])
          <li>{{ $holds['pos']['count'] }} unsettled outlet bill(s) — @if(can_do('pos.view')){{ money($holds['pos']['total']) }}@else amount details restricted @endif</li>
          @endif
          @if ($holds['trips']['count'])
          <li>{{ $holds['trips']['count'] }} transport trip(s) billed to the room — @if(can_do('transport.view')){{ money($holds['trips']['total']) }}@else amount details restricted @endif</li>
          @endif
          @if ($holds['laundry']['count'])
          <li>{{ $holds['laundry']['count'] }} open laundry order(s) — @if(can_do('laundry.view')){{ money($holds['laundry']['total']) }}@else amount details restricted @endif</li>
          @endif
          @if ($holds['activities']['count'])
          <li>{{ $holds['activities']['count'] }} activity booking(s) awaiting operator confirmation — @if(can_do('concierge.view')){{ money($holds['activities']['total']) }}@else amount details restricted @endif</li>
          @endif
          @if ($holds['formc']['count'])
          <li>Form C @if(can_do('formc.view')){{ $holds['formc']['form_no'] }}@else pending record @endif not filed with the FRRO</li>
          @endif
        </ul>
        @if ($postable)
        <form method="post" action="{{ route('frontdesk.prepare', $stay) }}">@csrf
          <button class="btn btn-warning btn-sm">Post outstanding charges to this bill</button>
          <span class="small muted">Eligible outlet bills and transport go onto the folio at their own totals. Resolve laundry in Laundry; activities need the operator and Form C is filed from GRC.</span>
        </form>
        @else
        <span class="small muted">Nothing here can be posted automatically — clear the items above in their own screens first.</span>
        @endif
      </div>
      @endif
      <ul class="list">
      @if($canViewFolio)
      <li><span>Total charges (incl. tax)</span><b>{{ money($totals['charges']) }}</b></li>
      <li><span>of which tax</span><span>{{ money($totals['tax']) }}</span></li>
      <li><span>Discounts</span><b>- {{ money($totals['discount']) }}</b></li>
      <li><span>Payments received</span><b class="text-green">- {{ money($totals['payments']) }}</b></li>
      @if($totals['refunds'] > 0)<li><span>Refunds</span><b>{{ money($totals['refunds']) }}</b></li>@endif
      @endif
      <li style="font-size:1.1rem"><span class="bold">Balance {{ $totals['balance'] < 0 ? '(refund due)' : 'due' }}</span><b class="{{ $totals['balance'] > 0 ? 'text-red' : 'text-green' }}">{{ money(abs($totals['balance'])) }}</b></li>
    </ul></div></div>
    <form method="post" action="{{ route('frontdesk.checkout.store', $stay) }}" class="card checkout-form" id="checkoutSettleForm" data-confirm="Confirm check-out and generate invoice?">@csrf
      <div class="card-head"><h3>Settlement</h3></div>
      <div class="card-body">
        <div class="field mb-1"><label>Settle by</label>
          <select name="settle" id="settleBy">
            <option value="payment">Direct payment</option>
            <option value="company" @selected($stay->reservation->billing_instruction === 'company')>Bill to company (City Ledger)</option>
            <option value="none">No balance / already settled</option>
          </select></div>
        <div id="payBox">
          <div id="checkoutSinglePayment">
            <x-field name="amount" label="{{ $totals['balance'] < 0 ? 'Refund amount' : 'Amount' }}" type="number" step="0.01" hint="Leave blank to settle the full balance after unposted nights are added. A typed amount must equal the full balance to complete checkout."/><div class="mt-1"></div>
            <x-field name="payment_mode_id" label="Payment mode" type="select" :options="$paymentModes" data-payment-mode/><div class="mt-1"></div>
            <x-payment-momo-fields/>
            <div class="mt-1"></div><x-field name="reference" label="Reference / transaction ID" hint="Gift Voucher: the voucher code"/>
            <x-cheque-fields/>
          </div>
          <div class="mt-1"><button type="button" class="btn btn-sm" id="checkoutToggleSplit">Split across payment methods</button></div>
          <div id="checkoutSplitPayment" class="mt-1" hidden>
            <p class="small muted">Add at least two tenders and allocate the full amount due (after any unposted nights). Expected settlement: <b>{{ money(abs($checkoutDue ?? $totals['balance'])) }}</b>; allocated so far: <b id="checkoutAllocationTotal">0.00</b>. The entries are recorded together on the folio.</p>
            <div id="checkoutPaymentAllocations"></div>
            <button type="button" class="btn btn-sm mt-1" id="checkoutAddPaymentAllocation">Add another payment method</button>
          </div>
        </div>
        <div id="coBox" class="hidden">@include('credit._picker', ['companies' => $companies, 'value' => $stay->reservation->company_id, 'amount' => max(0, $checkoutDue ?? $totals['balance'])])</div>
      </div>
      @if(($forexOk ?? false) && can_do('forex.exchange') && $totals['balance'] > 0)<div class="card-body" style="padding-top:0"><a href="{{ route('fg.fx.create', ['stay' => $stay->id, 'payout' => 'folio']) }}" class="small" id="payForexLink"><x-icon name="globe" size="sm"/> Guest wants to pay in foreign currency</a></div>@endif
      <div class="card-foot"><button class="btn btn-warning btn-lg btn-block"><x-icon name="logout"/> Settle & Check-out</button></div>
    </form>
  </div>
</div>
</div>
@endsection
@push('scripts')<script>
const s = document.getElementById('settleBy'); const f = () => { document.getElementById('payBox').classList.toggle('hidden', s.value !== 'payment'); document.getElementById('coBox').classList.toggle('hidden', s.value !== 'company'); }; s.addEventListener('change', f); f();
(() => {
  const form = document.getElementById('checkoutSettleForm');
  const toggle = document.getElementById('checkoutToggleSplit');
  const single = document.getElementById('checkoutSinglePayment');
  const split = document.getElementById('checkoutSplitPayment');
  const rows = document.getElementById('checkoutPaymentAllocations');
  const allocationTotal = document.getElementById('checkoutAllocationTotal');
  if (!form || !toggle || !single || !split || !rows) return;
  const modes = @json($paymentModes);
  const oldLines = @js(old('payments', []));
  const esc = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let sequence = 0;
  let restoredOldLines = false;
  const addRow = (line = {}) => {
    if (!line || typeof line !== 'object') line = {};
    const i = sequence++;
    const options = Object.entries(modes).map(([id, name]) => `<option value="${esc(id)}">${esc(name)}</option>`).join('');
    rows.insertAdjacentHTML('beforeend', `<div class="form-grid mt-1" data-checkout-payment-row style="border:1px solid var(--c-border);padding:.65rem;border-radius:var(--radius)">
      <div class="field"><label>Payment method</label><select name="payments[${i}][payment_mode_id]" required>${options}</select></div>
      <div class="field"><label>Amount</label><input name="payments[${i}][amount]" type="number" min="0.01" step="0.01" required></div>
      <div class="field" data-checkout-momo-field hidden><label for="checkoutMomoNetwork${i}">MoMo Network</label><select id="checkoutMomoNetwork${i}" name="payments[${i}][momo_network]" disabled><option value="">Select network</option><option value="MTN">MTN MoMo</option><option value="Telecel">Telecel Cash</option><option value="AirtelTigo">AT Money</option></select></div>
      <div class="field" data-checkout-momo-field hidden><label for="checkoutMomoPhone${i}">MoMo number</label><input id="checkoutMomoPhone${i}" name="payments[${i}][momo_phone]" type="tel" maxlength="30" inputmode="tel" autocomplete="tel" placeholder="024 123 4567" disabled></div>
      <div class="field span-all"><label>Reference / transaction ID</label><input name="payments[${i}][reference]" maxlength="80" placeholder="Receipt, card authorisation, or voucher code"></div>
      <div class="form-grid mt-1 span-all" data-cheque-fields hidden><div class="field span-all"><strong class="tiny">Cheque details</strong></div>
        <div class="field"><label>Bank name</label><input name="payments[${i}][cheque_bank_name]" maxlength="100" disabled></div>
        <div class="field"><label>Cheque number</label><input name="payments[${i}][cheque_number]" maxlength="20" disabled></div>
        <div class="field"><label>Bank branch</label><input name="payments[${i}][cheque_bank_branch]" maxlength="100" disabled></div>
      </div>
      <button type="button" class="btn btn-sm btn-ghost" data-remove-checkout-payment>Remove this tender</button>
    </div>`);
    const row = rows.lastElementChild;
    for (const key of ['payment_mode_id', 'amount', 'reference', 'momo_network', 'momo_phone', 'cheque_bank_name', 'cheque_number', 'cheque_bank_branch']) {
      const field = row.querySelector(`[name="payments[${i}][${key}]"]`);
      if (field && line[key] !== undefined && line[key] !== null) field.value = String(line[key]);
    }
    syncChequeRows();
    syncMomoRows();
    updateTotal();
  };
  const syncChequeRows = () => rows.querySelectorAll('[data-checkout-payment-row]').forEach(row => {
    const mode = row.querySelector('select[name$="[payment_mode_id]"]');
    const required = (mode?.selectedOptions?.[0]?.textContent || '').toLowerCase().includes('cheque');
    const details = row.querySelector('[data-cheque-fields]');
    if (details) details.hidden = !required;
    row.querySelectorAll('input[name$="[cheque_bank_name]"],input[name$="[cheque_number]"],input[name$="[cheque_bank_branch]"]').forEach(input => { input.required = required; input.disabled = !required; });
  });
  const syncMomoRows = () => rows.querySelectorAll('[data-checkout-payment-row]').forEach(row => {
    const mode = row.querySelector('select[name$="[payment_mode_id]"]');
    const label = (mode?.selectedOptions?.[0]?.textContent || '').trim().toLowerCase();
    const momo = label.includes('momo') || label.includes('mobile money');
    row.querySelectorAll('[data-checkout-momo-field]').forEach(field => {
      field.hidden = !momo;
      const control = field.querySelector('select,input');
      if (control) { control.required = momo; control.disabled = !momo; }
    });
  });
  const updateTotal = () => {
    const total = [...rows.querySelectorAll('input[name$="[amount]"]')].reduce((sum, input) => sum + (Number(input.value) || 0), 0);
    if (allocationTotal) allocationTotal.textContent = total.toFixed(2);
  };
  rows.addEventListener('input', updateTotal);
  rows.addEventListener('change', event => {
    updateTotal();
    if (event.target.matches('select[name$="[payment_mode_id]"]')) { syncChequeRows(); syncMomoRows(); }
  });
  const syncControls = () => {
    const paymentEnabled = s.value === 'payment';
    const splitEnabled = !split.hidden;
    single.querySelectorAll('input,select').forEach(input => { input.disabled = !paymentEnabled || splitEnabled || !!input.closest('[data-cheque-fields]')?.hidden || !!input.closest('[data-momo-fields]')?.hidden; });
    split.querySelectorAll('input,select').forEach(input => { input.disabled = !paymentEnabled || !splitEnabled || !!input.closest('[data-cheque-fields]')?.hidden || !!input.closest('[data-checkout-momo-field]')?.hidden; });
    toggle.disabled = !paymentEnabled;
  };
  const setSplit = enabled => {
    single.hidden = enabled;
    split.hidden = !enabled;
    toggle.textContent = enabled ? 'Use one payment method' : 'Split across payment methods';
    if (enabled && rows.children.length === 0) {
      if (!restoredOldLines && Array.isArray(oldLines) && oldLines.length) {
        oldLines.slice(0, 10).forEach(line => addRow(line));
        restoredOldLines = true;
      }
      while (rows.children.length < 2) addRow();
    }
    if (!enabled) rows.replaceChildren();
    syncControls();
  };
  s.addEventListener('change', syncControls);
  syncControls();
  toggle.addEventListener('click', () => setSplit(split.hidden));
  if (Array.isArray(oldLines) && oldLines.length) setSplit(true);
  document.getElementById('checkoutAddPaymentAllocation')?.addEventListener('click', addRow);
  rows.addEventListener('click', event => {
    if (event.target.closest('[data-remove-checkout-payment]')) {
      event.target.closest('[data-checkout-payment-row]')?.remove();
      updateTotal();
    }
  });
  form.addEventListener('submit', event => {
    if (s.value === 'payment' && !split.hidden && rows.querySelectorAll('[data-checkout-payment-row]').length < 2) {
      event.preventDefault();
      alert('Add at least two payment methods, or switch back to a single payment.');
    }
  });
})();
</script>@endpush
