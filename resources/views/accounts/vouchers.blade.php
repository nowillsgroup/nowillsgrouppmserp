@extends('layouts.app')
@section('title', 'Vouchers')
@section('actions')@if(can_do('accounts.manage'))<button class="btn btn-danger" data-modal="vModal" data-ftype="payment"><x-icon name="minus"/> Payment (Expense)</button><button class="btn btn-success" data-modal="vModal" data-ftype="receipt"><x-icon name="plus"/> Receipt (Income)</button>@endif @endsection
@section('content')
@php $pay = $vouchers->where('type','payment')->sum('amount'); $rec = $vouchers->where('type','receipt')->sum('amount'); @endphp
<div class="stats"><x-stat label="Receipts" :value="money($rec)" icon="money" tone="green"/><x-stat label="Payments" :value="money($pay)" icon="wallet" tone="red"/><x-stat label="Net" :value="money($rec - $pay)" icon="chart" tone="blue"/><x-stat label="Vouchers" :value="$vouchers->count()" icon="receipt" tone="slate"/></div>
<div class="card"><div class="card-body"><form class="filters">
<div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
<div class="field"><label>Type</label><select name="type"><option value="">All</option>@foreach(['payment','receipt'] as $t)<option @selected(request('type')==$t)>{{ $t }}</option>@endforeach</select></div>
<div class="field"><label>Head</label><select name="head"><option value="">All</option>@foreach($heads as $h)<option value="{{ $h->id }}" @selected(request('head')==$h->id)>{{ $h->label }}</option>@endforeach</select></div>
<button class="btn btn-primary">Filter</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Voucher</th><th>Date</th><th>Type</th><th>Account head</th><th>Party</th><th>Mode</th><th>Ref</th><th>Narration</th><th class="num">Amount</th><th></th></tr></thead><tbody>
@forelse($vouchers as $v)<tr><td class="bold">{{ $v->voucher_no }}</td><td>{{ $v->date->format('d M Y') }}</td><td><span class="badge {{ $v->type==='receipt' ? 'badge-green' : ($v->type==='payment' ? 'badge-red' : 'badge-slate') }}">{{ $v->type }}</span></td>
<td>{{ $v->head->name }}</td><td>{{ $v->supplier?->name ?? $v->party_name }}</td><td>{{ $v->paymentMode?->name }}</td><td>{{ $v->reference }}</td><td class="small">{{ $v->narration }}</td><td class="num bold">{{ money($v->amount) }}</td>
<td>@if(can_do('accounts.manage'))<form method="post" action="{{ route('accounts.vouchers.delete', $v) }}" data-confirm="Delete voucher {{ $v->voucher_no }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost"><x-icon name="trash" size="sm"/></button></form>@endif</td></tr>
@empty<tr><td colspan="10"><x-empty text="No vouchers in this period." icon="receipt"/></td></tr>@endforelse</tbody></table></div></div>
<div class="modal" id="vModal"><div class="modal-box"><form method="post" action="{{ route('accounts.vouchers.store') }}">@csrf
<div class="modal-head"><h3>New Voucher</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
<x-field name="type" label="Type" type="select" :options="['payment'=>'Payment (money out)','receipt'=>'Receipt (money in)']" :placeholder="false"/>
<x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" required/>
<div class="field"><label>Account head <span class="req">*</span></label><select name="account_head_id" required><option value="">— Select —</option>@foreach($heads->groupBy('type') as $type=>$hs)<optgroup label="{{ ucfirst($type) }}">@foreach($hs as $h)@if($h->is_active)<option value="{{ $h->id }}">{{ $h->label }}</option>@endif @endforeach</optgroup>@endforeach</select></div>
<x-field name="amount" label="Amount" type="number" step="0.01" min="0.01" required/>
<x-field name="payment_mode_id" label="Payment mode" type="select" :options="$paymentModes"/>
<x-field name="supplier_id" label="Supplier" type="select" :options="$suppliers"/>
<x-field name="party_name" label="Party name (if not supplier)"/><x-field name="reference" label="Bill / Ref no."/>
<x-field name="narration" label="Narration" class="span-all"/>
<p class="small muted span-all">Payment: Dr the account head, Cr the payment mode's cash/bank account. Receipt: the reverse. Posted to the general ledger once the day is closed. @if(can_do('gl.journal'))For entries without cash/bank movement use a <a href="{{ route('gl.journals.create') }}">manual journal</a>.@endif</p>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save Voucher</button></div></form></div></div>
@endsection
