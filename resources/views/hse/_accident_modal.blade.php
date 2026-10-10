<div class="modal" id="hseAccidentModal"><div class="modal-box lg"><form method="post" action="{{ route('hse.accidents.store') }}" data-reset id="hseAccidentForm">@csrf
  <div class="modal-head"><h3 data-title>Report an accident, near miss or dangerous occurrence</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>What happened</label><select name="kind" id="hseKind" required>@foreach(\App\Models\HseAccident::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>When</label><input type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\TH:i') }}" required></div>
    <div class="field"><label>Where</label><input name="location" maxlength="120" required placeholder="Main kitchen – fryer station"></div>
    <div class="field"><label>Room (if in a room)</label><select name="room_id"><option value="">—</option>@foreach($lk['rooms'] as $r)<option value="{{ $r->id }}">{{ $r->room_no }}</option>@endforeach</select></div>
    <div class="field" data-hse="dangerous"><label>Dangerous occurrence</label><select name="occurrence"><option value="">—</option>@foreach(\App\Models\HseAccident::OCCURRENCES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field" data-hse="hurt"><label>Outcome</label><select name="severity">@foreach(\App\Models\HseAccident::SEVERITIES as $k => $l)<option value="{{ $k }}" @selected($k === 'first_aid')>{{ $l }}</option>@endforeach</select></div>
    <div class="field" data-hse="hurt"><label>Person hurt / involved</label><select name="person_type" id="hsePersonType"><option value="">—</option>@foreach(\App\Models\HseAccident::PERSONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field" data-hse="staff"><label>Staff member</label><select name="employee_id"><option value="">—</option>@foreach($lk['employees'] as $e)<option value="{{ $e->id }}">{{ $e->name }}{{ $e->designation ? ' – '.$e->designation : '' }}</option>@endforeach</select></div>
    <div class="field" data-hse="guest"><label>In-house guest</label><select name="stay_id"><option value="">— not in house / enter the name —</option>@foreach($lk['stays'] as $s)<option value="{{ $s->id }}">{{ $s->room?->room_no }} · {{ $s->guest?->full_name }}</option>@endforeach</select></div>
    <div class="field" data-hse="name"><label>Name</label><input name="person_name" maxlength="120" placeholder="contractor / visitor / guest name"></div>
    <div class="field" data-hse="hurt"><label>Injury</label><select name="injury_type"><option value="">—</option>@foreach(\App\Models\HseAccident::INJURIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field" data-hse="hurt"><label>Part of the body</label><select name="body_part"><option value="">—</option>@foreach(\App\Models\HseAccident::BODY as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field" data-hse="hurt"><label>Days lost so far (lost time)</label><input type="number" name="lost_days" min="0" max="3650" value="0"></div>
    <div class="field" data-hse="hurt"><label>Hospital / clinic</label><input name="hospital" maxlength="120"></div>
    <div class="field span-all"><label>What happened</label><textarea name="description" rows="3" required minlength="10" placeholder="Sequence of events, what the person was doing, equipment involved"></textarea></div>
    <div class="field span-all" data-hse="hurt"><label>First aid / immediate action</label><input name="first_aid" maxlength="255"></div>
    <div class="field"><label>Witnesses</label><input name="witnesses" maxlength="255"></div>
    <div class="field"><label>Linked security incident</label><select name="security_incident_id"><option value="">—</option>@foreach($lk['incidents'] as $i)<option value="{{ $i->id }}">{{ $i->incident_no }} · {{ $i->title }}</option>@endforeach</select></div>
  </div>
  <p class="tiny muted mt-1">Deaths, injuries keeping staff or contractors off full-wage work for more than 3 days, and every fire, explosion, collapse or lift / hoist failure must be notified forthwith to the Factories Inspectorate (Factories, Offices and Shops Act 1970, ss. 10–11).</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Record</button></div>
</form></div></div>
<script>
(() => {
  const f = document.getElementById('hseAccidentForm'); if (!f) return;
  const sync = () => {
    const kind = f.kind.value, pt = f.person_type.value, hurt = kind !== 'near_miss';
    f.querySelectorAll('[data-hse]').forEach(el => {
      const t = el.dataset.hse;
      el.style.display = (t === 'dangerous' && kind === 'dangerous') || (t === 'hurt' && hurt) || (t === 'staff' && hurt && pt === 'staff') || (t === 'guest' && hurt && pt === 'guest')
        || (t === 'name' && hurt && ['guest', 'contractor', 'visitor'].includes(pt)) ? '' : 'none';
    });
  };
  f.addEventListener('change', sync); f.addEventListener('reset', () => setTimeout(sync)); sync();
})();
</script>
