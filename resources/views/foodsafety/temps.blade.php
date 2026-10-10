@extends('layouts.app')
@section('title', 'Temperature Logs')
@section('crumbs') <a href="{{ route('fs.index') }}">Food Safety</a> / Temperature logs @endsection
@section('actions')
@if(can_do('foodsafety.record'))<button class="btn btn-primary" data-modal="tempModal" data-title="Record a temperature" data-f-kind="equipment" id="addReading"><x-icon name="thermo"/> Record temperature</button>@endif
@if(can_do('foodsafety.manage'))<button class="btn" data-modal="equipModal" data-title="Add equipment" data-action="{{ route('fs.equipment.store') }}" data-f-checks_per_day="2" id="addEquip"><x-icon name="plus"/> Equipment</button>@endif
@endsection
@section('content')
@php $FS = \App\Services\FoodSafetyService::class; $t = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.'); @endphp
<form method="get" class="filters" id="tempFilter">
  <div class="field"><label for="f_date">Day</label><input type="date" name="date" id="f_date" value="{{ $day->toDateString() }}" max="{{ now()->toDateString() }}" onchange="this.form.submit()"></div>
  <div class="field"><label for="f_kind_filter">Type</label><select name="kind" id="f_kind_filter" onchange="this.form.submit()"><option value="">All readings</option>@foreach(\App\Models\FsTempLog::KINDS as $k => $l)<option value="{{ $k }}" @selected($kind === $k)>{{ $l }}</option>@endforeach</select></div>
</form>

<div data-tabwrap>
<div class="tabs" data-tabs>
  <button class="active" data-tab="board">Equipment board</button>
  <button data-tab="log">Readings ({{ $logs->count() }})</button>
  <button data-tab="review">To review @if($pending->count())<span class="badge badge-red">{{ $pending->count() }}</span>@endif</button>
  <button data-tab="register">Equipment register</button>
  @if(can_do('foodsafety.manage'))<button data-tab="limits">Critical limits</button>@endif
</div>

<div class="tab-pane active" id="board">
  @if($board->isEmpty())<x-empty text="No equipment registered. Add fridges, freezers and hot-holding units to log them." icon="thermo"/>@else
  <div class="fs-board" id="tempBoard">
    @foreach($board as $eq)
      <div class="fs-unit {{ $eq->state }}" data-unit="{{ $eq->id }}" data-state="{{ $eq->state }}">
        <div class="flex justify-between items-center"><b>{{ $eq->name }}</b><span class="dots">@for($i = 0; $i < $eq->checks_per_day; $i++)@php $l = $eq->today[$i] ?? null; @endphp<i class="{{ $l ? ($l->in_range ? 'on' : 'bad') : '' }}"></i>@endfor</span></div>
        <div class="tiny muted">{{ \App\Models\FsEquipment::TYPES[$eq->type] ?? $eq->type }}{{ $eq->location ? ' · '.$eq->location : '' }}</div>
        <div class="t {{ $eq->last && ! $eq->last->in_range ? 'bad' : '' }}">{{ $eq->last ? $t($eq->last->reading_c).' °C' : '—' }}</div>
        <div class="tiny muted">limit {{ $eq->limitText() }} · {{ $eq->last ? 'last '.$eq->last->recorded_at->format('d/m H:i').' by '.$eq->last->user?->name : 'never checked' }}</div>
        <div class="flex justify-between items-center"><span class="tiny bold {{ $eq->state === 'due' ? 'text-amber' : ($eq->state === 'breach' ? 'text-red' : 'text-green') }}">{{ $eq->done }}/{{ $eq->checks_per_day }} {{ $day->isToday() ? 'today' : 'that day' }}</span>
          @if(can_do('foodsafety.record') && $day->isToday())<button class="btn btn-sm" data-modal="tempModal" data-title="{{ $eq->name }} – record temperature" data-f-kind="equipment" data-f-fs_equipment_id="{{ $eq->id }}" data-record-unit="{{ $eq->id }}">Record</button>@endif</div>
      </div>
    @endforeach
  </div>@endif
  @if($coolingOpen->isNotEmpty())
  <div class="card flush mt-1" id="coolingCard"><div class="card-head"><h3>Cooling in progress – 6-hour reading</h3></div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Food</th><th>2-h reading</th><th>6-h reading due</th><th>By</th><th></th></tr></thead>
    <tbody>@foreach($coolingOpen as $l)
      <tr data-cooling="{{ $l->id }}"><td class="bold">{{ $l->item }}</td><td>{{ $t($l->reading_c) }} °C at {{ $l->recorded_at->format('d/m H:i') }}</td>
        <td class="{{ $l->recorded_at->lt(now()->subHours(4)) ? 'text-red bold' : '' }}">{{ $l->recorded_at->copy()->addHours(4)->format('d/m H:i') }}{{ $l->recorded_at->lt(now()->subHours(4)) ? ' – overdue' : '' }}</td><td class="small">{{ $l->user?->name }}</td>
        <td class="num">@if(can_do('foodsafety.record'))<button class="btn btn-sm" data-modal="coolModal" data-title="{{ $l->item }} – 6-hour cooling reading" data-action="{{ route('fs.temps.cooling', $l) }}">Record 6-h</button>@endif</td></tr>
    @endforeach</tbody></table></div></div>
  @endif
