@extends('layouts.print')
@section('title', ucfirst($tx->type).' receipt')
@section('doctitle', $tx->is_void ? strtoupper($tx->type).' RECEIPT — VOIDED' : strtoupper($tx->type).' RECEIPT')
@section('docmeta')
<div class="small">Folio: <b>{{ $folio->folio_no }}</b><br>Transaction: {{ $tx->id }}<br>Date: {{ $tx->business_date->format('d M Y') }}</div>
@endsection
@section('content')
<p class="small">This is a receipt for a payment already on the folio. It is not a tax invoice.</p>
<dl class="kv">
  <dt>Guest</dt><dd>{{ $folio->guest?->full_name ?? 'No guest on file' }}</dd>
  <dt>Room</dt><dd>{{ $folio->stay?->room?->room_no ?? 'No room on file' }}</dd>
  <dt>Reservation</dt><dd>{{ $folio->reservation?->reservation_no ?? 'None' }}</dd>
  <dt>Type</dt><dd>{{ ucfirst($tx->type) }}</dd>
  <dt>Amount</dt><dd>{{ money($tx->amount) }}</dd>
  <dt>Mode</dt><dd>{{ $tx->paymentMode?->name ?? 'Not recorded' }}</dd>
  <dt>Reference</dt><dd>{{ $tx->reference ?: 'None on file' }}</dd>
  @if($tx->cheque_number)<dt>Cheque</dt><dd>{{ $tx->cheque_number }} · {{ $tx->cheque_bank_name ?: 'Bank not recorded' }}{{ $tx->cheque_bank_branch ? ' · '.$tx->cheque_bank_branch : '' }}</dd>@endif
  @if($tx->is_void)<dt>Void</dt><dd>{{ $tx->void_reason ?: 'Voided' }}</dd>@endif
  @if($tx->poster)<dt>Posted by</dt><dd>{{ $tx->poster->name }}</dd>@endif
</dl>
<p class="small"><a href="{{ route('folios.invoice', $folio) }}">Open the folio invoice</a></p>
@endsection
