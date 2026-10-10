@extends('layouts.app')
@section('title', $t->number)
@section('crumbs') Treasury / <a href="{{ route('treasury.transfers') }}">Banking &amp; transfers</a> / {{ $t->number }} @endsection
@php
  $TT = \App\Models\TreasuryTransfer::class;
  $TA = \App\Models\TreasuryAccount::class;
  $canOp = can_do('treasury.operate');
@endphp
@section('actions')
  @if($canApprove && $t->status === 'requested')
    <form method="post" action="{{ route('treasury.transfers.approve', $t) }}" class="inline">@csrf<button class="btn btn-primary" id="approveBtn"><x-icon name="check"/> Approve</button></form>
    <button class="btn" data-modal="rejectModal" id="rejectBtn">Reject</button>
  @endif
  @if($canOp && $t->status === 'approved')<button class="btn btn-primary" data-modal="dispatchModal" id="dispatchBtn"><x-icon name="swap"/> {{ $t->via_transit ? 'Mark as sent' : 'Confirm credited' }}</button>@endif
  @if($canOp && $t->status === 'in_transit')<button class="btn btn-primary" data-modal="completeModal" id="completeBtn"><x-icon name="check"/> Confirm bank credit</button>@endif
  @if($canOp && $t->open())<button class="btn btn-danger" data-modal="cancelModal" id="cancelBtn">{{ $t->status === 'in_transit' ? 'Recall' : 'Cancel' }}</button>@endif
@endsection
@section('content')
@if($t->status === 'requested')
  <div class="alert alert-warn"><x-icon name="shield"/> {{ money($t->amount) }} is at or above the approval threshold – someone other than {{ $t->requester?->name }} must approve it before it is sent.</div>
@endif
@if($t->status === 'in_transit' && $t->dispatched_on->lt(bdate()->copy()->subDays(2)))
  <div class="alert alert-error"><x-icon name="alert"/> Sent on {{ $t->dispatched_on->format('d M') }} and still not confirmed – check the bank statement.</div>
@endif

<div class="grid g-2-1">
<div>
  <div class="card"><div class="card-head"><h3><x-icon name="swap"/> {{ $TT::KINDS[$t->kind] }}</h3><span class="badge badge-{{ $TT::TONES[$t->status] }}" id="transferStatus">{{ $TT::STATUSES[$t->status] }}</span></div><div class="card-body">
    <dl class="kv">
      <dt>From</dt><dd>{{ $t->from?->name }} <span class="muted small">{{ $t->from?->head?->code }} · {{ $TA::KINDS[$t->from?->kind] ?? '' }}</span></dd>
      <dt>To</dt><dd>{{ $t->to?->name }} <span class="muted small">{{ $t->to?->head?->code }}@if($t->to?->maskedNo()) · {{ $t->to->maskedNo() }}@endif</span></dd>
      <dt>Amount</dt><dd>{{ money($t->amount) }}@if((float) $t->cheque_amount > 0) <span class="muted small">cash {{ money($t->cash_amount) }} + cheques {{ money($t->cheque_amount) }}</span>@endif</dd>
      @if((float) $t->fee > 0)<dt>Fee</dt><dd>{{ money($t->fee) }} <span class="muted small">{{ $t->feeAccount?->name }}</span></dd>@endif
      @if($t->status === 'completed')<dt>Credited</dt><dd>{{ money($t->net()) }}@if(abs((float) $t->variance) >= 0.005) <span class="badge badge-red" id="varianceBadge">{{ $t->variance < 0 ? 'short' : 'over' }} {{ money(abs($t->variance)) }}</span> <span class="small muted">{{ $t->variance_reason }}</span>@endif</dd>@endif
      <dt>Date</dt><dd>{{ $t->date->format('d M Y') }}@if($t->slip_no) · slip {{ $t->slip_no }}@endif</dd>
      <dt>Route</dt><dd>{{ $t->via_transit ? 'Through Cash in Transit (1090)' : 'Credited directly' }}</dd>
      @if($t->notes)<dt>Notes</dt><dd>{{ $t->notes }}</dd>@endif
      @if($t->reason)<dt>{{ $t->status === 'rejected' ? 'Rejected' : 'Cancelled' }}</dt><dd>{{ $t->reason }}</dd>@endif
    </dl>
    @if($t->cash_count)
      <h4 class="mt-1 mb-1 small">Cash count</h4>
      <div class="flex gap-1 wrap small">@foreach($t->cash_count as $den => $pcs)<span class="chip">GH₵{{ rtrim(rtrim((string) $den, '0'), '.') }} × {{ $pcs }}</span>@endforeach</div>
    @endif
  </div></div>

  @if($t->cheques->count())
  <div class="card flush mt-1"><div class="card-head"><h3><x-icon name="receipt"/> Cheques on this deposit</h3></div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Register</th><th>Drawer</th><th>Cheque</th><th>Bank</th><th class="num">Amount</th><th>Status</th></tr></thead>
    <tbody>@foreach($t->cheques as $c)<tr><td>{{ $c->number }}</td><td>{{ $c->party() }}</td><td>{{ $c->cheque_no }}</td><td class="small">{{ $c->bank_name }}</td><td class="num">{{ money($c->amount, false) }}</td><td><span class="badge badge-{{ \App\Models\ChequeReceived::TONES[$c->status] }}">{{ \App\Models\ChequeReceived::STATUSES[$c->status] }}</span></td></tr>@endforeach</tbody>
  </table></div></div>
  @endif

  @foreach(['Sent' => $t->dispatchJournal, ($t->status === 'cancelled' ? 'Recalled' : 'Credited') => $t->completeJournal] as $lbl => $j) @continue(! $j)
  <div class="card flush mt-1" data-journal="{{ $j->id }}"><div class="card-head"><h3><x-icon name="book"/> {{ $lbl }} – {{ $j->journal_no }}</h3><span class="small muted">{{ $j->date->format('d M Y') }}</span></div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Account</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
    <tbody>@foreach($j->lines as $l)<tr><td>{{ $l->account?->code }} {{ $l->account?->name }}</td><td class="small muted">{{ $l->memo }}</td><td class="num">{{ (float) $l->debit ? money($l->debit, false) : '' }}</td><td class="num">{{ (float) $l->credit ? money($l->credit, false) : '' }}</td></tr>@endforeach</tbody>
  </table></div></div>
  @endforeach
