@extends('layouts.app')
@section('title', 'Period close – '.$p->label())
@section('crumbs') <a href="{{ route('close.index') }}">Period close</a> / {{ $p->label() }} @endsection
@php
  $CP = \App\Models\ClosePeriod::class; $CT = \App\Models\CloseTask::class; $CE = \App\Models\CloseEvent::class;
  $prep = can_do('close.prepare'); $appr = can_do('close.approve'); $me = auth()->id();
  $cleared = $tasks->filter->cleared()->count(); $failed = $tasks->where('status', 'fail')->count(); $waived = $tasks->where('status', 'waived');
  $isLatest = $lastClosed && $lastClosed->id === $p->id;
  $E = $p->end();
@endphp
@section('actions')
  @if($prev)<a class="btn btn-ghost" href="{{ route('close.show', $prev) }}">‹ {{ \Illuminate\Support\Carbon::parse($prev.'-01')->format('M Y') }}</a>@endif
  @if($next)<a class="btn btn-ghost" href="{{ route('close.show', $next) }}">{{ \Illuminate\Support\Carbon::parse($next.'-01')->format('M Y') }} ›</a>@endif
  <a class="btn" href="{{ route('reports.show', ['trial_balance', 'from' => $p->start()->toDateString(), 'to' => $E->toDateString()]) }}"><x-icon name="book"/> Trial balance</a>
  @if($p->status === 'open' && $prep)
    <form method="post" action="{{ route('close.check', $p->period) }}" style="display:inline">@csrf<button class="btn" id="runChecks"><x-icon name="refresh"/> Run checks</button></form>
    <button class="btn btn-primary" data-modal="submitModal" id="submitBtn" @disabled(count($blockers) > 0) title="{{ count($blockers) ? 'Clear the blockers first' : 'Send to an approver' }}"><x-icon name="check"/> Submit for approval</button>
  @endif
  @if($p->status === 'review' && $appr)
    <button class="btn" data-modal="rejectModal" id="rejectBtn">Send back</button>
    <button class="btn btn-primary" data-modal="approveModal" id="approveBtn" @disabled((int) $p->prepared_by === (int) $me) title="{{ (int) $p->prepared_by === (int) $me ? 'Four eyes – you prepared this close' : 'Close the period and lock the books' }}"><x-icon name="lock"/> Approve &amp; close</button>
  @endif
  @if($p->status === 'closed' && $isLatest && $prep && ! $p->reopen_requested_at)<button class="btn" data-modal="reopenReqModal" id="reopenReqBtn">Request reopen</button>@endif
  @if($p->reopen_requested_at && $appr)
    <button class="btn" data-modal="reopenDenyModal" id="reopenDenyBtn">Decline reopen</button>
    <form method="post" action="{{ route('close.reopen.approve', $p->period) }}" style="display:inline" data-confirm="Reopen {{ $p->label() }}? The GL lock moves back and postings dated in the month are allowed again.">@csrf<button class="btn btn-primary" id="reopenApproveBtn" @disabled((int) $p->reopen_requested_by === (int) $me)>Approve reopen</button></form>
  @endif
@endsection
@section('content')
<div class="mb-1"><span class="badge badge-{{ $CP::TONES[$p->status] }}" id="periodStatus">{{ $CP::STATUSES[$p->status] }}</span>
  <span class="small muted">· {{ \App\Services\BudgetService::fyLabel($p->fiscal_year) }}, month {{ $p->fiscal_month }} · {{ dmy($p->start()) }} – {{ dmy($E) }} · checks run {{ $p->checked_at ? $p->checked_at->format('d M H:i') : 'never' }}</span>
  @if($p->prepared_at)<span class="small"> · prepared by <b>{{ $p->preparer?->name }}</b> {{ $p->prepared_at->format('d M H:i') }}</span>@endif
  @if($p->closed_at)<span class="small"> · closed by <b>{{ $p->closer?->name }}</b> {{ $p->closed_at->format('d M Y H:i') }}</span>@endif
</div>

