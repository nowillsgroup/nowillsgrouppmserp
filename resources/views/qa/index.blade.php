@extends('layouts.app')
@section('title', 'Quality Assurance')
@section('crumbs') Quality Assurance / Dashboard @endsection
@php $canAudit = \App\Services\QaService::canAudit(auth()->user()); $pm = $cfg['pass_mark']; $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%'; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'qa_department_scores']) }}"><x-icon name="download"/> Scores</a>
  @if($canAudit)<button class="btn" data-modal="qaActionModal"><x-icon name="alert"/> Raise action</button><button class="btn btn-primary" data-modal="qaScheduleModal" id="qaScheduleBtn"><x-icon name="calendar"/> Schedule audit</button>@endif
@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input type="date" name="from" value="{{ $from->toDateString() }}" aria-label="From"><input type="date" name="to" value="{{ $to->toDateString() }}" aria-label="To"><button class="btn btn-sm">Apply</button>
  <span class="tiny muted">Scores by audit completion date · target {{ $fmt($k['target']) }} · default pass mark {{ $fmt($pm) }}{{ $cfg['critical_autofail'] ? ' · a critical failure fails the audit' : '' }}</span>
</form></div></div>
<div class="stats" id="qaStats">
  <x-stat label="Average score" :value="$fmt($k['avg'])" icon="star" :tone="$k['avg'] === null ? 'slate' : ($k['avg'] >= $k['target'] ? 'green' : ($k['avg'] >= $pm ? 'amber' : 'red'))" :sub="$k['audits'].' audits · target '.$fmt($k['target'])"/>
  <x-stat label="Pass rate" :value="$fmt($k['pass_rate'])" icon="check" tone="blue" :sub="$k['passed'].' of '.$k['audits'].' passed'.($k['mystery_avg'] !== null ? ' · mystery guest '.$fmt($k['mystery_avg']) : '')"/>
  <x-stat label="Findings" :value="$k['findings']" icon="alert" :tone="$k['critical'] ? 'red' : 'amber'" :sub="$k['critical'].' critical · '.$k['repeat'].' repeat'"/>
  <x-stat label="Open actions" :value="$k['open_actions']" icon="tool" :tone="$k['overdue_actions'] ? 'red' : 'blue'" :sub="$k['overdue_actions'].' overdue'" :href="route('qa.actions', ['view' => 'overdue'])"/>
  <x-stat label="To verify" :value="$k['to_verify']" icon="eye" :tone="$k['to_verify'] ? 'purple' : 'slate'" :sub="'on-time closure '.$fmt($k['on_time'])" :href="route('qa.actions', ['view' => 'verify'])"/>
</div>
@if($alerts['audits_late'] || $alerts['checklists_due'] || $alerts['unassigned'])
<div class="alert alert-warn mt-1" id="qaAlerts">@if($alerts['audits_late'])<b>{{ $alerts['audits_late'] }}</b> audit(s) past their date. @endif @if($alerts['checklists_due'])<b>{{ $alerts['checklists_due'] }}</b> checklist(s) due and not yet scheduled. @endif @if($alerts['unassigned'])<a href="{{ route('qa.actions', ['view' => 'unassigned']) }}"><b>{{ $alerts['unassigned'] }}</b> corrective action(s) without an owner.</a>@endif</div>
@endif
<div class="g-2-1 mt-1">
  <div class="card flush"><div class="card-head"><h3>Department scores</h3><span class="tiny muted">vs previous {{ (int) $from->diffInDays($to) + 1 }} days</span></div><div class="card-body table-wrap"><table class="table table-compact" id="qaDeptTable"><thead><tr><th>Department</th><th class="num">Audits</th><th>Average</th><th class="num">Change</th><th class="num">Last</th><th class="num">Failed</th><th class="num">Open / overdue actions</th></tr></thead><tbody>
    @forelse($depts as $d)<tr class="{{ $d['avg'] !== null && $d['avg'] < $pm ? 'row-red' : ($d['overdue'] ? 'row-amber' : '') }}">
      <td>{{ $d['name'] }}</td><td class="num">{{ $d['audits'] }}</td>
      <td style="min-width:8rem">@if($d['avg'] !== null)<div class="dm-bar {{ $d['avg'] < $pm ? 'over' : '' }}"><span style="width:{{ min(100, $d['avg']) }}%"></span></div><span class="small">{{ $fmt($d['avg']) }}</span>@else<span class="muted">—</span>@endif</td>
      <td class="num {{ ($d['change'] ?? 0) < 0 ? 'text-red' : 'text-green' }}">{{ $d['change'] === null ? '—' : ($d['change'] > 0 ? '+' : '').$d['change'] }}</td>
      <td class="num">{{ $fmt($d['last']) }}</td><td class="num">{{ $d['failed'] ?: '' }}</td><td class="num">{{ $d['open'] }}@if($d['overdue']) / <b class="text-red">{{ $d['overdue'] }}</b>@endif</td></tr>
    @empty<tr><td colspan="7" class="muted">No audits completed in this period.</td></tr>@endforelse
  </tbody></table></div></div>
  <div class="card"><div class="card-head"><h3>Score trend</h3><span class="tiny muted">monthly average</span></div><div class="card-body"><div class="bar-chart" id="qaTrend">
    @foreach($trend as $m)<div class="col"><small>{{ $m['avg'] !== null ? $fmt($m['avg']) : '' }}</small><div class="b" style="height:{{ $m['avg'] !== null ? max(2, ($m['avg'] - 50) * 2) : 0 }}%;{{ $m['avg'] === null ? 'visibility:hidden;' : '' }}{{ $m['avg'] !== null && $m['avg'] < $pm ? 'background:linear-gradient(180deg,#f87171,#dc2626)' : '' }}" data-v="{{ $m['audits'] }} audits · pass {{ $fmt($m['pass_rate']) }}"></div><small>{{ $m['month']->format('M') }}</small></div>@endforeach
  </div><p class="tiny muted mt-1">Bars start at 50 %; red months average below the pass mark.</p></div></div>
