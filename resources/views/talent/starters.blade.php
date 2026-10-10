@extends('layouts.app')
@section('title', 'New Starters')
@section('crumbs') Talent Management / New Starters @endsection
@section('content')
<p class="small muted">Employees who joined in the last six months, are on probation, or have no written statement of particulars on file. Under the Labour Act 2003 (s. 13) the statement is due within two months of starting.</p>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="talStarters"><thead><tr><th>Employee</th><th>Department</th><th>Joined</th><th>Onboarding</th><th>Written statement</th><th>Probation</th></tr></thead><tbody>
@forelse($emps as $e)@php $tasks = \App\Services\TalentService::onboarding($e); $done = $tasks->where('done', true)->count(); $due = \App\Services\TalentService::statementDue($e); $late = in_array($e->id, $overdue, true);
  $probLate = $e->probation_end && ! $e->confirmed_on && $e->probation_end->toDateString() < bdate()->toDateString(); @endphp
<tr class="{{ $late || $probLate ? 'row-red' : '' }}"><td><a href="{{ route('talent.starter', $e) }}"><b>{{ $e->name }}</b></a><div class="tiny muted">{{ $e->emp_code }} · {{ $e->designation }}</div></td>
  <td class="small">{{ $e->department?->name }}</td><td class="small nowrap">{{ $e->joining_date?->format('d M Y') }}</td>
  <td class="small">@if($tasks->count())<div class="tal-prog"><i style="width:{{ round($done / $tasks->count() * 100) }}%"></i></div>{{ $done }}/{{ $tasks->count() }}{{ $tasks->where('overdue', true)->count() ? ' · '.$tasks->where('overdue', true)->count().' overdue' : '' }}@else<span class="muted">—</span>@endif</td>
  <td class="small">@if($e->statement_issued_on)<span class="text-green">Issued {{ $e->statement_issued_on->format('d M Y') }}</span>@elseif($late)<b class="text-red">Overdue</b> (due {{ $due?->format('d M Y') }})@else Due {{ $due?->format('d M Y') }}@endif</td>
  <td class="small">@if($e->confirmed_on)<span class="text-green">Confirmed {{ $e->confirmed_on->format('d M Y') }}</span>@elseif($e->probation_end)<span class="{{ $probLate ? 'text-red bold' : '' }}">Ends {{ $e->probation_end->format('d M Y') }}{{ $probLate ? ' – review overdue' : '' }}</span>@else<span class="muted">—</span>@endif</td></tr>
@empty<tr><td colspan="6" class="muted">No new starters.</td></tr>@endforelse
</tbody></table></div></div>
@endsection
