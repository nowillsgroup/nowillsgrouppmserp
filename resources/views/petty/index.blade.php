@extends('layouts.app')
@section('title', 'Petty Cash')
@section('crumbs') Accounts / Petty cash @endsection
@section('actions')
@if(can_do('petty.approve'))<button class="btn btn-primary" data-modal="floatModal" id="newFloatBtn"><x-icon name="plus"/> New float</button>@endif
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['petty_cash_book']) }}"><x-icon name="chart"/> Cash book report</a>@endif
@if(can_do('claims.approve') || can_do('claims.pay'))<a class="btn" href="{{ route('claims.index') }}"><x-icon name="receipt"/> Expense claims</a>@endif
@endsection
@section('content')
<div class="stats" id="floatCards">
@forelse($floats as $f)
  @php $s = $stats[$f->id]; $tone = ! $f->is_active ? 'slate' : ($s['low'] || $s['overdue_ious'] ? 'red' : ($s['pending'] > 0 || $s['count_due'] ? 'amber' : 'green')); @endphp
  <div id="float{{ $f->code }}" data-balance="{{ $s['balance'] }}" style="display:contents">
  <x-stat :label="$f->name.($f->is_active ? '' : ' (closed)')" :value="money($s['balance'])" icon="wallet" :tone="$tone" :href="route('petty.show', $f)"
    :sub="'Imprest '.money($f->imprest_amount).' · '.$s['pct'].'% · '.($f->custodian?->name ?? 'no custodian').($s['ious'] > 0 ? ' · IOUs '.money($s['ious']) : '').($s['top_up'] > 0 ? ' · top-up due '.money($s['top_up']) : '')"/></div>
@empty
  <x-empty text="No petty cash floats yet. Open one for each cash box (front office, kitchen & stores …)." icon="wallet"/>
@endforelse
</div>
<p class="tiny muted">Imprest system: each float holds a fixed amount. Cash in the tin + IOUs out + receipts since the last top-up always equal the imprest; the top-up restores it. Red = below {{ $cfg['petty_low_pct'] }}% or an IOU overdue; amber = waiting for approval or a count is due (every {{ $cfg['petty_count_days'] }} days).</p>