</div>

<div class="tab-pane" id="log">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="logTable">
    <thead><tr><th>Time</th><th>Type</th><th>What</th><th class="num">°C</th><th>Limit</th><th>Result</th><th>Corrective action</th><th>By</th></tr></thead>
    <tbody>@forelse($logs as $l)
      <tr data-log="{{ $l->id }}" class="{{ $l->in_range ? '' : 'row-alert' }}"><td class="small">{{ $l->recorded_at->format('H:i') }}</td><td class="small">{{ \App\Models\FsTempLog::KINDS[$l->kind] }}{{ $l->category ? ' · '.$l->category : '' }}</td>
        <td>{{ $l->subject() }}@if($l->supplier)<div class="tiny muted">{{ $l->supplier->name }}</div>@endif</td>
        <td class="num bold {{ $l->in_range ? '' : 'text-red' }}">{{ $t($l->reading_c) }}@if($l->reading2_c !== null) → {{ $t($l->reading2_c) }}@endif</td>
        <td class="small">{{ $FS::limitText($l->min_c, $l->max_c) }}</td>
        <td>@if($l->in_range)<span class="badge badge-green">{{ $l->coolingOpen() ? 'cooling…' : 'OK' }}</span>@else<span class="badge badge-red">out of range</span>@if($l->reviewed_at)<div class="tiny muted">reviewed by {{ $l->reviewer?->name }}</div>@endif @endif</td>
        <td class="small">{{ $l->corrective_action }}</td><td class="small">{{ $l->user?->name }}</td></tr>
    @empty <tr><td colspan="8"><x-empty text="No readings for this day." icon="thermo"/></td></tr> @endforelse</tbody></table></div></div>
</div>

<div class="tab-pane" id="review">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="reviewTable">
    <thead><tr><th>When</th><th>What</th><th class="num">°C</th><th>Limit</th><th>Corrective action</th><th>Recorded by</th><th></th></tr></thead>
    <tbody>@forelse($pending as $l)
      <tr data-pending="{{ $l->id }}"><td class="small">{{ $l->recorded_at->format('d/m H:i') }}</td><td>{{ $l->subject() }}<div class="tiny muted">{{ \App\Models\FsTempLog::KINDS[$l->kind] }}</div></td>
        <td class="num bold text-red">{{ $t($l->reading2_c ?? $l->reading_c) }}</td><td class="small">{{ $FS::limitText($l->min_c, $l->max_c) }}</td><td class="small">{{ $l->corrective_action }}</td><td class="small">{{ $l->user?->name }}</td>
        <td class="num">@if(can_do('foodsafety.manage') && $l->user_id !== auth()->id())<button class="btn btn-sm btn-primary" data-modal="reviewModal" data-title="Review – {{ $l->subject() }}" data-action="{{ route('fs.temps.review', $l) }}" data-review="{{ $l->id }}">Review</button>@elseif($l->user_id === auth()->id())<span class="tiny muted">another manager reviews</span>@endif</td></tr>
    @empty <tr><td colspan="7"><x-empty text="All out-of-range readings have been reviewed." icon="check"/></td></tr> @endforelse</tbody></table></div></div>
  <p class="tiny muted">A manager checks that the corrective action was right (food discarded or moved, unit repaired or reported to engineering) and signs it off. The person who recorded a reading cannot review it.</p>
</div>

<div class="tab-pane" id="register">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="equipTable">
    <thead><tr><th>Equipment</th><th>Type</th><th>Location</th><th>Critical limit</th><th class="num">Checks / day</th><th>Status</th><th></th></tr></thead>
    <tbody>@forelse($equipment as $e)
      <tr data-equip="{{ $e->id }}" class="{{ $e->is_active ? '' : 'muted' }}"><td class="bold">{{ $e->name }}</td><td class="small">{{ \App\Models\FsEquipment::TYPES[$e->type] ?? $e->type }}</td><td class="small">{{ $e->location }}{{ $e->outlet ? ' · '.$e->outlet->name : '' }}</td>
        <td>{{ $e->limitText() }}</td><td class="num">{{ $e->checks_per_day }}</td><td>{!! $e->is_active ? '<span class="badge badge-green">in use</span>' : '<span class="badge badge-slate">retired</span>' !!}</td>
        <td class="num">@if(can_do('foodsafety.manage'))<button class="btn btn-sm btn-ghost btn-icon" title="Edit" data-modal="equipModal" data-title="Edit {{ $e->name }}" data-action="{{ route('fs.equipment.update', $e) }}" data-method="put"
          data-f-name="{{ $e->name }}" data-f-type="{{ $e->type }}" data-f-location="{{ $e->location }}" data-f-outlet_id="{{ $e->outlet_id }}" data-f-min_c="{{ $e->min_c !== null ? $t($e->min_c) : '' }}" data-f-max_c="{{ $e->max_c !== null ? $t($e->max_c) : '' }}" data-f-checks_per_day="{{ $e->checks_per_day }}" data-f-is_active="{{ $e->is_active ? '1' : '0' }}"><x-icon name="edit" size="sm"/></button>@endif</td></tr>
    @empty <tr><td colspan="7"><x-empty text="No equipment yet." icon="thermo"/></td></tr> @endforelse</tbody></table></div></div>
