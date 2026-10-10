@php $X = \App\Models\QaAction::class; @endphp
<div class="modal" id="qaActionModal"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ route('qa.actions.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Raise corrective action</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>What must be corrected</label><input name="title" required maxlength="200" placeholder="e.g. Lobby carpet stained by the lifts"></div>
    <div class="field"><label>Department</label><select name="department_id"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
    <div class="field"><label>Standard</label><select name="qa_standard_id"><option value="">—</option>@foreach($standardList as $s)<option value="{{ $s->id }}">{{ $s->code }} {{ \Illuminate\Support\Str::limit($s->title, 50) }}</option>@endforeach</select></div>
    <div class="field"><label>Priority</label><select name="priority">@foreach($X::PRIORITIES as $k => $p)<option value="{{ $k }}" @selected($k === 'normal')>{{ $p[0] }}</option>@endforeach</select></div>
    <div class="field"><label>Due</label><input type="date" name="due_date" min="{{ bdate()->toDateString() }}"><span class="tiny muted">blank = by priority (settings)</span></div>
    <div class="field span-all"><label>Owner</label><select name="owner_id"><option value="">Unassigned</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Root cause</label><textarea name="root_cause" rows="2"></textarea></div>
    <div class="field span-all"><label>Action plan</label><textarea name="action_plan" rows="2"></textarea></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Raise action</button></div>
</form></div></div>
