@extends('layouts.app')
@section('title', $f->name)
@section('crumbs') Accounts / <a href="{{ route('petty.index') }}">Petty cash</a> / {{ $f->code }} @endsection
@section('actions')
@if($can && $f->is_active)
<button class="btn btn-primary" data-modal="voucherModal" id="voucherBtn"><x-icon name="receipt"/> Voucher</button>
<button class="btn" data-modal="iouModal" id="iouBtn"><x-icon name="user"/> IOU</button>
<button class="btn" data-modal="countModal" id="countBtn"><x-icon name="money"/> Count cash</button>
@if(! $st['open_repl'] && $st['top_up'] > 0)<button class="btn" data-modal="replModal" id="replBtn"><x-icon name="refresh"/> Request top-up</button>@endif
@endif
@if(can_do('petty.approve'))<button class="btn btn-ghost" data-modal="editFloatModal" id="editFloatBtn" title="Float settings"><x-icon name="edit"/></button>@endif
@endsection
@section('content')
@if(! $f->is_active)<div class="alert alert-info"><x-icon name="lock"/><div>This float is closed. @if(can_do('petty.approve'))<form method="post" action="{{ route('petty.floats.active', $f) }}" style="display:inline">@csrf<button class="btn btn-sm">Reopen</button></form>@endif</div></div>@endif
@if(! $can && $f->is_active)<div class="alert alert-info"><x-icon name="eye"/><div>View only – {{ $f->custodian?->name }} is the custodian of this float.</div></div>@endif
@if($st['overdue_ious'])<div class="alert alert-error" id="iouAlert"><x-icon name="alert"/><div>{{ $st['overdue_ious'] }} IOU(s) overdue – collect the receipts and change.</div></div>@endif
@if($st['count_due'] && $f->is_active)<div class="alert alert-warn" id="countDue"><x-icon name="clock"/><div>Cash count due: {{ $st['last_count'] ? 'last counted '.dmy($st['last_count']->date) : 'never counted' }} (every {{ $cfg['petty_count_days'] }} days).</div></div>@endif

<div class="stats" id="floatStats">
  <x-stat label="Cash in the tin (book)" :value="money($st['balance'])" icon="wallet" :tone="$st['low'] ? 'red' : 'green'" :sub="$st['pct'].'% of the '.money($f->imprest_amount).' imprest'"/>
  <x-stat label="Available to pay out" :value="money($st['available'])" icon="money" tone="blue" :sub="$st['pending'] > 0 ? money($st['pending']).' waiting for approval' : 'nothing pending'"/>
  <x-stat label="IOUs outstanding" :value="money($st['ious'])" icon="user" :tone="$st['overdue_ious'] ? 'red' : 'slate'" :sub="$st['ious_count'].' open'.($st['overdue_ious'] ? ', '.$st['overdue_ious'].' overdue' : '')"/>
  <x-stat label="Top-up due" :value="money($st['top_up'])" icon="refresh" :tone="$st['open_repl'] ? 'amber' : 'slate'" :sub="$st['open_repl'] ? $st['open_repl']->repl_no.' '.$st['open_repl']->status : $unrepl->count().' receipt(s) since the last top-up'"/>
</div>
<p class="tiny muted">{{ $f->account?->label }} · custodian {{ $f->custodian?->name ?? 'anyone with petty cash rights' }}{{ $f->department ? ' · '.$f->department->name : '' }} · vouchers over {{ money($f->voucher_limit) }} need approval · receipt required above {{ money($cfg['petty_receipt_threshold']) }}</p>

<div data-tabwrap class="mt-1">
<div class="tabs" data-tabs>
  <button class="active" data-tab="book">Cash book</button>
  <button data-tab="pending">Pending ({{ $pending->count() }})</button>
  <button data-tab="ious">IOUs ({{ $st['ious_count'] }} open)</button>
  <button data-tab="counts">Counts</button>
  <button data-tab="repls">Top-ups</button>
  <button data-tab="cancelled">Cancelled</button>
</div>

