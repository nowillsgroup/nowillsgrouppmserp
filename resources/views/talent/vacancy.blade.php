@extends('layouts.app')
@section('title', $v->title)
@section('crumbs') Talent Management / <a href="{{ route('talent.vacancies') }}">Vacancies</a> / {{ $v->number }} @endsection
@php $canRec = $u->hasPermission('talent.recruit'); $canApp = $u->hasPermission('talent.approve'); @endphp
@section('actions')
  @if($canRec && $v->status === 'open')<button class="btn btn-primary" data-modal="talAppModal" id="talAddApplicant"><x-icon name="plus"/> Add applicant</button>@endif
  @if($canRec && in_array($v->status, ['draft', 'pending'], true))<button class="btn" data-modal="talVacEdit" id="talVacEditBtn"><x-icon name="edit"/> Edit</button>@endif
  @if($canRec && $v->status === 'draft')<form method="post" action="{{ route('talent.vacancy.submit', $v) }}" class="inline">@csrf<button class="btn btn-primary" id="talVacSubmit">Send for approval</button></form>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $v->title }} @include('talent._badge', ['s' => $v->status, 'l' => $v->statusLabel()])</h3><span class="small muted">{{ $v->number }}</span></div>
      <div class="card-body"><div class="kv-grid" id="talVacKv">
        <div><span>Department</span><b>{{ $v->department->name }}</b></div><div><span>Posts</span><b>{{ $v->filled }} of {{ $v->headcount }} filled</b></div>
        <div><span>Type</span><b>{{ $v->typeLabel() }}{{ $v->contract_months ? ' · '.$v->contract_months.' months' : '' }}</b></div><div><span>Salary range</span><b>{{ $v->range() }}</b></div>
        <div><span>Reason</span><b>{{ \App\Models\TalentVacancy::REASONS[$v->reason] }}{{ $v->replaces ? ' – '.$v->replaces->name : '' }}</b></div><div><span>Closing</span><b>{{ $v->closing_on?->format('d M Y') ?? 'Open until filled' }}</b></div>
        <div><span>Requested by</span><b>{{ $v->requester?->name ?? '—' }}</b></div><div><span>Approved</span><b>{{ $v->approver ? $v->approver->name.' · '.$v->approved_at->format('d M Y') : '—' }}</b></div>
        <div><span>Careers page</span><b>{{ $v->is_public ? 'Advertised' : 'Internal only' }}</b></div>@if($v->filled_on)<div><span>Filled</span><b>{{ $v->filled_on->format('d M Y') }}</b></div>@endif
      </div>
      @if($v->approval_note)<div class="alert {{ str_starts_with($v->approval_note, 'Returned') ? 'alert-warn' : 'alert-info' }} mt-1"><div>{{ $v->approval_note }}</div></div>@endif
      @if($v->closed_reason)<p class="small mt-1"><b>Cancelled:</b> {{ $v->closed_reason }}</p>@endif
      @if($v->justification)<h4 class="mt-1">Justification</h4><p class="small pre-line">{{ $v->justification }}</p>@endif
      @if($v->description)<h4 class="mt-1">Job description</h4><p class="small pre-line">{{ $v->description }}</p>@endif
      @if($v->requirements)<h4 class="mt-1">Requirements</h4><p class="small pre-line">{{ $v->requirements }}</p>@endif
      </div></div>
    <div class="card flush"><div class="card-head"><h3>Applicants ({{ $apps->count() }})</h3>@if($canRec)<a class="small" href="{{ route('talent.applicants', ['vacancy' => $v->id]) }}">pipeline board</a>@endif</div>
      <div class="card-body table-wrap"><table class="table table-compact" id="talVacApps"><thead><tr><th>Applicant</th><th>Source</th><th class="num">Exp.</th><th class="num">Expects</th><th class="num">Interview score</th><th>Stage</th></tr></thead><tbody>
      @forelse($apps as $a)@php $sc = $a->interviews->flatMap->scorecards; @endphp<tr>
        <td>@if($canRec)<a href="{{ route('talent.applicant', $a) }}"><b>{{ $a->name }}</b></a>@else<b>{{ $a->name }}</b>@endif<div class="tiny muted">{{ $a->number }} · {{ $a->created_at->format('d M') }}</div></td>
        <td class="small">{{ $a->sourceLabel() }}</td><td class="num">{{ $a->experience_years !== null ? rtrim(rtrim(number_format($a->experience_years, 1), '0'), '.').' y' : '—' }}</td>
        <td class="num">{{ $a->expected_salary ? money($a->expected_salary) : '—' }}</td><td class="num">{{ $sc->isNotEmpty() ? number_format($sc->avg('average'), 2) : '—' }}</td>
        <td>@include('talent._badge', ['s' => $a->stage, 'l' => $a->stageLabel()])</td></tr>
      @empty<tr><td colspan="6" class="muted">No applicants yet.</td></tr>@endforelse
      </tbody></table></div></div>
  </div>
  <div>
    @if($v->status === 'pending' && $canApp)
    <div class="card" id="talApproveCard"><div class="card-head"><h3>Approval</h3></div><div class="card-body">
      @if($chk)<p class="small">Establishment for {{ $v->department->name }}: <b>{{ $chk['establishment'] }}</b>. After this post: <b class="{{ $chk['over'] ? 'text-red' : '' }}">{{ $chk['after'] }}</b>{{ $chk['over'] ? ' – '.$chk['over'].' over' : '' }}.</p>
      @else<p class="small muted">No establishment is set for this department.</p>@endif
      @if($v->requested_by === $u->id)<p class="small text-amber">You requested this post – another approver must approve it.</p>@endif
      <form method="post" action="{{ route('talent.vacancy.approve', $v) }}">@csrf<x-field name="note" label="Approval note" :hint="$chk && $chk['over'] && $v->reason !== 'replacement' ? 'Required – the post goes over the establishment' : null"/>
        <button class="btn btn-primary" id="talVacApprove">Approve &amp; open</button></form>
      <form method="post" action="{{ route('talent.vacancy.return', $v) }}" class="mt-1">@csrf<x-field name="note" label="Return with a comment"/><button class="btn" id="talVacReturn">Return to requester</button></form>
    </div></div>
    @endif
    @if($canRec && in_array($v->status, ['open', 'on_hold', 'draft', 'pending'], true))
    <div class="card"><div class="card-head"><h3>Status</h3></div><div class="card-body">
      @if($v->status === 'open')<form method="post" action="{{ route('talent.vacancy.status', $v) }}" class="inline">@csrf<input type="hidden" name="to" value="on_hold"><button class="btn btn-sm" id="talVacHold">Put on hold</button></form>
        <form method="post" action="{{ route('talent.vacancy.status', $v) }}" class="inline">@csrf<input type="hidden" name="to" value="filled"><button class="btn btn-sm" onclick="return confirm('Close the vacancy as filled? Open applicants will be told the position is filled.')">Close as filled</button></form>@endif
      @if($v->status === 'on_hold')<form method="post" action="{{ route('talent.vacancy.status', $v) }}" class="inline">@csrf<input type="hidden" name="to" value="open"><button class="btn btn-sm" id="talVacReopen">Re-open</button></form>@endif
      <form method="post" action="{{ route('talent.vacancy.status', $v) }}" class="mt-1">@csrf<input type="hidden" name="to" value="cancelled"><x-field name="reason" label="Cancel the vacancy – reason"/><button class="btn btn-sm btn-danger" id="talVacCancel">Cancel vacancy</button></form>
    </div></div>
    @endif
    @if($v->status === 'open' && $v->is_public)<div class="card"><div class="card-body small">Advertised at <a href="{{ route('careers.show', [hotel()->code, $v->id]) }}" target="_blank">{{ route('careers.show', [hotel()->code, $v->id]) }}</a></div></div>@endif
  </div>
