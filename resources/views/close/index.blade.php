@extends('layouts.app')
@section('title', 'Period Close')
@section('crumbs') Accounts / Period close @endsection
@php
  $CP = \App\Models\ClosePeriod::class;
  $canApprove = can_do('close.approve');
  $nApprovals = $approvals['periods']->count() + $approvals['reopens']->count() + $approvals['accruals']->count() + $approvals['recurring']->count();
@endphp
@section('actions')
  <a class="btn" href="{{ route('close.accruals') }}"><x-icon name="layers"/> Accruals &amp; prepayments</a>
  <a class="btn" href="{{ route('close.recurring') }}"><x-icon name="refresh"/> Recurring journals</a>
  <a class="btn" href="{{ route('close.yearend') }}"><x-icon name="calendar"/> Year end</a>
  @if($focus)<a class="btn btn-primary" id="focusBtn" href="{{ route('close.show', $focus->period) }}"><x-icon name="check"/> Work on {{ $focus->label() }}</a>@endif
@endsection
@section('content')
@if($overdue->count())
  <div class="alert alert-warn" id="overdueAlert"><x-icon name="alert"/> Close overdue ({{ $cfg['due_days'] }} days after month end): <b>{{ $overdue->map(fn ($p) => $p->label())->implode(', ') }}</b>.</div>
@endif

<div class="stats">
  <x-stat label="Books locked up to" :value="$lock ? dmy($lock) : 'Not locked'" icon="lock" :tone="$lock ? 'green' : 'amber'" :sub="$lastClosed ? 'last closed: '.$lastClosed->label() : 'no period closed yet'"/>
  <x-stat label="Current close" :value="$focus ? $focus->label() : 'Up to date'" icon="check" tone="blue" :sub="$focus ? $CP::STATUSES[$focus->status].' · '.($focus->cleared_count ?? 0).' / '.($focus->tasks_count ?? 0).' cleared' : 'every ended month is closed'"/>
  <x-stat label="Waiting for approval" :value="$nApprovals" icon="shield" :tone="$nApprovals ? 'amber' : 'green'" :sub="$approvals['periods']->count().' period(s) · '.($approvals['accruals']->count() + $approvals['recurring']->count()).' schedule(s)'"/>
  <x-stat label="Prepaid / accrued / deferred" :value="money($prepaid)" icon="layers" tone="purple" :sub="'accrued '.money($accrued).' · deferred '.money($deferred)"/>
</div>

<div class="grid g-2-1 mt-1">
<div>
  <div class="card flush"><div class="card-head"><h3><x-icon name="calendar"/> Accounting periods</h3><span class="small muted">first managed period {{ \Illuminate\Support\Carbon::parse($cfg['start'].'-01')->format('M Y') }} · fiscal year starts {{ \Illuminate\Support\Carbon::create(2000, \App\Services\BudgetService::cfg()['fy_start'], 1)->format('F') }}</span></div>
  <div class="card-body table-wrap"><table class="table" id="periodTable">
    <thead><tr><th>Period</th><th>Fiscal</th><th>Status</th><th style="width:22%">Checklist</th><th>Prepared</th><th>Closed</th><th class="num">Days to close</th></tr></thead>
    <tbody>@foreach($periods as $p) @php $tot = $p->tasks_count ?? 0; $clr = $p->cleared_count ?? 0; $pct = $tot ? round($clr / $tot * 100) : 0; $ended = $p->end()->toDateString() < bdate()->toDateString(); @endphp
      <tr data-period="{{ $p->period }}" data-status="{{ $p->status }}">
        <td><a class="bold" href="{{ route('close.show', $p->period) }}">{{ $p->label() }}</a>@if($p->isYearEnd()) <span class="badge badge-purple" title="Closing this month posts the year-end closing entry">year end</span>@endif @if(! $ended)<div class="tiny muted">month in progress</div>@endif</td>
        <td class="small">{{ \App\Services\BudgetService::fyLabel($p->fiscal_year) }} · M{{ $p->fiscal_month }}</td>
        <td><span class="badge badge-{{ $CP::TONES[$p->status] }}">{{ $CP::STATUSES[$p->status] }}</span>@if($p->reopen_requested_at) <span class="badge badge-red">reopen requested</span>@endif @if($p->reopen_count)<div class="tiny muted">reopened {{ $p->reopen_count }}×</div>@endif</td>
        <td>@if($tot)<div class="progress" title="{{ $clr }} of {{ $tot }} cleared"><span style="width:{{ $pct }}%"></span></div><div class="tiny muted">{{ $clr }} / {{ $tot }} cleared</div>@else<span class="tiny muted">not started</span>@endif</td>
        <td class="small">@if($p->prepared_at){{ $p->preparer?->name }}<div class="tiny muted">{{ $p->prepared_at->format('d M') }}</div>@else<span class="muted">—</span>@endif</td>
        <td class="small">@if($p->closed_at){{ $p->closer?->name }}<div class="tiny muted">{{ $p->closed_at->format('d M Y') }}</div>@else<span class="muted">—</span>@endif</td>
        <td class="num">{{ $p->closed_at ? max(0, (int) $p->end()->diffInDays($p->closed_at->copy()->startOfDay())) : '' }}</td>
      </tr>@endforeach</tbody>
  </table></div></div>
