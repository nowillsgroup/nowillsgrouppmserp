@extends('layouts.app')
@section('title', $i->name)
@section('crumbs') Business Development / <a href="{{ route('bd.initiatives') }}">Initiatives</a> / {{ $i->number }} @endsection
@php $I = \App\Models\BdInitiative::class; $me = auth()->user();
  $ed = can_do('bd.manage') || (can_do('bd.edit') && ($i->owner_id === $me->id || ! $i->owner_id));
  $ap = can_do('bd.approve') || can_do('bd.manage');
  $stage = fn ($to, $label, $cls = 'btn', $id = null) => '<form method="post" action="'.route('bd.initiative.stage', $i).'">'.csrf_field().'<input type="hidden" name="stage" value="'.$to.'"><button class="'.$cls.'"'.($id ? ' id="'.$id.'"' : '').'>'.e($label).'</button></form>';
  $planning = in_array($i->stage, ['idea', 'assessment', 'business_case', 'approved', 'implementation'], true) && ! $i->awaitingApproval(); @endphp
@section('actions')
  <a class="btn" href="{{ route('bd.initiative.print', $i) }}" target="_blank"><x-icon name="print"/> Business case</a>
  @if($ed && $i->isOpen() && ! $i->awaitingApproval())<button class="btn" data-modal="initiativeModal" id="editInitiativeBtn"><x-icon name="edit"/> Edit</button>@endif
  @if($ed)
    @if($i->stage === 'idea'){!! $stage('assessment', 'Start assessment', 'btn btn-primary', 'assessBtn') !!}@endif
    @if($i->stage === 'assessment'){!! $stage('idea', 'Back to idea') !!}{!! $stage('business_case', 'Build business case', 'btn btn-primary', 'caseBtn') !!}@endif
    @if($i->stage === 'business_case' && ! $i->submitted_at){!! $stage('assessment', 'Back to assessment') !!}<form method="post" action="{{ route('bd.initiative.submit', $i) }}">@csrf<button class="btn btn-primary" id="submitBtn"><x-icon name="check"/> Submit for approval</button></form>@endif
    @if($i->stage === 'approved'){!! $stage('implementation', 'Start implementation', 'btn btn-primary', 'implementBtn') !!}@endif
    @if($i->stage === 'implementation')<form method="post" action="{{ route('bd.initiative.stage', $i) }}" data-confirm="Mark the initiative as live from today?">@csrf<input type="hidden" name="stage" value="live"><button class="btn btn-primary" id="liveBtn"><x-icon name="star"/> Go live</button></form>@endif
    @if(in_array($i->stage, ['approved', 'implementation', 'live']))<button class="btn" data-modal="closeModal" id="closeInitBtn">Close</button>@endif
  @endif
  @if($ap && $i->awaitingApproval() && $i->owner_id !== $me->id)<button class="btn btn-primary" data-modal="decideModal" id="decideBtn"><x-icon name="check"/> Decide</button>@endif
  @if($ap && in_array($i->stage, ['idea', 'assessment', 'business_case']))<button class="btn btn-danger" data-modal="shelveModal" id="shelveBtn">Shelve</button>@endif
  @if($ap && $i->stage === 'rejected'){!! $stage('idea', 'Revive as idea', 'btn', 'reviveBtn') !!}@endif
@endsection
@section('content')
<div class="flex gap-1 wrap mb-1"><span class="badge badge-{{ $i->stageTone() }}" id="initStage" data-stage="{{ $i->awaitingApproval() ? 'awaiting' : $i->stage }}">{{ $i->stageLabel() }}</span><span class="badge badge-blue">{{ $I::CATEGORIES[$i->category] }}</span>@if($i->target_market)<span class="badge badge-slate">{{ $i->target_market }}</span>@endif @if($s['milestones_overdue'])<span class="badge badge-red">{{ $s['milestones_overdue'] }} milestone(s) overdue</span>@endif</div>
@if($i->decision_note)<div class="alert {{ $i->stage === 'rejected' ? 'alert-error' : 'alert-info' }}" id="decisionNote"><b>{{ $i->decider?->name }}{{ $i->decided_at ? ', '.$i->decided_at->format('d M Y') : '' }}:</b> {{ $i->decision_note }}</div>@endif
@if($i->close_reason)<div class="alert alert-info"><b>Closed:</b> {{ $i->close_reason }}</div>@endif
<div class="stats" id="caseStats">
  <x-stat label="Investment" :value="money_short($i->investment)" icon="wallet" tone="amber"/>
  <x-stat label="Annual net contribution" :value="money_short($s['net'])" icon="money" :tone="$s['net'] >= 0 ? 'green' : 'red'" :sub="money_short($i->annual_revenue).' revenue'.($s['margin'] !== null ? ' · '.$s['margin'].'% margin' : '')"/>
  <x-stat label="Payback" :value="$s['payback_months'] !== null ? $s['payback_months'].' months' : 'never'" icon="clock" tone="blue"/>
  <x-stat label="NPV ({{ $s['horizon'] }} yrs @ {{ $s['rate'] }}%)" :value="money_short($s['npv'])" icon="trend" :tone="$s['npv'] >= 0 ? 'green' : 'red'" :sub="$s['roi'] !== null ? 'ROI '.$s['roi'].'%' : ''"/>
  @if($s['months'])<x-stat label="Actual net ({{ $s['months'] }} months live)" :value="money_short($s['actual_net'])" icon="chart" :tone="($s['achievement'] ?? 0) >= 100 ? 'green' : 'amber'" :sub="'plan '.money_short($s['plan_net']).($s['achievement'] !== null ? ' · '.$s['achievement'].'%' : '')"/>@endif
