@php $C = \App\Models\TrainingCourse::class; $mid = $c ? 'courseModal'.$c->id : 'courseModalNew'; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:44rem"><form method="post" action="{{ $c ? route('learning.course.update', $c) : route('learning.courses.store') }}">@csrf @if($c) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $c ? 'Edit '.$c->name : 'New course' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Code</label><input name="code" maxlength="20" required value="{{ $c?->code }}" placeholder="FIRE"></div>
    <div class="field"><label>Category</label><select name="category">@foreach($C::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected($c?->category === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Name</label><input name="name" maxlength="150" required value="{{ $c?->name }}"></div>
    <div class="field"><label>Delivery</label><select name="delivery">@foreach($C::DELIVERY as $k => $l)<option value="{{ $k }}" @selected(($c?->delivery ?? 'classroom') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Duration (hours)</label><input type="number" name="duration_hours" min="0.5" max="200" step="0.5" required value="{{ $c?->duration_hours ?? 2 }}"></div>
    <div class="field"><label>Valid for (months) <span class="tiny muted">blank = no expiry</span></label><input type="number" name="validity_months" min="1" max="120" value="{{ $c?->validity_months }}"></div>
    <div class="field"><label>Pass mark % <span class="tiny muted">blank = attendance</span></label><input type="number" name="pass_mark" min="1" max="100" value="{{ $c?->pass_mark }}"></div>
    <div class="field"><label>Provider / trainer</label><input name="provider" maxlength="150" value="{{ $c?->provider }}" placeholder="In-house, Ghana Red Cross…"></div>
    <div class="field"><label>Cost per head (GH₵)</label><input type="number" name="cost_per_head" min="0" step="0.01" value="{{ $c?->cost_per_head ?? 0 }}"></div>
    <div class="field"><label>Based on SOP <span class="tiny muted">passing = signing it</span></label><select name="sop_document_id"><option value="">—</option>@foreach($sops as $d)<option value="{{ $d->id }}" @selected($c?->sop_document_id == $d->id)>{{ $d->code }} {{ \Illuminate\Support\Str::limit($d->title, 40) }}</option>@endforeach</select></div>
    <div class="field"><label>Food safety link</label><select name="fs_type"><option value="">—</option><option value="hygiene" @selected($c?->fs_type === 'hygiene')>Renews the food-hygiene certificate</option></select></div>
    <div class="field span-all"><label>Description / learning outcomes</label><textarea name="description" rows="3" maxlength="2000">{{ $c?->description }}</textarea></div>
    <label class="span-all small"><input type="checkbox" name="is_active" value="1" @checked($c?->is_active ?? true)> Active (inactive courses drop out of the matrix)</label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save course</button></div>
</form></div></div>
