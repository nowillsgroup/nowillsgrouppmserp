@extends('layouts.app')
@section('title', 'Requisition '.$r->req_no)
@section('crumbs') <a href="{{ route('req.index') }}">Requisitions</a> / {{ $r->req_no }} @endsection
@php
  $u = auth()->user();
  $qf = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
  $short = $r->isOpenForIssue() ? $r->lines->filter(fn ($l) => $l->outstanding() > (float) ($stock[$l->inventory_item_id] ?? 0) + 0.0001) : collect();
  $reqValue = \App\Services\RequisitionService::valueOf($r->lines, 'qty_requested');
  $overIfApproved = $budget['budget'] !== null && $budget['used'] + $reqValue > $budget['budget'] + 0.005;
@endphp
@section('actions')
<span id="reqStatus">{!! $r->statusBadge() !!}</span>
<a class="btn" href="{{ route('req.print', $r) }}" target="_blank"><x-icon name="print"/> Print</a>
@if($canEdit)<a class="btn" href="{{ route('req.edit', $r) }}" id="editReq"><x-icon name="edit"/> Edit</a>
<form method="post" action="{{ route('req.submit', $r) }}" class="inline">@csrf<button class="btn btn-primary" id="submitReq"><x-icon name="check"/> Submit for approval</button></form>@endif
@if($canCancel)<form method="post" action="{{ route('req.cancel', $r) }}" class="inline" onsubmit="return confirm('Cancel {{ $r->req_no }}?')">@csrf<button class="btn btn-ghost text-red" id="cancelReq">Cancel requisition</button></form>@endif
@if(can_do('requisitions.raise') && ! $r->isEditable())<form method="post" action="{{ route('req.repeat', $r) }}" class="inline">@csrf<button class="btn" id="repeatReq" title="New draft with the same items"><x-icon name="refresh"/> Repeat</button></form>@endif
@if($canIssue)<button class="btn btn-danger" data-modal="closeModal" id="closeShort"><x-icon name="x"/> Close short</button>@endif
@endsection
@section('content')
<div class="stats req5">
  <x-stat label="Department" :value="$r->department?->name" icon="users" tone="blue" :sub="$r->department?->hod ? 'HOD '.$r->department->hod->name : 'no HOD set'"/>
  <x-stat :label="$r->approved_at && $r->status !== 'rejected' ? 'Approved value' : 'Requested value'" :value="money($r->est_value)" icon="money" tone="slate" :sub="$r->lines->count().' item(s) · at last cost'"/>
  <x-stat label="Issued" :value="money($r->issuedValue())" icon="box" tone="green" :sub="$r->issues->count().' issue note(s)'"/>
  <x-stat label="Still to issue" :value="$qf($r->outstandingQty()).' units'" icon="clock" :tone="$r->outstandingQty() ? ($short->count() ? 'red' : 'amber') : 'green'" :sub="$short->count() ? $short->count().' item(s) short in '.$r->fromStore?->name : ($r->isOpenForIssue() ? 'all in stock' : null)"/>
  <x-stat label="Needed by" :value="$r->required_by?->format('d M') ?? '—'" icon="calendar" :tone="$r->isOverdue() ? 'red' : 'purple'" :sub="($r->priority === 'urgent' ? 'URGENT · ' : '').'raised '.$r->req_date->format('d M')"/>
</div>

@if($r->status === 'rejected')<div class="alert alert-error"><b>Rejected</b> by {{ $r->approver?->name }} on {{ $r->approved_at?->format('d M Y H:i') }}: {{ $r->rejected_reason }}</div>@endif
@if($r->status === 'closed')<div class="alert alert-warning"><b>Closed short</b> by {{ $r->closer?->name }} on {{ $r->closed_at?->format('d M Y') }}: {{ $r->closed_reason }}</div>@endif
@if($r->over_budget)<div class="alert alert-warning"><b>Approved over budget</b> by {{ $r->approver?->name }}{{ $r->approval_note ? ': '.$r->approval_note : '' }}</div>@endif
@if($approvalBlocker && (can_do('requisitions.approve') || can_do('requisitions.manage')))<div class="alert alert-info" id="approvalInfo">{{ $approvalBlocker }}</div>@endif

