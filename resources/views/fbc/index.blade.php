@extends('layouts.app')
@section('title', 'F&B Cost Control')
@section('crumbs') F&amp;B Cost Control / Dashboard @endsection
@php $u = auth()->user(); $canCost = \App\Services\FbCostService::canCost($u); $canPrice = \App\Services\FbCostService::canPrice($u); $f = $k['food']; $b = $k['beverage']; @endphp
@section('actions')
  <a class="btn" href="{{ route('fbc.menu') }}"><x-icon name="grid"/> Menu engineering</a>
  <a class="btn" href="{{ route('fbc.statement') }}"><x-icon name="chart"/> Cost statement</a>
  @if($canCost)<a class="btn btn-primary" href="{{ route('fbc.meals') }}"><x-icon name="users"/> Log staff meals</a>@endif
@endsection
@section('content')
<div class="stats" id="fbcStats">
  <x-stat label="Food cost % · month to date" :value="$f['pct'] !== null ? $f['pct'].'%' : '—'" icon="coffee" :tone="$f['pct'] !== null && $f['pct'] > $cfg['food_target'] ? 'red' : 'green'"
    :sub="'theoretical '.($f['theoretical_pct'] ?? '—').'% · target '.$cfg['food_target'].'%'" :href="route('fbc.statement')"/>
  <x-stat label="Beverage cost % · month to date" :value="$b['pct'] !== null ? $b['pct'].'%' : '—'" icon="cup" :tone="$b['pct'] !== null && $b['pct'] > $cfg['bev_target'] ? 'red' : 'green'"
    :sub="'theoretical '.($b['theoretical_pct'] ?? '—').'% · target '.$cfg['bev_target'].'%'" :href="route('fbc.statement')"/>
  <x-stat label="F&B revenue · month to date" :value="money_short($f['revenue'] + $b['revenue'])" icon="money" tone="blue" :sub="'net of tax & discounts · '.$k['from']->format('d M').' – '.$k['to']->format('d M')"/>
  <x-stat label="Cost variance · month to date" :value="money_short($f['variance'] + $b['variance'])" icon="trend" :tone="($f['variance'] + $b['variance']) > 0 ? 'amber' : 'green'" sub="actual cost of sales − theoretical" :href="route('fbc.variance')"/>
  <x-stat label="Dishes costed" :value="$k['costed'].' / '.$k['dishes']" icon="book" :tone="$k['over'] ? 'amber' : 'green'" :sub="$k['over'].' over target · '.$k['creep'].' cost creep'" :href="route('fbc.recipes')"/>
</div>
@php $al = $alerts; @endphp
@if($al['over'] || $al['no_recipe'] || $al['creep'] || $al['zero_cost'] || $al['unapproved'] || $al['meals_missing'])
<div class="alert alert-warn" id="fbcAlerts"><x-icon name="alert"/><div>
  @if($al['over'])<a href="{{ route('fbc.recipes', ['status' => 'over']) }}"><b>{{ $al['over'] }}</b> dish(es) above their target cost %.</a> @endif
  @if($al['creep'])<a href="#fbcCreep"><b>{{ $al['creep'] }}</b> plate cost(s) up {{ rtrim(rtrim(number_format($cfg['creep_pct'], 1), '0'), '.') }} %+ in {{ $cfg['creep_days'] }} days.</a> @endif
  @if($al['no_recipe'])<a href="{{ route('fbc.recipes', ['status' => 'no_recipe']) }}"><b>{{ $al['no_recipe'] }}</b> dish(es) on sale without a recipe – no theoretical cost.</a> @endif
  @if($al['zero_cost'])<b>{{ $al['zero_cost'] }}</b> recipe ingredient(s) without a purchase cost. @endif
  @if($al['unapproved'])<a href="{{ route('fbc.recipes') }}"><b>{{ $al['unapproved'] }}</b> recipe card(s) awaiting approval.</a> @endif
  @if($al['meals_missing'])<a href="{{ route('fbc.meals') }}">Yesterday's staff meals are not logged.</a> @endif
