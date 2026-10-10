@extends('layouts.app')
@section('title', 'Invoices')
@section('content')
<div class="card"><div class="card-body"><form class="filters">
  <div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
  <div class="field"><label>Type</label><select name="type"><option value="">All documents</option><option value="invoice" @selected(request('type') === 'invoice')>Tax invoices</option><option value="credit_note" @selected(request('type') === 'credit_note')>Credit notes</option></select></div>
  <div class="field"><label>Supply</label><select name="supply"><option value="">B2B + B2C</option><option value="B2B" @selected(request('supply') === 'B2B')>B2B</option><option value="B2C" @selected(request('supply') === 'B2C')>B2C</option></select></div>
  <div class="field" style="flex:1"><label>Search</label><input name="q" value="{{ request('q') }}" placeholder="Invoice no / guest / TIN"></div><button class="btn btn-primary">Filter</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Invoice</th><th>Type</th><th>Date</th><th>Guest / Company</th><th>Room</th><th class="num">Taxable</th><th class="num">Tax</th><th class="num">Total</th><th></th></tr></thead><tbody>
@php $gt = 0; $gtax = 0; @endphp
@forelse($folios as $f) @php $t = $f->totals(); $gt += $t['charges'] - $t['discount']; $gtax += $t['tax']; @endphp
<tr class="{{ $f->irn_status === 'failed' ? 'row-alert' : '' }}"><td class="bold">{{ $f->invoice_no }}@if($f->status === 'open')<div class="tiny muted">re-opened</div>@endif</td>
<td class="small nowrap">{!! $f->isCreditNote() ? '<span class="badge badge-purple">Credit note</span><div class="tiny muted">'.e($f->original?->invoice_no).'</div>' : 'Invoice' !!} @if($f->supply_type)<span class="badge {{ $f->supply_type === 'B2B' ? 'badge-blue' : 'badge-slate' }}">{{ $f->supply_type }}</span>@endif {!! $f->status === 'cancelled' ? '<span class="badge badge-slate">cancelled</span>' : $f->irnBadge() !!}</td>
<td>{{ ($f->invoice_date ?? $f->closed_at)?->format('d M Y') }}</td><td>{{ $f->buyer_name && $f->buyer_gstin ? $f->buyer_name : $f->guest?->full_name }}<div class="tiny muted">{{ $f->buyer_gstin ?: $f->company?->name }}</div></td><td>{{ $f->stay?->room?->room_no ?? 'NR' }}</td>
<td class="num">{{ money($t['charges'] - $t['tax'] - $t['discount'], false) }}</td><td class="num">{{ money($t['tax'], false) }}</td><td class="num bold">{{ money($t['charges'] - $t['discount'], false) }}</td>
<td class="nowrap"><a class="btn btn-sm" href="{{ route('folios.invoice', $f) }}" target="_blank"><x-icon name="print" size="sm"/></a> <a class="btn btn-sm btn-ghost" href="{{ route('folios.show', $f) }}"><x-icon name="eye" size="sm"/></a></td></tr>
@empty<tr><td colspan="9"><x-empty text="No invoices in this period." icon="receipt"/></td></tr>@endforelse
</tbody><tfoot><tr><td colspan="6" class="right">Page total</td><td class="num">{{ money($gtax, false) }}</td><td class="num">{{ money($gt, false) }}</td><td></td></tr></tfoot></table>{{ $folios->links() }}</div></div>
@endsection
