@extends('layouts.app')
@section('title', 'Digital Marketing Settings')
@section('crumbs') Digital Marketing / Settings @endsection
@section('content')
<div class="card" style="max-width:44rem"><div class="card-body"><form method="post" action="{{ route('digital.settings.save') }}" id="dmSettings">@csrf
  <div class="form-grid">
    <div class="field span-all"><label class="dm-check"><input type="checkbox" name="require_approval" value="1" @checked($cfg['require_approval'])> Social posts need approval by a second person before publishing (four-eyes)</label></div>
    <div class="field"><label>Attribution window (days)</label><input type="number" name="attribution_days" min="1" max="90" value="{{ $cfg['attribution_days'] }}"><span class="tiny muted">A booking this long after a tracked-link click is still credited.</span></div>
    <div class="field"><label>Listing audit every (days)</label><input type="number" name="audit_days" min="7" max="365" value="{{ $cfg['audit_days'] }}"><span class="tiny muted">Listings not audited within this period are flagged.</span></div>
  </div>
  <button class="btn btn-primary mt-1">Save settings</button></form></div></div>
@endsection
