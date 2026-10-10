@extends('layouts.app')
@section('title', $l->lease_no.' – '.$l->tenant_name)
@section('crumbs') <a href="{{ route('leases.index') }}">Apartments &amp; Leases</a> / <a href="{{ route('leases.list') }}">Leases</a> / {{ $l->lease_no }} @endsection
@section('actions')
<a class="btn" href="{{ route('leases.agreement', $l) }}" target="_blank" id="agreementBtn"><x-icon name="book"/> Agreement</a>
@if($l->status !== 'draft')<a class="btn" href="{{ route('leases.statement', $l) }}" target="_blank" id="stmtBtn"><x-icon name="print"/> Statement</a>@endif
@if(in_array($l->status, ['ended', 'terminated']))<a class="btn" href="{{ route('leases.settlement', $l) }}" target="_blank" id="settlementPrint"><x-icon name="receipt"/> Deposit settlement</a>@endif
@if(can_do('leases.billing') && $l->status !== 'cancelled')<button class="btn btn-primary" data-modal="payModal" id="payBtn" data-f-amount="{{ $balance > 0 ? number_format($balance, 2, '.', '') : '' }}"><x-icon name="money"/> Receive money</button>@endif
@endsection
@section('content')
@php $live = in_array($l->status, ['active', 'notice']); @endphp
<div class="stats" id="leaseStats">
  <x-stat :label="$unit->code.' – '.\App\Models\LeaseUnit::TYPES[$unit->type]" :value="\App\Models\Lease::STATUSES[$l->status]" icon="building" :tone="['draft' => 'purple', 'active' => 'green', 'notice' => 'amber', 'ended' => 'slate', 'terminated' => 'red', 'cancelled' => 'slate'][$l->status]" :sub="dmy($l->start_date).' – '.dmy($l->end_date).($l->move_out_on ? ' · out '.dmy($l->move_out_on) : '').' · '.$l->term_months.' mo'"/>
  <x-stat label="Rent / month" :value="money($l->rent)" icon="trend" tone="blue" :sub="\App\Models\Lease::CYCLES[$l->cycle_months].' invoices'.((float) $l->service_charge > 0 ? ' · + '.money($l->service_charge, false).' service charge' : '').' · '.ucfirst($l->tax_class)"/>
  <x-stat label="Account balance" :value="money(abs($balance))" icon="wallet" :tone="$balance > 0.004 ? ($overdue[0] > 0 ? 'red' : 'amber') : 'green'" :sub="$balance > 0.004 ? ($overdue[0] > 0 ? money($overdue[0]).' in arrears since '.dmy($overdue[1]) : 'due') : ($balance < -0.004 ? 'paid in advance / in credit' : 'settled').($l->billed_to ? ' · invoiced to '.dmy($l->billed_to) : '')"/>
  <x-stat label="Deposit held" :value="money($deposit)" icon="lock" tone="purple" :sub="'agreed '.money($l->deposit_amount, false).($l->settled_at ? ' · settled '.$l->settled_at->format('d/m/Y') : '')"/>
</div>

@if($cap !== null)<div class="alert alert-info" id="capNote"><x-icon name="shield"/><div>Residential tenancy – the Rent Act 1963 (Act 220) s.25(5) caps rent paid in advance at <b>{{ money($cap) }}</b> ({{ $l->term_months > 6 ? '6 months' : '1 month' }}). Receipts that would exceed it are refused.</div></div>@endif
@if($l->status === 'draft' && ! $l->signed_at)<div class="alert alert-warn"><x-icon name="edit"/><div>Print the agreement for signature, then record the signature. The deposit can be received now; the tenant moves in from {{ dmy($l->start_date->copy()->subDays(3)) }}.</div></div>@endif

