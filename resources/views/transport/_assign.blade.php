{{-- v3.3 shared "assign vehicle & driver" dialog. Open with a button carrying data-assign="{{ route('tr.options', $trip) }}". --}}
@if(can_do('transport.book'))
<div class="modal" id="assignModal"><div class="modal-box"><form method="post" id="assignForm" action="#">@csrf
  <div class="modal-head"><h3>Assign car &amp; driver</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small" id="asTitle">…</p>
    <p class="tiny muted" id="asWindow"></p>
    <div class="field"><label for="as_vehicle">Vehicle</label><select name="vehicle_id" id="as_vehicle" required></select><div class="tr-opt-note" id="asVNote"></div></div>
    <div class="field"><label for="as_driver">Driver</label><select name="driver_id" id="as_driver" required></select><div class="tr-opt-note" id="asDNote"></div></div>
    <label class="check"><input type="checkbox" name="notify" value="1" checked> Send the driver the trip sheet and the guest the driver's details</label>
    <label class="check" id="asForceWrap" style="display:none"><input type="checkbox" name="force" value="1" id="asForce"> Assign even though the car / driver is busy then</label>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="assignSave">Assign</button></div>
</form></div></div>
@push('scripts')
<script>
(() => {
  const vSel = document.getElementById('as_vehicle'), dSel = document.getElementById('as_driver');
  let data = null;
  const fill = (sel, list, chosen) => {
    sel.innerHTML = '<option value="">— choose —</option>' + list.map(o => `<option value="${o.id}" ${o.blocked ? 'disabled' : ''} data-busy="${(o.busy || '').replace(/"/g, '&quot;')}">${o.label.replace(/</g, '&lt;')}${o.blocked ? ' – not available' : (o.busy ? ' – busy' : ' – free')}</option>`).join('');
    if (chosen) sel.value = String(chosen);
  };
  const note = () => {
    if (!data) return;
    const v = data.vehicles.find(o => String(o.id) === vSel.value), d = data.drivers.find(o => String(o.id) === dSel.value);
    document.getElementById('asVNote').innerHTML = v ? (v.busy ? '<span class="text-amber">Busy: ' + v.busy + '</span>' : '<span class="text-green">Free for this trip</span>') : '';
    document.getElementById('asDNote').innerHTML = d ? (d.busy ? '<span class="text-amber">Busy: ' + d.busy + '</span>' : '<span class="text-green">Free for this trip</span>') : '';
    const busy = (v && v.busy) || (d && d.busy);
    document.getElementById('asForceWrap').style.display = busy ? '' : 'none';
    if (!busy) document.getElementById('asForce').checked = false;
  };
  vSel.addEventListener('change', note); dSel.addEventListener('change', note);
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-assign]'); if (!b) return;
    e.preventDefault();
    const r = await fetch(b.dataset.assign, {headers: {'Accept': 'application/json'}});
    data = await r.json();
    document.getElementById('assignForm').action = data.action;
    document.getElementById('asTitle').textContent = data.trip.no + ' · ' + data.trip.title;
    document.getElementById('asWindow').textContent = 'Car & driver busy ' + data.trip.window + (data.suggest.vehicle_id ? ' · best free choice pre-selected' : ' · no free car fits – check the board');
    fill(vSel, data.vehicles, data.trip.vehicle_id || data.suggest.vehicle_id);
    fill(dSel, data.drivers, data.trip.driver_id || data.suggest.driver_id);
    note();
    openModal('assignModal');
  });
})();
</script>
@endpush
@endif