</div>

<div>
  <div class="card"><div class="card-head"><h3><x-icon name="clock"/> Trail</h3></div><div class="card-body small">
    <div class="mb-1"><b>Requested</b> by {{ $t->requester?->name }}<div class="tiny muted">{{ $t->created_at->format('d M Y H:i') }}</div></div>
    @if($t->approved_at)<div class="mb-1"><b>{{ $t->status === 'rejected' ? 'Rejected' : 'Approved' }}</b> by {{ $t->approver?->name }}<div class="tiny muted">{{ $t->approved_at->format('d M Y H:i') }}</div></div>
    @elseif(! in_array($t->status, ['requested', 'rejected'], true))<div class="mb-1"><b>Approval</b> not needed <span class="tiny muted">(below {{ money((float) \App\Services\TreasuryService::cfg('treasury_approval_threshold')) }})</span></div>@endif
    @if($t->dispatched_on)<div class="mb-1"><b>Sent</b> {{ $t->dispatched_on->format('d M Y') }} by {{ $t->dispatcher?->name }}</div>@endif
    @if($t->completed_on)<div class="mb-1"><b>{{ $t->status === 'cancelled' ? 'Recalled' : 'Credited' }}</b> {{ $t->completed_on->format('d M Y') }} by {{ $t->completer?->name }}</div>@endif
  </div></div>
</div>
</div>

@if($canApprove && $t->status === 'requested')
<div class="modal" id="rejectModal"><div class="modal-box"><form method="post" action="{{ route('treasury.transfers.reject', $t) }}">@csrf
  <div class="modal-head"><h3>Reject {{ $t->number }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label for="rjReason">Reason</label><input name="reason" id="rjReason" required minlength="3" maxlength="255"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Reject</button></div>
</form></div></div>
@endif
@if($canOp && $t->status === 'approved')
<div class="modal" id="dispatchModal"><div class="modal-box"><form method="post" action="{{ route('treasury.transfers.dispatch', $t) }}">@csrf
  <div class="modal-head"><h3>{{ $t->via_transit ? 'Send' : 'Confirm' }} {{ $t->number }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label for="dsDate">Date</label><input type="date" name="date" id="dsDate" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
    <div class="field"><label for="dsSlip">Deposit slip / reference</label><input name="slip_no" id="dsSlip" value="{{ $t->slip_no }}" maxlength="40"></div>
  </div><p class="small muted">{{ $t->via_transit ? 'Moves '.money($t->amount).' out of '.$t->from?->name.' into Cash in Transit until the bank confirms it.' : 'Posts '.money($t->amount).' from '.$t->from?->name.' to '.$t->to?->name.((float) $t->fee > 0 ? ' less the fee of '.money($t->fee) : '').'.' }}</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="dispatchSave">{{ $t->via_transit ? 'Send' : 'Confirm' }}</button></div>
</form></div></div>
@endif
@if($canOp && $t->status === 'in_transit')
<div class="modal" id="completeModal"><div class="modal-box"><form method="post" action="{{ route('treasury.transfers.complete', $t) }}">@csrf
  <div class="modal-head"><h3>Bank credit for {{ $t->number }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label for="cpDate">Credit date</label><input type="date" name="date" id="cpDate" value="{{ bdate()->toDateString() }}" min="{{ $t->dispatched_on?->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
    <div class="field"><label for="cpRecv">Amount received (before fee)</label><input type="number" step="0.01" min="0" name="received" id="cpRecv" value="{{ number_format((float) $t->amount, 2, '.', '') }}" required></div>
    <div class="field"><label for="cpFee">Bank fee</label><input type="number" step="0.01" min="0" name="fee" id="cpFee" value="{{ number_format((float) $t->fee, 2, '.', '') }}"></div>
    <div class="field"><label for="cpSlip">Bank reference</label><input name="slip_no" id="cpSlip" value="{{ $t->slip_no }}" maxlength="40"></div>
    <div class="field span-all"><label for="cpReason">Reason for any difference <span class="tiny muted">(required if the bank credited more or less than the slip – posted to cash over / short)</span></label><input name="reason" id="cpReason" maxlength="255"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="completeSave">Confirm credit</button></div>
</form></div></div>
@endif
@if($canOp && $t->open())
<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('treasury.transfers.cancel', $t) }}">@csrf
  <div class="modal-head"><h3>{{ $t->status === 'in_transit' ? 'Recall' : 'Cancel' }} {{ $t->number }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">@if($t->status === 'in_transit')<p class="small">The money goes back to {{ $t->from?->name }}@if((float) $t->cheque_amount > 0) and the cheques back into hand @endif.</p>@endif
    <div class="field"><label for="cnReason">Reason</label><input name="reason" id="cnReason" required minlength="3" maxlength="255"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="cancelSave">{{ $t->status === 'in_transit' ? 'Recall' : 'Cancel transfer' }}</button></div>
</form></div></div>
@endif
@endsection
