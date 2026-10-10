@php $x = $x ?? null; $P = \App\Models\TvPage::class; @endphp
<div class="field"><label>Title</label><input name="title" maxlength="80" required value="{{ old('title', $x?->title) }}"></div>
<div class="field"><label>Category</label><select name="category" required>@foreach($P::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('category', $x?->category ?? 'info') === $k)>{{ $l }}</option>@endforeach</select></div>
<div class="field"><label>Icon</label><select name="icon" required>@foreach($P::ICONS as $i)<option value="{{ $i }}" @selected(old('icon', $x?->icon ?? 'book') === $i)>{{ $i }}</option>@endforeach</select></div>
<div class="field"><label>Language</label><select name="language" required>@foreach(\App\Support\TvLang::LANGS as $k => $l)<option value="{{ $k }}" @selected(old('language', $x?->language ?? 'en') === $k)>{{ $l }}</option>@endforeach</select></div>
<div class="field"><label>Order</label><input type="number" name="sort" min="0" value="{{ old('sort', $x?->sort ?? 0) }}"></div>
<div class="field"><label>&nbsp;</label><label class="small"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $x?->is_active ?? true))> Shown on the TVs</label></div>
<div class="field span-all"><label>Text</label><textarea name="body" rows="7" maxlength="3000" required>{{ old('body', $x?->body) }}</textarea><span class="tiny muted">Plain text; each line is a paragraph on the TV.</span></div>
