@php $M = \App\Services\EsgService::METRICS; @endphp
<div class="form-grid">
  <div class="field span-all"><label>Metric</label><select name="metric" required>@foreach($M as $k => [$l, $u, $b])<option value="{{ $k }}" @selected(($x?->metric) === $k)>{{ $l }} ({{ $u }}, {{ $b }} is better)</option>@endforeach</select></div>
  <div class="field"><label>Baseline year</label><input type="number" name="baseline_year" min="2000" max="2100" value="{{ $x?->baseline_year ?? bdate()->year - 1 }}" required></div>
  <div class="field"><label>Baseline value</label><input type="number" name="baseline_value" step="0.001" min="0" value="{{ $x?->baseline_value }}" required></div>
  <div class="field"><label>Target year</label><input type="number" name="target_year" min="2000" max="2100" value="{{ $x?->target_year ?? bdate()->year + 3 }}" required></div>
  <div class="field"><label>Target value</label><input type="number" name="target_value" step="0.001" min="0" value="{{ $x?->target_value }}" required></div>
  <div class="field span-all"><label>Notes</label><input name="notes" maxlength="255" value="{{ $x?->notes }}"></div>
  @if($x)<label class="small span-all"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($x->is_active)> Active (untick to retire the target)</label>@endif
</div>