<div class="tab-pane active" id="book"><div class="card flush">
  <div class="card-head"><h3>Cash book</h3>
    <form class="filters" style="margin:0"><input type="date" name="from" value="{{ $from }}" class="input"> <input type="date" name="to" value="{{ $to }}" class="input"> <button class="btn btn-sm">Show</button></form></div>
  <div class="card-body table-wrap"><table class="table" id="bookTable">
    <thead><tr><th>Date</th><th>No.</th><th>Type</th><th>Paid to / from</th><th>Account</th><th class="num">In</th><th class="num">Out</th><th class="num">Balance</th><th></th></tr></thead>
    <tbody>
      <tr class="muted"><td>{{ dmy($from) }}</td><td colspan="6"><i>Opening balance</i></td><td class="num">{{ money($open, false) }}</td><td></td></tr>
      @forelse($rows as $r)
      @php $e = (float) $r->effect; @endphp
      <tr data-row="{{ $r->txn_no }}"><td class="small">{{ dmy($r->date) }}</td><td class="nowrap"><b>{{ $r->txn_no }}</b></td><td class="small">{{ $r->typeLabel() }}</td>
        <td class="small">{{ $r->party() ?: $r->description }}@if($r->party())<div class="tiny muted">{{ \Illuminate\Support\Str::limit($r->description, 60) }}</div>@endif
          @if($r->type === 'settle')<div class="tiny muted">IOU {{ money($r->amount) }}, spent {{ money($r->spent) }}</div>@endif</td>
        <td class="tiny">{{ $r->head?->label }}@if((float) $r->vat_amount > 0)<div class="muted">VAT {{ money($r->vat_amount, false) }}</div>@endif</td>
        <td class="num">{{ $e > 0 ? money($e, false) : '' }}</td><td class="num">{{ $e < 0 ? money(-$e, false) : '' }}</td><td class="num">{{ money($r->running, false) }}</td>
        <td class="nowrap">@if($r->receipt_path)<a href="{{ route('petty.txn.receipt', $r) }}" target="_blank" class="btn btn-sm btn-ghost" title="Receipt"><x-icon name="eye" size="sm"/></a>@elseif($r->no_receipt_reason)<span class="tiny muted" title="{{ $r->no_receipt_reason }}">no receipt</span>@endif
          @if(in_array($r->type, ['expense', 'advance', 'settle', 'claim_pay']))<a href="{{ route('petty.txn.print', $r) }}" class="btn btn-sm btn-ghost" title="Print voucher"><x-icon name="print" size="sm"/></a>@endif
          @if($can && in_array($r->type, ['expense', 'advance', 'settle']) && ! ($r->type === 'advance' && $r->settled_at) && ! ($r->replenishment && $r->replenishment->status === 'paid'))
          <button class="btn btn-sm btn-ghost" data-modal="voidModal" data-action="{{ route('petty.txn.void', $r) }}" data-title="Cancel {{ $r->txn_no }}" title="Cancel" data-void="{{ $r->txn_no }}"><x-icon name="x" size="sm"/></button>@endif</td></tr>
      @empty<tr><td colspan="9"><x-empty text="No movements in this period." icon="book"/></td></tr>@endforelse
      <tr class="bold"><td>{{ dmy($to) }}</td><td colspan="6">Closing balance</td><td class="num" id="bookClose">{{ money($close, false) }}</td><td></td></tr>
    </tbody></table></div></div></div>

<div class="tab-pane" id="pending"><div class="card flush"><div class="card-body table-wrap"><table class="table" id="pendingTable">
  <thead><tr><th>No.</th><th>Date</th><th>Type</th><th>To</th><th>Purpose</th><th class="num">Amount</th><th>Raised by</th><th></th></tr></thead><tbody>
  @forelse($pending as $t)
    <tr><td><b>{{ $t->txn_no }}</b></td><td class="small">{{ dmy($t->date) }}</td><td class="small">{{ $t->typeLabel() }}</td><td>{{ $t->party() }}</td><td class="small">{{ $t->description }}</td>
      <td class="num">{{ money($t->amount, false) }}</td><td class="small">{{ $t->creator?->name }}</td>
      <td class="nowrap">@if(can_do('petty.approve'))<form method="post" action="{{ route('petty.txn.approve', $t) }}" style="display:inline">@csrf<button class="btn btn-sm btn-primary">Approve</button></form>
        <button class="btn btn-sm" data-modal="reasonModal" data-action="{{ route('petty.txn.reject', $t) }}" data-title="Reject {{ $t->txn_no }}">Reject</button>@else<span class="tiny muted">awaiting approval</span>@endif</td></tr>
  @empty<tr><td colspan="8"><x-empty text="Nothing waiting for approval." icon="check"/></td></tr>@endforelse
  </tbody></table></div></div></div>

