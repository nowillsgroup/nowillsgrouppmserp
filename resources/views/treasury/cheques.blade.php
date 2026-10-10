@extends('layouts.app')
@section('title', 'Cheque Registers')
@section('crumbs') Treasury / Cheques @endsection
@php
  $CR = \App\Models\ChequeReceived::class;
  $CI = \App\Models\ChequeIssued::class;
  $canOp = can_do('treasury.operate');
  $canManage = can_do('treasury.manage');
@endphp
@section('actions')
  @if($canOp)
    <form method="post" action="{{ route('treasury.cheques.sync') }}" class="inline">@csrf<button class="btn" id="syncBtn" title="Capture cheque receipts from vouchers and folios, post post-dated cheques now due, clear banked cheques, pick up cheques presented in the bank reconciliation"><x-icon name="refresh"/> Update register</button></form>
    <button class="btn" data-modal="issueModal" id="issueBtn"><x-icon name="edit"/> Write cheque</button>
    <button class="btn btn-primary" data-modal="receiveModal" id="receiveBtn"><x-icon name="plus"/> Record cheque received</button>
  @endif
@endsection
@section('content')
<div class="stats tr-stats">
  <x-stat label="Post-dated (held)" :value="money($stats['pdc'])" icon="calendar" tone="purple" sub="posted to the ledger on the cheque date"/>
  <x-stat label="In hand – to bank" :value="money($stats['in_hand'])" icon="receipt" tone="amber" sub="Cheques in Hand (1035)"/>
  <x-stat label="Deposited – clearing" :value="money($stats['deposited'])" icon="clock" tone="blue" :sub="'cleared '.\App\Services\TreasuryService::cfg('treasury_clear_days').' day(s) after the bank credit'"/>
  <x-stat label="Written, not presented" :value="money($stats['unpresented'])" icon="edit" tone="slate" :sub="$stats['bounced'].' bounced in the last 90 days'"/>
</div>

<div class="tabs mt-1" data-tabs>
  <button type="button" data-tab="tabReceived" class="{{ $tab === 'received' ? 'active' : '' }}">Received</button>
  <button type="button" data-tab="tabIssued" class="{{ $tab === 'issued' ? 'active' : '' }}">Written</button>
  <button type="button" data-tab="tabBooks" class="{{ $tab === 'books' ? 'active' : '' }}">Cheque books</button>
</div>

<div class="tab-pane {{ $tab === 'received' ? 'active' : '' }}" id="tabReceived">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="receivedTable">
    <thead><tr><th>No.</th><th>Drawer</th><th>Cheque</th><th>Cheque date</th><th>Received</th><th class="num">Amount</th><th>Status</th><th>Deposit</th><th></th></tr></thead>
    <tbody>@forelse($received as $c) @php $stale = in_array($c->status, ['in_hand', 'pdc'], true) && $c->staleOn()->lt(bdate()); @endphp
      <tr data-cheque="{{ $c->id }}" data-status="{{ $c->status }}" class="{{ $c->status === 'bounced' ? 'row-red' : ($stale ? 'row-amber' : '') }}">
        <td>{{ $c->number }}<div class="tiny muted">{{ ['treasury' => 'Treasury', 'voucher' => 'Receipt voucher', 'folio' => 'Guest folio'][$c->source] ?? $c->source }}</div></td>
        <td><b>{{ $c->party() }}</b>@if($c->company)<div class="tiny muted">city ledger</div>@endif</td>
        <td>{{ $c->cheque_no }}<div class="tiny muted">{{ $c->bank_name }}{{ $c->bank_branch ? ' · '.$c->bank_branch : '' }}</div></td>
        <td class="small">{{ $c->cheque_date->format('d M Y') }}@if($stale)<div class="tiny text-red">stale since {{ $c->staleOn()->format('d M') }}</div>@endif</td>
        <td class="small">{{ $c->received_on->format('d M Y') }}</td>
        <td class="num bold">{{ money($c->amount, false) }}</td>
        <td><span class="badge badge-{{ $CR::TONES[$c->status] }}">{{ $CR::STATUSES[$c->status] }}</span>@if($c->bounce_reason)<div class="tiny muted">{{ $c->bounce_reason }}</div>@endif</td>
        <td class="small">@if($c->transfer)<a href="{{ route('treasury.transfers.show', $c->transfer) }}">{{ $c->transfer->number }}</a>@endif</td>
        <td class="right nowrap">@if($canOp)
          @if($c->status === 'pdc' && $c->cheque_date->lte(bdate()))<form method="post" action="{{ route('treasury.cheques.pdc', $c) }}" class="inline">@csrf<button class="btn btn-sm btn-primary">Post receipt</button></form>@endif
          @if($c->status === 'deposited' && $c->transfer?->status === 'completed')<form method="post" action="{{ route('treasury.cheques.clear', $c) }}" class="inline">@csrf<button class="btn btn-sm">Cleared</button></form>@endif
          @if(in_array($c->status, ['deposited', 'cleared'], true) && $c->transfer?->status === 'completed')<button class="btn btn-sm btn-danger" data-modal="bounceModal" data-action="{{ route('treasury.cheques.bounce', $c) }}" data-title="Returned unpaid – {{ $c->party() }} #{{ $c->cheque_no }}">Bounced</button>@endif
          @if(($c->status === 'in_hand' && ! $c->transfer_id && $c->source !== 'folio') || $c->status === 'pdc')<button class="btn btn-sm" data-modal="returnModal" data-action="{{ route('treasury.cheques.return', $c) }}" data-title="{{ $c->status === 'pdc' ? 'Cancel post-dated cheque' : 'Return to drawer' }} – {{ $c->party() }}">{{ $c->status === 'pdc' ? 'Cancel' : 'Return' }}</button>@endif
        @endif</td>
      </tr>
    @empty<tr><td colspan="9"><x-empty text="No cheques received yet." icon="receipt"/></td></tr>@endforelse</tbody>
  </table></div></div>
  <div class="mt-1">{{ $received->links() }}</div>
  <p class="tiny muted">Cheques taken on a guest folio or a city-ledger receipt with the <b>Cheque</b> payment mode join this register automatically. They are banked with a <a href="{{ route('treasury.transfers', ['new' => 1, 'kind' => 'deposit']) }}">deposit</a>. A cheque dated more than {{ $CR::STALE_MONTHS }} months ago is stale and will not be paid.</p>
