@extends('layouts.app')
@section('title', 'Pricing Grid')
@section('crumbs') <a href="{{ route('revenue.index') }}">Revenue</a> / Pricing Grid @endsection
@section('actions')
<span class="rm-mode {{ $mode }}"><x-icon name="trend" size="sm"/> {{ \App\Services\RevenueService::MODES[$mode] }}</span>
<form class="filters" method="get"><div class="field"><input type="date" name="from" value="{{ $from->toDateString() }}" min="{{ bdate()->toDateString() }}" data-autosubmit></div>
<div class="field"><select name="days" data-autosubmit>@foreach([7,14,21,28] as $d)<option value="{{ $d }}" @selected($days==$d)>{{ $d }} days</option>@endforeach</select></div></form>
@if($from->gt(bdate()))<a class="btn" href="{{ route('revenue.pricing', ['from' => $from->copy()->subDays($days)->max(bdate())->toDateString(), 'days' => $days]) }}">‹</a>@endif
<a class="btn" href="{{ route('revenue.pricing', ['from' => $from->copy()->addDays($days)->toDateString(), 'days' => $days]) }}">›</a>
@endsection
@section('content')
@php $canManage = can_do('revenue.manage'); $editable = $canManage && $mode !== 'auto'; @endphp
@if($mode === 'auto')
<div class="alert alert-info"><b>Auto-pilot is on.</b> The rates below are applied live to the booking engine, channel feeds and front desk quotes as occupancy changes – nothing to publish. Fixed-price dates are never touched.</div>
@elseif($mode === 'off')
<div class="alert alert-warn">Revenue management is <b>off</b>. Recommendations are shown for reference; you can still publish them manually.</div>
@endif

<form method="post" action="{{ route('revenue.publish') }}" id="pubForm">@csrf
<input type="hidden" name="from" value="{{ $from->toDateString() }}"><input type="hidden" name="days" value="{{ $days }}">
<div class="card flush"><div class="card-head"><h3>{{ $from->format('d M') }} – {{ $from->copy()->addDays($days - 1)->format('d M Y') }}</h3>
  @if($editable)
  <div class="flex gap-1 items-center">
    <span class="muted small"><b id="pickCount">0</b> selected · {{ $pending }} recommendation(s) differ from live</span>
    <button type="button" class="btn btn-sm btn-ghost" id="selChanges">Select changes</button><button type="button" class="btn btn-sm btn-ghost" id="selNone">Clear</button>
    <button class="btn btn-sm btn-primary" id="pubBtn">Publish selected</button>
    @if($pending)<button class="btn btn-sm btn-accent" name="accept_all" value="1" onclick="return confirm('Publish all {{ $pending }} recommended rate(s) in this range?')">Accept all</button>@endif
  </div>
  @endif
</div>
<div class="card-body table-wrap">
<table class="table table-compact rm-grid">
<thead><tr><th>Room type</th>@foreach($occ as $date => $o) @php $d = \Illuminate\Support\Carbon::parse($date); @endphp<th class="{{ in_array($d->dayOfWeek, \App\Services\RateService::WEEKEND) ? 'text-amber' : '' }}">{{ $d->format('D') }}<br>{{ $d->format('d/m') }}</th>@endforeach</tr></thead>
<tbody>
<tr class="occ-row"><td class="muted">Hotel occupancy</td>@foreach($occ as $date => $o)<td class="{{ $o['hotel'] >= 80 ? 'occ-hi' : ($o['hotel'] >= 50 ? 'occ-mid' : 'occ-lo') }}">{{ $o['hotel'] }}%<div class="tiny muted">{{ $o['sold'] }}/{{ $o['cap'] }}</div></td>@endforeach</tr>
@foreach($types as $t)
<tr><td class="bold nowrap">{{ $t->name }}<div class="tiny muted">Base {{ money($t->base_rate) }}</div>
  <div class="tiny muted">@if($t->min_rate !== null || $t->max_rate !== null)Floor {{ $t->min_rate !== null ? number_format($t->min_rate, 0) : '—' }} · Ceiling {{ $t->max_rate !== null ? number_format($t->max_rate, 0) : '—' }}@else No floor / ceiling @endif</div></td>
