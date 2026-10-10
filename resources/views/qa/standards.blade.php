@extends('layouts.app')
@section('title', 'Brand Standards')
@section('crumbs') Quality Assurance / Standards @endsection
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'qa_standard_failures']) }}"><x-icon name="download"/> Failure analysis</a>
  @if($canManage)<button class="btn btn-primary" data-modal="qaStdModal" data-title="New standard" data-fid="" data-fis_active="1" id="newStdBtn"><x-icon name="plus"/> New standard</button>@endif
@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <select name="dept" aria-label="Department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}" @selected((int) request('dept') === $d->id)>{{ $d->name }}</option>@endforeach</select>
  <label class="check"><input type="checkbox" name="critical" value="1" @checked(request('critical') === '1')> Critical only</label>
  <label class="check"><input type="checkbox" name="show" value="all" @checked(request('show') === 'all')> Include inactive</label>
  <input name="q" value="{{ request('q') }}" placeholder="Code, area or text" aria-label="Search"><button class="btn btn-sm">Filter</button>
  <span class="tiny muted">{{ $standards->count() }} standards · weight 1–5 points · a critical failure fails the whole audit · fail rate over the last 180 days</span>
</form></div></div>
@foreach($standards->groupBy('area') as $area => $rows)
<div class="card flush mb-1"><div class="card-head"><h3>{{ $area }}</h3><span class="tiny muted">{{ $rows->count() }}</span></div><div class="card-body table-wrap"><table class="table table-compact std-table"><thead><tr><th style="width:8%">Code</th><th>Standard</th><th>Department</th><th class="num">Weight</th><th>SOP</th><th class="num">Checklists</th><th class="num">Fail rate</th>@if($canManage)<th></th>@endif</tr></thead><tbody>
  @foreach($rows as $s)@php $f = $fail[$s->id] ?? null; @endphp<tr data-std="{{ $s->code }}" class="{{ $s->is_active ? '' : 'muted' }}">
    <td><b>{{ $s->code }}</b></td><td>{{ $s->title }}@if($s->is_critical) <span class="badge badge-red">critical</span>@endif @unless($s->is_active)<span class="badge badge-slate">inactive</span>@endunless @if($s->description)<div class="tiny muted">{{ $s->description }}</div>@endif</td>
    <td class="small">{{ $s->department?->name ?? '—' }}</td><td class="num">{{ $s->weight }}</td><td class="small">{{ $s->sop?->code ?? '' }}</td><td class="num">{{ $s->templates_count }}</td>
    <td class="num {{ $f && $f['rate'] >= 30 ? 'text-red' : '' }}">{{ $f ? $f['rate'].'%' : '' }}<div class="tiny muted">{{ $f ? $f['fails'].' / '.$f['checks'] : '' }}</div></td>
    @if($canManage)<td><button class="btn btn-sm btn-ghost" data-modal="qaStdModal" data-title="Edit {{ $s->code }}" data-fid="{{ $s->id }}" data-fcode="{{ $s->code }}" data-ftitle="{{ $s->title }}" data-farea="{{ $s->area }}" data-fweight="{{ $s->weight }}" data-fdepartment_id="{{ $s->department_id }}" data-fsop_document_id="{{ $s->sop_document_id }}" data-fdescription="{{ $s->description }}" data-fis_critical="{{ $s->is_critical ? 1 : 0 }}" data-fis_active="{{ $s->is_active ? 1 : 0 }}"><x-icon name="edit"/></button></td>@endif
  </tr>@endforeach
</tbody></table></div></div>
@endforeach
@if($standards->isEmpty())<div class="card"><div class="card-body muted">No standards match.</div></div>@endif
@if($canManage)
<div class="modal" id="qaStdModal"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ route('qa.standards.save') }}" data-reset>@csrf<input type="hidden" name="id">
  <div class="modal-head"><h3 data-title>Standard</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Code</label><input name="code" required maxlength="20" placeholder="FO-07"></div>
    <div class="field"><label>Area</label><input name="area" required maxlength="60" list="qaAreas" placeholder="Arrival &amp; check-in"><datalist id="qaAreas">@foreach($standards->pluck('area')->unique() as $ar)<option value="{{ $ar }}">@endforeach</datalist></div>
    <div class="field span-all"><label>Standard</label><input name="title" required maxlength="200" placeholder="Guest greeted within 30 seconds of arrival at the desk"></div>
    <div class="field span-all"><label>How to check / what good looks like</label><textarea name="description" rows="2"></textarea></div>
    <div class="field"><label>Department</label><select name="department_id"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
    <div class="field"><label>Weight (points)</label><select name="weight">@foreach([1, 2, 3, 4, 5] as $w)<option value="{{ $w }}" @selected($w === 2)>{{ $w }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Linked SOP</label><select name="sop_document_id"><option value="">—</option>@foreach($sops as $sop)<option value="{{ $sop->id }}">{{ $sop->code }} · {{ $sop->title }}</option>@endforeach</select></div>
    <div class="field"><label class="check"><input type="checkbox" name="is_critical" value="1"> Critical – one failure fails the audit</label></div>
    <div class="field"><label class="check"><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save standard</button></div>
</form></div></div>
@endif
@endsection
