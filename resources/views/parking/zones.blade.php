@extends('layouts.app')
@section('title', 'Parking Zones')
@section('crumbs') Parking &amp; Valet / Zones @endsection
@php $canMg = \App\Services\ParkingService::canManage(auth()->user()); @endphp
@section('actions')@if($canMg)<button class="btn btn-primary" data-modal="pkZoneModal" data-action="{{ route('parking.zones.store') }}" data-title="New zone" data-fis_active="1"><x-icon name="plus"/> New zone</button>@endif @endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="pkZonesTable"><thead><tr><th>Code</th><th>Zone</th><th>Type</th><th class="num">Bays</th><th class="num">Parked</th><th>Occupancy</th><th>Features</th><th></th></tr></thead><tbody>
  @foreach($all as $z)@php $pct = $z->capacity ? round($z->active_sessions_count / $z->capacity * 100) : null; @endphp<tr class="{{ ! $z->is_active ? 'muted' : '' }}">
    <td><b>{{ $z->code }}</b></td><td>{{ $z->name }}@if($z->notes)<div class="tiny muted">{{ $z->notes }}</div>@endif</td><td class="small">{{ $z->typeLabel() }}</td>
    <td class="num">{{ $z->capacity ?: '∞' }}</td><td class="num">{{ $z->active_sessions_count }}</td>
    <td style="min-width:9rem">@if($pct !== null)<div class="dm-bar {{ $pct >= $cfg['full_pct'] ? 'over' : '' }}"><span style="width:{{ min(100, $pct) }}%"></span></div><span class="tiny">{{ $pct }}%</span>@endif</td>
    <td class="small">{{ $z->is_covered ? 'covered' : 'open air' }}{{ $z->ev_points ? ' · '.$z->ev_points.' EV chargers' : '' }}{{ $z->is_active ? '' : ' · closed' }}</td>
    <td>@if($canMg)<button class="btn btn-sm" data-modal="pkZoneModal" data-action="{{ route('parking.zones.update', $z) }}" data-title="Zone {{ $z->code }}" data-fcode="{{ $z->code }}" data-fname="{{ $z->name }}" data-ftype="{{ $z->type }}" data-fcapacity="{{ $z->capacity }}" data-fis_covered="{{ $z->is_covered ? 1 : 0 }}" data-fev_points="{{ $z->ev_points }}" data-fnotes="{{ $z->notes }}" data-fsort="{{ $z->sort }}" data-fis_active="{{ $z->is_active ? 1 : 0 }}">Edit</button>@endif</td></tr>
  @endforeach
</tbody></table></div></div>
@if($canMg)
<div class="modal" id="pkZoneModal"><div class="modal-box"><form method="post" action="{{ route('parking.zones.store') }}" data-reset>@csrf<input type="hidden" name="is_active" value="0">
  <div class="modal-head"><h3 data-title>Zone</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Code</label><input name="code" maxlength="10" required style="text-transform:uppercase"></div>
    <div class="field"><label>Name</label><input name="name" maxlength="80" required></div>
    <div class="field"><label>Type</label><select name="type">@foreach(\App\Models\ParkingZone::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Bays (0 = unlimited)</label><input type="number" name="capacity" min="0" max="5000" value="20" required></div>
    <div class="field"><label>EV chargers</label><input type="number" name="ev_points" min="0" value="0"></div>
    <div class="field"><label>Sort</label><input type="number" name="sort" min="0" value="0"></div>
    <div class="field"><label class="check"><input type="checkbox" name="is_covered" value="1"> Covered</label></div>
    <div class="field"><label class="check"><input type="checkbox" name="is_active" value="1" checked> Open</label></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="255"></div>
  </div><p class="tiny muted">Staff zones take permit holders only; the valet compound takes valet cars only. Capacity is enforced when a ticket is issued.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save zone</button></div>
</form></div></div>
@endif
@endsection