<div class="tab-pane" id="ious"><div class="card flush"><div class="card-body table-wrap"><table class="table" id="iouTable">
  <thead><tr><th>IOU</th><th>Issued</th><th>Staff</th><th>Purpose</th><th class="num">Amount</th><th>Due</th><th>Settlement</th><th></th></tr></thead><tbody>
  @forelse($ious as $t)
    @php $late = ! $t->settled_at && $t->due_date && $t->due_date->lt(bdate()); @endphp
    <tr data-iou="{{ $t->txn_no }}"><td><b>{{ $t->txn_no }}</b></td><td class="small">{{ dmy($t->date) }}</td><td>{{ $t->employee?->name }}</td><td class="small">{{ $t->description }}</td>
      <td class="num">{{ money($t->amount, false) }}</td><td class="small {{ $late ? 'text-red' : '' }}">{{ dmy($t->due_date) }}@if($late) · overdue @endif</td>
      <td class="small">@if($t->settlement)spent {{ money($t->settlement->spent) }} · {{ (float) $t->settlement->effect >= 0 ? 'change '.money($t->settlement->effect) : 'extra '.money(-$t->settlement->effect) }}<div class="tiny muted">{{ dmy($t->settlement->date) }}</div>@else<span class="badge badge-amber">open</span>@endif</td>
      <td>@if($can && ! $t->settled_at)<button class="btn btn-sm btn-primary" data-modal="settleModal" data-action="{{ route('petty.txn.settle', $t) }}" data-title="Settle {{ $t->txn_no }} · {{ $t->employee?->name }} · {{ money($t->amount) }}" data-fspent="{{ $t->amount }}" data-settle="{{ $t->txn_no }}">Settle</button>@endif</td></tr>
  @empty<tr><td colspan="8"><x-empty text="No IOUs." icon="user"/></td></tr>@endforelse
  </tbody></table></div></div></div>

<div class="tab-pane" id="counts"><div class="card flush"><div class="card-body table-wrap"><table class="table" id="countTable">
  <thead><tr><th>Count</th><th>Date</th><th class="num">Counted</th><th class="num">Book</th><th class="num">Difference</th><th>Status</th><th>By / witness</th><th>Note</th></tr></thead><tbody>
  @forelse($counts as $c)
    <tr data-count="{{ $c->count_no }}"><td><b>{{ $c->count_no }}</b></td><td class="small">{{ dmy($c->date) }}</td><td class="num">{{ money($c->counted, false) }}</td><td class="num">{{ money($c->book, false) }}</td>
      <td class="num {{ $c->variance < 0 ? 'text-red' : '' }}">{{ money($c->variance, false) }}</td><td>{!! status_badge($c->status) !!}</td>
      <td class="small">{{ $c->counter?->name }}@if($c->witness)<div class="tiny muted">witness {{ $c->witness->name }}</div>@endif</td><td class="small">{{ $c->note }}</td></tr>
  @empty<tr><td colspan="8"><x-empty text="No counts yet." icon="money"/></td></tr>@endforelse
  </tbody></table></div></div></div>

<div class="tab-pane" id="repls"><div class="card flush"><div class="card-body table-wrap"><table class="table" id="replTable">
  <thead><tr><th>Top-up</th><th>Requested</th><th class="num">Amount</th><th>Items</th><th>Status</th><th>Approved</th><th>Paid</th><th></th></tr></thead><tbody>
  @forelse($repls as $r)
    <tr data-repl="{{ $r->repl_no }}"><td><b>{{ $r->repl_no }}</b></td><td class="small">{{ dmy($r->date) }}<div class="tiny muted">{{ $r->requester?->name }}</div></td><td class="num">{{ money($r->amount, false) }}</td>
      <td class="small">{{ $r->txns_count }}</td><td>{!! status_badge($r->status) !!}@if($r->reject_reason)<div class="tiny muted">{{ $r->reject_reason }}</div>@endif</td>
      <td class="small">{{ $r->approver?->name }}</td><td class="small">{{ $r->paid_on ? dmy($r->paid_on).' · '.$r->paymentMode?->name : '—' }}@if($r->reference)<div class="tiny muted">{{ $r->reference }}</div>@endif</td>
      <td><a href="{{ route('petty.repl.print', $r) }}" class="btn btn-sm btn-ghost" title="Print"><x-icon name="print" size="sm"/></a></td></tr>
  @empty<tr><td colspan="8"><x-empty text="No top-ups yet." icon="refresh"/></td></tr>@endforelse
  </tbody></table></div></div></div>

