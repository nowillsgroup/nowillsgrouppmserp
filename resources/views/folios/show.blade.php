@extends('layouts.app')
@section('title', 'Folio '.$folio->folio_no)
@section('crumbs') <a href="{{ route('folios.index') }}">Folios</a> / {{ $folio->folio_no }} @endsection
@section('actions')
  {!! status_badge($folio->status) !!}
  @if($folio->status === 'open')
    @if(can_do('folio.post'))<button class="btn btn-primary" data-modal="chargeModal"><x-icon name="plus"/> Post Charge</button>@endif
    @if(can_do('folio.payment'))<button class="btn btn-success" data-modal="payModal"><x-icon name="money"/> Payment</button>@endif
    @if(can_do('payments.request') && $totals['balance'] > 0)<button class="btn" data-modal="payLinkModal"><x-icon name="link"/> Payment link</button>@endif
    @if(can_do('folio.discount'))<button class="btn" data-modal="discModal">Discount</button>@endif
    @if(can_do('folio.payment') && $totals['balance'] > 0)<button class="btn" data-modal="transferModal"><x-icon name="building"/> City Ledger</button>@endif
    @if(can_do('folio.payment') && !($folio->stay && $folio->stay->status === 'checked_in'))<form method="post" action="{{ route('folios.close', $folio) }}" data-confirm="Settle and close this folio?">@csrf<button class="btn btn-warning">Settle & Close</button></form>@endif
    @if($folio->stay && $folio->stay->status === 'checked_in' && can_do('frontdesk.checkout'))<a class="btn btn-warning" href="{{ route('frontdesk.checkout', $folio->stay) }}"><x-icon name="logout"/> Check-out</a>@endif
  @else
    @if($folio->invoice_no && $folio->status !== 'cancelled')<a class="btn btn-primary" href="{{ route('folios.invoice', $folio) }}" target="_blank"><x-icon name="print"/> {{ $folio->isCreditNote() ? 'Credit note' : 'Invoice' }} {{ $folio->invoice_no }}</a>@endif
    @if($folio->status === 'closed' && can_do('folio.credit_note') && ! $folio->isCreditNote())<a class="btn" href="{{ route('gst.credit_note.create', $folio) }}" id="creditNoteBtn"><x-icon name="swap"/> Issue credit note</a>@endif
    @if($folio->status === 'closed' && can_do('folio.void') && ! \App\Services\GstService::reopenBlocker($folio))<form method="post" action="{{ route('folios.reopen', $folio) }}" data-confirm="Re-open this closed folio? This is logged.">@csrf<button class="btn">Re-open</button></form>@endif
    @if($folio->status === 'closed' && $folio->invoice_no && can_do('gst.manage') && ! \App\Services\GstService::cancelBlocker($folio))<button class="btn btn-danger" data-modal="gstCancelModal" id="gstCancelBtn"><x-icon name="x"/> Cancel {{ $folio->isCreditNote() ? 'note' : 'invoice' }}</button>@endif
  @endif
  <button class="btn" data-modal="mailModal" title="E-mail {{ $folio->invoice_no ? 'invoice' : 'statement' }} to guest"><x-icon name="mail"/> E-mail</button>
@endsection
@section('content')
<div class="stats">
  <x-stat label="Charges (incl. tax)" :value="money($totals['charges'])" icon="receipt" tone="blue"/>
  <x-stat label="Discounts" :value="money($totals['discount'])" icon="star" tone="purple"/>
  <x-stat label="Payments" :value="money($totals['payments'] - $totals['refunds'])" icon="wallet" tone="green"/>
  <x-stat label="Balance" :value="money($totals['balance'])" icon="money" :tone="$totals['balance'] > 0 ? 'red' : 'teal'"/>
