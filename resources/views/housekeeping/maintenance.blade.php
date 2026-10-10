@extends('layouts.app')
@section('title', 'Maintenance Tickets')
@section('actions')<a class="btn" href="{{ route('pm.index') }}"><x-icon name="calendar"/> PM schedules</a> <button class="btn btn-primary" data-modal="tModal"><x-icon name="plus"/> New Ticket</button>@endsection
@section('content')
<div class="tabs">@foreach(['active'=>'Active','pm'=>'Preventive','overdue'=>'Overdue','open'=>'Open','in_progress'=>'In progress','resolved'=>'Resolved','closed'=>'Closed','all'=>'All'] as $k=>$v)<a href="?status={{ $k }}{{ request('asset') ? '&asset='.request('asset') : '' }}" class="{{ request('status','active')===$k ? 'active' : '' }}">{{ $v }}@if($k==='overdue' && $pmOverdue) <span class="nav-badge">{{ $pmOverdue }}</span>@endif</a>@endforeach</div>
@if(request('asset') && ($fa = \App\Models\Asset::find(request('asset'))))<div class="alert alert-info">Showing tickets for <a class="bold" href="{{ route('assets.show', $fa) }}">{{ $fa->asset_no }} {{ $fa->name }}</a> · <a href="?status={{ request('status','active') }}">clear filter</a></div>@endif
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="mntTable">
<thead><tr><th>Ticket</th><th>Location</th><th>Issue</th><th>Category</th><th>Priority</th><th>Assigned</th><th>Status</th><th>Logged</th><th></th></tr></thead><tbody>
@forelse($tickets as $t)
@php $late = $t->isOpen() && $t->due_date && $t->due_date->lt(bdate()); @endphp
<tr class="{{ $late ? 'row-warn' : '' }}"><td class="bold">{{ $t->ticket_no }}@if($t->isPm())<div><span class="badge badge-blue" title="Preventive maintenance">PM</span></div>@endif</td><td>{{ $t->room ? 'Room '.$t->room->room_no : $t->location }} @if($t->block_room)<span class="badge badge-slate">Blocked</span>@endif
@if($t->asset)<div class="tiny">@if(can_do('assets.view'))<a href="{{ route('assets.show', $t->asset) }}">{{ $t->asset->asset_no }}</a>@else{{ $t->asset->asset_no }}@endif · {{ \Illuminate\Support\Str::limit($t->asset->name, 28) }}</div>@endif</td>
<td>{{ $t->title }}@if($t->due_date)<div class="tiny {{ $late ? 'text-red bold' : 'muted' }}">Due {{ $t->due_date->format('d M Y') }}{{ $late ? ' · overdue '.$t->due_date->diffInDays(bdate()).'d' : '' }}</div>@endif<div class="tiny muted">{{ \Illuminate\Support\Str::limit($t->description, 80) }}</div>@if($t->resolution)<div class="tiny text-green">✔ {{ $t->resolution }}</div>@endif</td>
<td>{{ ucfirst($t->category) }}</td><td>{!! in_array($t->priority,['high','urgent']) ? '<span class="badge badge-red">'.ucfirst($t->priority).'</span>' : ucfirst($t->priority) !!}</td>
<td>{{ $t->employee?->name ?? '—' }}</td><td>{!! status_badge($t->status) !!}</td><td class="small">{{ $t->created_at->format('d M H:i') }}<div class="tiny muted">{{ $t->reporter?->name }}</div></td>
<td><button class="btn btn-sm" data-modal="uModal" data-action="{{ route('maintenance.update', $t) }}" data-fstatus="{{ $t->status }}" data-femployee_id="{{ $t->employee_id }}">Update</button></td></tr>
@empty<tr><td colspan="9"><x-empty text="No tickets." icon="tool"/></td></tr>@endforelse
</tbody></table>{{ $tickets->links() }}</div></div>

<div class="modal" id="tModal"><div class="modal-box"><form method="post" action="{{ route('maintenance.store') }}">@csrf
  <div class="modal-head"><h3>New maintenance ticket</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="room_id" label="Room" type="select" :options="$rooms"/><x-field name="location" label="Or location"/>
    <x-field name="asset_id" label="Asset (optional)" type="select" :options="$assets" class="span-all"/>
    <x-field name="title" label="Issue" required class="span-all"/>
    <x-field name="category" label="Category" type="select" :options="['general'=>'General','electrical'=>'Electrical','plumbing'=>'Plumbing','ac'=>'AC / HVAC','carpentry'=>'Carpentry','it'=>'IT / TV / Wi-Fi','civil'=>'Civil']" :placeholder="false"/>
    <x-field name="priority" label="Priority" type="select" :options="['normal'=>'Normal','low'=>'Low','high'=>'High','urgent'=>'Urgent']" :placeholder="false"/>
    <x-field name="employee_id" label="Assign technician" type="select" :options="$staff"/>
    <x-field name="description" label="Details" type="textarea" class="span-all"/>
    <x-field name="due_date" label="Fix by (optional)" type="date"/>
    <div></div>
    <label class="check span-all"><input type="checkbox" name="block_room" value="1"> Block room (set Out of Order until resolved)</label>
    <label class="check span-all"><input type="checkbox" name="asset_down" value="1"> Asset is out of service (mark it Under repair until the ticket is resolved)</label>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Log Ticket</button></div>
</form></div></div>
<div class="modal" id="uModal"><div class="modal-box"><form method="post" action="">@csrf
  <div class="modal-head"><h3>Update ticket</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="status" label="Status" type="select" :options="['open'=>'Open','in_progress'=>'In progress','resolved'=>'Resolved','closed'=>'Closed']" :placeholder="false"/>
    <x-field name="employee_id" label="Technician" type="select" :options="$staff"/>
    <x-field name="cost" label="Cost incurred" type="number" step="0.01"/>
    <x-field name="resolution" label="Resolution notes" type="textarea" class="span-all"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endsection
