@extends('layouts.app')
@section('title', 'Online payment '.$p->pay_no)
@section('crumbs')<a href="{{ route('payments.index') }}">Online payments</a> / {{ $p->pay_no }} @endsection
@section('actions')
@php $st = $p->displayStatus(); @endphp
<span class="badge badge-{{ ['paid' => 'green', 'part_refunded' => 'amber', 'refunded' => 'slate', 'pending' => 'blue', 'review' => 'red', 'expired' => 'slate', 'cancelled' => 'red'][$st] ?? 'slate' }}" id="olpStatus">{{ $p->statusLabel() }}</span>
@if($p->status === 'pending' && $p->provider_ref && can_do('payments.view'))<form method="post" action="{{ route('payments.check', $p) }}" class="inline">@csrf<button class="btn"><x-icon name="refresh"/> Check with gateway</button></form>@endif
@if($p->refundable() > 0 && can_do('payments.refund'))<button class="btn" data-modal="refundModal"><x-icon name="swap"/> Refund</button>@endif
@if($p->status === 'review' && can_do('payments.refund'))<button class="btn btn-primary" data-modal="acceptModal"><x-icon name="check"/> Accept & post</button>@endif
@if($p->status === 'pending' && can_do('payments.request'))<button class="btn btn-danger" data-modal="cancelModal"><x-icon name="x"/> Cancel link</button>@endif
@endsection
@section('content')
@if($p->status === 'review')<div class="alert alert-error"><x-icon name="alert"/><div><b>{{ $p->last_error }}</b> The money was taken by the gateway but has <b>not</b> been posted to a folio. Accept it to post the amount actually received, or refund it.</div></div>
@elseif($p->last_error && $p->status === 'pending')<div class="alert alert-warn"><x-icon name="alert"/><div>Last attempt: {{ $p->last_error }}</div></div>@endif
<div class="grid g-2-1">
<div>
  <div class="card"><div class="card-body">
    <div class="flex justify-between items-start gap-1 wrap"><div><div class="tiny muted">{{ strtoupper(\App\Models\OnlinePayment::PURPOSES[$p->purpose] ?? $p->purpose) }}</div><div style="font-size:2rem;font-weight:800">{{ money($p->status === 'paid' || $p->status === 'review' ? $p->received() : $p->amount) }}</div><div class="small">{{ $p->description }}</div></div>
      @if((float) $p->refunded_amount > 0)<div class="right"><div class="tiny muted">REFUNDED</div><div class="bold text-red">{{ money($p->refunded_amount) }}</div><div class="tiny muted">refundable {{ money($p->refundable()) }}</div></div>@endif</div>
    <div class="grid g-2 mt-1 kv-grid">
      <div><span>Guest</span><b>{{ $p->payer_name }}</b><div class="tiny muted">{{ $p->payer_email }} {{ $p->payer_phone }}</div></div>
      @if($p->company_id && $p->company)<div><span>Company account</span><a class="bold" href="{{ route('accounts.company', $p->company_id) }}">{{ $p->company->name }}</a> <span class="tiny muted">{{ $p->purpose === 'city_ledger' ? 'city-ledger settlement' : '' }}</span></div> @endif
      @if($p->banquet_event_id && $p->event)<div><span>Event</span><a class="bold" href="{{ route('banquet.show', $p->event) }}">{{ $p->event->event_no }}</a> <span class="tiny muted">{{ $p->event->title }} · event deposit / balance online</span></div> @endif
      <div><span>Reservation / folio</span>@if($p->reservation)<a class="bold" href="{{ route('reservations.show', $p->reservation) }}">{{ $p->reservation->reservation_no }}</a> <span class="badge badge-slate">{{ $p->reservation->status }}</span>@endif @if($p->folio)<div><a href="{{ route('folios.show', $p->folio) }}">Folio {{ $p->folio->folio_no }}</a></div>@endif</div>
      <div><span>Created</span><b>{{ $p->created_at->format('d M Y, H:i') }}</b><div class="tiny muted">by {{ $p->creator?->name ?? 'guest (online)' }}{{ $p->sent_at ? ' · sent '.$p->sent_at->format('d M H:i') : '' }}</div></div>
      <div><span>{{ $p->paid_at ? 'Paid' : 'Expires' }}</span><b>{{ ($p->paid_at ?? $p->expires_at)?->format('d M Y, H:i') ?? '—' }}</b></div>
      <div><span>Gateway</span><b>{{ \App\Services\OnlinePaymentService::PROVIDERS[$p->provider] ?? $p->provider }}</b><div class="tiny muted">{{ $p->attempts }} checkout attempt(s)</div></div>
      <div><span>Transaction</span><b>{{ $p->provider_payment_id ?? '—' }}</b><div class="tiny muted">{{ $p->channel ? str_replace('_', ' ', $p->channel).' · ' : '' }}{{ $p->fee !== null ? 'gateway fee '.money($p->fee) : '' }}</div></div>
      @if($p->folioTransaction)<div class="span-2"><span>Posted to</span><a class="bold" href="{{ route('folios.show', $p->folioTransaction->folio) }}">Folio {{ $p->folioTransaction->folio->folio_no }}</a> · {{ $p->folioTransaction->description }} · {{ dmy($p->folioTransaction->business_date) }} · Online Payment mode → 1050 Payment Gateway Clearing</div>@endif
      @if($p->cancel_reason)<div class="span-2"><span>Cancelled</span><b>{{ $p->cancel_reason }}</b></div>@endif
    </div>
  </div></div>

  @if($p->refunds->count())
  <div class="card flush"><div class="card-head"><h3>Refunds</h3></div><div class="card-body table-wrap"><table class="table table-compact">
    <thead><tr><th>Date</th><th>Reason</th><th>Gateway ref</th><th>Status</th><th>By</th><th class="num">Amount</th></tr></thead><tbody>
    @foreach($p->refunds as $rf)<tr><td class="small">{{ $rf->created_at->format('d M Y, H:i') }}</td><td>{{ $rf->reason }}</td><td class="small">{{ $rf->provider_ref }}</td><td><span class="badge badge-{{ $rf->status === 'processed' ? 'green' : 'amber' }}">{{ $rf->status }}</span></td><td class="small">{{ $rf->creator?->name }}</td><td class="num">{{ money($rf->amount) }}</td></tr>@endforeach
  </tbody></table></div></div>
  @endif

  <div class="card"><div class="card-head"><h3>Timeline</h3><span class="small muted">gateway events, webhooks & staff actions</span></div><div class="card-body"><ol class="timeline" id="olpTimeline">
    @foreach($p->events as $e)
    <li class="tl-{{ in_array($e->event, ['paid', 'accepted', 'refunded']) ? 'ok' : (in_array($e->event, ['failed', 'review', 'rejected', 'refund_failed', 'verify_error', 'cancelled']) ? 'bad' : 'info') }}">
      <div class="flex justify-between gap-1"><b class="small">{{ ucwords(str_replace('_', ' ', $e->event)) }}</b><span class="tiny muted">{{ $e->created_at->format('d M H:i:s') }}</span></div>
      <div class="small">{{ $e->result }}</div>
      <div class="tiny muted">{{ ucfirst($e->source) }}{{ $e->user ? ' · '.$e->user->name : '' }}{{ $e->signature_ok ? ' · signature verified' : '' }}{{ $e->event_id ? ' · '.$e->event_id : '' }}</div>
    </li>
    @endforeach
  </ol></div></div>
