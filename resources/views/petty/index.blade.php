@extends('layouts.app')
@section('title', 'Petty Cash')
@section('crumbs') Accounts / Petty cash @endsection
@section('actions')
@if(can_do('petty.approve'))<button class="btn btn-primary" data-modal="floatModal" id="newFloatBtn"><x-icon name="plus"/> New float</button>@endif
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['petty_cash_book']) }}"><x-icon name="chart"/> Cash book report</a>@endif
@if(can_do('claims.approve') || can_do('claims.pay'))<a class="btn" href="{{ route('claims.index') }}"><x-icon name="receipt"/> Expense claims</a>@endif
@endsection
@section('content')
@php
  $nApp = $approvals['txns']->count() + $approvals['repl']->count() + $approvals['counts']->count();
  $iousTotal = round((float) $register->sum('ious'), 2);
  $overdueCount = $overdue->count();
  $overdueDays = $overdue->max(fn ($t) => (int) $t->due_date->diffInDays(bdate()));
@endphp

<div class="stats">
  <x-stat label="Cash in the tins" :value="money($tie['tin'])" icon="wallet" tone="amber"
    :sub="$tie['active'].' active float'.($tie['active'] === 1 ? '' : 's').($tie['floats'] > $tie['active'] ? ' · '.($tie['floats'] - $tie['active']).' closed' : '')"
    :href="route('petty.index')"/>
  <x-stat label="IOUs out" :value="money($iousTotal)" icon="receipt" :tone="$overdueCount ? 'red' : 'slate'"
    :sub="$register->sum('ious_count').' IOU'.($register->sum('ious_count') === 1 ? '' : 's').' with staff'.($overdueCount ? ' · '.$overdueCount.' overdue by up to '.$overdueDays.' days' : ' · none overdue')"/>
  <x-stat label="Waiting for approval" :value="$nApp" icon="check" :tone="$nApp ? 'amber' : 'green'"
    :sub="$approvals['txns']->count().' voucher'.($approvals['txns']->count() === 1 ? '' : 's').' · '.$approvals['repl']->count().' top-up'.($approvals['repl']->count() === 1 ? '' : 's').' · '.$approvals['counts']->count().' count difference'.($approvals['counts']->count() === 1 ? '' : 's')"/>
  <x-stat label="Without a receipt" :value="$receipt['missing']" icon="alert" :tone="$receipt['missing'] ? 'red' : 'green'"
    :sub="$receipt['missing'] ? money($receipt['missing_amount']).' spent with nothing behind it' : 'every spend over '.money($receipt['threshold']).' is evidenced'"/>
</div>

{{-- v9.13 the tin against the books: a float posts to its ledger account at the night audit, so a movement dated after the
     last close is an explained difference — anything else is money the ledger cannot see --}}
<div class="card" id="pettyTie">
  <div class="card-head"><h3>Does the ledger agree with the tin?</h3>
    <span class="muted small">{{ $tie['floats'] }} float{{ $tie['floats'] === 1 ? '' : 's' }} · {{ money($tie['tin']) }} in the tins · {{ money($tie['ledger']) }} in the ledger</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="In the tins" :value="money($tie['tin'])" icon="wallet" tone="slate" sub="what the custodians' books say"/>
      <x-stat label="In the ledger" :value="money($tie['ledger'])" icon="book" tone="slate" sub="posted day by day at the night audit"/>
      <x-stat label="Not yet posted" :value="money($tie['waiting'])" icon="clock" tone="blue" sub="movements dated after the last close"/>
      <x-stat label="Unaccounted for" :value="money($tie['unexplained'])" icon="alert" :tone="$tie['unexplained'] ? 'red' : 'green'"
        :sub="$tie['unexplained'] ? 'in neither the ledger nor the queue' : 'the whole difference is the queue'"/>
    </div>
    @if(abs($tie['unexplained']) < 0.005)
      <p class="small" id="pettyTieState" style="margin:.6rem 0 0"><x-icon name="check"/>Every float's difference between the tin and its ledger account is a movement dated after the
        last night audit — {{ money($tie['waiting']) }} still to post. The ledger and the tins describe the same money.</p>
    @else
      <p class="small text-red" id="pettyTieState" style="margin:.6rem 0 0"><x-icon name="alert"/>{{ money($tie['unexplained']) }} of the {{ money($tie['gap']) }} difference is neither posted nor waiting to post:
        @foreach($tie['with_gap'] as $g){{ $g['float']->name }} {{ money($g['unexplained'], false) }} @if(! $loop->last), @endif @endforeach.
        Something moved in a tin without reaching its ledger account — re-run the night audit or find the movement before the tin is counted again.</p>
    @endif
    <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="tinTable">
      <thead><tr><th>Float</th><th class="num">Cash in tin</th><th class="num">Ledger</th><th class="num">Waiting to post</th><th class="num">Unaccounted</th><th>State</th></tr></thead>
      <tbody>
      @foreach($register as $r)<tr data-float="{{ $r['float']->code }}">
        <td class="bold">{{ $r['float']->name }}</td>
        <td class="num">{{ money($r['tin'], false) }}</td>
        <td class="num {{ $r['ledger'] === null ? 'muted' : '' }}">{{ $r['ledger'] === null ? 'no account' : money($r['ledger'], false) }}</td>
        <td class="num {{ $r['waiting'] > 0 ? '' : 'muted' }}">{{ $r['waiting'] == 0 ? '—' : money($r['waiting'], false) }}</td>
        <td class="num {{ abs($r['unexplained']) >= 0.005 ? 'text-red bold' : 'muted' }}">{{ abs($r['unexplained']) < 0.005 ? '—' : money($r['unexplained'], false) }}</td>
        <td>@if(abs($r['unexplained']) < 0.005)<span class="badge badge-green">agrees</span>@else<span class="badge badge-red">breaks</span>@endif</td></tr>@endforeach
      </tbody>
      <tfoot><tr class="bold"><td>Total — {{ $tie['floats'] }} floats</td>
        <td class="num" id="tieSumTin">{{ money($tie['tin'], false) }}</td>
        <td class="num" id="tieSumLedger">{{ money($tie['ledger'], false) }}</td>
        <td class="num" id="tieSumWaiting">{{ money($tie['waiting'], false) }}</td>
        <td class="num" id="tieSumUnexplained">{{ money($tie['unexplained'], false) }}</td><td></td></tr></tfoot>
    </table></div>
  </div>
