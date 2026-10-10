@php $sfx = $t ? $t->id : 'new'; $uses = $t ? ($t->usable_on ?: ['folio', 'pos']) : ['folio', 'pos']; @endphp
<form method="post" action="{{ $t ? route('vouchers.types.update', $t) : route('vouchers.types.store') }}" id="typeForm{{ $sfx }}" class="small" style="margin-top:.5rem">@csrf
  <div class="form-grid">
    <div class="field"><label for="tn{{ $sfx }}">Name</label><input id="tn{{ $sfx }}" name="name" value="{{ $t?->name }}" required maxlength="80"></div>
    <div class="field"><label for="tk{{ $sfx }}">Kind</label><select id="tk{{ $sfx }}" name="kind">@foreach(\App\Models\GiftVoucherType::KINDS as $k => $l)<option value="{{ $k }}" @selected(($t?->kind ?? 'value') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label for="tf{{ $sfx }}">Fixed value (empty = any amount)</label><input id="tf{{ $sfx }}" type="number" step="0.01" min="0" name="face_value" value="{{ $t?->face_value }}"></div>
    <div class="field"><label for="tv{{ $sfx }}">Validity (months)</label><input id="tv{{ $sfx }}" type="number" min="1" max="120" name="validity_months" value="{{ $t?->validity_months ?? 12 }}" required></div>
    <div class="field"><label for="tmin{{ $sfx }}">Min (open amount)</label><input id="tmin{{ $sfx }}" type="number" step="0.01" min="0" name="min_value" value="{{ $t?->min_value }}"></div>
    <div class="field"><label for="tmax{{ $sfx }}">Max (0 = hotel limit)</label><input id="tmax{{ $sfx }}" type="number" step="0.01" min="0" name="max_value" value="{{ $t?->max_value }}"></div>
    <div class="field"><label>Can be used on</label><div class="flex" style="gap:.8rem">@foreach(\App\Models\GiftVoucherType::USES as $k => $l)<label class="flex" style="gap:.3rem;align-items:center"><input type="checkbox" name="usable_on[]" value="{{ $k }}" @checked(in_array($k, $uses, true))> {{ $l }}</label>@endforeach</div></div>
    <div class="field"><label for="tc{{ $sfx }}">Colour</label><input id="tc{{ $sfx }}" type="color" name="color" value="{{ $t?->color ?? '#0f766e' }}"></div>
    <div class="field span-2"><label for="tt{{ $sfx }}">Terms</label><textarea id="tt{{ $sfx }}" name="terms" rows="2" maxlength="1000">{{ $t?->terms }}</textarea></div>
    <label class="flex" style="gap:.3rem;align-items:center"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($t ? $t->is_active : true)> Active</label>
  </div>
  <div style="margin-top:.5rem"><button class="btn btn-primary btn-sm">{{ $t ? 'Save type' : 'Add type' }}</button></div>
</form>
