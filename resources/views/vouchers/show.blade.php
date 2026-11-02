@extends('layouts.app')
@php
  $canRevealCode = can_do('vouchers.sell') || can_do('vouchers.manage');
  $displayCode = $canRevealCode ? $v->code : $v->maskedCode();
  $actionForm = (string) old('_voucher_action', '');
  $reloadLimit = $v->type->max_value > 0 ? min((float) $v->type->max_value, (float) $cfg['max']) : (float) $cfg['max'];
  $reloadRoom = max(0, round($reloadLimit - (float) $v->balance, 2));
@endphp
@section('title', 'Voucher '.$displayCode)
@section('crumbs') <a href="{{ route('vouchers.index') }}">Gift Vouchers</a> / {{ $displayCode }} @endsection
@section('actions')
  @if($canRevealCode)<a class="btn" href="{{ route('vouchers.print', $v) }}" target="_blank" rel="noopener" id="printBtn"><x-icon name="print"/> Print voucher</a>@endif
  @if(can_do('vouchers.sell') && in_array($v->status, ['active', 'suspended']))<button class="btn" data-modal="emailModal" id="emailBtn"><x-icon name="mail"/> E-mail</button>@endif
  @if(can_do('vouchers.sell') && $v->type->kind === 'stored' && in_array($v->status, ['active', 'redeemed']) && ! $v->isExpired() && $reloadRoom > 0.004)<button class="btn btn-primary" data-modal="reloadModal" id="reloadBtn"><x-icon name="plus"/> Reload</button>@endif
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
        <div class="mono" style="font-size:1.35rem;letter-spacing:3px;font-weight:700" id="gvCode">{{ $canRevealCode ? $v->code : $v->maskedCode() }}</div>
        <div style="text-align:right;font-size:.8rem;opacity:.9">Valid until<br><b style="font-size:1rem">{{ $v->expires_on->format('d M Y') }}</b></div>
      </div>
    </div>
    @unless($canRevealCode)<p class="small muted" style="margin:.55rem 0 0">Full code, guest share link, and card printing are limited to staff with voucher sales or management access.</p>@endunless
    @if($v->status === 'suspended')<div class="alert alert-warn" style="margin-top:.8rem"><x-icon name="lock"/><div>Suspended: {{ $v->status_note }}</div></div>@endif
    @if($v->status === 'cancelled')<div class="alert alert-error" style="margin-top:.8rem"><x-icon name="x"/><div>Cancelled: {{ $v->status_note }}</div></div>@endif
    @if($v->status === 'active' && $v->isExpired())<div class="alert alert-warn" style="margin-top:.8rem"><x-icon name="clock"/><div>Past its expiry date – the balance lapses at the next night audit.</div></div>@endif

    <div class="card" style="margin-top:1rem"><div class="card-head"><h3>Details</h3>@if($canRevealCode)<a class="small" href="{{ $v->publicUrl() }}" target="_blank" rel="noopener" id="publicLink">Guest view</a>@endif</div><div class="card-body">
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
      <form method="post" action="{{ route('vouchers.redeem', $v) }}" id="redeemForm">@csrf<input type="hidden" name="_voucher_action" value="redeem"><div class="form-grid">
        <div class="field span-2"><label for="f_folio_id">Folio</label><select name="folio_id" id="f_folio_id" required><option value="">— Select —</option>@foreach($folios as $f)<option value="{{ $f['id'] }}" data-balance="{{ max(0, $f['balance']) }}" @selected($actionForm === 'redeem' && old('folio_id') == $f['id'])>{{ $f['label'] }}</option>@endforeach</select></div>
        <div class="field"><label for="redeemAmount">Amount <span class="req">*</span></label><input type="number" id="redeemAmount" name="amount" step="0.01" min="0.01" max="{{ $v->balance }}" value="{{ $actionForm === 'redeem' ? old('amount', number_format($v->balance, 2, '.', '')) : number_format($v->balance, 2, '.', '') }}" required @if($actionForm === 'redeem' && $errors->has('amount')) aria-invalid="true" @endif>@if($actionForm === 'redeem' && $errors->has('amount'))<span class="err">{{ $errors->first('amount') }}</span>@endif</div>
      </div><div style="margin-top:.6rem"><button class="btn btn-success"><x-icon name="check"/> Redeem</button></div></form>
      <script>document.getElementById('f_folio_id').addEventListener('change', e => { const b = parseFloat(e.target.selectedOptions[0]?.dataset.balance || 0); if (b > 0) document.getElementById('redeemAmount').value = Math.min(b, {{ $v->balance }}).toFixed(2); });</script>
      @endif
      <p class="small muted" style="margin-bottom:0">Or take it at the folio / checkout: payment mode <b>Gift Voucher</b>, reference = the voucher code. In the restaurant choose Gift Voucher when settling the bill.</p>
    </div></div>
    @endif

    <div class="card flush" style="margin-top:1rem"><div class="card-head"><h3>Transactions</h3></div>
      <div class="table-wrap"><table class="table table-compact" id="gvTxns">
        <caption class="sr-only">Voucher issue, reload, redemption, reversal, expiry, and cancellation movements.</caption>
        <thead><tr><th scope="col">Date</th><th scope="col">Movement</th><th scope="col">Where / how</th><th scope="col" class="right">Amount</th><th scope="col" class="right">Balance</th></tr></thead>
        <tbody>
        @forelse($v->transactions as $t)
          <tr><td class="nowrap">{{ $t->business_date->format('d M Y') }}<div class="tiny muted">{{ $t->user?->name }}</div></td>
            <td>{{ \App\Models\GiftVoucherTransaction::TYPES[$t->type] ?? $t->type }}@if($t->note)<div class="tiny muted">{{ $t->note }}</div>@endif</td>
            <td class="small">@if($t->folioTransaction?->folio)<a href="{{ route('folios.show', $t->folioTransaction->folio) }}">Folio {{ $t->folioTransaction->folio->folio_no }}</a>@elseif($t->posOrder)<a href="{{ route('pos.print', $t->posOrder) }}">{{ $t->outlet }} · {{ $t->posOrder->bill_no }}</a>@else{{ $t->outlet ?: $t->paymentMode?->name }}@endif{{ $t->reference && ! $t->folioTransaction && ! $t->posOrder ? ' · '.$t->reference : '' }}</td>
            <td class="right nowrap {{ \App\Models\GiftVoucherTransaction::SIGN[$t->type] > 0 ? 'text-green' : 'text-red' }}">{{ \App\Models\GiftVoucherTransaction::SIGN[$t->type] > 0 ? '+' : '−' }}{{ money($t->amount) }}</td>
            <td class="right nowrap">{{ money($t->balance_after) }}</td></tr>
        @empty
          <tr><td colspan="5" class="center muted">No voucher movements recorded.</td></tr>
        @endforelse
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
      <form method="post" action="{{ route('vouchers.suspend', $v) }}" class="flex" style="gap:.5rem;margin-bottom:.6rem" id="suspendForm">@csrf<input type="hidden" name="_voucher_action" value="suspend"><div class="field" style="flex:1"><label class="sr-only" for="suspendReason">Reason for suspension</label><input class="input" id="suspendReason" name="reason" value="{{ $actionForm === 'suspend' ? old('reason') : '' }}" placeholder="Reason – e.g. reported lost by purchaser" required minlength="3" maxlength="200" @if($actionForm === 'suspend' && $errors->has('reason')) aria-invalid="true" @endif>@if($actionForm === 'suspend' && $errors->has('reason'))<span class="err">{{ $errors->first('reason') }}</span>@endif</div><button class="btn">Suspend</button></form>
      @else
      <form method="post" action="{{ route('vouchers.unsuspend', $v) }}" style="margin-bottom:.6rem" id="unsuspendForm">@csrf<button class="btn">Re-activate</button></form>
      @endif
      @if($v->balance > 0)
      <form method="post" action="{{ route('vouchers.cancel', $v) }}" id="cancelForm" data-confirm="Cancel {{ $v->code }} and {{ $v->source === 'sold' ? 'refund' : 'withdraw' }} {{ money($v->balance) }}?">@csrf<input type="hidden" name="_voucher_action" value="cancel">
        <div class="form-grid">
          <div class="field"><label for="cancelReason">Cancellation reason <span class="req">*</span></label><input id="cancelReason" name="reason" value="{{ $actionForm === 'cancel' ? old('reason') : '' }}" required maxlength="200" @if($actionForm === 'cancel' && $errors->has('reason')) aria-invalid="true" @endif>@if($actionForm === 'cancel' && $errors->has('reason'))<span class="err">{{ $errors->first('reason') }}</span>@endif</div>
          @if($v->source === 'sold')<div class="field"><label for="cancelPaymentMode">Refund {{ money($v->balance) }} by <span class="req">*</span></label><select id="cancelPaymentMode" name="payment_mode_id" required><option value="">— Select —</option>@foreach($modes as $id => $name)<option value="{{ $id }}" @selected($actionForm === 'cancel' && old('payment_mode_id') == $id)>{{ $name }}</option>@endforeach</select>@if($actionForm === 'cancel' && $errors->has('payment_mode_id'))<span class="err">{{ $errors->first('payment_mode_id') }}</span>@endif</div>@endif
        </div>
        <button class="btn btn-danger" style="margin-top:.5rem">Cancel {{ $v->source === 'sold' ? '& refund' : 'voucher' }}</button>
      </form>
      @endif
    </div></div>
    @endif
  </div>
