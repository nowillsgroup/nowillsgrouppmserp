@php $fixedEmployee = $fixedEmployee ?? null; $allCourses = $courses ?? \App\Models\TrainingCourse::where('is_active', true)->orderBy('name')->get(); @endphp
@if(can_do('learning.train'))
<div class="modal" id="extModal"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ route('learning.records.store') }}">@csrf
  <div class="modal-head"><h3>Add a training record</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small muted">Training done outside a scheduled session – with an external body or a previous employer.</p><div class="form-grid">
    <div class="field"><label>Employee</label>@if($fixedEmployee)<input type="hidden" name="employee_id" value="{{ $fixedEmployee->id }}"><input value="{{ $fixedEmployee->name }}" disabled>@else<select name="employee_id" required id="extEmployee"><option value="">Choose…</option>@foreach($employees as $x)<option value="{{ $x->id }}">{{ $x->name }} · {{ $x->designation }}</option>@endforeach</select>@endif</div>
    <div class="field"><label>Course</label><select name="training_course_id" required id="extCourse">@foreach($allCourses as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
    <div class="field"><label>Completed on</label><input type="date" name="completed_on" required max="{{ bdate()->toDateString() }}" value="{{ bdate()->toDateString() }}" id="extDate"></div>
    <div class="field"><label>Result</label><select name="status" id="extStatus"><option value="passed">Passed</option><option value="failed">Failed</option></select></div>
    <div class="field"><label>Score % <span class="tiny muted">(optional)</span></label><input type="number" name="score" min="0" max="100" step="0.1"></div>
    <div class="field"><label>Expires <span class="tiny muted">(blank = course validity)</span></label><input type="date" name="expires_on" id="extExpires"></div>
    <div class="field"><label>Certificate no.</label><input name="certificate_no" maxlength="40" id="extCert"></div>
    <div class="field"><label>Provider</label><input name="provider" maxlength="150" id="extProvider"></div>
    <div class="field"><label>Cost (GH₵)</label><input type="number" name="cost" min="0" step="0.01"></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="255"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save record</button></div>
</form></div></div>
@endif
@if(can_do('learning.manage'))
<div class="modal" id="exemptModal"><div class="modal-box" style="max-width:34rem"><form method="post" action="{{ route('learning.exempt') }}">@csrf
  <div class="modal-head"><h3>Exempt from a course</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Employee</label>@if($fixedEmployee)<input type="hidden" name="employee_id" value="{{ $fixedEmployee->id }}"><input value="{{ $fixedEmployee->name }}" disabled>@else<select name="employee_id" required>@foreach($employees as $x)<option value="{{ $x->id }}">{{ $x->name }}</option>@endforeach</select>@endif</div>
    <div class="field"><label>Course</label><select name="training_course_id" required>@foreach($allCourses as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
    <div class="field"><label>Until <span class="tiny muted">(blank = permanent)</span></label><input type="date" name="until" min="{{ bdate()->toDateString() }}"></div>
    <div class="field span-all"><label>Reason</label><input name="reason" required maxlength="255" placeholder="e.g. registered nurse – holds a current clinical first-aid qualification"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Record exemption</button></div>
</form></div></div>
@endif
