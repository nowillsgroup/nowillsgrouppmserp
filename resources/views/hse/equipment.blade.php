@extends('layouts.app')
@section('title', 'Fire Equipment')
@section('crumbs') Health &amp; Safety / Fire equipment @endsection
@php $canMg = \App\Services\HseService::canManage(auth()->user()); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'hse_equipment']) }}"><x-icon name="download"/> Register</a>
  @if(\App\Services\HseService::canCheck(auth()->user()))<a class="btn" href="{{ route('hse.round') }}"><x-icon name="check"/> Inspection round</a>@endif
  @if($canMg)<button class="btn btn-primary" data-modal="hseEquipModal" data-action="{{ route('hse.equipment.store') }}" data-title="Add fire equipment" id="hseEquipNew"><x-icon name="plus"/> Add equipment</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="In order" :value="$eq['pct'] !== null ? $eq['pct'].'%' : '—'" icon="check" :tone="($eq['pct'] ?? 100) < 95 ? 'red' : 'green'" :sub="$eq['in_order'].' of '.$eq['total'].' items'"/>
  <x-stat label="Overdue" :value="$eq['overdue']" icon="clock" :tone="$eq['overdue'] ? 'red' : 'green'" sub="check or service past due"/>
  <x-stat label="Defective" :value="$eq['defective']" icon="tool" :tone="$eq['defective'] ? 'red' : 'green'" sub="with engineering"/>
  <x-stat label="Expired" :value="$eq['expired']" icon="alert" :tone="$eq['expired'] ? 'red' : 'green'" sub="cylinder / contents / pads"/>
  <x-stat label="Service due · 30 days" :value="$eq['service_due']" icon="calendar" tone="amber" sub="annual contractor service"/>
</div>
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input name="q" value="{{ request('q') }}" placeholder="code, location, GNFS tag" style="max-width:14rem" aria-label="Search">
  <select name="type" aria-label="Type"><option value="">All types</option>@foreach(\App\Models\HseEquipment::TYPES as $k => $t)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $t[0] }}</option>@endforeach</select>
  <select name="floor" aria-label="Floor"><option value="">All floors</option>@foreach($floors as $f)<option value="{{ $f }}" @selected(request('floor') === $f)>{{ $f }}</option>@endforeach</select>
  <select name="state" aria-label="State"><option value="">Any state</option>@foreach(['overdue' => 'Overdue', 'due' => 'Due soon', 'defective' => 'Defective', 'expired' => 'Expired', 'ok' => 'In order'] as $k => $l)<option value="{{ $k }}" @selected(request('state') === $k)>{{ $l }}</option>@endforeach</select>
  <label class="small"><input type="checkbox" name="retired" value="1" @checked(request('retired'))> show retired</label>
  <button class="btn btn-sm">Filter</button>
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="hseEquipTable"><thead><tr><th>Code</th><th>Type</th><th>Location</th><th>GNFS tag</th><th>Last check</th><th>Next check</th><th>Service due</th><th>Expires</th><th>State</th></tr></thead><tbody>
  @forelse($items as $e)<tr class="{{ in_array($e->st, ['overdue', 'defective', 'expired'], true) ? 'row-red' : ($e->st === 'due' ? 'row-amber' : '') }}">
    <td><a href="{{ route('hse.equipment.show', $e) }}"><b>{{ $e->code }}</b></a></td><td class="small">{{ $e->typeLabel() }}{{ $e->capacity ? ' · '.$e->capacity : '' }}</td>
    <td class="small">{{ $e->location }}{{ $e->floor ? ' · '.$e->floor : '' }}</td><td class="mono tiny">{{ $e->gnfs_tag ?? '—' }}</td>
    <td class="small nowrap">{{ $e->last_checked_on?->format('d M Y') ?? '—' }}</td><td class="small nowrap">{{ $e->next_check_on?->format('d M Y') ?? 'now' }}</td>
    <td class="small nowrap">{{ $e->next_service_on?->format('d M Y') ?? '—' }}</td><td class="small nowrap">{{ $e->expires_on?->format('M Y') ?? '—' }}</td>
    <td>@include('hse._state', ['s' => $e->st])</td></tr>
  @empty<tr><td colspan="9" class="muted">No equipment matches.</td></tr>@endforelse
</tbody></table></div></div>
@if($canMg)@include('hse._equip_modal')@endif
@endsection
