@php
  $sfx = $t ? $t->id : 'new';
  $formKey = (string) $sfx;
  $restoreForm = (string) old('_voucher_type_form', '') === $formKey;
  $uses = $t ? ($t->usable_on ?: ['folio', 'pos']) : ['folio', 'pos'];
  if ($restoreForm && is_array(old('usable_on'))) $uses = old('usable_on');
  $kindValue = $restoreForm ? old('kind', $t?->kind ?? 'value') : ($t?->kind ?? 'value');
  $activeValue = $restoreForm ? (bool) old('is_active', false) : ($t ? $t->is_active : true);
@endphp
<form method="post" action="{{ $t ? route('vouchers.types.update', $t) : route('vouchers.types.store') }}" id="typeForm{{ $sfx }}" class="small" style="margin-top:.5rem">@csrf
  <input type="hidden" name="_voucher_type_form" value="{{ $formKey }}">
  <div class="form-grid">
    <div class="field"><label for="tn{{ $sfx }}">Name</label><input id="tn{{ $sfx }}" name="name" value="{{ $restoreForm ? old('name', $t?->name) : $t?->name }}" required maxlength="80" @if($restoreForm && $errors->has('name')) aria-invalid="true" @endif>@if($restoreForm && $errors->has('name'))<span class="err">{{ $errors->first('name') }}</span>@endif</div>
    <div class="field"><label for="tk{{ $sfx }}">Kind</label><select id="tk{{ $sfx }}" name="kind" @if($restoreForm && $errors->has('kind')) aria-invalid="true" @endif>@foreach(\App\Models\GiftVoucherType::KINDS as $k => $l)<option value="{{ $k }}" @selected($kindValue === $k)>{{ $l }}</option>@endforeach</select>@if($restoreForm && $errors->has('kind'))<span class="err">{{ $errors->first('kind') }}</span>@endif</div>
    <div class="field"><label for="tf{{ $sfx }}">Fixed value (empty = any amount)</label><input id="tf{{ $sfx }}" type="number" step="0.01" min="0" name="face_value" value="{{ $restoreForm ? old('face_value', $t?->face_value) : $t?->face_value }}" @if($restoreForm && $errors->has('face_value')) aria-invalid="true" @endif>@if($restoreForm && $errors->has('face_value'))<span class="err">{{ $errors->first('face_value') }}</span>@endif</div>
    <div class="field"><label for="tv{{ $sfx }}">Validity (months)</label><input id="tv{{ $sfx }}" type="number" min="1" max="120" name="validity_months" value="{{ $restoreForm ? old('validity_months', $t?->validity_months ?? 12) : ($t?->validity_months ?? 12) }}" required @if($restoreForm && $errors->has('validity_months')) aria-invalid="true" @endif>@if($restoreForm && $errors->has('validity_months'))<span class="err">{{ $errors->first('validity_months') }}</span>@endif</div>
    <div class="field"><label for="tmin{{ $sfx }}">Min (open amount)</label><input id="tmin{{ $sfx }}" type="number" step="0.01" min="0" name="min_value" value="{{ $restoreForm ? old('min_value', $t?->min_value) : $t?->min_value }}" @if($restoreForm && $errors->has('min_value')) aria-invalid="true" @endif>@if($restoreForm && $errors->has('min_value'))<span class="err">{{ $errors->first('min_value') }}</span>@endif</div>
    <div class="field"><label for="tmax{{ $sfx }}">Max (0 = hotel limit)</label><input id="tmax{{ $sfx }}" type="number" step="0.01" min="0" name="max_value" value="{{ $restoreForm ? old('max_value', $t?->max_value) : $t?->max_value }}" @if($restoreForm && $errors->has('max_value')) aria-invalid="true" @endif>@if($restoreForm && $errors->has('max_value'))<span class="err">{{ $errors->first('max_value') }}</span>@endif</div>
    <div class="field"><label>Can be used on</label><div class="flex" style="gap:.8rem">@foreach(\App\Models\GiftVoucherType::USES as $k => $l)<label class="flex" style="gap:.3rem;align-items:center"><input type="checkbox" name="usable_on[]" value="{{ $k }}" @checked(in_array($k, $uses, true))> {{ $l }}</label>@endforeach</div>@if($restoreForm && $errors->has('usable_on'))<span class="err">{{ $errors->first('usable_on') }}</span>@endif</div>
    <div class="field"><label for="tc{{ $sfx }}">Colour</label><input id="tc{{ $sfx }}" type="color" name="color" value="{{ $restoreForm ? old('color', $t?->color ?? '#166534') : ($t?->color ?? '#166534') }}" @if($restoreForm && $errors->has('color')) aria-invalid="true" @endif>@if($restoreForm && $errors->has('color'))<span class="err">{{ $errors->first('color') }}</span>@endif</div>
    <div class="field span-2"><label for="tt{{ $sfx }}">Terms</label><textarea id="tt{{ $sfx }}" name="terms" rows="2" maxlength="1000" @if($restoreForm && $errors->has('terms')) aria-invalid="true" @endif>{{ $restoreForm ? old('terms', $t?->terms) : $t?->terms }}</textarea>@if($restoreForm && $errors->has('terms'))<span class="err">{{ $errors->first('terms') }}</span>@endif</div>
    <label class="flex" style="gap:.3rem;align-items:center"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($activeValue)> Active</label>
  </div>
  <div style="margin-top:.5rem"><button class="btn btn-primary btn-sm">{{ $t ? 'Save type' : 'Add type' }}</button></div>
</form>