</div>
<div class="g-2-1 mt-1">
  <div>
    <div class="card"><div class="card-head"><h3>Opportunity & approach</h3></div><div class="card-body small">{!! nl2br(e($i->description ?? 'Not described yet.')) !!}</div></div>
    <div class="card flush mt-1" id="milestoneCard"><div class="card-head"><h3><x-icon name="check"/> Milestones</h3><span class="tiny muted">{{ $s['milestones_done'] }}/{{ $s['milestones'] }} done</span></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th></th><th>Milestone</th><th>Due</th><th>Owner</th><th></th></tr></thead><tbody>
      @forelse($i->milestones as $m)<tr data-milestone="{{ $m->id }}" class="{{ $m->done_at ? 'muted' : '' }}"><td>@if($planning && ($ed || $m->owner_id === $me->id))<form method="post" action="{{ route('bd.milestone.toggle', $m) }}">@csrf<button class="btn btn-sm btn-ghost" title="{{ $m->done_at ? 'Re-open' : 'Mark done' }}">{!! $m->done_at ? '☑' : '☐' !!}</button></form>@else{!! $m->done_at ? '☑' : '☐' !!}@endif</td>
        <td>{{ $m->title }}@if($m->done_at)<div class="tiny muted">done {{ $m->done_at->format('d M Y') }}</div>@endif</td><td class="nowrap {{ $m->isOverdue() ? 'text-red bold' : '' }}">{{ $m->due_date->format('d M Y') }}</td><td class="small">{{ $m->owner?->name }}</td>
        <td>@if($planning && $ed && ! $m->done_at)<form method="post" action="{{ route('bd.milestone.delete', $m) }}" data-confirm="Remove this milestone?">@csrf<button class="btn btn-sm btn-ghost" title="Remove"><x-icon name="trash"/></button></form>@endif</td></tr>
      @empty<tr><td colspan="5" class="muted">No milestones yet – the implementation plan is needed before approval.</td></tr>@endforelse
    </tbody></table></div>
    @if($planning && $ed)<div class="card-body pad"><form method="post" action="{{ route('bd.milestones.store', $i) }}" class="flex gap-1 wrap" id="milestoneForm">@csrf<input name="title" maxlength="150" required placeholder="Milestone" style="flex:2;min-width:12rem" aria-label="Milestone"><input type="date" name="due_date" required min="{{ bdate()->toDateString() }}" aria-label="Due date">
      <select name="owner_id" aria-label="Owner"><option value="">Initiative owner</option>@foreach($people as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select><button class="btn btn-sm" id="addMilestoneBtn"><x-icon name="plus"/> Add</button></form></div>@endif
    </div>
    @if($i->stage === 'live' || $i->results->count())
    <div class="card flush mt-1" id="resultsCard"><div class="card-head"><h3><x-icon name="chart"/> Results since going live</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Month</th><th class="num">Revenue</th><th class="num">Cost</th><th class="num">Net</th><th class="num">Plan net</th><th>Note</th></tr></thead><tbody>
      @php $pm = $s['net'] / 12; @endphp
      @forelse($i->results as $r)<tr><td>{{ $r->month->format('M Y') }}</td><td class="num">{{ number_format($r->revenue, 2) }}</td><td class="num">{{ number_format($r->cost, 2) }}</td><td class="num {{ (float) $r->revenue - (float) $r->cost >= $pm ? 'text-green' : 'text-red' }}">{{ number_format((float) $r->revenue - (float) $r->cost, 2) }}</td><td class="num muted">{{ number_format($pm, 2) }}</td><td class="small">{{ $r->note }}</td></tr>
      @empty<tr><td colspan="6" class="muted">No results recorded yet.</td></tr>@endforelse
    </tbody></table></div>
    @if($i->stage === 'live' && $ed)<div class="card-body pad"><form method="post" action="{{ route('bd.initiative.results', $i) }}" class="flex gap-1 wrap" id="resultForm">@csrf<input type="month" name="month" required value="{{ bdate()->format('Y-m') }}" min="{{ $i->live_date->format('Y-m') }}" max="{{ bdate()->format('Y-m') }}" aria-label="Month">
      <input type="number" name="revenue" min="0" step="0.01" placeholder="Revenue" aria-label="Revenue"><input type="number" name="cost" min="0" step="0.01" placeholder="Cost" aria-label="Cost"><input name="note" maxlength="255" placeholder="Note" aria-label="Note"><button class="btn btn-sm" id="saveResultBtn">Save month</button></form></div>@endif
    </div>@endif
  </div>
  <div>
    <div class="card"><div class="card-body"><dl class="kv">
      <dt>Owner</dt><dd>{{ $i->owner?->name ?? '—' }}</dd>
      <dt>Partnership</dt><dd>@if($i->partner)<a href="{{ route('bd.partner', $i->partner) }}">{{ $i->partner->number }} {{ $i->partner->name }}</a>@else — @endif</dd>
      <dt>Target launch</dt><dd>{{ $i->launch_date?->format('d M Y') ?? '—' }}</dd>
      @if($i->live_date)<dt>Live since</dt><dd>{{ $i->live_date->format('d M Y') }}</dd>@endif
      @if($i->submitted_at)<dt>Submitted</dt><dd>{{ $i->submitted_at->format('d M Y H:i') }}</dd>@endif
      @if($i->decided_at && ! $i->decision_note)<dt>Decided</dt><dd>{{ $i->decider?->name }}, {{ $i->decided_at->format('d M Y') }}</dd>@endif
    </dl></div></div>
    <div class="card flush mt-1" id="cashFlow"><div class="card-head"><h3><x-icon name="money"/> Discounted cash flow</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Year</th><th class="num">Cash flow</th><th class="num">Discounted</th><th class="num">Cumulative</th></tr></thead><tbody>
      @php $cum = -(float) $i->investment; @endphp<tr><td>0</td><td class="num">{{ number_format(-(float) $i->investment) }}</td><td class="num">{{ number_format(-(float) $i->investment) }}</td><td class="num text-red">{{ number_format($cum) }}</td></tr>
      @for($t = 1; $t <= $s['horizon']; $t++)@php $dv = $s['net'] / (1 + $s['rate'] / 100) ** $t; $cum += $dv; @endphp<tr><td>{{ $t }}</td><td class="num">{{ number_format($s['net']) }}</td><td class="num">{{ number_format($dv) }}</td><td class="num {{ $cum >= 0 ? 'text-green' : 'text-red' }}">{{ number_format($cum) }}</td></tr>@endfor
    </tbody></table></div></div>
  </div>
</div>
@if($ed && $i->isOpen())@include('bd._initiative_modal', ['i' => $i])@endif
@if($ap && $i->awaitingApproval())
<div class="modal" id="decideModal"><div class="modal-box" style="max-width:34rem"><form method="post" action="{{ route('bd.initiative.decide', $i) }}">@csrf
  <div class="modal-head"><h3>Business case decision</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field span-all"><label>Decision</label><select name="decision"><option value="approve">Approve – go ahead</option><option value="return">Return for changes</option><option value="reject">Reject</option></select></div>
    <div class="field span-all"><label>Note to the owner <span class="tiny muted">(required to return or reject)</span></label><textarea name="note" rows="3" maxlength="500"></textarea></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save decision</button></div></form></div></div>
@endif
@if($ap && in_array($i->stage, ['idea', 'assessment', 'business_case']))
<div class="modal" id="shelveModal"><div class="modal-box" style="max-width:32rem"><form method="post" action="{{ route('bd.initiative.stage', $i) }}">@csrf<input type="hidden" name="stage" value="rejected">
  <div class="modal-head"><h3>Shelve initiative</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Why</label><textarea name="note" rows="3" required maxlength="500"></textarea></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Shelve</button></div></form></div></div>
@endif
@if($ed && in_array($i->stage, ['approved', 'implementation', 'live']))
<div class="modal" id="closeModal"><div class="modal-box" style="max-width:32rem"><form method="post" action="{{ route('bd.initiative.stage', $i) }}">@csrf<input type="hidden" name="stage" value="closed">
  <div class="modal-head"><h3>Close initiative</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Closing note</label><textarea name="note" rows="3" required maxlength="255" placeholder="Completed and handed to operations / abandoned because …"></textarea></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Close initiative</button></div></form></div></div>
@endif
@endsection
