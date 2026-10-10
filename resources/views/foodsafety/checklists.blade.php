@extends('layouts.app')
@section('title', 'HACCP Checklists')
@section('crumbs') <a href="{{ route('fs.index') }}">Food Safety</a> / Checklists @endsection
@section('actions')
@if(can_do('foodsafety.manage'))<button class="btn" data-modal="listModal" data-title="New checklist" data-action="{{ route('fs.checklists.store') }}" data-f-frequency="daily" id="newList"><x-icon name="plus"/> New checklist</button>@endif
@endsection
@section('content')
@php $FS = \App\Services\FoodSafetyService::class; @endphp
<div data-tabwrap>
<div class="tabs" data-tabs>
  <button class="active" data-tab="current">Current period</button>
  <button data-tab="history">Completed</button>
  <button data-tab="templates">Templates</button>
</div>
<div class="tab-pane active" id="current">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="currentTable">
    <thead><tr><th>Checklist</th><th>Area</th><th>Period</th><th>Due</th><th>Status</th><th>Completed by</th><th></th></tr></thead>
    <tbody>@forelse($lists as $c)
      <tr data-list="{{ $c->id }}"><td class="bold">{{ $c->name }}<div class="tiny muted">{{ count($c->items) }} items · {{ \App\Models\FsChecklist::FREQUENCIES[$c->frequency] }}</div></td><td class="small">{{ $c->area }}</td>
        <td class="small">{{ $FS::periodLabel($c, $c->period) }}</td><td class="small">{{ $c->due_time ? substr($c->due_time, 0, 5) : '—' }}</td><td>@include('foodsafety._state', ['state' => $c->state])</td>
        <td class="small">@if($c->run){{ $c->run->completer?->name }} {{ $c->run->completed_at->format('H:i') }}@if($c->run->failed)<div class="tiny text-red">{{ $c->run->failed }} failed</div>@endif @endif</td>
        <td class="num">@if(! $c->run && can_do('foodsafety.record'))<a class="btn btn-sm btn-primary" href="{{ route('fs.checklists.run', $c) }}" data-start="{{ $c->id }}">Start</a>@elseif($c->run)<a class="btn btn-sm" href="{{ route('fs.checklists.show', $c->run) }}">View</a>@endif</td></tr>
    @empty <tr><td colspan="7"><x-empty text="No active checklists." icon="check"/></td></tr> @endforelse</tbody></table></div></div>
</div>
<div class="tab-pane" id="history">
  <form method="get" class="filters"><div class="field"><label for="f_from">Since</label><input type="date" name="from" id="f_from" value="{{ $from }}" onchange="this.form.submit()"></div></form>
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="runTable">
    <thead><tr><th>Checklist</th><th>Period</th><th>Completed</th><th class="num">Failed</th><th>Signed off</th><th></th></tr></thead>
    <tbody>@forelse($runs as $r)
      <tr data-run="{{ $r->id }}"><td class="bold">{{ $r->checklist->name }}</td><td class="small">{{ $FS::periodLabel($r->checklist, $r->period) }}</td><td class="small">{{ $r->completer?->name }} · {{ $r->completed_at->format('d/m H:i') }}</td>
        <td class="num {{ $r->failed ? 'text-red bold' : '' }}">{{ $r->failed }}</td><td class="small">{!! $r->signed_at ? e($r->signer?->name).' · '.$r->signed_at->format('d/m H:i') : ($r->failed ? '<span class="badge badge-red">awaiting</span>' : '<span class="muted">—</span>') !!}</td>
        <td class="num"><a class="btn btn-sm btn-ghost" href="{{ route('fs.checklists.show', $r) }}">Open</a></td></tr>
    @empty <tr><td colspan="6"><x-empty text="Nothing completed in this period." icon="check"/></td></tr> @endforelse</tbody></table></div></div>
</div>
<div class="tab-pane" id="templates">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="tplTable">
    <thead><tr><th>Checklist</th><th>Frequency</th><th>Area</th><th>Due by</th><th class="num">Items</th><th>Status</th><th></th></tr></thead>
    <tbody>@foreach($all as $c)
      <tr class="{{ $c->is_active ? '' : 'muted' }}"><td class="bold">{{ $c->name }}</td><td>{{ \App\Models\FsChecklist::FREQUENCIES[$c->frequency] }}</td><td class="small">{{ $c->area }}</td><td>{{ $c->due_time ? substr($c->due_time, 0, 5) : '—' }}</td><td class="num">{{ count($c->items) }}</td>
        <td>{!! $c->is_active ? '<span class="badge badge-green">active</span>' : '<span class="badge badge-slate">inactive</span>' !!}</td>
        <td class="num">@if(can_do('foodsafety.manage'))<button class="btn btn-sm btn-ghost btn-icon" title="Edit" data-modal="listModal" data-title="Edit {{ $c->name }}" data-action="{{ route('fs.checklists.update', $c) }}" data-method="put" data-f-name="{{ $c->name }}" data-f-frequency="{{ $c->frequency }}" data-f-area="{{ $c->area }}" data-f-due_time="{{ $c->due_time ? substr($c->due_time, 0, 5) : '' }}" data-f-items="{{ implode("\n", $c->items) }}" data-f-is_active="{{ $c->is_active ? '1' : '0' }}"><x-icon name="edit" size="sm"/></button>@endif</td></tr>
    @endforeach</tbody></table></div></div>
</div>
</div>
@if(can_do('foodsafety.manage'))
<div class="modal" id="listModal"><div class="modal-box" style="max-width:620px"><form method="post" action="{{ route('fs.checklists.store') }}" data-reset>@csrf<input type="hidden" name="_method" value="post" id="listMethod">
  <div class="modal-head"><h3 data-title>Checklist</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="name" label="Name" required placeholder="Kitchen opening checks"/>
    <x-field name="frequency" label="Frequency" type="select" :options="\App\Models\FsChecklist::FREQUENCIES" :placeholder="false" required/>
    <x-field name="area" label="Area" placeholder="Main kitchen"/>
    <x-field name="due_time" label="Due by (daily)" type="time"/>
    <div class="field span-2"><label for="f_items">Items – one per line <span class="req">*</span></label><textarea name="items" id="f_items" rows="8" required placeholder="Hand-wash basins stocked with soap and paper towels&#10;Sanitiser made up at the correct dilution"></textarea></div>
    <div class="field"><label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="listSave">Save</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>document.addEventListener('click', e => { const b = e.target.closest('[data-modal=listModal]'); if (b) setTimeout(() => { document.getElementById('listMethod').value = b.dataset.method || 'post'; }, 0); });</script>
@endpush
