@extends('layouts.app')
@section('title', 'Health & Safety Settings')
@section('crumbs') Health &amp; Safety / Settings @endsection
@section('content')
<div class="card" style="max-width:760px"><div class="card-body"><form method="post" action="{{ route('hse.settings.save') }}" id="hseSettingsForm">@csrf
  <div class="form-grid">
    <div class="field"><label>Fire drill interval per shift (days)</label><input type="number" name="drill_days" min="30" max="730" value="{{ $cfg['drill_days'] }}" required></div>
    <div class="field"><label>Evacuation target (seconds)</label><input type="number" name="drill_target" min="30" max="1800" value="{{ $cfg['drill_target'] }}" required></div>
    <div class="field"><label>Risk assessment review interval (days)</label><input type="number" name="review_days" min="30" max="1095" value="{{ $cfg['review_days'] }}" required></div>
    <div class="field"><label>Hours worked per employee per month (LTIFR)</label><input type="number" name="hours_month" min="40" max="320" value="{{ $cfg['hours_month'] }}" required></div>
    <div class="field span-all"><label>Factories Inspectorate office (notices of accidents)</label><input name="inspectorate" maxlength="150" value="{{ $cfg['inspectorate'] }}" required></div>
    <label class="small span-all"><input type="checkbox" name="auto_ticket" value="1" @checked($cfg['auto_ticket'])> Raise an engineering ticket automatically when a fire-equipment check fails</label>
    <label class="small span-all"><input type="checkbox" name="issuer_differs" value="1" @checked($cfg['issuer_differs'])> A permit to work must be issued by someone other than the requester</label>
  </div>
  <button class="btn btn-primary mt-1">Save settings</button>
</form></div></div>
@endsection