<div class="grid g-2-1">
<div>
  @if($canApprove)<form method="post" action="{{ route('req.approve', $r) }}" id="approveForm">@csrf @endif
  @if($canIssue)<form method="post" action="{{ route('req.issue', $r) }}" id="issueForm">@csrf @endif
  <div class="card flush">
    <div class="card-head"><h3>Items</h3><span class="tiny muted">{{ $r->fromStore?->name }} → {{ $r->destination() }}{{ $r->isTransfer() ? ' (transfer)' : ' (expensed)' }}</span></div>
    <div class="card-body table-wrap"><table class="table" id="reqLines">
      <thead><tr><th>Item</th><th class="num">Requested</th><th class="num">Approved</th><th class="num">Issued</th><th class="num">To issue</th><th class="num">In {{ \Illuminate\Support\Str::limit($r->fromStore?->name, 14) }}</th>@if($r->to_store_id)<th class="num">In {{ \Illuminate\Support\Str::limit($r->toStore?->name, 14) }}</th>@endif<th class="num">Value</th>@if($canApprove)<th class="num">Approve</th>@endif @if($canIssue)<th class="num">Issue now</th>@endif</tr></thead>
      <tbody>
      @foreach($r->lines as $l)
        @php $have = (float) ($stock[$l->inventory_item_id] ?? 0); $out = $l->outstanding(); @endphp
        <tr>
          <td>{{ $l->item?->name }} <span class="tiny muted">{{ $l->item?->unit }}</span>@if($l->remarks)<div class="tiny muted">{{ $l->remarks }}</div>@endif</td>
          <td class="num">{{ $qf($l->qty_requested) }}</td>
          <td class="num {{ $l->cut() ? 'text-red' : '' }}">{{ $l->qty_approved === null ? '—' : $qf($l->qty_approved) }}</td>
          <td class="num">{{ (float) $l->qty_issued ? $qf($l->qty_issued) : '—' }}</td>
          <td class="num bold">{{ $out ? $qf($out) : '—' }}</td>
          <td class="num small {{ $out > $have + 0.0001 ? 'text-red bold' : 'muted' }}">{{ $qf($have) }}</td>
          @if($r->to_store_id)<td class="num small muted">{{ $qf($toStock[$l->inventory_item_id] ?? 0) }}</td>@endif
          <td class="num small">{{ money($l->allowed() * (float) $l->item?->last_cost, false) }}</td>
          @if($canApprove)<td class="num"><input type="number" name="qty[{{ $l->id }}]" value="{{ $qf($l->qty_requested) }}" min="0" max="{{ $l->qty_requested }}" step="0.01" class="input input-sm" style="width:80px"></td>@endif
          @if($canIssue)<td class="num">@if($out)<input type="number" name="qty[{{ $l->id }}]" value="{{ $qf(max(0, min($out, $have))) }}" min="0" max="{{ $out }}" step="0.01" class="input input-sm" style="width:80px">@endif</td>@endif
        </tr>
      @endforeach
      </tbody>
    </table></div>
    @if($canApprove)
    <div class="card-body approve-bar">
      <div class="field grow"><label>Note to the store / requester</label><input name="approval_note" class="input" maxlength="255" placeholder="{{ $overIfApproved ? 'Reason for approving over budget (required)' : 'optional' }}"></div>
      @if($overIfApproved)<label class="check text-red"><input type="checkbox" name="over_budget_ok" value="1" id="overOk"> Approve over budget</label>@endif
      <button type="button" class="btn btn-danger" data-modal="rejectModal" id="rejectBtn">Reject</button>
      <button class="btn btn-primary" id="approveBtn"><x-icon name="check"/> Approve</button>
    </div>
    @endif
    @if($canIssue)
    <div class="card-body approve-bar">
      <div class="field"><label>Issue date</label><input type="date" name="date" class="input" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}" min="{{ $r->req_date->toDateString() }}" required></div>
      <div class="field grow"><label>Notes</label><input name="notes" class="input" maxlength="255" placeholder="optional – e.g. collected by"></div>
      <button class="btn btn-primary" id="issueBtn"><x-icon name="box"/> Issue</button>
    </div>
    @endif
  </div>
  @if($canApprove || $canIssue)</form>@endif
  @if($short->count() && $r->isOpenForIssue())
    <div class="alert alert-warning flex items-center gap-1" id="shortAlert"><div class="grow"><b>{{ $r->fromStore?->name }} can't cover:</b> {{ $short->map(fn ($l) => $l->item->name.' (needs '.$qf($l->outstanding()).', has '.$qf($stock[$l->inventory_item_id] ?? 0).')')->join(', ') }}. Issue what's there now and the rest later, or close it short.</div>
    @if(can_do('inventory.manage'))<form method="post" action="{{ route('req.purchase', $r) }}" class="inline">@csrf<button class="btn btn-sm" id="raisePo"><x-icon name="receipt"/> Raise purchase order</button></form>@endif</div>
  @endif

  <div class="card flush" id="issueNotes"><div class="card-head"><h3>Issue notes</h3><span class="tiny muted">{{ $r->issues->count() }}</span></div>
    <div class="card-body">
    @forelse($r->issues as $i)
      <div class="issue-note">
        <div class="flex items-center gap-1">
          <div class="grow"><a class="bold" href="{{ route('req.issue.print', $i) }}" target="_blank">{{ $i->issue_no }}</a> <span class="tiny muted">{{ $i->date->format('d M Y') }} · by {{ $i->issuer?->name }}</span></div>
          <b>{{ money($i->value) }}</b>
        </div>
        <div class="small muted">{{ $i->lines->map(fn ($x) => $x->item?->name.' × '.$qf($x->qty))->join(', ') }}</div>
        @if($i->notes)<div class="tiny muted">{{ $i->notes }}</div>@endif
        @if($i->received_at)
          <div class="tiny text-green mt-1"><x-icon name="check"/> Received by {{ $i->receiver?->name }} · {{ $i->received_at->format('d M H:i') }}{{ $i->receipt_note ? ' – '.$i->receipt_note : '' }}</div>
        @elseif(\App\Services\RequisitionService::canAcknowledge($u, $i))
          <form method="post" action="{{ route('req.ack', $i) }}" class="flex gap-1 mt-1 items-center">@csrf<input name="receipt_note" class="input input-sm grow" maxlength="255" placeholder="Anything missing or damaged? (optional)"><button class="btn btn-sm btn-primary" id="ack{{ $i->id }}"><x-icon name="check"/> Acknowledge receipt</button></form>
        @else
          <div class="tiny text-amber mt-1">Waiting for {{ $r->department?->name }} to acknowledge receipt</div>
        @endif
      </div>
    @empty<p class="muted small m-0" style="padding:14px 16px">Nothing issued yet.</p>@endforelse
    </div>
  </div>