</div>

@if(can_do('vouchers.sell'))
<div class="modal @if(old('_voucher_action') === 'email')show @endif" id="emailModal"><div class="modal-box"><form method="post" action="{{ route('vouchers.email', $v) }}">@csrf<input type="hidden" name="_voucher_action" value="email">
  <div class="modal-head"><h3>E-mail voucher</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="to" label="Send to" type="email" :value="$v->recipient_email ?: $v->purchaser_email" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Send</button></div>
</form></div></div>
<div class="modal @if(old('_voucher_action') === 'reload')show @endif" id="reloadModal"><div class="modal-box"><form method="post" action="{{ route('vouchers.reload', $v) }}">@csrf<input type="hidden" name="_voucher_action" value="reload">
  <div class="modal-head"><h3>Reload {{ $v->code }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field"><label for="reloadAmount">Amount <span class="req">*</span></label><input type="number" id="reloadAmount" name="amount" step="0.01" min="0.01" max="{{ $reloadRoom }}" value="{{ $actionForm === 'reload' ? old('amount') : '' }}" required @if($actionForm === 'reload' && $errors->has('amount')) aria-invalid="true" @endif>@if($actionForm === 'reload' && $errors->has('amount'))<span class="err">{{ $errors->first('amount') }}</span>@endif</div>
    <div class="field"><label for="reloadPaymentMode">Paid by <span class="req">*</span></label><select id="reloadPaymentMode" name="payment_mode_id" required><option value="">— Select —</option>@foreach($modes as $id => $name)<option value="{{ $id }}" @selected($actionForm === 'reload' && old('payment_mode_id') == $id)>{{ $name }}</option>@endforeach</select>@if($actionForm === 'reload' && $errors->has('payment_mode_id'))<span class="err">{{ $errors->first('payment_mode_id') }}</span>@endif</div>
    <x-field name="reference" label="Reference" class="span-2"/>
    <p class="small muted span-2 m-0">Expiry after reload: {{ bdate()->copy()->addMonthsNoOverflow($v->type->validity_months ?: $cfg['validity'])->max($v->expires_on)->format('d M Y') }} (the later of the current expiry and {{ bdate()->copy()->addMonthsNoOverflow($v->type->validity_months ?: $cfg['validity'])->format('d M Y') }}; the current expiry will not be shortened).</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-success">Reload</button></div>
</form></div></div>
@endif
@endsection
