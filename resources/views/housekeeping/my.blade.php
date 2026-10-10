@extends('layouts.app')
@section('title', 'My Rooms')
@section('actions')
@if($supervisor && $attendants->isNotEmpty())
<form class="filters"><div class="field"><select name="as" data-autosubmit aria-label="View as attendant"><option value="">{{ $me ? 'Me ('.$me->name.')' : '— view attendant —' }}</option>@foreach($attendants as $id => $n)<option value="{{ $id }}" @selected(request('as') == $id)>{{ $n }}</option>@endforeach</select></div></form>
@endif
@endsection
@section('content')
<div class="hk-wrap">
@if(! $emp && ! $supervisor)
  <div class="card"><div class="card-body">
    <h3 style="margin-top:0">Your login is not linked to an employee</h3>
    <p class="text-2">Ask HR to open <b>HR &amp; Staff › Employees</b>, edit your record and choose your login under <b>Login user</b>. Your assigned rooms will then appear here.</p>
  </div></div>
@else
  @if($emp)
  @php
    $done = $tasks->where('status', 'completed')->count();
    $credits = $tasks->sum('credits');
  @endphp
  <div class="flex justify-between items-center mb-1"><div><div class="small text-2">{{ bdate()->format('l, d M Y') }}</div><h2 style="margin:.1rem 0 0">{{ $emp->name }}</h2></div></div>
  <div class="hk-summary">
    <div><b>{{ $tasks->count() }}</b><span>Rooms</span></div>
    <div><b class="text-green">{{ $done }}</b><span>Done</span></div>
    <div><b>{{ $tasks->whereIn('status', ['pending', 'in_progress'])->count() }}</b><span>To do</span></div>
    <div><b>{{ $credits }}</b><span>Credits</span></div>
  </div>

  @forelse($tasks as $t)
  @php
    $stay = $t->room?->currentStay;
    $guest = $stay ? ($stay->guest ?? $stay->reservation?->guest) : null;
    $awaiting = $t->awaitingInspection();
  @endphp
  <div class="hk-card st-{{ $t->status }} {{ $t->dnd_at && $t->status === 'pending' ? 'is-dnd' : '' }} {{ $t->reclean_count && $t->status !== 'completed' ? 'is-reclean' : '' }}" data-task="{{ $t->id }}">
    <div class="flex justify-between items-center">
      <div>
        <div class="hk-room">{{ $t->room ? $t->room->room_no : ($t->area ?: 'Area') }}</div>
        <div class="small text-2">{{ $t->room?->roomType?->name }}{{ $t->room?->floor ? ' · '.$t->room->floor->name : '' }}</div>
      </div>
      <div style="text-align:right">
        <div class="badge {{ $t->task_type === 'departure' ? 'badge-red' : 'badge-blue' }}">{{ \App\Models\HousekeepingTask::TYPES[$t->task_type] ?? $t->task_type }}</div>
        <div class="tiny muted mt-1">{{ $t->credits }} credit{{ $t->credits == 1 ? '' : 's' }}{{ $t->priority === 'high' ? ' · high priority' : '' }}</div>
      </div>
    </div>
    <div class="small mt-1">
      @if($t->room)
        {{ $t->room->status === 'occupied' ? 'Occupied' : 'Vacant' }} · HK: <b>{{ ucwords(str_replace('_', ' ', $t->room->hk_status)) }}</b>
        @if($guest) · {{ $guest->full_name }}@if($stay->departure_date->isSameDay(bdate())) <span class="badge badge-amber">Departs today</span>@endif @endif
      @endif
    </div>
    @if($t->notes)<div class="tiny text-2 mt-1">📝 {{ $t->notes }}</div>@endif
    @if($t->reclean_count && $t->status !== 'completed')<div class="alert alert-error mt-1" style="padding:.45rem .6rem;margin:0">Failed inspection: {{ $t->inspection_notes }}</div>@endif
    @if($t->dnd_at && $t->status === 'pending')<div class="tiny mt-1" style="color:var(--c-purple)">🚫 DND / refused at {{ $t->dnd_at->format('H:i') }} – try again later</div>@endif

    @if($t->status !== 'completed')
    <div class="acts">
      @if($t->status === 'pending')
        <form method="post" action="{{ route('hk.start', $t) }}">@csrf<button class="btn btn-primary btn-block">▶ Start</button></form>
      @else
        <span class="btn btn-soft btn-block" style="cursor:default">⏱ {{ $t->started_at?->format('H:i') }}</span>
      @endif
      <form method="post" action="{{ route('hk.dnd', $t) }}">@csrf<button class="btn btn-block">🚫 DND</button></form>
      <button class="btn btn-success btn-block" data-modal="doneModal" data-action="{{ route('hk.complete', $t) }}" data-title="Room {{ $t->room?->room_no ?? $t->area }} – checklist">✓ Done</button>
    </div>
    @else
    <div class="small mt-1 text-green">✓ Completed {{ $t->completed_at?->format('H:i') }}@if($t->minutes() !== null) · {{ $t->minutes() }} min @endif
      @if($t->inspection_status === 'passed') · <b>Inspected</b>@elseif($awaiting) · <span class="text-2">waiting for inspection</span>@endif
    </div>
    @endif
    @if($t->room)
    <div class="more">
      @if($minibar->isNotEmpty() && $t->room->status === 'occupied')<button class="btn btn-sm btn-ghost" data-modal="minibarModal" data-action="{{ route('hk.minibar', $t) }}" data-title="Minibar – room {{ $t->room->room_no }}">🍫 Minibar</button>@endif
      <button class="btn btn-sm btn-ghost" data-modal="issueModal" data-action="{{ route('hk.issue', $t) }}" data-title="Report issue – room {{ $t->room->room_no }}">🔧 Report issue</button>
      <button class="btn btn-sm btn-ghost" data-modal="foundModal" data-action="{{ route('hk.found', $t) }}" data-title="Found item – room {{ $t->room->room_no }}">🔎 Found item</button>
    </div>
    @endif
  </div>
  @empty
  <div class="card"><div class="card-body muted">No rooms assigned to {{ $emp->name }} today.</div></div>
  @endforelse
  @endif

  @if($supervisor)
  <h3 class="mt-2" id="inspect">Waiting for inspection <span class="badge badge-slate">{{ $inspect->count() }}</span></h3>
  @forelse($inspect as $t)
  <div class="hk-card st-completed">
    <div class="flex justify-between items-center">
      <div><div class="hk-room">{{ $t->room->room_no }}</div><div class="small text-2">{{ $t->employee?->name ?? '—' }} · done {{ $t->completed_at?->format('H:i') }}@if($t->minutes() !== null) · {{ $t->minutes() }} min @endif</div></div>
      <div class="tiny muted">{{ count($t->checklist ?? []) }} checks{{ $t->reclean_count ? ' · re-clean #'.$t->reclean_count : '' }}</div>
    </div>
    <div class="acts" style="grid-template-columns:1fr 1fr">
      <form method="post" action="{{ route('hk.inspect', $t) }}">@csrf<input type="hidden" name="result" value="pass"><button class="btn btn-success btn-block">✓ Pass</button></form>
      <button class="btn btn-danger btn-block" data-modal="failModal" data-action="{{ route('hk.inspect', $t) }}" data-title="Fail room {{ $t->room->room_no }}">✗ Fail</button>
    </div>
  </div>
  @empty
  <div class="muted small">Nothing waiting for inspection.</div>
  @endforelse
  @endif
