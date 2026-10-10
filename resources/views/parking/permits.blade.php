@extends('layouts.app')
@section('title', 'Parking Permits')
@section('crumbs') Parking &amp; Valet / Permits @endsection
@section('actions')@if($canManage)<button class="btn btn-primary" data-modal="pkPermitModal" data-action="{{ route('parking.permits.store') }}" data-title="New parking permit" id="pkPermitNew"><x-icon name="plus"/> New permit</button>@endif @endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input name="q" value="{{ request('q') }}" placeholder="holder, permit, plate" style="max-width:14rem" aria-label="Search">
  <select name="view" aria-label="View"><option value="">All permits</option>@foreach(['active' => 'Valid today', 'expiring' => 'Expiring in 7 days', 'expired' => 'Expired / revoked'] as $k => $l)<option value="{{ $k }}" @selected(request('view') === $k)>{{ $l }}</option>@endforeach</select>
  <button class="btn btn-sm">Filter</button><span class="tiny muted">Permit holders park free in their zone while the permit is valid – park them as "Staff / permit".</span>
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="pkPermitsTable"><thead><tr><th>Permit</th><th>Holder</th><th>Type</th><th>Vehicle</th><th>Zone</th><th>Valid</th><th class="num">Fee</th><th>State</th><th></th></tr></thead><tbody>
  @forelse($permits as $p)@php [$sl, $st] = $p->stateBadge(); @endphp<tr class="{{ $p->state() === 'active' && $p->valid_to->lte(bdate()->copy()->addDays(7)) ? 'row-amber' : '' }}">
    <td><b>{{ $p->number }}</b></td><td>{{ $p->holder_name }}@if($p->employee)<div class="tiny muted">{{ $p->employee->emp_code }}</div>@endif</td><td class="small">{{ \App\Models\ParkingPermit::HOLDERS[$p->holder_type] ?? $p->holder_type }}</td>
    <td class="mono small">{{ $p->vehicle->plate_display }}<div class="tiny muted">{{ $p->vehicle->description() }}</div></td><td class="small">{{ $p->zone?->code ?? 'any' }}</td>
    <td class="small">{{ $p->valid_from->format('d M Y') }} – {{ $p->valid_to->format('d M Y') }}</td><td class="num">{{ (float) $p->fee > 0 ? money($p->fee) : '' }}</td>
    <td><span class="badge badge-{{ $st }}" @if($p->revoke_reason) title="{{ $p->revoke_reason }}" @endif>{{ $sl }}</span></td>
    <td class="nowrap">@if($canManage && $p->status === 'active')<button class="btn btn-sm" data-modal="pkPermitModal" data-action="{{ route('parking.permits.update', $p) }}" data-title="Permit {{ $p->number }}" data-fplate="{{ $p->vehicle->plate_display }}" data-fholder_name="{{ $p->holder_name }}" data-fholder_type="{{ $p->holder_type }}" data-femployee_id="{{ $p->employee_id }}" data-fparking_zone_id="{{ $p->parking_zone_id }}" data-fvalid_from="{{ $p->valid_from->toDateString() }}" data-fvalid_to="{{ $p->valid_to->toDateString() }}" data-ffee="{{ $p->fee }}" data-fnotes="{{ $p->notes }}">Edit / renew</button>
      <button class="btn btn-sm" data-modal="pkRevokeModal" data-action="{{ route('parking.permits.revoke', $p) }}" data-title="Revoke {{ $p->number }}">Revoke</button>@endif</td></tr>
  @empty<tr><td colspan="9" class="muted">No permits.</td></tr>@endforelse
</tbody></table></div></div>
{{ $permits->links() }}
@if($canManage)
<div class="modal" id="pkPermitModal"><div class="modal-box"><form method="post" action="{{ route('parking.permits.store') }}" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Parking permit</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Number plate</label><input name="plate" maxlength="20" required style="text-transform:uppercase"></div>
    <div class="field"><label>Holder</label><input name="holder_name" maxlength="120" required></div>
    <div class="field"><label>Holder type</label><select name="holder_type">@foreach(\App\Models\ParkingPermit::HOLDERS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Employee</label><select name="employee_id"><option value="">—</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->emp_code }})</option>@endforeach</select></div>
    <div class="field"><label>Zone</label><select name="parking_zone_id"><option value="">Any zone</option>@foreach($zones as $z)<option value="{{ $z->id }}">{{ $z->code }} · {{ $z->name }}</option>@endforeach</select></div>
    <div class="field"><label>Fee (recorded)</label><input type="number" name="fee" min="0" step="0.01" value="0"></div>
    <div class="field"><label>Valid from</label><input type="date" name="valid_from" value="{{ bdate()->toDateString() }}" required></div>
    <div class="field"><label>Valid to</label><input type="date" name="valid_to" value="{{ bdate()->copy()->addMonths(6)->toDateString() }}" required></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="255"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save permit</button></div>
</form></div></div>
<div class="modal" id="pkRevokeModal"><div class="modal-box"><form method="post" action="">@csrf
  <div class="modal-head"><h3 data-title>Revoke permit</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="reason" maxlength="190" required placeholder="left employment, vehicle sold…"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Revoke</button></div>
</form></div></div>
@endif
@endsection
