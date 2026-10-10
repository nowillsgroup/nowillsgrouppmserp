@extends('layouts.print')
@section('title', 'Activity voucher '.$b->number)
@section('doctitle', 'ACTIVITY VOUCHER')
@section('docmeta')<div class="small"><b>{{ $b->number }}</b></div><div class="small">{{ $b->vendor_ref ? 'Operator ref '.$b->vendor_ref : '' }}</div>@endsection
@section('content')
@php $a = $b->activity; @endphp
<style>.vch{display:grid;grid-template-columns:1fr 150px;gap:1.2rem;align-items:start;margin-top:.8rem}.vch h1{font-size:1.45rem;margin:0 0 .3rem}.vch .qr svg{width:150px;height:150px}
.vkv{display:grid;grid-template-columns:130px 1fr;gap:.35rem .8rem;font-size:.92rem;margin-top:.7rem}.vkv dt{color:#64748b}.vkv dd{margin:0;font-weight:600}
.vbox{border:1px dashed #94a3b8;border-radius:10px;padding:.6rem .8rem;margin-top:1rem;font-size:.85rem}@media(max-width:560px){.vch{grid-template-columns:1fr}}</style>
<div class="vch" id="voucher" data-number="{{ $b->number }}">
  <div>
    <h1>{{ $a->name }}</h1>
    <div class="small muted">{{ \App\Models\Activity::CATEGORIES[$a->category] ?? '' }} · {{ $a->durationLabel() }} · operated by {{ $b->vendor->name }}</div>
    <dl class="vkv">
      <dt>Date</dt><dd>{{ $b->date->format('l d F Y') }}</dd>
      <dt>Start</dt><dd>{{ $b->time }}</dd>
      <dt>Guests</dt><dd>{{ $b->paxLabel() }}</dd>
      <dt>Lead guest</dt><dd>{{ $b->guest_name }}</dd>
      <dt>{{ $b->pickup_point || $a->pickup_included ? 'Pick-up' : 'Meeting point' }}</dt><dd>{{ $b->pickup_point ? $b->pickup_point.($b->pickup_time ? ' at '.$b->pickup_time : '') : ($a->pickup_included ? 'Hotel lobby – the operator will confirm the time' : ($a->meeting_point ?: 'Hotel lobby')) }}</dd>
      <dt>Payment</dt><dd>{{ $b->collection === 'folio' ? 'Paid – charged to the hotel bill' : 'Pay the operator on the day: '.money($b->gross) }}</dd>
      @if(! $public && $b->room_no)<dt>Room</dt><dd>{{ $b->room_no }}</dd>@endif
      @if($b->notes)<dt>Notes</dt><dd>{{ $b->notes }}</dd>@endif
    </dl>
  </div>
  <div class="qr center">{!! $qr !!}<div class="tiny muted">{{ $b->number }}</div></div>
</div>
@if($a->bring)<div class="vbox"><b>Please bring:</b> {{ $a->bring }}</div>@endif
<div class="vbox"><b>Status:</b> {{ $b->statusLabel() }}. Please be ready 10 minutes before the start. Free cancellation until {{ $b->freeCancelUntil()->format('D d M, H:i') }}{{ (float) $a->cancel_fee_pct > 0 ? '; later cancellations or no-shows are charged '.rtrim(rtrim(number_format((float) $a->cancel_fee_pct, 1), '0'), '.').'% (no-show 100%).' : '.' }}
  <br>Operator: {{ $b->vendor->name }}{{ $b->vendor->phone ? ' · '.$b->vendor->phone : '' }}{{ $b->vendor->licence_no ? ' · licence '.$b->vendor->licence_no : '' }}
  <br>Concierge: {{ $hotel->name }}{{ $cfg['desk_phone'] ? ' · '.$cfg['desk_phone'] : ($hotel->phone ? ' · '.$hotel->phone : '') }}</div>
<p class="tiny muted mt-1">{{ $hotel->name }} books this activity as agent for the operator named above, who is responsible for running it.</p>
@endsection
