@extends('layouts.print')
@php $printStatus = $v->status === 'active' && $v->isExpired() ? 'Expired' : $v->statusLabel(); @endphp
@section('title', 'Gift voucher '.$v->code)
@section('doctitle', 'GIFT VOUCHER')
@section('docmeta')<div class="small">{{ $v->code }}</div>@endsection
@section('content')
@php $c = $v->type->color; @endphp
<div style="border:2px solid {{ $c }};border-radius:14px;overflow:hidden;margin:1rem 0" id="printCard">
  <div style="background:{{ $c }};color:#fff;padding:1.2rem 1.4rem;display:flex;justify-content:space-between;align-items:center">
    <div><div style="font-size:1.3rem;font-weight:700">{{ $v->type->name }}</div>@if($v->recipient_name)<div style="opacity:.9">for {{ $v->recipient_name }}</div>@endif<div style="font-size:.8rem;opacity:.9;margin-top:.3rem">Status: {{ $printStatus }}</div></div>
    <div style="text-align:right"><div style="font-size:.75rem;opacity:.9;text-transform:uppercase;letter-spacing:.06em">Balance</div><div style="font-size:2rem;font-weight:800">{{ money($v->balance) }}</div></div>
  </div>
  @unless($v->usable())<div style="background:#fef2f2;color:#991b1b;padding:.6rem 1rem;text-align:center;font-weight:700">NOT VALID FOR REDEMPTION — {{ $printStatus }}</div>@endunless
  <div style="display:flex;gap:1.4rem;padding:1.2rem 1.4rem;align-items:center">
    <div style="width:130px;flex:none">{!! \App\Services\EInvoiceService::qrSvg($v->publicUrl()) !!}</div>
    <div style="flex:1">
      <div class="small text-2">Voucher code</div>
      <div style="font-family:monospace;font-size:1.7rem;font-weight:700;letter-spacing:4px">{{ $v->code }}</div>
      <div style="margin-top:.4rem">Valid until <b>{{ $v->expires_on->format('d F Y') }}</b> · {{ $v->type->usesLabel() }}{{ $v->type->kind === 'single' ? ' · single use' : '' }}</div>
      @if($v->message)<div style="margin-top:.5rem;font-style:italic">“{{ $v->message }}”</div>@endif
      @if($v->purchaser_name && $v->source === 'sold')<div class="small text-2" style="margin-top:.3rem">With best wishes from {{ $v->purchaser_name }}</div>@endif
    </div>
  </div>
  <div style="border-top:1px dashed #cbd5e1;padding:.8rem 1.4rem" class="small text-2">{{ $v->type->terms }} {{ $cfg['terms'] }}<br>Check the balance: {{ $v->publicUrl() }}</div>
</div>
@endsection