</div>
<div class="grid g-3-1">
  <div class="card flush"><div class="card-head"><h3>Transactions</h3><span class="flex gap-1 items-center"><span class="small muted">{{ $transactions->where('is_void', false)->count() }} active</span>
    @if($folio->status === 'open' && can_do('folio.split'))<button type="button" class="btn btn-sm" id="moveBtn" data-modal="moveModal" disabled>Split / move selected (<span id="txN">0</span>)</button>@endif</span></div>
    <div class="card-body table-wrap"><table class="table">
      <thead><tr>@if($folio->status === 'open' && can_do('folio.split'))<th style="width:28px"><input type="checkbox" title="Select all" onclick="document.querySelectorAll('.txsel').forEach(c => c.checked = this.checked); txCount()"></th>@endif<th>Date</th><th>Description</th><th>Category</th><th class="num">Amount</th><th class="num">Tax</th><th class="num">Debit</th><th class="num">Credit</th><th>By</th><th></th></tr></thead><tbody>
      @forelse($transactions as $t)
        <tr class="{{ $t->is_void ? 'void' : '' }}">
          @if($folio->status === 'open' && can_do('folio.split'))<td>@unless($t->is_void)<input type="checkbox" class="txsel" name="tx[]" value="{{ $t->id }}" form="moveForm" onchange="txCount()">@endunless</td>@endif
          <td class="nowrap">{{ $t->business_date->format('d M') }}</td>
          <td>{{ $t->description }} @if($t->reference)<span class="tiny muted">#{{ $t->reference }}</span>@endif @if($t->paymentMode)<span class="badge badge-slate">{{ $t->paymentMode->name }}</span>@endif @if($t->company)<span class="badge badge-blue">{{ $t->company->name }}</span>@endif
            @if($t->cheque_number)<div class="tiny muted">Cheque {{ $t->cheque_number }} · {{ $t->cheque_bank_name ?: 'Bank not recorded' }}{{ $t->cheque_bank_branch ? ' · '.$t->cheque_bank_branch : '' }}</div>@endif
            @if($t->is_void)<div class="tiny text-red">Void: {{ $t->void_reason }}</div>@endif</td>
          <td class="small">{{ config('pms.charge_categories.'.$t->category, ucwords(str_replace('_',' ',$t->category))) }}</td>
          <td class="num">{{ $t->type === 'charge' ? money($t->amount, false) : '' }}</td>
          <td class="num">{{ $t->type === 'charge' && $t->tax_amount > 0 ? money($t->tax_amount, false).' ('.(float)$t->tax_rate.'%)' : '' }}</td>
          <td class="num">{{ in_array($t->type, ['charge','refund']) ? money($t->total, false) : '' }}</td>
          <td class="num">{{ in_array($t->type, ['payment','discount','allowance']) ? money($t->amount, false) : '' }}</td>
          <td class="tiny muted">{{ $t->poster?->username ?? ($t->source_type === 'online_payment' ? 'online' : '') }}</td>
          <td>@if(!$t->is_void && $folio->status === 'open' && can_do('folio.void') && ! in_array($t->source_type, ['online_payment', 'online_refund']))<button class="btn btn-sm btn-ghost" data-modal="voidModal" data-action="{{ route('folios.void', $t) }}" title="Void"><x-icon name="x" size="sm"/></button>@endif</td>
        </tr>
      @empty <tr><td colspan="9"><x-empty text="No transactions yet." icon="receipt"/></td></tr> @endforelse
      </tbody>
      <tfoot><tr><td colspan="{{ $folio->status === 'open' && can_do('folio.split') ? 6 : 5 }}" class="right">Totals</td><td class="num">{{ money($totals['charges'] + $totals['refunds'], false) }}</td><td class="num">{{ money($totals['payments'] + $totals['discount'], false) }}</td><td colspan="2"></td></tr></tfoot>
    </table></div></div>
  <div>
    <div class="card"><div class="card-head"><h3>Folio Details</h3></div><div class="card-body"><dl class="kv">
      <dt>Guest</dt><dd>@if($folio->guest)<a href="{{ route('guests.show', $folio->guest) }}">{{ $folio->guest->full_name }}</a>@endif</dd>
      <dt>Type</dt><dd>{{ ucwords(str_replace('_',' ',$folio->type)) }}</dd>
      @if($folio->stay)<dt>Room</dt><dd>{{ $folio->stay->room?->room_no }} ({{ $folio->stay->roomType->name }})</dd><dt>Stay</dt><dd>{{ $folio->stay->arrival_date->format('d M') }} → {{ $folio->stay->departure_date->format('d M') }}</dd><dt>Rate</dt><dd>{{ money($folio->stay->rate) }}</dd>@endif
      @if($folio->reservation)<dt>Reservation</dt><dd><a href="{{ route('reservations.show', $folio->reservation) }}">{{ $folio->reservation->reservation_no }}</a></dd>@endif
      <dt>Bill to</dt><dd>{{ $folio->company?->name ?? 'Guest' }}</dd>
      @if($folio->invoice_no)<dt>Invoice</dt><dd>{{ $folio->invoice_no }}</dd><dt>Closed</dt><dd>{{ $folio->closed_at?->format('d M Y H:i') }}</dd>@endif
    </dl></div></div>
    @if($folio->invoice_no && \App\Services\GstService::isGhana())@include('folios._gst')@endif
    @if($folio->stay && $folio->stay->status === 'checked_in' && (can_do('keys.view') || can_do('calls.view')))@include('interfaces._stay', ['stay' => $folio->stay])@endif
    @if($folio->stay)
    <div class="card" id="fixed"><div class="card-head"><h3><x-icon name="refresh" size="sm"/> Fixed charges / packages</h3>
      @if($folio->status === 'open' && in_array($folio->stay->status, ['reserved','checked_in']) && can_do('folio.post'))<button class="btn btn-sm" data-modal="fixedModal"><x-icon name="plus" size="sm"/> Add</button>@endif @if($folio->status === 'open' && in_array($folio->stay->status, ['reserved','checked_in']) && can_do('packages.sell'))<button class="btn btn-sm" data-modal="pkgModal" id="addPkgBtn"><x-icon name="coffee" size="sm"/> Package</button>@endif</div>
      <div class="card-body">
      @php $incPk = \App\Services\PackageService::includedIn($folio->stay->rate_plan_id); @endphp
      @if($incPk->isNotEmpty())<div class="pkg-included small" id="rateIncludes"><x-icon name="coffee" size="sm"/> Rate {{ $folio->stay->ratePlan?->code }} includes: @foreach($incPk as $ip)<b>{{ $ip->name }}</b> ({{ $ip->quote((int) $folio->stay->adults, (int) $folio->stay->children)[0] }}{{ $ip->calc === 'per_room' ? '' : ' cov' }}, {{ strtolower(\App\Models\Package::RHYTHMS[$ip->rhythm]) }})@if(! $loop->last), @endif @endforeach</div>@endif
      @forelse($fixedCharges as $c)
        <div class="flex items-center gap-1" style="padding:6px 0;border-bottom:1px solid var(--c-border)">
          <div style="flex:1"><b class="{{ $c->is_active ? '' : 'muted' }}">{{ $c->description }}</b> <span class="badge {{ $c->frequency === 'daily' ? 'badge-blue' : 'badge-slate' }}">{{ $c->frequency === 'daily' ? 'per night' : 'once' }}</span> @unless($c->is_active)<span class="badge badge-slate">stopped</span>@endunless
            <div class="tiny muted">{{ money($c->amount) }}{{ $c->apply_tax ? ' + tax' : '' }} · from {{ $c->start_date->format('d M') }}{{ $c->end_date ? ' to '.$c->end_date->format('d M') : '' }} · posted {{ $c->times_posted }}×@if($c->is_active && $folio->stay->status !== 'checked_out') · still to post ≈ {{ money(\App\Services\FixedChargeService::projected($c, $folio->stay)) }}@endif</div></div>
          @if($c->is_active && can_do('folio.post'))<form method="post" action="{{ route('folios.fixed.destroy', $c) }}" data-confirm="Stop this fixed charge?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Stop"><x-icon name="x" size="sm"/></button></form>@endif
        </div>
      @empty<p class="small muted">No fixed charges. Add breakfast, parking, an extra bed or a one-off pickup here. They post automatically with the room tariff every night.</p>@endforelse
      </div></div>
    @endif
    @if($folio->guest && \App\Services\LoyaltyService::enabled())
    @php $pts = (int) $folio->guest->loyalty_points; $pv = \App\Services\LoyaltyService::pointValue(); $minR = \App\Services\LoyaltyService::minRedeem(); @endphp
    <div class="card"><div class="card-head"><h3><x-icon name="star" size="sm"/> Loyalty</h3><span class="badge badge-purple">{{ \App\Services\LoyaltyService::tier($pts) }}</span></div><div class="card-body">
      <div class="flex justify-between"><span>Points balance</span><b>{{ number_format($pts) }}</b></div>
      <div class="flex justify-between small muted"><span>Worth</span><span>{{ money($pts * $pv) }}</span></div>
      @if($folio->status === 'open')
        <div class="small muted mt-1">Est. points on this folio: <b>{{ (int) floor(\App\Services\LoyaltyService::eligibleSpend($folio) / \App\Services\LoyaltyService::spendPerPoint()) }}</b> (credited at settlement)</div>
        @if(can_do('folio.loyalty') && $pts >= $minR && $totals['balance'] > 0)
        <form method="post" action="{{ route('folios.redeem', $folio) }}" class="flex gap-1 mt-1" data-confirm="Redeem these points as a discount on this folio?">@csrf
          <input class="input input-sm" type="number" name="points" min="{{ $minR }}" max="{{ min($pts, (int) floor($totals['balance'] / max($pv, 0.0001))) }}" value="{{ min($pts, (int) floor($totals['balance'] / max($pv, 0.0001))) }}" required style="width:110px">
          <button class="btn btn-sm btn-primary" style="flex:1">Redeem points</button>
        </form>
        <div class="tiny muted mt-1">1 point = {{ money($pv) }} · minimum {{ $minR }} points</div>
        @endif
      @endif
    </div></div>
    @endif
  </div>
