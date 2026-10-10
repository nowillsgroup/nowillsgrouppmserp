@extends('layouts.app')
@section('title', 'Income Audit')
@section('crumbs') Income Audit / Audit days @endsection
@section('actions')
@if(can_do('income_audit.audit'))<form method="post" action="{{ route('audit.start') }}" style="display:inline">@csrf<input type="hidden" name="date" value="{{ bdate()->copy()->subDay()->toDateString() }}"><button class="btn btn-primary" id="auditYesterday"><x-icon name="shield"/> Audit {{ dmy(bdate()->copy()->subDay(), 'D d M') }}</button></form>@endif
<a class="btn" href="{{ route('audit.auth') }}"><x-icon name="check"/> Authorisations</a>
@endsection
@php
  $tone = ['open' => 'amber', 'signed' => 'blue', 'closed' => 'green'];
@endphp
@section('content')
<div class="stats">
  <x-stat label="Exceptions open" :value="$stats['open_findings']" icon="alert" :tone="$stats['open_findings'] ? 'red' : 'green'" :sub="$stats['escalated'].' escalated'"/>
  <x-stat label="Days in audit" :value="$stats['open_days']" icon="search" tone="amber" :sub="$stats['unaudited'].' closed day(s) not started (7 days)'"/>
  <x-stat label="Awaiting controller" :value="$stats['signed']" icon="clock" tone="blue" sub="signed by the auditor"/>
  <x-stat label="Closed & locked" :value="$stats['closed']" icon="lock" tone="green" sub="last 30 days"/>
  <x-stat label="Authorisations pending" :value="$stats['pending_auth']" icon="check" :tone="$stats['pending_auth'] ? 'purple' : 'slate'" :sub="$stats['count_issues'].' room-count discrepancy(ies) open'"/>
</div>

<form class="filters mt-1" method="get"><div class="field"><label>Status</label><select name="status" onchange="this.form.submit()"><option value="">All days</option><option value="none" @selected(request('status') === 'none')>Not started</option>@foreach(\App\Models\AuditDay::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div></form>

<div class="card flush" id="auditDays"><div class="card-head"><h3><x-icon name="calendar"/> Business dates – last 30 days</h3><span class="small muted">each night audit opens the day for income audit and runs the checks</span></div><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Business date</th><th>Status</th><th>Checks run</th><th class="num">Exceptions</th><th class="num">Open</th><th>Signed (auditor)</th><th>Closed (controller)</th><th></th></tr></thead>
  <tbody>@forelse($rows as $r) @php $d = $r['day']; @endphp
    <tr data-date="{{ $r['date'] }}"><td class="bold">{{ dmy($r['date'], 'D d M Y') }}</td>
      <td>@if($d)<span class="badge badge-{{ $tone[$d->status] }}">{{ \App\Models\AuditDay::STATUSES[$d->status] }}</span>@if($d->reopened_at && $d->status !== 'closed') <span class="badge badge-slate" title="{{ $d->reopen_reason }}">reopened</span>@endif @else<span class="muted small">not started</span>@endif</td>
      <td class="small">{{ $d?->checked_at ? $d->checked_at->format('d M H:i') : '—' }}</td>
      <td class="num">{{ $d ? $d->findings_total : '' }}</td>
      <td class="num">@if($d)<span class="{{ $d->findings_open ? 'bold text-red' : 'muted' }}">{{ $d->findings_open }}</span>@endif</td>
      <td class="small">{{ $d?->signer?->name }}<div class="tiny muted">{{ $d?->signed_at?->format('d M H:i') }}</div></td>
      <td class="small">{{ $d?->closer?->name }}<div class="tiny muted">{{ $d?->closed_at?->format('d M H:i') }}</div></td>
      <td class="num">@if($d)<a class="btn btn-sm" href="{{ route('audit.day', $d) }}">Open</a>@elseif(can_do('income_audit.audit'))<form method="post" action="{{ route('audit.start') }}">@csrf<input type="hidden" name="date" value="{{ $r['date'] }}"><button class="btn btn-sm btn-primary">Start audit</button></form>@endif</td></tr>
  @empty <tr><td colspan="8"><x-empty text="No days match." icon="calendar"/></td></tr> @endforelse</tbody>
</table></div></div>

<div class="grid g-2 mt-1" style="gap:1rem">
  <div class="card" id="checksList"><div class="card-head"><h3><x-icon name="search"/> Automated checks</h3></div><div class="card-body"><table class="table table-compact">
    @foreach($checks as $k => [$label, $sev, $help])<tr><td class="bold small">{{ $label }}</td><td><span class="badge badge-{{ ['high' => 'red', 'medium' => 'amber', 'low' => 'slate'][$sev] }}">{{ $sev }}</span></td><td class="small muted">{{ $help }}</td></tr>@endforeach
  </table>
  <p class="tiny muted mt-1">Work flow: the night audit closes the day → checks run → the income auditor explains, corrects or escalates every exception and signs → the financial controller reviews and closes (a different person). A closed day is locked: its postings can no longer be voided; corrections go through today.</p></div></div>
  <div class="card" id="iaSettings"><div class="card-head"><h3><x-icon name="tool"/> Tolerances &amp; authority limits</h3></div><div class="card-body">
    <form method="post" action="{{ route('audit.settings') }}">@csrf<div class="grid g-2" style="gap:.2rem 1rem">
      @foreach($settings as $k => [$label, $def])<div class="field"><label for="s_{{ $k }}">{{ $label }}</label><input type="number" step="any" min="0" name="{{ $k }}" id="s_{{ $k }}" value="{{ setting($k, $def) }}" @disabled(! can_do('income_audit.close'))></div>@endforeach
    </div>@if(can_do('income_audit.close'))<button class="btn btn-primary mt-1" id="iaSettingsSave">Save settings</button>@endif</form>
  </div></div>
</div>
@endsection
