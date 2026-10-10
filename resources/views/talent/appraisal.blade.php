@extends('layouts.app')
@section('title', 'Appraisal – '.$a->employee->name)
@section('crumbs') @if($canSign)<a href="{{ route('talent.cycle', $a->cycle) }}">{{ $a->cycle->name }}</a>@else<a href="{{ route('talent.me') }}">My Appraisal</a>@endif / {{ $a->employee->name }} @endsection
@php
  $active = $a->cycle->status === 'active';
  $selfLate = $a->status === 'self' && bdate()->toDateString() > $a->cycle->self_due->toDateString();
  $selfMode = $own && $a->status === 'self' && $active;
  $revMode = $canReview && $active && ($a->status === 'review' || $selfLate);
  $showMgr = ! $own || $a->status === 'signed';
  $rate = [1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'];
@endphp
@section('content')
<div class="card"><div class="card-head"><h3>{{ $a->employee->name }} · {{ $a->employee->designation }}</h3>@include('talent._badge', ['s' => $a->status, 'l' => $a->statusLabel()])</div><div class="card-body"><div class="kv-grid" id="talApprKv">
  <div><span>Cycle</span><b>{{ $a->cycle->name }} ({{ $a->cycle->period_from->format('M') }}–{{ $a->cycle->period_to->format('M Y') }})</b></div><div><span>Reviewer</span><b>{{ $a->reviewer?->name ?? 'Not assigned' }}</b></div>
  <div><span>Self-assessment due</span><b>{{ $a->cycle->self_due->format('d M Y') }}{{ $a->self_at ? ' · done '.$a->self_at->format('d M') : '' }}</b></div><div><span>Review due</span><b>{{ $a->cycle->review_due->format('d M Y') }}{{ $a->reviewed_at ? ' · done '.$a->reviewed_at->format('d M') : '' }}</b></div>
  @if($a->overall !== null && $showMgr)<div><span>Overall</span><b class="tal-score">{{ number_format($a->overall, 2) }} – {{ $a->bandLabel() }}</b></div><div><span>Goals / competencies</span><b>{{ number_format($a->goals_score, 2) }} / {{ number_format($a->comp_score, 2) }}{{ $a->pip ? ' · performance improvement plan' : '' }}</b></div>@endif
  @if($a->signer)<div><span>Signed off</span><b>{{ $a->signer->name }} · {{ $a->signed_at->format('d M Y') }}</b></div>@endif
  @if($a->acknowledged_at)<div><span>Acknowledged</span><b>{{ $a->acknowledged_at->format('d M Y') }}{{ $a->employee_comment ? ' – “'.$a->employee_comment.'”' : '' }}</b></div>@endif
</div>@if($selfLate && $revMode)<div class="alert alert-warn mt-1"><div>The self-assessment deadline has passed – you may review without it.</div></div>@endif</div></div>

@if($selfMode || $revMode)<form method="post" action="{{ $selfMode ? route('talent.appraisal.self', $a) : route('talent.appraisal.review', $a) }}" id="talApprForm">@csrf @endif
<div class="card flush"><div class="card-head"><h3>Goals</h3><span class="small muted">rated 1 (not met) to 5 (far exceeded)</span></div><div class="card-body table-wrap"><table class="table table-compact" id="talGoals">
  <thead><tr><th>Goal</th><th class="num">Weight %</th><th class="center">Self</th>@if($showMgr || $revMode)<th class="center">Manager</th>@endif</tr></thead><tbody>
  @php $goals = $a->goals ?? []; if ($revMode) { $goals = array_merge($goals, array_fill(0, max(0, 5 - count($goals)), ['title' => '', 'weight' => '', 'self' => null, 'mgr' => null])); } @endphp
  @foreach($goals as $i => $g)<tr>
    <td>@if($revMode)<input name="goal_title[{{ $i }}]" value="{{ old('goal_title.'.$i, $g['title']) }}" class="tal-wide" placeholder="Add a goal">@else{{ $g['title'] }}@endif</td>
    <td class="num">@if($revMode)<input name="goal_weight[{{ $i }}]" value="{{ old('goal_weight.'.$i, $g['weight']) }}" type="number" min="0" max="100" class="tal-num">@else{{ $g['weight'] }}@endif</td>
    <td class="center">@if($selfMode)<select name="goal_self[{{ $i }}]" class="tal-sel"><option value="">–</option>@foreach($rate as $k => $l)<option @selected((int) old('goal_self.'.$i, $g['self']) === $k)>{{ $k }}</option>@endforeach</select>@else{{ $g['self'] ?? '—' }}@endif</td>
    @if($showMgr || $revMode)<td class="center">@if($revMode)<select name="goal_mgr[{{ $i }}]" class="tal-sel"><option value="">–</option>@foreach($rate as $k => $l)<option @selected((int) old('goal_mgr.'.$i, $g['mgr']) === $k)>{{ $k }}</option>@endforeach</select>@else{{ $g['mgr'] ?? '—' }}@endif</td>@endif
  </tr>@endforeach
</tbody></table></div></div>
<div class="card flush"><div class="card-head"><h3>Competencies</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="talComps">
  <thead><tr><th>Competency</th><th class="center">Self</th>@if($showMgr || $revMode)<th class="center">Manager</th>@endif</tr></thead><tbody>
  @foreach(\App\Services\TalentService::COMPETENCIES as $k => $l)@php $c = $a->competencies[$k] ?? ['self' => null, 'mgr' => null]; @endphp<tr><td>{{ $l }}</td>
    <td class="center">@if($selfMode)<select name="comp_self[{{ $k }}]" class="tal-sel"><option value="">–</option>@foreach($rate as $n => $x)<option @selected((int) old('comp_self.'.$k, $c['self']) === $n)>{{ $n }}</option>@endforeach</select>@else{{ $c['self'] ?? '—' }}@endif</td>
    @if($showMgr || $revMode)<td class="center">@if($revMode)<select name="comp_mgr[{{ $k }}]" class="tal-sel"><option value="">–</option>@foreach($rate as $n => $x)<option @selected((int) old('comp_mgr.'.$k, $c['mgr']) === $n)>{{ $n }}</option>@endforeach</select>@else{{ $c['mgr'] ?? '—' }}@endif</td>@endif</tr>@endforeach
</tbody></table></div></div>
<div class="card"><div class="card-body form-grid">
  @if($selfMode)<x-field name="self_comments" label="My comments – achievements, challenges, support I need" type="textarea" :value="$a->self_comments" class="span-all"/>
  @elseif($a->self_comments)<div class="span-all"><b class="small">Employee's comments</b><p class="small pre-line">{{ $a->self_comments }}</p></div>@endif
  @if($revMode)<x-field name="mgr_comments" label="Manager's overall comments" type="textarea" :value="$a->mgr_comments" class="span-all"/><x-field name="development" label="Development plan / training" type="textarea" :value="$a->development" class="span-all"/>
  @elseif($showMgr)@if($a->mgr_comments)<div class="span-all"><b class="small">Manager's comments</b><p class="small pre-line">{{ $a->mgr_comments }}</p></div>@endif @if($a->development)<div class="span-all"><b class="small">Development plan</b><p class="small pre-line">{{ $a->development }}</p></div>@endif @endif
</div>
@if($selfMode || $revMode)<div class="card-foot"><button class="btn" name="action" value="save" id="talApprSave">Save draft</button> <button class="btn btn-primary" name="action" value="submit" id="talApprSubmit">{{ $selfMode ? 'Submit to my manager' : 'Submit to HR' }}</button></div>@endif
</div>
@if($selfMode || $revMode)</form>@endif

@if($a->status === 'hr' && $canSign)
<div class="card" id="talSignCard"><div class="card-head"><h3>HR sign-off</h3></div><div class="card-body">
  @if($a->reviewer_id === $u->id)<p class="small text-amber">You reviewed this appraisal – another HR user must sign it off.</p>@endif
  <form method="post" action="{{ route('talent.appraisal.signoff', $a) }}" class="inline">@csrf<input type="hidden" name="action" value="approve"><button class="btn btn-primary" id="talSignOff">Sign off</button></form>
  <form method="post" action="{{ route('talent.appraisal.signoff', $a) }}" class="tal-inline-form">@csrf<input type="hidden" name="action" value="return"><input name="note" placeholder="What needs changing" required><button class="btn">Return to reviewer</button></form>
</div></div>
@endif
@if($own && $a->status === 'signed' && ! $a->acknowledged_at)
<form method="post" action="{{ route('talent.appraisal.acknowledge', $a) }}" class="card" id="talAckForm">@csrf<div class="card-body"><x-field name="comment" label="Your comment (optional)"/>
  <p class="tiny muted">Acknowledging confirms you have seen and discussed the appraisal; it does not mean you agree with every rating.</p></div>
  <div class="card-foot"><button class="btn btn-primary" id="talAck">Acknowledge</button></div></form>
@endif
@endsection
