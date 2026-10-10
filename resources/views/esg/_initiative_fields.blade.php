@php $C = \App\Models\EsgInitiative::CATEGORIES; @endphp
<div class="form-grid">
  <div class="field span-all"><label>Title</label><input name="title" maxlength="150" value="{{ old('title', $x?->title) }}" required></div>
  <div class="field"><label>Category</label><select name="category" required>@foreach($C as $k => $l)<option value="{{ $k }}" @selected(old('category', $x?->category) === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label>Owner</label><select name="owner_user_id"><option value="">—</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected(old('owner_user_id', $x?->owner_user_id) == $u->id)>{{ $u->name }}</option>@endforeach</select></div>
  <div class="field span-all"><label>Description / business case</label><textarea name="description" class="input" rows="3" maxlength="5000">{{ old('description', $x?->description) }}</textarea></div>
  <div class="field"><label>Capital cost (GH₵)</label><input type="number" name="capex" step="0.01" min="0" value="{{ old('capex', $x?->capex) }}"></div>
  <div class="field"><label>Saving per year (GH₵)</label><input type="number" name="saving_ghs" step="0.01" min="0" value="{{ old('saving_ghs', $x?->saving_ghs) }}"></div>
  <div class="field"><label>Energy saved / yr (kWh)</label><input type="number" name="saving_kwh" step="0.1" min="0" value="{{ old('saving_kwh', $x?->saving_kwh) }}"></div>
  <div class="field"><label>Water saved / yr (m³)</label><input type="number" name="saving_m3" step="0.1" min="0" value="{{ old('saving_m3', $x?->saving_m3) }}"></div>
  <div class="field"><label>Waste diverted / yr (kg)</label><input type="number" name="saving_waste_kg" step="0.1" min="0" value="{{ old('saving_waste_kg', $x?->saving_waste_kg) }}"></div>
  <div class="field"><label>CO₂e saved / yr (kg)</label><input type="number" name="saving_co2_kg" step="0.1" min="0" value="" placeholder="blank = calculate"></div>
  <div class="field"><label>Start</label><input type="date" name="start_on" value="{{ old('start_on', $x?->start_on?->toDateString()) }}"></div>
  <div class="field"><label>Due</label><input type="date" name="due_on" value="{{ old('due_on', $x?->due_on?->toDateString()) }}"></div>
</div>
<p class="tiny muted">Leave CO₂e blank to calculate it from the energy, water and waste savings with the current factors.</p>
