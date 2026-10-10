@extends('layouts.app')
@section('title', $c->number)
@section('crumbs') Talent Management / <a href="{{ route('talent.cases') }}">Discipline &amp; Grievances</a> / {{ $c->number }} @endsection
@php $disc = $c->kind === 'discipline'; $today = bdate()->toDateString(); $canDecide = in_array($c->status, ['query', 'hearing'], true) && ($c->replied_on || $c->reply_due?->toDateString() < $today) && ($c->status !== 'hearing' || $c->hearing_held); @endphp
@section('actions')
  @if($c->query_issued_on)<a class="btn" href="{{ route('talent.case.letter', [$c, 'query']) }}" id="talQueryLetter"><x-icon name="print"/> Query letter</a>@endif
  @if($c->outcome && $c->outcome !== 'none')<a class="btn" href="{{ route('talent.case.letter', [$c, 'outcome']) }}" id="talOutcomeLetter"><x-icon name="print"/> Outcome letter</a>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $disc ? 'Disciplinary case' : 'Grievance' }} {{ $c->number }} @include('talent._badge', ['s' => $c->status, 'l' => $c->statusLabel()])</h3>@if($c->confidential)<span class="badge badge-red">Confidential</span>@endif</div>
      <div class="card-body"><div class="kv-grid" id="talCaseKv">
        <div><span>Employee</span><b>{{ $c->employee->name }} · {{ $c->employee->designation }}</b></div><div><span>Category</span><b>{{ $c->categoryLabel() }}{{ $c->severity ? ' · '.\App\Models\TalentCase::SEVERITY[$c->severity] : '' }}</b></div>
        <div><span>Incident / raised</span><b>{{ $c->incident_on->format('d M Y') }}</b></div><div><span>Logged by</span><b>{{ $c->raiser?->name }}</b></div>
        @if($c->query_issued_on)<div><span>Query</span><b>issued {{ $c->query_issued_on->format('d M') }}, reply by {{ $c->reply_due->format('d M Y') }}</b></div>
        <div><span>Reply</span><b class="{{ ! $c->replied_on && $c->reply_due->toDateString() < $today ? 'text-red' : '' }}">{{ $c->replied_on ? 'received '.$c->replied_on->format('d M Y').($c->replied_on->gt($c->reply_due) ? ' (late)' : '') : ($c->reply_due->toDateString() < $today ? 'none – deadline passed' : 'awaited') }}</b></div>@endif
        @if($c->hearing_at)<div><span>Hearing</span><b>{{ $c->hearing_at->format('d M Y H:i') }}{{ $c->hearing_held ? ' · held' : '' }}</b></div><div><span>Panel</span><b>{{ $c->panel }}{{ $c->representative ? ' · representative: '.$c->representative : '' }}</b></div>@endif
        @if($c->outcome)<div><span>Decision</span><b>{{ $c->finding === 'proven' ? 'Proven' : 'Not proven' }} – {{ $c->outcomeLabel() }}{{ $c->suspension_days ? ' ('.$c->suspension_days.' days, '.($c->suspension_paid ? 'paid' : 'unpaid').')' : '' }}</b></div>
          <div><span>Decided</span><b>{{ $c->decider?->name }} · {{ $c->outcome_on?->format('d M Y') }}{{ $c->expires_on ? ' · on file until '.$c->expires_on->format('d M Y') : '' }}</b></div>@endif
        @if($c->exitRecord)<div><span>Exit</span><b><a href="{{ route('talent.exit', $c->exitRecord) }}">{{ $c->exitRecord->number }}</a></b></div>@endif
      </div>
      <h4 class="mt-1">{{ $disc ? 'Allegation' : 'Grievance' }}</h4><p class="small pre-line">{{ $c->description }}</p>
      @if($c->reply)<h4>Employee's reply</h4><p class="small pre-line">{{ $c->reply }}</p>@endif
      @if($c->hearing_notes)<h4>Hearing notes</h4><p class="small pre-line">{{ $c->hearing_notes }}</p>@endif
      @if($c->resolution)<h4>{{ $disc ? 'Notes' : 'Resolution' }}</h4><p class="small pre-line">{{ $c->resolution }}</p>@endif
    </div></div>
    @if($disc && $history->isNotEmpty())<div class="card flush"><div class="card-head"><h3>Previous disciplinary record</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="talCaseHist">
      <thead><tr><th>Case</th><th>Category</th><th>Outcome</th><th>On file until</th></tr></thead><tbody>
      @foreach($history as $h)<tr class="{{ $h->expires_on && $h->expires_on->toDateString() >= $today ? 'row-amber' : '' }}"><td class="small">{{ $h->number }} · {{ $h->incident_on->format('d M Y') }}</td><td class="small">{{ $h->categoryLabel() }}</td><td class="small">{{ $h->outcomeLabel() ?? $h->statusLabel() }}</td><td class="small">{{ $h->expires_on ? $h->expires_on->format('d M Y').($h->expires_on->toDateString() < $today ? ' (expired)' : '') : '—' }}</td></tr>@endforeach
    </tbody></table></div></div>@endif
  </div>
  <div>
    @if($disc)
      @if($c->status === 'open')<div class="card"><div class="card-head"><h3>1 · Issue a query</h3></div><div class="card-body"><form method="post" action="{{ route('talent.case.action', [$c, 'query']) }}">@csrf
        <x-field name="reply_due" label="Reply by" type="date" :value="bdate()->addDays($cfg['query_days'])->toDateString()"/><button class="btn btn-primary" id="talIssueQuery">Issue query</button></form></div></div>@endif
      @if($c->status === 'query' && ! $c->replied_on)<div class="card"><div class="card-head"><h3>2 · Record the reply</h3></div><div class="card-body"><form method="post" action="{{ route('talent.case.action', [$c, 'reply']) }}">@csrf
        <x-field name="reply" label="Employee's written reply" type="textarea" required/><x-field name="replied_on" label="Received" type="date" :value="bdate()->toDateString()"/><button class="btn btn-primary" id="talRecordReply">Record reply</button></form></div></div>@endif
      @if(in_array($c->status, ['query', 'hearing'], true) && ! $c->hearing_held)<div class="card"><div class="card-head"><h3>3 · Disciplinary hearing</h3></div><div class="card-body">
        <form method="post" action="{{ route('talent.case.action', [$c, 'hearing']) }}">@csrf<x-field name="hearing_at" label="Date & time" type="datetime-local" :value="$c->hearing_at?->format('Y-m-d\TH:i')" required/>
          <x-field name="panel" label="Panel" :value="$c->panel" required placeholder="HR Manager, Head of Department"/><x-field name="representative" label="Employee's representative" :value="$c->representative" hint="Colleague or union representative"/>
          <button class="btn" id="talScheduleHearing">{{ $c->hearing_at ? 'Reschedule' : 'Schedule hearing' }}</button></form>
        @if($c->status === 'hearing')<form method="post" action="{{ route('talent.case.action', [$c, 'held']) }}" class="mt-1">@csrf<x-field name="hearing_notes" label="Hearing notes – the employee's account and the evidence" type="textarea" required/><button class="btn btn-primary" id="talHearingHeld">Record hearing</button></form>@endif
      </div></div>@endif
      @if($canDecide)<div class="card" id="talDecideCard"><div class="card-head"><h3>4 · Decision</h3></div><div class="card-body">
        @if($warnings->isNotEmpty())<p class="small text-amber">Live warnings: {{ $warnings->map(fn($w) => $w->outcomeLabel().' until '.$w->expires_on->format('d M Y'))->implode('; ') }}</p>@endif
        <form method="post" action="{{ route('talent.case.action', [$c, 'decide']) }}">@csrf
          <x-field name="finding" label="Finding" type="select" :options="['proven' => 'Misconduct proven', 'not_proven' => 'Not proven']" required/>
          <x-field name="outcome" label="Sanction" type="select" :options="$c->hearing_held ? \App\Models\TalentCase::OUTCOMES : collect(\App\Models\TalentCase::OUTCOMES)->except(['final', 'suspension', 'dismissal'])" :hint="$c->hearing_held ? null : 'A final warning, suspension or dismissal needs a hearing'"/>
          <x-field name="suspension_days" label="Suspension days" type="number" min="1" max="30"/>
          <label class="check small"><input type="checkbox" name="suspension_paid" value="1"> Suspension with pay</label>
          <x-field name="resolution" label="Reasons" type="textarea"/>
          @if($c->hearing_held && $u->hasPermission('talent.approve'))<label class="check small"><input type="checkbox" name="fair_confirmed" value="1"> For a dismissal: I confirm none of the unfair reasons in s. 63 (union membership, a complaint, gender, race, religion, pregnancy, disability…) plays any part</label>@endif
          <button class="btn btn-primary mt-1" id="talDecide">Record decision</button></form>
      </div></div>@endif
      @if(in_array($c->status, ['open', 'query'], true))<form method="post" action="{{ route('talent.case.action', [$c, 'close']) }}" class="card">@csrf<div class="card-body"><x-field name="reason" label="Withdraw the case – reason"/><button class="btn btn-sm">Withdraw</button></div></form>@endif
    @else
      @if(in_array($c->status, ['open', 'investigating'], true))<div class="card"><div class="card-head"><h3>Handle the grievance</h3></div><div class="card-body">
        @if($c->status === 'open')<form method="post" action="{{ route('talent.case.action', [$c, 'investigating']) }}">@csrf<button class="btn" id="talGrvInvestigate">Start investigating</button></form>@endif
        <form method="post" action="{{ route('talent.case.action', [$c, 'resolved']) }}" class="mt-1">@csrf<x-field name="resolution" label="Resolution agreed" type="textarea" required/><button class="btn btn-primary" id="talGrvResolve">Resolve</button></form>
        <form method="post" action="{{ route('talent.case.action', [$c, 'withdrawn']) }}" class="mt-1">@csrf<x-field name="resolution" label="Withdrawn – note"/><button class="btn btn-sm">Withdrawn by employee</button></form>
      </div></div>@endif
    @endif
    @if(in_array($c->status, ['decided', 'resolved'], true))<form method="post" action="{{ route('talent.case.action', [$c, 'close']) }}" class="card">@csrf<div class="card-body"><button class="btn" id="talCaseClose">Close case</button></div></form>@endif
  </div>
</div>
@endsection
