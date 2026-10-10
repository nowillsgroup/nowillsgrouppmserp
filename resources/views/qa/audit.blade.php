@extends('layouts.app')
@section('title', $a->number.' · '.$a->title)
@section('crumbs') Quality Assurance / <a href="{{ route('qa.audits') }}">Audits</a> / {{ $a->number }} @endsection
@php $I = \App\Models\QaAuditItem::class; $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%'; $done = $a->status === 'completed'; @endphp
@section('actions')
  <a class="btn" href="{{ route('qa.audit.print', $a) }}" target="_blank"><x-icon name="print"/> Print</a>
  @if($done && ! $a->acknowledged_at && $a->auditor_id !== auth()->id())<form method="post" action="{{ route('qa.audit.acknowledge', $a) }}" class="inline">@csrf<button class="btn" id="ackBtn"><x-icon name="check"/> Acknowledge result</button></form>@endif
  @if($canManage && in_array($a->status, ['scheduled', 'in_progress'], true))<button class="btn btn-danger" data-modal="qaCancelModal">Cancel audit</button>@endif
@endsection
@section('content')
<div class="stats" id="auditStats">
  <x-stat label="Status" :value="$a->isOverdue() ? 'Late' : $a->statusLabel()" icon="clock" :tone="$a->isOverdue() ? 'red' : $a->statusTone()" :sub="($done ? 'completed '.$a->completed_at->format('d M Y H:i') : 'date '.$a->scheduled_for->format('d M Y')).' · '.$a->auditorName()"/>
  <x-stat label="Score" :value="$done ? $fmt($a->score_pct) : '—'" icon="star" :tone="$a->resultTone()" :sub="$done ? (($a->passed ? 'PASSED' : 'FAILED').' · pass mark '.$fmt($a->pass_mark).' · '.rtrim(rtrim(number_format($a->points_earned, 1), '0'), '.').' / '.rtrim(rtrim(number_format($a->points_possible, 1), '0'), '.').' pts') : 'pass mark '.$fmt($a->pass_mark)"/>
  <x-stat label="Findings" :value="$done ? $a->fails : $a->items->where('result', 'fail')->count()" icon="alert" :tone="$a->critical_fails ? 'red' : 'amber'" :sub="$a->critical_fails.' critical · '.$a->repeat_fails.' repeat'"/>
  <x-stat label="Previous" :value="$prev ? $fmt($prev->score_pct) : '—'" icon="trend" tone="slate" :sub="$prev ? $prev->number.' · '.$prev->completed_at->format('d M Y') : 'first audit of this checklist'"/>
</div>
@if($done && $a->critical_fails && ! $a->passed)<div class="alert alert-error mt-1" id="critAlert"><b>{{ $a->critical_fails }} critical standard{{ $a->critical_fails > 1 ? 's' : '' }} failed</b>{{ $a->score_pct >= $a->pass_mark ? ' – the audit fails although '.$fmt($a->score_pct).' clears the pass mark' : '' }}. Critical actions are due within {{ \App\Services\QaService::cfg()['critical_action_days'] }} day(s).</div>@endif
@if($a->status === 'cancelled')<div class="alert alert-info mt-1">Cancelled: {{ $a->cancel_reason }}</div>@endif
<div class="g-2-1 mt-1">
<div>
@if($canConduct)
<form method="post" action="{{ route('qa.audit.record', $a) }}" id="auditForm">@csrf
@endif
@foreach($areas as $area => $items)
  <div class="card flush mb-1"><div class="card-head"><h3>{{ $area }}</h3><span class="tiny muted">{{ $items->count() }} standards · {{ $items->sum('weight') }} pts</span></div><div class="card-body table-wrap"><table class="table table-compact qa-items"><tbody>
  @foreach($items as $it)<tr data-item="{{ $it->code }}" class="{{ $it->result === 'fail' ? 'row-red' : '' }}">
    <td style="width:55%"><b>{{ $it->code }}</b> {{ $it->title }} <span class="tiny muted">· {{ $it->weight }} pt{{ $it->weight > 1 ? 's' : '' }}</span>@if($it->is_critical) <span class="badge badge-red">critical</span>@endif @if($it->is_repeat) <span class="badge badge-amber">repeat</span>@endif
      @if($it->standard?->sop)<div class="tiny muted">SOP {{ $it->standard->sop->code }} · {{ $it->standard->sop->title }}</div>@endif
      @if($it->standard?->description && $canConduct)<div class="tiny muted">{{ $it->standard->description }}</div>@endif</td>
    @if($canConduct)
    <td class="nowrap"><div class="qa-seg">@foreach($I::RESULTS as $k => $r)<label class="qa-{{ $k }}"><input type="radio" name="result[{{ $it->id }}]" value="{{ $k }}" @checked($it->result === $k)><span>{{ $r[0] }}</span></label>@endforeach</div></td>
    <td><input name="note[{{ $it->id }}]" value="{{ $it->note }}" maxlength="500" placeholder="{{ $it->is_critical || $it->weight >= 4 ? 'Evidence needed if failed' : 'Note' }}" aria-label="Note {{ $it->code }}"></td>
    @else
    <td class="nowrap">@if($it->result)<span class="badge badge-{{ $I::RESULTS[$it->result][1] }}">{{ $I::RESULTS[$it->result][0] }}</span>@else<span class="muted">—</span>@endif</td><td class="small">{{ $it->note }}</td>
    @endif
  </tr>@endforeach
  </tbody></table></div></div>
