@php $x = $x ?? null; @endphp
<div class="field"><label>Number</label><input type="number" name="number" min="1" max="9999" required value="{{ old('number', $x?->number) }}"></div>
<div class="field"><label>Name</label><input name="name" maxlength="80" required value="{{ old('name', $x?->name) }}"></div>
<div class="field"><label>Category</label><select name="category" required>@foreach(\App\Models\TvChannel::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('category', $x?->category ?? 'entertainment') === $k)>{{ $l }}</option>@endforeach</select></div>
<div class="field"><label>Language</label><input name="language" maxlength="30" value="{{ old('language', $x?->language) }}" placeholder="English, Twi, French…"></div>
<div class="field span-all"><label>Stream address</label><input name="stream_url" maxlength="255" required value="{{ old('stream_url', $x?->stream_url) }}" placeholder="udp://@239.10.0.1:1234 or https://…/index.m3u8"></div>
<div class="field"><label>Guide (XMLTV) id</label><input name="epg_id" maxlength="80" value="{{ old('epg_id', $x?->epg_id) }}" placeholder="e.g. gtv.gh"></div>
<div class="field"><label>&nbsp;</label><label class="small"><input type="checkbox" name="is_radio" value="1" @checked(old('is_radio', $x?->is_radio))> Radio station</label>
  <label class="small"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $x?->is_active ?? true))> In the line-up</label></div>
