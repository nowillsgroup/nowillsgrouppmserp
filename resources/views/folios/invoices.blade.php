@extends('layouts.app')
@section('title', 'Invoices')
@section('content')
<div class="card"><div class="card-body"><form class="filters">
  <div class="field"><label for="invoiceFrom">From</label><input id="invoiceFrom" type="date" name="from" value="{{ $from }}"></div><div class="field"><label for="invoiceTo">To</label><input id="invoiceTo" type="date" name="to" value="{{ $to }}"></div>
  <div class="field"><label for="invoiceType">Type</label><select id="invoiceType" name="type"><option value="">All documents</option><option value="invoice" @selected(request('type') === 'invoice')>Tax invoices</option><option value="credit_note" @selected(request('type') === 'credit_note')>Credit notes</option></select></div>
  <div class="field"><label for="invoiceSupply">Supply</label><select id="invoiceSupply" name="supply"><option value="">B2B + B2C</option><option value="B2B" @selected(request('supply') === 'B2B')>B2B</option><option value="B2C" @selected(request('supply') === 'B2C')>B2C</option></select></div>
  <div class="field" style="flex:1"><label for="invoiceSearch">Search</label><input id="invoiceSearch" name="q" value="{{ request('q') }}" placeholder="Invoice no / guest / TIN"></div><button class="btn btn-primary">Filter</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<caption class="sr-only">Invoices and credit notes from {{ $from }} to {{ $to }}</caption>
<thead><tr><th scope="col">Invoice</th><th scope="col">Type</th><th scope="col">Date</th><th scope="col">Guest / Company</th><th scope="col">Room</th><th scope="col" class="num">Taxable</th><th scope="col" class="num">Tax</th><th scope="col" class="num">Total</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
@php $gt = 0; $gtax = 0; @endphp
@forelse($folios as $f) @php $t = $f->totals(); $documentDate = $f->invoice_date ?? $f->closed_at; $rowReservation = $f->reservation ?? $f->stay?->reservation; $rowGuest = $f->guest ?? $f->stay?->guest ?? $rowReservation?->guest; $rowCompany = $f->company ?? $rowReservation?->company; $gt += $t['charges'] - $t['discount']; $gtax += $t['tax']; @endphp
<tr class="{{ $f->irn_status === 'failed' ? 'row-alert' : '' }}"><td class="bold">{{ $f->invoice_no }}@if($f->status === 'open')<div class="tiny muted">re-opened</div>@endif</td>
<td class="small nowrap">{!! $f->isCreditNote() ? '<span class="badge badge-purple">Credit note</span><div class="tiny muted">'.e($f->original?->invoice_no).'</div>' : 'Invoice' !!} @if($f->supply_type)<span class="badge {{ $f->supply_type === 'B2B' ? 'badge-blue' : 'badge-slate' }}">{{ $f->supply_type }}</span>@endif {!! $f->status === 'cancelled' ? '<span class="badge badge-slate">cancelled</span>' : $f->irnBadge() !!}</td>
<td>@if($documentDate)<time datetime="{{ $documentDate->toDateString() }}">{{ $documentDate->format('d M Y') }}</time>@else—@endif</td><td>{{ $f->buyer_name ?: ($rowCompany?->name ?? $rowGuest?->full_name ?? '—') }}<div class="tiny muted">{{ $f->buyer_gstin ?: $rowCompany?->name }}</div></td><td>{{ $f->stay?->room?->room_no ?? 'NR' }}</td>
<td class="num">{{ money($t['charges'] - $t['tax'] - $t['discount'], false) }}</td><td class="num">{{ money($t['tax'], false) }}</td><td class="num bold">{{ money($t['charges'] - $t['discount'], false) }}</td>
<td class="nowrap"><a class="btn btn-sm" href="{{ route('folios.invoice', $f) }}" target="_blank" rel="noopener" aria-label="Open invoice {{ $f->invoice_no }} for printing" title="Open invoice"><x-icon name="print" size="sm"/></a> <a class="btn btn-sm btn-ghost" href="{{ route('folios.show', $f) }}" aria-label="Open folio for invoice {{ $f->invoice_no }}" title="View folio"><x-icon name="eye" size="sm"/></a></td></tr>
@empty<tr><td colspan="9"><x-empty text="No invoices in this period." icon="receipt"/></td></tr>@endforelse
</tbody><tfoot><tr><td colspan="6" class="right">Page total</td><td class="num">{{ money($gtax, false) }}</td><td class="num">{{ money($gt, false) }}</td><td></td></tr></tfoot></table>{{ $folios->links() }}</div></div>
@endsection