</div>
@if($canRec && in_array($v->status, ['draft', 'pending'], true))
<div class="modal" id="talVacEdit"><div class="modal-box lg"><form method="post" action="{{ route('talent.vacancy.update', $v) }}">@csrf
  <div class="modal-head"><h3>Edit {{ $v->number }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('talent._vacancy_fields', ['v' => $v])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endif
@if($canRec && $v->status === 'open')
<div class="modal" id="talAppModal"><div class="modal-box lg"><form method="post" action="{{ route('talent.applicants.store', $v) }}" enctype="multipart/form-data" data-reset id="talAppForm">@csrf
  <div class="modal-head"><h3>Add an applicant – {{ $v->title }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="name" label="Full name" required/><x-field name="phone" label="Phone"/><x-field name="email" label="E-mail" type="email"/>
    <x-field name="source" label="Source" type="select" :options="collect(\App\Models\TalentApplicant::SOURCES)->except('careers')" value="walk_in" :placeholder="false"/>
    <x-field name="referred_by_employee_id" label="Referred by (staff)" type="select" :options="$employees->pluck('name', 'id')" hint="Staff referrals only"/>
    <x-field name="current_employer" label="Current / last employer"/>
    <x-field name="experience_years" label="Experience (years)" type="number" step="0.5" min="0"/><x-field name="expected_salary" label="Expected salary (GH₵)" type="number" step="0.01"/>
    <div class="field"><label>CV (PDF / Word / photo, max 5 MB)</label><input type="file" name="cv" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></div>
    <x-field name="cover_note" label="Notes" type="textarea" class="span-all"/>
    <label class="check span-all small"><input type="checkbox" name="consent" value="1" checked> The applicant agreed to the hotel keeping their details for recruitment</label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Add applicant</button></div>
</form></div></div>
@endif
@endsection