</div>

<div>
  <div class="card" id="approvalsCard"><div class="card-head"><h3><x-icon name="shield"/> Approvals</h3>@if(! $canApprove)<span class="tiny muted">needs close approval</span>@endif</div><div class="card-body">
    @if(! $nApprovals)<x-empty text="Nothing is waiting for a second pair of eyes." icon="check"/>@endif
    @foreach($approvals['periods'] as $p)<div class="flex justify-between items-center mb-1" data-approval="period"><div><b>{{ $p->label() }}</b> close<div class="tiny muted">prepared by {{ $p->preparer?->name }} · {{ $p->prepared_at?->format('d M H:i') }}</div></div><a class="btn btn-sm btn-primary" href="{{ route('close.show', $p->period) }}">Review</a></div>@endforeach
    @foreach($approvals['reopens'] as $p)<div class="flex justify-between items-center mb-1" data-approval="reopen"><div><b>Reopen {{ $p->label() }}</b><div class="tiny muted">{{ $p->reopenRequester?->name }}: {{ \Illuminate\Support\Str::limit($p->reopen_reason, 70) }}</div></div><a class="btn btn-sm" href="{{ route('close.show', $p->period) }}">Review</a></div>@endforeach
    @foreach($approvals['accruals'] as $a)<div class="flex justify-between items-center mb-1" data-approval="accrual"><div><b>{{ $a->number }}</b> {{ \Illuminate\Support\Str::limit($a->description, 34) }}<div class="tiny muted">{{ \App\Models\Accrual::TYPES[$a->type] }} · {{ money($a->amount) }} · {{ $a->creator?->name }}</div></div><a class="btn btn-sm" href="{{ route('close.accruals.show', $a) }}">Review</a></div>@endforeach
    @foreach($approvals['recurring'] as $r)<div class="flex justify-between items-center mb-1" data-approval="recurring"><div><b>{{ $r->code }}</b> {{ \Illuminate\Support\Str::limit($r->name, 34) }}<div class="tiny muted">{{ \App\Models\RecurringJournal::FREQUENCIES[$r->frequency] }} · {{ money($r->total) }} · {{ $r->creator?->name }}</div></div><a class="btn btn-sm" href="{{ route('close.recurring', ['open' => $r->id]) }}">Review</a></div>@endforeach
  </div></div>

  <div class="card mt-1" id="dueCard"><div class="card-head"><h3><x-icon name="layers"/> Period-end postings</h3><span class="tiny muted">{{ $activeSchedules }} schedules · {{ $activeRecurring }} templates active</span></div><div class="card-body">
    @if($focus && $due->count())
      <p class="small">{{ $due->count() }} journal(s) due for {{ $focus->label() }} – <b>{{ money($due->sum('amount')) }}</b>.</p>
      <ul class="small" style="margin:0 0 .6rem 1rem;padding:0">@foreach($due->take(6) as $d)<li>{{ $d['source'] === 'accrual' ? $d['model']->number : $d['model']->code }} · {{ \Illuminate\Support\Str::limit($d['source'] === 'accrual' ? $d['model']->description : $d['model']->name, 32) }} <span class="muted">{{ \Illuminate\Support\Carbon::parse($d['period'].'-01')->format('M') }}</span> <b class="right">{{ money($d['amount'], false) }}</b></li>@endforeach</ul>
      <a class="btn btn-sm" href="{{ route('close.show', $focus->period) }}#postingsCard">Go to postings</a>
    @else<p class="small muted" style="margin:0">Nothing due{{ $focus ? ' for '.$focus->label() : '' }}. {{ $cfg['auto_post'] ? 'Schedules post automatically when the night audit closes the last day of the month.' : 'Automatic posting at month end is off.' }}</p>@endif
  </div></div>

  <div class="card mt-1" id="yearCard"><div class="card-head"><h3><x-icon name="calendar"/> {{ \App\Services\BudgetService::fyLabel($fy) }} so far</h3><a class="small" href="{{ route('close.yearend', ['fy' => $fy]) }}">Year end ›</a></div><div class="card-body small">
    <div class="flex justify-between"><span>Income</span><b>{{ money($preview['fy_income']) }}</b></div>
    <div class="flex justify-between"><span>Expenses</span><b>{{ money($preview['fy_expense']) }}</b></div>
    <div class="flex justify-between" style="border-top:1px solid var(--c-border);margin-top:.3rem;padding-top:.3rem"><span>Net {{ $preview['fy_income'] - $preview['fy_expense'] >= 0 ? 'profit' : 'loss' }} to date</span><b>{{ money($preview['fy_income'] - $preview['fy_expense']) }}</b></div>
    <p class="tiny muted" style="margin:.5rem 0 0">Closes to retained earnings when {{ \Illuminate\Support\Carbon::parse(\App\Services\CloseService::fyEnd($fy))->format('F Y') }} is approved.</p>
  </div></div>

  @if($canApprove)
  <div class="card mt-1"><div class="card-head"><h3><x-icon name="tool"/> Settings</h3></div><div class="card-body">
    <form method="post" action="{{ route('close.settings') }}" id="closeSettings">@csrf
      <div class="form-grid">
        <div class="field"><label for="csDue">Close due (days after month end)</label><input type="number" min="1" max="60" name="due_days" id="csDue" value="{{ $cfg['due_days'] }}" required></div>
        <div class="field"><label for="csStart">First managed period</label><input type="month" name="start" id="csStart" value="{{ $cfg['start'] }}" @disabled($canClose) title="{{ $canClose ? 'Fixed once a period has been closed' : '' }}"></div>
        <div class="field span-all"><label class="check"><input type="checkbox" name="auto_post" value="1" @checked($cfg['auto_post'])> <span>Post schedules &amp; recurring journals automatically when the night audit closes the month</span></label></div>
        <div class="field span-all"><label for="csManual">Manual checklist tasks <span class="tiny muted">(one per line – “Area | Task”)</span></label><textarea name="manual" id="csManual" rows="4">{{ collect($cfg['manual'])->map(fn ($r) => $r[0].' | '.$r[1])->implode("\n") }}</textarea></div>
      </div>
      <div class="right mt-1"><button class="btn btn-primary btn-sm">Save settings</button></div>
    </form>
  </div></div>
  @endif
</div>
</div>
@endsection
