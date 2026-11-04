@php
  $isUnavailable = $noCurrentData ?? false;
  $state = $isUnavailable ? 'empty' : ($change === null ? 'new' : ($change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat')));
  $label = $isUnavailable ? 'No data' : ($change === null ? 'New' : (($change > 0 ? '+' : '').number_format($change, 1).'%'));
  $arrow = $change === null ? '' : ($change > 0 ? '↑' : ($change < 0 ? '↓' : '→'));
@endphp
<small class="pos-dash-change is-{{ $state }}" title="Compared with {{ $previousPeriodLabel }}">
  @if($arrow !== '')<span class="pos-dash-change-arrow" aria-hidden="true">{{ $arrow }}</span>@endif
  <span>{{ $label }}</span>
  @unless($isUnavailable)<span class="pos-dash-change-context">vs prior</span>@endunless
  <span class="sr-only">Compared with {{ $previousPeriodLabel }}</span>
</small>