@if($p->status === 'closed')<div class="alert alert-info" id="closedAlert"><x-icon name="lock"/> {{ $p->label() }} is closed and the ledger is locked up to {{ dmy($lock) }}. Corrections post on the first open day.@if($p->close_note) Approver: “{{ $p->close_note }}”@endif @if(! $isLatest && $lastClosed) Only {{ $lastClosed->label() }} (the latest closed period) can be reopened.@endif</div>@endif
@if($p->reopen_requested_at)<div class="alert alert-warn" id="reopenAlert"><x-icon name="alert"/> <b>{{ $p->reopenRequester?->name }}</b> asked to reopen this period on {{ $p->reopen_requested_at->format('d M H:i') }}: “{{ $p->reopen_reason }}”. {{ $appr ? 'Approving moves the GL lock back.' : 'Waiting for an approver.' }}</div>@endif
@if($p->status === 'open' && $p->reject_reason)<div class="alert alert-warn" id="rejectAlert"><x-icon name="alert"/> Sent back by the approver: “{{ $p->reject_reason }}”</div>@endif
@if($p->status === 'review')<div class="alert alert-info"><x-icon name="shield"/> Submitted for approval{{ $p->prepare_note ? ' – “'.$p->prepare_note.'”' : '' }}. {{ $appr ? ((int) $p->prepared_by === (int) $me ? 'You prepared it, so another approver must close it.' : 'Review the checklist and waivers, then approve to close.') : 'Waiting for an approver.' }}</div>@endif
@if($p->status === 'open' && count($blockers))<div class="alert alert-warn" id="blockers"><b>Before this period can be submitted:</b><ul style="margin:.3rem 0 0 1.1rem;padding:0">@foreach($blockers as $b)<li>{{ $b }}</li>@endforeach</ul></div>@endif

<div class="stats">
  <x-stat label="Checklist cleared" :value="$cleared.' / '.$tasks->count()" icon="check" :tone="$cleared === $tasks->count() && $tasks->count() ? 'green' : 'blue'" :sub="$tasks->where('kind', 'auto')->count().' automatic · '.$tasks->where('kind', 'manual')->count().' manual'"/>
  <x-stat label="Failed checks" :value="$failed" icon="alert" :tone="$failed ? 'red' : 'green'" :sub="$tasks->whereIn('status', ['pending'])->count().' not done yet'"/>
  <x-stat label="Waived" :value="$waived->count()" icon="shield" :tone="$waived->count() ? 'purple' : 'slate'" sub="approver reviews every waiver"/>
  <x-stat :label="'Net '.($pnl['net'] >= 0 ? 'profit' : 'loss').' for the month'" :value="money($pnl['net'])" icon="trend" :tone="$pnl['net'] >= 0 ? 'green' : 'red'" :sub="'income '.money($pnl['income']).' · expenses '.money($pnl['expense'])"/>
</div>

<div class="card flush mt-1" id="checklist"><div class="card-head"><h3><x-icon name="check"/> Close checklist</h3><span class="small muted">automatic checks re-run on submit and on approval</span></div><div class="card-body table-wrap"><table class="table">
  <thead><tr><th style="width:9rem">Status</th><th>Area</th><th>Item</th><th>Result / note</th><th></th></tr></thead>
  <tbody>@foreach($tasks as $t)
    <tr data-task="{{ $t->key }}" data-status="{{ $t->status }}">
      <td><span class="badge badge-{{ $CT::TONES[$t->status] }}">{{ $CT::STATUSES[$t->status] }}</span>@if($t->kind === 'manual')<div class="tiny muted">manual</div>@endif</td>
      <td class="small">{{ $t->area }}</td>
      <td class="small bold" style="max-width:20rem">{{ $t->title }}</td>
      <td class="small" style="max-width:28rem">@if($t->result)<div>{{ $t->result }}</div>@endif
        @if($t->note)<div class="tiny"><b>{{ $t->status === 'waived' ? 'Waiver' : 'Note' }}:</b> {{ $t->note }}</div>@endif
        @if($t->done_at)<div class="tiny muted">{{ $t->doer?->name }} · {{ $t->done_at->format('d M H:i') }}</div>@endif</td>
      <td class="nowrap right">@if($p->status === 'open' && $prep)
        @if($t->kind === 'manual' && ! in_array($t->status, ['done', 'waived']))<button class="btn btn-sm btn-primary" data-modal="doneModal" data-title="{{ $t->title }}" data-action="{{ route('close.tasks.done', $t) }}">Done</button>@endif
        @if(in_array($t->status, ['fail', 'warn', 'pending']))<button class="btn btn-sm" data-modal="waiveModal" data-title="{{ $t->title }}" data-action="{{ route('close.tasks.waive', $t) }}">Waive</button>@endif
        @if(in_array($t->status, ['done', 'waived']))<form method="post" action="{{ route('close.tasks.reset', $t) }}" style="display:inline">@csrf<button class="btn btn-sm btn-ghost" title="Undo">Undo</button></form>@endif
      @endif</td>
    </tr>@endforeach</tbody>
