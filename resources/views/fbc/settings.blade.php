@extends('layouts.app')
@section('title', 'F&B Cost Settings')
@section('crumbs') F&amp;B Cost Control / Settings @endsection
@section('content')
<div class="g-2">
<div class="card"><div class="card-head"><h3>Costing &amp; targets</h3></div><div class="card-body">
<form method="post" action="{{ route('fbc.settings.save') }}" id="fbcSettingsForm">@csrf<div class="form-grid">
  <div class="field"><label>Food cost target %</label><input type="number" step="0.5" name="food_target" value="{{ old('food_target', $cfg['food_target']) }}" min="5" max="80" required></div>
  <div class="field"><label>Beverage cost target %</label><input type="number" step="0.5" name="bev_target" value="{{ old('bev_target', $cfg['bev_target']) }}" min="5" max="80" required></div>
  <div class="field"><label>Costing basis</label><select name="basis"><option value="last" @selected($cfg['basis'] === 'last')>Last purchase cost</option><option value="average" @selected($cfg['basis'] === 'average')>Weighted average – last 90 days</option></select></div>
  <div class="field"><label>Round suggested prices up to</label><select name="round">@foreach([1, 5, 10] as $r)<option value="{{ $r }}" @selected($cfg['round'] === $r)>GH₵{{ $r }}</option>@endforeach</select></div>
  <div class="field"><label>Popularity factor % (menu engineering)</label><input type="number" name="popularity" value="{{ old('popularity', $cfg['popularity']) }}" min="50" max="100" required></div>
  <div class="field"><label>Staff meal cost per cover</label><input type="number" step="0.01" name="meal_cost" value="{{ old('meal_cost', $cfg['meal_cost']) }}" min="0" max="500" required></div>
  <div class="field"><label>Cost-creep alert at % rise</label><input type="number" step="0.5" name="creep_pct" value="{{ old('creep_pct', $cfg['creep_pct']) }}" min="1" max="50" required></div>
  <div class="field"><label>… over the last (days)</label><input type="number" name="creep_days" value="{{ old('creep_days', $cfg['creep_days']) }}" min="7" max="365" required></div>
</div><button class="btn btn-primary mt-1">Save settings</button></form>
<p class="tiny muted mt-1">A dish is popular when its share of items sold is at least the popularity factor × an equal share (Kasavana &amp; Smith use 70 %). A recipe card can override the target for one dish.</p>
</div></div>
<div class="card flush"><div class="card-head"><h3>Food / beverage classes</h3><a class="small" href="{{ route('masters.index', 'menu-categories') }}">edit in masters</a></div><div class="card-body table-wrap"><table class="table table-compact" id="fbcClasses">
  <thead><tr><th>Menu category (revenue)</th><th>Class</th></tr></thead><tbody>
  @foreach($mcats as $c)<tr><td>{{ $c->name }}</td><td><span class="badge badge-{{ $c->fb_class === 'beverage' ? 'purple' : 'blue' }}">{{ $c->fb_class }}</span></td></tr>@endforeach
  </tbody>
  <thead><tr><th>Stock category (cost)</th><th>Class</th></tr></thead><tbody>
  @foreach($icats as $c)<tr><td>{{ $c->name }}</td><td>@if($c->fb_class)<span class="badge badge-{{ $c->fb_class === 'beverage' ? 'purple' : 'blue' }}">{{ $c->fb_class }}</span>@else<span class="muted small">not F&amp;B</span>@endif</td></tr>@endforeach
  </tbody></table></div></div>
</div>
@endsection
