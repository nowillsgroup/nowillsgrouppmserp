@extends('layouts.app')
@section('title', 'Guest Surveys')
@section('crumbs') Guests &amp; CRM / Surveys @endsection
@section('actions')
<a class="btn" href="{{ route('rep.invitations') }}" id="invLink"><x-icon name="mail"/> Invitations</a>
@if(can_do('feedback.manage'))<a class="btn btn-primary" href="#" data-modal="newSurvey" id="newSurveyBtn"><x-icon name="plus"/> New survey</a>@endif
@endsection
@section('content')
@include('reputation._nav')
<div class="alert alert-info" style="background:var(--c-blue-50,#eff6ff)"><x-icon name="mail"/><div>The <b>default</b> survey is scheduled automatically when a reservation checks out{{ setting('fb_survey_auto', '1') === '1' ? '' : ' (currently switched off in Settings)' }}, sent after the configured delay, reminded once, and closed after it expires. Each guest is surveyed at most once every {{ setting('fb_cooldown_days', '30') }} days.</div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="surveyTable">
  <thead><tr><th>Survey</th><th>Status</th><th>Channel</th><th class="num">Questions</th><th>Timing</th><th class="num">Sent</th><th class="num">Responses</th><th class="num">Response rate</th><th class="num">NPS</th><th></th></tr></thead><tbody>
  @foreach($surveys as $s)
    @php $i = $inv[$s->id] ?? null; @endphp
    <tr><td><b>{{ $s->name }}</b>@if($s->is_default) <span class="badge badge-blue">default · post-stay</span>@endif</td>
      <td>{!! status_badge($s->is_active ? 'active' : 'inactive') !!}</td><td class="small">{{ \App\Models\Survey::CHANNELS[$s->channel] }}</td>
      <td class="num">{{ $s->active_questions }}</td>
      <td class="small">{{ $s->send_delay_hours }} h after check-out · {{ $s->reminder_hours ? 'reminder after '.$s->reminder_hours.' h' : 'no reminder' }} · open {{ $s->expiry_days }} days</td>
      <td class="num">{{ (int) ($i->sent ?? 0) }}</td><td class="num">{{ $s->responses_count }}</td>
      <td class="num">{{ ($i->sent ?? 0) ? round($i->done / $i->sent * 100).'%' : '–' }}</td>
      <td class="num">{{ $nps[$s->id] === null ? '–' : ($nps[$s->id] > 0 ? '+' : '').$nps[$s->id] }}</td>
      <td class="nowrap right"><a class="btn btn-sm" href="{{ route('rep.surveys.preview', $s) }}" target="_blank"><x-icon name="eye"/> Preview</a>
        @if(can_do('feedback.manage'))<a class="btn btn-sm" href="{{ route('rep.surveys.edit', $s) }}"><x-icon name="edit"/> Edit</a>@endif</td></tr>
  @endforeach
</tbody></table></div></div>
@if(can_do('feedback.manage'))
<div class="modal" id="newSurvey"><div class="modal-box"><form method="post" action="{{ route('rep.surveys.store') }}">@csrf
  <div class="modal-head"><h3>New survey</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="name" label="Name" required placeholder="e.g. Banquet event feedback"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Create</button></div>
</form></div></div>
@endif
@endsection
