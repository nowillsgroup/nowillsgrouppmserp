@extends('layouts.app')
@section('title', 'Drills & Wardens')
@section('crumbs') Health &amp; Safety / Drills &amp; wardens @endsection
@php $canMg = \App\Services\HseService::canManage(auth()->user()); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'hse_drills']) }}"><x-icon name="download"/> Drill log</a>
  @if($canMg)<button class="btn" data-modal="hseWardenModal" data-action="{{ route('hse.wardens.store') }}" data-title="Add warden / first aider" id="hseWardenNew"><x-icon name="users"/> Add warden</button>
  <button class="btn btn-primary" data-modal="hseDrillModal" id="hseDrillNew"><x-icon name="plus"/> Schedule drill</button>@endif
@endsection
@section('content')
<div class="g-3" id="hseShiftCards">
  @foreach($status as $s => $d)@php $c = $cover[$s]; @endphp<div class="card"><div class="card-head"><h3>{{ $d['label'] }}</h3>
    <span class="badge badge-{{ ['ok' => 'green', 'due' => 'amber', 'overdue' => 'red', 'never' => 'red'][$d['state']] }}">{{ ['ok' => 'drill in date', 'due' => 'drill due '.$d['due']?->format('d M'), 'overdue' => 'drill overdue', 'never' => 'never drilled'][$d['state']] }}</span></div>
    <div class="card-body small">
      <div>Last evacuation: <b>{{ $d['last'] ? $d['last']->alarm_at->format('d M Y').' · '.$d['last']->evacLabel().' · '.strtoupper($d['last']->result) : '—' }}</b></div>
      <div>Next due: <b>{{ $d['due']?->format('d M Y') ?? 'now' }}</b>{{ $d['planned'] ? ' · planned '.$d['planned']->scheduled_at->format('d M H:i') : '' }}</div>
      <div class="mt-1">{{ $c['chief'] }} chief · {{ $c['floor'] }} floor wardens · {{ $c['first_aider'] }} first aiders (valid training)</div>
      @foreach($c['gaps'] as $g)<div class="text-red">⚠ {{ ucfirst($g) }}</div>@endforeach
    </div></div>@endforeach
</div>
<div class="card flush"><div class="card-head"><h3>Drills</h3><span class="tiny muted">Every shift evacuates at least every {{ $cfg['drill_days'] }} days · target {{ intdiv($cfg['drill_target'], 60) }} min {{ $cfg['drill_target'] % 60 ? ($cfg['drill_target'] % 60).' s' : '' }}</span></div><div class="card-body table-wrap"><table class="table table-compact" id="hseDrillsTable"><thead><tr><th>Ref</th><th>Type</th><th>Shift</th><th>When</th><th>Evacuation</th><th>Roll call</th><th>Led by</th><th>Result</th></tr></thead><tbody>
  @forelse($drills as $x)<tr class="{{ $x->result === 'fail' ? 'row-red' : ($x->status === 'planned' ? 'row-amber' : '') }}"><td><a href="{{ route('hse.drill', $x) }}"><b>{{ $x->number }}</b></a></td><td class="small">{{ $x->typeLabel() }}</td><td class="small">{{ ucfirst($x->shift) }}</td>
    <td class="small nowrap">{{ ($x->alarm_at ?? $x->scheduled_at)->format('d M Y H:i') }}</td><td class="small">{{ $x->evacLabel() }}</td><td class="small">{{ $x->staff_expected ? $x->staff_accounted.' / '.$x->staff_expected : '' }}</td>
    <td class="small">{{ $x->leader?->name }}</td><td>@if($x->status === 'done')<span class="badge badge-{{ $x->result === 'pass' ? 'green' : 'red' }}">{{ strtoupper($x->result) }}</span>@else<span class="badge badge-{{ $x->status === 'planned' ? 'amber' : 'slate' }}">{{ ucfirst($x->status) }}</span>@endif</td></tr>
  @empty<tr><td colspan="8" class="muted">No drills.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card flush" id="hseCoverage"><div class="card-head"><h3>Fire wardens &amp; first aiders</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="hseWardensTable"><thead><tr><th>Name</th><th>Role</th><th>Shift</th><th>Area</th><th>Trained</th><th>Valid until</th><th></th></tr></thead><tbody>
  @forelse($wardens as $w)<tr class="{{ ! $w->is_active ? 'muted' : (! $w->trainingValid() ? 'row-red' : '') }}"><td>{{ $w->employee->name }}<div class="tiny muted">{{ $w->employee->department?->name }}</div></td><td class="small">{{ $w->roleLabel() }}</td><td class="small">{{ ucfirst($w->shift) }}</td>
    <td class="small">{{ $w->area ?? '—' }}</td><td class="small">{{ $w->trained_on?->format('d M Y') ?? '—' }}</td><td class="small">{{ $w->trained_until?->format('d M Y') ?? '—' }}{{ $w->is_active && ! $w->trainingValid() ? ' · expired' : '' }}{{ $w->is_active ? '' : ' · inactive' }}</td>
    <td>@if($canMg)<button class="btn btn-sm" data-modal="hseWardenModal" data-action="{{ route('hse.wardens.update', $w) }}" data-title="{{ $w->employee->name }}" data-femployee_id="{{ $w->employee_id }}" data-frole="{{ $w->role }}" data-fshift="{{ $w->shift }}" data-farea="{{ $w->area }}" data-ftrained_on="{{ $w->trained_on?->toDateString() }}" data-ftrained_until="{{ $w->trained_until?->toDateString() }}" data-fis_active="{{ $w->is_active ? 1 : 0 }}">Edit</button>@endif</td></tr>
  @empty<tr><td colspan="7" class="muted">No wardens appointed.</td></tr>@endforelse