</div>

<div class="tab-pane {{ $tab === 'issued' ? 'active' : '' }}" id="tabIssued">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="issuedTable">
    <thead><tr><th>Cheque</th><th>Account</th><th>Payee</th><th>Date</th><th class="num">Amount</th><th>For</th><th>Status</th><th></th></tr></thead>
    <tbody>@forelse($issued as $c)
      <tr data-issued="{{ $c->id }}" data-status="{{ $c->status }}">
        <td class="bold">{{ $c->cheque_no }}</td>
        <td class="small">{{ $c->book?->account?->name }}</td>
        <td>{{ $c->payee ?: '—' }}</td>
        <td class="small">{{ $c->cheque_date->format('d M Y') }}</td>
        <td class="num bold">{{ (float) $c->amount ? money($c->amount, false) : '' }}</td>
        <td class="small">@if($c->payment){{ $c->payment->payment_no }} <span class="muted">supplier payment</span>@elseif($c->head){{ $c->head->code }} {{ $c->head->name }}@endif @if($c->memo)<div class="tiny muted">{{ $c->memo }}</div>@endif</td>
        <td><span class="badge badge-{{ $CI::TONES[$c->status] }}">{{ $CI::STATUSES[$c->status] }}</span>@if($c->cleared_on)<div class="tiny muted">{{ $c->cleared_on->format('d M') }}</div>@endif @if($c->void_reason)<div class="tiny muted">{{ $c->void_reason }}</div>@endif</td>
        <td class="right nowrap">@if($canOp && $c->status === 'issued')
          <form method="post" action="{{ route('treasury.cheques.presented', $c) }}" class="inline">@csrf<button class="btn btn-sm">Presented</button></form>
          <button class="btn btn-sm btn-danger" data-modal="voidModal" data-action="{{ route('treasury.cheques.void', $c) }}" data-title="Void / stop cheque {{ $c->cheque_no }}">Void / stop</button>
        @endif</td>
      </tr>
    @empty<tr><td colspan="8"><x-empty text="No cheques written yet." icon="edit"/></td></tr>@endforelse</tbody>
  </table></div></div>
  <div class="mt-1">{{ $issued->links() }}</div>
  <p class="tiny muted">A cheque written for a supplier payment adds no ledger entry of its own – the payment already credited the bank. Other cheques post Dr the chosen account / Cr the bank. Cheques matched to the bank statement in Bank Reconciliation are marked presented automatically; unpresented cheques go stale after 6 months and are written back.</p>
</div>