</div>

<div>
  @if($qr)
  <div class="card"><div class="card-head"><h3>Payment link</h3></div><div class="card-body center">
    <div class="qr-box">{!! $qr !!}</div>
    <div class="small muted mb-1">Guest scans to pay on their phone</div>
    <div class="flex gap-1"><input readonly class="input input-sm" id="payUrl" value="{{ $p->url() }}" onclick="this.select()"><button type="button" class="btn btn-sm" onclick="navigator.clipboard?.writeText(document.getElementById('payUrl').value).then(() => this.textContent = 'Copied ✓')">Copy</button></div>
    <a class="btn btn-sm btn-block mt-1" href="{{ $p->url() }}" target="_blank" rel="noopener"><x-icon name="eye"/> Open guest page</a>
  </div></div>
  @if(can_do('payments.request'))
  <div class="card"><div class="card-head"><h3>Send / resend</h3></div><form class="card-body" method="post" action="{{ route('payments.send', $p) }}">@csrf
    <div class="field"><label>E-mail</label><input type="email" name="email" class="input" value="{{ $p->payer_email }}"></div>
    <div class="field"><label>Mobile</label><input name="phone" class="input" value="{{ $p->payer_phone }}"></div>
    <div class="flex gap-1 wrap mb-1"><label class="check"><input type="checkbox" name="send[]" value="email" checked> E-mail</label><label class="check"><input type="checkbox" name="send[]" value="sms"> SMS</label><label class="check"><input type="checkbox" name="send[]" value="whatsapp"> WhatsApp</label></div>
    <button class="btn btn-primary btn-block"><x-icon name="mail"/> Send link</button>
  </form></div>
  @endif
  @elseif($p->status === 'paid' || $p->status === 'review')
  <div class="card"><div class="card-body"><a class="btn btn-block" href="{{ $p->url() }}" target="_blank" rel="noopener"><x-icon name="receipt"/> Guest receipt page</a></div></div>
  @endif
  <div class="card"><div class="card-body small muted">
    <p class="m-0 mb-1"><b>How it posts:</b> when the gateway confirms the payment (on return or by webhook), it is credited once to {{ $p->folio_id ? 'the folio' : 'the in-house folio, or the reservation\'s advance folio before arrival' }} with the <i>Online Payment</i> mode.</p>
    <p class="m-0">Refunds go back through the gateway and post a refund line on the same folio. Online payments can't be voided on the folio.</p>
  </div></div>
