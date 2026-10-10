{{-- Hotel KPI table. $kpis = ['m_act' => [...], 'm_bud' => [...], 'm_ly' => [...]?, 'y_act' => [...], 'y_bud' => [...]] --}}
@php
  $U = \App\Services\UsaliService::class;
  $fmt = function ($v, $t) { if ($v === null) return '–'; return match ($t) { '%' => number_format($v, 1).'%', 'money' => money($v, false), default => number_format($v) }; };
  $lower = ['cpor', 'labor_pct', 'fnb_cost_pct'];
  $cols = array_filter(['m_act' => 'Month', 'm_bud' => 'Budget', 'm_ly' => 'Last year', 'y_act' => 'YTD', 'y_bud' => 'YTD budget'], fn ($l, $k) => isset($kpis[$k]), ARRAY_FILTER_USE_BOTH);
@endphp
<div class="table-wrap"><table class="table table-compact" id="kpiTable">
  <thead><tr><th>Indicator</th>@foreach($cols as $k => $l)<th class="num">{{ $l }}</th>@endforeach</tr></thead>
  <tbody>@foreach($U::KPI_LABELS as $key => [$label, $type])
    <tr data-kpi="{{ $key }}"><td>{{ $label }}</td>
      @foreach($cols as $k => $l) @php $v = $kpis[$k][$key] ?? null; $cmp = $k === 'm_act' ? ($kpis['m_bud'][$key] ?? null) : ($k === 'y_act' ? ($kpis['y_bud'][$key] ?? null) : null);
        $cls = ($cmp !== null && $v !== null && ! in_array($key, ['sold', 'available'], true) && abs($v - $cmp) > 0.001) ? ((in_array($key, $lower, true) ? $v < $cmp : $v > $cmp) ? 'var-good' : 'var-bad') : ''; @endphp
        <td class="num {{ $k === 'm_act' || $k === 'y_act' ? 'bold' : '' }} {{ $cls }}">{{ $fmt($v, $type) }}</td>
      @endforeach
    </tr>
  @endforeach</tbody>
</table></div>