</table></div>
@if($p->status === 'open' && $prep)
  <div class="card-body" style="border-top:1px solid var(--c-border)"><form method="post" action="{{ route('close.tasks.add', $p->period) }}" class="flex gap-1 wrap items-end" id="addTaskForm">@csrf
    <div class="field" style="min-width:9rem"><label for="ntArea">Area</label><input name="area" id="ntArea" maxlength="30" placeholder="General"></div>
    <div class="field" style="flex:1;min-width:16rem"><label for="ntTitle">Add a task for this month</label><input name="title" id="ntTitle" maxlength="150" required placeholder="e.g. Confirm the ECG prepaid meter credit"></div>
    <button class="btn">Add task</button></form></div>
@endif
</div>

<div class="grid g-2 mt-1">
  <div class="card flush" id="postingsCard"><div class="card-head"><h3><x-icon name="layers"/> Period-end postings</h3>
    @if($p->status === 'open' && $prep && $due->count())<form method="post" action="{{ route('close.post', $p->period) }}" data-confirm="Post {{ $due->count() }} journal(s) dated {{ dmy($E) }}?">@csrf<button class="btn btn-sm btn-primary" id="postNowBtn">Post {{ $due->count() }} due</button></form>@endif</div>
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Source</th><th>Kind</th><th class="num">Amount</th><th>Journal</th></tr></thead>
    <tbody>@foreach($due as $d)<tr class="row-due" data-due="{{ $d['source'] }}"><td class="small muted">due</td><td class="small">{{ $d['source'] === 'accrual' ? $d['model']->number.' · '.$d['model']->description : $d['model']->code.' · '.$d['model']->name }}@if($d['period'] !== $p->period)<div class="tiny text-red">catch-up for {{ \Illuminate\Support\Carbon::parse($d['period'].'-01')->format('M Y') }}</div>@endif</td><td><span class="badge badge-amber">to post</span></td><td class="num">{{ money($d['amount'], false) }}</td><td></td></tr>@endforeach
    @forelse($postings as $pp)<tr data-posting="{{ $pp->id }}"><td class="small">{{ $pp->date->format('d M') }}</td><td class="small">{{ $pp->sourceLabel() }}</td><td class="small">{{ $pp->kind === 'reverse' ? 'auto-reversal' : 'posting' }}</td><td class="num">{{ money($pp->amount, false) }}</td><td class="small">@if($pp->journal)<a href="{{ route('gl.journals.show', $pp->journal) }}">{{ $pp->journal->journal_no }}</a>@endif</td></tr>
    @empty @if(! $due->count())<tr><td colspan="5"><x-empty text="No schedule or recurring journal falls in this month." icon="layers"/></td></tr>@endif @endforelse</tbody>
  </table></div></div>

  <div>
    @if($p->isYearEnd())
    <div class="card mb-1" id="yearEndCard"><div class="card-head"><h3><x-icon name="calendar"/> Year end – {{ \App\Services\BudgetService::fyLabel($p->fiscal_year) }}</h3><a class="small" href="{{ route('close.yearend', ['fy' => $p->fiscal_year]) }}">Preview ›</a></div><div class="card-body small">
      @if($yearClose)Closing entry <a href="{{ route('gl.journals.show', $yearClose->gl_journal_id ?? 0) }}">{{ $yearClose->journal?->journal_no }}</a> posted {{ $yearClose->closed_at?->format('d M Y') }}: net {{ $yearClose->net >= 0 ? 'profit' : 'loss' }} <b>{{ money(abs($yearClose->net)) }}</b> to retained earnings.
      @else This is the last month of the fiscal year. Approving the close posts the closing entry – every income and expense account is cleared to retained earnings – dated {{ dmy($E) }}. Reopening reverses it.@endif
    </div></div>
    @endif
    <div class="card"><div class="card-head"><h3><x-icon name="clock"/> History</h3></div><div class="card-body">
      @if($p->events->count())<ul class="timeline" id="closeTimeline">@foreach($p->events->take(25) as $ev)<li class="{{ $CE::TONES[$ev->action] ?? '' }}"><div class="small"><b>{{ $CE::LABELS[$ev->action] ?? $ev->action }}</b> <span class="muted">· {{ $ev->user?->name ?? 'System' }} · {{ $ev->created_at?->format('d M H:i') }}</span></div>@if($ev->note)<div class="tiny">{{ $ev->note }}</div>@endif</li>@endforeach</ul>
      @else<x-empty text="Nothing has happened in this period's close yet." icon="clock"/>@endif
    </div></div>
  </div>