</div>

{{-- v9.13 the receipt control: petty cash is the easiest place in the hotel to pay for something that cannot be proved --}}
<div class="card" id="receiptTie">
  <div class="card-head"><h3>Is there a receipt behind every cedi out of the tin?</h3>
    <span class="muted small">{{ $receipt['needing'] }} spend{{ $receipt['needing'] === 1 ? '' : 's' }} over {{ money($receipt['threshold']) }} · {{ $receipt['with'] }} on file · {{ $receipt['stated'] }} with a stated reason · {{ $receipt['missing'] }} without either</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Spent over the threshold" :value="money($receipt['amount'])" icon="receipt" tone="slate" :sub="$receipt['needing'].' vouchers and IOU settlements'"/>
      <x-stat label="On file" :value="money($receipt['with_amount'])" icon="check" tone="slate" :sub="$receipt['with'].' with a receipt attached'"/>
      <x-stat label="Stated reason" :value="$receipt['stated']" icon="edit" tone="slate" :sub="'no receipt, but the voucher says why'"/>
      <x-stat label="Without either" :value="money($receipt['missing_amount'])" icon="alert" :tone="$receipt['missing'] ? 'red' : 'green'"
        :sub="$receipt['missing'] ? $receipt['missing'].' spend'.($receipt['missing'] === 1 ? '' : 's').' with nothing behind '.($receipt['missing'] === 1 ? 'it' : 'them') : 'nothing is paid out without evidence'"/>
    </div>
    @if($receipt['missing'] === 0)
      <p class="small" id="receiptTieState" style="margin:.6rem 0 0"><x-icon name="check"/>All {{ $receipt['needing'] }} spends over {{ money($receipt['threshold']) }} carry a receipt or a reason,
        and the {{ $receipt['with'] }} receipts cover {{ money($receipt['with_amount']) }}. Nothing left the tins without something behind it.</p>
    @else
      <p class="small text-red" id="receiptTieState" style="margin:.6rem 0 0"><x-icon name="alert"/>{{ $receipt['missing'] }} spend{{ $receipt['missing'] === 1 ? '' : 's' }} over {{ money($receipt['threshold']) }} with no receipt and no reason
        @foreach($receipt['missing_rows'] as $m)— {{ $m['txn_no'] }} {{ $m['float'] }} {{ money($m['amount'], false) }}{{ $m['age'] !== null ? ' ('.$m['age'].' days old)' : '' }} @if(! $loop->last), @endif @endforeach.
        {{ money($receipt['missing_amount']) }} left the tin and nothing here says what for — attach the receipt, or write the reason on the voucher.</p>
    @endif
    <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="receiptRows">
      <thead><tr><th>Date</th><th class="num">Age</th><th>Float</th><th>Voucher</th><th>Paid to</th><th>Purpose</th><th class="num">Amount</th><th>Evidence</th></tr></thead>
      <tbody>
      @forelse($evidence as $e)<tr data-evidence="{{ $e['evidence'] }}" data-txn="{{ $e['txn_no'] }}">
        <td>{{ dmy($e['date']) }}</td>
        <td class="num {{ $e['age'] > 30 ? 'text-red' : 'muted' }}">{{ $e['age'] }} d</td>
        <td class="small">{{ $e['float'] }}</td>
        <td class="bold">{{ $e['txn_no'] }} @if($e['status'] === 'pending') <span class="tiny muted">pending</span>@endif</td>
        <td class="small">{{ $e['payee'] }}</td>
        <td class="small">{{ $e['head'] }}</td>
        <td class="num">{{ money($e['amount'], false) }}</td>
        <td class="small">@if($e['evidence'] === 'receipt')<span class="badge badge-green">receipt</span>
          @elseif($e['evidence'] === 'reason')<span class="badge badge-slate" title="{{ $e['reason'] }}">reason given</span>
          @else<span class="badge badge-red">nothing</span>@endif</td></tr>
      @empty<tr><td colspan="8"><x-empty text="No spend over the receipt threshold." icon="check"/></td></tr>
      @endforelse
      </tbody></table></div>
    {{ $evidence->links() }}
  </div>
