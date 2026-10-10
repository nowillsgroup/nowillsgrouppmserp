@extends('layouts.app')
@section('title', 'Talent Management')
@section('crumbs') Talent Management / Dashboard @endsection
@php $u = auth()->user(); $al = $alerts; @endphp
@section('actions')
  @if($u->hasPermission('talent.recruit'))<a class="btn" href="{{ route('talent.vacancies') }}#new" id="talNewVac"><x-icon name="plus"/> Request a vacancy</a>@endif
  <a class="btn" href="{{ route('careers.index', hotel()->code) }}" target="_blank" id="talCareersLink"><x-icon name="globe"/> Careers page</a>
@endsection
@section('content')
<div class="stats" id="talStats">
  <x-stat label="Headcount" :value="$headcount" icon="users" tone="blue" :sub="$open->where('status', 'open')->sum(fn($v) => $v->remaining()).' post(s) being recruited'" :href="route('talent.establishment')"/>
  <x-stat label="Open vacancies" :value="$open->where('status', 'open')->count()" icon="search" tone="purple" :sub="($pipeline->sum() ?: 0).' applicants in the pipeline'" :href="route('talent.vacancies')"/>
  <x-stat label="Turnover · 12 months" :value="$turnover['rate'].'%'" icon="trend" :tone="$turnover['rate'] > 25 ? 'red' : ($turnover['rate'] > 15 ? 'amber' : 'green')" :sub="$turnover['leavers'].' leaver(s) · '.$turnover['voluntary_rate'].'% voluntary'" :href="$canDisc ? route('talent.exits') : null"/>
  <x-stat label="Appraisals signed" :value="$cycle ? $cycle->signed_count.' / '.$cycle->appraisals_count : '—'" icon="star" tone="green" :sub="$cycle?->name ?? 'No active cycle'" :href="$u->hasPermission('talent.appraise') && $cycle ? route('talent.cycle', $cycle) : null"/>
  @if($canDisc)<x-stat label="Open cases" :value="$cases" icon="alert" :tone="$cases ? 'amber' : 'green'" :sub="$exits->count().' exit(s) in progress'" :href="route('talent.cases')"/>@endif
</div>
@if(array_sum($al))
<div class="alert alert-warn" id="talAlerts"><x-icon name="alert"/><div>
  @if($al['vacancies'])<a href="{{ route('talent.vacancies', ['status' => 'pending']) }}"><b>{{ $al['vacancies'] }}</b> vacancy request(s) awaiting approval.</a> @endif
  @if($al['offers'])<a href="{{ route('talent.applicants') }}"><b>{{ $al['offers'] }}</b> above-range offer(s) awaiting approval.</a> @endif
  @if($al['probation'])<a href="{{ route('talent.starters') }}"><b>{{ $al['probation'] }}</b> probation review(s) overdue.</a> @endif
  @if($al['statements'])<a href="{{ route('talent.starters') }}"><b>{{ $al['statements'] }}</b> written statement(s) of particulars overdue (Labour Act s. 13 – within two months).</a> @endif
  @if($al['replies'] && $canDisc)<a href="{{ route('talent.cases') }}"><b>{{ $al['replies'] }}</b> query reply deadline(s) passed – the case can proceed.</a> @endif
  @if($al['exits'] && $canDisc)<a href="{{ route('talent.exits') }}"><b>{{ $al['exits'] }}</b> leaver(s) past their last day – complete the clearance.</a> @endif
  @if($al['appraisals'])<b>{{ $al['appraisals'] }}</b> appraisal(s) past their deadline. @endif
