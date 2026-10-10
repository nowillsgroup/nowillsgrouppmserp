@extends('layouts.app')
@section('title', 'Online Payment Settings')
@section('crumbs') Administration / Online payments @endsection
@section('actions')<a class="btn" href="{{ route('payments.index') }}">Online payments</a>@endsection
@section('content')
@php
  $val = fn ($k, $d = '') => old($k, $v[$k] ?? $d);
  $secret = fn ($k) => '<input type="password" name="'.$k.'" id="f_'.$k.'" class="input" autocomplete="new-password" placeholder="'.($secretSet[$k] ? '•••••••• saved – leave blank to keep' : '').'">'
      .($secretSet[$k] ? '<label class="check tiny mt-1"><input type="checkbox" name="clear_secrets[]" value="'.$k.'"> remove saved key</label>' : '');
@endphp
<form method="post" action="{{ route('payments.settings.save') }}" class="grid g-2-1">@csrf
<div>
  <div class="card"><div class="card-head"><h3>Gateway</h3></div><div class="card-body form-grid">
    <label class="check span-all"><input type="checkbox" name="pay_enabled" value="1" @checked($val('pay_enabled', '0') === '1')> <span><b>Accept online payments</b> – payment links, booking-engine deposits and portal bill payment</span></label>
    <div class="field span-all"><label>Provider</label>
      @foreach(\App\Services\OnlinePaymentService::PROVIDERS as $k => $label)
        <label class="check mb-1"><input type="radio" name="pay_provider" value="{{ $k }}" @checked($val('pay_provider', 'demo') === $k)> <span>{{ $label }} @if($configured[$k])<span class="badge badge-green">ready</span>@else<span class="badge badge-slate">keys missing</span>@endif</span></label>
      @endforeach
    </div>
    <div class="field"><label>Payment links valid for (hours)</label><input type="number" name="pay_link_hours" min="1" max="720" class="input" value="{{ $val('pay_link_hours', 72) }}"></div>
    <div class="field"><label>Currency</label><input class="input" value="{{ \App\Services\OnlinePaymentService::currency() }}" disabled><span class="hint">From the hotel profile – the gateway account must support it.</span></div>
    <label class="check"><input type="checkbox" name="pay_receipt_email" value="1" @checked($val('pay_receipt_email', '1') === '1')> E-mail a receipt when a payment arrives</label>
    <label class="check"><input type="checkbox" name="pay_portal_enabled" value="1" @checked($val('pay_portal_enabled', '1') === '1')> Guests can pay their bill in the mobile portal</label>
  </div></div>

  <div class="card"><div class="card-head"><h3>Booking engine deposit</h3></div><div class="card-body form-grid">
    <div class="field span-2"><label>Online bookings require</label><select name="pay_booking_deposit" class="input" id="depPolicy" onchange="document.getElementById('depPct').closest('.field').style.display=this.value==='percent'?'':'none'">@foreach(\App\Services\OnlinePaymentService::DEPOSIT_POLICIES as $k => $l)<option value="{{ $k }}" @selected($val('pay_booking_deposit', 'none') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field" @style(['display:none' => $val('pay_booking_deposit', 'none') !== 'percent'])><label>Deposit %</label><input type="number" name="pay_booking_deposit_pct" id="depPct" min="1" max="100" step="0.5" class="input" value="{{ $val('pay_booking_deposit_pct', 30) }}"></div>
    <div class="field"><label>Hold rooms for (minutes)</label><input type="number" name="pay_booking_hold_minutes" min="15" max="1440" class="input" value="{{ $val('pay_booking_hold_minutes', 60) }}"><span class="hint">Unpaid bookings are released automatically after this.</span></div>
    <p class="small muted span-2 m-0">With a deposit, an online booking is held as <b>tentative</b> and the guest goes straight to the payment page. It becomes <b>confirmed</b> (and the confirmation e-mail / SMS is sent) when the deposit is paid.</p>
  </div></div>

  <div class="card"><div class="card-head"><h3>Paystack</h3><span class="small muted">dashboard.paystack.com › Settings › API keys & webhooks</span></div><div class="card-body form-grid">
    <div class="field span-2"><label for="f_pay_paystack_secret">Secret key (sk_live_… / sk_test_…)</label>{!! $secret('pay_paystack_secret') !!}<span class="hint">Webhooks are verified with the same key (x-paystack-signature).</span></div>
    <div class="field span-2"><label>Webhook URL</label><span class="hook-url">{{ $hooks['paystack'] }}</span></div>
  </div></div>
  <div class="card"><div class="card-head"><h3>Stripe</h3><span class="small muted">dashboard.stripe.com › Developers</span></div><div class="card-body form-grid">
    <div class="field"><label for="f_pay_stripe_secret">Secret key (sk_…)</label>{!! $secret('pay_stripe_secret') !!}</div>
    <div class="field"><label for="f_pay_stripe_webhook_secret">Webhook signing secret (whsec_…)</label>{!! $secret('pay_stripe_webhook_secret') !!}</div>
    <div class="field span-2"><label>Webhook URL <span class="muted">(events: checkout.session.completed, checkout.session.async_payment_succeeded)</span></label><span class="hook-url">{{ $hooks['stripe'] }}</span></div>
  </div></div>
  <div class="card"><div class="card-head"><h3>Razorpay</h3><span class="small muted">dashboard.razorpay.com › Account & settings › API keys / Webhooks</span></div><div class="card-body form-grid">
    <div class="field"><label>Key ID (rzp_…)</label><input name="pay_razorpay_key_id" class="input" value="{{ $val('pay_razorpay_key_id') }}" autocomplete="off"></div>
    <div class="field"><label for="f_pay_razorpay_key_secret">Key secret</label>{!! $secret('pay_razorpay_key_secret') !!}</div>
    <div class="field"><label for="f_pay_razorpay_webhook_secret">Webhook secret</label>{!! $secret('pay_razorpay_webhook_secret') !!}</div>
    <div class="field span-2"><label>Webhook URL <span class="muted">(event: payment_link.paid)</span></label><span class="hook-url">{{ $hooks['razorpay'] }}</span></div>
  </div></div>
  <button class="btn btn-primary"><x-icon name="check"/> Save settings</button>
</div>
<div>
  <div class="card"><div class="card-body small">
    <h4 class="mt-0">How online payments work</h4>
    <ol class="m-0" style="padding-left:1.1rem">
      <li class="mb-1">Staff create a <b>payment link</b> from a reservation (deposit) or a folio (balance) and send it by e-mail, SMS or WhatsApp, or show the QR code.</li>
      <li class="mb-1">The guest pays on the gateway's hosted page – card data never reaches this server.</li>
      <li class="mb-1">The result is confirmed server-to-server (and by signed webhook) and posted <b>once</b> to the folio with the <i>Online Payment</i> mode.</li>
      <li class="mb-1">Refunds go back through the gateway; payouts move money from 1050 Payment Gateway Clearing to the bank, with fees to 6120.</li>
    </ol>
    <p class="muted mt-1 m-0">Secret keys are stored encrypted and never shown again. Webhooks without a valid signature are rejected and logged.</p>
  </div></div>
</div>
</form>
@endsection