@if($l->status !== 'cancelled')
<div class="flex gap-1 mb-1" style="flex-wrap:wrap" id="lifecycle">
  @if(can_do('leases.manage'))
    @if($l->status === 'draft')
      <a class="btn btn-sm" href="{{ route('leases.edit', $l) }}" id="editBtn"><x-icon name="edit"/> Edit terms</a>
      <button class="btn btn-sm" data-modal="signModal" id="signBtn"><x-icon name="edit"/> {{ $l->signed_at ? 'Re-record signature' : 'Record signature' }}</button>
      <button class="btn btn-sm btn-primary" data-modal="moveInModal" id="moveInBtn"><x-icon name="login"/> Move in</button>
      <button class="btn btn-sm btn-danger" data-modal="cancelModal" id="cancelBtn"><x-icon name="x"/> Cancel draft</button>
    @endif
    @if($l->status === 'active')
      <button class="btn btn-sm" data-modal="renewModal" id="renewBtn"><x-icon name="refresh"/> Renew</button>
      <button class="btn btn-sm" data-modal="noticeModal" id="noticeBtn"><x-icon name="bell"/> Record notice</button>
    @endif
    @if($l->status === 'notice')<form method="post" action="{{ route('leases.withdraw', $l) }}">@csrf<button class="btn btn-sm" id="withdrawBtn">Withdraw notice</button></form>@endif
    @if($live)
      <button class="btn btn-sm btn-primary" data-modal="moveOutModal" id="moveOutBtn"><x-icon name="logout"/> Move out</button>
      <button class="btn btn-sm" data-modal="inspectModal" id="inspectBtn"><x-icon name="check"/> Inspection</button>
    @endif
  @endif
  @if(can_do('leases.billing') && $l->status !== 'draft')<button class="btn btn-sm" data-modal="chargeModal" id="chargeBtn"><x-icon name="plus"/> Add charge</button>@endif
  @if(can_do('leases.approve'))
    @if(in_array($l->status, ['ended', 'terminated']) && ! $l->settled_at)<button class="btn btn-sm btn-primary" data-modal="settleModal" id="settleBtn"><x-icon name="lock"/> Settle deposit</button>@endif
    @if($balance < -0.004)<button class="btn btn-sm" data-modal="refundModal" id="refundBtn" data-f-amount="{{ number_format(-$balance, 2, '.', '') }}" data-f-kind="refund">Refund credit {{ money(-$balance) }}</button>@endif
    @if($deposit > 0.004 && $balance > 0.004 && $l->status !== 'draft')<button class="btn btn-sm" data-modal="applyModal" id="applyBtn" data-f-amount="{{ number_format(min($deposit, $balance), 2, '.', '') }}">Apply deposit</button>@endif
  @endif
</div>
@endif

<div class="tabs" data-tabs>
  <button class="active" data-tab="account">Account</button>
  <button data-tab="deposit">Deposit</button>
  <button data-tab="details">Terms</button>
  <button data-tab="meters">Meters ({{ $readings->count() }})</button>
  <button data-tab="inspections">Inspections ({{ $inspections->count() }})</button>
  <button data-tab="tickets">Maintenance ({{ $tickets->count() }})</button>
</div>