</div>

<div class="card flush" id="floatRegister">
  <div class="card-head"><h3>Floats, and how long since each was counted</h3>
    <span class="muted small">imprest system · counted every {{ $cfg['petty_count_days'] }} days · low below {{ $cfg['petty_low_pct'] }} % of imprest</span></div>
  <div class="card-body table-wrap"><table class="table" id="floatTable">
    <thead><tr><th>Float</th><th>Custodian</th><th class="num">Imprest</th><th class="num">Cash in tin</th><th class="num">IOUs out</th>
      <th class="num">Spend since top-up</th><th class="num">Top-up due</th><th class="num">%</th><th class="num">Last count</th><th>State</th></tr></thead>
    <tbody>
    @forelse($register as $r)<tr data-code="{{ $r['float']->code }}" data-tone="{{ $r['tone'] }}">
      <td><a href="{{ route('petty.show', $r['float']) }}" class="bold">{{ $r['float']->name }}</a>
        <div class="tiny muted">{{ $r['float']->code }}{{ $r['float']->department ? ' · '.$r['float']->department->name : '' }}{{ $r['active'] ? '' : ' · closed' }}</div></td>
      <td class="small">{{ $r['float']->custodian?->name ?? 'anyone with rights' }}</td>
      <td class="num">{{ money($r['imprest'], false) }}</td>
      <td class="num bold">{{ money($r['tin'], false) }}
        @if($r['pending'] > 0)<div class="tiny muted">{{ money($r['pending'], false) }} pending</div>@endif</td>
      <td class="num {{ $r['overdue'] ? 'text-red' : '' }}">{{ $r['ious'] ? money($r['ious'], false) : '—' }}
        @if($r['overdue'])<div class="tiny text-red">{{ $r['overdue'] }} overdue</div>@endif</td>
      <td class="num">{{ $r['spend'] ? money($r['spend'], false) : '—' }}</td>
      <td class="num {{ $r['top_up'] > 0 ? 'bold' : 'muted' }}">{{ $r['top_up'] ? money($r['top_up'], false) : '—' }}</td>
      <td class="num {{ $r['low'] ? 'text-red' : 'muted' }}">{{ $r['pct'] }} %</td>
      <td class="num {{ $r['count_due'] && $r['active'] ? 'text-red' : 'muted' }}">{{ $r['count_age'] === null ? 'never' : $r['count_age'].' d' }}
        @if($r['spend_age'] !== null)<div class="tiny muted">oldest spend {{ $r['spend_age'] }} d</div>@endif</td>
      <td>@php $state = $r['tone'] === 'slate' ? 'Closed' : ($r['low'] ? 'Below '.$cfg['petty_low_pct'].' %' : ($r['overdue'] ? 'IOU overdue' : ($r['pending'] > 0 ? 'Awaiting approval' : ($r['count_due'] ? 'Count due' : 'In order')))); @endphp
        <span class="badge badge-{{ $r['tone'] }}">{{ $state }}</span>
        @if($r['count_due'] && $r['active'] && $r['count_age'] !== null)<div class="tiny muted">count due every {{ $cfg['petty_count_days'] }} days</div>@endif</td></tr>
    @empty<tr><td colspan="10"><x-empty text="No petty cash floats yet. Open one for each cash box (front office, kitchen & stores …)." icon="wallet"/></td></tr>
    @endforelse
    </tbody>
    <tfoot><tr class="bold"><td colspan="2">Total — {{ $tie['floats'] }} floats</td>
      <td class="num" id="regSumImprest">{{ money($tie['floats'] ? $register->sum('imprest') : 0, false) }}</td>
      <td class="num" id="regSumTin">{{ money($tie['tin'], false) }}</td>
      <td class="num" id="regSumIous">{{ money($iousTotal, false) }}</td>
      <td class="num" id="regSumSpend">{{ money($register->sum('spend'), false) }}</td>
      <td class="num" id="regSumTopUp">{{ money($register->sum('top_up'), false) }}</td>
      <td class="num">{{ $register->sum('imprest') > 0 ? round(100 * $tie['tin'] / $register->sum('imprest')) : 0 }} %<div class="tiny muted">of imprest</div></td><td></td><td></td></tr></tfoot>
  </table></div>