</tbody></table></div></div>
@if($canMg)
<div class="modal" id="hseDrillModal"><div class="modal-box"><form method="post" action="{{ route('hse.drills.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Schedule a drill</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Type</label><select name="type">@foreach(\App\Models\HseDrill::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Shift</label><select name="shift">@foreach(\App\Models\HseWarden::SHIFTS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>When</label><input type="datetime-local" name="scheduled_at" value="{{ now()->addDays(7)->setTime(10, 0)->format('Y-m-d\TH:i') }}" required></div>
    <div class="field"><label>Target evacuation (seconds)</label><input type="number" name="target_seconds" min="30" max="1800" value="{{ $cfg['drill_target'] }}"></div>
    <div class="field"><label>Assembly point</label><input name="assembly_point" maxlength="80" value="Front car park, by the flagpoles"></div>
    <div class="field"><label>Led by</label><select name="led_by">@foreach($users as $uu)<option value="{{ $uu->id }}" @selected($uu->id === auth()->id())>{{ $uu->name }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Scenario</label><textarea name="scenario" rows="2" placeholder="Smoke in the 2nd-floor linen store; lift out of use"></textarea></div>
    <label class="small span-all"><input type="checkbox" name="announced" value="1" checked> Announced to guests in advance</label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Schedule</button></div>
</form></div></div>
<div class="modal" id="hseWardenModal"><div class="modal-box"><form method="post" action="{{ route('hse.wardens.store') }}" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Warden</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Employee</label><select name="employee_id" required><option value="">—</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}{{ $e->designation ? ' – '.$e->designation : '' }}</option>@endforeach</select></div>
    <div class="field"><label>Role</label><select name="role">@foreach(\App\Models\HseWarden::ROLES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Shift</label><select name="shift">@foreach(\App\Models\HseWarden::SHIFTS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Area</label><input name="area" maxlength="80" placeholder="Floors 1–2, kitchen, whole building"></div>
    <div class="field"><label>Trained on</label><input type="date" name="trained_on"></div>
    <div class="field"><label>Valid until</label><input type="date" name="trained_until"></div>
    <label class="small span-all"><input type="checkbox" name="is_active" value="1" checked> Active</label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endif
@endsection
