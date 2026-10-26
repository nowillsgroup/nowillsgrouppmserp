@extends('layouts.app')
@section('title', 'Purchase Order '.$po->po_no)
@section('crumbs') <a href="{{ route('purchases.index') }}">Purchase Orders</a> / {{ $po->po_no }} @endsection
@php
  $manage = can_do('inventory.manage');
  $canApprove = $po->status === 'draft' && ! $blocker;
  $byGrn = $po->receipts->isNotEmpty();
  $aboveN = $po->items->filter(fn ($l) => $l->aboveList())->count();
  $home = strtoupper(trim((string) (hotel()?->currency ?: 'GHS')));
  $currency = $po->isForeign() ? $po->currency : $home;
@endphp
@section('actions')
{!! status_badge($po->status) !!}
<button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>
@if($po->status === 'draft' && $canApprove)<form method="post" action="{{ route('purchases.status', $po) }}">@csrf<button name="status" value="approved" class="btn btn-primary" id="approveBtn"><x-icon name="check"/> Approve</button></form>@endif
@if($manage)
  @if(in_array($po->status, ['approved', 'partial']))<button class="btn btn-success" data-modal="grnModal" id="grnBtn"><x-icon name="box"/> Receive Goods (GRN)</button>@endif
  @if($po->status === 'partial')<button class="btn" data-modal="closeModal" id="shortCloseBtn">Short-close balance</button>@endif
  @if(in_array($po->status, ['draft', 'approved']))<form method="post" action="{{ route('purchases.status', $po) }}" data-confirm="Cancel this PO?">@csrf<button name="status" value="cancelled" class="btn btn-danger">Cancel</button></form>@endif
@endif
@endsection
@section('content')
@if($po->status === 'draft')
<div class="alert {{ $canApprove ? 'alert-info' : 'alert-warn' }}" id="approvalPanel"><x-icon name="shield"/><div>
  <b>Approval required: {{ \App\Services\ProcurementService::levelLabel($level) }}</b> – {{ money($po->total) }}@if($aboveN) · <span class="text-red">{{ $aboveN }} line(s) priced above the agreed supplier rate</span>@endif
  <div class="small">{{ $blocker ?? 'You can approve this purchase order.' }}</div>
</div></div>
@endif
@if($po->isForeign())<div class="notice notice-info"><b>{{ $po->fxLabel() }}</b> at {{ number_format((float) $po->fx_rate, 4) }} {{ $po->fx_basis }} board of {{ $po->fx_date?->format('d M Y') }}@if($po->fx_date && $po->fx_date->toDateString() !== $po->po_date->toDateString()) <span class="badge badge-amber">carried forward</span>@endif · approval and PO comparison value <b>{{ money($po->total) }}</b> in {{ $home ?? strtoupper((string) (hotel()?->currency ?: 'GHS')) }}. Each GRN is revalued at its own receipt-date board.</div>@endif
<div class="card"><div class="card-body grid g-3">
  <dl class="kv"><dt>Supplier</dt><dd>{{ $po->supplier->name }}</dd><dt>Contact</dt><dd>{{ $po->supplier->contact_person }} {{ $po->supplier->phone }}</dd><dt>TIN</dt><dd>{{ $po->supplier->tax_number }}</dd>@if($po->rfq)<dt>From RFQ</dt><dd><a href="{{ route('rfq.show', $po->rfq) }}">{{ $po->rfq->rfq_no }}</a></dd>@endif</dl>
  <dl class="kv"><dt>PO date</dt><dd>{{ $po->po_date->format('d M Y') }}</dd><dt>Expected</dt><dd>{{ dmy($po->expected_date) }}@if($po->isOverdue()) <span class="badge badge-red" id="poOverdue">overdue</span>@endif</dd><dt>Store</dt><dd>{{ $po->store->name }}</dd></dl>
  <dl class="kv"><dt>Created by</dt><dd>{{ $po->creator?->name }}</dd><dt>Approved</dt><dd id="poApproved">@if($po->approved_at){{ $po->approver?->name ?? '—' }} · {{ $po->approved_at->format('d M Y') }}@if($po->approval_level) <span class="tiny muted">L{{ $po->approval_level }}</span>@endif @else — @endif</dd><dt>Received</dt><dd>{{ $po->received_at?->format('d M Y H:i') ?? ($po->status === 'partial' ? 'part delivered' : '—') }}</dd><dt>Supplier inv.</dt><dd>{{ $po->invoice_no ?? '—' }}</dd></dl>
