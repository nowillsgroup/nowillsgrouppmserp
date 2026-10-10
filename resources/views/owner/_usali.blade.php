{{-- USALI Summary Operating Statement. $cols = [m_act, m_bud, m_ly, y_act, y_bud, y_ly], $layout = UsaliService::layout(), $drill = period (Y-m) for drill-down links or null --}}
@php
  $drill = $drill ?? null;
  $costKeys = ['tde', 'tue', 'mgmt', 'tnoie', 'rr', 'tida', 'b_interest', 'b_depreciation', 'b_amortization', 'b_income_tax', 'n_rent', 'n_prop_tax', 'n_insurance', 'n_nonop_other'];
  $isCost = fn ($k) => in_array($k, $costKeys, true) || str_starts_with($k, 'exp_') || str_starts_with($k, 'u_');
  $pct = fn ($c, $k, $base) => ($c[$base] ?? 0) != 0 ? number_format(($c[$k] ?? 0) * 100 / $c[$base], 1) : '';
  $var = function ($a, $b, $k) use ($isCost) {
      if ($b === null) return ['', ''];
      $d = round($a - $b, 2);
      if (abs($d) < 0.005) return ['–', ''];
      $good = $isCost($k) ? $d < 0 : $d > 0;
      return [money($d, false), $good ? 'var-good' : 'var-bad'];
  };
  $hasBud = collect($cols['m_bud'] ?? [])->filter(fn ($v) => is_numeric($v) && $v != 0)->isNotEmpty() || collect($cols['y_bud'] ?? [])->filter(fn ($v) => is_numeric($v) && $v != 0)->isNotEmpty();
  $hasLy = collect($cols['y_ly'] ?? [])->filter(fn ($v) => is_numeric($v) && $v != 0)->isNotEmpty();
@endphp
<div class="table-wrap"><table class="table usali-table" id="usaliTable">
  <thead>
    <tr class="usali-grp"><th></th><th colspan="{{ 2 + ($hasBud ? 2 : 0) + ($hasLy ? 2 : 0) }}" class="center">Month</th><th colspan="{{ 2 + ($hasBud ? 2 : 0) + ($hasLy ? 1 : 0) }}" class="center usali-ytd">Year to date</th></tr>
    <tr><th>Summary Operating Statement</th>
      <th class="num">Actual</th><th class="num">%</th>@if($hasBud)<th class="num">Budget</th><th class="num">Var.</th>@endif @if($hasLy)<th class="num">Last year</th><th class="num">Var.</th>@endif
      <th class="num usali-ytd">Actual</th><th class="num">%</th>@if($hasBud)<th class="num">Budget</th><th class="num">Var.</th>@endif @if($hasLy)<th class="num">Last year</th>@endif</tr>
  </thead>
  <tbody>
  @foreach($layout as $row) @php [$k, $label, $kind] = $row; $base = $row[3] ?? 'tor'; $dd = $row[4] ?? null; @endphp
    @if($kind === 'head')
      <tr class="usali-head" data-key="{{ $k }}"><td colspan="20">{{ $label }}</td></tr>
    @else
      @php $ma = $cols['m_act'][$k] ?? 0; $ya = $cols['y_act'][$k] ?? 0; [$mv, $mvc] = $var($ma, $hasBud ? ($cols['m_bud'][$k] ?? 0) : null, $k); [$lv, $lvc] = $var($ma, $hasLy ? ($cols['m_ly'][$k] ?? 0) : null, $k); [$yv, $yvc] = $var($ya, $hasBud ? ($cols['y_bud'][$k] ?? 0) : null, $k); @endphp
      <tr class="usali-{{ $kind }}" data-key="{{ $k }}">
        <td>@if($drill && $dd && $kind === 'line')<a href="{{ route('owner.usali.dept', ['dept' => $dd, 'period' => $drill]) }}">{{ $label }}</a>@else{{ $label }}@endif</td>
        <td class="num">{{ money($ma, false) }}</td><td class="num pct">{{ $pct($cols['m_act'], $k, $base) }}</td>
        @if($hasBud)<td class="num">{{ money($cols['m_bud'][$k] ?? 0, false) }}</td><td class="num {{ $mvc }}">{{ $mv }}</td>@endif
        @if($hasLy)<td class="num">{{ money($cols['m_ly'][$k] ?? 0, false) }}</td><td class="num {{ $lvc }}">{{ $lv }}</td>@endif
        <td class="num usali-ytd">{{ money($ya, false) }}</td><td class="num pct">{{ $pct($cols['y_act'], $k, $base) }}</td>
        @if($hasBud)<td class="num">{{ money($cols['y_bud'][$k] ?? 0, false) }}</td><td class="num {{ $yvc }}">{{ $yv }}</td>@endif
        @if($hasLy)<td class="num">{{ money($cols['y_ly'][$k] ?? 0, false) }}</td>@endif
      </tr>
    @endif
  @endforeach
  </tbody>
</table></div>
<p class="tiny muted usali-note">Departmental expenses are shown as a % of their own department's revenue; everything else as a % of total operating revenue. Variances in green are favourable (more revenue or profit, less cost).@if(abs($cols['m_act']['excluded'] ?? 0) >= 0.01) Accounts excluded from the statement moved profit by {{ money($cols['m_act']['excluded']) }} this month.@endif</p>
