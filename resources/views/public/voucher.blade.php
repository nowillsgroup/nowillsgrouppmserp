@extends('public.layout')
@section('title', 'Gift voucher')
@section('content')
@php $c = $v->type->color; $live = $v->usable(); @endphp
<div class="card" style="max-width:560px;margin:0 auto" id="pubVoucher">
  <div style="background:linear-gradient(135deg,{{ $c }},{{ $c }}cc);color:#fff;padding:1.4rem;border-radius:12px 12px 0 0">
    <div style="display:flex;justify-content:space-between;align-items:flex-start"><div style="font-size:1.15rem;font-weight:600">{{ $v->type->name }}</div>
      <span class="badge" style="background:rgba(255,255,255,.22);color:#fff" id="pubStatus">{{ $v->status === 'active' && $v->isExpired() ? 'Expired' : $v->statusLabel() }}</span></div>
    <div style="font-size:.85rem;opacity:.85;margin-top:.8rem">Balance</div>
    <div style="font-size:2.4rem;font-weight:800" id="pubBalance">{{ money($v->balance) }}</div>
    <div style="font-family:monospace;font-size:1.3rem;letter-spacing:3px;font-weight:700;margin-top:.6rem">{{ $v->code }}</div>
  </div>
  <div class="card-body" style="display:flex;gap:1.2rem;align-items:center">
    <div style="width:120px;flex:none">{!! $qr !!}</div>
    <div>
      @if($v->recipient_name)<div>For <b>{{ $v->recipient_name }}</b></div>@endif
      <div>Valid until <b>{{ $v->expires_on->format('d M Y') }}</b></div>
      <div class="small muted">Use it on: {{ $v->type->usesLabel() }}{{ $v->type->kind === 'single' ? ' (single use)' : '' }}</div>
      @if($v->message)<div class="small" style="margin-top:.4rem;font-style:italic">“{{ $v->message }}”</div>@endif
      @if($live)<div class="small" style="margin-top:.4rem">Show this page or the code when you pay at {{ $hotel->name }}.</div>@else<div class="small text-red" style="margin-top:.4rem">This voucher can no longer be used.</div>@endif
    </div>
  </div>
  @if($recent->isNotEmpty())
  <div class="card-body" style="border-top:1px solid #e2e8f0"><h4 class="mt-0 small">Recent activity</h4>
    <table class="table table-compact small"><tbody>@foreach($recent as $t)<tr><td>{{ $t->business_date->format('d M Y') }}</td><td>{{ \App\Models\GiftVoucherTransaction::TYPES[$t->type] }}</td><td class="right">{{ \App\Models\GiftVoucherTransaction::SIGN[$t->type] > 0 ? '+' : '−' }}{{ money($t->amount) }}</td></tr>@endforeach</tbody></table>
  </div>
  @endif
  <div class="card-body small muted" style="border-top:1px solid #e2e8f0">{{ $v->type->terms }} {{ $cfg['terms'] }}</div>
  <div class="card-body" style="padding-top:0"><button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button></div>
</div>
@endsection