@php $a = $approvals; $nApp = $a['txns']->count() + $a['repl']->count() + $a['counts']->count(); @endphp
<div class="card flush mt-1" id="approvalsCard">
  <div class="card-head"><h3><x-icon name="check"/> Waiting for approval / payment @if($nApp)<span class="badge badge-amber">{{ $nApp }}</span>@endif</h3></div>
  <div class="card-body table-wrap"><table class="table" id="approvalTable">
    <thead><tr><th>Item</th><th>Float</th><th>Details</th><th class="num">Amount</th><th>Raised</th><th></th></tr></thead>
    <tbody>
    @foreach($a['txns'] as $t)
      <tr data-txn="{{ $t->txn_no }}"><td><b>{{ $t->txn_no }}</b><div class="tiny muted">{{ $t->typeLabel() }} over the limit</div></td><td class="small">{{ $t->float->name }}</td>
        <td class="small">{{ $t->party() }} · {{ $t->description }}@if($t->head)<div class="tiny muted">{{ $t->head->label }}</div>@endif @if($t->receipt_path)<a class="tiny" href="{{ route('petty.txn.receipt', $t) }}" target="_blank">receipt</a>@endif</td>
        <td class="num">{{ money($t->amount, false) }}</td><td class="small">{{ dmy($t->date) }}<div class="tiny muted">{{ $t->creator?->name }}</div></td>
        <td class="nowrap">@if(can_do('petty.approve'))<form method="post" action="{{ route('petty.txn.approve', $t) }}" style="display:inline">@csrf<button class="btn btn-sm btn-primary" data-approve="{{ $t->txn_no }}">Approve</button></form>
          <button class="btn btn-sm" data-modal="reasonModal" data-action="{{ route('petty.txn.reject', $t) }}" data-title="Reject {{ $t->txn_no }}">Reject</button>@endif</td></tr>
    @endforeach
    @foreach($a['repl'] as $r)
      <tr data-repl="{{ $r->repl_no }}"><td><b>{{ $r->repl_no }}</b><div class="tiny muted">Top-up {{ $r->status === 'approved' ? 'to pay' : 'to approve' }}</div></td><td class="small">{{ $r->float->name }}</td>
        <td class="small">Restore to imprest {{ money($r->float->imprest_amount) }} · <a href="{{ route('petty.repl.print', $r) }}">summary</a></td><td class="num">{{ money($r->amount, false) }}</td>
        <td class="small">{{ dmy($r->date) }}<div class="tiny muted">{{ $r->requester?->name }}</div></td>
        <td class="nowrap">@if(can_do('petty.approve'))
          @if($r->status === 'requested')<form method="post" action="{{ route('petty.repl.approve', $r) }}" style="display:inline">@csrf<button class="btn btn-sm btn-primary" data-approve="{{ $r->repl_no }}">Approve</button></form>
          @else<button class="btn btn-sm btn-primary" data-modal="payReplModal" data-action="{{ route('petty.repl.pay', $r) }}" data-title="Pay {{ $r->repl_no }} · {{ money($r->amount) }}" data-pay="{{ $r->repl_no }}">Pay</button>@endif
          <button class="btn btn-sm" data-modal="reasonModal" data-action="{{ route('petty.repl.reject', $r) }}" data-title="Reject {{ $r->repl_no }}">Reject</button>@endif</td></tr>
    @endforeach
    @foreach($a['counts'] as $c)
      <tr data-count="{{ $c->count_no }}"><td><b>{{ $c->count_no }}</b><div class="tiny muted">Count difference</div></td><td class="small">{{ $c->float->name }}</td>
        <td class="small">Counted {{ money($c->counted) }} vs book {{ money($c->book) }} · {{ $c->note }}</td><td class="num {{ $c->variance < 0 ? 'text-red' : '' }}">{{ money($c->variance, false) }}</td>
        <td class="small">{{ dmy($c->date) }}<div class="tiny muted">{{ $c->counter?->name }}</div></td>
        <td class="nowrap">@if(can_do('petty.approve'))<form method="post" action="{{ route('petty.count.approve', $c) }}" style="display:inline">@csrf<button class="btn btn-sm btn-primary" data-approve="{{ $c->count_no }}">Write off</button></form>
          <button class="btn btn-sm" data-modal="reasonModal" data-action="{{ route('petty.count.reject', $c) }}" data-title="Reject {{ $c->count_no }}">Recount</button>@endif</td></tr>
    @endforeach
    @if(! $nApp)<tr><td colspan="6"><x-empty text="Nothing waiting." icon="check"/></td></tr>@endif
    </tbody></table></div>
</div>

@if($overdue->isNotEmpty())
<div class="card flush mt-2" id="overdueIous"><div class="card-head"><h3><x-icon name="alert"/> Overdue IOUs</h3></div>
  <div class="card-body table-wrap"><table class="table"><thead><tr><th>IOU</th><th>Float</th><th>Staff</th><th>Purpose</th><th class="num">Amount</th><th>Due</th></tr></thead><tbody>
  @foreach($overdue as $t)<tr><td><a href="{{ route('petty.show', $t->petty_float_id) }}#ious" class="bold">{{ $t->txn_no }}</a></td><td class="small">{{ $t->float->name }}</td><td>{{ $t->employee?->name }}</td>
    <td class="small">{{ $t->description }}</td><td class="num">{{ money($t->amount, false) }}</td><td class="text-red small">{{ dmy($t->due_date) }} · {{ (int) $t->due_date->diffInDays(bdate()) }}d late</td></tr>@endforeach
  </tbody></table></div></div>
@endif