<div class="tab-pane active" id="account">
  <div class="card flush"><div class="card-head"><h3>Invoices &amp; credit notes</h3></div><div class="card-body table-wrap"><table class="table" id="chargesTable">
    <thead><tr><th>No.</th><th>Date</th><th>Description</th><th>Due</th><th class="num">Net</th><th class="num">Tax</th><th class="num">Total</th><th class="num">Settled</th><th></th></tr></thead>
    <tbody>@forelse($charges as $c)@php $st = $c->state(); @endphp
      <tr data-charge="{{ $c->charge_no }}" data-type="{{ $c->type }}" class="{{ $c->cancelled_at ? 'muted' : '' }}"><td class="bold"><a href="{{ route('leases.charges.invoice', $c) }}" target="_blank">{{ $c->charge_no }}</a></td><td class="small">{{ dmy($c->date) }}</td>
        <td>{{ $c->description }}<div class="tiny muted">{{ \App\Models\LeaseCharge::TYPES[$c->type] }}</div>@if($c->cancelled_at)<div class="tiny text-red">cancelled: {{ $c->cancel_reason }}</div>@endif</td>
        <td class="small">{{ $c->type === 'credit' ? '' : dmy($c->due_date) }}</td><td class="num">{{ money($c->amount, false) }}</td><td class="num">{{ money($c->tax, false) }}</td><td class="num bold">{{ money($c->total, false) }}</td>
        <td class="num">@if($st === 'paid')<span class="badge badge-green">paid</span>@elseif($st === 'part')<span class="badge badge-amber">{{ money($c->paid, false) }}</span>@elseif($st === 'open')<span class="badge {{ $c->due_date->copy()->addDays((int) \App\Services\LeaseService::cfg('lease_grace_days'))->toDateString() < $today ? 'badge-red' : 'badge-slate' }}">open</span>@elseif($st === 'credit')<span class="badge badge-blue">credit</span>@endif</td>
        <td class="num" style="white-space:nowrap">@if(! $c->cancelled_at && can_do('leases.approve'))
          @if($c->type !== 'credit' && \App\Services\LeaseBillingService::creditable($c) > 0.004)<button class="btn btn-sm" data-modal="creditModal" data-title="Credit note on {{ $c->charge_no }}" data-action="{{ route('leases.charges.credit', $c) }}" data-f-amount="{{ number_format(\App\Services\LeaseBillingService::creditable($c), 2, '.', '') }}">Credit</button>@endif
          @if($c->date->toDateString() === $today)<button class="btn btn-sm btn-ghost" data-modal="cancelChargeModal" data-title="Cancel {{ $c->charge_no }}" data-action="{{ route('leases.charges.cancel', $c) }}" title="Cancel">✕</button>@endif @endif</td></tr>
    @empty <tr><td colspan="9"><x-empty :text="$l->status === 'draft' ? 'Invoices are raised at move-in and then '.\App\Services\LeaseService::cfg('lease_bill_ahead_days').' days before each period.' : 'No charges.'" icon="receipt"/></td></tr> @endforelse</tbody></table></div></div>

  <div class="card flush mt-1"><div class="card-head"><h3>Receipts &amp; refunds</h3></div><div class="card-body table-wrap"><table class="table" id="receiptsTable">
    <thead><tr><th>No.</th><th>Date</th><th>Type</th><th>Mode / reference</th><th class="num">Amount</th><th class="num">WHT</th><th></th></tr></thead>
    <tbody>@forelse($payments->whereIn('kind', ['payment', 'refund', 'deposit_applied']) as $p)
      <tr data-receipt="{{ $p->receipt_no }}" class="{{ $p->voided_at ? 'muted' : '' }}"><td class="bold"><a href="{{ route('leases.receipts.show', $p) }}" target="_blank">{{ $p->receipt_no }}</a></td><td class="small">{{ dmy($p->date) }}</td><td>{{ \App\Models\LeasePayment::KINDS[$p->kind] }}@if($p->voided_at)<div class="tiny text-red">void: {{ $p->void_reason }}</div>@endif</td>
        <td class="small">{{ $p->paymentMode?->name ?? '—' }}{{ $p->reference ? ' · '.$p->reference : '' }}</td><td class="num bold">{{ money($p->amount, false) }}</td><td class="num small">{{ (float) $p->wht_amount > 0 ? money($p->wht_amount, false).($p->wht_cert_no ? ' ('.$p->wht_cert_no.')' : '') : '' }}</td>
        <td class="num">@if(! $p->voided_at && can_do('leases.approve'))<button class="btn btn-sm btn-ghost" data-modal="voidModal" data-title="Void {{ $p->receipt_no }}" data-action="{{ route('leases.receipts.void', $p) }}">Void</button>@endif</td></tr>
    @empty <tr><td colspan="7"><x-empty text="No receipts yet." icon="money"/></td></tr> @endforelse</tbody></table></div></div>
</div>

<div class="tab-pane" id="deposit">
  <div class="card flush"><div class="card-head"><h3>Security deposit – agreed {{ money($l->deposit_amount) }}, held {{ money($deposit) }}</h3></div><div class="card-body table-wrap"><table class="table" id="depositTable">
    <thead><tr><th>No.</th><th>Date</th><th>Movement</th><th>Mode / reference</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody>@forelse($payments->whereIn('kind', ['deposit', 'deposit_refund', 'deposit_applied']) as $p)
      <tr class="{{ $p->voided_at ? 'muted' : '' }}"><td class="bold"><a href="{{ route('leases.receipts.show', $p) }}" target="_blank">{{ $p->receipt_no }}</a></td><td class="small">{{ dmy($p->date) }}</td><td>{{ \App\Models\LeasePayment::KINDS[$p->kind] }}@if($p->voided_at)<div class="tiny text-red">void</div>@endif</td>
        <td class="small">{{ $p->paymentMode?->name ?? '—' }}{{ $p->reference ? ' · '.$p->reference : '' }}{{ $p->note ? ' · '.$p->note : '' }}</td><td class="num {{ $p->kind === 'deposit' ? '' : 'text-red' }}">{{ $p->kind === 'deposit' ? '' : '−' }}{{ money($p->amount, false) }}</td>
        <td class="num">@if(! $p->voided_at && can_do('leases.approve'))<button class="btn btn-sm btn-ghost" data-modal="voidModal" data-title="Void {{ $p->receipt_no }}" data-action="{{ route('leases.receipts.void', $p) }}">Void</button>@endif</td></tr>
    @empty <tr><td colspan="6"><x-empty text="No deposit received yet." icon="lock"/></td></tr> @endforelse</tbody></table></div></div>
  <p class="tiny muted">Deposits are held in 2140 Tenant Security Deposits Held – they are not income and carry no VAT until applied to the tenant's account at move-out.</p>
