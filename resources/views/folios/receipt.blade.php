@extends('layouts.print')
@php
  $receiptReservation = $folio->reservation ?? $folio->stay?->reservation;
  $receiptGuest = $folio->guest ?? $folio->stay?->guest ?? $receiptReservation?->guest;
  $receiptCompany = $folio->company ?? $receiptReservation?->company;
@endphp
@section('title', ucfirst($tx->type).' receipt')
@section('backurl', route('folios.show', $folio))
@section('pageclass', 'folio-payment-receipt')
@section('doctitle', $tx->is_void ? strtoupper($tx->type).' RECEIPT — VOIDED' : strtoupper($tx->type).' RECEIPT')
@php
  $receiptAmountLabel = $tx->type === 'refund' ? 'Refund amount' : 'Payment amount';
  $receiptState = $tx->is_void ? 'voided' : ($tx->type === 'refund' ? 'refund' : 'payment');
  $receiptStateLabel = $tx->is_void ? 'VOIDED' : ($tx->type === 'refund' ? 'REFUND RECORDED' : 'PAYMENT RECORDED');
@endphp
@push('head')
<style>
  .print-page.folio-payment-receipt { max-width:760px; padding:2.15rem 2.35rem; }
  .folio-payment-receipt .print-head { align-items:flex-start; border-bottom:3px solid #166534; padding-bottom:1rem; margin-bottom:1.15rem; }
  .folio-payment-receipt .print-head h2 { color:#14532d; letter-spacing:-.025em; }
  .folio-payment-receipt .receipt-meta { margin-top:.45rem; color:#526358; line-height:1.7; }
  .folio-payment-receipt .receipt-meta b { color:#203728; font-variant-numeric:tabular-nums; }
  .folio-payment-receipt .receipt-highlight { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin:1rem 0 1.2rem; padding:1rem 1.15rem; border:1px solid #d7e3d9; border-left:5px solid #166534; border-radius:10px; background:#f1f8f2; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .folio-payment-receipt .receipt-highlight--refund { border-left-color:#1d4ed8; border-color:#bfdbfe; background:#eff6ff; }
  .folio-payment-receipt .receipt-highlight--voided { border-left-color:#b91c1c; border-color:#fecaca; background:#fef2f2; }
  .folio-payment-receipt .receipt-highlight-label { color:#64748b; font-size:.72rem; font-weight:750; letter-spacing:.07em; text-transform:uppercase; }
  .folio-payment-receipt .receipt-highlight-amount { display:block; margin-top:.15rem; color:#14532d; font-size:1.7rem; font-weight:850; font-variant-numeric:tabular-nums; line-height:1.15; }
  .folio-payment-receipt .receipt-highlight--refund .receipt-highlight-amount { color:#1d4ed8; }
  .folio-payment-receipt .receipt-highlight--voided .receipt-highlight-amount { color:#991b1b; text-decoration:line-through; }
  .folio-payment-receipt .receipt-details { grid-template-columns:minmax(100px,120px) minmax(0,1fr); gap:.55rem .8rem; border:1px solid #e2e8e3; border-radius:8px; padding:.8rem 1rem; background:#fcfdfc; }
  .folio-payment-receipt .receipt-details dt { color:#64748b; }
  .folio-payment-receipt .receipt-details dd { min-width:0; overflow-wrap:anywhere; color:#203728; }
  @media (max-width:620px) {
    .folio-payment-receipt .receipt-highlight { align-items:flex-start; flex-direction:column; }
    .folio-payment-receipt .receipt-details { grid-template-columns:minmax(84px,100px) minmax(0,1fr); gap:.45rem .65rem; padding:.75rem; }
  }
  @media print { .folio-payment-receipt .receipt-highlight, .folio-payment-receipt .receipt-details { break-inside:avoid; page-break-inside:avoid; } .folio-payment-receipt .receipt-highlight { -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style>
@endpush
@section('docmeta')
<div class="small receipt-meta">Folio: <b>{{ $folio->folio_no }}</b><br>Transaction: <b>#{{ $tx->id }}</b><br>Business date: <time datetime="{{ $tx->business_date->toDateString() }}">{{ $tx->business_date->format('d M Y') }}</time>@if($tx->created_at)<br>Recorded: <time datetime="{{ $tx->created_at->toIso8601String() }}">{{ $tx->created_at->format('d M Y, H:i') }}</time>@endif<br>Invoice: {{ $folio->invoice_no ?: 'Not yet issued' }}</div>
@endsection
@section('content')
@if($tx->is_void)
<p class="small text-red"><b>This {{ $tx->type }} transaction is voided.</b> This document is retained for audit and is not valid proof of payment or refund.</p>
@elseif($tx->type === 'refund')
<p class="small">This confirms a refund recorded on the folio. It is not a tax invoice.</p>
@else
<p class="small">This confirms a payment recorded on the folio. It is not a tax invoice.</p>
@endif
<div class="receipt-highlight receipt-highlight--{{ $receiptState }}" aria-label="{{ $receiptAmountLabel }} {{ money($tx->amount) }}; {{ strtolower($receiptStateLabel) }}">
  <div><span class="receipt-highlight-label">{{ $receiptAmountLabel }}</span><strong class="receipt-highlight-amount">{{ money($tx->amount) }}</strong></div>
  <span class="badge badge-{{ $tx->is_void ? 'red' : ($tx->type === 'refund' ? 'blue' : 'green') }}">{{ $receiptStateLabel }}</span>
</div>
<dl class="kv receipt-details">
  <dt>Guest</dt><dd>{{ $receiptGuest?->full_name ?? 'No guest on file' }}</dd>
  @if($receiptCompany)<dt>Company account</dt><dd>{{ $receiptCompany->name }}</dd>@endif
  <dt>Room</dt><dd>{{ $folio->stay?->room?->room_no ?? 'No room on file' }}</dd>
  <dt>Reservation</dt><dd>{{ $receiptReservation?->reservation_no ?? 'None' }}</dd>
  <dt>Invoice</dt><dd>{{ $folio->invoice_no ?: 'Not yet issued' }}</dd>
  <dt>Type</dt><dd>{{ ucfirst($tx->type) }}</dd>
  <dt>Mode</dt><dd>{{ $tx->paymentMode?->name ?? 'Not recorded' }}</dd>
  <dt>Reference</dt><dd>{{ $tx->reference ?: 'None on file' }}</dd>
  @if($tx->cheque_number)<dt>Cheque</dt><dd>{{ $tx->cheque_number }} · {{ $tx->cheque_bank_name ?: 'Bank not recorded' }}{{ $tx->cheque_bank_branch ? ' · '.$tx->cheque_bank_branch : '' }}</dd>@endif
  @if($tx->is_void)<dt>Void</dt><dd>{{ $tx->void_reason ?: 'Voided' }}</dd>@endif
  @if($tx->poster)<dt>Posted by</dt><dd>{{ $tx->poster->name }}</dd>@endif
</dl>
<p class="small"><a href="{{ route('folios.invoice', $folio) }}">Open the folio invoice</a></p>
@endsection
