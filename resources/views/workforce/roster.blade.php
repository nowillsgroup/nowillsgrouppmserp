@extends('layouts.app')
@section('title', 'Duty Roster')
@section('crumbs') HR &amp; Payroll / Duty roster @endsection
@section('actions')
<a class="btn" href="{{ route('clock.index', ['dept' => $dept]) }}"><x-icon name="clock"/> Time clock</a>
@if($g)<a class="btn" href="{{ route('roster.print', ['dept' => $dept, 'week' => $week->toDateString()]) }}" target="_blank" id="rosterPrint"><x-icon name="print"/> Print</a>@endif
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['roster_vs_actual']) }}"><x-icon name="chart"/> Roster vs actual</a>@endif
@endsection
@section('content')
@php
  $bd = bdate()->toDateString();
  $state = $g['week']?->state() ?? 'draft';
  $pending = $swaps->where('status', 'pending_manager');
@endphp
<form method="get" class="filters" id="rosterFilter">
  <div class="field"><label for="f_dept">Department</label><select name="dept" id="f_dept" onchange="this.form.submit()">@foreach($departments as $id => $n)<option value="{{ $id }}" @selected($dept == $id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field"><label for="f_week">Week of</label><input type="date" name="week" id="f_week" value="{{ $week->toDateString() }}" onchange="this.form.submit()"></div>
  <div class="field" style="align-self:flex-end;display:flex;flex-direction:row;gap:.4rem;flex-wrap:wrap">
    <a class="btn" href="{{ route('roster.index', ['dept' => $dept, 'week' => $week->copy()->subWeek()->toDateString()]) }}" id="prevWeek"><x-icon name="chev" style="transform:rotate(180deg)"/> Previous</a>
    <a class="btn" href="{{ route('roster.index', ['dept' => $dept, 'week' => now()->toDateString()]) }}">This week</a>
    <a class="btn" href="{{ route('roster.index', ['dept' => $dept, 'week' => $week->copy()->addWeek()->toDateString()]) }}" id="nextWeek">Next <x-icon name="chev"/></a>
  </div>
</form>

<div data-tabwrap>
<div class="tabs" data-tabs>
  <button class="active" data-tab="grid">Week roster</button>
  <button data-tab="swaps">Shift swaps @if($pending->count())<span class="badge badge-amber">{{ $pending->count() }}</span>@endif</button>
  <button data-tab="templates">Shift templates</button>
  <button data-tab="rules">Rules</button>
</div>

<div class="tab-pane active" id="grid">
@if(! $g)
  <x-empty text="Choose a department." icon="calendar"/>
@else
  <div class="card flush">
    <div class="card-head"><h3><x-icon name="calendar"/> {{ $departments[$dept] ?? '' }} · {{ dmy($week) }} – {{ dmy($week->copy()->addDays(6)) }}
      <span class="badge {{ ['draft' => 'badge-slate', 'published' => 'badge-green', 'changed' => 'badge-amber'][$state] }}" id="rosterState">{{ ['draft' => 'Draft – not visible to staff', 'published' => 'Published '.($g['week']?->published_at?->format('d M H:i') ?? ''), 'changed' => 'Changed since publishing – republish'][$state] }}</span></h3>
      @if($canManage)
      <div class="flex g-1">
        <form method="post" action="{{ route('roster.copy') }}" data-confirm="Copy the previous week's shifts into empty days of this week?">@csrf<input type="hidden" name="dept" value="{{ $dept }}"><input type="hidden" name="week" value="{{ $week->toDateString() }}"><input type="hidden" name="from_week" value="{{ $week->copy()->subWeek()->toDateString() }}"><button class="btn btn-sm" id="copyWeek"><x-icon name="layers"/> Copy last week</button></form>
        <form method="post" action="{{ route('roster.publish') }}">@csrf<input type="hidden" name="dept" value="{{ $dept }}"><input type="hidden" name="week" value="{{ $week->toDateString() }}"><button class="btn btn-sm btn-primary" id="publishWeek" @disabled($state === 'published')><x-icon name="check"/> Publish</button></form>
      </div>
      @endif
    </div>
    <form method="post" action="{{ route('roster.save') }}" id="rosterForm">@csrf
    <input type="hidden" name="dept" value="{{ $dept }}"><input type="hidden" name="week" value="{{ $week->toDateString() }}">
    <div class="card-body table-wrap"><table class="table roster-grid" id="rosterGrid">
      <thead><tr><th>Employee</th>
        @foreach($g['days'] as $d)<th class="center {{ $d->toDateString() === $bd ? 'today' : '' }}">{{ $d->format('D') }}<div class="tiny muted">{{ $d->format('d M') }}</div>@if(! empty($g['short'][$d->toDateString()]))<div class="tiny text-red" title="Below minimum staffing">short {{ implode(', ', $g['short'][$d->toDateString()]) }}</div>@endif</th>@endforeach
        <th class="num">Hours</th></tr></thead>
      <tbody>
      @forelse($g['employees'] as $e)
        @php $w = $g['warnings'][$e->id] ?? []; @endphp
        <tr data-emp="{{ $e->emp_code }}">
          <td><b>{{ $e->name }}</b><div class="tiny muted">{{ $e->emp_code }} · {{ $e->designation }}</div>
            @if($w)<div class="tiny text-red" data-warn>@foreach($w as $x)<div>⚠ {{ $x }}</div>@endforeach</div>@endif</td>
          @foreach($g['days'] as $d)
            @php
              $ds = $d->toDateString(); $s = $g['shifts'][$e->id][$ds] ?? null; $lv = $g['leave'][$e->id][$ds] ?? null;
              $locked = $ds < $bd || ($lv && ! $lv->half_day) || ! $canManage;
              $val = $s ? ($s->kind === 'off' ? 'OFF' : (string) $s->shift_template_id) : '';
              $col = $s?->isShift() ? ($s->template?->color ?? '#64748b') : null;
            @endphp
            <td class="center roster-cell" data-day="{{ $ds }}" @if($col) style="background:{{ $col }}22;border-left:3px solid {{ $col }}" @endif>
              @if($lv && ! $lv->half_day)<span class="badge badge-blue" title="{{ $lv->leaveType?->name }}">Leave</span>
              @elseif($locked)<span class="bold" title="{{ $s?->label() }}">{{ $s ? $s->code() : '—' }}</span>@if($s?->isShift())<div class="tiny muted">{{ substr($s->start_time, 0, 5) }}–{{ substr($s->end_time, 0, 5) }}</div>@endif
              @else
                <select name="cells[{{ $e->id }}][{{ $ds }}]" class="roster-select" aria-label="{{ $e->name }} {{ $d->format('D d M') }}">
                  <option value="">—</option>
                  @foreach($g['templates'] as $t)<option value="{{ $t->id }}" data-color="{{ $t->color }}" @selected($val === (string) $t->id)>{{ $t->code }} {{ substr($t->start_time, 0, 5) }}</option>@endforeach
                  @if($s && $s->isShift() && ! $g['templates']->contains('id', $s->shift_template_id))<option value="{{ $s->shift_template_id }}" selected>{{ $s->code() }}</option>@endif
                  <option value="OFF" @selected($val === 'OFF')>OFF</option>
                </select>
                @if($lv)<div class="tiny text-blue">½ leave</div>@endif
              @endif
            </td>
          @endforeach
          <td class="num {{ ($g['hours'][$e->id] ?? 0) > \App\Services\RosterService::weekHours() ? 'text-red bold' : '' }}" data-hours>{{ number_format($g['hours'][$e->id] ?? 0, 1) }}</td>
        </tr>
      @empty
        <tr><td colspan="9"><x-empty text="No active employees in this department." icon="users"/></td></tr>
      @endforelse
      </tbody>
      <tfoot><tr><th class="small">On duty</th>
        @foreach($g['days'] as $d)<th class="center tiny">@foreach($g['templates'] as $t)@if($n = $g['coverage'][$d->toDateString()][$t->id] ?? 0)<span style="color:{{ $t->color }}">{{ $t->code }}</span>&nbsp;{{ $n }} @endif @endforeach</th>@endforeach
        <th></th></tr></tfoot>
    </table></div>
    @if($canManage && $g['employees']->isNotEmpty())
    <div class="card-foot flex g-1" style="padding:.75rem 1rem;justify-content:space-between;align-items:center">
      <span class="tiny muted">Past days and approved full-day leave are locked. Staff see the roster in self-service only after it is published; changes after publishing are flagged.</span>
      <button class="btn btn-primary" id="rosterSave"><x-icon name="check"/> Save roster</button>
    </div>
    @endif
    </form>
  </div>
  <div class="flex mt-1 tiny" id="legend" style="gap:.4rem 1.2rem;flex-wrap:wrap">@foreach($g['templates'] as $t)<span><span style="display:inline-block;width:.8rem;height:.8rem;border-radius:2px;background:{{ $t->color }};vertical-align:middle"></span> <b>{{ $t->code }}</b> {{ $t->name }} {{ $t->label() }}</span>@endforeach <span><b>OFF</b> day off</span></div>
@endif
</div>

<div class="tab-pane" id="swaps">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="swapTable">
    <thead><tr><th>Requested by</th><th>Shift</th><th>With</th><th>In exchange</th><th>Reason</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($swaps as $s)
      <tr data-swap="{{ $s->id }}">
        <td><b>{{ $s->requester?->name }}</b><div class="tiny muted">{{ $s->created_at->format('d M H:i') }}</div></td>
        <td class="small">{{ $s->shift ? dmy($s->shift->date).' · '.$s->shift->code().' '.$s->shift->label() : '—' }}</td>
        <td>{{ $s->colleague?->name }}</td>
        <td class="small">{{ $s->colleagueShift ? dmy($s->colleagueShift->date).' · '.$s->colleagueShift->code().' '.$s->colleagueShift->label() : 'hand-over (colleague off)' }}</td>
        <td class="small">{{ $s->reason }}</td>
        <td><span class="badge {{ ['pending_peer' => 'badge-slate', 'pending_manager' => 'badge-amber', 'approved' => 'badge-green'][$s->status] ?? 'badge-red' }}">{{ str_replace('_', ' ', $s->status) }}</span>
          @if($s->decider)<div class="tiny muted">{{ $s->decider->name }}{{ $s->decision_note ? ' · '.$s->decision_note : '' }}</div>@endif</td>
        <td class="nowrap">@if($canManage && $s->status === 'pending_manager')
          <form method="post" action="{{ route('roster.swap', $s) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="approve"><button class="btn btn-sm btn-primary" data-approve-swap="{{ $s->id }}">Approve</button></form>
          <form method="post" action="{{ route('roster.swap', $s) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="reject"><button class="btn btn-sm">Refuse</button></form>@endif</td>
      </tr>
    @empty
      <tr><td colspan="7"><x-empty text="No swap requests in the last two weeks." icon="swap"/></td></tr>
    @endforelse
    </tbody></table></div></div>
  <p class="tiny muted">Staff ask a colleague in self-service; once the colleague accepts, a manager approves and the two roster days are exchanged (or the shift is handed over if the colleague is off). Requests close {{ setting('roster_swap_notice_hours', '12') }} h before the shift starts.</p>
</div>

<div class="tab-pane" id="templates">
  <div class="card flush"><div class="card-head"><h3><x-icon name="clock"/> Shift templates</h3>@if($canManage)<button class="btn btn-sm btn-primary" data-modal="tplModal" data-title="New shift" id="newTpl"><x-icon name="plus"/> New shift</button>@endif</div>
  <div class="card-body table-wrap"><table class="table" id="tplTable">
    <thead><tr><th>Code</th><th>Name</th><th>Time</th><th class="num">Break</th><th class="num">Paid h</th><th>Department</th><th class="num">Min staff</th><th></th></tr></thead>
    <tbody>
    @foreach($allTemplates as $t)
      <tr class="{{ $t->is_active ? '' : 'muted' }}"><td><span style="display:inline-block;width:.7rem;height:.7rem;border-radius:2px;background:{{ $t->color }}"></span> <b>{{ $t->code }}</b></td><td>{{ $t->name }}</td><td>{{ $t->label() }}</td>
        <td class="num">{{ $t->break_minutes }} min</td><td class="num">{{ number_format($t->paidMinutes() / 60, 1) }}</td><td class="small">{{ $t->department?->name ?? 'All departments' }}</td><td class="num">{{ $t->min_staff ?: '—' }}</td>
        <td class="nowrap">@if($canManage)<button class="btn btn-sm" data-modal="tplModal" data-title="Edit {{ $t->code }}" data-f-id="{{ $t->id }}" data-f-code="{{ $t->code }}" data-f-name="{{ $t->name }}" data-f-department_id="{{ $t->department_id }}" data-f-start_time="{{ substr($t->start_time, 0, 5) }}" data-f-end_time="{{ substr($t->end_time, 0, 5) }}" data-f-break_minutes="{{ $t->break_minutes }}" data-f-min_staff="{{ $t->min_staff }}" data-f-color="{{ $t->color }}">Edit</button>
          <form method="post" action="{{ route('roster.template.toggle', $t) }}" style="display:inline">@csrf<button class="btn btn-sm">{{ $t->is_active ? 'Retire' : 'Activate' }}</button></form>@endif</td></tr>
    @endforeach
    </tbody></table></div></div>
</div>

<div class="tab-pane" id="rules">
  <div class="card"><div class="card-body">
  @if($canManage)
  <form method="post" action="{{ route('roster.settings') }}" class="form-grid" id="rosterRules">@csrf
    <x-field name="roster_min_rest_hours" label="Minimum rest between shifts (h)" type="number" step="0.5" min="0" max="24" :value="setting('roster_min_rest_hours', '11')" required/>
    <x-field name="roster_max_days" label="Maximum consecutive working days" type="number" min="1" max="14" :value="setting('roster_max_days', '6')" required/>
    <x-field name="roster_week_hours" label="Normal working week (h)" type="number" step="0.5" min="1" max="84" :value="setting('roster_week_hours', '40')" required/>
    <x-field name="roster_swap_notice_hours" label="Swap requests close before the shift (h)" type="number" min="0" max="168" :value="setting('roster_swap_notice_hours', '12')" required/>
    <div><button class="btn btn-primary">Save rules</button></div>
  </form>
  @endif
  <p class="tiny muted mt-1">The roster warns (it does not block) when a shift leaves less rest than the minimum, when someone works more consecutive days than allowed, and when planned hours exceed the normal week – Ghana's Labour Act 2003 (Act 651) sets 8 h a day / 40 h a week as normal hours, with overtime paid above that.</p>
  </div></div>
</div>
</div>

@if($canManage)
<div class="modal" id="tplModal"><div class="modal-box"><form method="post" action="{{ route('roster.template') }}" data-reset>@csrf
  <input type="hidden" name="id" value="">
  <div class="modal-head"><h3 data-title>Shift</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="code" label="Code" required maxlength="8" placeholder="e.g. M"/>
    <x-field name="name" label="Name" required maxlength="60" placeholder="e.g. Morning"/>
    <x-field name="start_time" label="Starts" type="time" required/>
    <x-field name="end_time" label="Ends (earlier = next day)" type="time" required/>
    <x-field name="break_minutes" label="Unpaid break (min)" type="number" min="0" max="240" value="60" required/>
    <x-field name="min_staff" label="Minimum staff per day" type="number" min="0" max="50" value="0"/>
    <x-field name="department_id" label="Department" type="select" :options="$departments" placeholder="— all departments —"/>
    <x-field name="color" label="Colour" type="color" value="#3b82f6" required/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="tplSave">Save shift</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>
document.querySelectorAll('.roster-select').forEach(s => s.addEventListener('change', () => {
  const o = s.selectedOptions[0], td = s.closest('td'), c = o && o.dataset.color;
  td.style.background = c ? c + '22' : ''; td.style.borderLeft = c ? '3px solid ' + c : '';
}));
</script>
@endpush