</div>

<div class="modal" id="chargeModal"><div class="modal-box"><form method="post" action="{{ route('folios.charge', $folio) }}">@csrf
  <div class="modal-head"><h3>Post charge</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="category" label="Category" type="select" :options="$categories" required :placeholder="false"/>
    <x-field name="description" label="Description" required placeholder="e.g. Laundry 3 shirts"/>
    <x-field name="amount" label="Unit amount (excl. tax)" type="number" step="0.01" min="0.01" required/>
    <x-field name="qty" label="Qty" type="number" step="0.01" min="0.01" value="1"/>
    <label class="check span-all"><input type="hidden" name="apply_tax" value="0"><input type="checkbox" name="apply_tax" value="1" checked> Apply tax as per tax master</label>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Post</button></div>
</form></div></div>

<div class="modal" id="payModal"><div class="modal-box"><form method="post" action="{{ route('folios.payment', $folio) }}">@csrf
  <div class="modal-head"><h3>Payment / Refund</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="type" label="Type" type="select" :options="['payment'=>'Receive payment','refund'=>'Refund to guest']" :placeholder="false"/>
    <x-field name="amount" label="Amount" type="number" step="0.01" min="0.01" :value="$totals['balance'] > 0 ? number_format($totals['balance'],2,'.','') : ''" required/>
    <x-field name="payment_mode_id" label="Mode" type="select" :options="$paymentModes" required data-payment-mode/>
    <x-field name="reference" label="Reference / Txn ID" hint="Gift Voucher payments: enter the voucher code (GV-XXXX-XXXX)"/>
    <x-cheque-fields/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-success">Save</button></div>
