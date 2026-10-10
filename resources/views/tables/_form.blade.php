{{-- v3.2 new table booking – $outlet, $inHouse, $tables, optional $back ('floor') --}}
@php
  $secs = $tables->pluck('section')->filter()->unique()->values();
  $defDate = old('date', isset($date) ? $date->toDateString() : now()->toDateString());
@endphp
<div class="modal" id="bookModal"><div class="modal-box" style="max-width:820px"><form method="post" action="{{ route('tb.store') }}" id="bookForm">@csrf
  <input type="hidden" name="outlet_id" value="{{ $outlet->id }}">@if(!empty($back))<input type="hidden" name="back" value="{{ $back }}">@endif
  <div class="modal-head"><h3>New table booking · {{ $outlet->name }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="form-grid">
      <x-field name="date" label="Date" type="date" :value="$defDate" required/>
      <x-field name="party_size" label="Guests" type="number" :value="old('party_size', 2)" required/>
      <x-field name="time" label="Time" type="time" :value="old('time')" required hint="pick a time below or type one"/>
      <x-field name="source" label="Booked by" type="select" :options="['phone' => 'Phone', 'walkin' => 'Walk-in', 'email' => 'E-mail', 'concierge' => 'Front desk / concierge', 'staff' => 'Staff']" :placeholder="false" :value="old('source', 'phone')"/>
    </div>
    <div class="field"><label>Available times <span class="muted small" id="slotInfo"></span></label><div id="slotBox" class="muted small">Choose a date and party size…</div></div>
    <div class="form-grid">
      <x-field name="phone" label="Phone" :value="old('phone')" placeholder="+91 98…" hint="returning guests are recognised"/>
      <x-field name="name" label="Guest name" :value="old('name')"/>
      <x-field name="email" label="E-mail" type="email" :value="old('email')" hint="confirmation goes here if given"/>
      <div class="field"><label for="f_reservation_room_id">In-house guest</label><select name="reservation_room_id" id="f_reservation_room_id"><option value="">— not staying —</option>@foreach($inHouse as $s)<option value="{{ $s->id }}" data-name="{{ $s->reservation?->guest ? trim($s->reservation->guest->first_name.' '.$s->reservation->guest->last_name) : '' }}" @selected(old('reservation_room_id') == $s->id)>Room {{ $s->room?->room_no }} – {{ $s->reservation?->guest?->full_name }}</option>@endforeach</select></div>
    </div>
    <div id="guestHint" class="alert alert-info" style="display:none;margin:.2rem 0 .6rem"></div>
    <div class="form-grid">
      <x-field name="occasion" label="Occasion" type="select" :options="\App\Models\TableReservation::OCCASIONS" :value="old('occasion')" placeholder="—"/>
      <x-field name="section_pref" label="Seating preference" type="select" :options="$secs->mapWithKeys(fn ($s) => [$s => $s])->all()" :value="old('section_pref')" placeholder="No preference"/>
    </div>
    <div class="field"><label>Requests</label><div class="tbl-picks">@foreach(\App\Models\TableReservation::TAGS as $k => $l)<label><input type="checkbox" name="tags[]" value="{{ $k }}" @checked(in_array($k, (array) old('tags', [])))><span>{{ $l }}</span></label>@endforeach</div></div>
    <x-field name="notes" label="Notes" type="textarea" :value="old('notes')" placeholder="Allergies, cake to bring out, seating…"/>
    <details class="mt-1" id="tablePick"><summary class="small bold">Choose tables yourself (otherwise the best table is assigned)</summary>
      <div class="tbl-picks mt-1">@foreach($tables as $t)<label title="{{ $t->section }}"><input type="checkbox" name="table_ids[]" value="{{ $t->id }}"><span>{{ $t->table_no }} · {{ $t->capacity }}</span></label>@endforeach</div>
    </details>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveBooking"><x-icon name="check"/> Book table</button></div>
</form></div></div>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const f = document.getElementById('bookForm'); if (!f) return;
  const date = f.querySelector('[name=date]'), party = f.querySelector('[name=party_size]'), time = f.querySelector('[name=time]'), box = document.getElementById('slotBox'), info = document.getElementById('slotInfo');
  const why = {past: 'already passed', full: 'no table free', notice: 'too soon', pacing: 'kitchen pacing'};
  let seq = 0;
  async function load() {
    if (!date.value || !party.value) return;
    const my = ++seq; box.textContent = 'Checking…';
    try {
      const r = await fetch(@json(route('tb.availability')) + '?' + new URLSearchParams({outlet_id: @json($outlet->id), date: date.value, party_size: party.value}), {headers: {Accept: 'application/json'}});
      const d = await r.json(); if (my !== seq) return;
      info.textContent = '· about ' + d.duration + ' min at the table';
      if (!d.slots.length) { box.innerHTML = '<span class="muted">No service periods on this day – type a time to book anyway.</span>'; return; }
      const groups = {}; d.slots.forEach(s => (groups[s.period] = groups[s.period] || []).push(s));
      box.innerHTML = '';
      Object.entries(groups).forEach(([p, list]) => {
        const g = document.createElement('div'); g.className = 'slot-group'; g.innerHTML = '<h4>' + p + '</h4>';
        const pills = document.createElement('div'); pills.className = 'slot-pills';
        list.forEach(s => {
          const b = document.createElement('button'); b.type = 'button'; b.textContent = s.time; b.dataset.time = s.time;
          if (s.ok) { b.title = 'Table ' + s.tables.join(' + '); b.onclick = () => { time.value = s.time; pills.closest('#slotBox').querySelectorAll('button').forEach(x => x.classList.remove('on')); b.classList.add('on'); }; }
          else { b.className = 'off'; b.disabled = true; b.title = why[s.reason] || s.reason; }
          if (s.time === time.value) b.classList.add('on');
          pills.appendChild(b);
        });
        g.appendChild(pills); box.appendChild(g);
      });
    } catch (e) { box.textContent = 'Could not load times – type a time.'; }
  }
  date.addEventListener('change', load); party.addEventListener('change', load); party.addEventListener('input', () => { clearTimeout(party._t); party._t = setTimeout(load, 400); });
  document.querySelectorAll('[data-modal=bookModal]').forEach(b => b.addEventListener('click', load));
  const phone = f.querySelector('[name=phone]'), name = f.querySelector('[name=name]'), email = f.querySelector('[name=email]'), hint = document.getElementById('guestHint');
  phone.addEventListener('change', async () => {
    hint.style.display = 'none'; if (phone.value.trim().length < 6) return;
    const d = await (await fetch(@json(route('tb.lookup')) + '?' + new URLSearchParams({phone: phone.value.trim()}), {headers: {Accept: 'application/json'}})).json();
    if (!d.found && !d.no_shows) return;
    if (d.name && !name.value) name.value = d.name; if (d.email && !email.value) email.value = d.email;
    let t = '<b>' + (d.name || 'Known number') + '</b>' + (d.vip ? ' · VIP' : '') + ' – ' + d.visits + ' visit(s)' + (d.last ? ', last ' + d.last.substring(0, 10) : '');
    if (d.no_shows) t += ' · <b class="text-red">' + d.no_shows + ' no-show(s)</b>';
    if (d.cancelled) t += ' · ' + d.cancelled + ' cancelled';
    hint.innerHTML = t; hint.style.display = 'flex';
  });
  const room = f.querySelector('[name=reservation_room_id]');
  room.addEventListener('change', () => { const o = room.selectedOptions[0]; if (o && o.dataset.name && !name.value) name.value = o.dataset.name; });
  @if($errors->any() && old('outlet_id')) openModal('bookModal'); load(); @endif
});
</script>
@endpush
