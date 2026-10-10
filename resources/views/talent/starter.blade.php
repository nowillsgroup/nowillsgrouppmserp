@extends('layouts.app')
@section('title', $e->name)
@section('crumbs') Talent Management / <a href="{{ route('talent.starters') }}">New Starters</a> / {{ $e->name }} @endsection
@php $canApp = $u->hasPermission('talent.appraise'); $onProb = $e->is_active && $e->probation_end && ! $e->confirmed_on; @endphp
@section('actions')<a class="btn" href="{{ route('talent.statement', $e) }}" id="talStatementPrint"><x-icon name="print"/> Written statement</a>@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $e->name }}</h3><span class="small muted">{{ $e->emp_code }}</span></div><div class="card-body"><div class="kv-grid" id="talStarterKv">
      <div><span>Position</span><b>{{ $e->designation }} · {{ $e->department?->name }}</b></div><div><span>Type</span><b>{{ \App\Models\TalentVacancy::TYPES[$e->employment_type] ?? $e->employment_type }}</b></div>
      <div><span>Joined</span><b>{{ $e->joining_date?->format('d M Y') }}</b></div><div><span>Salary</span><b>{{ money($e->salary) }}</b></div>
      <div><span>Line manager</span><b>{{ $e->manager?->name ?? '—' }}</b></div><div><span>Contract end</span><b>{{ $e->contract_end?->format('d M Y') ?? '—' }}</b></div>
      <div><span>Probation</span><b>{{ $e->confirmed_on ? 'Confirmed '.$e->confirmed_on->format('d M Y') : ($e->probation_end ? 'Ends '.$e->probation_end->format('d M Y') : '—') }}</b></div>
      <div><span>Written statement</span><b>{{ $e->statement_issued_on ? 'Issued '.$e->statement_issued_on->format('d M Y') : 'Due by '.\App\Services\TalentService::statementDue($e)?->format('d M Y') }}</b></div>
      @if($hire)<div><span>Recruited via</span><b>{{ $hire->number }} · {{ $hire->vacancy->number }}</b></div>@endif
    </div></div></div>
    <div class="card flush"><div class="card-head"><h3>Onboarding checklist</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="talTasks">
      <thead><tr><th>Item</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>
      @forelse($tasks as $t)<tr class="{{ $t['overdue'] ? 'row-red' : '' }}"><td>{{ $t['label'] }}@if($t['task']->note)<div class="tiny muted">{{ $t['task']->note }}</div>@endif</td><td class="small nowrap">{{ $t['task']->due_on->format('d M Y') }}</td>
        <td class="small">@if($t['auto'])<span class="text-green">✓ on the employee record</span>@elseif($t['done'])<span class="text-green">✓ {{ $t['task']->doer?->name }} · {{ $t['task']->done_at->format('d M') }}</span>@elseif($t['overdue'])<b class="text-red">Overdue</b>@else Pending @endif</td>
        <td class="nowrap">@if(! $t['auto'] && $t['task']->item !== 'statement')<form method="post" action="{{ route('talent.task.toggle', $t['task']) }}" class="inline">@csrf<input type="hidden" name="done" value="{{ $t['done'] ? 0 : 1 }}"><button class="btn btn-sm">{{ $t['done'] ? 'Undo' : 'Done' }}</button></form>
          @elseif($t['field'] && ! $t['auto'] && $t['task']->item !== 'statement')<span class="tiny muted">update employee record</span>@endif</td></tr>
      @empty<tr><td colspan="4" class="muted">No onboarding checklist (employee joined before v6.9).</td></tr>@endforelse
    </tbody></table></div></div>
    @if($reviews->isNotEmpty())<div class="card flush"><div class="card-head"><h3>Probation reviews</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="talProbHist">
      <thead><tr><th>Reviewed</th><th>Outcome</th><th>Rating</th><th>By</th></tr></thead><tbody>
      @foreach($reviews as $r)<tr><td class="small">{{ $r->reviewed_on->format('d M Y') }}</td><td class="small">{{ \App\Models\TalentProbation::OUTCOMES[$r->outcome] }}{{ $r->extend_months ? ' by '.$r->extend_months.' months' : '' }}@if($r->comments)<div class="tiny muted">{{ $r->comments }}</div>@endif</td><td>{{ $r->rating ? str_repeat('★', $r->rating) : '—' }}</td><td class="small">{{ $r->reviewer?->name }}</td></tr>@endforeach
    </tbody></table></div></div>@endif
  </div>
  <div>
    @if(! $e->statement_issued_on)
    <div class="card" id="talStatementCard"><div class="card-head"><h3>Written statement (s. 13)</h3></div><div class="card-body">
      <p class="small">Print the statement, have it signed, then record the date it was given to the employee.</p>
      <form method="post" action="{{ route('talent.statement.issue', $e) }}">@csrf<x-field name="issued_on" label="Given on" type="date" :value="bdate()->toDateString()"/><button class="btn btn-primary" id="talStatementIssue">Record as issued</button></form>
    </div></div>
    @endif
    @if($onProb && $canApp)
    <div class="card" id="talProbCard"><div class="card-head"><h3>Probation review</h3></div><div class="card-body">
      <form method="post" action="{{ route('talent.probation', $e) }}">@csrf
        <x-field name="outcome" label="Outcome" type="select" :options="$extended ? collect(\App\Models\TalentProbation::OUTCOMES)->except('extend') : \App\Models\TalentProbation::OUTCOMES" required/>
        @unless($extended)<x-field name="extend_months" label="Extend by (months)" type="number" min="1" max="6" hint="Probation can be extended once"/>@endunless
        <x-field name="rating" label="Rating" type="select" :options="[5 => '5 – Outstanding', 4 => '4 – Exceeds', 3 => '3 – Meets', 2 => '2 – Needs improvement', 1 => '1 – Unsatisfactory']"/>
        <x-field name="comments" label="Comments" type="textarea" placeholder="Required for an extension or termination"/>
        <button class="btn btn-primary" id="talProbSave">Record review</button></form>
    </div></div>
    @endif
  </div>
</div>
@endsection
