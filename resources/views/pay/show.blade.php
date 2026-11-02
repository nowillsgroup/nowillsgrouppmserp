@extends('public.layout')
@section('title', $p->status === 'paid' ? 'Payment receipt '.$p->pay_no : 'Payment '.$p->pay_no)
@section('content')
@php $st = $p->displayStatus(); @endphp
<div class="pay-box">
@if($test)<div class="alert alert-warn"><x-icon name="alert"/><div><b>Test mode</b> – this hotel uses the built-in test gateway. No real money is charged.</div></div>@endif
<div class="card"><div class="card-body">
  <div class="flex justify-between items-center gap-1 mb-1"><div><div class="tiny muted">{{ in_array($st, ['paid', 'part_refunded', 'refunded'], true) ? 'RECEIPT' : 'PAYMENT REQUEST' }}</div><h2 class="m-0">{{ $p->pay_no }}</h2></div>
    <span class="badge badge-{{ ['paid' => 'green', 'part_refunded' => 'amber', 'refunded' => 'slate', 'pending' => 'blue', 'review' => 'amber', 'expired' => 'slate', 'cancelled' => 'red'][$st] ?? 'slate' }}" id="payStatus">{{ $p->statusLabel() }}</span></div>
  <div class="tiny muted">{{ in_array($st, ['paid', 'part_refunded', 'review'], true) ? 'AMOUNT RECEIVED' : ($p->isPayable() ? 'AMOUNT DUE' : 'ORIGINAL AMOUNT') }}</div>
  <div class="pay-amount">{{ $p->currency }} {{ number_format($p->status === 'paid' || $p->status === 'review' ? $p->received() : (float) $p->amount, 2) }}</div>
  <dl class="kv-list">
    <div><dt>For</dt><dd>{{ $p->description }}</dd></div>
    <div><dt>Name</dt><dd>{{ $p->payer_name }}</dd></div>
    @if($r)<div><dt>Reservation</dt><dd>{{ $r->reservation_no }} · {{ $r->arrival_date->format('d M Y') }} – {{ $r->departure_date->format('d M Y') }} ({{ $r->rooms->where('status', '!=', 'cancelled')->count() }} × {{ $r->rooms->first()?->roomType?->name }})</dd></div>@endif
    @if($p->status === 'paid' || $p->status === 'review')
      <div><dt>Paid on</dt><dd>@if($p->paid_at)<time datetime="{{ $p->paid_at->toIso8601String() }}">{{ $p->paid_at->format('d M Y, H:i') }}</time>@else—@endif</dd></div>
      <div><dt>Transaction</dt><dd>{{ $p->provider_payment_id ?: '—' }}{{ $p->channel ? ' · '.ucwords(str_replace('_', ' ', $p->channel)) : '' }}</dd></div>
      @if((float) $p->refunded_amount > 0)<div><dt>Refund total</dt><dd>{{ $p->currency }} {{ number_format((float) $p->refunded_amount, 2) }}</dd></div>@endif
    @elseif($p->isPayable() && $p->expires_at)
      <div><dt>Pay by</dt><dd><time datetime="{{ $p->expires_at->toIso8601String() }}">{{ $p->expires_at->format('d M Y, H:i') }}</time></dd></div>
    @elseif($st === 'expired' && $p->expires_at)
      <div><dt>Expired on</dt><dd><time datetime="{{ $p->expires_at->toIso8601String() }}">{{ $p->expires_at->format('d M Y, H:i') }}</time></dd></div>
    @endif
  </dl>

  @if($p->isPayable() && $p->purpose === 'booking')
    <div class="alert alert-info mt-2 m-0" id="holdNotice"><x-icon name="clock"/><div>Your room is <b>held until {{ $p->expires_at->format('d M Y, H:i') }}</b>. Pay the deposit to confirm the booking – after that the room is released.</div></div>
  @endif
  @if($p->isPayable())
    <form method="post" action="{{ route('pay.start', $p->token) }}" class="mt-2">@csrf
      <button class="btn btn-primary btn-block btn-lg" id="payNow"><x-icon name="lock"/> Pay {{ $p->currency }} {{ number_format((float) $p->amount, 2) }} securely</button>
    </form>
    <p class="tiny muted center mt-1 m-0">You will be taken to {{ $test ? 'the test checkout' : ucfirst($p->provider)."'s secure checkout" }} to pay by {{ ['paystack' => 'card or mobile money', 'stripe' => 'card or wallet', 'razorpay' => 'card, UPI or netbanking', 'demo' => 'card, mobile money or UPI'][$p->provider] ?? 'card' }}. {{ $hotel->name }} never sees your card details.</p>
    @if($p->last_error)<p class="small text-red center mt-1 m-0">Last attempt: {{ $p->last_error }}</p>@endif
  @elseif($st === 'refunded')
    <div class="alert alert-info mt-2 m-0"><x-icon name="info"/><div>@if($pendingRefundAmount > 0)<b>The full refund is still being processed.</b> @if($processedRefundAmount > 0){{ $p->currency }} {{ number_format($processedRefundAmount, 2) }} has been returned, and @endif{{ $p->currency }} {{ number_format($pendingRefundAmount, 2) }} is pending with the gateway. No further payment is due on this link.@else<b>This payment was fully refunded.</b> {{ $p->currency }} {{ number_format((float) $p->refunded_amount, 2) }} was returned to the original payment method. No further payment is due on this link.@endif</div></div>
    <button class="btn btn-block mt-1" onclick="window.print()"><x-icon name="print"/> Print receipt</button>
  @elseif($st === 'part_refunded')
    <div class="alert alert-info mt-2 m-0"><x-icon name="info"/><div>@if($pendingRefundAmount > 0)<b>A partial refund is in progress.</b> @if($processedRefundAmount > 0){{ $p->currency }} {{ number_format($processedRefundAmount, 2) }} has been returned; @endif{{ $p->currency }} {{ number_format($pendingRefundAmount, 2) }} is still pending with the gateway. @else<b>A partial refund was issued.</b> {{ $p->currency }} {{ number_format((float) $p->refunded_amount, 2) }} was returned to the original payment method. @endif This page remains a receipt for the original payment.</div></div>
    <button class="btn btn-block mt-1" onclick="window.print()"><x-icon name="print"/> Print receipt</button>
  @elseif($p->status === 'paid')
    <div class="alert alert-success mt-2 m-0"><x-icon name="check"/><div><b>Thank you – payment received.</b> It has been credited to your bill{{ $p->payer_email ? ' and a receipt was e-mailed to you' : '' }}.</div></div>
    @if($bookingDone)<a class="btn btn-primary btn-block mt-1" href="{{ $bookingDone }}">View your booking confirmation</a>@endif
    <button class="btn btn-block mt-1" onclick="window.print()"><x-icon name="print"/> Print receipt</button>
  @elseif($p->status === 'review')
    <div class="alert alert-info mt-2 m-0"><x-icon name="clock"/><div>Your payment was received and is being checked by the hotel. No further action is needed.</div></div>
  @elseif($st === 'expired')
    <div class="alert alert-warn mt-2 m-0"><x-icon name="clock"/><div>This payment link has expired{{ $p->purpose === 'booking' ? ' and the rooms on hold were released' : '' }}. Please contact {{ $hotel->name }}{{ $hotel->phone ? ' on '.$hotel->phone : '' }} for a new link.</div></div>
  @else
    <div class="alert alert-warn mt-2 m-0"><x-icon name="x"/><div>This payment link was cancelled by the hotel.</div></div>
  @endif
</div></div>
</div>
<style>.pay-box{max-width:520px;margin:0 auto}.pay-amount{font-size:2.2rem;font-weight:800;margin:.4rem 0 1rem;letter-spacing:-.02em}
.kv-list{margin:0}.kv-list>div{display:flex;justify-content:space-between;gap:1rem;padding:.5rem 0;border-bottom:1px dashed var(--c-border);font-size:.92rem}.kv-list dt{color:#64748b;font-weight:400;white-space:nowrap}.kv-list dd{margin:0;min-width:0;text-align:right}
.btn-lg{padding:.85rem 1rem;font-size:1.02rem}@media print{.pub-head,.btn,.alert-warn{display:none!important}.pub-wrap{margin-top:0}}</style>
@endsection
