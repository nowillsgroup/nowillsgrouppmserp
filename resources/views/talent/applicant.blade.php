@extends('layouts.app')
@section('title', $a->name)
@section('crumbs') Talent Management / <a href="{{ route('talent.applicants', ['vacancy' => $a->vacancy_id]) }}">Applicants</a> / {{ $a->number }} @endsection
@php $open = $a->isOpen(); $live = $a->offers->first(fn($o) => in_array($o->status, ['pending', 'issued'], true)); $hasScore = $a->interviews->flatMap->scorecards->isNotEmpty(); @endphp
@section('actions')
  @if($a->cv_path)<a class="btn" href="{{ route('talent.applicant.cv', $a) }}" id="talCv"><x-icon name="download"/> CV</a>@endif
  @if(in_array($a->stage, ['screening', 'interview'], true))<button class="btn" data-modal="talIntModal" id="talIntNew"><x-icon name="calendar"/> Schedule interview</button>@endif
  @if($a->stage === 'interview' && ! $live && $hasScore)<button class="btn btn-primary" data-modal="talOfferModal" id="talOfferNew"><x-icon name="receipt"/> Make offer</button>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $a->name }} @include('talent._badge', ['s' => $a->stage, 'l' => $a->stageLabel()])</h3><span class="small muted">{{ $a->number }}</span></div>
      <div class="card-body"><div class="kv-grid" id="talAppKv">
        <div><span>Vacancy</span><b><a href="{{ route('talent.vacancy', $a->vacancy) }}">{{ $a->vacancy->title }}</a> · {{ $a->vacancy->department->name }}</b></div>
        <div><span>Salary range</span><b>{{ $a->vacancy->range() }}</b></div>
        <div><span>Phone</span><b>{{ $a->phone ?? '—' }}</b></div><div><span>E-mail</span><b>{{ $a->email ?? '—' }}</b></div>
        <div><span>Source</span><b>{{ $a->sourceLabel() }}{{ $a->referrer ? ' – '.$a->referrer->name : '' }}</b></div><div><span>Current employer</span><b>{{ $a->current_employer ?? '—' }}</b></div>
        <div><span>Experience</span><b>{{ $a->experience_years !== null ? $a->experience_years.' years' : '—' }}</b></div><div><span>Expected salary</span><b>{{ $a->expected_salary ? money($a->expected_salary) : '—' }}</b></div>
        <div><span>Applied</span><b>{{ $a->created_at->format('d M Y') }}</b></div><div><span>Consent</span><b>{{ $a->consent_at ? $a->consent_at->format('d M Y') : 'Not recorded' }}</b></div>
        @if($a->screen_rating)<div><span>Screening</span><b>{{ str_repeat('★', $a->screen_rating) }}{{ str_repeat('☆', 5 - $a->screen_rating) }}</b></div>@endif
        @if($a->employee)<div><span>Hired as</span><b><a href="{{ route('talent.starter', $a->employee) }}">{{ $a->employee->emp_code }}</a></b></div>@endif
      </div>
      @if($a->reject_reason)<div class="alert alert-warn mt-1"><div><b>{{ $a->stageLabel() }}:</b> {{ $a->reject_reason }}</div></div>@endif
      @if($a->cover_note)<h4 class="mt-1">Cover note</h4><p class="small pre-line">{{ $a->cover_note }}</p>@endif
    </div></div>
    <div class="card flush"><div class="card-head"><h3>Interviews</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="talIntTable">
      <thead><tr><th>Round</th><th>When</th><th>Panel</th><th class="num">Score</th><th>Recommendations</th><th>Status</th><th></th></tr></thead><tbody>
      @forelse($a->interviews as $i)<tr><td><a href="{{ route('talent.interview', $i) }}">#{{ $i->round }} {{ $i->kindLabel() }}</a></td><td class="small nowrap">{{ $i->scheduled_at->format('d M H:i') }}<div class="tiny muted">{{ $i->location }}</div></td>
        <td class="small">{{ $i->panelUsers()->pluck('name')->implode(', ') }}</td><td class="num">{{ $i->average() !== null ? number_format($i->average(), 2) : '—' }}</td>
        <td class="small">@foreach($i->scorecards as $s)<span class="badge badge-{{ ['hire' => 'green', 'hold' => 'amber', 'reject' => 'red'][$s->recommendation] }}" title="{{ $s->user->name }}">{{ \Illuminate\Support\Str::of($s->user->name)->explode(' ')->first() }}: {{ $s->recommendation }}</span> @endforeach</td>
        <td>@include('talent._badge', ['s' => $i->status, 'l' => \App\Models\TalentInterview::STATUSES[$i->status]])</td>
        <td class="nowrap">@if($i->status === 'scheduled')<form method="post" action="{{ route('talent.interview.status', $i) }}" class="inline">@csrf<select name="to" class="tal-sel"><option value="done">Held</option><option value="no_show">No-show</option><option value="cancelled">Cancelled</option></select><button class="btn btn-sm">Set</button></form>@endif</td></tr>
      @empty<tr><td colspan="7" class="muted">No interviews yet.</td></tr>@endforelse
    </tbody></table></div></div>
    <div class="card flush"><div class="card-head"><h3>Offers</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="talOfferTable">
      <thead><tr><th>Offer</th><th class="num">Salary</th><th>Start</th><th>Probation</th><th>Status</th><th></th></tr></thead><tbody>
      @forelse($a->offers as $o)<tr><td><b>{{ $o->number }}</b><div class="tiny muted">by {{ $o->creator?->name }}{{ $o->approver ? ' · approved '.$o->approver->name : '' }}</div></td>
        <td class="num">{{ money($o->salary) }}@if($o->above_range)<div class="tiny text-amber">above range</div>@endif</td><td class="small">{{ $o->start_date->format('d M Y') }}</td><td class="small">{{ $o->probation_months }} mo</td>
        <td>@include('talent._badge', ['s' => $o->status, 'l' => $o->statusLabel()])@if($o->decline_reason)<div class="tiny muted">{{ $o->decline_reason }}</div>@endif @if($o->respond_by && $o->status === 'issued')<div class="tiny muted">reply by {{ $o->respond_by->format('d M') }}</div>@endif</td>
        <td class="nowrap">@if($o->status !== 'pending')<a class="btn btn-sm" href="{{ route('talent.offer.letter', $o) }}"><x-icon name="print"/></a>@endif
          @if($o->status === 'pending' && $u->hasPermission('talent.approve'))<form method="post" action="{{ route('talent.offer.approve', $o) }}" class="inline">@csrf<button class="btn btn-sm btn-primary" id="talOfferApprove">Approve</button></form>@endif</td></tr>
      @empty<tr><td colspan="6" class="muted">No offers.</td></tr>@endforelse
    </tbody></table>
    @if($live)<div class="card-body">
      @if($live->status === 'issued')<form method="post" action="{{ route('talent.offer.respond', $live) }}" class="inline">@csrf<input type="hidden" name="action" value="accept"><button class="btn btn-primary" id="talOfferAccept" onclick="return confirm('Record acceptance and create the employee record?')"><x-icon name="check"/> Accepted – hire</button></form>@endif
      <form method="post" action="{{ route('talent.offer.respond', $live) }}" class="tal-inline-form">@csrf
        <select name="action" class="tal-sel">@if($live->status === 'issued')<option value="decline">Declined by applicant</option>@endif<option value="withdraw">Withdraw offer</option></select>
        <input name="reason" placeholder="Reason" required><button class="btn">Record</button></form>
    </div>@endif
    </div>
  </div>
  <div>
    @if($open)
    <div class="card" id="talMoveCard"><div class="card-head"><h3>Move applicant</h3></div><div class="card-body">
      @if($a->stage === 'applied')<form method="post" action="{{ route('talent.applicant.move', $a) }}">@csrf<input type="hidden" name="to" value="screening">
        <x-field name="rating" label="Screening rating" type="select" :options="[5 => '★★★★★ Strong', 4 => '★★★★ Good', 3 => '★★★ Possible', 2 => '★★ Weak', 1 => '★ Poor']"/><button class="btn btn-primary" id="talShortlist">Shortlist for screening</button></form>@endif
      @if($a->stage === 'screening')<p class="small">Schedule an interview to move the applicant forward.</p>@endif
      @if(in_array($a->stage, ['applied', 'screening', 'interview'], true))
      <form method="post" action="{{ route('talent.applicant.move', $a) }}" class="mt-1">@csrf
        <x-field name="to" label="Close the application" type="select" :options="['rejected' => 'Unsuccessful', 'withdrawn' => 'Withdrew']" :placeholder="false"/>
        <x-field name="reason" label="Reason" required placeholder="e.g. insufficient experience for the role"/><button class="btn btn-danger btn-sm" id="talReject">Close application</button></form>@endif
      @if($a->stage === 'offer')<p class="small muted">An offer is outstanding – record the reply or withdraw it.</p>@endif
    </div></div>
    @endif
    <div class="card"><div class="card-body tiny muted">Applicant data is kept for recruitment only. Unsuccessful applications are anonymised {{ $cfg['retention_months'] }} months after closing and the CV deleted (Data Protection Act 2012, Act 843). Hiring decisions must not discriminate on gender, race, religion, disability or social status (Labour Act s. 14).</div></div>
  </div>
