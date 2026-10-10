@extends('layouts.app')
@section('title', 'Concierge Settings')
@section('crumbs') Concierge / Settings @endsection
@section('content')
<form method="post" action="{{ route('concierge.settings.save') }}" class="card" style="max-width:46rem" id="settingsForm">@csrf @method('PUT')
<div class="card-body"><div class="form-grid">
  <label class="span-all flex gap-1"><input type="checkbox" name="portal" value="1" @checked($cfg['portal'])> <span><b>Guests can book activities on the guest portal</b><br><span class="tiny muted">Only activities marked "portal" and only within the guest's stay dates.</span></span></label>
  <div class="field"><label for="sLead">Portal bookings at least (hours ahead)</label><input type="number" id="sLead" name="portal_lead" min="0" max="168" value="{{ $cfg['portal_lead'] }}" required></div>
  <div class="field"><label for="sConfirm">Flag vendor requests waiting over (hours)</label><input type="number" id="sConfirm" name="confirm_hours" min="0" max="168" value="{{ $cfg['confirm_hours'] }}" required></div>
  <label class="span-all flex gap-1"><input type="checkbox" name="auto_complete" value="1" @checked($cfg['auto_complete'])> <span><b>Night audit completes the day's confirmed activities</b><br><span class="tiny muted">Books the commission automatically; mark no-shows before the audit. Unconfirmed requests for past dates lapse.</span></span></label>
  <label class="span-all flex gap-1"><input type="checkbox" name="notify" value="1" @checked($cfg['notify'])> <span><b>Message guests automatically</b><br><span class="tiny muted">SMS / WhatsApp or e-mail with the voucher link on confirmation, waitlist seats and cancellations.</span></span></label>
  <div class="field"><label for="sPhone">Concierge desk phone</label><input id="sPhone" name="desk_phone" maxlength="30" value="{{ $cfg['desk_phone'] }}" placeholder="shown on vouchers and the portal"></div>
</div></div>
<div class="card-foot"><button class="btn btn-primary">Save</button></div>
</form>
<div class="card mt-1" style="max-width:46rem"><div class="card-body small">
  <b>Accounting (agency model).</b> Room-bill bookings are charged under "Excursions &amp; Tickets" with no VAT; the full price is a liability to the operator (2170 Activity vendors payable). When the activity takes place (or a no-show / late cancellation fee applies) the commission moves to 4089 Concierge commission income (USALI Miscellaneous Income). Pay-the-vendor bookings book the commission against 1140 Commission receivable. Settlements net the two and pay / receive the difference through a treasury account.
  <br><span class="muted">VAT on commission income is accounted for through your VAT return and is not split out here.</span>
</div></div>
@endsection