<div class="tab-pane {{ $tab === 'books' ? 'active' : '' }}" id="tabBooks">
  <div class="card flush"><div class="card-head"><h3><x-icon name="book"/> Cheque books</h3>@if($canManage)<button class="btn btn-sm btn-primary" data-modal="bookModal" id="bookBtn"><x-icon name="plus"/> Register book</button>@endif</div>
  <div class="card-body table-wrap"><table class="table" id="bookTable">
    <thead><tr><th>Account</th><th>Leaves</th><th>Next</th><th class="num">Left</th><th class="num">Used</th><th>Received</th><th>Status</th><th></th></tr></thead>
    <tbody>@forelse($books as $b)
      <tr data-book="{{ $b->id }}"><td>{{ $b->account?->name }}</td><td>{{ $b->range() }}</td><td>{{ $b->status === 'active' ? $b->fmt($b->next_no) : '' }}</td><td class="num">{{ $b->leavesLeft() }}</td><td class="num">{{ $b->cheques_count }}</td>
        <td class="small">{{ $b->received_on?->format('d M Y') }}</td><td><span class="badge badge-{{ $b->status === 'active' ? 'green' : 'slate' }}">{{ ucfirst($b->status) }}</span></td>
        <td class="right">@if($canOp && $b->status === 'active')<button class="btn btn-sm" data-modal="spoilModal" data-action="{{ route('treasury.cheques.spoil', $b) }}" data-title="Spoil leaf {{ $b->fmt($b->next_no) }}">Spoil next leaf</button>@endif</td></tr>
    @empty<tr><td colspan="8"><x-empty text="No cheque books registered." icon="book"/></td></tr>@endforelse</tbody>
  </table></div></div>
</div>

@if($canOp)
<div class="modal" id="receiveModal"><div class="modal-box" style="max-width:42rem"><form method="post" action="{{ route('treasury.cheques.receive') }}" id="receiveForm">@csrf
  <div class="modal-head"><h3>Record cheque received</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label for="rcCompany">Company (city ledger)</label><select name="company_id" id="rcCompany"><option value="">— not a company: choose an account below —</option>@foreach($companies as $co)<option value="{{ $co->id }}" @selected((int) old('company_id') === $co->id)>{{ $co->name }}</option>@endforeach</select></div>
    <div class="field"><label for="rcDrawer">Drawer (if not a company)</label><input name="drawer" id="rcDrawer" maxlength="150" value="{{ old('drawer') }}"></div>
    <div class="field"><label for="rcHead">Receipt account (if not a company)</label><select name="account_head_id" id="rcHead"><option value="">—</option>@foreach($heads as $h)<option value="{{ $h->id }}">{{ $h->code }} {{ $h->name }}</option>@endforeach</select></div>
    <div class="field"><label for="rcNo">Cheque no.</label><input name="cheque_no" id="rcNo" required maxlength="20" value="{{ old('cheque_no') }}"></div>
    <div class="field"><label for="rcBank">Drawer's bank</label><input name="bank_name" id="rcBank" maxlength="80" value="{{ old('bank_name') }}" list="ghBanks"></div>
    <div class="field"><label for="rcBranch">Bank branch</label><input name="bank_branch" id="rcBranch" maxlength="100" value="{{ old('bank_branch') }}"></div>
    <div class="field"><label for="rcDate">Cheque date</label><input type="date" name="cheque_date" id="rcDate" required value="{{ old('cheque_date', bdate()->toDateString()) }}"><div class="tiny muted">A later date makes it post-dated – nothing is posted until then.</div></div>
    <div class="field"><label for="rcAmt">Amount</label><input type="number" step="0.01" min="0.01" name="amount" id="rcAmt" required value="{{ old('amount') }}"></div>
    <div class="field span-all"><label for="rcNotes">Notes</label><input name="notes" id="rcNotes" maxlength="255"></div>
  </div>
  <datalist id="ghBanks">@foreach(['GCB Bank', 'Ecobank Ghana', 'Absa Bank Ghana', 'Stanbic Bank Ghana', 'Standard Chartered Ghana', 'Fidelity Bank Ghana', 'CalBank', 'Zenith Bank Ghana', 'Access Bank Ghana', 'Republic Bank Ghana', 'Agricultural Development Bank', 'Consolidated Bank Ghana', 'Prudential Bank', 'Societe Generale Ghana', 'UBA Ghana', 'First National Bank Ghana', 'National Investment Bank'] as $b)<option value="{{ $b }}">@endforeach</datalist></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="receiveSave">Save</button></div>
</form></div></div>

<div class="modal" id="bounceModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Returned unpaid</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label for="bnReason">Bank's reason</label><input name="reason" id="bnReason" required minlength="3" maxlength="150" list="bounceReasons"><datalist id="bounceReasons"><option value="Insufficient funds"><option value="Refer to drawer"><option value="Signature differs"><option value="Account closed"><option value="Payment stopped by drawer"><option value="Post-dated / stale"><option value="Amount in words and figures differ"></datalist></div>
    <div class="field"><label for="bnFee">Bank's returned-cheque charge</label><input type="number" step="0.01" min="0" name="fee" id="bnFee" value="0"></div>
    <div class="field"><label for="bnDate">Date</label><input type="date" name="date" id="bnDate" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
  </div><p class="small muted">Takes the amount back out of the bank and re-opens what the drawer owes (city ledger or the original account). The company's credit file is noted.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="bounceSave">Record bounce</button></div>