</div>

@if(can_do('foodsafety.manage'))
<div class="tab-pane" id="limits">
  <form method="post" action="{{ route('fs.settings') }}" class="card" id="limitsForm">@csrf
    <div class="card-head"><h3>Critical limits &amp; settings</h3></div>
    <div class="card-body"><div class="form-grid">
      @foreach($FS::SETTINGS as $k => [$label, $def])<x-field :name="$k" :label="$label" type="number" step="0.5" :value="$t($limits[$k])" required/>@endforeach
      <x-field name="fs_cert_warn_days" label="Warn about certificates expiring within (days)" type="number" min="0" max="180" :value="setting('fs_cert_warn_days', '30')" required/>
      <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="fs_cert_roster" value="1" @checked(setting('fs_cert_roster', '1') === '1')> Warn on the duty roster when a food handler has no valid medical certificate</label></div>
    </div>
    <div class="field mt-1"><label>Food handler departments</label><div class="flex gap-1" style="flex-wrap:wrap">@foreach(\App\Models\Department::orderBy('name')->get() as $d)<label class="check"><input type="checkbox" name="fs_handler_depts[]" value="{{ $d->id }}" @checked(in_array($d->id, $FS::handlerDepartments()))> {{ $d->name }}</label>@endforeach</div></div>
    </div>
    <div class="card-foot"><button class="btn btn-primary" id="limitsSave">Save</button></div>
  </form>
</div>
@endif
</div>

