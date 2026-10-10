@extends('layouts.app')
@section('title', 'Housekeeping Tasks')
@section('actions')
<form class="filters"><div class="field"><input type="date" name="date" value="{{ $date }}" data-autosubmit></div>
  <div class="field"><select name="status" data-autosubmit><option value="">All tasks</option>@foreach(['pending'=>'Pending','in_progress'=>'In progress','completed'=>'Completed','inspect'=>'Waiting for inspection'] as $k=>$v)<option value="{{ $k }}" @selected(request('status')===$k)>{{ $v }}</option>@endforeach</select></div></form>
@if(can_do('housekeeping.attendant') || can_do('housekeeping.manage'))<a class="btn" href="{{ route('offline.housekeeping') }}"><x-icon name="wifi"/> Offline workspace</a> <a class="btn" href="{{ route('hk.my') }}"><x-icon name="phone"/> Mobile view</a>@endif
@if(can_do('housekeeping.manage'))
<form method="post" action="{{ route('housekeeping.tasks.generate') }}">@csrf<input type="hidden" name="date" value="{{ $date }}"><button class="btn" title="Creates tasks for dirty and occupied rooms and balances them across attendants by credits"><x-icon name="refresh"/> Generate &amp; auto-assign</button></form>
<button class="btn btn-primary" data-modal="taskModal"><x-icon name="plus"/> New Task</button>@endif
@endsection
@section('content')
@php $awaiting = $tasks->filter->awaitingInspection(); @endphp
<div class="card mb-1"><div class="card-head"><h3>Attendant workload</h3><span class="small text-2">Credits: departure {{ setting('hk_credits_departure', 2) }} · stay-over {{ setting('hk_credits_stayover', 1) }} · deep clean {{ setting('hk_credits_deep', 3) }}</span></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Attendant</th><th class="num">Rooms</th><th class="num">Credits</th><th class="num">Done</th><th class="num">In progress</th><th class="num">DND</th><th class="num">Re-cleans</th><th style="width:25%">Progress</th></tr></thead><tbody>
  @forelse($workload as $w)
    @php $n = $w->tasks->count(); $d = $w->tasks->where('status','completed')->count(); @endphp
    <tr><td>{{ $w->emp?->name ?? 'Unassigned' }}</td><td class="num">{{ $n }}</td><td class="num"><b>{{ $w->tasks->sum('credits') }}</b></td><td class="num">{{ $d }}</td>
      <td class="num">{{ $w->tasks->where('status','in_progress')->count() }}</td><td class="num">{{ $w->tasks->where('status','pending')->whereNotNull('dnd_at')->count() }}</td>
      <td class="num">{{ $w->tasks->sum('reclean_count') }}</td>
      <td><div style="background:var(--c-slate-50);border-radius:99px;height:8px;overflow:hidden"><div style="height:100%;width:{{ $n ? round($d / $n * 100) : 0 }}%;background:var(--c-green)"></div></div></td></tr>
  @empty <tr><td colspan="8" class="muted">No housekeeping attendants. Add employees to a department named “Housekeeping”.</td></tr> @endforelse
  </tbody></table></div></div>

@if(can_do('housekeeping.manage') && $awaiting->isNotEmpty())
<div class="card mb-1" id="inspection"><div class="card-head"><h3>Waiting for inspection</h3><span class="badge badge-amber">{{ $awaiting->count() }}</span></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Room</th><th>Task</th><th>Cleaned by</th><th>Completed</th><th class="num">Minutes</th><th>Checklist</th><th></th></tr></thead><tbody>
  @foreach($awaiting as $t)
    <tr><td><b>{{ $t->room->room_no }}</b></td><td>{{ \App\Models\HousekeepingTask::TYPES[$t->task_type] ?? $t->task_type }}{{ $t->reclean_count ? ' · re-clean #'.$t->reclean_count : '' }}</td><td>{{ $t->employee?->name ?? '—' }}</td>
      <td>{{ $t->completed_at?->format('H:i') }}</td><td class="num">{{ $t->minutes() ?? '—' }}</td><td class="small">{{ count($t->checklist ?? []) }} items ticked</td>
      <td class="nowrap"><form method="post" action="{{ route('hk.inspect', $t) }}" style="display:inline">@csrf<input type="hidden" name="result" value="pass"><button class="btn btn-sm btn-success">✓ Pass</button></form>
        <button class="btn btn-sm btn-danger" data-modal="failModal" data-action="{{ route('hk.inspect', $t) }}" data-title="Fail room {{ $t->room->room_no }}">✗ Fail</button></td></tr>
  @endforeach
  </tbody></table></div></div>
