@php $T = \App\Models\QaTemplate::class; @endphp
<div class="modal" id="qaScheduleModal"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ route('qa.audits.schedule') }}">@csrf
  <div class="modal-head"><h3 data-title>Schedule audit</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Checklist</label><select name="qa_template_id" required>@foreach($templates as $t)<option value="{{ $t->id }}">{{ $t->name }} · {{ $t->typeLabel() }} · {{ $t->standards_count }} standards · pass {{ rtrim(rtrim(number_format($t->pass_mark, 1), '0'), '.') }}%</option>@endforeach</select></div>
    <div class="field"><label>Audit / visit date</label><input type="date" name="scheduled_for" required value="{{ bdate()->toDateString() }}"><span class="tiny muted">mystery guest &amp; brand visits may be entered up to 90 days back</span></div>
    <div class="field"><label>Auditor (staff)</label><select name="auditor_id"><option value="">Me</option>@foreach($auditors as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
    <div class="field span-all"><label>External auditor</label><input name="external_auditor" maxlength="120" placeholder="Mystery guest / brand auditor – only for mystery &amp; brand checklists"></div>
    <div class="field span-all"><label>Title</label><input name="title" maxlength="150" placeholder="blank = checklist name, e.g. Guest room audit – 2nd floor"></div>
  </div><p class="tiny muted mt-1">The checklist is copied into the audit now – later changes to the standards library do not alter it.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Schedule</button></div>
</form></div></div>