</div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="poItems"><thead><tr><th>#</th><th>Item</th><th class="num">Ordered</th><th class="num">Received</th><th class="num">Rejected</th><th class="num">Outstanding</th><th class="num">Rate · {{ $currency }}</th><th class="num">Agreed · {{ $home }}</th><th class="num">Tax %</th><th class="num">Amount · {{ $currency }}</th></tr></thead><tbody>
@foreach($po->items as $l)@php $poRate = $po->isForeign() ? (float) $l->fx_unit_rate : (float) $l->rate; $poAmount = $po->isForeign() ? (float) $l->fx_amount : (float) $l->amount; @endphp<tr>
  <td>{{ $loop->iteration }}</td><td>{{ $l->item->name }}</td><td class="num">{{ (float) $l->qty }} {{ $l->item->unit }}</td>
  <td class="num">{{ (float) $l->received_qty ?: '—' }}</td><td class="num {{ (float) $l->rejected_qty ? 'text-red' : '' }}">{{ (float) $l->rejected_qty ?: '—' }}</td>
  <td class="num">@if(in_array($po->status, ['approved', 'partial'])){{ $l->outstanding() }}@elseif($po->status === 'closed' && $l->outstanding() > 0)<span class="muted" title="Short-closed">{{ $l->outstanding() }} ✕</span>@else — @endif</td>
  <td class="num">{{ $po->isForeign() ? number_format($poRate, 4) : money($poRate, false) }}
    @if($po->isForeign())<div class="tiny muted">{{ money($l->rate, false) }} {{ $home }} at PO-date rate</div>@endif
    @if($l->aboveList())<div class="tiny text-red" data-above>above agreed</div>@endif
  </td><td class="num muted">{{ $l->list_rate !== null ? money($l->list_rate, false) : '—' }}</td>
  <td class="num">{{ (float) $l->tax_rate }}</td><td class="num">{{ $po->isForeign() ? number_format($poAmount, 2) : money($poAmount, false) }}@if($po->isForeign())<div class="tiny muted">{{ money($l->amount, false) }} {{ $home }}</div>@endif</td></tr>@endforeach
</tbody><tfoot><tr><td colspan="9" class="right">Subtotal · {{ $currency }}</td><td class="num">{{ $po->isForeign() ? number_format($po->fx_subtotal, 2) : money($po->subtotal) }}@if($po->isForeign())<div class="tiny muted">{{ money($po->subtotal) }} {{ $home }}</div>@endif</td></tr><tr><td colspan="9" class="right">Tax · {{ $currency }}</td><td class="num">{{ $po->isForeign() ? number_format($po->fx_tax, 2) : money($po->tax) }}@if($po->isForeign())<div class="tiny muted">{{ money($po->tax) }} {{ $home }}</div>@endif</td></tr><tr><td colspan="9" class="right bold">Total · {{ $currency }}</td><td class="num bold">{{ $po->isForeign() ? number_format($po->fx_total, 2) : money($po->total) }}@if($po->isForeign())<div class="tiny muted">{{ money($po->total) }} {{ $home }}</div>@endif</td></tr>
@if($byGrn)<tr><td colspan="9" class="right bold">Received value · {{ $home }}</td><td class="num bold" id="poReceivedValue">{{ money($po->receivedValue()) }}@if($po->isForeign())@php [$receivedFxSub, $receivedFxTax] = $po->receivedForeignSplit(); @endphp<div class="tiny muted">{{ $po->currency }} {{ number_format($receivedFxSub + $receivedFxTax, 2) }}</div>@endif</td></tr>@endif</tfoot></table></div></div>

