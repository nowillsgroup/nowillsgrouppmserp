@extends('layouts.app')
@section('title', 'Year-end Close – '.$label)
@section('crumbs') <a href="{{ route('close.index') }}">Period close</a> / Year end @endsection
@php $CP = \App\Models\ClosePeriod::class; $pv = $preview; $lastMonth = $months->last(); @endphp
@section('actions')
  <form method="get" class="flex gap-1 items-center"><label class="small muted" for="fySel">Fiscal year</label><select name="fy" id="fySel" onchange="this.form.submit()">@foreach($years as $y)<option value="{{ $y }}" @selected($y === $fy)>{{ \App\Services\BudgetService::fyLabel($y) }}</option>@endforeach</select></form>
  <a class="btn" href="{{ route('reports.show', ['gl_pnl', 'from' => $pv['start'], 'to' => $pv['end']]) }}"><x-icon name="chart"/> Profit &amp; loss</a>
@endsection
@section('content')
@if($close)
  <div class="alert alert-info" id="yeClosed"><x-icon name="lock"/> {{ $label }} is closed. Closing entry <a href="{{ route('gl.journals.show', $close->gl_journal_id ?? 0) }}"><b>{{ $close->journal?->journal_no ?? '—' }}</b></a> dated {{ dmy($close->closing_date) }} moved a net {{ $close->net >= 0 ? 'profit' : 'loss' }} of <b>{{ money(abs($close->net)) }}</b> to {{ $retained?->code }} {{ $retained?->name }}@if($close->closer) (approved by {{ $close->closer->name }})@endif.</div>
@else
  <div class="alert alert-warn" id="yeOpen"><x-icon name="calendar"/> {{ $label }} is open. The closing entry is posted automatically when <b>{{ \Illuminate\Support\Carbon::parse($lastMonth.'-01')->format('F Y') }}</b> – the last month of the year – is approved in Period Close; reopening that month reverses it.</div>
@endif

<div class="card mb-1" id="fyMonths"><div class="card-head"><h3><x-icon name="calendar"/> Periods of {{ $label }}</h3><span class="tiny muted">{{ dmy($pv['start']) }} – {{ dmy($pv['end']) }}</span></div><div class="card-body flex gap-1 wrap">
  @foreach($months as $m) @php $row = $rows[$m] ?? null; $managed = $m >= $start; @endphp
    <a href="{{ $managed && $m <= \App\Services\CloseService::currentPeriod() ? route('close.show', $m) : '#' }}" class="fy-month st-{{ $row?->status ?? ($managed ? 'none' : 'pre') }}" data-month="{{ $m }}">
      <b>{{ \Illuminate\Support\Carbon::parse($m.'-01')->format('M') }}</b><span>{{ $row ? $CP::STATUSES[$row->status] : ($managed ? ($m <= \App\Services\CloseService::currentPeriod() ? 'Open' : 'Future') : 'Before go-live') }}</span></a>
  @endforeach
</div></div>

<div class="stats">
  <x-stat label="Income for the year" :value="money($pv['fy_income'])" icon="trend" tone="green" sub="before the closing entry"/>
  <x-stat label="Expenses for the year" :value="money($pv['fy_expense'])" icon="receipt" tone="amber"/>
  <x-stat :label="'Net '.($pv['fy_income'] - $pv['fy_expense'] >= 0 ? 'profit' : 'loss')" :value="money($pv['fy_income'] - $pv['fy_expense'])" icon="money" tone="blue"/>
  <x-stat label="Still to close to retained earnings" :value="money($pv['net'])" icon="layers" :tone="abs($pv['net']) < 0.01 ? 'green' : 'purple'" :sub="abs($pv['net']) < 0.01 ? 'income & expense accounts are clear' : 'balances up to '.dmy($pv['end'])"/>
</div>

<div class="card flush mt-1"><div class="card-head"><h3><x-icon name="book"/> {{ $close ? 'Balances left after the closing entry' : 'Closing entry preview' }}</h3><span class="tiny muted">Dr income / Cr expenses, net to retained earnings</span></div><div class="card-body table-wrap"><table class="table" id="yePreview">
  <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="num">Movement in the year</th><th class="num">Balance to clear</th><th class="num">Closing Dr</th><th class="num">Closing Cr</th></tr></thead>
  <tbody>@forelse($pv['rows'] as $r)<tr data-acct="{{ $r['code'] }}"><td class="small">{{ $r['code'] }}</td><td class="small">{{ $r['account'] }}</td><td class="small">{{ ucfirst($r['type']) }}</td><td class="num">{{ money($r['fy'], false) }}</td><td class="num">{{ money($r['balance'], false) }}</td><td class="num">{{ $r['dr'] ? money($r['dr'], false) : '' }}</td><td class="num">{{ $r['cr'] ? money($r['cr'], false) : '' }}</td></tr>
    @empty <tr><td colspan="7"><x-empty text="No income or expense in this fiscal year." icon="book"/></td></tr> @endforelse
    @if(abs($pv['net']) >= 0.01)<tr class="row-total" id="yeRetained"><td class="small">{{ $retained?->code }}</td><td class="small bold">{{ $retained?->name }} – net {{ $pv['net'] >= 0 ? 'profit' : 'loss' }}</td><td class="small">Equity</td><td></td><td></td><td class="num">{{ $pv['net'] < 0 ? money(-$pv['net'], false) : '' }}</td><td class="num">{{ $pv['net'] > 0 ? money($pv['net'], false) : '' }}</td></tr>@endif
  </tbody></table></div></div>

<div class="card flush mt-1"><div class="card-head"><h3><x-icon name="clock"/> Year-end history</h3></div><div class="card-body table-wrap"><table class="table" id="yeHistory">
  <thead><tr><th>Fiscal year</th><th>Closing date</th><th class="num">Income</th><th class="num">Expenses</th><th class="num">Net to retained earnings</th><th>Journal</th><th>Status</th></tr></thead>
  <tbody>@forelse($history as $h)<tr><td class="bold">{{ \App\Services\BudgetService::fyLabel($h->fiscal_year) }}</td><td class="small">{{ dmy($h->closing_date) }}</td><td class="num">{{ money($h->income, false) }}</td><td class="num">{{ money($h->expense, false) }}</td><td class="num bold">{{ money($h->net, false) }}</td><td class="small">@if($h->journal)<a href="{{ route('gl.journals.show', $h->journal) }}">{{ $h->journal->journal_no }}</a>@endif</td><td><span class="badge badge-{{ $h->status === 'closed' ? 'green' : 'slate' }}">{{ $h->status === 'closed' ? 'Closed' : 'Reopened '.$h->reopened_at?->format('d M Y') }}</span></td></tr>
  @empty <tr><td colspan="7"><x-empty text="No fiscal year has been closed yet." icon="calendar"/></td></tr> @endforelse</tbody></table></div></div>
@endsection