</form></div></div>

<div class="modal" id="returnModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Return cheque</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label for="rtReason">Reason</label><input name="reason" id="rtReason" required minlength="3" maxlength="150" placeholder="e.g. replaced by bank transfer"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="returnSave">Confirm</button></div>
</form></div></div>

<div class="modal" id="issueModal"><div class="modal-box" style="max-width:42rem"><form method="post" action="{{ route('treasury.cheques.issue') }}" id="issueForm">@csrf
  <div class="modal-head"><h3>Write a cheque</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label for="isBook">Cheque book</label><select name="cheque_book_id" id="isBook" required>@forelse($books->where('status', 'active') as $b)<option value="{{ $b->id }}" data-head="{{ $b->account?->account_head_id }}">{{ $b->account?->name }} – next leaf {{ $b->fmt($b->next_no) }} ({{ $b->leavesLeft() }} left)</option>@empty<option value="">No active cheque book – register one first</option>@endforelse</select></div>
    <div class="field span-all"><label for="isPay">Supplier payment <span class="tiny muted">(paid from the same bank; leave blank for another payment)</span></label><select name="supplier_payment_id" id="isPay"><option value="">— not a supplier payment —</option>@foreach($payments as $p)<option value="{{ $p->id }}" data-head="{{ \App\Services\LedgerService::modeAccount($p->payment_mode_id) }}">{{ $p->payment_no }} · {{ $p->supplier?->name }} · {{ $p->date->format('d M') }} · {{ money($p->amount) }}</option>@endforeach</select></div>
    <div class="field" data-standalone><label for="isPayee">Payee</label><input name="payee" id="isPayee" maxlength="150"></div>
    <div class="field" data-standalone><label for="isAmt">Amount</label><input type="number" step="0.01" min="0.01" name="amount" id="isAmt"></div>
    <div class="field" data-standalone><label for="isHead">Charge to</label><select name="account_head_id" id="isHead"><option value="">—</option>@foreach($heads as $h)<option value="{{ $h->id }}">{{ $h->code }} {{ $h->name }}</option>@endforeach</select></div>
    <div class="field" data-standalone><label for="isDate">Cheque date</label><input type="date" name="cheque_date" id="isDate" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
    <div class="field span-all"><label for="isMemo">Memo</label><input name="memo" id="isMemo" maxlength="255"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="issueSave">Write cheque</button></div>
</form></div></div>

<div class="modal" id="voidModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Void cheque</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label for="vdReason">Reason</label><input name="reason" id="vdReason" required minlength="3" maxlength="255"></div>
    <label class="check small mt-1"><input type="checkbox" name="stop" value="1"> <span>Stop payment – the cheque left the building and the bank has been instructed not to pay it</span></label></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="voidSave">Confirm</button></div>
</form></div></div>

<div class="modal" id="spoilModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Spoil leaf</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label for="spReason">Reason</label><input name="reason" id="spReason" required minlength="3" maxlength="150" placeholder="e.g. misprinted, torn"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Spoil leaf</button></div>
</form></div></div>
@endif

@if($canManage)
<div class="modal" id="bookModal"><div class="modal-box"><form method="post" action="{{ route('treasury.cheques.books') }}" id="bookForm">@csrf
  <div class="modal-head"><h3>Register cheque book</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label for="bkAcct">Bank account</label><select name="treasury_account_id" id="bkAcct" required>@foreach($banks as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
    <div class="field"><label for="bkFirst">First leaf</label><input type="number" min="1" name="first_no" id="bkFirst" required></div>
    <div class="field"><label for="bkLast">Last leaf</label><input type="number" min="1" name="last_no" id="bkLast" required></div>
    <div class="field"><label for="bkDigits">Digits printed</label><input type="number" min="4" max="10" name="digits" id="bkDigits" value="6"></div>
    <div class="field"><label for="bkDate">Received</label><input type="date" name="received_on" id="bkDate" value="{{ bdate()->toDateString() }}"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="bookSave">Register</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>
(function () {
  const f = document.getElementById('issueForm'); if (!f) return;
  const book = f.querySelector('#isBook'), pay = f.querySelector('#isPay');
  const sync = () => {
    const head = book.selectedOptions[0]?.dataset.head;
    [...pay.options].forEach(o => { if (o.value) o.hidden = o.dataset.head !== head; });
    if (pay.selectedOptions[0]?.hidden) pay.value = '';
    f.querySelectorAll('[data-standalone]').forEach(el => el.style.display = pay.value ? 'none' : '');
  };
  book.addEventListener('change', sync); pay.addEventListener('change', sync); sync();
  @if(session('error') && old('cheque_no'))
  document.getElementById('receiveBtn')?.click();
  @endif
})();
</script>
@endpush
