<div class="modal" id="hseEquipModal"><div class="modal-box lg"><form method="post" action="{{ route('hse.equipment.store') }}" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Fire equipment</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Type</label><select name="type" required>@foreach(\App\Models\HseEquipment::TYPES as $k => $t)<option value="{{ $k }}">{{ $t[0] }}</option>@endforeach</select></div>
    <div class="field"><label>Code</label><input name="code" maxlength="20" placeholder="blank = next number" style="text-transform:uppercase"></div>
    <div class="field"><label>Location</label><input name="location" maxlength="120" required placeholder="2nd floor corridor by the lift"></div>
    <div class="field"><label>Floor</label><input name="floor" maxlength="10" placeholder="G, 1, 2, B1, Roof"></div>
    <div class="field"><label>Room</label><select name="room_id"><option value="">—</option>@foreach($rooms as $r)<option value="{{ $r->id }}">{{ $r->room_no }}</option>@endforeach</select></div>
    <div class="field"><label>Size / capacity</label><input name="capacity" maxlength="30" placeholder="9 kg, 30 m, 8-zone"></div>
    <div class="field"><label>GNFS tag</label><input name="gnfs_tag" maxlength="40" style="text-transform:uppercase"></div>
    <div class="field"><label>Serial</label><input name="serial" maxlength="60"></div>
    <div class="field"><label>Installed</label><input type="date" name="installed_on"></div>
    <div class="field"><label>Expires</label><input type="date" name="expires_on"></div>
    <div class="field"><label>Check every (days)</label><input type="number" name="check_days" min="1" max="365" placeholder="type default"></div>
    <div class="field"><label>Service every (days, 0 = none)</label><input type="number" name="service_days" min="0" max="1825" placeholder="type default"></div>
    <div class="field"><label>Status</label><select name="status">@foreach(\App\Models\HseEquipment::STATUSES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="255"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