</div>
</div>

@if($p->refundable() > 0 && can_do('payments.refund'))
<div class="modal" id="refundModal"><div class="modal-box"><form method="post" action="{{ route('payments.refund', $p) }}" onsubmit="return confirm('Refund through {{ ucfirst($p->provider) }} now?')">@csrf
<div class="modal-head"><h3>Refund {{ $p->pay_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
  <div class="field"><label>Amount <span class="req">*</span></label><input type="number" name="amount" step="0.01" min="0.01" max="{{ $p->refundable() }}" class="input" required value="{{ number_format($p->refundable(), 2, '.', '') }}"><span class="hint">Up to {{ money($p->refundable()) }}</span></div>
  <div class="field"><label>Reason <span class="req">*</span></label><input name="reason" class="input" maxlength="190" required placeholder="e.g. Cancelled within the free window"></div>
  <p class="small muted span-2 m-0">The refund is sent to {{ ucfirst($p->provider) }} and a refund line is posted on folio {{ $p->folioTransaction?->folio?->folio_no }} (which must be open).</p>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Refund</button></div></form></div></div>
@endif
@if($p->status === 'review' && can_do('payments.refund'))
<div class="modal" id="acceptModal"><div class="modal-box"><form method="post" action="{{ route('payments.accept', $p) }}">@csrf
<div class="modal-head"><h3>Accept {{ money($p->received()) }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><p class="small">Posts the amount actually received ({{ $p->currency }} {{ number_format($p->received(), 2) }}) to the guest's folio.</p>
  <div class="field"><label>Note <span class="req">*</span></label><input name="note" class="input" maxlength="190" required placeholder="e.g. Guest paid the updated amount by phone agreement"></div></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Accept & post</button></div></form></div></div>
@endif
@if($p->status === 'pending' && can_do('payments.request'))
<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('payments.cancel', $p) }}">@csrf
<div class="modal-head"><h3>Cancel payment link</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><div class="field"><label>Reason <span class="req">*</span></label><input name="reason" class="input" maxlength="190" required></div></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel link</button></div></form></div></div>
@endif
@endsection
