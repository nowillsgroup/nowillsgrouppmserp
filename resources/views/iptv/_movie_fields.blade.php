@php $x = $x ?? null; $M = \App\Models\TvMovie::class; @endphp
<div class="field span-all"><label>Title</label><input name="title" maxlength="120" required value="{{ old('title', $x?->title) }}"></div>
<div class="field"><label>Genre</label><select name="genre" required>@foreach($M::GENRES as $k => $l)<option value="{{ $k }}" @selected(old('genre', $x?->genre) === $k)>{{ $l }}</option>@endforeach</select></div>
<div class="field"><label>Rating</label><select name="rating" required>@foreach($M::RATINGS as $r)<option value="{{ $r }}" @selected(old('rating', $x?->rating ?? 'PG') === $r)>{{ $r }}</option>@endforeach</select></div>
<div class="field"><label>Length (min)</label><input type="number" name="duration_min" min="1" max="600" required value="{{ old('duration_min', $x?->duration_min ?? 100) }}"></div>
<div class="field"><label>Year</label><input type="number" name="year" min="1900" max="2100" value="{{ old('year', $x?->year) }}"></div>
<div class="field"><label>Language</label><input name="language" maxlength="30" value="{{ old('language', $x?->language) }}"></div>
<div class="field"><label>Price before tax (GH₵)</label><input type="number" name="price" step="0.01" min="0" required value="{{ old('price', $x?->price) }}"></div>
<div class="field"><label>Rental window (hours)</label><input type="number" name="rental_hours" min="1" max="72" required value="{{ old('rental_hours', $x?->rental_hours ?? 24) }}"></div>
<div class="field"><label>Sort</label><input type="number" name="sort" min="0" value="{{ old('sort', $x?->sort ?? 0) }}"></div>
<div class="field span-all"><label>Stream address (VOD server)</label><input name="stream_url" maxlength="255" value="{{ old('stream_url', $x?->stream_url) }}" placeholder="https://vod.hotel.local/movies/…/index.m3u8"></div>
<div class="field span-all"><label>Synopsis</label><textarea name="synopsis" rows="2" maxlength="500">{{ old('synopsis', $x?->synopsis) }}</textarea></div>
<div class="field span-all"><label class="small"><input type="checkbox" name="is_adult" value="1" @checked(old('is_adult', $x?->is_adult))> Adult title (hidden unless adult content is allowed in settings; always needs the room PIN)</label>
  <label class="small"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $x?->is_active ?? true))> Available to rent</label></div>