</div>

<div class="tab-pane" id="details">
  <div class="grid g-2" style="gap:1rem">
    <div class="card"><div class="card-head"><h3>Tenant</h3></div><div class="card-body"><dl class="kv">
      <dt>Name</dt><dd>{{ $l->tenant_name }}</dd>@if($l->company)<dt>Paying company</dt><dd>{{ $l->company->name }}</dd>@endif
      <dt>Phone / email</dt><dd>{{ $l->phone ?: '—' }} {{ $l->email }}</dd><dt>ID</dt><dd>{{ $l->id_type }} {{ $l->id_no ?: '—' }}</dd>
      <dt>Occupants</dt><dd>{{ $l->occupants ?: '—' }}</dd><dt>Signed</dt><dd>{{ $l->signed_at ? $l->signed_by.' on '.$l->signed_at->format('d/m/Y') : 'not yet' }}</dd>
      <dt>WHT deducted</dt><dd>{{ (float) $l->wht_pct > 0 ? rtrim(rtrim(number_format($l->wht_pct, 2), '0'), '.').'% of rent – ask for the WHT credit certificate' : 'none' }}</dd>
      @if($l->notes)<dt>Notes</dt><dd>{{ $l->notes }}</dd>@endif</dl></div></div>
    <div class="card"><div class="card-head"><h3>Terms</h3></div><div class="card-body"><dl class="kv">
      <dt>Unit</dt><dd>{{ $unit->label() }}{{ $unit->room ? ' (hotel room '.$unit->room->room_no.' – off nightly sale)' : '' }}</dd>
      <dt>Tax class</dt><dd>{{ \App\Models\Lease::TAX_CLASSES[$l->tax_class] }}</dd>
      <dt>Billing</dt><dd>{{ \App\Models\Lease::CYCLES[$l->cycle_months] }}, in advance, due on the first day of each period</dd>
      <dt>Rent review</dt><dd>{{ (float) $l->escalation_pct > 0 ? '+'.rtrim(rtrim(number_format($l->escalation_pct, 2), '0'), '.').'% every '.$l->escalation_months.' months · next '.dmy($l->next_review_on) : 'fixed for the term' }}</dd>
      <dt>Notice</dt><dd>{{ $l->notice_days }} days{{ $l->notice_on ? ' · given by '.$l->notice_by.' on '.dmy($l->notice_on).' – '.$l->end_reason : '' }}</dd>
      <dt>Utilities</dt><dd>Electricity: {{ \App\Models\Lease::UTILITY_MODES[$l->elec_mode] }}@if($l->elec_mode === 'metered') @ GH₵ {{ \App\Services\LeaseService::tariff($l, 'electricity') }}/kWh @endif<br>Water: {{ \App\Models\Lease::UTILITY_MODES[$l->water_mode] }}@if($l->water_mode === 'metered') @ GH₵ {{ \App\Services\LeaseService::tariff($l, 'water') }}/m³ @endif</dd>
      <dt>Included</dt><dd>{{ $l->inclusions ?: '—' }}</dd><dt>Auto-renew</dt><dd>{{ $l->auto_renew ? 'yes' : 'no' }}</dd>
      <dt>Moved in</dt><dd>{{ $l->moved_in_on ? dmy($l->moved_in_on) : '—' }}</dd></dl></div></div>
  </div>
</div>

