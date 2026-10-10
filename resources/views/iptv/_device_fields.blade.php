@php $D = \App\Models\TvDevice::class; $x = $x ?? null; @endphp
<div class="field"><label>Type</label><select name="kind" required>@foreach($D::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $x?->kind ?? 'room') === $k)>{{ $l }}</option>@endforeach</select></div>
<div class="field"><label>Room (room TVs)</label><select name="room_id"><option value="">—</option>@foreach($rooms as $r)<option value="{{ $r->id }}" @selected(old('room_id', $x?->room_id) == $r->id)>{{ $r->room_no }}</option>@endforeach</select></div>
<div class="field"><label>Location (public screens)</label><input name="location" maxlength="80" value="{{ old('location', $x?->location) }}" placeholder="e.g. Lobby, Restaurant entrance"></div>
<div class="field"><label>Name</label><input name="name" maxlength="80" value="{{ old('name', $x?->name) }}" placeholder="auto: Room 101 TV"></div>
<div class="field"><label>Platform</label><select name="platform" required>@foreach($D::PLATFORMS as $k => $l)<option value="{{ $k }}" @selected(old('platform', $x?->platform ?? 'samsung') === $k)>{{ $l }}</option>@endforeach</select></div>
<div class="field"><label>Model</label><input name="model" maxlength="60" value="{{ old('model', $x?->model) }}" placeholder="e.g. Samsung HG43AU800"></div>
<div class="field"><label>MAC address</label><input name="mac" maxlength="17" value="{{ old('mac', $x?->mac) }}" placeholder="AA:BB:CC:DD:EE:FF"></div>
<div class="field"><label>Serial no.</label><input name="serial" maxlength="60" value="{{ old('serial', $x?->serial) }}"></div>
<div class="field span-all"><label>Notes</label><input name="notes" maxlength="255" value="{{ old('notes', $x?->notes) }}"></div>
