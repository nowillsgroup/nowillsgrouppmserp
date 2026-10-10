@extends('layouts.app')
@section('title', 'Preventive Maintenance')
@section('crumbs') Fixed Assets / PM schedules @endsection
@section('actions')
<a class="btn" href="{{ route('maintenance.index', ['status' => 'pm']) }}"><x-icon name="tool"/> PM tickets</a>
<form method="post" action="{{ route('pm.generate') }}" class="inline">@csrf<button class="btn btn-accent" id="pmGenerate"><x-icon name="refresh"/> Raise due tickets{{ $toRaise ? ' ('.$toRaise.')' : '' }}</button></form>
<button class="btn btn-primary" data-modal="pmModal" id="newPm"><x-icon name="plus"/> New schedule</button>
@endsection
@section('content')
@php $cp = $compliance; @endphp
<div class="stats wide">
  <x-stat label="Schedules" :value="$total" icon="calendar" tone="blue" :sub="($counts['paused'] ?? 0).' paused'" href="?show=all"/>
  <x-stat label="Overdue" :value="$counts['overdue'] ?? 0" icon="alert" :tone="($counts['overdue'] ?? 0) ? 'red' : 'green'" sub="past the due date" href="?show=overdue"/>
  <x-stat label="Due now" :value="$counts['due'] ?? 0" icon="clock" tone="amber" sub="within the lead time" href="?show=due"/>
  <x-stat label="On-time completion" :value="$cp['pct'] === null ? '—' : $cp['pct'].' %'" icon="check" :tone="$cp['pct'] !== null && $cp['pct'] < 80 ? 'amber' : 'green'" :sub="'last 90 days · '.$cp['on_time'].' on time, '.$cp['late'].' late, '.$cp['open'].' open'"/>
</div>
<div class="tabs">@foreach(['all' => 'All', 'overdue' => 'Overdue', 'due' => 'Due now', 'scheduled' => 'Scheduled', 'paused' => 'Paused'] as $k => $v)<a href="?show={{ $k }}" class="{{ request('show', 'all') === $k ? 'active' : '' }}">{{ $v }}@if($k !== 'all' && ($counts[$k] ?? 0)) ({{ $counts[$k] }})@endif</a>@endforeach</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="pmTable">
<thead><tr><th>Task</th><th>Asset / location</th><th>Frequency</th><th>Next due</th><th>Last done</th><th>Assigned</th><th>Ticket</th><th></th></tr></thead><tbody>
@forelse($schedules as $s)
@php $st = $s->state(); @endphp
<tr class="{{ $st === 'overdue' ? 'row-warn' : '' }}">
  <td class="bold">{{ $s->title }}@if($s->checklist)<ul class="chk-list">@foreach(array_slice(array_filter(array_map('trim', explode("\n", $s->checklist))), 0, 3) as $c)<li>{{ $c }}</li>@endforeach</ul>@endif</td>
  <td class="small">@if($s->asset)<a href="{{ route('assets.show', $s->asset) }}">{{ $s->asset->name }}</a><div class="tiny muted">{{ $s->asset->asset_no }}{{ $s->asset->placeLabel() ? ' · '.$s->asset->placeLabel() : '' }}</div>@else{{ $s->target() }}@endif</td>
  <td class="small">{{ $s->frequency() }}<div class="tiny muted">ticket {{ $s->lead_days }}d ahead</div></td>
  <td><span class="badge pm-state badge-{{ ['overdue' => 'red', 'due' => 'amber', 'scheduled' => 'green', 'paused' => 'slate'][$st] }}">{{ $s->next_due->format('d M Y') }}</span><div class="tiny muted">{{ $st === 'overdue' ? $s->next_due->diffInDays(bdate()).' days overdue' : ($st === 'paused' ? 'paused' : (($dd = (int) bdate()->diffInDays($s->next_due)) === 0 ? 'today' : ($dd === 1 ? 'tomorrow' : 'in '.$dd.' days'))) }}</div></td>
  <td class="small">{{ $s->last_done?->format('d M Y') ?? '—' }}</td>
  <td class="small">{{ $s->employee?->name ?? ($s->vendor ?: '—') }}</td>
  <td class="small nowrap">@if($s->openTicket)<a href="{{ route('maintenance.index', ['status' => 'pm']) }}">{{ $s->openTicket->ticket_no }}</a><div class="tiny">{!! status_badge($s->openTicket->status) !!}</div>@else<span class="muted">—</span>@endif</td>
  <td class="nowrap">
    <button class="btn btn-sm" data-modal="pmEdit{{ $s->id }}">Edit</button>
    <form method="post" action="{{ route('pm.toggle', $s) }}" class="inline">@csrf<button class="btn btn-sm btn-ghost" title="{{ $s->is_active ? 'Pause' : 'Resume' }}">{{ $s->is_active ? 'Pause' : 'Resume' }}</button></form>
  </td>
</tr>
@empty<tr><td colspan="8"><x-empty text="No preventive-maintenance schedules here." icon="calendar"/></td></tr>@endforelse
</tbody></table></div></div>
<p class="tiny muted mt-1">Tickets are raised automatically each morning (and at night audit) once a task is within its lead time – one open ticket per schedule. Resolving the ticket records the service and moves the schedule to its next due date; missed cycles are skipped rather than piled up.</p>

<div class="modal" id="pmModal"><div class="modal-box"><form method="post" action="{{ route('pm.store') }}">@csrf
  <div class="modal-head"><h3>New PM schedule</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">@include('assets._pm_fields', ['s' => null, 'withTarget' => true])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="savePm">Save schedule</button></div>
</form></div></div>
@foreach($schedules as $s)
<div class="modal" id="pmEdit{{ $s->id }}"><div class="modal-box"><form method="post" action="{{ route('pm.update', $s) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>Edit schedule</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">@include('assets._pm_fields', ['s' => $s, 'withTarget' => true])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endforeach
@endsection
