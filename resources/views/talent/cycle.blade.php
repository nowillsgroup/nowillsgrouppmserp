@extends('layouts.app')
@section('title', $c->name)
@section('crumbs') Talent Management / <a href="{{ route('talent.appraisals') }}">Appraisals</a> / {{ $c->name }} @endsection
@section('actions')@if($c->status === 'active')<form method="post" action="{{ route('talent.cycle.close', $c) }}" class="inline">@csrf<button class="btn" id="talCycleClose" onclick="return confirm('Close the cycle?')">Close cycle</button></form>@endif @endsection
@section('content')
<div class="stats" id="talCycleStats">
  @foreach(\App\Models\TalentAppraisal::STATUSES as $k => $l)<x-stat :label="$l" :value="$rows->where('status', $k)->count()" :icon="['self' => 'user', 'review' => 'edit', 'hr' => 'shield', 'signed' => 'check'][$k]" :tone="['self' => 'slate', 'review' => 'amber', 'hr' => 'purple', 'signed' => 'green'][$k]"/>@endforeach
</div>
<div class="g-2-1">
  <div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="talCycleTable"><thead><tr><th>Employee</th><th>Reviewer</th><th class="num">Overall</th><th>Band</th><th>Status</th><th></th></tr></thead><tbody>
  @foreach($rows as $a)@php $late = \App\Services\TalentService::appraisalOverdue($a); @endphp<tr class="{{ $late ? 'row-amber' : '' }} {{ $a->pip ? 'row-red' : '' }}">
    <td><a href="{{ route('talent.appraisal', $a) }}"><b>{{ $a->employee->name }}</b></a><div class="tiny muted">{{ $a->employee->designation }} · {{ $a->employee->department?->name }}</div></td>
    <td class="small">@if($a->status !== 'signed' && $c->status === 'active')<form method="post" action="{{ route('talent.appraisal.reviewer', $a) }}" class="tal-inline-form">@csrf<select name="reviewer_id" class="tal-sel" onchange="this.form.submit()"><option value="">— assign —</option>@foreach($users as $x)<option value="{{ $x->id }}" @selected($a->reviewer_id === $x->id)>{{ $x->name }}</option>@endforeach</select></form>@else{{ $a->reviewer?->name }}@endif</td>
    <td class="num">{{ $a->overall !== null ? number_format($a->overall, 2) : '—' }}</td><td class="small">{{ $a->bandLabel() ?? '—' }}{{ $a->pip ? ' · PIP' : '' }}</td>
    <td>@include('talent._badge', ['s' => $a->status, 'l' => $a->statusLabel()]){{ $late ? ' ' : '' }}@if($late)<span class="tiny text-red">late</span>@endif @if($a->acknowledged_at)<div class="tiny muted">acknowledged</div>@endif</td>
    <td class="nowrap">@if($a->status === 'hr')<form method="post" action="{{ route('talent.appraisal.signoff', $a) }}" class="inline">@csrf<input type="hidden" name="action" value="approve"><button class="btn btn-sm btn-primary">Sign off</button></form>@endif</td></tr>
  @endforeach
  </tbody></table></div></div>
  <div class="card"><div class="card-head"><h3>Rating distribution</h3></div><div class="card-body" id="talDist">
    @php $tot = max(1, $dist->sum()); @endphp
    @foreach(\App\Models\TalentAppraisal::BANDS as $b => $l)<div class="tal-funnel-row"><span class="small">{{ $b }} · {{ $l }}</span><div class="tal-funnel-bar"><i style="width:{{ round(($dist[$b] ?? 0) / $tot * 100) }}%" class="tal-band{{ $b }}"></i></div><b class="small">{{ $dist[$b] ?? 0 }}</b></div>@endforeach
    <p class="tiny muted mt-1">Reviewed appraisals only ({{ $dist->sum() }} of {{ $rows->count() }}).</p>
  </div></div>
</div>
@endsection