@if(can_do('foodsafety.record'))
<div class="modal" id="tempModal"><div class="modal-box" style="max-width:560px"><form method="post" action="{{ route('fs.temps.store') }}" data-reset id="tempForm">@csrf
  <div class="modal-head"><h3 data-title>Record a temperature</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="kind" label="Reading" type="select" :options="\App\Models\FsTempLog::KINDS" :placeholder="false" required/>
    <div class="field" data-for="equipment"><label for="f_fs_equipment_id">Equipment</label><select name="fs_equipment_id" id="f_fs_equipment_id"><option value="">— Select —</option>@foreach($equipment->where('is_active', true) as $e)<option value="{{ $e->id }}" data-min="{{ $e->min_c }}" data-max="{{ $e->max_c }}">{{ $e->name }} ({{ $e->limitText() }})</option>@endforeach</select></div>
    <div class="field" data-for="receiving,cooking,reheating,cooling"><label for="f_item">Food / delivery</label><input name="item" id="f_item" maxlength="120" placeholder="e.g. Chicken thighs, Jollof rice batch 2"></div>
    <div class="field" data-for="receiving"><label for="f_category">Delivered</label><select name="category" id="f_category"><option value="chilled">Chilled</option><option value="frozen">Frozen</option><option value="hot">Hot (cooked, delivered hot)</option></select></div>
    <div class="field" data-for="receiving"><label for="f_supplier_id">Supplier</label><select name="supplier_id" id="f_supplier_id"><option value="">—</option>@foreach($suppliers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
    <div class="field"><label for="f_reading_c"><span id="readLabel">Temperature</span> (°C) <span class="req">*</span></label><input type="number" step="0.1" min="-60" max="150" name="reading_c" id="f_reading_c" required inputmode="decimal"></div>
    <div class="field"><label>Critical limit</label><div class="bold" id="limitHint" style="padding:.5rem 0">—</div></div>
    <div class="field span-2" id="actionWrap" hidden><label for="f_corrective_action">Corrective action taken <span class="req">*</span></label><input name="corrective_action" id="f_corrective_action" maxlength="255" placeholder="e.g. Moved stock to walk-in 2, called engineering, discarded 2 kg"></div>
    <details class="span-2"><summary class="small muted">Reading taken earlier?</summary><div class="field mt-1"><label for="f_recorded_at">Time of the check</label><input type="datetime-local" name="recorded_at" id="f_recorded_at" max="{{ now()->format('Y-m-d\TH:i') }}"></div></details>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="tempSave">Save reading</button></div>
</form></div></div>

<div class="modal" id="coolModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>6-hour cooling reading</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reading2_c" label="Temperature now (°C)" type="number" step="0.1" required/><div class="field"><label for="f_cool_action">Corrective action (if above {{ $t($limits['fs_cool_6h_max']) }} °C)</label><input name="corrective_action" id="f_cool_action" maxlength="255"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="coolSave">Save</button></div>
</form></div></div>
@endif

@if(can_do('foodsafety.manage'))
<div class="modal" id="reviewModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Review reading</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="review_note" label="Review note" placeholder="Action confirmed; engineer ticket raised…"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="reviewSave">Mark reviewed</button></div>
</form></div></div>

<div class="modal" id="equipModal"><div class="modal-box"><form method="post" action="{{ route('fs.equipment.store') }}" data-reset id="equipForm">@csrf<input type="hidden" name="_method" value="post" id="equipMethod">
  <div class="modal-head"><h3 data-title>Equipment</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="name" label="Name" required placeholder="Walk-in chiller 1"/>
    <x-field name="type" label="Type" type="select" :options="\App\Models\FsEquipment::TYPES" required/>
    <x-field name="location" label="Location" placeholder="Main kitchen"/>
    <x-field name="outlet_id" label="Outlet" type="select" :options="$outlets"/>
    <x-field name="min_c" label="Minimum °C (blank = none)" type="number" step="0.5"/>
    <x-field name="max_c" label="Maximum °C (blank = none)" type="number" step="0.5"/>
    <x-field name="checks_per_day" label="Checks per day" type="number" min="1" max="6" required/>
    <div class="field"><label>&nbsp;</label><label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> In use</label></div>
  </div><p class="tiny muted">Leave the limits blank on a new unit to use the defaults for its type (chilled ≤ {{ $t($limits['fs_fridge_max']) }} °C, frozen ≤ {{ $t($limits['fs_freezer_max']) }} °C, hot ≥ {{ $t($limits['fs_hot_hold_min']) }} °C).</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="equipSave">Save</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>
(() => {
  const L = @json($limits);
  const f = document.getElementById('tempForm'); if (!f) return;
  const kind = f.querySelector('[name=kind]'), eq = f.querySelector('[name=fs_equipment_id]'), cat = f.querySelector('[name=category]'), val = f.querySelector('[name=reading_c]');
  const fmt = v => (Math.round(v * 10) / 10) + ' °C';
  function limits() {
    const k = kind.value;
    if (k === 'equipment') { const o = eq.selectedOptions[0]; return o && o.value ? [o.dataset.min === '' ? null : +o.dataset.min, o.dataset.max === '' ? null : +o.dataset.max] : [null, null]; }
    if (k === 'receiving') return cat.value === 'frozen' ? [null, L.fs_recv_frozen_max] : (cat.value === 'hot' ? [L.fs_hot_hold_min, null] : [null, L.fs_recv_chilled_max]);
    if (k === 'cooking') return [L.fs_cook_min, null];
    if (k === 'reheating') return [L.fs_reheat_min, null];
    if (k === 'cooling') return [null, L.fs_cool_2h_max];
    return [null, null];
  }
  function sync() {
    const k = kind.value;
    f.querySelectorAll('[data-for]').forEach(el => el.hidden = !el.dataset.for.split(',').includes(k));
    document.getElementById('readLabel').textContent = k === 'cooling' ? 'Temperature 2 hours after cooking' : (k === 'cooking' || k === 'reheating' ? 'Core temperature' : 'Temperature');
    const [mn, mx] = limits();
    document.getElementById('limitHint').textContent = mn !== null && mx !== null ? fmt(mn) + ' – ' + fmt(mx) : (mn !== null ? '≥ ' + fmt(mn) : (mx !== null ? '≤ ' + fmt(mx) : '—'));
    const v = val.value === '' ? null : +val.value, bad = v !== null && ((mn !== null && v < mn) || (mx !== null && v > mx));
    document.getElementById('actionWrap').hidden = !bad; document.getElementById('f_corrective_action').required = bad;
    val.style.color = bad ? 'var(--c-red)' : '';
  }
  [kind, eq, cat].forEach(el => el.addEventListener('change', sync)); val.addEventListener('input', sync);
  document.addEventListener('click', e => { if (e.target.closest('[data-modal=tempModal]')) setTimeout(sync, 0); });
  // equipment modal: PUT for edits
  document.addEventListener('click', e => { const b = e.target.closest('[data-modal=equipModal]'); if (b) setTimeout(() => { document.getElementById('equipMethod').value = b.dataset.method || 'post'; }, 0); });
  if (location.hash === '#record') { document.getElementById('addReading')?.click(); }
  sync();
})();
</script>
@endpush