</div></div>
@endif
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>Daily flash – cost % last 30 days</h3><span class="small muted">food (bars) · beverage (dots) · target lines</span></div><div class="card-body">
      @php
        $mx = 10;
        foreach ($daily as $dd) { $mx = max($mx, (float) ($dd['food']['pct'] ?? 0), (float) ($dd['beverage']['pct'] ?? 0), $cfg['food_target'] + 10); }
        $mx = min(150, ceil($mx / 10) * 10);
      @endphp
      <div class="fbc-flash" id="fbcFlash">
        <div class="fbc-target" style="bottom:{{ round($cfg['food_target'] / $mx * 100, 1) }}%" title="Food target {{ $cfg['food_target'] }}%"><span>food {{ $cfg['food_target'] }}%</span></div>
        <div class="fbc-target bev" style="bottom:{{ round($cfg['bev_target'] / $mx * 100, 1) }}%" title="Beverage target {{ $cfg['bev_target'] }}%"><span>bev {{ $cfg['bev_target'] }}%</span></div>
        @foreach($daily as $dt => $dd)@php $fp = $dd['food']['pct']; $bp = $dd['beverage']['pct']; @endphp
        <div class="col" title="{{ \Carbon\Carbon::parse($dt)->format('D d M') }} – food {{ $fp ?? '—' }}% (theoretical {{ $dd['food']['theoretical_pct'] ?? '—' }}%) · beverage {{ $bp ?? '—' }}%">
          <div class="fbc-bar {{ $fp !== null && $fp > $cfg['food_target'] ? 'over' : '' }}" style="height:{{ $fp !== null ? min(100, round(max(0, $fp) / $mx * 100, 1)) : 0 }}%"></div>
          @if($bp !== null)<i class="dot" style="bottom:{{ min(100, round(max(0, $bp) / $mx * 100, 1)) }}%"></i>@endif
          <small>{{ \Carbon\Carbon::parse($dt)->format('j') }}</small>
        </div>@endforeach
      </div>
    </div></div>
    <div class="card flush"><div class="card-head"><h3>Dishes above target cost</h3><a class="small" href="{{ route('fbc.pricing', ['show' => 'over']) }}">pricing</a></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="fbcOver"><thead><tr><th>Dish</th><th class="num">Price</th><th class="num">Plate cost</th><th class="num">Cost %</th><th class="num">Target</th><th class="num">Suggested</th></tr></thead><tbody>
      @forelse($over as $m)<tr class="row-amber"><td><a href="{{ route('fbc.card', $m) }}"><b>{{ $m->name }}</b></a><div class="tiny muted">{{ $m->category?->name }}</div></td>
        <td class="num">{{ money($m->price) }}</td><td class="num">{{ money($m->cost) }}</td><td class="num"><b>{{ $m->pct }}%</b></td><td class="num">{{ $m->target }}%</td><td class="num">{{ money($m->suggested) }}</td></tr>
      @empty<tr><td colspan="6" class="muted">Every costed dish is within its target.</td></tr>@endforelse
      </tbody></table></div></div>
    <div class="card flush" id="fbcCreep"><div class="card-head"><h3>Cost creep – last {{ $cfg['creep_days'] }} days</h3><span class="small muted">threshold {{ $cfg['creep_pct'] }}%</span></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="fbcCreepTable"><thead><tr><th>Dish</th><th class="num">Was</th><th class="num">Now</th><th class="num">Change</th><th class="num">Cost % now</th></tr></thead><tbody>
      @forelse($creep as $m)<tr><td><a href="{{ route('fbc.card', $m) }}"><b>{{ $m->name }}</b></a><div class="tiny muted">since {{ $m->creep['on']->format('d M') }}</div></td>
        <td class="num">{{ money($m->creep['was']) }}</td><td class="num">{{ money($m->cost) }}</td><td class="num"><span class="badge badge-red">+{{ $m->creep['pct'] }}%</span></td>
        <td class="num">{{ $m->pct }}%</td></tr>
      @empty<tr><td colspan="5" class="muted">No plate cost rose past the threshold.</td></tr>@endforelse
      </tbody></table></div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Menu mix – last 28 days</h3><a class="small" href="{{ route('fbc.menu') }}">matrix</a></div><div class="card-body" id="fbcMix">
      <div class="fbc-quad">
        @foreach(\App\Services\FbCostService::CLASSES as $ck => [$cl, $tone, $act])<a class="q q-{{ $ck }}" href="{{ route('fbc.menu') }}#me-{{ $ck }}"><b>{{ $me['counts'][$ck] }}</b><span>{{ $cl }}s</span></a>@endforeach
      </div>
      <div class="kv-grid mt-1">
        <div><span>Items sold</span>{{ number_format($me['n']) }}</div>
        <div><span>Average CM</span>{{ money($me['avg_cm']) }}</div>
        <div><span>Popularity threshold</span>{{ $me['threshold'] }}% mix</div>
        <div><span>Menu cost %</span>{{ $me['pct'] ?? '—' }}%</div>
      </div>
    </div></div>
    <div class="card"><div class="card-head"><h3>Month to date – food</h3></div><div class="card-body">
      <div class="kv-grid" id="fbcMtd">
        <div><span>Revenue</span>{{ money($f['revenue']) }}</div>
        <div><span>Theoretical cost</span>{{ money($f['theoretical']) }}</div>
        <div><span>Cost consumed</span>{{ money($f['actual']) }}</div>
        <div><span>Less staff meals</span>{{ money($f['staff_meals']) }}</div>
        <div><span>Less officers' checks</span>{{ money($f['comps']) }}</div>
        <div><span>Cost of food sold</span><b>{{ money($f['cost_of_sales']) }}</b></div>
        <div><span>of which waste log</span>{{ money($f['waste']) }}</div>
        <div><span>Count variance</span>{{ money($f['adjustments']) }}</div>
      </div>
    </div></div>
    <div class="card flush"><div class="card-head"><h3>Recent price changes</h3><a class="small" href="{{ route('fbc.pricing') }}">pricing</a></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="fbcChanges"><tbody>
      @forelse($changes as $c)<tr><td class="small nowrap">{{ $c->effective_on->format('d M') }}</td><td class="small"><b>{{ $c->menuItem?->name }}</b><div class="tiny muted">{{ $c->reason }}</div></td>
        <td class="num small nowrap">{{ money($c->old_price) }} → <b>{{ money($c->new_price) }}</b></td></tr>
      @empty<tr><td class="muted">No price changes yet.</td></tr>@endforelse
      </tbody></table></div></div>
  </div>
</div>
@endsection
