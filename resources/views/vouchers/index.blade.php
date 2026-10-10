@extends('layouts.app')
@section('title', 'Gift Vouchers')
@section('crumbs') Gift Vouchers / {{ \App\Http\Controllers\GiftVoucherController::TABS[$tab] }} @endsection
@section('actions')
  @if(can_do('reports.view') || can_do('vouchers.view'))<a class="btn" href="{{ route('reports.show', 'voucher_liability') }}"><x-icon name="chart"/> Liability report</a>@endif
  @if(can_do('vouchers.manage'))<button class="btn" data-modal="promoModal" id="promoBtn"><x-icon name="star"/> Promotional vouchers</button>@endif
  @if(can_do('vouchers.sell'))<a class="btn btn-primary" href="{{ route('vouchers.index', ['tab' => 'sell']) }}" id="sellBtn"><x-icon name="plus"/> Sell voucher</a>@endif
@endsection
@section('content')
@php $s = $snap; $fullCode = can_do('vouchers.sell') || can_do('vouchers.manage'); @endphp
@if(! $cfg['enabled'])<div class="alert alert-info"><x-icon name="alert"/><div>Gift vouchers are switched off – nothing can be sold or redeemed until they are switched on under Settings.</div></div>@endif
@if($s['expiring'])<div class="alert alert-warn" id="expiringAlert"><x-icon name="clock"/><div><a href="{{ route('vouchers.index', ['status' => 'expiring']) }}"><b>{{ $s['expiring'] }}</b> voucher(s) worth {{ money($s['expiring_value']) }}</a> expire within {{ $s['expiring_days'] }} days – holders are e-mailed a reminder automatically.</div></div>@endif
<div class="stats">
  <x-stat label="Outstanding liability" :value="money($s['outstanding'])" icon="wallet" tone="purple" :sub="$s['live'].' vouchers with a balance'"/>
  <x-stat label="Sold today" :value="money($s['sold_today'])" icon="money" tone="green" sub="sales & reloads"/>
  <x-stat label="Redeemed today" :value="money($s['redeemed_today'])" icon="receipt" tone="blue" sub="folio & POS"/>
  <x-stat label="Expiring soon" :value="$s['expiring']" icon="clock" :tone="$s['expiring'] ? 'amber' : 'green'" :href="route('vouchers.index', ['status' => 'expiring'])" :sub="money($s['expiring_value']).' within '.$s['expiring_days'].' days'"/>
</div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\GiftVoucherController::TABS as $k => $l)
      @if(can_do(['list' => 'vouchers.view', 'sell' => 'vouchers.sell', 'lookup' => 'vouchers.view', 'campaigns' => 'vouchers.view', 'types' => 'vouchers.manage', 'settings' => 'vouchers.manage'][$k]))
      <a href="{{ route('vouchers.index', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}</a>
      @endif
    @endforeach
  </div></div>

@if($tab === 'list')
  <div class="card-body" style="padding-bottom:.4rem">
    <form method="get" class="flex" style="gap:.5rem;flex-wrap:wrap" id="gvFilter">
      <input class="input" name="q" value="{{ request('q') }}" placeholder="Code, name, e-mail, phone or campaign" style="max-width:280px">
      <select name="status"><option value="">All statuses</option><option value="expiring" @selected(request('status') === 'expiring')>Expiring soon</option>@foreach(\App\Models\GiftVoucher::STATUSES as $k => [$l]) <option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
      <select name="type"><option value="">All types</option>@foreach($types as $t)<option value="{{ $t->id }}" @selected(request('type') == $t->id)>{{ $t->name }}</option>@endforeach</select>
      <select name="source"><option value="">Sold & promotional</option>@foreach(\App\Models\GiftVoucher::SOURCES as $k => $l)<option value="{{ $k }}" @selected(request('source') === $k)>{{ $l }}</option>@endforeach</select>
      <button class="btn"><x-icon name="search"/> Filter</button>
    </form>
  </div>
  <div class="table-wrap"><table class="table" id="gvTable">
    <thead><tr><th>Code</th><th>Type</th><th>Holder</th><th>Issued</th><th>Expires</th><th class="right">Value</th><th class="right">Balance</th><th>Status</th></tr></thead>
    <tbody>
    @forelse($vouchers as $v)
      <tr>
        <td class="nowrap"><a href="{{ route('vouchers.show', $v) }}" class="mono"><b>{{ $fullCode ? $v->code : $v->maskedCode() }}</b></a>@if($v->source === 'promo')<div class="tiny muted">{{ $v->campaign ?: 'Promotional' }}</div>@endif</td>
        <td><span class="badge" style="background:{{ $v->type->color }}1a;color:{{ $v->type->color }}">{{ $v->type->name }}</span></td>
        <td>{{ $v->recipient_name ?: $v->purchaser_name ?: '—' }}@if($v->recipient_name && $v->purchaser_name && $v->recipient_name !== $v->purchaser_name)<div class="tiny muted">from {{ $v->purchaser_name }}</div>@endif</td>
        <td class="nowrap">{{ $v->issued_on->format('d M Y') }}</td>
        <td class="nowrap {{ $v->status === 'active' && $v->expires_on->lte(bdate()->copy()->addDays($cfg['reminder_days'] ?: 30)) ? 'text-amber' : '' }}">{{ $v->expires_on->format('d M Y') }}</td>
        <td class="right">{{ money($v->face_value) }}</td>
        <td class="right"><b>{{ money($v->balance) }}</b></td>
        <td><span class="badge badge-{{ $v->statusTone() }}">{{ $v->statusLabel() }}</span></td>
      </tr>
    @empty
      <tr><td colspan="8" class="center muted">No vouchers match.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <div class="card-body">{{ $vouchers->links() }}</div>