</div></div>
@endif
<div class="g-2-1">
  <div>
    <div class="card flush"><div class="card-head"><h3>Recruitment</h3><a class="small" href="{{ route('talent.vacancies') }}">all vacancies</a></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="talOpenTable"><thead><tr><th>Vacancy</th><th>Department</th><th class="num">Posts</th><th class="num">Pipeline</th><th>Closes</th><th>Status</th></tr></thead><tbody>
      @forelse($open as $v)<tr><td><a href="{{ route('talent.vacancy', $v) }}"><b>{{ $v->title }}</b></a><div class="tiny muted">{{ $v->number }} · {{ \App\Models\TalentVacancy::REASONS[$v->reason] }}</div></td>
        <td class="small">{{ $v->department->name }}</td><td class="num">{{ $v->filled }}/{{ $v->headcount }}</td><td class="num">{{ $v->pipeline_count }}</td>
        <td class="small nowrap">{{ $v->closing_on?->format('d M') ?? '—' }}</td><td>@include('talent._badge', ['s' => $v->status, 'l' => $v->statusLabel()])</td></tr>
      @empty<tr><td colspan="6" class="muted">No vacancies open.</td></tr>@endforelse
      </tbody></table></div></div>
    <div class="card"><div class="card-head"><h3>Pipeline (open vacancies)</h3></div><div class="card-body">
      <div class="tal-funnel" id="talFunnel">@php $mx = max(1, $pipeline->max() ?? 1); @endphp
      @foreach(['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'] as $st)<div class="tal-funnel-row"><span class="small">{{ \App\Models\TalentApplicant::STAGES[$st] }}</span>
        <div class="tal-funnel-bar"><i style="width:{{ round(($pipeline[$st] ?? 0) / $mx * 100) }}%" class="tal-{{ $st }}"></i></div><b class="small">{{ $pipeline[$st] ?? 0 }}</b></div>@endforeach
      </div></div></div>
    @if($canDisc)
    <div class="card flush"><div class="card-head"><h3>Leavers working notice</h3><a class="small" href="{{ route('talent.exits') }}">exits &amp; turnover</a></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="talExitsTable"><thead><tr><th>Employee</th><th>Type</th><th>Last day</th><th>Final pay by</th></tr></thead><tbody>
      @forelse($exits as $x)<tr class="{{ $x->last_day->toDateString() < $today ? 'row-red' : '' }}"><td><a href="{{ route('talent.exit', $x) }}"><b>{{ $x->employee->name }}</b></a><div class="tiny muted">{{ $x->employee->department?->name }}</div></td>
        <td class="small">{{ $x->typeLabel() }}</td><td class="small nowrap">{{ $x->last_day->format('d M Y') }}</td><td class="small nowrap">{{ $x->final_pay_due->format('d M Y') }}</td></tr>
      @empty<tr><td colspan="4" class="muted">Nobody is working notice.</td></tr>@endforelse
      </tbody></table></div></div>
    @endif
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>My interview panels</h3></div><div class="card-body" id="talMyPanels">
      @forelse($mine as $i)<div class="small mb-1"><a href="{{ route('talent.interview', $i) }}"><b>{{ $i->applicant->name }}</b></a> – {{ $i->applicant->vacancy->title }}<div class="tiny muted">{{ $i->scheduled_at->format('D d M H:i') }} · {{ $i->kindLabel() }}{{ $i->location ? ' · '.$i->location : '' }}</div></div>
      @empty<div class="muted small">You are not on any upcoming panel.</div>@endforelse
    </div></div>
    <div class="card"><div class="card-head"><h3>Upcoming interviews</h3></div><div class="card-body" id="talInterviews">
      @forelse($interviews as $i)<div class="small mb-1"><b>{{ $i->scheduled_at->format('D d M H:i') }}</b> · {{ $i->applicant->name }}<div class="tiny muted">{{ $i->applicant->vacancy->title }} · round {{ $i->round }} · {{ $i->kindLabel() }}</div></div>
      @empty<div class="muted small">None scheduled.</div>@endforelse
    </div></div>
    <div class="card"><div class="card-head"><h3>Probation ending (30 days)</h3></div><div class="card-body" id="talProbation">
      @forelse($probation as $e)<div class="small mb-1 {{ $e->probation_end->toDateString() < $today ? 'text-red' : '' }}">@if($u->hasPermission('talent.recruit') || $u->hasPermission('talent.appraise'))<a href="{{ route('talent.starter', $e) }}"><b>{{ $e->name }}</b></a>@else<b>{{ $e->name }}</b>@endif
        <span class="muted">– {{ $e->designation }}</span><div class="tiny muted">ends {{ $e->probation_end->format('d M Y') }}{{ $e->probation_end->toDateString() < $today ? ' · OVERDUE' : '' }}</div></div>
      @empty<div class="muted small">No probation periods ending.</div>@endforelse
    </div></div>
  </div>
</div>
@endsection