@foreach($grid[$t->id] ?? [] as $date => $c)
  @php
    $rec = $c['rec']; $target = $mode === 'auto' ? $c['current'] : $rec['rate']; $delta = $target - $c['current'];
    $cls = $c['fixed'] ? 'fixed' : ($c['change'] ? ($delta > 0 ? 'up' : 'down') : '');
    $tip = \Illuminate\Support\Carbon::parse($date)->format('D d M').' · lead '.$rec['lead'].'d · hotel '.$c['occ_hotel'].'% · '.$t->code.' '.$c['occ_type'].'%'."\n".'Base (BAR) '.number_format($c['base'], 0);
    foreach ($rec['rules'] as $r) { $tip .= "\n".$r['name'].' '.$r['adj'].' ('.($r['delta'] >= 0 ? '+' : '').number_format($r['delta'], 0).')'; }
    if ($rec['limit']) { $tip .= "\n→ held at the ".$rec['limit']; }
    if ($rec['min_stay']) { $tip .= "\nMin stay ".$rec['min_stay'].' nights'; }
    $tip .= "\nRecommended ".number_format($rec['rate'], 0).' · live '.number_format($c['current'], 0).($c['published'] ? ' (published)' : '');
  @endphp
  <td class="rm-cell {{ $cls }} {{ $c['stop'] ? 'stop' : '' }}" title="{{ $tip }}">
    @if($c['fixed'])
      <b>{{ number_format($c['current'], 0) }}</b><div class="meta">🔒 fixed</div>
    @elseif($editable)
      <div class="cur">live {{ number_format($c['current'], 0) }}@if($c['published'])<sup class="rm-tag">RM</sup>@endif</div>
      <input type="number" step="1" min="1" name="rate[{{ $t->id }}][{{ $date }}]" value="{{ round($rec['rate']) }}" data-cur="{{ $c['current'] }}" data-pick="{{ $t->id }}|{{ $date }}" aria-label="{{ $t->name }} {{ $date }}">
      <div class="delta">{{ $c['change'] ? ($delta > 0 ? '▲ +' : '▼ ').number_format($delta, 0) : '' }}</div>
      <label class="check"><input type="checkbox" name="pick[]" value="{{ $t->id }}|{{ $date }}" data-change="{{ $c['change'] ? 1 : 0 }}"> pick</label>
    @else
      <b>{{ number_format($c['current'], 0) }}</b>@if($c['published'] || ($mode === 'auto' && $rec['rules']))<sup class="rm-tag">RM</sup>@endif
      @if($mode !== 'auto' && $c['change'])<div class="delta">rec {{ number_format($rec['rate'], 0) }}</div>@elseif($mode === 'auto' && abs($c['current'] - $c['base']) >= .01)<div class="cur"><s>{{ number_format($c['base'], 0) }}</s></div>@endif
    @endif
    <div class="meta">{{ $c['left'] !== null ? $c['left'].' left' : '' }}{{ $c['stop'] ? ' · closed' : '' }}{{ $rec['min_stay'] ? ' · min '.$rec['min_stay'] : '' }}</div>
  </td>
@endforeach</tr>
@endforeach
</tbody></table>
</div></div>
</form>
<div class="legend mt-1"><span><i style="background:#fffbeb;border:1px solid #f59e0b"></i>Recommend raising</span><span><i style="background:#f0fdf4;border:1px solid #22c55e"></i>Recommend lowering</span><span><i style="background:#f8fafc;border:1px solid #e2e8f0"></i>Fixed price</span><span><i style="background:#fef2f2;border:1px solid #fca5a5"></i>Stop-sell</span><span><sup class="rm-tag">RM</sup> published by revenue management</span><span>Hover a cell for the rule breakdown.</span></div>

<div class="grid g-2 mt-2">
<div class="card"><div class="card-head"><h3>How recommendations are made</h3></div><div class="card-body rm-steps">
  <div><b>1 · Base rate</b>The room type's base / weekend rate and any seasonal override (revenue-managed rates excluded).</div>
  <div><b>2 · Rules</b>Every matching pricing rule adjusts it in priority order – by occupancy, lead time, weekday or dates.</div>
  <div><b>3 · Guard-rails</b>Rounded to the nearest {{ setting('rm_round_to', 50) }}, then held between the floor and ceiling.</div>
</div></div>
@if($canManage)
<div class="card"><div class="card-head"><h3>Reset to base rates</h3></div><div class="card-body">
<form method="post" action="{{ route('revenue.reset') }}" class="form-grid" onsubmit="return confirm('Remove revenue-managed rates in this range?')">@csrf
  <input type="hidden" name="days" value="{{ $days }}">
  <div class="field"><label>From</label><input type="date" name="date_from" value="{{ $from->toDateString() }}" required></div>
  <div class="field"><label>To (inclusive)</label><input type="date" name="date_to" value="{{ $from->copy()->addDays($days - 1)->toDateString() }}" required></div>
  <div class="field"><label>Room type</label><select name="room_type_id"><option value="">All room types</option>@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn-danger">Remove published rates</button></div>
</form>
<p class="small muted mb-0">Seasonal and fixed rates entered on the Rate Calendar are not affected.</p>
</div></div>
@endif
</div>
@endsection
@push('scripts')
<script>
(() => {
  const boxes = [...document.querySelectorAll('.rm-grid input[name="pick[]"]')];
  const count = () => { const el = document.getElementById('pickCount'); if (el) el.textContent = boxes.filter(b => b.checked).length; };
  document.querySelectorAll('.rm-grid input[type=number][data-pick]').forEach(inp => inp.addEventListener('input', () => {
    const td = inp.closest('td'), cur = parseFloat(inp.dataset.cur), v = parseFloat(inp.value), d = v - cur;
    td.classList.toggle('up', d > 0.5); td.classList.toggle('down', d < -0.5);
    td.querySelector('.delta').textContent = isNaN(d) || Math.abs(d) < 0.5 ? '' : (d > 0 ? '▲ +' : '▼ ') + Math.round(d).toLocaleString();
    const box = td.querySelector('input[type=checkbox]'); if (box) box.checked = true; count();
  }));
  boxes.forEach(b => b.addEventListener('change', count));
  document.getElementById('selChanges')?.addEventListener('click', () => { boxes.forEach(b => b.checked = b.dataset.change === '1'); count(); });
  document.getElementById('selNone')?.addEventListener('click', () => { boxes.forEach(b => b.checked = false); count(); });
  document.getElementById('pubBtn')?.addEventListener('click', e => { if (!boxes.some(b => b.checked)) { e.preventDefault(); alert('Tick the dates to publish first (or use “Select changes”).'); } });
  count();
})();
</script>
@endpush