@elseif($tab === 'sell')
  <div class="card-body">
    <form method="post" action="{{ route('vouchers.sell') }}" id="sellForm">@csrf
      <div class="grid g-2">
        <div>
          <h4 class="mt-0">Voucher</h4>
          <div class="form-grid">
            <div class="field span-2"><label for="f_gift_voucher_type_id">Voucher type <span class="req">*</span></label>
              <select name="gift_voucher_type_id" id="f_gift_voucher_type_id" required>
                <option value="">— Select —</option>
                @foreach($types->where('is_active', true) as $t)<option value="{{ $t->id }}" data-face="{{ $t->face_value }}" @selected(old('gift_voucher_type_id') == $t->id)>{{ $t->name }} · {{ $t->valueLabel() }} · {{ $t->usesLabel() }}</option>@endforeach
              </select></div>
            <x-field name="amount" label="Amount (open-amount vouchers)" type="number" step="0.01" min="0" :hint="'Between '.money($cfg['min']).' and '.money($cfg['max']).'; ignored for fixed-value vouchers'"/>
            <x-field name="expires_on" label="Valid until" type="date" :hint="'Default: '.$cfg['validity'].' months (or the type’s validity)'"/>
            <x-field name="payment_mode_id" label="Paid by" type="select" :options="$modes" required/>
            <x-field name="payment_ref" label="Payment reference" placeholder="card slip / UPI ref"/>
          </div>
        </div>
        <div>
          <h4 class="mt-0">Purchaser & recipient</h4>
          <div class="form-grid">
            <x-field name="purchaser_name" label="Purchaser name" required/>
            <x-field name="purchaser_phone" label="Purchaser phone"/>
            <x-field name="purchaser_email" label="Purchaser e-mail" type="email"/>
            <x-field name="recipient_name" label="Recipient name" placeholder="who the gift is for"/>
            <x-field name="recipient_email" label="Recipient e-mail" type="email" class="span-2"/>
            <x-field name="message" label="Gift message" type="textarea" rows="2" class="span-2" maxlength="300"/>
            <label class="flex small span-2" style="gap:.4rem;align-items:center"><input type="checkbox" name="send_email" value="1" @checked(old('send_email', '1'))> E-mail the voucher (to the recipient, else the purchaser)</label>
          </div>
        </div>
      </div>
      <div class="flex" style="justify-content:space-between;align-items:center;margin-top:.8rem">
        <span class="small muted">A voucher is a prepaid instrument, not a supply – no VAT on the sale; tax is charged on the bill it pays for.</span>
        <button class="btn btn-success" id="sellSubmit"><x-icon name="check"/> Sell voucher</button>
      </div>
    </form>
  </div>

@elseif($tab === 'lookup')
  <div class="card-body">
    <form method="post" action="{{ route('vouchers.lookup') }}" class="flex" style="gap:.5rem;max-width:560px" id="lookupForm">@csrf
      <input class="input mono" name="code" id="lookupCode" value="{{ old('code') }}" placeholder="GV-XXXX-XXXX" autofocus required style="font-size:1.15rem;letter-spacing:2px;text-transform:uppercase">
      <button class="btn btn-primary"><x-icon name="search"/> Check voucher</button>
    </form>
    <p class="small muted">Type the code from the voucher, the e-mail or scan its QR code. The voucher page shows the balance and lets you apply it to an in-house guest’s folio.
      In the restaurant, choose <b>Gift Voucher</b> as the payment mode when settling a bill and enter the code there.</p>
  </div>

