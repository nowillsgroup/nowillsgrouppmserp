{{-- plan / log an activity, complete a planned one, cancel one. Expects $accounts, $sellers, $contacts, $openOpps; optional $presetCompany, $presetOpp --}}
@php $A = \App\Models\SalesActivity::class; $pc = $presetCompany ?? null; $po = $presetOpp ?? null; $fu = bdate()->copy()->addDays($cfg['followup_days'] ?? 7)->toDateString(); @endphp
<div class="modal" id="actModal"><div class="modal-box" style="max-width:46rem"><form method="post" action="{{ route('sales.activities.store') }}" id="actForm">@csrf
  <div class="modal-head"><h3 data-title>Plan an activity</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Activity</label><select name="type">@foreach($A::TYPES as $k => [$l])<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Status</label><select name="status" id="actStatus"><option value="planned">Planned (to do)</option><option value="done">Done – log it now</option></select></div>
    <div class="field span-all"><label>Subject</label><input name="subject" maxlength="190" required placeholder="Quarterly review with the travel desk"></div>
    <div class="field"><label>Account</label><select name="company_id" id="actCompany"><option value="">—</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected($pc == $a->id)>{{ $a->name }}</option>@endforeach</select></div>
    <div class="field"><label>Contact</label><select name="sales_contact_id" id="actContact"><option value="">—</option>@foreach($contacts as $x)<option value="{{ $x->id }}" data-company="{{ $x->company_id }}">{{ $x->name }}</option>@endforeach</select></div>
    <div class="field"><label>Opportunity</label><select name="sales_opportunity_id" id="actOpp"><option value="">—</option>@foreach($openOpps as $x)<option value="{{ $x->id }}" data-company="{{ $x->company_id }}" @selected($po == $x->id)>{{ $x->number }} {{ \Illuminate\Support\Str::limit($x->name, 40) }}</option>@endforeach</select></div>
    <div class="field"><label>Owner</label><select name="owner_id">@foreach($sellers as $u)<option value="{{ $u->id }}" @selected(auth()->id() === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div class="field"><label>Date</label><input type="date" name="date" required value="{{ bdate()->toDateString() }}"></div>
    <div class="field"><label>Time</label><input type="time" name="time" value="10:00"></div>
    <div class="field"><label>Duration (min)</label><input type="number" name="duration_min" min="0" max="1440" value="30"></div>
    <div class="field"><label>Cost (GH₵) <span class="tiny muted">entertainment, travel</span></label><input type="number" name="cost" min="0" step="0.01" value="0"></div>
    <div class="field span-all" id="actOutcomeWrap"><label>Outcome <span class="tiny muted">required when logging a completed activity</span></label><textarea name="outcome" rows="2"></textarea></div>
    <fieldset class="span-all form-grid sa-follow"><legend class="small bold">Next step (optional)</legend>
      <div class="field"><label>Follow-up</label><select name="follow_type"><option value="">None</option>@foreach($A::TYPES as $k => [$l])<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
      <div class="field"><label>On</label><input type="date" name="follow_date" value="{{ $fu }}"></div>
      <div class="field span-all"><label>Follow-up subject <span class="tiny muted">blank = “Follow up: …”</span></label><input name="follow_subject" maxlength="190"></div>
    </fieldset>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save activity</button></div>
</form></div></div>

<div class="modal" id="doneModal"><div class="modal-box" style="max-width:40rem"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Complete activity</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Outcome</label><textarea name="outcome" rows="3" required placeholder="What happened, what was agreed, next steps"></textarea></div>
    <div class="field"><label>Cost (GH₵)</label><input type="number" name="cost" min="0" step="0.01"></div>
    <div class="field"></div>
    <div class="field"><label>Follow-up</label><select name="follow_type"><option value="">None</option>@foreach($A::TYPES as $k => [$l])<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>On</label><input type="date" name="follow_date" value="{{ $fu }}"></div>
    <div class="field span-all"><label>Follow-up subject</label><input name="follow_subject" maxlength="190"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Mark done</button></div>
</form></div></div>

<div class="modal" id="cancelActModal"><div class="modal-box" style="max-width:32rem"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Cancel activity</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="reason" maxlength="250" required placeholder="Client postponed / no longer needed"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel activity</button></div>
</form></div></div>
@push('scripts')
<script>
(function () {
  const co = document.getElementById('actCompany'), ct = document.getElementById('actContact'), op = document.getElementById('actOpp');
  if (!co) return;
  const sync = () => { [ct, op].forEach(s => { [...s.options].forEach(o => { if (o.dataset.company) { o.hidden = co.value !== '' && o.dataset.company !== co.value; if (o.hidden && o.selected) s.value = ''; } }); }); };
  co.addEventListener('change', sync); sync();
  op.addEventListener('change', () => { const o = op.selectedOptions[0]; if (o && o.dataset.company && co.value === '') { co.value = o.dataset.company; sync(); } });
})();
</script>
@endpush
