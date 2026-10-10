@extends('layouts.app')
@section('title', 'Voucher '.$v->code)
@section('crumbs') <a href="{{ route('vouchers.index') }}">Gift Vouchers</a> / {{ $v->code }} @endsection
@section('actions')
  <a class="btn" href="{{ route('vouchers.print', $v) }}" target="_blank" id="printBtn"><x-icon name="print"/> Print voucher</a>
  @if(can_do('vouchers.sell') && in_array($v->status, ['active', 'suspended']))<button class="btn" data-modal="emailModal" id="emailBtn"><x-icon name="mail"/> E-mail</button>@endif
  @if(can_do('vouchers.sell') && $v->type->kind === 'stored' && in_array($v->status, ['active', 'redeemed']) && ! $v->isExpired())<button class="btn btn-primary" data-modal="reloadModal" id="reloadBtn"><x-icon name="plus"/> Reload</button>@endif
@endsection
@section('content')
@php $c = $v->type->color; @endphp
<div class="grid g-2">
  <div>
    <div id="gvCard" style="background:linear-gradient(135deg,{{ $c }},{{ $c }}cc);color:#fff;border-radius:14px;padding:1.3rem 1.4rem;box-shadow:0 8px 24px rgba(15,23,42,.18);position:relative">
      <div class="flex" style="justify-content:space-between;align-items:flex-start">
        <div><div style="opacity:.85;font-size:.8rem;text-transform:uppercase;letter-spacing:1px">{{ $currentHotel->name }}</div><div style="font-size:1.1rem;font-weight:600">{{ $v->type->name }}</div></div>
        <span class="badge" style="background:rgba(255,255,255,.2);color:#fff" id="gvStatus">{{ $v->statusLabel() }}</span>
      </div>
      <div style="font-size:2.2rem;font-weight:700;margin:.6rem 0 .1rem" id="gvBalance">{{ money($v->balance) }}</div>
      <div style="opacity:.85;font-size:.85rem">balance of {{ money($v->face_value) }}{{ $v->type->kind === 'stored' ? ' initial load' : '' }}</div>
      <div class="flex" style="justify-content:space-between;align-items:flex-end;margin-top:1rem">
        <div class="mono" style="font-size:1.35rem;letter-spacing:3px;font-weight:700" id="gvCode">{{ $v->code }}</div>
        <div style="text-align:right;font-size:.8rem;opacity:.9">Valid until<br><b style="font-size:1rem">{{ $v->expires_on->format('d M Y') }}</b></div>
      </div>
    </div>
    @if($v->status === 'suspended')<div class="alert alert-warn" style="margin-top:.8rem"><x-icon name="lock"/><div>Suspended: {{ $v->status_note }}</div></div>@endif
    @if($v->status === 'cancelled')<div class="alert alert-error" style="margin-top:.8rem"><x-icon name="x"/><div>Cancelled: {{ $v->status_note }}</div></div>@endif
    @if($v->status === 'active' && $v->isExpired())<div class="alert alert-warn" style="margin-top:.8rem"><x-icon name="clock"/><div>Past its expiry date – the balance lapses at the next night audit.</div></div>@endif

    <div class="card" style="margin-top:1rem"><div class="card-head"><h3>Details</h3><a class="small" href="{{ $v->publicUrl() }}" target="_blank" id="publicLink">Guest view</a></div><div class="card-body">
      <dl class="kv">
        <dt>Source</dt><dd>{{ \App\Models\GiftVoucher::SOURCES[$v->source] }}{{ $v->campaign ? ' – '.$v->campaign : '' }}</dd>
        <dt>Can be used on</dt><dd>{{ $v->type->usesLabel() }}{{ $v->type->kind === 'single' ? ' · single use' : '' }}</dd>
        <dt>Issued</dt><dd>{{ $v->issued_on->format('d M Y') }} by {{ $v->issuer?->name ?? 'system' }}</dd>
        @if($v->source === 'sold')<dt>Paid by</dt><dd>{{ $v->paymentMode?->name }}{{ $v->payment_ref ? ' · '.$v->payment_ref : '' }}</dd>@endif
        @if($v->purchaser_name)<dt>Purchaser</dt><dd>{{ $v->purchaser_name }}{{ $v->purchaser_phone ? ' · '.$v->purchaser_phone : '' }}{{ $v->purchaser_email ? ' · '.$v->purchaser_email : '' }}</dd>@endif
        @if($v->recipient_name || $v->recipient_email)<dt>Recipient</dt><dd>{{ $v->recipient_name }}{{ $v->recipient_email ? ' · '.$v->recipient_email : '' }}</dd>@endif
        @if($v->guest)<dt>Guest profile</dt><dd><a href="{{ route('guests.show', $v->guest) }}">{{ $v->guest->full_name }}</a></dd>@endif
        @if($v->message)<dt>Message</dt><dd><i>“{{ $v->message }}”</i></dd>@endif
        <dt>E-mailed</dt><dd>{{ $v->emailed_at?->format('d M Y H:i') ?? 'not yet' }}{{ $v->reminded_at ? ' · expiry reminder '.$v->reminded_at->format('d M') : '' }}</dd>
        <dt>Redeemed so far</dt><dd>{{ money($v->redeemed()) }}</dd>
      </dl>
    </div></div>
  </div>

  <div>
    @if(can_do('vouchers.redeem') && $v->usable() && $v->type->usableOn('folio'))
    <div class="card" id="redeemCard"><div class="card-head"><h3><x-icon name="receipt" size="sm"/> Apply to a guest folio</h3></div><div class="card-body">
      @if($folios->isEmpty())<p class="muted m-0">No open folios of in-house guests.</p>@else
      <form method="post" action="{{ route('vouchers.redeem', $v) }}" id="redeemForm">@csrf<div class="form-grid">
        <div class="field span-2"><label for="f_folio_id">Folio</label><select name="folio_id" id="f_folio_id" required><option value="">— Select —</option>@foreach($folios as $f)<option value="{{ $f['id'] }}" data-balance="{{ max(0, $f['balance']) }}">{{ $f['label'] }}</option>@endforeach</select></div>
        <x-field name="amount" label="Amount" type="number" step="0.01" min="0.01" :max="$v->balance" :value="number_format($v->balance, 2, '.', '')" required/>
      </div><div style="margin-top:.6rem"><button class="btn btn-success"><x-icon name="check"/> Redeem</button></div></form>
      <script>document.getElementById('f_folio_id').addEventListener('change', e => { const b = parseFloat(e.target.selectedOptions[0]?.dataset.balance || 0); if (b > 0) document.getElementById('f_amount').value = Math.min(b, {{ $v->balance }}).toFixed(2); });</script>
      @endif
      <p class="small muted" style="margin-bottom:0">Or take it at the folio / checkout: payment mode <b>Gift Voucher</b>, reference = the voucher code. In the restaurant choose Gift Voucher when settling the bill.</p>
    </div></div>
    @endif

    <div class="card flush" style="margin-top:1rem"><div class="card-head"><h3>Transactions</h3></div>
      <div class="table-wrap"><table class="table table-compact" id="gvTxns">
        <thead><tr><th>Date</th><th>Movement</th><th>Where / how</th><th class="right">Amount</th><th class="right">Balance</th></tr></thead>
        <tbody>
        @foreach($v->transactions as $t)
          <tr><td class="nowrap">{{ $t->business_date->format('d M Y') }}<div class="tiny muted">{{ $t->user?->name }}</div></td>
            <td>{{ \App\Models\GiftVoucherTransaction::TYPES[$t->type] ?? $t->type }}@if($t->note)<div class="tiny muted">{{ $t->note }}</div>@endif</td>
            <td class="small">@if($t->folioTransaction?->folio)<a href="{{ route('folios.show', $t->folioTransaction->folio) }}">Folio {{ $t->folioTransaction->folio->folio_no }}</a>@elseif($t->posOrder)<a href="{{ route('pos.print', $t->posOrder) }}">{{ $t->outlet }} · {{ $t->posOrder->bill_no }}</a>@else{{ $t->outlet ?: $t->paymentMode?->name }}@endif{{ $t->reference && ! $t->folioTransaction && ! $t->posOrder ? ' · '.$t->reference : '' }}</td>
            <td class="right nowrap {{ \App\Models\GiftVoucherTransaction::SIGN[$t->type] > 0 ? 'text-green' : 'text-red' }}">{{ \App\Models\GiftVoucherTransaction::SIGN[$t->type] > 0 ? '+' : '−' }}{{ money($t->amount) }}</td>
            <td class="right nowrap">{{ money($t->balance_after) }}</td></tr>
        @endforeach
        </tbody>
      </table></div>
    </div>

    @if($emails->isNotEmpty())
    <div class="card" style="margin-top:1rem"><div class="card-head"><h3>E-mails</h3></div><div class="card-body"><ul class="list small">
      @foreach($emails as $e)<li><span>{{ $e->created_at->format('d M H:i') }} · {{ $e->to_email }}</span><span class="badge badge-{{ $e->status === 'sent' ? 'green' : 'red' }}" title="{{ $e->error }}">{{ $e->status }}</span></li>@endforeach
    </ul></div></div>
    @endif

    @if(can_do('vouchers.manage') && in_array($v->status, ['active', 'suspended']))
    <div class="card" style="margin-top:1rem" id="manageCard"><div class="card-head"><h3><x-icon name="shield" size="sm"/> Manage</h3></div><div class="card-body">
      @if($v->status === 'active')
      <form method="post" action="{{ route('vouchers.suspend', $v) }}" class="flex" style="gap:.5rem;margin-bottom:.6rem" id="suspendForm">@csrf<input class="input" name="reason" placeholder="Reason – e.g. reported lost by purchaser" required minlength="3"><button class="btn">Suspend</button></form>
      @else
      <form method="post" action="{{ route('vouchers.unsuspend', $v) }}" style="margin-bottom:.6rem" id="unsuspendForm">@csrf<button class="btn">Re-activate</button></form>
      @endif
      @if($v->balance > 0)
      <form method="post" action="{{ route('vouchers.cancel', $v) }}" id="cancelForm" data-confirm="Cancel {{ $v->code }} and {{ $v->source === 'sold' ? 'refund' : 'withdraw' }} {{ money($v->balance) }}?">@csrf
        <div class="form-grid">
          <x-field name="reason" label="Cancellation reason" required/>
          @if($v->source === 'sold')<x-field name="payment_mode_id" label="Refund {{ money($v->balance) }} by" type="select" :options="$modes" required/>@endif
        </div>
        <button class="btn btn-danger" style="margin-top:.5rem">Cancel {{ $v->source === 'sold' ? '& refund' : 'voucher' }}</button>
      </form>
      @endif
    </div></div>
    @endif
  </div>
</div>

@if(can_do('vouchers.sell'))
<div class="modal" id="emailModal"><div class="modal-box"><form method="post" action="{{ route('vouchers.email', $v) }}">@csrf
  <div class="modal-head"><h3>E-mail voucher</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="to" label="Send to" type="email" :value="$v->recipient_email ?: $v->purchaser_email" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Send</button></div>
</form></div></div>
<div class="modal" id="reloadModal"><div class="modal-box"><form method="post" action="{{ route('vouchers.reload', $v) }}">@csrf
  <div class="modal-head"><h3>Reload {{ $v->code }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="amount" label="Amount" type="number" step="0.01" min="0.01" required/>
    <x-field name="payment_mode_id" label="Paid by" type="select" :options="$modes" required/>
    <x-field name="reference" label="Reference" class="span-2"/>
    <p class="small muted span-2 m-0">Validity restarts: {{ $v->type->validity_months }} months from today.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-success">Reload</button></div>
</form></div></div>
@endif
@endsection