<div class="tab-pane" id="cancelled"><div class="card flush"><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>No.</th><th>Date</th><th>Type</th><th>To</th><th class="num">Amount</th><th>Status</th><th>Reason</th></tr></thead><tbody>
  @forelse($cancelled as $t)
    <tr><td>{{ $t->txn_no }}</td><td class="small">{{ dmy($t->date) }}</td><td class="small">{{ $t->typeLabel() }}</td><td>{{ $t->party() }}</td><td class="num">{{ money($t->amount, false) }}</td>
      <td>{!! status_badge($t->status) !!}</td><td class="small">{{ $t->void_reason ?: $t->reject_reason }}</td></tr>
  @empty<tr><td colspan="7"><x-empty text="Nothing cancelled." icon="x"/></td></tr>@endforelse
  </tbody></table></div></div></div>
</div>

{{-- ───────── modals ───────── --}}
@php $today = bdate()->toDateString(); @endphp
<div class="modal" id="voucherModal"><div class="modal-box"><form method="post" action="{{ route('petty.expense', $f) }}" enctype="multipart/form-data">@csrf
  <div class="modal-head"><h3>Petty cash voucher</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="date" label="Date" type="date" :value="$today" required/>
    <x-field name="amount" label="Amount paid (GH₵)" type="number" step="0.01" min="0.01" required/>
    <x-field name="payee" label="Paid to" required maxlength="120" placeholder="e.g. Makola hardware / taxi driver"/>
    <x-field name="expense_head_id" label="Expense account" type="select" :options="$heads" required/>
  </div>
  <x-field name="description" label="What for" required maxlength="255"/>
  <div class="form-grid">
    <div class="field"><label class="check"><input type="checkbox" name="vat_invoice" value="1" id="f_vat_invoice"> GRA VAT invoice (claim input VAT)</label></div>
    <x-field name="vat_amount" label="VAT + NHIL + GETFund in the receipt" type="number" step="0.01" min="0" placeholder="auto: 20/120 of the amount"/>
    <x-field name="receipt" label="Receipt (photo / PDF)" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf"/>
    <x-field name="receipt_ref" label="Receipt / MoMo no." maxlength="60"/>
  </div>
  <x-field name="no_receipt_reason" label="No receipt? Why" maxlength="255" :hint="'Required above '.money($cfg['petty_receipt_threshold']).' when there is no receipt.'"/>
  <p class="tiny muted">Available {{ money($st['available']) }}. Over {{ money($f->voucher_limit) }} the voucher waits for approval before the cash is paid out.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="voucherSave">Record voucher</button></div>
</form></div></div>

<div class="modal" id="iouModal"><div class="modal-box"><form method="post" action="{{ route('petty.iou', $f) }}">@csrf
  <div class="modal-head"><h3>Issue an IOU (cash advance)</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="employee_id" label="Staff member" type="select" :options="$employees" required/>
    <x-field name="amount" label="Amount (GH₵)" type="number" step="0.01" min="0.01" required/>
    <x-field name="date" label="Date" type="date" :value="$today" required/>
    <x-field name="due_date" label="Due back" type="date" :value="bdate()->copy()->addDays((int) $cfg['petty_iou_days'])->toDateString()"/>
  </div><x-field name="description" label="Purpose" required maxlength="255" placeholder="e.g. buy fresh fish at the market"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="iouSave">Issue IOU</button></div>
</form></div></div>

<div class="modal" id="settleModal"><div class="modal-box"><form method="post" action="#" enctype="multipart/form-data" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Settle IOU</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="spent" label="Spent per receipts (GH₵)" type="number" step="0.01" min="0" required/>
    <x-field name="date" label="Date" type="date" :value="$today" required/>
    <x-field name="expense_head_id" label="Expense account" type="select" :options="$heads"/>
    <x-field name="receipt_ref" label="Receipt / MoMo no." maxlength="60"/>
    <div class="field"><label class="check"><input type="checkbox" name="vat_invoice" value="1"> GRA VAT invoice</label></div>
    <x-field name="receipt" label="Receipt (photo / PDF)" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf"/>
  </div>
  <x-field name="description" label="What was bought" maxlength="255"/>
  <x-field name="no_receipt_reason" label="No receipt? Why" maxlength="255"/>
  <p class="tiny muted">Less than the IOU: the change comes back into the tin. More: the difference is paid out of the float.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="settleSave">Settle</button></div>
</form></div></div>

