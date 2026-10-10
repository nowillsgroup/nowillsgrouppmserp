@extends('public.layout')
@section('title', 'Visitor pass')
@section('content')
@php $live = $v->status === 'expected'; @endphp
<div class="card" style="max-width:420px;margin:0 auto;text-align:center" id="visitorPass">
  <div style="background:#0f172a;color:#fff;padding:1.1rem;border-radius:12px 12px 0 0">
    <div style="font-size:.75rem;letter-spacing:.14em;text-transform:uppercase;opacity:.8">{{ $hotel->name }}</div>
    <div style="font-size:1.6rem;font-weight:900;letter-spacing:.2em;margin-top:.2rem">VISITOR PASS</div>
  </div>
  <div class="card-body">
    @if($live)
      <div style="width:190px;margin:0 auto" id="passQr">{!! $qr !!}</div>
      <div class="mono" style="font-size:1.4rem;font-weight:800;letter-spacing:.1em" id="passNo">{{ $v->pass_no }}</div>
      <h3 style="margin:.5rem 0 .1rem">{{ $v->name }}</h3>
      <div class="muted">Guest of {{ $hostFirst ?: 'our guest' }}</div>
      <div class="mt-1"><b>{{ $v->expected_at?->format('D d M Y, H:i') }}</b>@if($v->persons > 1) · {{ $v->persons }} people @endif</div>
      <p class="small muted mt-2">Show this pass and a photo ID at the front desk. Valid until {{ $v->valid_until?->format('H:i') }} on the day.</p>
    @else
      <h3 id="passClosed" style="margin:1.2rem 0">{{ $v->status === 'in' ? 'Checked in – welcome!' : 'This visitor pass is no longer valid.' }}</h3>
      <p class="small muted">Questions? Call {{ $hotel->phone }}.</p>
    @endif
  </div>
</div>
@endsection