<div class="tab-pane" id="meters">
  <div class="card flush"><div class="card-head"><h3>Meter readings – {{ $unit->code }}</h3><a class="small" href="{{ route('leases.meters') }}">Bulk entry →</a></div><div class="card-body table-wrap"><table class="table" id="readingsTable">
    <thead><tr><th>Date</th><th>Meter</th><th>Kind</th><th class="num">Previous</th><th class="num">Reading</th><th class="num">Used</th><th>Charge</th></tr></thead>
    <tbody>@forelse($readings as $r)
      <tr><td class="small">{{ dmy($r->date) }}</td><td>{{ \App\Models\LeaseMeterReading::METERS[$r->meter][0] }}</td><td class="small">{{ $r->kind }}</td><td class="num">{{ $r->previous !== null ? number_format($r->previous, 2) : '—' }}</td><td class="num bold">{{ number_format($r->reading, 2) }}</td>
        <td class="num">{{ number_format($r->consumption, 2) }} {{ \App\Models\LeaseMeterReading::METERS[$r->meter][1] }}</td><td class="small">@if($r->charge)<a href="{{ route('leases.charges.invoice', $r->charge) }}" target="_blank">{{ $r->charge->charge_no }}</a> {{ money($r->charge->total, false) }}@endif</td></tr>
    @empty <tr><td colspan="7"><x-empty text="No readings." icon="thermo"/></td></tr> @endforelse</tbody></table></div></div>
</div>

<div class="tab-pane" id="inspections">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="inspTable">
    <thead><tr><th>Date</th><th>Inspection</th><th>Findings</th><th class="num">Damages</th><th>Tenant</th><th></th></tr></thead>
    <tbody>@forelse($inspections as $i)@php $bad = collect($i->items)->whereIn('condition', ['damaged', 'missing']); @endphp
      <tr><td class="small">{{ dmy($i->date) }}</td><td>{{ \App\Models\LeaseInspection::KINDS[$i->kind] }}<div class="tiny muted">{{ $i->user?->name }}</div></td><td class="small">{{ count($i->items) }} items · {{ $bad->count() ? $bad->count().' damaged/missing: '.$bad->pluck('item')->implode(', ') : 'all fine' }}</td>
        <td class="num">{{ (float) $i->damages > 0 ? money($i->damages, false) : '' }}@if($i->charge)<div class="tiny">{{ $i->charge->charge_no }}</div>@endif</td><td class="small">{{ $i->tenant_ack ?: '—' }}</td><td class="num"><a class="btn btn-sm" href="{{ route('leases.inspections.show', $i) }}" target="_blank"><x-icon name="print"/></a></td></tr>
    @empty <tr><td colspan="6"><x-empty text="No inspections yet." icon="check"/></td></tr> @endforelse</tbody></table></div></div>
</div>

<div class="tab-pane" id="tickets">
  <div class="card flush"><div class="card-head"><h3>Maintenance requests for {{ $unit->code }}{{ $unit->room ? ' / room '.$unit->room->room_no : '' }}</h3>@if(can_do('maintenance.manage'))<a class="small" href="{{ route('maintenance.index') }}">Maintenance →</a>@endif</div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Ticket</th><th>Opened</th><th>Issue</th><th>Priority</th><th>Status</th></tr></thead>
    <tbody>@forelse($tickets as $t)
      <tr><td class="bold">{{ $t->ticket_no }}</td><td class="small">{{ $t->created_at->format('d/m/Y') }}</td><td>{{ $t->title }}<div class="tiny muted">{{ $t->category }}{{ $t->location ? ' · '.$t->location : '' }}</div></td><td class="small">{{ $t->priority }}</td><td>{!! status_badge($t->status) !!}</td></tr>
    @empty <tr><td colspan="5"><x-empty text="No maintenance requests during this lease." icon="tool"/></td></tr> @endforelse</tbody></table></div></div>
  <p class="tiny muted">Log a request with the unit code (e.g. {{ $unit->code }}) in the location, or against the linked hotel room, to see it here.</p>
</div>