@elseif($tab === 'campaigns')
  <div class="table-wrap"><table class="table" id="campaignTable">
    <thead><tr><th>Campaign / reason</th><th class="right">Vouchers</th><th class="right">Value issued</th><th class="right">Fully used</th><th class="right">Outstanding</th><th>Issued</th><th>Expires</th></tr></thead>
    <tbody>
    @forelse($campaigns as $c)
      <tr><td><a href="{{ route('vouchers.index', ['q' => $c->campaign, 'source' => 'promo']) }}"><b>{{ $c->campaign }}</b></a></td><td class="right">{{ $c->n }}</td><td class="right">{{ money($c->issued) }}</td>
        <td class="right">{{ (int) $c->used }} ({{ $c->n ? round($c->used * 100 / $c->n) : 0 }}%)</td><td class="right">{{ money($c->outstanding) }}</td><td>{{ \Illuminate\Support\Carbon::parse($c->first_on)->format('d M Y') }}</td><td>{{ \Illuminate\Support\Carbon::parse($c->expires)->format('d M Y') }}</td></tr>
    @empty
      <tr><td colspan="7" class="center muted">No promotional campaigns yet.@if(can_do('vouchers.manage')) Use “Promotional vouchers” above.@endif</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <div class="card-body small muted">Promotional vouchers cost nothing when issued; their value is booked as a marketing expense (6150) and released back if they lapse unused.</div>

@elseif($tab === 'types')
  <div class="card-body">
    <div class="grid g-2" id="typeGrid">
    @foreach($types as $t)
      <div class="card" style="border-left:4px solid {{ $t->color }}"><div class="card-body">
        <div class="flex" style="justify-content:space-between"><b>{{ $t->name }}</b><span class="badge badge-{{ $t->is_active ? 'green' : 'slate' }}">{{ $t->is_active ? 'Active' : 'Inactive' }}</span></div>
        <div class="small muted">{{ \App\Models\GiftVoucherType::KINDS[$t->kind] ?? $t->kind }} · {{ $t->valueLabel() }} · {{ $t->validity_months }} months · {{ $t->usesLabel() }} · {{ $t->vouchers_count }} issued</div>
        @if($t->terms)<div class="small" style="margin-top:.3rem">{{ $t->terms }}</div>@endif
        <details style="margin-top:.5rem"><summary class="small">Edit</summary>@include('vouchers._type_form', ['t' => $t])</details>
      </div></div>
    @endforeach
    </div>
    <h4>New voucher type</h4>
    @include('vouchers._type_form', ['t' => null])
  </div>

@elseif($tab === 'settings')
  <div class="card-body">
    <form method="post" action="{{ route('vouchers.settings') }}" id="gvSettings" style="max-width:720px">@csrf
      <div class="form-grid">
        <label class="flex span-2" style="gap:.4rem;align-items:center"><input type="checkbox" name="enabled" value="1" @checked($cfg['enabled'])> Gift vouchers enabled (sale & redemption)</label>
        <x-field name="validity" label="Default validity (months)" type="number" min="1" max="120" :value="$cfg['validity']" required/>
        <x-field name="reminder_days" label="Expiry reminder (days before)" type="number" min="0" max="180" :value="$cfg['reminder_days']" required hint="0 = no reminders"/>
        <x-field name="min" label="Smallest open-amount voucher" type="number" step="0.01" min="0" :value="$cfg['min']" required/>
        <x-field name="max" label="Largest balance on one voucher" type="number" step="0.01" min="1" :value="$cfg['max']" required/>
        <x-field name="terms" label="Terms printed on every voucher" type="textarea" rows="3" class="span-2" :value="$cfg['terms']"/>
      </div>
      <div class="flex" style="justify-content:space-between;margin-top:.8rem"><span class="small muted">Expiry & reminders run in the night audit and daily at 06:30 (pms:vouchers).</span><button class="btn btn-primary">Save settings</button></div>
    </form>
    <form method="post" action="{{ route('vouchers.run') }}" style="margin-top:1rem" data-confirm="Expire overdue vouchers and send reminders now?">@csrf<button class="btn" id="runNow"><x-icon name="refresh"/> Run expiry & reminders now</button></form>
  </div>
@endif
</div>

@if(can_do('vouchers.manage'))
<div class="modal" id="promoModal"><div class="modal-box" style="max-width:640px"><form method="post" action="{{ route('vouchers.promo') }}">@csrf
  <div class="modal-head"><h3>Promotional / complimentary vouchers</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="campaign" label="Campaign or reason" required placeholder="Diwali 2026 · Service recovery – room 204" class="span-2"/>
    <x-field name="gift_voucher_type_id" label="Voucher type" type="select" :options="$types->where('is_active', true)->pluck('name', 'id')" required/>
    <x-field name="amount" label="Value each (open-amount types)" type="number" step="0.01" min="0"/>
    <x-field name="count" label="How many (without a recipient list)" type="number" min="1" max="500" value="1"/>
    <x-field name="expires_on" label="Valid until" type="date"/>
    <x-field name="recipients" label="Recipients – one per line: Name, e-mail (each gets a voucher by e-mail)" type="textarea" rows="4" class="span-2" placeholder="Asha Rao, asha@example.com"/>
    <x-field name="message" label="Message" type="textarea" rows="2" class="span-2" maxlength="300"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-success">Issue vouchers</button></div>
</form></div></div>
@endif
@endsection
