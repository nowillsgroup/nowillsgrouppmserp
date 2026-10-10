<div class="field"><label>Name</label><input name="name" class="input" maxlength="80" required value="{{ old('name', $x?->name ?? 'Lobby kiosk') }}"></div>
<div class="field"><label>Location</label><input name="location" class="input" maxlength="80" value="{{ old('location', $x?->location ?? 'Main lobby') }}" placeholder="Main lobby"></div>
<div class="field"><label>Serial / asset no.</label><input name="serial" class="input" maxlength="60" value="{{ old('serial', $x?->serial) }}"></div>
<div class="field"><label>Notes</label><input name="notes" class="input" maxlength="255" value="{{ old('notes', $x?->notes) }}"></div>
<div class="field span-all"><label>Services</label><div class="check-row">
  <label class="check"><input type="checkbox" name="allow_checkin" value="1" @checked(old('allow_checkin', $x?->allow_checkin ?? true))> Check-in</label>
  <label class="check"><input type="checkbox" name="allow_checkout" value="1" @checked(old('allow_checkout', $x?->allow_checkout ?? true))> Check-out</label>
  <label class="check"><input type="checkbox" name="allow_walkin" value="1" @checked(old('allow_walkin', $x?->allow_walkin ?? true))> Walk-in booking</label>
</div></div>
<div class="field span-all"><label>Hardware</label><div class="check-row">
  <label class="check"><input type="checkbox" name="has_encoder" value="1" @checked(old('has_encoder', $x?->has_encoder ?? true))> Key-card encoder</label>
  <label class="check"><input type="checkbox" name="has_camera" value="1" @checked(old('has_camera', $x?->has_camera ?? true))> ID camera / scanner</label>
</div></div>