@endforeach
@if($canConduct)
  <div class="card"><div class="card-body"><div class="field"><label>Summary</label><textarea name="summary" rows="3" placeholder="Overall impression, strengths, key concerns">{{ $a->summary }}</textarea></div>
  <div class="flex gap-1 mt-1"><button class="btn" name="do" value="save" id="saveResultsBtn">Save progress</button><button class="btn btn-primary" name="do" value="complete" id="completeAuditBtn" data-confirm-click="Complete and score this audit? Every failure raises a corrective action.">Complete &amp; score</button>
  <span class="tiny muted" style="align-self:center">Critical and 4–5-point failures need a note. Completing raises a corrective action for each failure.</span></div></div></div>
</form>
@elseif($a->summary)
  <div class="card"><div class="card-head"><h3>Summary</h3></div><div class="card-body"><p class="small" style="white-space:pre-line">{{ $a->summary }}</p></div></div>
@endif
</div>
<div>
  <div class="card"><div class="card-head"><h3>Audit</h3></div><div class="card-body"><div class="kv-grid">
    <div><span>Checklist</span>{{ $a->template?->name }}</div><div><span>Type</span>{{ $a->typeLabel() }}</div><div><span>Department</span>{{ $a->department?->name ?? 'Whole hotel' }}</div>
    <div><span>Auditor</span>{{ $a->auditorName() }}@if($a->external_auditor && $a->auditor)<div class="tiny muted">entered by {{ $a->auditor->name }}</div>@endif</div>
    <div><span>Date</span>{{ $a->scheduled_for->format('d M Y') }}@if($a->started_at)<div class="tiny muted">started {{ $a->started_at->format('d M H:i') }}</div>@endif</div>
    <div><span>Acknowledged</span>{{ $a->acknowledged_at ? $a->acknowledger?->name.' · '.$a->acknowledged_at->format('d M H:i') : '—' }}</div>
  </div></div></div>
  @if($history->count() > 1)<div class="card"><div class="card-head"><h3>Checklist history</h3></div><div class="card-body"><div class="bar-chart" style="height:120px" id="auditHistory">
    @foreach($history as $h)<div class="col"><small>{{ $fmt($h->score_pct) }}</small><div class="b" style="height:{{ max(2, ($h->score_pct - 50) * 2) }}%;{{ ! $h->passed ? 'background:linear-gradient(180deg,#f87171,#dc2626)' : '' }}{{ $h->id === $a->id ? ';outline:2px solid #0f172a' : '' }}" data-v="{{ $h->number }}"></div><small>{{ $h->completed_at->format('M y') }}</small></div>@endforeach
  </div></div></div>@endif
  @if($a->actions->isNotEmpty())<div class="card flush"><div class="card-head"><h3>Corrective actions</h3><a class="small" href="{{ route('qa.actions', ['view' => 'all', 'q' => '']) }}">all</a></div><div class="card-body table-wrap"><table class="table table-compact" id="auditActions"><tbody>
    @foreach($a->actions as $x)<tr class="{{ $x->isOverdue() ? 'row-alert' : '' }}"><td><b>{{ $x->number }}</b> {{ \Illuminate\Support\Str::limit($x->title, 45) }}<div class="tiny muted">{{ $x->owner?->name ?? 'unassigned' }} · due {{ $x->due_date->format('d M') }}{{ $x->ticket ? ' · '.$x->ticket->ticket_no : '' }}</div></td><td class="nowrap"><span class="badge badge-{{ $x->statusTone() }}">{{ $x->statusLabel() }}</span></td></tr>@endforeach
  </tbody></table></div></div>@endif
</div>
</div>
@if($canManage && in_array($a->status, ['scheduled', 'in_progress'], true))
<div class="modal" id="qaCancelModal"><div class="modal-box"><form method="post" action="{{ route('qa.audit.cancel', $a) }}">@csrf
  <div class="modal-head"><h3>Cancel {{ $a->number }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="reason" required maxlength="255"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel audit</button></div>
</form></div></div>
@endif
<script>document.getElementById('completeAuditBtn')?.addEventListener('click', e => { if (!confirm(e.currentTarget.dataset.confirmClick)) e.preventDefault(); });</script>
@endsection