<div class="modal" id="countModal"><div class="modal-box"><form method="post" action="{{ route('petty.count', $f) }}" id="countForm">@csrf
  <div class="modal-head"><h3>Count the cash – {{ $f->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <table class="table" style="font-size:.85rem"><thead><tr><th>Denomination</th><th style="width:110px">Quantity</th><th class="num">Value</th></tr></thead><tbody>
    @foreach($denoms as $k => [$v, $label])
      <tr><td>{{ $label }}</td><td><input type="number" min="0" name="qty[{{ $k }}]" class="input denom" data-v="{{ $v }}" id="q_{{ $k }}" style="width:100px"></td><td class="num" data-line>0.00</td></tr>
    @endforeach
    <tr class="bold"><td>Total counted</td><td></td><td class="num" id="countTotal">0.00</td></tr>
    <tr><td>Book balance</td><td></td><td class="num" data-book="{{ $st['balance'] }}">{{ money($st['balance'], false) }}</td></tr>
    <tr class="bold"><td>Difference</td><td></td><td class="num" id="countDiff">{{ money(-$st['balance'], false) }}</td></tr>
    </tbody></table>
    <div class="form-grid"><x-field name="witness_id" label="Witness" type="select" :options="$users"/><x-field name="note" label="Explain any difference" maxlength="255"/></div>
    <p class="tiny muted">Differences up to {{ money($cfg['petty_variance_tolerance']) }} are written off at once; larger ones wait for approval.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="countSave">Save count</button></div>
</form></div></div>

<div class="modal" id="replModal"><div class="modal-box"><form method="post" action="{{ route('petty.replenish', $f) }}">@csrf
  <div class="modal-head"><h3>Request a top-up of {{ money($st['top_up']) }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <table class="table" style="font-size:.85rem"><thead><tr><th>No.</th><th>Date</th><th>For</th><th class="num">Amount</th></tr></thead><tbody>
    @foreach($unrepl as $t)<tr><td>{{ $t->txn_no }}</td><td class="small">{{ dmy($t->date) }}</td><td class="small">{{ $t->head?->name ?? $t->typeLabel() }} · {{ $t->party() }}</td><td class="num">{{ money(-$t->effect, false) }}</td></tr>@endforeach
    <tr class="bold"><td colspan="3">Restores the float to {{ money($f->imprest_amount) }} (less IOUs out {{ money($st['ious']) }})</td><td class="num">{{ money($st['top_up'], false) }}</td></tr>
    </tbody></table>
    <x-field name="notes" label="Notes" maxlength="255"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="replSave">Request top-up</button></div>
</form></div></div>

<div class="modal" id="voidModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Cancel</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Why cancel? (the cash must be back in the tin)" required maxlength="255"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="voidSave">Cancel it</button></div>
</form></div></div>

<div class="modal" id="reasonModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Reason</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required maxlength="255"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Confirm</button></div>
</form></div></div>

@if(can_do('petty.approve'))
<div class="modal" id="editFloatModal"><div class="modal-box"><form method="post" action="{{ route('petty.floats.update', $f) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>{{ $f->code }} settings</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="name" label="Name" required maxlength="60" :value="$f->name"/>
    <x-field name="custodian_id" label="Custodian" type="select" :options="$users" :value="$f->custodian_id" placeholder="— anyone with petty cash rights —"/>
    <x-field name="department_id" label="Department" type="select" :options="$departments" :value="$f->department_id"/>
    <x-field name="imprest_amount" label="Imprest (GH₵)" type="number" step="0.01" min="1" required :value="$f->imprest_amount"/>
    <x-field name="voucher_limit" label="Voucher limit (GH₵)" type="number" step="0.01" min="1" required :value="$f->voucher_limit"/>
  </div><x-field name="notes" label="Notes" maxlength="255" :value="$f->notes"/>
  <p class="tiny muted">Raising the imprest: the next top-up includes the increase.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button>
    @if($f->is_active)<button type="submit" class="btn btn-danger" form="closeFloatForm">Close float</button>@endif<button class="btn btn-primary" id="editFloatSave">Save</button></div>
</form>
<form method="post" action="{{ route('petty.floats.active', $f) }}" id="closeFloatForm" data-confirm="Close this float? The balance must be nil.">@csrf</form></div></div>
@endif

<script>
(() => {
  const f = document.getElementById('countForm'); if (!f) return;
  const book = parseFloat(f.querySelector('[data-book]').dataset.book);
  const fmt = n => n.toLocaleString('en-GH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const calc = () => { let t = 0; f.querySelectorAll('.denom').forEach(i => { const v = (parseInt(i.value) || 0) * parseFloat(i.dataset.v); t += v; i.closest('tr').querySelector('[data-line]').textContent = fmt(v); });
    t = Math.round(t * 100) / 100; document.getElementById('countTotal').textContent = fmt(t); const d = Math.round((t - book) * 100) / 100; const el = document.getElementById('countDiff'); el.textContent = fmt(d); el.style.color = Math.abs(d) < 0.005 ? '#15803d' : '#b91c1c'; };
  f.addEventListener('input', calc);
})();
</script>
@endsection
