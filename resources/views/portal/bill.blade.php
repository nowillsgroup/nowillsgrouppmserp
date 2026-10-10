@extends('portal.layout')
@section('title', 'My bill')
@section('heading', 'My bill')
@section('sub', 'Up to date as of '.now()->format('d M Y, H:i'))
@section('content')
@forelse($folios as $f)
  @php $t = $totals[$f->id]; @endphp
  <div class="gp-card">
    <div class="flex justify-between items-center mb-1"><h3 class="m-0">Folio {{ $f->folio_no }}</h3>
      <span class="st {{ $f->status === 'open' ? 'acknowledged' : 'done' }}">{{ $f->status === 'open' ? 'Open' : 'Settled' }}</span></div>
    @forelse($f->transactions as $tx)
      <div class="gp-row" style="padding:.45rem 0"><div style="min-width:0"><div class="small">{{ $tx->description }}</div><div class="tiny muted">{{ \Illuminate\Support\Carbon::parse($tx->business_date)->format('d M') }}@if($tx->tax_amount > 0) · incl. tax {{ money($tx->tax_amount) }}@endif</div></div>
        <b class="small" style="white-space:nowrap;{{ $tx->type === 'charge' || $tx->type === 'refund' ? '' : 'color:#15803d' }}">{{ $tx->type === 'charge' || $tx->type === 'refund' ? '' : '−' }}{{ money($tx->type === 'charge' ? $tx->amount + $tx->tax_amount : $tx->amount) }}</b></div>
    @empty
      <p class="small muted m-0">No charges yet.</p>
    @endforelse
    <div class="mt-1">
      <div class="gp-kv"><span>Charges</span><span>{{ money($t['charges']) }}</span></div>
      @if($t['discount'] > 0)<div class="gp-kv"><span>Discounts</span><span>−{{ money($t['discount']) }}</span></div>@endif
      <div class="gp-kv"><span>Paid</span><span>−{{ money($t['payments'] - $t['refunds']) }}</span></div>
      <div class="gp-kv" style="font-size:1.05rem"><b>Balance</b><b>{{ money($t['balance']) }}</b></div>
    </div>
    @if(($payOnline ?? false) && $f->status === 'open' && $t['balance'] > 0 && $loop->first)
      <form method="post" action="{{ route('portal.pay', $token) }}" class="mt-1">@csrf<button class="btn btn-primary btn-block"><x-icon name="lock"/> Pay {{ money($t['balance']) }} online</button></form>
      <p class="tiny muted center m-0 mt-1">Secure payment on our payment provider's page – card{{ in_array(\App\Services\OnlinePaymentService::provider(), ['paystack', 'demo']) ? ', mobile money' : '' }}{{ \App\Services\OnlinePaymentService::provider() === 'razorpay' ? ', UPI' : '' }}.</p>
    @endif
  </div>
@empty
  <div class="gp-card"><p class="m-0 small muted">Your bill will appear here after check-in.</p></div>
@endforelse
@if($state === 'inhouse')<p class="tiny muted center">Room charges for each night are added during the night audit. Pending restaurant orders appear once served.</p>@endif

@if($state === 'inhouse')
<div class="gp-card" id="checkout"><h3>Express check-out</h3>
  @if($checkoutReq && $checkoutReq->isOpen())
    <p class="small m-0">Requested {{ $checkoutReq->created_at->diffForHumans() }} – <span class="st {{ $checkoutReq->status }}">{{ \App\Models\GuestRequest::STATUSES[$checkoutReq->status] }}</span>. Leave your key card in the room or at the desk; we will settle your bill and e-mail the invoice.</p>
  @else
    <p class="small muted m-0 mb-1">Leaving soon? Skip the queue – we will prepare your bill and settle it with the card on file or at the desk.</p>
    <form method="post" action="{{ route('portal.checkout', $token) }}">@csrf
      <label class="check small mb-1"><input type="checkbox" name="email_invoice" value="1" checked> <span>E-mail me the invoice{{ $guest?->email ? ' ('.$guest->email.')' : '' }}</span></label>
      <textarea name="notes" rows="2" maxlength="500" class="input mb-1" placeholder="Anything we should know? (e.g. luggage storage, taxi)"></textarea>
      <button class="btn btn-primary btn-block"><x-icon name="logout"/> Request express check-out</button>
    </form>
  @endif
</div>
@endif

@if(in_array($state, ['inhouse', 'departed']))
<div class="gp-card" id="feedback"><h3>{{ $feedback ? 'Thanks for your feedback' : 'How is your stay?' }}</h3>
  <form method="post" action="{{ route('portal.feedback', $token) }}">@csrf
    @foreach(['rating_overall' => 'Overall', 'rating_room' => 'Room', 'rating_service' => 'Service', 'rating_food' => 'Food', 'rating_cleanliness' => 'Cleanliness'] as $k => $label)
      <div class="flex justify-between items-center" style="padding:.2rem 0"><span class="small">{{ $label }}@if($k === 'rating_overall') *@endif</span>
        <span class="stars">@for($s = 5; $s >= 1; $s--)<input type="radio" id="{{ $k }}{{ $s }}" name="{{ $k }}" value="{{ $s }}" @checked((int) old($k, $feedback?->$k) === $s) @if($k === 'rating_overall') required @endif><label for="{{ $k }}{{ $s }}">★</label>@endfor</span></div>
    @endforeach
    <textarea name="comments" rows="3" maxlength="2000" class="input mt-1 mb-1" placeholder="Tell us more…">{{ old('comments', $feedback?->comments) }}</textarea>
    <button class="btn btn-block {{ $feedback ? '' : 'btn-primary' }}">{{ $feedback ? 'Update feedback' : 'Send feedback' }}</button>
  </form>
</div>
@endif
@endsection
