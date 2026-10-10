@extends('layouts.app')
@section('title', 'Food Safety')
@section('crumbs') Food Safety / HACCP dashboard @endsection
@section('actions')
@if(can_do('foodsafety.record'))<a class="btn btn-primary" href="{{ route('fs.temps') }}#record" id="goRecord"><x-icon name="thermo"/> Record temperature</a>@endif
<a class="btn" href="{{ route('fs.pack') }}" target="_blank" id="packBtn"><x-icon name="print"/> Inspection pack</a>
@endsection
@section('content')
@php $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%'; $tone = fn ($v) => $v === null ? 'blue' : ($v >= 95 ? 'green' : ($v >= 80 ? 'amber' : 'red')); @endphp
<div class="stats" id="fsStats">
  <x-stat label="HACCP compliance – last 7 days" :value="$pct($c7['score'])" icon="shield" :tone="$tone($c7['score'])" :sub="$c7['done'].'/'.$c7['expected'].' equipment checks · '.$c7['lists_done'].'/'.$c7['lists_due'].' checklists · 30 days '.$pct($c30['score'])"/>
  <x-stat label="Readings within limits (7 days)" :value="$pct($c7['in_range_pct'])" icon="thermo" :tone="$tone($c7['in_range_pct'])" :sub="$c7['readings'].' readings · '.$c7['missed'].' missed checks'" :href="route('fs.temps')"/>
  <x-stat label="Needs attention" :value="$att['total']" icon="alert" :tone="$att['total'] ? 'red' : 'green'" :sub="$att['breaches'].' breaches to review · '.$att['cooling'].' cooling overdue · '.$att['failed'].' failed checklists · '.$att['overdue'].' checklists overdue'"/>
  <x-stat :label="'Food waste '.bdate()->format('M Y')" :value="money($waste['total'])" icon="trash" tone="amber" :sub="($waste['pct'] !== null ? $pct($waste['pct']).' of food & beverage cost · ' : '').$waste['count'].' entries'" :href="route('fs.waste')"/>
</div>

