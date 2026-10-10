<div class="form-grid">
  <div class="field span-all"><label>Activity / title</label><input name="title" maxlength="150" required value="{{ $x->title ?? '' }}" placeholder="Deep-fat frying in the main kitchen"></div>
  <div class="field"><label>Area</label><input name="area" maxlength="80" required value="{{ $x->area ?? '' }}" list="hseAreas"></div>
  <div class="field"><label>Department</label><select name="department_id"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}" @selected(($x->department_id ?? null) === $d->id)>{{ $d->name }}</option>@endforeach</select></div>
  <div class="field span-all"><label>Hazard – what could go wrong</label><textarea name="hazard" rows="2" required>{{ $x->hazard ?? '' }}</textarea></div>
  <div class="field span-all"><label>Who is at risk</label><input name="who_at_risk" maxlength="120" required value="{{ $x->who_at_risk ?? '' }}" placeholder="Kitchen staff, stewards"></div>
  <div class="field"><label>Likelihood (before controls)</label><select name="likelihood" required>@foreach(\App\Models\HseRisk::LIKELIHOOD as $k => $l)<option value="{{ $k }}" @selected(($x->likelihood ?? 3) === $k)>{{ $k }} – {{ $l }}</option>@endforeach</select></div>
  <div class="field"><label>Severity (before controls)</label><select name="severity" required>@foreach(\App\Models\HseRisk::SEVERITY as $k => $l)<option value="{{ $k }}" @selected(($x->severity ?? 3) === $k)>{{ $k }} – {{ $l }}</option>@endforeach</select></div>
  <div class="field span-all"><label>Controls in place</label><textarea name="controls" rows="3" required>{{ $x->controls ?? '' }}</textarea></div>
  <div class="field"><label>Residual likelihood</label><select name="residual_likelihood" required>@foreach(\App\Models\HseRisk::LIKELIHOOD as $k => $l)<option value="{{ $k }}" @selected(($x->residual_likelihood ?? 2) === $k)>{{ $k }} – {{ $l }}</option>@endforeach</select></div>
  <div class="field"><label>Residual severity</label><select name="residual_severity" required>@foreach(\App\Models\HseRisk::SEVERITY as $k => $l)<option value="{{ $k }}" @selected(($x->residual_severity ?? 2) === $k)>{{ $k }} – {{ $l }}</option>@endforeach</select></div>
  <div class="field span-all"><label>Further actions (required when residual risk is 10 or more)</label><textarea name="further_actions" rows="2">{{ $x->further_actions ?? '' }}</textarea></div>
  <div class="field"><label>Risk owner</label><select name="owner_id"><option value="">—</option>@foreach($users as $uu)<option value="{{ $uu->id }}" @selected(($x->owner_id ?? null) === $uu->id)>{{ $uu->name }}</option>@endforeach</select></div>
  <div class="field"><label>Review by</label><input type="date" name="review_on" value="{{ isset($x) && $x->review_on ? $x->review_on->toDateString() : '' }}" placeholder="default interval"></div>
</div>
<p class="tiny muted mt-1">Risk = likelihood × severity: 1–4 low · 5–9 medium · 10–14 high (needs acceptance by another manager) · 15–25 very high (must not go ahead).</p>
