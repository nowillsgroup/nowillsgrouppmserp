@extends('layouts.app')
@section('title', 'QA Checklists')
@section('crumbs') Quality Assurance / Checklists @endsection
@php $T = \App\Models\QaTemplate::class; $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%'; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'qa_template_compliance']) }}"><x-icon name="download"/> Compliance</a>
  @if($canManage)<button class="btn btn-primary" id="newTplBtn" onclick="qaTpl(null)"><x-icon name="plus"/> New checklist</button>@endif
@endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="tplTable"><thead><tr><th>Checklist</th><th>Type</th><th>Department</th><th class="num">Standards</th><th class="num">Points</th><th>Frequency</th><th class="num">Pass mark</th><th class="num">Audits</th><th class="num">Avg score</th><th>Next due</th><th></th></tr></thead><tbody>
  @forelse($list as $t)@php $d = $due[$t->id] ?? null; $sc = $scores[$t->id] ?? null; @endphp
  <tr data-tpl="{{ $t->id }}" class="{{ $t->is_active ? '' : 'muted' }} {{ $d && in_array($d['state'], ['due', 'late'], true) ? 'row-amber' : '' }}">
    <td><b>{{ $t->name }}</b>@unless($t->is_active) <span class="badge badge-slate">inactive</span>@endunless @if($t->description)<div class="tiny muted">{{ \Illuminate\Support\Str::limit($t->description, 90) }}</div>@endif</td>
    <td class="small">{{ $t->typeLabel() }}</td><td class="small">{{ $t->department?->name ?? 'Whole hotel' }}</td>
    <td class="num">{{ $t->standards->count() }}@if($c = $t->standards->where('is_critical', true)->count())<div class="tiny text-red">{{ $c }} critical</div>@endif</td><td class="num">{{ $t->standards->sum('weight') }}</td>
    <td class="small">{{ $t->frequency_days ? 'every '.$t->frequency_days.' days' : 'on demand' }}</td><td class="num">{{ $fmt($t->pass_mark) }}</td>
    <td class="num">{{ $t->audits_count }}</td><td class="num">{{ $sc ? $fmt($sc->avg) : '—' }}@if($sc)<div class="tiny muted">{{ (int) $sc->passed }}/{{ $sc->n }} passed</div>@endif</td>
    <td class="small nowrap">@if($d){{ $d['pending'] ? $d['pending']->scheduled_for->format('d M') : $d['next']->format('d M') }} <span class="badge badge-{{ ['booked' => 'blue', 'late' => 'red', 'due' => 'amber', 'ok' => 'green'][$d['state']] }}">{{ ['booked' => 'booked', 'late' => 'late', 'due' => 'due', 'ok' => 'on track'][$d['state']] }}</span>@else — @endif</td>
    <td class="nowrap">@if($canAudit && $t->is_active)<button class="btn btn-sm" data-modal="qaScheduleModal" data-fqa_template_id="{{ $t->id }}" data-title="Schedule – {{ $t->name }}">Schedule</button>@endif
      @if($canManage)@php $tj = json_encode(['id' => $t->id, 'name' => $t->name, 'type' => $t->type, 'department_id' => $t->department_id, 'frequency_days' => $t->frequency_days, 'pass_mark' => (float) $t->pass_mark, 'description' => $t->description, 'is_active' => $t->is_active, 'std' => $t->standards->pluck('id')]); @endphp<button class="btn btn-sm btn-ghost" data-tpl-json="{{ $tj }}" onclick="qaTpl(JSON.parse(this.dataset.tplJson))" title="Edit"><x-icon name="edit"/></button>@endif</td></tr>
  @empty<tr><td colspan="11" class="muted">No checklists yet.</td></tr>@endforelse
</tbody></table></div></div>
@if($canAudit)@include('qa._schedule_modal')@endif
@if($canManage)
<div class="modal" id="qaTplModal"><div class="modal-box" style="max-width:48rem"><form method="post" action="{{ route('qa.templates.save') }}" id="qaTplForm">@csrf<input type="hidden" name="id">
  <div class="modal-head"><h3 id="qaTplTitle">Checklist</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Name</label><input name="name" required maxlength="120"></div>
    <div class="field"><label>Type</label><select name="type">@foreach($T::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Department</label><select name="department_id"><option value="">Whole hotel</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
    <div class="field"><label>Every (days)</label><input type="number" name="frequency_days" min="1" max="365" placeholder="blank = on demand"></div>
    <div class="field"><label>Pass mark %</label><input type="number" name="pass_mark" min="50" max="100" step="0.5" placeholder="{{ $cfg['pass_mark'] }}"></div>
    <div class="field span-all"><label>Description</label><input name="description" maxlength="500"></div>
    <div class="field span-all"><label>Standards <span class="tiny muted" id="qaTplCount"></span></label><div class="qa-std-list">
      @foreach($standardList->groupBy('area') as $area => $ss)<div class="grp">{{ $area }}</div>@foreach($ss as $s)<label><input type="checkbox" name="standard_ids[]" value="{{ $s->id }}"> <span><b>{{ $s->code }}</b> {{ $s->title }} <span class="muted">· {{ $s->weight }}</span>@if($s->is_critical) <span class="badge badge-red">critical</span>@endif</span></label>@endforeach @endforeach
    </div></div>
    <div class="field"><label class="check"><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save checklist</button></div>
</form></div></div>
<script>
function qaTpl(t) {
  const f = document.getElementById('qaTplForm'); f.reset(); t = t || {};
  ['id', 'name', 'type', 'department_id', 'frequency_days', 'pass_mark', 'description'].forEach(k => { f.elements[k].value = t[k] ?? (k === 'type' ? 'internal' : ''); });
  f.elements['is_active'].checked = t.is_active ?? true;
  const ids = (t.std || []).map(String); f.querySelectorAll('input[name="standard_ids[]"]').forEach(c => c.checked = ids.includes(c.value));
  document.getElementById('qaTplTitle').textContent = t.id ? 'Edit checklist' : 'New checklist'; count(); openModal('qaTplModal');
}
function count() { document.getElementById('qaTplCount').textContent = '(' + document.querySelectorAll('#qaTplForm input[name="standard_ids[]"]:checked').length + ' selected)'; }
document.getElementById('qaTplForm').addEventListener('change', count);
</script>
@endif
@endsection
