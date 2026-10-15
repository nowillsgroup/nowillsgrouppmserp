@extends('layouts.app')
@section('title', 'Supplier Bills')
@section('crumbs') Accounts / Payables @endsection
@section('actions')
<a class="btn" href="{{ route('ap.ageing') }}"><x-icon name="chart"/> Ageing</a>
@if(can_do('ap.pay'))<a class="btn" href="{{ route('ap.payments.create') }}"><x-icon name="money"/> Pay supplier</a>@endif
@if(can_do('ap.manage'))
<a class="btn" href="{{ route('ap.bills.create', ['type' => 'debit_note']) }}"><x-icon name="minus"/> Debit note</a>
<a class="btn btn-primary" href="{{ route('ap.bills.create') }}"><x-icon name="plus"/> Enter bill</a>
@endif
@endsection
@section('content')
@php $unb = $kpi['unbilled']; @endphp
<div class="stats">
  <x-stat label="Outstanding on bills" :value="money($kpi['outstanding'])" icon="receipt" tone="blue" href="?status=unpaid"/>
  <x-stat label="Overdue" :value="money($kpi['overdue'])" icon="alert" :tone="$kpi['overdue'] > 0 ? 'red' : 'green'" href="?overdue=1"/>
  <x-stat label="Due in next 7 days" :value="money($kpi['due7'])" icon="clock" tone="amber" href="?status=unpaid"/>
  <x-stat label="Held for variance" :value="$kpi['hold']" icon="lock" :tone="$kpi['hold'] ? 'red' : 'green'" href="?status=on_hold" sub="need ap.approve"/>
</div>

@if($unb->count())
<div class="card" id="unbilled"><div class="card-head"><h3>Goods received, not yet billed</h3><span class="small muted">{{ $unb->count() }} receipt(s) · {{ money($unb->sum('receiptValue')) }} – owed at receipt value until the supplier invoice is matched</span></div>
<div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>PO</th><th>Supplier</th><th>Received</th><th>Supplier inv.</th><th class="num">GRN value</th><th></th></tr></thead><tbody>
@foreach($unb as $po)
<tr><td><a href="{{ route('purchases.show', $po) }}" class="bold">{{ $po->po_no }}</a></td><td>{{ $po->supplier->name }}</td><td>{{ dmy($po->grnDate()) }}</td><td>{{ $po->invoice_no ?? '—' }}@if($po->bill?->status === 'on_hold') <span class="badge badge-red">bill on hold</span>@endif</td><td class="num">{{ money($po->receiptValue ?? $po->total) }}</td>
<td class="right">@if($po->status === 'partial')<span class="badge badge-amber" title="Receive the balance or short-close the PO before matching the bill">part-received</span>@elseif(can_do('ap.manage') && ! $po->bill)<a class="btn btn-sm btn-primary" href="{{ route('ap.bills.create', ['po' => $po->id]) }}">Match bill</a>@elseif($po->bill)<a class="btn btn-sm" href="{{ route('ap.bills.show', $po->bill) }}">{{ $po->bill->doc_no }}</a>@endif</td></tr>
@endforeach
</tbody></table></div></div>
@endif

<div class="tabs">@foreach(['' => 'All', 'unpaid' => 'Unpaid', 'on_hold' => 'On hold', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $k => $v)<a href="?{{ http_build_query(array_merge(request()->except('page', 'status', 'overdue'), ['status' => $k])) }}" class="{{ (string) request('status') === $k && ! request('overdue') ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<form class="filters mb-1 flex gap-1 wrap items-center" method="get"><input type="hidden" name="status" value="{{ request('status') }}">
  <select name="supplier" class="input input-sm" style="width:auto;min-width:200px" onchange="this.form.submit()"><option value="">All suppliers</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach</select>
  <select name="type" class="input input-sm" style="width:auto" onchange="this.form.submit()"><option value="">Bills & debit notes</option><option value="invoice" @selected(request('type') === 'invoice')>Bills only</option><option value="debit_note" @selected(request('type') === 'debit_note')>Debit notes only</option></select>
  <input name="q" value="{{ request('q') }}" placeholder="Doc / supplier invoice no." class="input input-sm" style="width:200px">
  <label class="check small"><input type="checkbox" name="overdue" value="1" @checked(request('overdue')) onchange="this.form.submit()"> <span>Overdue only</span></label>
  <button class="btn btn-sm">Filter</button>
</form>

<div class="card flush"><div class="card-body table-wrap"><table class="table" id="billTable">
<thead><tr><th>Document</th><th>Supplier</th><th>Supplier inv.</th><th>Date</th><th>Due</th><th class="num">Total</th><th class="num">Paid / applied</th><th class="num">Outstanding</th><th>Status</th></tr></thead><tbody>
@forelse($bills as $b)
@php $od = ! $b->isDebitNote() && in_array($b->status, ['open', 'partial']) ? $b->daysOverdue() : 0; @endphp
<tr>
  <td><a class="bold" href="{{ route('ap.bills.show', $b) }}">{{ $b->doc_no }}</a>
    <div class="tiny muted">@if($b->isDebitNote())Debit note @elseif($b->kind === 'goods')Goods · <a href="{{ route('purchases.show', $b->purchase_order_id) }}">{{ $b->purchaseOrder?->po_no }}</a>@else Expense @endif</div></td>
  <td>{{ $b->supplier->name }}</td>
  <td>{{ $b->supplier_ref ?? '—' }}</td>
  <td class="nowrap">{{ $b->doc_date->format('d M Y') }}</td>
  <td class="nowrap {{ $od ? 'text-red bold' : '' }}">{{ $b->isDebitNote() ? '—' : $b->due_date?->format('d M Y') }}@if($od)<div class="tiny">{{ $od }} days overdue</div>@endif</td>
  <td class="num">{{ $b->isDebitNote() ? '−' : '' }}{{ money($b->total, false) }}@if($b->isForeign())<div class="tiny muted">{{ $b->fxLabel() }} at {{ number_format((float) $b->fx_rate, 4) }}</div>@endif @if($b->variance())<div class="tiny {{ $b->status === 'on_hold' ? 'text-red' : 'muted' }}">var {{ money($b->variance(), false) }}</div>@endif</td>
  <td class="num">{{ money($b->allocated, false) }}</td>
  <td class="num bold">{{ money($b->outstanding(), false) }}</td>
  <td>{!! status_badge($b->status) !!}</td>
</tr>
@empty
<tr><td colspan="9" class="empty">No supplier bills match.</td></tr>
@endforelse
</tbody></table></div></div>
{{ $bills->links('partials.pagination') }}
@endsection
