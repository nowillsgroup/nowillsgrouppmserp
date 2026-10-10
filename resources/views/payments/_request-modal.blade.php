{{-- Request an online payment. Vars: $payRes (Reservation|null), $payFolio (Folio|null), $paySuggest (float), $payGuest (Guest|null) --}}
<div class="modal" id="payLinkModal"><div class="modal-box"><form method="post" action="{{ route('payments.store') }}">@csrf
@if($payRes)<input type="hidden" name="reservation_id" value="{{ $payRes->id }}">@endif
@if($payFolio)<input type="hidden" name="folio_id" value="{{ $payFolio->id }}">@endif
<div class="modal-head"><h3>Request online payment</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
  @if(! \App\Services\OnlinePaymentService::enabled())
    <div class="alert alert-warn span-2 m-0"><x-icon name="alert"/><div>Online payments are switched off.@if(can_do('payments.settings')) Turn them on in <a href="{{ route('payments.settings') }}">Online Payment Settings</a>.@endif</div></div>
  @endif
  <div class="field"><label>Amount <span class="req">*</span></label><input type="number" name="amount" step="0.01" min="1" class="input" required value="{{ old('amount', number_format($paySuggest, 2, '.', '')) }}" id="payAmt"></div>
  <div class="field"><label>Link valid for (hours)</label><input type="number" name="hours" min="1" max="720" class="input" value="{{ old('hours', setting('pay_link_hours', 72)) }}"></div>
  <div class="field span-2"><label>Description (shown to the guest)</label><input name="description" class="input" maxlength="190" value="{{ old('description') }}" placeholder="{{ $payFolio ? 'Balance of bill '.$payFolio->folio_no : 'Advance for reservation '.$payRes?->reservation_no }}"></div>
  <div class="field"><label>Guest e-mail</label><input type="email" name="email" class="input" value="{{ old('email', $payGuest?->email) }}"></div>
  <div class="field"><label>Guest mobile</label><input name="phone" class="input" value="{{ old('phone', $payGuest?->phone) }}"></div>
  <div class="field span-2"><label>Send the link now</label><div class="flex gap-1 wrap">
    <label class="check"><input type="checkbox" name="send[]" value="email" @checked($payGuest?->email)> E-mail</label>
    <label class="check"><input type="checkbox" name="send[]" value="sms"> SMS</label>
    <label class="check"><input type="checkbox" name="send[]" value="whatsapp"> WhatsApp</label></div>
    <span class="hint">Or leave unticked and copy the link / show the QR code from the next screen.</span></div>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary"><x-icon name="link"/> Create payment link</button></div></form></div></div>