</div>
@if(in_array($a->stage, ['screening', 'interview'], true))
<div class="modal" id="talIntModal"><div class="modal-box"><form method="post" action="{{ route('talent.interviews.store', $a) }}" data-reset>@csrf
  <div class="modal-head"><h3>Schedule an interview</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="kind" label="Type" type="select" :options="\App\Models\TalentInterview::KINDS" value="in_person" :placeholder="false"/>
    <x-field name="scheduled_at" label="Date & time" type="datetime-local" required/>
    <x-field name="location" label="Where" placeholder="HR office / Zoom / kitchen" class="span-all"/>
    <div class="field span-all"><label>Panel (hold Ctrl to choose several)</label><select name="panel[]" multiple size="6" required>@foreach($users as $x)<option value="{{ $x->id }}" @selected($x->id === $u->id)>{{ $x->name }}</option>@endforeach</select></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Schedule</button></div>
</form></div></div>
@endif
@if($a->stage === 'interview' && ! $live && $hasScore)
<div class="modal" id="talOfferModal"><div class="modal-box"><form method="post" action="{{ route('talent.offer.store', $a) }}" data-reset>@csrf
  <div class="modal-head"><h3>Make an offer</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="salary" label="Monthly salary (GH₵)" type="number" step="0.01" :min="$mw" :value="$a->vacancy->salary_min" required :hint="'Range '.$a->vacancy->range().' – above it needs approval'"/>
    <x-field name="start_date" label="Start date" type="date" :value="bdate()->addDays(14)->toDateString()" required/>
    <x-field name="probation_months" label="Probation (months)" type="number" min="0" :max="$cfg['probation_months']" :value="$cfg['probation_months']"/>
    <x-field name="employment_type" label="Type" type="select" :options="\App\Models\TalentVacancy::TYPES" :value="$a->vacancy->employment_type" :placeholder="false"/>
    <x-field name="contract_months" label="Contract (months)" type="number" min="1" max="60" :value="$a->vacancy->contract_months" hint="Fixed-term only"/>
    <x-field name="notes" label="Notes / conditions" type="textarea" class="span-all" placeholder="e.g. subject to references and a food-handler medical"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Make offer</button></div>
</form></div></div>
@endif
@endsection
