@extends('layouts.app')
@section('title', 'Edit survey – '.$survey->name)
@section('crumbs') <a href="{{ route('rep.surveys.index') }}">Surveys</a> / {{ $survey->name }} @endsection
@section('actions')<a class="btn" href="{{ route('rep.surveys.preview', $survey) }}" target="_blank"><x-icon name="eye"/> Preview</a>@endsection
@section('content')
@include('reputation._nav')
<form method="post" action="{{ route('rep.surveys.update', $survey) }}" id="surveyForm">@csrf @method('PUT')
<div class="card"><div class="card-head"><h3>Survey</h3></div><div class="card-body form-grid">
  <x-field name="name" label="Name" :value="$survey->name" required/>
  <x-field name="channel" label="Send by" type="select" :options="\App\Models\Survey::CHANNELS" :value="$survey->channel" required/>
  <x-field name="send_delay_hours" label="Send … hours after check-out" type="number" :value="$survey->send_delay_hours" required/>
  <x-field name="reminder_hours" label="Reminder after … hours (blank = none)" type="number" :value="$survey->reminder_hours"/>
  <x-field name="expiry_days" label="Link open for … days" type="number" :value="$survey->expiry_days" required/>
  <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $survey->is_active))> Active</label>
    <label class="check"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $survey->is_default))> Default post-stay survey</label></div>
  <x-field name="intro" label="Introduction" type="textarea" :value="$survey->intro" class="span-all"/>
  <x-field name="thank_you" label="Thank-you message" type="textarea" :value="$survey->thank_you" class="span-all"/>
</div></div>
<div class="card flush"><div class="card-head"><h3>Questions</h3><button type="button" class="btn btn-sm" id="addQ"><x-icon name="plus"/> Add question</button></div>
<div class="card-body table-wrap">
  @error('q')<div class="alert alert-error"><x-icon name="alert"/><div>{{ $message }}</div></div>@enderror
  <table class="table" id="qTable"><thead><tr><th style="width:36px">#</th><th>Question</th><th>Type</th><th>Department (ratings)</th><th style="width:22%">Answers (choice, comma-separated)</th><th>Required</th><th>Active</th><th>Remove</th><th class="num">Answered</th></tr></thead>
  <tbody>
  @foreach($survey->questions as $i => $q)
    <tr data-row>
      <td class="muted"><input type="hidden" name="q[{{ $i }}][id]" value="{{ $q->id }}">{{ $i + 1 }}</td>
      <td><input name="q[{{ $i }}][label]" value="{{ old("q.$i.label", $q->label) }}" style="width:100%;min-width:300px" maxlength="255">@error("q.$i.label")<div class="err">{{ $message }}</div>@enderror</td>
      <td><select name="q[{{ $i }}][type]" @if($q->answers_count) title="Answered – the type is locked" @endif>@foreach(\App\Models\SurveyQuestion::TYPES as $k => $l)<option value="{{ $k }}" @selected(old("q.$i.type", $q->type) === $k) @disabled($q->answers_count && $k !== $q->type)>{{ $l }}</option>@endforeach</select>@error("q.$i.type")<div class="err">{{ $message }}</div>@enderror</td>
      <td><select name="q[{{ $i }}][department]"><option value="">—</option>@foreach(\App\Services\FeedbackService::DEPTS as $k => $l)<option value="{{ $k }}" @selected(old("q.$i.department", $q->department) === $k)>{{ $l }}</option>@endforeach</select></td>
      <td><input name="q[{{ $i }}][options]" value="{{ old("q.$i.options", implode(', ', $q->options ?? [])) }}" style="width:100%;min-width:140px">@error("q.$i.options")<div class="err">{{ $message }}</div>@enderror</td>
      <td class="center"><input type="checkbox" name="q[{{ $i }}][required]" value="1" @checked($q->required)></td>
      <td class="center"><input type="checkbox" name="q[{{ $i }}][active]" value="1" @checked($q->is_active)></td>
      <td class="center"><input type="checkbox" name="q[{{ $i }}][remove]" value="1" title="{{ $q->answers_count ? 'Answered questions are switched off, not deleted' : 'Delete' }}"></td>
      <td class="num small">{{ $q->answers_count }}</td></tr>
  @endforeach
  </tbody></table>
  <p class="tiny muted mt-1">Ratings linked to a department feed the department scores. The recommend (NPS) question drives the Net Promoter Score – one per survey. Questions that already have answers can't change type; removing them switches them off so past answers stay readable.</p>
</div>
<div class="card-foot"><button class="btn btn-primary" id="saveSurvey">Save survey</button></div></div>
</form>
<template id="qTpl"><tr data-row><td class="muted">new</td><td><input name="q[__i__][label]" style="width:100%;min-width:300px" maxlength="255" placeholder="Question text"></td>
  <td><select name="q[__i__][type]">@foreach(\App\Models\SurveyQuestion::TYPES as $k => $l)<option value="{{ $k }}" @selected($k === 'rating')>{{ $l }}</option>@endforeach</select></td>
  <td><select name="q[__i__][department]"><option value="">—</option>@foreach(\App\Services\FeedbackService::DEPTS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></td>
  <td><input name="q[__i__][options]" style="width:100%"></td><td class="center"><input type="checkbox" name="q[__i__][required]" value="1"></td>
  <td class="center"><input type="checkbox" name="q[__i__][active]" value="1" checked></td><td class="center"><input type="checkbox" name="q[__i__][remove]" value="1"></td><td></td></tr></template>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('addQ').addEventListener('click', function () {
    const tb = document.querySelector('#qTable tbody'), n = tb.querySelectorAll('[data-row]').length + Date.now() % 1000 * 100;
    tb.insertAdjacentHTML('beforeend', document.getElementById('qTpl').innerHTML.replaceAll('__i__', n));
    tb.lastElementChild.querySelector('input').focus();
  });
});
</script>
@endsection
