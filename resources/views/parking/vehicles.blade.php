@extends('layouts.app')
@section('title', 'Vehicles')
@section('crumbs') Parking &amp; Valet / Vehicles @endsection
@php $canOp = \App\Services\ParkingService::canOperate(auth()->user()); $canMg = \App\Services\ParkingService::canManage(auth()->user()); @endphp
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input name="q" value="{{ request('q') }}" placeholder="plate, owner, make" style="max-width:14rem" aria-label="Search">
  <select name="view" aria-label="View"><option value="">All vehicles</option><option value="banned" @selected(request('view') === 'banned')>Banned ({{ $banned }})</option></select>
  <button class="btn btn-sm">Filter</button><span class="tiny muted">Vehicles are added automatically the first time they park or get a permit.</span>
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="pkVehiclesTable"><thead><tr><th>Plate</th><th>Vehicle</th><th>Owner</th><th>Phone</th><th class="num">Visits</th><th>Last seen</th><th>Now</th><th></th></tr></thead><tbody>
  @forelse($vehicles as $v)<tr class="{{ $v->is_banned ? 'row-red' : '' }}">
    <td class="mono"><b>{{ $v->plate_display }}</b></td><td class="small">{{ $v->description() }}</td><td class="small">{{ $v->guest?->full_name ?? $v->owner_name ?? '—' }}</td><td class="small">{{ $v->phone }}</td>
    <td class="num">{{ $v->visits }}</td><td class="small">{{ $v->last_seen_at?->format('d M Y') }}</td>
    <td class="small">@if(isset($inside[$v->id]))<span class="badge badge-blue">{{ $inside[$v->id] }}</span>@endif @if($v->is_banned)<span class="badge badge-red" title="{{ $v->ban_reason }}">banned</span>@endif</td>
    <td class="nowrap">@if($canOp)<button class="btn btn-sm" data-modal="pkVehicleModal" data-action="{{ route('parking.vehicle.update', $v) }}" data-title="Vehicle {{ $v->plate_display }}" data-fmake="{{ $v->make }}" data-fmodel="{{ $v->model }}" data-fcolour="{{ $v->colour }}" data-fvehicle_type="{{ $v->type }}" data-fowner_name="{{ $v->owner_name }}" data-fphone="{{ $v->phone }}" data-fnotes="{{ $v->notes }}">Edit</button>@endif
      @if($canMg)@if($v->is_banned)<form method="post" action="{{ route('parking.vehicle.ban', $v) }}" class="inline">@csrf<input type="hidden" name="do" value="unban"><button class="btn btn-sm">Lift ban</button></form>@else<button class="btn btn-sm" data-modal="pkBanModal" data-action="{{ route('parking.vehicle.ban', $v) }}" data-title="Ban {{ $v->plate_display }}">Ban</button>@endif @endif</td></tr>
  @empty<tr><td colspan="8" class="muted">No vehicles.</td></tr>@endforelse
</tbody></table></div></div>
{{ $vehicles->links() }}
@if($canOp)
<div class="modal" id="pkVehicleModal"><div class="modal-box"><form method="post" action="">@csrf
  <div class="modal-head"><h3 data-title>Vehicle</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Make</label><input name="make" maxlength="40"></div><div class="field"><label>Model</label><input name="model" maxlength="40"></div>
    <div class="field"><label>Colour</label><input name="colour" maxlength="30"></div>
    <div class="field"><label>Type</label><select name="vehicle_type">@foreach(\App\Models\ParkingVehicle::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Owner</label><input name="owner_name" maxlength="120"></div><div class="field"><label>Phone</label><input name="phone" maxlength="30"></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="255"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endif
@if($canMg)
<div class="modal" id="pkBanModal"><div class="modal-box"><form method="post" action="">@csrf<input type="hidden" name="do" value="ban">
  <div class="modal-head"><h3 data-title>Ban vehicle</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="reason" maxlength="255" required placeholder="unpaid parking, security incident INC-…"></div><p class="tiny muted">A banned vehicle cannot be issued a ticket; the gate lookup shows the reason.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Ban</button></div>
</form></div></div>
@endif
@endsection
