@extends('layouts.app')
@section('title', 'Sales Settings')
@section('crumbs') Sales / Settings @endsection
@php $O = \App\Models\SalesOpportunity::class; @endphp
@section('content')
<div class="card" style="max-width:44rem"><div class="card-body"><form method="post" action="{{ route('sales.settings.save') }}" class="form-grid" id="salesSettings">@csrf
  <h3 class="span-all">Default win probability by stage (%)</h3>
  @foreach($O::OPEN as $s)<div class="field"><label>{{ $O::STAGES[$s][0] }}</label><input type="number" name="prob_{{ $s }}" min="1" max="99" required value="{{ $cfg['prob_'.$s] }}"></div>@endforeach
  <h3 class="span-all">Reminders</h3>
  <div class="field"><label>Flag open deals with no contact for (days)</label><input type="number" name="stale_days" min="3" max="365" required value="{{ $cfg['stale_days'] }}"></div>
  <div class="field"><label>Flag key / major accounts with no contact for (days)</label><input type="number" name="dormant_days" min="14" max="730" required value="{{ $cfg['dormant_days'] }}"></div>
  <div class="field"><label>Default follow-up after (days)</label><input type="number" name="followup_days" min="1" max="90" required value="{{ $cfg['followup_days'] }}"></div>
  <div class="field"><label>Show contact birthdays (days ahead)</label><input type="number" name="birthday_days" min="0" max="60" required value="{{ $cfg['birthday_days'] }}"></div>
  <div class="span-all"><button class="btn btn-primary" id="saveSettings">Save settings</button></div>
</form></div></div>
@endsection