@endif

<div class="grid g-3">
@foreach(['pending' => 'Pending', 'in_progress' => 'In progress', 'completed' => 'Completed'] as $st => $label)
  <div class="card"><div class="card-head"><h3>{{ $label }}</h3><span class="badge badge-slate">{{ $tasks->where('status',$st)->count() }}</span></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:.6rem">
    @forelse($tasks->where('status',$st) as $t)
      <div style="border:1px solid var(--c-border);border-radius:8px;padding:.6rem .7rem">
        <div class="flex justify-between"><b>{{ $t->room ? 'Room '.$t->room->room_no : $t->area }}</b>
          <span>{!! $t->priority === 'high' ? '<span class="badge badge-red">High</span>' : '' !!} <span class="badge badge-slate" title="Credits">{{ $t->credits }}c</span></span></div>
        <div class="small text-2">{{ \App\Models\HousekeepingTask::TYPES[$t->task_type] ?? ucwords(str_replace('_',' ',$t->task_type)) }} · {{ $t->employee?->name ?? 'Unassigned' }}</div>
        @if($t->notes)<div class="tiny muted">{{ $t->notes }}</div>@endif
        @if($t->dnd_at && $st === 'pending')<div class="tiny" style="color:var(--c-purple)">DND / refused {{ $t->dnd_at->format('H:i') }}</div>@endif
        @if($t->reclean_count && $st !== 'completed')<div class="tiny text-red">Failed inspection: {{ $t->inspection_notes }}</div>@endif
        @if(can_do('housekeeping.manage') && $st !== 'completed')
        <form method="post" action="{{ route('housekeeping.tasks.update', $t) }}" class="flex gap-1 mt-1">@csrf
          @if($st === 'pending')<button name="status" value="in_progress" class="btn btn-sm btn-soft">Start</button>@endif
          <button name="status" value="completed" class="btn btn-sm btn-success" title="Supervisor override – checklist taken as done">Done</button>
        </form>@endif
        @if($t->completed_at)<div class="tiny text-green">Completed {{ $t->completed_at->format('H:i') }}@if($t->minutes() !== null) · {{ $t->minutes() }} min @endif
          @if($t->inspection_status === 'passed') · inspected{{ $t->inspector ? ' by '.$t->inspector->name : '' }}@elseif($t->awaitingInspection()) · <span class="text-amber">awaiting inspection</span>@endif</div>@endif
      </div>
    @empty <div class="muted small">No tasks</div> @endforelse
    </div></div>
@endforeach
</div>
<div class="modal" id="taskModal"><div class="modal-box"><form method="post" action="{{ route('housekeeping.tasks.store') }}">@csrf
  <div class="modal-head"><h3>New housekeeping task</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="room_id" label="Room" type="select" :options="$rooms"/><x-field name="area" label="Or public area" placeholder="Lobby, corridor…"/>
    <x-field name="task_type" label="Task" type="select" :options="\App\Models\HousekeepingTask::TYPES" :placeholder="false"/>
    <x-field name="employee_id" label="Assign to" type="select" :options="$staff"/>
    <x-field name="priority" label="Priority" type="select" :options="['normal'=>'Normal','high'=>'High','low'=>'Low']" :placeholder="false"/>
    <x-field name="task_date" label="Date" type="date" :value="$date" required/>
    <x-field name="notes" label="Notes" class="span-all"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Assign</button></div>
</form></div></div>
<div class="modal" id="failModal"><div class="modal-box"><form method="post" data-reset>@csrf<input type="hidden" name="result" value="fail">
  <div class="modal-head"><h3 data-title>Fail inspection</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="notes" label="What needs re-doing?" required placeholder="e.g. hair in bathroom, bed not tucked"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Send back for re-clean</button></div>
</form></div></div>
@endsection
