@extends('layouts.app')
@section('title', 'Feedback Settings')
@section('crumbs') Guests &amp; CRM / Feedback &amp; Reputation / Settings @endsection
@section('content')
@include('reputation._nav')
<form method="post" action="{{ route('rep.settings.save') }}" id="fbSettings">@csrf
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3><x-icon name="mail"/> Surveys</h3></div><div class="card-body form-grid">
    <label class="check span-all"><input type="checkbox" name="fb_survey_auto" value="1" @checked($s['fb_survey_auto'] === '1')> Send the default survey automatically after check-out</label>
    <x-field name="fb_cooldown_days" label="Survey a guest at most once every … days" type="number" :value="$s['fb_cooldown_days']" required hint="0 = every stay"/>
    <x-field name="fb_signature" label="Signed by" :value="$s['fb_signature']" required/>
    <x-field name="fb_review_link_google" label="Google review link" :value="$s['fb_review_link_google']" class="span-all" placeholder="https://g.page/r/…/review" hint="promoters (9-10) are invited to share their experience here"/>
    <x-field name="fb_review_link_tripadvisor" label="TripAdvisor review link" :value="$s['fb_review_link_tripadvisor']" class="span-all" placeholder="https://www.tripadvisor.com/UserReviewEdit-…"/>
  </div></div>
  <div class="card"><div class="card-head"><h3><x-icon name="alert"/> Alerts → guest cases</h3></div><div class="card-body form-grid">
    <x-field name="fb_alert_nps" label="Open a case when NPS is at or below" type="number" :value="$s['fb_alert_nps']" required hint="0-10 (6 = every detractor)"/>
    <x-field name="fb_alert_rating" label="… or any rating is at or below (★)" type="number" :value="$s['fb_alert_rating']" required/>
    <x-field name="fb_alert_review" label="… or an online review is at or below (out of 5)" type="number" :value="$s['fb_alert_review']" required/>
    <x-field name="fb_goodwill_max" label="Goodwill points without a manager" type="number" :value="$s['fb_goodwill_max']" required/>
    <x-field name="fb_severe_words" label="Words that always mean high severity" type="textarea" :value="$s['fb_severe_words']" class="span-all" hint="comma-separated"/>
  </div></div>
</div>
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3><x-icon name="clock"/> Resolution SLA (hours)</h3></div><div class="card-body form-grid">
    <x-field name="fb_sla_high" label="High severity" type="number" :value="$s['fb_sla_high']" required/>
    <x-field name="fb_sla_medium" label="Medium" type="number" :value="$s['fb_sla_medium']" required/>
    <x-field name="fb_sla_low" label="Low" type="number" :value="$s['fb_sla_low']" required/>
    <x-field name="fb_sla_inhouse" label="Guest still in the hotel" type="number" :value="$s['fb_sla_inhouse']" required hint="fix it before they leave"/>
    <label class="check span-all"><input type="checkbox" name="fb_review_approval" value="1" @checked($s['fb_review_approval'] === '1')> Responses to reviews of 3★ or less need approval by a second manager</label>
  </div></div>
  <div class="card"><div class="card-head"><h3><x-icon name="users"/> Case owners by department</h3></div><div class="card-body form-grid" id="ownerGrid">
    @foreach(\App\Services\FeedbackService::DEPTS as $k => $l)
      <div class="field"><label for="own_{{ $k }}">{{ $l }}</label><select name="owners[{{ $k }}]" id="own_{{ $k }}"><option value="">— unassigned —</option>@foreach($staff as $id => $n)<option value="{{ $id }}" @selected((int) ($owners[$k] ?? 0) === $id)>{{ $n }}</option>@endforeach</select></div>
    @endforeach
    @error('owners.*')<div class="err span-all">{{ $message }}</div>@enderror
  </div></div>
</div>
<div class="card"><div class="card-foot"><button class="btn btn-primary" id="saveFb">Save settings</button></div></div>
</form>

<div class="card flush"><div class="card-head"><h3><x-icon name="message"/> Review response templates</h3><span class="tiny muted">placeholders: {reviewer} {hotel} {signature} {email} {liked} {disliked} {source}</span></div>
<div class="card-body table-wrap"><table class="table" id="tplTable"><tbody>
@foreach($templates as $t)
  <tr><td colspan="5"><form method="post" action="{{ route('rep.templates.update', $t) }}" class="form-grid" style="grid-template-columns:1fr 1fr 3fr auto auto;align-items:start">@csrf @method('PUT')
    <input name="name" value="{{ $t->name }}" required maxlength="80">
    <select name="sentiment">@foreach(\App\Models\ReviewTemplate::SENTIMENTS as $k => $l)<option value="{{ $k }}" @selected($t->sentiment === $k)>{{ $l }}</option>@endforeach</select>
    <textarea name="body" rows="5" required style="font:inherit;font-size:.85rem">{{ $t->body }}</textarea>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked($t->is_active)> active</label>
    <span class="flex gap-1"><button class="btn btn-sm">Save</button><button class="btn btn-sm btn-danger" form="del{{ $t->id }}" onclick="return confirm('Delete this template?')"><x-icon name="trash"/></button></span></form>
    <form method="post" action="{{ route('rep.templates.destroy', $t) }}" id="del{{ $t->id }}">@csrf @method('DELETE')</form></td></tr>
@endforeach
<tr><td colspan="5"><form method="post" action="{{ route('rep.templates.store') }}" class="form-grid" style="grid-template-columns:1fr 1fr 3fr auto auto;align-items:start" id="newTpl">@csrf
  <input name="name" placeholder="New template name" required maxlength="80">
  <select name="sentiment">@foreach(\App\Models\ReviewTemplate::SENTIMENTS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
  <textarea name="body" rows="3" required style="font:inherit;font-size:.85rem" placeholder="Dear {reviewer}, …"></textarea><input type="hidden" name="is_active" value="1"><span></span>
  <button class="btn btn-sm btn-primary">Add</button></form></td></tr>
</tbody></table></div></div>
@endsection
