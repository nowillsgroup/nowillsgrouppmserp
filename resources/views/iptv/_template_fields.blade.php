@php $x = $x ?? null; $T = \App\Models\TvWelcomeTemplate::class; @endphp
<div class="field"><label>Name</label><input name="name" maxlength="80" required value="{{ old('name', $x?->name) }}"></div>
<div class="field"><label>Language</label><select name="language" required>@foreach(\App\Support\TvLang::LANGS as $k => $l)<option value="{{ $k }}" @selected(old('language', $x?->language ?? 'en') === $k)>{{ $l }}</option>@endforeach</select></div>
<div class="field"><label>Shown to</label><select name="segment" required>@foreach($T::SEGMENTS as $k => $l)<option value="{{ $k }}" @selected(old('segment', $x?->segment ?? 'all') === $k)>{{ $l }}</option>@endforeach</select></div>
<div class="field"><label>Priority</label><input type="number" name="priority" min="0" max="100" value="{{ old('priority', $x?->priority ?? 0) }}"></div>
<div class="field"><label>Background</label><select name="theme" required>@foreach($T::THEMES as $k => $l)<option value="{{ $k }}" @selected(old('theme', $x?->theme ?? 'gold') === $k)>{{ $l }}</option>@endforeach</select></div>
<div class="field"><label>Valid from / to</label><div class="flex" style="gap:.3rem"><input type="date" name="valid_from" value="{{ old('valid_from', $x?->valid_from?->toDateString()) }}"><input type="date" name="valid_to" value="{{ old('valid_to', $x?->valid_to?->toDateString()) }}"></div></div>
<div class="field span-all"><label>Headline</label><input name="headline" maxlength="120" required value="{{ old('headline', $x?->headline) }}" placeholder="Akwaaba, {name}!"></div>
<div class="field span-all"><label>Message</label><textarea name="body" rows="4" maxlength="1000">{{ old('body', $x?->body) }}</textarea>
  <span class="tiny muted">Placeholders: {{ implode(' ', \App\Services\IptvService::PLACEHOLDERS) }}</span></div>
<div class="field span-all"><label class="small"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $x?->is_active ?? true))> Active</label></div>