<div class="card mt-2"><div class="card-head"><h3><x-icon name="tool"/> Settings</h3></div><div class="card-body">
  @if(can_do('petty.approve'))
  <form method="post" action="{{ route('petty.settings') }}" class="form-grid" id="pettySettings">@csrf
    <div class="field"><label for="f_petty_receipt_threshold">Receipt required above (GH₵)</label><input type="number" step="0.01" min="0" name="petty_receipt_threshold" id="f_petty_receipt_threshold" value="{{ $cfg['petty_receipt_threshold'] }}" required></div>
    <div class="field"><label for="f_petty_iou_days">IOU due after (days)</label><input type="number" min="1" max="60" name="petty_iou_days" id="f_petty_iou_days" value="{{ $cfg['petty_iou_days'] }}" required></div>
    <div class="field"><label for="f_petty_count_days">Count every (days)</label><input type="number" min="1" max="90" name="petty_count_days" id="f_petty_count_days" value="{{ $cfg['petty_count_days'] }}" required></div>
    <div class="field"><label for="f_petty_low_pct">Low-balance alert below (% of imprest)</label><input type="number" min="0" max="100" name="petty_low_pct" id="f_petty_low_pct" value="{{ $cfg['petty_low_pct'] }}" required></div>
    <div class="field"><label for="f_petty_variance_tolerance">Count differences written off automatically up to (GH₵)</label><input type="number" step="0.01" min="0" name="petty_variance_tolerance" id="f_petty_variance_tolerance" value="{{ $cfg['petty_variance_tolerance'] }}" required></div>
    <div><button class="btn btn-primary">Save</button></div>
  </form>
  @else
  <p class="small">Receipts above {{ money($cfg['petty_receipt_threshold']) }} · IOUs due in {{ $cfg['petty_iou_days'] }} days · count every {{ $cfg['petty_count_days'] }} days · auto write-off up to {{ money($cfg['petty_variance_tolerance']) }}.</p>
  @endif
  <p class="tiny muted mt-1">Vouchers over a float's limit, top-ups and larger count differences need a second person with approval rights. Each movement posts to the general ledger at the night audit: vouchers Dr expense (+ input VAT, NHIL &amp; GETFund on a VAT invoice) / Cr the float; IOUs to 1420 Petty Cash IOUs; differences to 6190 Cash Shortages &amp; Overages.</p>
</div></div>

<div class="modal" id="reasonModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Reason</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required maxlength="255"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="reasonSave">Confirm</button></div>
</form></div></div>

<div class="modal" id="payReplModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Pay top-up</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <x-field name="payment_mode_id" label="Paid from" type="select" :options="$modes" required/>
    <x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" required/>
    <x-field name="reference" label="Cheque / transfer / MoMo reference" maxlength="80"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="payReplSave">Pay into float</button></div>
</form></div></div>

@if(can_do('petty.approve'))
<div class="modal" id="floatModal"><div class="modal-box"><form method="post" action="{{ route('petty.floats.store') }}">@csrf
  <div class="modal-head"><h3>Open a petty cash float</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="code" label="Code" required maxlength="20" placeholder="e.g. BAR"/>
    <x-field name="name" label="Name" required maxlength="60" placeholder="e.g. Bar Petty Cash"/>
    <x-field name="custodian_id" label="Custodian (holds the cash)" type="select" :options="$users" placeholder="— anyone with petty cash rights —"/>
    <x-field name="department_id" label="Department" type="select" :options="$departments"/>
    <x-field name="imprest_amount" label="Imprest (GH₵)" type="number" step="0.01" min="1" required/>
    <x-field name="voucher_limit" label="Voucher limit without approval (GH₵)" type="number" step="0.01" min="1" value="500" required/>
    <x-field name="payment_mode_id" label="Funded from" type="select" :options="$modes" required/>
    <x-field name="date" label="Date" type="date" :value="bdate()->toDateString()"/>
  </div><x-field name="notes" label="Notes" maxlength="255"/>
  <p class="tiny muted">A ledger account "Petty Cash – name" is created under the Petty Cash group and the imprest is transferred into it.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="floatSave">Open float</button></div>
</form></div></div>
@endif
@endsection