</div>
<div class="g-2-1 mt-1">
  <div class="card flush"><div class="card-head"><h3>Most-failed standards</h3><a class="small" href="{{ route('reports.show', ['report' => 'qa_standard_failures']) }}">report</a></div><div class="card-body table-wrap"><table class="table table-compact" id="qaFailTable"><thead><tr><th>Standard</th><th>Department</th><th class="num">Fails</th><th class="num">Fail rate</th><th class="num">Repeat</th><th class="num">Open actions</th></tr></thead><tbody>
    @forelse($failures as $f)<tr><td><b>{{ $f['standard']->code }}</b> {{ \Illuminate\Support\Str::limit($f['standard']->title, 60) }}@if($f['standard']->is_critical) <span class="badge badge-red">critical</span>@endif</td><td class="small">{{ $f['standard']->department?->name ?? '—' }}</td>
      <td class="num">{{ $f['fails'] }} / {{ $f['checks'] }}</td><td class="num">{{ $f['rate'] }}%</td><td class="num">{{ $f['repeats'] ?: '' }}</td><td class="num">{{ $f['open'] ?: '' }}</td></tr>
    @empty<tr><td colspan="6" class="muted">No failures in this period.</td></tr>@endforelse
  </tbody></table></div></div>
  <div class="card flush"><div class="card-head"><h3>Checklist schedule</h3><a class="small" href="{{ route('qa.templates') }}">checklists</a></div><div class="card-body table-wrap"><table class="table table-compact" id="qaDueTable"><thead><tr><th>Checklist</th><th>Next due</th><th>State</th></tr></thead><tbody>
    @forelse($due as $d)<tr class="{{ in_array($d['state'], ['due', 'late'], true) ? 'row-amber' : '' }}"><td>{{ $d['template']->name }}<div class="tiny muted">every {{ $d['template']->frequency_days }} days · last {{ $d['last']?->format('d M') ?? 'never' }}</div></td>
      <td class="small nowrap">{{ $d['pending'] ? $d['pending']->scheduled_for->format('d M') : $d['next']->format('d M') }}</td>
      <td>@if($d['state'] === 'booked')<a href="{{ route('qa.audit', $d['pending']) }}"><span class="badge badge-blue">booked</span></a>@elseif($d['state'] === 'late')<a href="{{ route('qa.audit', $d['pending']) }}"><span class="badge badge-red">late</span></a>@elseif($d['state'] === 'due')<span class="badge badge-amber">due</span>@if($canAudit) <button class="btn btn-sm" data-modal="qaScheduleModal" data-fqa_template_id="{{ $d['template']->id }}" data-title="Schedule – {{ $d['template']->name }}">Schedule</button>@endif @else<span class="badge badge-green">on track</span>@endif</td></tr>
    @empty<tr><td colspan="3" class="muted">No recurring checklists.</td></tr>@endforelse
  </tbody></table></div></div>
</div>
<div class="g-2 mt-1">
  <div class="card flush"><div class="card-head"><h3>Recent results</h3><a class="small" href="{{ route('qa.audits', ['status' => 'completed']) }}">all</a></div><div class="card-body table-wrap"><table class="table table-compact" id="qaRecent"><tbody>
    @forelse($recent as $a)<tr><td><a href="{{ route('qa.audit', $a) }}"><b>{{ $a->number }}</b></a> {{ $a->title }}<div class="tiny muted">{{ $a->completed_at->format('d M') }} · {{ $a->auditorName() }}</div></td><td class="num nowrap"><b>{{ $fmt($a->score_pct) }}</b> <span class="badge badge-{{ $a->resultTone() }}">{{ $a->passed ? 'pass' : 'fail' }}</span></td></tr>
    @empty<tr><td class="muted">No audits yet.</td></tr>@endforelse
    @foreach($upcoming as $a)<tr class="{{ $a->isOverdue() ? 'row-alert' : '' }}"><td><a href="{{ route('qa.audit', $a) }}"><b>{{ $a->number }}</b></a> {{ $a->title }}<div class="tiny muted">{{ $a->scheduled_for->format('d M') }} · {{ $a->auditorName() }}</div></td><td class="num"><span class="badge badge-{{ $a->isOverdue() ? 'red' : $a->statusTone() }}">{{ $a->isOverdue() ? 'late' : $a->statusLabel() }}</span></td></tr>@endforeach
  </tbody></table></div></div>
  <div class="card flush"><div class="card-head"><h3>Overdue corrective actions</h3><a class="small" href="{{ route('qa.actions', ['view' => 'overdue']) }}">all</a></div><div class="card-body table-wrap"><table class="table table-compact" id="qaOverdue"><tbody>
    @forelse($overdue as $x)<tr><td><b>{{ $x->number }}</b> {{ \Illuminate\Support\Str::limit($x->title, 55) }}<div class="tiny muted">{{ $x->department?->name ?? '—' }} · {{ $x->owner?->name ?? 'unassigned' }}</div></td><td class="nowrap small"><span class="badge badge-{{ $x->priorityTone() }}">{{ $x->priorityLabel() }}</span><div class="tiny text-red">due {{ $x->due_date->format('d M') }}</div></td></tr>
    @empty<tr><td class="muted">Nothing overdue.</td></tr>@endforelse
  </tbody></table></div></div>
</div>
@if($canAudit)@include('qa._schedule_modal')@include('qa._action_modal')@endif
@endsection
