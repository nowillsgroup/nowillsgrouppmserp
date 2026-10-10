@extends('layouts.app')
@section('title', 'QA Settings')
@section('crumbs') Quality Assurance / Settings @endsection
@section('content')
<div class="card" style="max-width:44rem"><div class="card-head"><h3>Scoring &amp; corrective actions</h3></div><div class="card-body">
<form method="post" action="{{ route('qa.settings.save') }}" id="qaSettingsForm">@csrf<div class="form-grid">
  <div class="field"><label>Default pass mark %</label><input type="number" name="pass_mark" min="50" max="100" step="0.5" value="{{ $cfg['pass_mark'] }}" required><span class="tiny muted">new checklists start with this; each checklist can override it</span></div>
  <div class="field"><label>Target score %</label><input type="number" name="target_score" min="50" max="100" step="0.5" value="{{ $cfg['target_score'] }}" required><span class="tiny muted">the green line on the dashboard</span></div>
  <div class="field span-all"><label class="check"><input type="checkbox" name="critical_autofail" value="1" @checked($cfg['critical_autofail'])> A failed critical standard (food safety, fire, ID checks…) fails the whole audit, whatever the score</label></div>
  <div class="field"><label>Corrective actions due in (days)</label><input type="number" name="action_days" min="1" max="90" value="{{ $cfg['action_days'] }}" required></div>
  <div class="field"><label>Critical actions due in (days)</label><input type="number" name="critical_action_days" min="1" max="90" value="{{ $cfg['critical_action_days'] }}" required></div>
</div><p class="tiny muted mt-1">Score = points of passed standards ÷ points of standards checked (n/a excluded). A standard failed again on the next audit of the same checklist is a <b>repeat finding</b>; its action is raised as high priority. Actions are verified by a quality manager other than the person who owns or completed them.</p>
<button class="btn btn-primary mt-1">Save settings</button></form></div></div>
@endsection