</form></div></div>

@if($folio->status === 'open' && can_do('folio.split'))
<div class="modal" id="moveModal"><div class="modal-box"><form method="post" action="{{ route('folios.move', $folio) }}" id="moveForm">@csrf
  <div class="modal-head"><h3>Split / route folio lines</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field span-all"><label>Move selected lines to <span class="req">*</span></label><select name="target" required>
      <option value="new">➕ New split folio (same guest &amp; stay)</option>
      @foreach($targets as $f)<option value="{{ $f->id }}">{{ $f->folio_no }} · {{ $f->guest?->full_name ?? ucwords(str_replace('_',' ',$f->type)) }}{{ $f->stay?->room ? ' · Room '.$f->stay->room->room_no : '' }}</option>@endforeach
    </select><span class="hint">e.g. route extras to the guest's own bill while room charges stay on the company folio</span></div>
    <x-field name="reason" label="Reason" required class="span-all" placeholder="Company pays room only / split between guests…"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Move lines</button></div>
</form></div></div>
@endif
@if($folio->stay && $folio->status === 'open')
@if(can_do('packages.sell') && $folio->stay)
<div class="modal" id="pkgModal"><div class="modal-box"><form method="post" action="{{ route('packages.addon', $folio) }}">@csrf
  <div class="modal-head"><h3>Add package</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field span-all"><label for="pkgSel">Package <span class="req">*</span></label><select name="package_id" id="pkgSel" required><option value="">—</option>@foreach(\App\Models\Package::where('is_active', true)->where('is_sellable', true)->orderBy('sort')->orderBy('name')->get() as $sp) @php [$sq, $sa] = $sp->quote((int) $folio->stay->adults, (int) $folio->stay->children); @endphp<option value="{{ $sp->id }}">{{ $sp->name }} – {{ money($sa) }}{{ in_array($sp->rhythm, ['once','arrival','departure']) ? ' once' : ' / night' }}{{ $sp->calc === 'per_room' ? '' : ' ('.$sq.' pax)' }}</option>@endforeach</select></div>
    <x-field name="start_date" label="From (night of)" type="date" :value="$folio->stay->arrival_date->max(bdate())->min($folio->stay->departure_date->copy()->subDay())->toDateString()" required/>
    <x-field name="end_date" label="To (optional)" type="date" hint="Empty = until departure"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Add package</button></div>