@if($byGrn)
<div class="card" id="poReceipts"><div class="card-head"><h3>Goods receipts</h3><span class="small muted">{{ $po->receipts->count() }} delivery(ies)</span></div>
<div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>GRN</th><th>Date / FX board</th><th>Delivery note</th><th>Items</th><th class="num">Accepted · {{ $home }}</th><th class="num">Rejected</th><th>Received by</th><th></th></tr></thead><tbody>
@foreach($po->receipts as $g)<tr><td class="bold">{{ $g->grn_no }}</td><td>{{ $g->date->format('d M Y') }}@if(! $g->onTime()) <span class="badge badge-amber">late</span>@endif @if($g->isForeign())<div class="tiny muted">1 {{ $g->currency }} = {{ number_format((float) $g->fx_rate, 4) }} · {{ $g->fx_basis }} board {{ $g->fx_date?->format('d M Y') }}@if($g->fx_date && $g->fx_date->toDateString() !== $g->date->toDateString()) <span class="badge badge-amber">carried forward</span>@endif</div>@endif</td><td>{{ $g->delivery_note ?: '—' }}@if($g->invoice_no)<div class="tiny muted">inv. {{ $g->invoice_no }}</div>@endif</td>
  <td class="small">@foreach($g->lines as $gl){{ $gl->item->name }} {{ (float) $gl->qty_accepted }}@if((float) $gl->qty_rejected)<span class="text-red"> (−{{ (float) $gl->qty_rejected }}: {{ $gl->reject_reason }})</span>@endif{{ $loop->last ? '' : ' · ' }}@endforeach</td>
  <td class="num">{{ money($g->total, false) }}@if($g->isForeign())<div class="tiny muted">{{ $g->fxLabel() }}</div>@endif</td><td class="num {{ (float) $g->rejected_value ? 'text-red' : 'muted' }}">{{ (float) $g->rejected_value ? money($g->rejected_value, false) : '—' }}@if($g->isForeign() && (float) $g->fx_rejected_value)<div class="tiny">{{ $g->currency }} {{ number_format($g->fx_rejected_value, 2) }}</div>@endif</td><td class="small">{{ $g->receiver?->name }}</td>
  <td class="right"><a class="btn btn-sm" href="{{ route('grn.print', $g) }}?autoprint=1" target="_blank"><x-icon name="print" size="sm"/></a></td></tr>@endforeach
</tbody></table></div></div>
@endif
@if($po->status === 'closed')<div class="alert alert-warn" id="poClosed"><x-icon name="alert"/><div><b>Short-closed</b> {{ $po->closed_at?->format('d M Y') }}: {{ $po->close_reason }}</div></div>@endif

@if(in_array($po->status, ['received', 'closed', 'partial']) && can_do('ap.view'))
@php $bill = $po->bill; @endphp
<div class="card" id="poBill"><div class="card-body flex gap-2 items-center wrap">
  <x-icon name="receipt"/>
  @if($bill)
    <div><b>Supplier bill <a href="{{ route('ap.bills.show', $bill) }}">{{ $bill->doc_no }}</a></b> · {{ $bill->supplier_ref }} · {{ $bill->isForeign() ? $bill->fxLabel() : money($bill->total) }}@if($bill->isForeign()) <span class="small muted">({{ money($bill->total) }} at {{ number_format((float) $bill->fx_rate, 4) }})</span>@endif {!! status_badge($bill->status) !!}
    <div class="small muted">Paid {{ money($bill->allocated) }} · outstanding {{ money($bill->outstanding()) }} · due {{ $bill->due_date?->format('d M Y') }}@if($bill->variance()) · price/tax variance {{ money($bill->variance()) }}@endif @if($bill->fxDifference()) · receipt-to-invoice FX {{ money($bill->fxDifference()) }}@endif</div></div>
    @if(in_array($bill->status, ['open', 'partial']) && can_do('ap.pay'))<a class="btn btn-sm btn-success" style="margin-left:auto" href="{{ route('ap.payments.create', ['supplier' => $po->supplier_id, 'bills' => [$bill->id]]) }}">Pay</a>@endif
  @elseif($po->status === 'partial')
    <div><b>Part delivered.</b> <span class="small muted">{{ money($po->receivedValue()) }} received so far is owed to the supplier. Match the bill once the balance arrives or the PO is short-closed.</span></div>
  @else
    <div><b>No supplier bill yet.</b> <span class="small muted">The goods are owed at {{ money($po->receivedValue()) }} until the supplier's invoice is matched.</span></div>
    @if(can_do('ap.manage'))<a class="btn btn-sm btn-primary" style="margin-left:auto" href="{{ route('ap.bills.create', ['po' => $po->id]) }}">Enter supplier bill</a>@endif
  @endif