@endif
</div>

<div class="modal" id="doneModal"><div class="modal-box"><form method="post" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Checklist</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="checklist">
      @foreach($checklist as $c)<label><input type="checkbox" name="checked[]" value="{{ $c }}"> {{ $c }}</label>@endforeach
    </div>
    @if($checklist)<button type="button" class="btn btn-sm btn-ghost mt-1" onclick="this.closest('form').querySelectorAll('[name=&quot;checked[]&quot;]').forEach(c => c.checked = true)">Tick all</button>@endif
    <x-field name="notes" label="Notes (optional)" placeholder="e.g. extra towels left, bulb replaced" class="mt-1"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-success">Room done</button></div>
</form></div></div>

<div class="modal" id="minibarModal"><div class="modal-box"><form method="post" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Minibar</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small text-2" style="margin-top:0">Enter what the guest consumed. It is posted straight to the guest's bill.</p>
    <div class="mb-grid">
      @foreach($minibar as $m)<label for="mb{{ $m->id }}">{{ $m->name }} <span class="tiny muted">{{ money($m->price) }}</span></label><input id="mb{{ $m->id }}" type="number" name="qty[{{ $m->id }}]" min="0" max="99" inputmode="numeric" placeholder="0">@endforeach
    </div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Post to bill</button></div>
</form></div></div>

<div class="modal" id="issueModal"><div class="modal-box"><form method="post" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Report issue</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="title" label="What is wrong?" required class="span-all" placeholder="e.g. AC not cooling"/>
    <x-field name="category" label="Type" type="select" :options="['general'=>'General','electrical'=>'Electrical','plumbing'=>'Plumbing','ac'=>'AC','carpentry'=>'Carpentry','it'=>'TV / Wi-Fi','civil'=>'Civil']" :placeholder="false"/>
    <x-field name="priority" label="Priority" type="select" :options="['normal'=>'Normal','high'=>'High','urgent'=>'Urgent','low'=>'Low']" :placeholder="false"/>
    <label class="check span-all"><input type="checkbox" name="block_room" value="1"> Block the room (out of order) if it is vacant</label>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Raise ticket</button></div>
</form></div></div>

<div class="modal" id="foundModal"><div class="modal-box"><form method="post" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Found item</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="item_name" label="Item" required class="span-all" placeholder="e.g. Phone charger"/>
    <x-field name="lf_category" label="Category" type="select" class="span-all" :options="collect(\App\Models\LostFoundItem::CATEGORIES)->map(fn ($c) => $c[0])->all()" value="other" :placeholder="false"/>
    <x-field name="description" label="Description / where found" class="span-all"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Log item</button></div>
</form></div></div>

<div class="modal" id="failModal"><div class="modal-box"><form method="post" data-reset>@csrf<input type="hidden" name="result" value="fail">
  <div class="modal-head"><h3 data-title>Fail inspection</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="notes" label="What needs re-doing?" required placeholder="e.g. hair in bathroom, bed not tucked"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Send back</button></div>
</form></div></div>
@endsection
