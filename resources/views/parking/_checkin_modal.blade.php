{{-- v6.6 park a vehicle: guest (self / valet), visitor or permit holder --}}
<div class="modal" id="pkCheckinModal"><div class="modal-box" style="max-width:52rem"><form method="post" action="{{ route('parking.checkin') }}" id="pkCheckinForm">@csrf
  <div class="modal-head"><h3>Park a vehicle</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Who is parking</label><select name="kind" id="pkKind" required>@foreach(\App\Models\ParkingSession::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', 'guest') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Zone</label><select name="parking_zone_id" required>@foreach($zones as $z)<option value="{{ $z->id }}" data-type="{{ $z->type }}" @selected((int) old('parking_zone_id') === $z->id)>{{ $z->code }} · {{ $z->name }} ({{ $z->typeLabel() }})</option>@endforeach</select></div>
    <div class="field"><label>Number plate</label><input name="plate" value="{{ old('plate') }}" placeholder="GR 1234-21" maxlength="20" required style="text-transform:uppercase" autocomplete="off"></div>
    <div class="field pk-guest"><label>Guest room</label><select name="reservation_room_id"><option value="">— choose —</option>@foreach($stays as $st)<option value="{{ $st->id }}" @selected((int) old('reservation_room_id') === $st->id)>{{ $st->room?->room_no ?? 'unassigned' }} · {{ $st->guest?->full_name }}{{ $st->status === 'reserved' ? ' (arriving)' : '' }}</option>@endforeach</select></div>
    <div class="field"><label>Make</label><input name="make" value="{{ old('make') }}" maxlength="40" placeholder="Toyota"></div>
    <div class="field"><label>Model</label><input name="model" value="{{ old('model') }}" maxlength="40" placeholder="Corolla"></div>
    <div class="field"><label>Colour</label><input name="colour" value="{{ old('colour') }}" maxlength="30"></div>
    <div class="field"><label>Type</label><select name="vehicle_type">@foreach(\App\Models\ParkingVehicle::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('vehicle_type', 'car') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Driver / owner name</label><input name="guest_name" value="{{ old('guest_name') }}" maxlength="120" placeholder="blank = guest / permit holder"></div>
    <div class="field"><label>Phone</label><input name="phone" value="{{ old('phone') }}" maxlength="30"></div>
    <div class="field"><label>Bay</label><input name="bay" value="{{ old('bay') }}" maxlength="20" placeholder="B-14"></div>
    <div class="field span-all pk-novalet-staff"><label class="check"><input type="checkbox" name="is_valet" value="1" id="pkValet" @checked(old('is_valet'))> Valet – we take the keys and park the car</label></div>
    <div class="field pk-valet"><label>Key tag</label><input name="key_tag" value="{{ old('key_tag') }}" maxlength="20" placeholder="K-031"></div>
    <div class="field pk-valet"><label>Fuel</label><select name="fuel"><option value="">—</option>@foreach(\App\Models\ParkingSession::FUEL as $f)<option @selected(old('fuel') === $f)>{{ $f }}</option>@endforeach</select></div>
    <div class="field span-all pk-valet"><label>Existing damage (walk round the car with the driver)</label><div class="flex gap-1 wrap">@foreach(\App\Models\ParkingSession::PANELS as $k => $l)<label class="check small"><input type="checkbox" name="damage[]" value="{{ $k }}" @checked(in_array($k, (array) old('damage', [])))> {{ $l }}</label>@endforeach</div></div>
    <div class="field span-all pk-valet"><label>Condition notes</label><input name="condition_notes" value="{{ old('condition_notes') }}" maxlength="500" placeholder="scratch on rear bumper, cracked left mirror…"></div>
    <div class="field span-all pk-valet"><label>Valuables left in the car</label><input name="valuables" value="{{ old('valuables') }}" maxlength="200" placeholder="none declared"></div>
    <div class="field span-all"><label>Notes</label><input name="notes" value="{{ old('notes') }}" maxlength="255"></div>
  </div>
  <p class="tiny muted mt-1">Guests: {{ $cfg['guest_rate'] > 0 ? money($cfg['guest_rate']).' / night self-park' : 'self-park free' }} · valet {{ money($cfg['valet_rate']) }} / night, posted to the room with the night audit. Visitors: {{ $cfg['free_minutes'] }} min free, then {{ money($cfg['hour_rate']) }} / h{{ $cfg['daily_max'] > 0 ? ', max '.money($cfg['daily_max']).' / day' : '' }}{{ $cfg['valet_fee'] > 0 ? ' + valet '.money($cfg['valet_fee']) : '' }}. Permit holders park free.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Issue ticket</button></div>
</form></div></div>
<script>
(function () {
  const f = document.getElementById('pkCheckinForm'); if (!f) return;
  const sync = () => {
    const kind = f.kind.value, valet = f.querySelector('#pkValet').checked;
    f.querySelectorAll('.pk-guest').forEach(e => e.style.display = kind === 'guest' ? '' : 'none');
    f.querySelectorAll('.pk-novalet-staff').forEach(e => e.style.display = kind === 'staff' ? 'none' : '');
    f.querySelectorAll('.pk-valet').forEach(e => e.style.display = valet && kind !== 'staff' ? '' : 'none');
  };
  f.addEventListener('change', sync); sync();
})();
</script>
