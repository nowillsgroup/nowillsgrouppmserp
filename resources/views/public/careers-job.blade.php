@extends('public.layout')
@section('title', $v->title)
@section('content')
<div class="grid g-2" style="align-items:start">
  <div class="card"><div class="card-head"><h3>{{ $v->title }}</h3><a class="small" href="{{ route('careers.index', $hotel->code) }}">all jobs</a></div><div class="card-body">
    <p class="small muted">{{ $v->department->name }} · {{ \App\Models\TalentVacancy::TYPES[$v->employment_type] }}{{ $v->contract_months ? ' · '.$v->contract_months.' months' : '' }} · {{ $v->closing_on ? 'apply by '.$v->closing_on->format('d M Y') : 'open until filled' }}</p>
    @if($v->description)<p class="pre-line">{{ $v->description }}</p>@endif
    @if($v->requirements)<h4>Requirements</h4><p class="pre-line small">{{ $v->requirements }}</p>@endif
  </div></div>
  <form method="post" action="{{ route('careers.apply', [$hotel->code, $v->id]) }}" enctype="multipart/form-data" class="card" id="careersForm">@csrf
    <div class="card-head"><h3>Apply</h3></div>
    <div class="card-body form-grid" style="grid-template-columns:1fr 1fr">
      <x-field name="name" label="Full name" required class="span-all"/>
      <x-field name="phone" label="Mobile" required/><x-field name="email" label="E-mail" type="email"/>
      <x-field name="experience_years" label="Years of experience" type="number" step="0.5" min="0"/><x-field name="current_employer" label="Current / last employer"/>
      <div class="field span-all"><label for="cv">CV (PDF or Word, max 5 MB)</label><input type="file" name="cv" id="cv" accept=".pdf,.doc,.docx"></div>
      <x-field name="cover_note" label="Why you would like this job" type="textarea" class="span-all"/>
      <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
      <label class="check span-all small"><input type="checkbox" name="consent" value="1" required> I agree that {{ $hotel->name }} may keep my details to consider this application. Unsuccessful applications are deleted after a few months.</label>
    </div>
    <div class="card-foot"><button class="btn btn-primary" id="careersSubmit">Send application</button></div>
  </form>
</div>
@endsection
