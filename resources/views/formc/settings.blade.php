@extends('layouts.app')
@section('title', 'Foreign Guest Settings')
@section('crumbs') Foreign Guests / Settings @endsection
@section('content')
<div class="grid g-2">
<form method="post" action="{{ route('fg.settings.save') }}" id="formcSettings">@csrf <input type="hidden" name="section" value="formc">
  <fieldset @disabled(! can_do('formc.manage')) style="border:0;padding:0;margin:0">
  <div class="card"><div class="card-head"><h3>Form C (FRRO)</h3></div><div class="card-body form-grid">
    <label class="check span-all"><input type="checkbox" name="enabled" value="1" @checked($c['enabled'] === '1')> Track Form C for foreign nationals</label>
    <x-field name="deadline_hours" label="File within (hours of check-in)" type="number" :value="$c['deadline_hours']" required hint="the law allows 24 h"/>
    <x-field name="alert_hours" label="Warn when due within (h)" type="number" :value="$c['alert_hours']" required/>
    <x-field name="departure_hours" label="Report departures within (h)" type="number" :value="$c['departure_hours']" required/>
    <x-field name="alert_email" label="Alert e-mail" type="email" :value="$c['alert_email']" hint="blank = hotel e-mail"/>
    <x-field name="visa_exempt" label="Nationalities that need no visa" :value="$c['visa_exempt']" class="span-all" hint="comma separated – only the visa type is asked"/>
    <label class="check span-all"><input type="checkbox" name="require_scans" value="1" @checked($c['require_scans'] === '1')> Passport &amp; visa scans must be on file before filing</label>
    <label class="check span-all"><input type="checkbox" name="block_checkout" value="1" @checked($c['block_checkout'] === '1')> Refuse check-out while the arrival report is still outstanding</label>
  </div></div>
  @if(can_do('formc.manage'))<button class="btn btn-primary" id="saveFormcSettings"><x-icon name="check"/> Save Form C settings</button>@endif
  </fieldset>
</form>

<form method="post" action="{{ route('fg.settings.save') }}" id="forexSettings">@csrf <input type="hidden" name="section" value="forex">
  <fieldset @disabled(! can_do('forex.manage')) style="border:0;padding:0;margin:0">
  <div class="card"><div class="card-head"><h3>Forex desk (Bank of Ghana licensed buying)</h3></div><div class="card-body form-grid">
    <label class="check span-all"><input type="checkbox" name="enabled" value="1" @checked($x['enabled'] === '1')> Buy foreign currency from guests</label>
    <x-field name="cash_cap_usd" label="Cash limit – foreign guest (USD)" type="number" :value="$x['cash_cap_usd']" required hint="House limit, counted in US dollars"/>
    <x-field name="resident_cap_usd" label="Cash limit – resident (USD)" type="number" :value="$x['resident_cap_usd']" required hint="House limit, counted in US dollars"/>
    <x-field name="aggregate_days" label="Purchases within (days) count as one" type="number" :value="$x['aggregate_days']" required/>
    <x-field name="id_copy_inr" label="Keep a passport copy from ({{ hotel()->currency_symbol }})" type="number" :value="$x['id_copy_inr']" required hint="0 = for every purchase"/>
    <x-field name="max_margin" label="Buy rate at most (%) below reference" type="number" step="0.01" :value="$x['max_margin']" required/>
    <x-field name="fee_pct" label="Service fee on cash (%)" type="number" step="0.01" :value="$x['fee_pct']" required/>
    <x-field name="surrender_days" label="Surrender currency within (days)" type="number" :value="$x['surrender_days']" required hint="0 = no reminder"/>
    <x-field name="licence" label="BoG licence / agency no." :value="$x['licence']"/>
    <x-field name="franchiser" label="Bank / licensed bureau (principal)" :value="$x['franchiser']"/>
    <p class="tiny muted span-all">Money changing is a VAT-exempt financial service – no VAT, NHIL, GETFund or tourism levy is deducted. Buying foreign currency needs a Bank of Ghana forex bureau licence, or an agency with a bank / licensed bureau.</p>
    <label class="check span-all"><input type="checkbox" name="allow_residents" value="1" @checked($x['allow_residents'] === '1')> Also buy from resident (Ghanaian) guests</label>
    <label class="check span-all"><input type="checkbox" name="carry_rates" value="1" @checked($x['carry_rates'] === '1')> Night audit carries rates forward (flagged until reviewed)</label>
    <label class="check span-all"><input type="checkbox" name="channel_convert" value="1" @checked($x['channel_convert'] === '1')> Convert OTA / channel bookings sent in a foreign currency at this board</label>
    <x-field name="channel_basis" label="Channel bookings convert at" type="select" :placeholder="false" :value="$x['channel_basis']" class="span-all"
             :options="['reference' => 'The reference rate (the board as published)', 'buy' => 'The buy rate (what the desk would pay a guest)']"
             hint="Off means a booking in another currency is held in the channel inbox instead of converted."/>
    <x-field name="channel_stale_days" label="Use a board up to (days) old" type="number" min="0" max="90" :value="$x['channel_stale_days']" class="span-all"
             hint="When today’s rates are not set yet. Beyond this the booking is held for a human. 0 = today’s board only."/>
    <label class="check span-all"><input type="checkbox" name="ap_convert" value="1" @checked($x['ap_convert'] === '1')> Convert supplier invoices billed in a foreign currency at this board</label>
    <x-field name="ap_basis" label="Supplier invoices convert at" type="select" :placeholder="false" :value="$x['ap_basis']" class="span-all"
             :options="['reference' => 'The reference rate (the board as published)', 'buy' => 'The buy rate (what the desk would pay a guest)']"
             hint="Off means a bill in another currency is refused until somebody enters it in the property's own currency, so nothing is booked at a rate nobody chose."/>
    <x-field name="ap_stale_days" label="Use a board up to (days) old for a bill" type="number" min="0" max="90" :value="$x['ap_stale_days']" class="span-all"
             hint="An invoice dated on a Sunday, or on a day the desk never set rates, still has to be booked. Beyond this the bill is refused. 0 means that date's board only."/>
    <x-field name="ap_rate_date" label="Read the board at" type="select" :placeholder="false" :value="$x['ap_rate_date']" class="span-all"
             :options="['document' => 'The invoice date - the transaction date, which is what accounting wants', 'business' => 'The board for today, whatever the invoice date']"
             hint="This choice applies to invoice booking. A settlement always reads the board for its payment date. Each invoice line is rounded on its own, so the lines sum to what the ledger posts."/>
    <label class="check span-all"><input type="checkbox" name="ap_pay" value="1" @checked($x['ap_pay'] === '1')> Let a foreign bill be settled in the currency it was billed in <span class="tiny muted">— the actual bank transfer is net of GRA withholding; the cedi tax stays separate, and the realised exchange difference posts to Exchange Gain / Loss</span></label>
    <label class="check span-all"><input type="checkbox" name="ap_pay_deal" value="1" @checked($x['ap_pay_deal'] === '1')> Allow an explicitly typed bank deal rate on a payment <span class="tiny muted">— each payment still previews the dated board; a deal rate must be opted into on the payment and is checked against that board</span></label>
  </div></div>
  @if(can_do('forex.manage'))<button class="btn btn-primary" id="saveForexSettings"><x-icon name="check"/> Save forex settings</button>
  <span class="small muted ml-1"><a href="{{ route('masters.index', 'currencies') }}">Currencies</a> · GL 1060 Foreign Currency on Hand, 4085 / 4986</span>@endif
  </fieldset>
</form>
</div>
@endsection
