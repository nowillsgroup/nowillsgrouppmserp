@extends('partner.layout')
@php
  $openingTxs = $f->transactions->filter(fn ($t) => $t->isOpening());
  $openingOnly = $openingTxs->isNotEmpty() && ! $f->transactions->contains(fn ($t) => $t->type === 'charge');
  $openingBalance = (float) $openingTxs->sum('amount');
@endphp
@section('title', $openingOnly ? 'Opening receivable '.$f->folio_no : 'Invoice '.($f->invoice_no ?? $f->folio_no))
@section('crumbs') <a href="{{ $partner->can('finance') ? route('partner.statement') : route('partner.bookings') }}">{{ $partner->can('finance') ? 'Statement' : 'Bookings' }}</a> / {{ $openingOnly ? 'Opening receivable' : 'Invoice' }} @endsection
@section('actions') <button class="btn" type="button" onclick="window.print()"><x-icon name="print"/> Print / PDF {{ $openingOnly ? 'statement' : 'invoice' }}</button> @endsection
@section('content')
<div class="card ptn-invoice" id="ptnInvoice"><div class="card-body">
  <div class="flex justify-between" style="gap:20px;flex-wrap:wrap">
    <div><h2 class="m-0">{{ $hotel->legal_name ?: $hotel->name }}</h2><div class="small muted">{{ $hotel->address }}{{ $hotel->city ? ', '.$hotel->city : '' }}<br>{{ $hotel->tax_number ? 'TIN '.$hotel->tax_number : '' }}</div></div>
    <div class="right"><b>{{ $openingOnly ? 'Opening receivable statement · not a tax invoice' : ($f->invoice_no ? 'Tax invoice '.$f->invoice_no : 'Folio '.$f->folio_no) }}</b><div class="small">{{ $openingOnly ? 'As at '.$openingTxs->first()->opening_date?->format('d M Y') : ($f->invoice_date?->format('d M Y') ?? $f->created_at->format('d M Y')) }}</div><div class="small">Bill to: <b>{{ $f->buyer_name ?: $company->name }}</b></div><div class="small">Guest: {{ $f->guest?->full_name }}</div></div>
  </div>
  @if($openingTxs->isNotEmpty())<div class="alert alert-info mt-2"><b>Opening receivable as at {{ $openingTxs->first()->opening_date?->format('d M Y') }} — separate from current charges.</b> This non-posting balance is not a newly issued tax invoice or taxable charge. Historical revenue, tax and settlements were not replayed; later account receipts appear on the statement.</div>@endif
  <table class="table table-compact mt-2"><thead><tr><th>Date</th><th>Description</th><th class="right">Amount</th><th class="right">Tax</th><th class="right">Total</th></tr></thead><tbody>
  @php $sumA = 0; $sumT = 0; @endphp
  @foreach($f->transactions->whereIn('type', ['charge', 'discount', 'allowance']) as $t)
    @php $sign = $t->type === 'charge' ? 1 : -1; $sumA += $sign * (float) $t->amount; $sumT += $sign * (float) $t->tax_amount; @endphp
    <tr><td class="nowrap">{{ $t->business_date->format('d M') }}</td><td>{{ $t->description }}</td><td class="right">{{ money($sign * $t->amount) }}</td><td class="right">{{ money($sign * $t->tax_amount) }}</td><td class="right">{{ money($sign * ($t->amount + $t->tax_amount)) }}</td></tr>
  @endforeach
  @foreach($openingTxs as $t)
    <tr><td class="nowrap">{{ $t->business_date->format('d M') }}</td><td>Opening balance · source {{ $t->reference }} · due {{ $t->due_date?->format('d M Y') ?? '—' }} <span class="badge badge-amber">not a new charge</span></td><td class="right">{{ money($t->amount) }}</td><td class="right">—</td><td class="right">{{ money($t->amount) }}</td></tr>
  @endforeach
  </tbody><tfoot><tr><th colspan="2" class="right">Current charges (opening balance excluded)</th><th class="right">{{ money($sumA) }}</th><th class="right">{{ money($sumT) }}</th><th class="right">{{ money($sumA + $sumT) }}</th></tr>
  @if($openingTxs->isNotEmpty())<tr><td colspan="4" class="right small">Opening receivable at cutover (historical source amount; not a tax invoice)</td><td class="right small">{{ money($openingBalance) }}</td></tr>@endif
  @foreach($f->transactions->whereIn('type', ['payment', 'refund']) as $t)<tr><td colspan="4" class="right small">{{ $t->category === 'city_ledger' ? 'Transferred to your account' : ($t->type === 'refund' ? 'Refund' : 'Paid') }} · {{ $t->business_date->format('d M') }}</td><td class="right small">{{ money(($t->type === 'refund' ? 1 : -1) * $t->amount) }}</td></tr>@endforeach
  </tfoot></table>
  @if($hotel->invoice_footer)<p class="tiny muted">{{ $hotel->invoice_footer }}</p>@endif
</div></div>
@endsection