{{-- ───── modals ───── --}}
@php $modeOpts = $modes->map(fn ($m) => '<option value="'.$m->id.'">'.e($m->name).'</option>')->implode(''); @endphp
@if(can_do('leases.billing'))
<div class="modal" id="payModal"><div class="modal-box"><form method="post" action="{{ route('leases.pay', $l) }}" data-reset>@csrf
  <div class="modal-head"><h3>Receive money – {{ $l->lease_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label for="p_kind">For</label><select name="kind" id="p_kind"><option value="payment">Rent &amp; charges (receipt)</option><option value="deposit">Security deposit</option></select></div>
    <div class="field"><label for="p_amount">Amount received (GH₵) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="amount" id="p_amount" required></div>
    <div class="field"><label for="p_mode">Mode <span class="req">*</span></label><select name="payment_mode_id" id="p_mode" required>{!! $modeOpts !!}</select></div>
    <div class="field"><label for="p_ref">Reference</label><input name="reference" id="p_ref" maxlength="80" placeholder="Bank transfer / MoMo ID / cheque no."></div>
    @if((float) $l->wht_pct > 0)
    <div class="field"><label for="p_wht">Tax withheld by the tenant (GH₵)</label><input type="number" step="0.01" min="0" name="wht_amount" id="p_wht"></div>
    <div class="field"><label for="p_cert">WHT certificate no.</label><input name="wht_cert_no" id="p_cert" maxlength="40"></div>
    @endif
  </div>
  <p class="small">Balance due: <b>{{ money(max(0, $balance)) }}</b>@if($deposit < (float) $l->deposit_amount) · deposit outstanding <b>{{ money((float) $l->deposit_amount - $deposit) }}</b>@endif</p>
  @if((float) $l->wht_pct > 0)<p class="tiny muted">{{ $l->company?->name ?? 'The tenant' }} withholds {{ rtrim(rtrim(number_format($l->wht_pct, 2), '0'), '.') }}% of rent and pays it to GRA; the amount withheld also settles the account and is booked to 1315 WHT recoverable.</p>@endif</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="paySave">Receive</button></div>
</form></div></div>

<div class="modal" id="chargeModal"><div class="modal-box"><form method="post" action="{{ route('leases.charge', $l) }}" data-reset>@csrf
  <div class="modal-head"><h3>Add a charge</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Type</label><select name="type"><option value="other">Other charge</option><option value="damage">Damage</option><option value="electricity">Electricity</option><option value="water">Water</option></select></div>
    <div class="field"><label>Amount, excl. tax (GH₵) <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="amount" required></div>
    <div class="field span-2"><label>Description <span class="req">*</span></label><input name="description" maxlength="160" required placeholder="Replacement access card / extra cleaning"></div>
  </div><p class="tiny muted">Tax at the lease's rate ({{ rtrim(rtrim(number_format(\App\Services\LeaseBillingService::rateFor($l->tax_class), 3), '0'), '.') }}%) is added.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="chargeSave">Add charge</button></div>
</form></div></div>
@endif

@if(can_do('leases.approve'))
<div class="modal" id="refundModal"><div class="modal-box"><form method="post" action="{{ route('leases.refund', $l) }}" data-reset>@csrf
  <div class="modal-head"><h3>Refund</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Refund of</label><select name="kind"><option value="refund">Credit balance ({{ money(max(0, -$balance)) }})</option><option value="deposit_refund">Security deposit ({{ money($deposit) }})</option></select></div>
    <div class="field"><label>Amount (GH₵) <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="amount" required></div>
    <div class="field"><label>Mode <span class="req">*</span></label><select name="payment_mode_id" required>{!! $modeOpts !!}</select></div>
    <div class="field"><label>Reference</label><input name="reference" maxlength="80"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="refundSave">Pay out</button></div>
</form></div></div>

<div class="modal" id="applyModal"><div class="modal-box"><form method="post" action="{{ route('leases.apply', $l) }}" data-reset>@csrf
  <div class="modal-head"><h3>Apply deposit to the account</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Amount (GH₵) <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="amount" required></div>
    <div class="field"><label>Note</label><input name="note" maxlength="255" placeholder="Tenant agreed to offset arrears"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Apply</button></div>
</form></div></div>

<div class="modal" id="creditModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Credit note</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Net amount (GH₵) <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="amount" required></div>
    <div class="field"><label>Reason <span class="req">*</span></label><input name="reason" maxlength="200" required placeholder="Rent-free days – AC out of order"></div>
  </div><p class="tiny muted">Tax is credited at the original invoice's rate.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="creditSave">Issue credit note</button></div>
</form></div></div>

<div class="modal" id="cancelChargeModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Cancel charge</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason <span class="req">*</span></label><input name="reason" maxlength="255" required></div><p class="tiny muted">Same-day corrections only – later, issue a credit note. Cancelling a rent invoice also cancels its service charge and re-opens the period for billing.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel charge</button></div>
</form></div></div>

<div class="modal" id="voidModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Void receipt</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason <span class="req">*</span></label><input name="reason" maxlength="255" required placeholder="Cheque returned unpaid"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Void</button></div>
</form></div></div>

@if($settlement)
<div class="modal" id="settleModal"><div class="modal-box"><form method="post" action="{{ route('leases.settle', $l) }}">@csrf
  <div class="modal-head"><h3>Deposit settlement – {{ $l->lease_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <table class="table" id="settleTable"><tbody>
      <tr><td>Deposit held</td><td class="num">{{ money($settlement['held']) }}</td></tr>
      <tr><td>Balance on the account (final rent, utilities, damages, less credits)</td><td class="num">{{ money($settlement['balance']) }}</td></tr>
      <tr><td>Deposit applied to the account</td><td class="num">− {{ money($settlement['apply']) }}</td></tr>
      <tr class="bold"><td>Deposit to refund</td><td class="num">{{ money($settlement['refund']) }}</td></tr>
      @if($settlement['due'] > 0)<tr class="text-red bold"><td>Still owed by the tenant</td><td class="num">{{ money($settlement['due']) }}</td></tr>@endif
    </tbody></table>
    @if($settlement['credit'] > 0)<tr><td>Account credit (unused rent, overpayments) – refunded too</td><td class="num">{{ money($settlement['credit']) }}</td></tr>@endif
    @if($settlement['total_refund'] > 0)<p class="bold mt-1">Total to refund: {{ money($settlement['total_refund']) }}</p><div class="form-grid mt-1"><div class="field"><label>Refund by <span class="req">*</span></label><select name="payment_mode_id" required>{!! $modeOpts !!}</select></div><div class="field"><label>Reference</label><input name="reference" maxlength="80"></div></div>@endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="settleSave">Settle &amp; close</button></div>
</form></div></div>
@endif
@endif

@if(can_do('leases.manage'))
<div class="modal" id="signModal"><div class="modal-box"><form method="post" action="{{ route('leases.sign', $l) }}">@csrf
  <div class="modal-head"><h3>Signed agreement</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Signed for the tenant by <span class="req">*</span></label><input name="signed_by" value="{{ $l->signed_by ?? $l->tenant_name }}" maxlength="120" required></div><p class="tiny muted">File the signed paper copy – stamp duty applies to leases under the Stamp Duty Act 2005 (Act 689).</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="signSave">Record</button></div>
</form></div></div>

<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('leases.cancel', $l) }}">@csrf
  <div class="modal-head"><h3>Cancel draft lease</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason <span class="req">*</span></label><input name="reason" maxlength="255" required></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel lease</button></div>
</form></div></div>

<div class="modal" id="moveInModal"><div class="modal-box" style="max-width:860px"><form method="post" action="{{ route('leases.activate', $l) }}">@csrf
  <div class="modal-head"><h3>Move in – {{ $unit->code }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="form-grid">
      @foreach(['electricity' => 'elec_mode', 'water' => 'water_mode'] as $m => $mode)@if($l->{$mode} === 'metered')
        <div class="field"><label>Opening {{ strtolower(\App\Models\LeaseMeterReading::METERS[$m][0]) }} reading ({{ \App\Models\LeaseMeterReading::METERS[$m][1] }}) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="reading_{{ $m }}" required value="{{ $lastReadings[$m]?->reading }}"></div>
      @endif @endforeach
    </div>
    <p class="small">The first {{ strtolower(\App\Models\Lease::CYCLES[$l->cycle_months]) }} invoice (from {{ dmy($l->start_date) }}) is raised on move-in.</p>
    <label class="check mb-1"><input type="checkbox" name="with_inspection" value="1" checked> <span>Record the move-in inspection (condition report)</span></label>
    @include('leases._items', ['checklist' => $checklist, 'charges' => false])
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="moveInSave">Move in</button></div>
</form></div></div>

<div class="modal" id="noticeModal"><div class="modal-box"><form method="post" action="{{ route('leases.notice', $l) }}">@csrf
  <div class="modal-head"><h3>Record notice</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Given by</label><select name="by"><option value="tenant">Tenant</option><option value="landlord">Landlord / hotel</option></select></div>
    <div class="field"><label>Notice date</label><input type="date" name="notice_on" value="{{ $today }}" max="{{ $today }}"></div>
    <div class="field"><label>Move-out date <span class="req">*</span></label><input type="date" name="move_out_on" value="{{ min($minNotice, $l->end_date->toDateString()) }}" max="{{ $l->end_date->toDateString() }}" required></div>
    <div class="field"><label>Reason <span class="req">*</span></label><input name="reason" maxlength="255" required placeholder="Relocating / contract ended"></div>
  </div><p class="tiny muted">{{ $l->notice_days }} days' notice required (earliest move-out {{ dmy($minNotice) }}); shorter notice needs a manager with lease approval rights.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="noticeSave">Record notice</button></div>
</form></div></div>

<div class="modal" id="renewModal"><div class="modal-box"><form method="post" action="{{ route('leases.renew', $l) }}">@csrf
  <div class="modal-head"><h3>Renew {{ $l->lease_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Extend by (months) <span class="req">*</span></label><input type="number" name="months" min="1" max="60" value="12" required></div>
    <div class="field"><label>New rent / month (optional)</label><input type="number" step="0.01" min="1" name="rent" placeholder="{{ $l->rent }}"></div>
  </div><p class="small">The term continues from {{ dmy($l->end_date->copy()->addDay()) }}. A new rent applies to periods not yet invoiced{{ $l->billed_to ? ' (after '.dmy($l->billed_to).')' : '' }}.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="renewSave">Renew</button></div>
</form></div></div>

<div class="modal" id="moveOutModal"><div class="modal-box" style="max-width:900px"><form method="post" action="{{ route('leases.moveout', $l) }}">@csrf
  <div class="modal-head"><h3>Move out – {{ $unit->code }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="form-grid">
      <div class="field"><label>Move-out date <span class="req">*</span></label><input type="date" name="date" value="{{ min($today, $l->lastDay()->toDateString()) }}" max="{{ $today }}" required></div>
      <div class="field"><label>Tenant's acknowledgement</label><input name="tenant_ack" maxlength="120" placeholder="Name of the tenant who signed the report"></div>
      @foreach(['electricity' => 'elec_mode', 'water' => 'water_mode'] as $m => $mode)@if($l->{$mode} === 'metered')
        <div class="field"><label>Closing {{ strtolower(\App\Models\LeaseMeterReading::METERS[$m][0]) }} reading <span class="req">*</span></label><input type="number" step="0.01" min="{{ $lastReadings[$m]?->reading ?? 0 }}" name="reading_{{ $m }}" required><span class="hint">last {{ $lastReadings[$m] ? number_format($lastReadings[$m]->reading, 2).' on '.dmy($lastReadings[$m]->date) : '—' }}</span></div>
      @endif @endforeach
    </div>
    <p class="small">Rent and service charge already invoiced beyond the move-out date are credited day by day; a short final period is billed pro rata. Metered use is recharged and damages below are charged. Settle the deposit next.</p>
    <h4 class="mt-1">Move-out inspection</h4>
    @include('leases._items', ['checklist' => $checklist, 'charges' => true])
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="moveOutSave">Record move-out</button></div>
</form></div></div>

<div class="modal" id="inspectModal"><div class="modal-box" style="max-width:900px"><form method="post" action="{{ route('leases.inspect', $l) }}">@csrf
  <div class="modal-head"><h3>Inspection – {{ $unit->code }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="form-grid"><div class="field"><label>Kind</label><select name="kind"><option value="periodic">Periodic</option><option value="move_in">Move-in (late record)</option></select></div>
      <div class="field"><label>Tenant present / acknowledged</label><input name="tenant_ack" maxlength="120"></div>
      <div class="field span-2"><label>Notes</label><input name="notes" maxlength="500"></div></div>
    @include('leases._items', ['checklist' => $checklist, 'charges' => true])
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="inspectSave">Save inspection</button></div>
</form></div></div>
@endif
@endsection