</div></div>
@endif
@if($po->notes)<div class="card"><div class="card-body">{{ $po->notes }}</div></div>@endif

@if(in_array($po->status, ['approved', 'partial']) && $manage)
<div class="modal" id="grnModal"><div class="modal-box" style="max-width:860px"><form method="post" action="{{ route('purchases.receive', $po) }}">@csrf
<div class="modal-head"><h3>Goods Receipt Note – {{ $po->po_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body">
  <div class="form-grid">
    <div class="field"><label for="grnDate">Received on</label><input class="input" type="date" name="date" id="grnDate" value="{{ bdate()->toDateString() }}" min="{{ $po->po_date->toDateString() }}" max="{{ bdate()->toDateString() }}" required></div>
    <x-field name="delivery_note" label="Delivery note / waybill no."/>
    <x-field name="invoice_no" label="Supplier invoice no."/>
    <x-field name="vehicle_no" label="Vehicle no."/>
  </div>
  @if($po->isForeign())<div id="grnFxInfo" class="notice notice-info" style="margin:.6rem 0" data-currency="{{ $po->currency }}" data-home="{{ $home }}" data-rate-url="{{ route('purchases.fx-rate') }}">Loading receipt-date Forex Desk rate…</div>@endif
  <table class="table table-compact mt-1" id="grnLines"><thead><tr><th>Item</th><th class="num">Outstanding</th><th style="width:110px">Delivered</th><th style="width:100px">Rejected</th><th>Reject reason</th></tr></thead><tbody>
  @foreach($po->items as $l)@if($l->outstanding() > 0 || $overPct > 0)
  <tr @if($po->isForeign())data-grn-line data-fx-unit="{{ $l->fx_unit_rate }}" data-tax="{{ $l->tax_rate }}"@endif><td>{{ $l->item->name }}<div class="tiny muted">@ {{ $po->isForeign() ? number_format((float) $l->fx_unit_rate, 4).' '.$po->currency.' · '.money($l->rate, false).' '.$home.' at PO-date board' : money($l->rate, false) }}</div></td><td class="num">{{ $l->outstanding() }} {{ $l->item->unit }}</td>
    <td><input class="input input-sm" type="number" step="0.01" min="0" name="lines[{{ $l->id }}][delivered]" value="{{ $l->outstanding() }}" data-grn-del></td>
    <td><input class="input input-sm" type="number" step="0.01" min="0" name="lines[{{ $l->id }}][rejected]" value="0" data-grn-rej></td>
    <td><input class="input input-sm" name="lines[{{ $l->id }}][reason]" maxlength="120" placeholder="damaged, expired, wrong spec…"></td></tr>
  @endif @endforeach
  </tbody></table>
  <p class="tiny muted">Accepted quantity (delivered − rejected) goes into <b>{{ $po->store->name }}</b> stock. @if($po->isForeign())Stock and the GRN accrual are valued at the Forex Desk board for this receipt date, not the PO-date rate.@else Stock is valued at the PO rate.@endif Rejected goods go back with the driver and are not owed.@if($overPct) Over-delivery tolerance: {{ $overPct }}%.@endif Leave the balance at 0 for items not in this delivery.</p>
  <x-field name="notes" label="Notes"/>
  @if(can_do('ap.manage') && ! $po->isForeign())<label class="check small"><input type="checkbox" name="create_bill" value="1"> <span>If this completes the PO and the invoice matches, record the supplier bill now</span></label>@elseif(can_do('ap.manage') && $po->isForeign())<p class="tiny muted">Enter the supplier bill separately in AP using the actual invoice date so the invoice-date FX board and receipt-to-invoice exchange movement are recorded correctly.</p>@endif
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-success" id="postGrn">Post GRN</button></div></form></div></div>
@endif
@if($po->status === 'partial' && $manage)
<div class="modal" id="closeModal"><div class="modal-box"><form method="post" action="{{ route('purchases.close', $po) }}">@csrf
<div class="modal-head"><h3>Short-close {{ $po->po_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><p class="small">The undelivered balance (<b>{{ money($po->items->sum(fn ($l) => $l->outstanding() * $l->rate)) }}</b> before tax @if($po->isForeign()) · {{ $po->currency }} {{ number_format($po->items->sum(fn ($l) => $l->outstanding() * $l->fx_unit_rate), 2) }}@endif) is cancelled. The PO can then be billed at the received value of {{ money($po->receivedValue()) }}@if($po->isForeign()) ({{ $po->currency }} {{ number_format(array_sum($po->receivedForeignSplit()), 2) }})@endif.</p>
<x-field name="reason" label="Reason" required placeholder="e.g. supplier out of stock – balance sourced elsewhere"/></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger">Close balance</button></div></form></div></div>
@endif
@if($po->isForeign() && in_array($po->status, ['approved', 'partial']) && $manage)
@push('scripts')<script>
(() => {
  const info = document.getElementById('grnFxInfo'), date = document.getElementById('grnDate'), button = document.getElementById('postGrn');
  if (!info || !date || !button) return;
  let quote = null, requestNo = 0;
  const round = n => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
  const estimate = () => {
    if (!quote) return null;
    let subtotal = 0, tax = 0;
    document.querySelectorAll('#grnLines tr[data-grn-line]').forEach(row => {
      const delivered = parseFloat(row.querySelector('[data-grn-del]').value) || 0;
      const rejected = parseFloat(row.querySelector('[data-grn-rej]').value) || 0;
      const accepted = Math.max(0, delivered - rejected);
      const localRate = round((parseFloat(row.dataset.fxUnit) || 0) * quote.rate);
      const amount = round(accepted * localRate);
      subtotal += amount; tax += round(amount * (parseFloat(row.dataset.tax) || 0) / 100);
    });
    return round(subtotal + tax);
  };
  const load = async () => {
    const current = ++requestNo;
    quote = null; button.disabled = true; info.className = 'notice notice-info'; info.textContent = 'Loading receipt-date Forex Desk rate…';
    const params = new URLSearchParams({currency: info.dataset.currency, date: date.value});
    try {
      const response = await fetch(info.dataset.rateUrl + '?' + params.toString(), {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
      const data = await response.json();
      if (current !== requestNo) return;
      if (!response.ok) throw new Error(data.message || 'No Forex Desk rate is available for this receipt date.');
      quote = data;
      info.className = 'notice notice-info';
      const value = estimate();
      info.textContent = `1 ${info.dataset.currency} = ${Number(data.rate).toFixed(4)} ${info.dataset.home} · ${data.basis} board of ${data.date}${data.stale ? ' (carried forward within the configured window)' : ''}. Estimated stock and GRN accrual: ${info.dataset.home} ${Number(value || 0).toFixed(2)}.`;
      button.disabled = false;
    } catch (error) {
      if (current !== requestNo) return;
      quote = null; info.className = 'alert alert-warn'; info.textContent = error.message; button.disabled = true;
    }
  };
  date.addEventListener('change', load);
  document.querySelectorAll('#grnLines [data-grn-del], #grnLines [data-grn-rej]').forEach(input => input.addEventListener('input', () => {
    if (quote) { const value = estimate(); info.textContent = info.textContent.replace(/Estimated stock and GRN accrual:.*$/, `Estimated stock and GRN accrual: ${info.dataset.home} ${Number(value || 0).toFixed(2)}.`); }
  }));
  load();
})();
</script>@endpush
@endif
@endsection