</div>
<p class="tiny muted">Imprest system: each float holds a fixed amount. Cash in the tin + IOUs out + spend since the last top-up always equal the imprest; the top-up restores it.
  Red = below {{ $cfg['petty_low_pct'] }} % or an IOU overdue; amber = waiting for approval or a count is due (every {{ $cfg['petty_count_days'] }} days).</p>

<div class="card flush mt-1" id="approvalsCard">
  <div class="card-head"><h3><x-icon name="check"/> Waiting for approval / payment @if($nApp)<span class="badge badge-amber">{{ $nApp }}</span>@endif</h3></div>
  <div class="card-body table-wrap"><table class="table" id="approvalTable">
    <thead><tr><th>Item</th><th>Float</th><th>Details</th><th class="num">Amount</th><th>Raised</th><th></th></tr></thead>
    <tbody>
    @foreach($approvals['txns'] as $t)
      <tr data-txn="{{ $t->txn_no }}"><td><b>{{ $t->txn_no }}</b><div class="tiny muted">{{ $t->typeLabel() }} over the limit</div></td><td class="small">{{ $t->float->name }}</td>
        <td class="small">{{ $t->party() }} · {{ $t->description }} @if($t->head)<div class="tiny muted">{{ $t->head->label }}</div>@endif @if($t->receipt_path)<a class="tiny" href="{{ route('petty.txn.receipt', $t) }}" target="_blank">receipt</a>@endif</td>
        <td class="num">{{ money($t->amount, false) }}</td><td class="small">{{ dmy($t->date) }}<div class="tiny muted">{{ $t->creator?->name }}</div></td>
        <td class="nowrap">@if(can_do('petty.approve'))<form method="post" action="{{ route('petty.txn.approve', $t) }}" style="display:inline">@csrf<button class="btn btn-sm btn-primary" data-approve="{{ $t->txn_no }}">Approve</button></form>
          <button class="btn btn-sm" data-modal="reasonModal" data-action="{{ route('petty.txn.reject', $t) }}" data-title="Reject {{ $t->txn_no }}">Reject</button>@endif</td></tr>
    @endforeach
    @foreach($approvals['repl'] as $r)
      <tr data-repl="{{ $r->repl_no }}"><td><b>{{ $r->repl_no }}</b><div class="tiny muted">Top-up {{ $r->status === 'approved' ? 'to pay' : 'to approve' }}</div></td><td class="small">{{ $r->float->name }}</td>
        <td class="small">Restore to imprest {{ money($r->float->imprest_amount) }} · <a href="{{ route('petty.repl.print', $r) }}">summary</a></td><td class="num">{{ money($r->amount, false) }}</td>
        <td class="small">{{ dmy($r->date) }}<div class="tiny muted">{{ $r->requester?->name }}</div></td>
        <td class="nowrap">@if(can_do('petty.approve'))
          @if($r->status === 'requested')<form method="post" action="{{ route('petty.repl.approve', $r) }}" style="display:inline">@csrf<button class="btn btn-sm btn-primary" data-approve="{{ $r->repl_no }}">Approve</button></form>
          @else<button class="btn btn-sm btn-primary" data-modal="payReplModal" data-action="{{ route('petty.repl.pay', $r) }}" data-title="Pay {{ $r->repl_no }} · {{ money($r->amount) }}" data-pay="{{ $r->repl_no }}">Pay</button>@endif
          <button class="btn btn-sm" data-modal="reasonModal" data-action="{{ route('petty.repl.reject', $r) }}" data-title="Reject {{ $r->repl_no }}">Reject</button>@endif</td></tr>
    @endforeach
    @foreach($approvals['counts'] as $c)
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
  <div class="card-body table-wrap"><table class="table" id="overdueTable"><thead><tr><th>IOU</th><th>Float</th><th>Staff</th><th>Purpose</th><th class="num">Amount</th><th>Due</th></tr></thead><tbody>
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
