<div class="hse-heat" id="hseHeat">
  <div class="hl"></div>@for($s = 1; $s <= 5; $s++)<div class="hh">{{ $s }}</div>@endfor
  @for($l = 5; $l >= 1; $l--)<div class="hl" title="{{ \App\Models\HseRisk::LIKELIHOOD[$l] }}">{{ $l }}</div>
    @for($s = 1; $s <= 5; $s++)@php $n = $heat[$l][$s] ?? 0; [$bk] = \App\Models\HseRisk::band($l * $s); @endphp<div class="hc hc-{{ $bk }}" title="likelihood {{ $l }} × severity {{ $s }} = {{ $l * $s }}">{{ $n ?: '' }}</div>@endfor
  @endfor
</div>
<div class="tiny muted mt-1">Rows: likelihood 5 (almost certain) → 1 (rare). Columns: severity 1 (negligible) → 5 (catastrophic). Counts are live assessments by residual risk.</div>