</form></div></div>
@endif
<div class="modal" id="fixedModal"><div class="modal-box"><form method="post" action="{{ route('folios.fixed.store', $folio) }}">@csrf
  <div class="modal-head"><h3>Add fixed charge</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field span-all"><label>Description <span class="req">*</span></label><input name="description" list="fixedPresets" required maxlength="120" placeholder="Breakfast (2 pax)">
      <datalist id="fixedPresets"><option>Breakfast</option><option>Parking</option><option>Extra bed</option><option>Airport pickup</option><option>Late check-out</option><option>Laundry package</option><option>Wi-Fi premium</option></datalist></div>
    <x-field name="category" label="Category" type="select" :options="config('pms.charge_categories')" value="misc" required/>
    <x-field name="amount" label="Amount" type="number" step="0.01" min="0.01" required/>
    <x-field name="frequency" label="Frequency" type="select" :options="['daily' => 'Every night of the stay', 'once' => 'Once']" :placeholder="false" required/>
    <label class="check" style="align-self:end"><input type="hidden" name="apply_tax" value="0"><input type="checkbox" name="apply_tax" value="1" checked> Apply tax (by category)</label>
    <x-field name="start_date" label="Start (night of)" type="date" :value="$folio->stay->arrival_date->max(bdate())->min($folio->stay->departure_date->copy()->subDay())->toDateString()" required/>
    <x-field name="end_date" label="End (optional)" type="date" hint="Empty = until departure"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Add</button></div>
</form></div></div>
@endif
<div class="modal" id="mailModal"><div class="modal-box"><form method="post" action="{{ route('folios.email', $folio) }}">@csrf
  <div class="modal-head"><h3>E-mail {{ $folio->invoice_no ? 'invoice '.$folio->invoice_no : 'folio statement' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="to" label="Send to" type="email" :value="$folio->guest?->email ?? $folio->company?->email" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary"><x-icon name="mail"/> Send</button></div>
</form></div></div>
<div class="modal" id="discModal"><div class="modal-box"><form method="post" action="{{ route('folios.discount', $folio) }}">@csrf
  <div class="modal-head"><h3>Discount / Allowance</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="mode" label="Mode" type="select" :options="['amount'=>'Flat amount','percent'=>'% of charges']" :placeholder="false"/>
    <x-field name="value" label="Value" type="number" step="0.01" min="0.01" required/>
    <x-field name="reason" label="Reason / Approved by" required class="span-all"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Apply</button></div>
</form></div></div>

<div class="modal" id="transferModal"><div class="modal-box"><form method="post" action="{{ route('folios.transfer', $folio) }}">@csrf
  <div class="modal-head"><h3>Transfer balance to City Ledger</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p>Balance of <b>{{ money($totals['balance']) }}</b> will be billed to the company account.</p>@include('credit._picker', ['companies' => $companies, 'value' => $folio->company_id ?? $folio->reservation?->company_id, 'amount' => max(0, $totals['balance']), 'required' => true])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Transfer</button></div>
</form></div></div>

<div class="modal" id="voidModal"><div class="modal-box"><form method="post" action="">@csrf
  <div class="modal-head"><h3>Void transaction</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Void</button></div>
</form></div></div>
@if($folio->status === 'closed' && $folio->invoice_no && can_do('gst.manage'))@include('folios._gst-cancel')@endif
@if($folio->status === 'open' && can_do('payments.request') && $totals['balance'] > 0)
@include('payments._request-modal', ['payRes' => null, 'payFolio' => $folio, 'paySuggest' => (float) $totals['balance'], 'payGuest' => $folio->guest])
@endif
@endsection
@push('scripts')
<script>function txCount(){ const n = document.querySelectorAll('.txsel:checked').length; const b = document.getElementById('moveBtn'); if (b) { b.disabled = !n; document.getElementById('txN').textContent = n; } }</script>
@endpush