</div>

<div>
  <div class="card"><div class="card-head"><h3>{{ $r->department?->name }} budget</h3><span class="tiny muted">{{ bdate()->format('F Y') }}</span></div><div class="card-body">
    @if($budget['budget'] !== null)
      @php $thisV = in_array($r->status, ['submitted', 'draft']) ? $reqValue : 0; $usedPct = min(100, $budget['used'] / $budget['budget'] * 100); $thisPct = max(0, min(100, ($budget['used'] + $thisV) / $budget['budget'] * 100) - $usedPct); @endphp
      <div class="budget-bar"><span class="bb-used" style="width:{{ $usedPct }}%"></span><span class="bb-this {{ $budget['used'] + $thisV > $budget['budget'] ? 'over' : '' }}" style="width:{{ $thisPct }}%"></span></div>
      <ul class="list" id="budgetList">
        <li><span>Monthly budget</span><b>{{ money($budget['budget'], false) }}</b></li>
        <li><span>Issued this month</span><b>{{ money($budget['issued'], false) }}</b></li>
        <li><span>Committed{{ $r->status === 'submitted' ? ' (other requisitions)' : '' }}</span><b>{{ money($budget['committed'], false) }}</b></li>
        @if($thisV)<li><span>This requisition</span><b>{{ money($thisV, false) }}</b></li>@endif
        @php $left = $budget['budget'] - $budget['used'] - $thisV; @endphp
        <li><span>{{ $left < 0 ? 'Over budget by' : 'Left' }}</span><b class="{{ $left < 0 ? 'text-red' : 'text-green' }}">{{ money(abs($left), false) }}</b></li>
      </ul>
    @else<p class="small muted m-0">No monthly stores budget set. Issued this month: {{ money($budget['issued']) }}.</p>@endif
  </div></div>
  <div class="card"><div class="card-head"><h3>History</h3></div><div class="card-body">
    <ul class="timeline">
      <li class="tl-info"><b>Raised</b> by {{ $r->raiser?->name ?? '—' }}<div class="tiny muted">{{ $r->created_at?->format('d M Y H:i') }}{{ $r->purpose ? ' · '.$r->purpose : '' }}</div></li>
      @if($r->submitted_at)<li class="tl-info"><b>Submitted</b> for approval<div class="tiny muted">{{ $r->submitted_at->format('d M Y H:i') }}</div></li>@endif
      @if($r->approved_at)<li class="{{ $r->status === 'rejected' ? 'tl-bad' : 'tl-ok' }}"><b>{{ $r->status === 'rejected' ? 'Rejected' : ($r->approver ? 'Approved' : 'Auto-approved') }}</b>{{ $r->approver ? ' by '.$r->approver->name : '' }}<div class="tiny muted">{{ $r->approved_at->format('d M Y H:i') }}{{ $r->approval_note && $r->status !== 'rejected' ? ' · '.$r->approval_note : '' }}</div></li>@endif
      @foreach($r->issues as $i)<li class="tl-ok"><b>{{ $i->issue_no }}</b> issued – {{ money($i->value) }}<div class="tiny muted">{{ $i->date->format('d M Y') }} · {{ $i->issuer?->name }}{{ $i->received_at ? ' · received '.$i->received_at->format('d M H:i') : '' }}</div></li>@endforeach
      @if(in_array($r->status, ['closed', 'cancelled']))<li class="tl-bad"><b>{{ $r->status === 'closed' ? 'Closed short' : 'Cancelled' }}</b>{{ $r->closer ? ' by '.$r->closer->name : '' }}<div class="tiny muted">{{ $r->closed_at?->format('d M Y H:i') }}</div></li>@endif
      @if($r->turnaroundHours() !== null)<li><span class="tiny muted">Submitted → first issue: {{ $r->turnaroundHours() }} h</span></li>@endif
    </ul>
  </div></div>
</div>
</div>

<div class="modal" id="rejectModal"><div class="modal-box"><form method="post" action="{{ route('req.reject', $r) }}">@csrf
  <div class="modal-head"><h3>Reject {{ $r->req_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="reason" class="input" required maxlength="255" placeholder="e.g. already issued last week – use the floor stock"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger" id="confirmReject">Reject</button></div>
</form></div></div>
<div class="modal" id="closeModal"><div class="modal-box"><form method="post" action="{{ route('req.close', $r) }}">@csrf
  <div class="modal-head"><h3>Close {{ $r->req_no }} short</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="m-0 mb-1">{{ $qf($r->outstandingQty()) }} unit(s) will not be issued. The department can raise a new requisition later.</p>
    <div class="field"><label>Reason</label><input name="reason" class="input" required maxlength="255" placeholder="e.g. item discontinued – bought locally"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger" id="confirmClose">Close short</button></div>
</form></div></div>
@endsection