</div>

<div class="modal" id="doneModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Mark done</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field span-all"><label for="dnNote">What was done (optional)</label><textarea name="note" id="dnNote" rows="3" maxlength="255" placeholder="e.g. Ageing reviewed – GH₵ 4,200 provision for Asante Events agreed with the GM"></textarea></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="doneSave">Mark done</button></div>
</form></div></div>

<div class="modal" id="waiveModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Waive</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field span-all"><label for="wvNote">Why is it acceptable to close without this? <span class="req">*</span></label><textarea name="note" id="wvNote" rows="3" minlength="5" maxlength="255" required placeholder="e.g. Depreciation for the month to be run with September – immaterial (GH₵ 1,900)"></textarea></div></div><p class="tiny muted">Waivers are shown to the approver and kept with the period.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="waiveSave">Waive</button></div>
</form></div></div>

<div class="modal" id="submitModal"><div class="modal-box"><form method="post" action="{{ route('close.submit', $p->period) }}">@csrf
  <div class="modal-head"><h3>Submit {{ $p->label() }} for approval</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">The automatic checks run again. A second person with close approval then closes the period, which locks the ledger up to {{ dmy($E) }}.</p>
    <div class="form-grid"><div class="field span-all"><label for="sbNote">Note to the approver (optional)</label><textarea name="note" id="sbNote" rows="3" maxlength="255"></textarea></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="submitSave">Submit</button></div>
</form></div></div>

<div class="modal" id="approveModal"><div class="modal-box"><form method="post" action="{{ route('close.approve', $p->period) }}">@csrf
  <div class="modal-head"><h3>Approve &amp; close {{ $p->label() }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">Prepared by {{ $p->preparer?->name }}. {{ $waived->count() ? $waived->count().' item(s) waived: '.$waived->pluck('title')->implode('; ').'.' : 'No waivers.' }} Closing locks the ledger up to {{ dmy($E) }}.@if($p->isYearEnd()) <b>This is the fiscal year end – the closing entry to retained earnings is posted.</b>@endif</p>
    <div class="form-grid"><div class="field span-all"><label for="apNote">Approver's note (optional)</label><textarea name="note" id="apNote" rows="2" maxlength="255"></textarea></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="approveSave">Close period</button></div>
</form></div></div>

<div class="modal" id="rejectModal"><div class="modal-box"><form method="post" action="{{ route('close.reject', $p->period) }}">@csrf
  <div class="modal-head"><h3>Send {{ $p->label() }} back</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field span-all"><label for="rjReason">What needs doing? <span class="req">*</span></label><textarea name="reason" id="rjReason" rows="3" minlength="5" maxlength="255" required></textarea></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="rejectSave">Send back</button></div>
</form></div></div>

<div class="modal" id="reopenReqModal"><div class="modal-box"><form method="post" action="{{ route('close.reopen.request', $p->period) }}">@csrf
  <div class="modal-head"><h3>Request to reopen {{ $p->label() }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field span-all"><label for="roReason">Reason <span class="req">*</span></label><textarea name="reason" id="roReason" rows="3" minlength="10" maxlength="255" required placeholder="e.g. Auditors' adjustment: August ECG bill received late – accrue in August"></textarea></div></div><p class="tiny muted">A second person with close approval must approve. Reopening moves the GL lock back to {{ $p->lock_before ? dmy($p->lock_before) : 'the previous month end' }}.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="reopenReqSave">Send request</button></div>
</form></div></div>

<div class="modal" id="reopenDenyModal"><div class="modal-box"><form method="post" action="{{ route('close.reopen.deny', $p->period) }}">@csrf
  <div class="modal-head"><h3>Decline the reopen request</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field span-all"><label for="rdReason">Reason <span class="req">*</span></label><textarea name="reason" id="rdReason" rows="2" minlength="5" maxlength="255" required placeholder="e.g. Post the adjustment in September instead"></textarea></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="reopenDenySave">Decline</button></div>
</form></div></div>
@endsection