<div class="fs-split mt-1">
  <div>
    <div class="card flush" id="dashBoard"><div class="card-head"><h3><x-icon name="thermo"/> Equipment today</h3><a class="small" href="{{ route('fs.temps') }}">Temperature logs →</a></div>
      <div class="card-body">
        @if($board->isEmpty())<x-empty text="No refrigeration / hot-holding equipment registered yet." icon="thermo"/>@else
        <div class="fs-board">
          @foreach($board as $eq)
            <div class="fs-unit {{ $eq->state }}" data-unit="{{ $eq->id }}">
              <div class="flex justify-between items-center"><b class="small">{{ $eq->name }}</b><span class="dots">@for($i = 0; $i < $eq->checks_per_day; $i++)@php $l = $eq->today[$i] ?? null; @endphp<i class="{{ $l ? ($l->in_range ? 'on' : 'bad') : '' }}"></i>@endfor</span></div>
              <div class="t {{ $eq->last && ! $eq->last->in_range ? 'bad' : '' }}">{{ $eq->last ? rtrim(rtrim(number_format((float) $eq->last->reading_c, 1), '0'), '.').'°' : '—' }}</div>
              <div class="tiny muted">limit {{ $eq->limitText() }} · {{ $eq->last ? 'last '.$eq->last->recorded_at->format('d/m H:i') : 'never checked' }}</div>
              <div class="tiny {{ $eq->state === 'due' ? 'text-amber' : ($eq->state === 'breach' ? 'text-red' : 'text-green') }} bold">{{ ['due' => $eq->done.'/'.$eq->checks_per_day.' checks today – due', 'done' => 'All checks done', 'breach' => 'Out of range today'][$eq->state] }}</div>
            </div>
          @endforeach
        </div>@endif
      </div></div>

    <div class="card flush mt-1" id="dashBreaches"><div class="card-head"><h3><x-icon name="alert"/> Out-of-range readings to review</h3>@if(can_do('foodsafety.manage'))<a class="small" href="{{ route('fs.temps') }}#review">Review →</a>@endif</div>
      <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>When</th><th>What</th><th class="num">°C</th><th>Limit</th><th>Corrective action</th><th>By</th></tr></thead>
        <tbody>@forelse($breaches as $l)
          <tr><td class="small">{{ $l->recorded_at->format('d/m H:i') }}</td><td>{{ $l->subject() }}<div class="tiny muted">{{ \App\Models\FsTempLog::KINDS[$l->kind] }}</div></td><td class="num bold text-red">{{ rtrim(rtrim(number_format((float) ($l->reading2_c ?? $l->reading_c), 1), '0'), '.') }}</td>
            <td class="small">{{ \App\Services\FoodSafetyService::limitText($l->min_c, $l->max_c) }}</td><td class="small">{{ $l->corrective_action }}</td><td class="small">{{ $l->user?->name }}</td></tr>
        @empty <tr><td colspan="6"><x-empty text="Nothing to review." icon="check"/></td></tr> @endforelse</tbody></table></div></div>

    @if($cooling->isNotEmpty())
    <div class="card flush mt-1" id="dashCooling"><div class="card-head"><h3>Cooling in progress</h3></div><div class="card-body table-wrap"><table class="table">
      <thead><tr><th>Food</th><th>2-h reading</th><th>6-h reading due</th><th>By</th></tr></thead>
      <tbody>@foreach($cooling as $l)<tr><td>{{ $l->item }}</td><td>{{ rtrim(rtrim(number_format((float) $l->reading_c, 1), '0'), '.') }} °C at {{ $l->recorded_at->format('H:i') }}</td>
        <td class="{{ $l->recorded_at->lt(now()->subHours(4)) ? 'text-red bold' : '' }}">{{ $l->recorded_at->copy()->addHours(4)->format('d/m H:i') }}{{ $l->recorded_at->lt(now()->subHours(4)) ? ' – overdue' : '' }}</td><td class="small">{{ $l->user?->name }}</td></tr>@endforeach</tbody></table></div></div>
    @endif
  </div>

  <div>
    <div class="card flush" id="dashLists"><div class="card-head"><h3><x-icon name="check"/> Checklists – current period</h3><a class="small" href="{{ route('fs.checklists') }}">All →</a></div>
      <div class="card-body table-wrap"><table class="table">
        <tbody>@forelse($lists as $c)
          <tr data-list="{{ $c->id }}"><td><b class="small">{{ $c->name }}</b><div class="tiny muted">{{ \App\Services\FoodSafetyService::periodLabel($c, $c->period) }}{{ $c->due_time ? ' · due '.substr($c->due_time, 0, 5) : '' }}</div></td>
            <td class="num">@include('foodsafety._state', ['state' => $c->state])</td>
            <td class="num">@if(! $c->run && can_do('foodsafety.record'))<a class="btn btn-sm" href="{{ route('fs.checklists.run', $c) }}">Start</a>@elseif($c->run)<a class="btn btn-sm btn-ghost" href="{{ route('fs.checklists.show', $c->run) }}">View</a>@endif</td></tr>
        @empty <tr><td><x-empty text="No checklists set up." icon="check"/></td></tr> @endforelse</tbody></table></div></div>

    <div class="card mt-1" id="dashOther"><div class="card-head"><h3>Allergens, people &amp; labels</h3></div><div class="card-body"><ul class="list">
      <li><span><a href="{{ route('fs.allergens') }}">Dishes with undeclared recipe allergens</a></span><b class="{{ $allergens['undeclared'] ? 'text-red' : 'text-green' }}">{{ $allergens['undeclared'] }}</b></li>
      <li><span>Dishes with allergens not yet verified</span><b class="{{ $allergens['unverified'] ? 'text-amber' : 'text-green' }}">{{ $allergens['unverified'] }} / {{ $allergens['total'] }}</b></li>
      <li><span><a href="{{ route('fs.certs') }}">Food handlers – medical certificate expired / missing</a></span><b class="{{ $certs['expired'] + $certs['missing'] ? 'text-red' : 'text-green' }}">{{ $certs['expired'] + $certs['missing'] }} / {{ $certs['handlers'] }}</b></li>
      <li><span>Certificates expiring within {{ \App\Services\FoodSafetyService::certWarnDays() }} days</span><b class="{{ $certs['expiring'] ? 'text-amber' : '' }}">{{ $certs['expiring'] }}</b></li>
    </ul>
    @if($labels->isNotEmpty())<div class="tiny muted mt-1 bold">Prepared food reaching its use-by in the next 24 h</div><ul class="list">@foreach($labels as $l)<li><span class="small">{{ $l->item }} <span class="tiny muted">{{ $l->qty }} label(s)</span></span><span class="small {{ $l->use_by->lt(now()->addHours(4)) ? 'text-red bold' : '' }}">{{ $l->use_by->format('D H:i') }}</span></li>@endforeach</ul>@endif
    </div></div>

    @if($waste['by_reason'])
    <div class="card mt-1" id="dashWaste"><div class="card-head"><h3>Waste by reason ({{ bdate()->format('M') }})</h3></div><div class="card-body"><ul class="list">
      @foreach($waste['by_reason'] as $r => $v)<li><span class="small">{{ \App\Models\FsWaste::REASONS[$r] ?? $r }}</span><span class="small">{{ money($v) }}</span></li>@endforeach
    </ul></div></div>
    @endif
  </div>
</div>
<p class="tiny muted mt-1">Critical limits (Codex / Ghana FDA guidance): chilled ≤ {{ \App\Services\FoodSafetyService::num('fs_fridge_max') }} °C, frozen ≤ {{ \App\Services\FoodSafetyService::num('fs_freezer_max') }} °C, hot holding ≥ {{ \App\Services\FoodSafetyService::num('fs_hot_hold_min') }} °C, cooking &amp; reheating core ≥ {{ \App\Services\FoodSafetyService::num('fs_cook_min') }} °C, cooling ≤ {{ \App\Services\FoodSafetyService::num('fs_cool_2h_max') }} °C in 2 h and ≤ {{ \App\Services\FoodSafetyService::num('fs_cool_6h_max') }} °C in 6 h. Compliance counts completed days only (today is still in progress).</p>
@endsection
