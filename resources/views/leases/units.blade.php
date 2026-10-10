@extends('layouts.app')
@section('title', 'Lettable Units')
@section('crumbs') <a href="{{ route('leases.index') }}">Apartments &amp; Leases</a> / Units &amp; settings @endsection
@section('actions')
@if(can_do('leases.manage'))<button class="btn btn-primary" data-modal="unitNew" id="addUnit"><x-icon name="plus"/> Add unit</button>@endif
@endsection
@section('content')
<div class="card flush" id="unitsCard"><div class="card-body table-wrap"><table class="table" id="unitsTable">
  <thead><tr><th>Unit</th><th>Type</th><th>Hotel room</th><th>Tax class</th><th class="num">List rent</th><th class="num">Service charge</th><th>Meters</th><th>Status</th><th></th></tr></thead>
  <tbody>@forelse($units as $u)
    <tr data-unit="{{ $u->code }}" class="{{ $u->is_active ? '' : 'muted' }}"><td class="bold">{{ $u->code }}<div class="tiny muted">{{ $u->name }}{{ $u->block ? ' · '.$u->block : '' }}{{ $u->floor !== null ? ' · floor '.$u->floor : '' }}</div></td>
      <td class="small">{{ \App\Models\LeaseUnit::TYPES[$u->type] }}{{ $u->size_sqm ? ' · '.rtrim(rtrim(number_format($u->size_sqm, 2), '0'), '.').' m²' : '' }}{{ $u->furnished ? ' · furnished' : '' }}</td>
      <td class="small">{{ $u->room ? $u->room->room_no.' ('.$u->room->roomType?->name.')' : '—' }}</td><td class="small">{{ ucfirst($u->tax_class) }}</td>
      <td class="num">{{ money($u->list_rent, false) }}</td><td class="num">{{ money($u->service_charge, false) }}</td>
      <td class="tiny">{{ $u->elec_meter ? 'E '.$u->elec_meter : '' }}{{ $u->water_meter ? ' · W '.$u->water_meter : '' }}</td>
      <td>@php $t = ['vacant' => 'green', 'reserved' => 'purple', 'leased' => 'blue', 'maintenance' => 'slate'][$u->status]; @endphp<span class="badge badge-{{ $t }}">{{ \App\Models\LeaseUnit::STATUSES[$u->status] }}</span>@if($u->currentLease)<div class="tiny"><a href="{{ route('leases.show', $u->currentLease) }}">{{ $u->currentLease->lease_no }}</a></div>@endif</td>
      <td class="num">@if(can_do('leases.manage'))<button class="btn btn-sm" data-modal="unit{{ $u->id }}">Edit</button>@endif</td></tr>
  @empty <tr><td colspan="9"><x-empty text="No units yet." icon="building"/></td></tr> @endforelse</tbody></table></div></div>
<p class="tiny muted">Link a unit to a hotel room to let a room as a serviced apartment – while a lease is live the room is taken out of nightly sale (availability, room chart and bookings).</p>

<div class="card mt-1" id="leaseSettings"><div class="card-head"><h3>Lease settings</h3></div><div class="card-body">
  <form method="post" action="{{ route('leases.settings') }}">@csrf<div class="grid g-3" style="gap:.2rem 1rem">
    @foreach($settings as $k => $s)<div class="field"><label for="s_{{ $k }}">{{ $s['label'] }}</label><input type="number" step="any" min="0" name="{{ $k }}" id="s_{{ $k }}" value="{{ $s['value'] }}" @disabled(! can_do('leases.approve'))></div>@endforeach
  </div>@if(can_do('leases.approve'))<button class="btn btn-primary mt-1" id="settingsSave">Save settings</button>@endif</form>
  <p class="tiny muted mt-1">Ghana: residential lettings are VAT-exempt; serviced apartments run with hotel services (reception, housekeeping) attract VAT 15%, NHIL 2.5%, GETFund 2.5% and the 1% Tourism Levy; commercial lets attract VAT, NHIL and GETFund. The Rent Act 1963 (Act 220) s.25(5) caps advance rent on residential tenancies at 6 months (1 month for tenancies of 6 months or less). Company tenants withhold 8% tax on rent paid to a resident landlord.</p>
</div></div>

@if(can_do('leases.manage'))
@foreach($units->prepend(null) as $u)
<div class="modal" id="{{ $u ? 'unit'.$u->id : 'unitNew' }}"><div class="modal-box"><form method="post" action="{{ $u ? route('leases.units.update', $u) : route('leases.units.store') }}">@csrf @if($u) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $u ? 'Edit '.$u->code : 'New unit' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Code <span class="req">*</span></label><input name="code" value="{{ $u?->code }}" required maxlength="20" placeholder="A-101"></div>
    <div class="field"><label>Name <span class="req">*</span></label><input name="name" value="{{ $u?->name }}" required maxlength="100" placeholder="Two-bedroom apartment"></div>
    <div class="field"><label>Type</label><select name="type">@foreach(\App\Models\LeaseUnit::TYPES as $k => $v)<option value="{{ $k }}" @selected($u?->type === $k)>{{ $v }}</option>@endforeach</select></div>
    <div class="field"><label>Tax class</label><select name="tax_class">@foreach(\App\Models\Lease::TAX_CLASSES as $k => $v)<option value="{{ $k }}" @selected(($u?->tax_class ?? 'serviced') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div class="field"><label>Hotel room (optional)</label><select name="room_id"><option value="">— standalone unit —</option>@foreach($rooms as $r)@if(! $linked->contains($r->id) || $u?->room_id === $r->id)<option value="{{ $r->id }}" @selected($u?->room_id === $r->id)>{{ $r->room_no }} – {{ $r->roomType?->name }}</option>@endif @endforeach</select></div>
    <div class="field"><label>Block / building</label><input name="block" value="{{ $u?->block }}" maxlength="40"></div>
    <div class="field"><label>Floor</label><input name="floor" value="{{ $u?->floor }}" maxlength="10"></div>
    <div class="field"><label>Bedrooms</label><input type="number" name="bedrooms" min="0" max="9" value="{{ $u?->bedrooms ?? 1 }}"></div>
    <div class="field"><label>Size (m²)</label><input type="number" step="0.01" name="size_sqm" value="{{ $u?->size_sqm }}"></div>
    <div class="field"><label>List rent / month (GH₵) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="list_rent" value="{{ $u?->list_rent }}" required></div>
    <div class="field"><label>Service charge / month</label><input type="number" step="0.01" min="0" name="service_charge" value="{{ $u?->service_charge ?? 0 }}"></div>
    <div class="field"><label>Electricity meter no.</label><input name="elec_meter" value="{{ $u?->elec_meter }}" maxlength="30"></div>
    <div class="field"><label>Water meter no.</label><input name="water_meter" value="{{ $u?->water_meter }}" maxlength="30"></div>
    <div class="field span-2"><label>Amenities</label><input name="amenities" value="{{ $u?->amenities }}" maxlength="255" placeholder="AC, Wi-Fi, parking, gym access, weekly housekeeping"></div>
    @if(! $u || in_array($u->status, ['vacant', 'maintenance']))<div class="field"><label>Availability</label><select name="status"><option value="vacant" @selected($u?->status !== 'maintenance')>Available to let</option><option value="maintenance" @selected($u?->status === 'maintenance')>Under maintenance</option></select></div>@endif
    <label class="check"><input type="checkbox" name="furnished" value="1" @checked($u?->furnished ?? true)> <span>Furnished</span></label>
    <input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" @checked($u?->is_active ?? true)> <span>Active</span></label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save unit</button></div>
</form></div></div>
@endforeach
@endif
@endsection
