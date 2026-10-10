@extends('layouts.app')
@section('title', 'Activities & Vendors')
@section('crumbs') Concierge / Catalogue @endsection
@php $A = \App\Models\Activity::class; $V = \App\Models\ActivityVendor::class; $manage = can_do('concierge.manage'); @endphp
@section('actions')
  @if($manage)
    <button class="btn" data-modal="vendorModal" data-title="New operator" data-action="{{ route('concierge.vendor.store') }}" data-f_method="POST" data-fis_active="1" data-fcollection="folio" data-fkind="tour_operator" data-fcommission_pct="15" id="newVendorBtn"><x-icon name="plus"/> Operator</button>
    <button class="btn btn-primary" data-modal="actModalNew" id="newActivityBtn"><x-icon name="plus"/> Activity</button>
  @endif
@endsection
@section('content')
<div class="tabs" data-tabs><button data-tab="tabActivities" class="active">Activities ({{ $activities->count() }})</button><button data-tab="tabVendors">Operators ({{ $vendors->count() }})</button></div>

<div class="tab-pane active" id="tabActivities"><div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="activitiesTable">
  <thead><tr><th>Activity</th><th>Operator</th><th>Runs</th><th class="num">Price</th><th class="num">Comm.</th><th>Paid</th><th>Terms</th><th class="num">Sold</th><th></th></tr></thead>
  <tbody>@forelse($activities as $a)
    <tr data-activity="{{ $a->code }}" class="{{ $a->is_active ? '' : 'muted' }}">
      <td><b>{{ $a->name }}</b><div class="tiny muted">{{ $a->code }} · {{ $A::CATEGORIES[$a->category] ?? $a->category }} · {{ $a->durationLabel() }}{{ $a->portal_visible ? ' · portal' : '' }}{{ $a->instant ? ' · instant' : '' }}{{ $a->is_active ? '' : ' · off sale' }}</div></td>
      <td class="small">{{ $a->vendor?->name }}</td>
      <td class="small">{{ $a->daysLabel() }}<div class="tiny muted">{{ $a->isScheduled() ? implode(', ', $a->timesList()) : 'any time' }}{{ $a->capacity ? ' · '.$a->capacity.' seats' : '' }}{{ $a->min_pax > 1 ? ' · min '.$a->min_pax : '' }}</div></td>
      <td class="num small">{{ $a->priceLabel() }}</td>
      <td class="num">{{ rtrim(rtrim(number_format($a->commissionPct(), 2), '0'), '.') }}%</td>
      <td class="small">{{ $a->collectionMode() === 'folio' ? 'Room bill' : 'Vendor' }}</td>
      <td class="tiny">book {{ $a->cutoff_hours }} h ahead · free cancel {{ $a->free_cancel_hours }} h · fee {{ rtrim(rtrim(number_format((float) $a->cancel_fee_pct, 1), '0'), '.') }}%</td>
      <td class="num">{{ $a->sold }}</td>
      <td class="right nowrap">@if(can_do('concierge.book') && $a->is_active)<a class="btn btn-sm" href="{{ route('concierge.create', ['activity' => $a->id]) }}" title="Book"><x-icon name="plus"/></a>@endif
        @if($manage)<button class="btn btn-sm" data-modal="actModal{{ $a->id }}" title="Edit"><x-icon name="edit"/></button>
        <form method="post" action="{{ route('concierge.activity.delete', $a) }}" class="inline" data-confirm="{{ $a->sold ? 'This activity has bookings – take it off sale?' : 'Delete this activity?' }}">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Delete"><x-icon name="trash"/></button></form>@endif</td>
    </tr>
  @empty<tr><td colspan="9" class="empty">No activities yet – add an operator, then its activities.</td></tr>@endforelse</tbody>
</table></div></div></div>

<div class="tab-pane" id="tabVendors"><div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="vendorsTable">
  <thead><tr><th>Operator</th><th>Contact</th><th>Licence</th><th class="num">Commission</th><th>Guest pays</th><th class="num">Activities</th><th></th></tr></thead>
  <tbody>@forelse($vendors as $v)
    <tr data-vendor="{{ $v->id }}" class="{{ $v->is_active ? '' : 'muted' }}">
      <td><b>{{ $v->name }}</b><div class="tiny muted">{{ $V::KINDS[$v->kind] ?? $v->kind }}{{ $v->is_active ? '' : ' · inactive' }}</div></td>
      <td class="small">{{ $v->contact_name }}<div class="tiny muted">{{ collect([$v->phone, $v->email])->filter()->implode(' · ') }}</div></td>
      <td class="small">{{ $v->licence_no ?: '—' }}@if($v->licence_expires)<div class="tiny {{ $v->licenceExpired() ? '' : 'muted' }}">@if($v->licenceExpired())<span class="badge badge-red">expired</span> @endif{{ $v->licence_expires->format('d M Y') }}</div>@endif</td>
      <td class="num">{{ rtrim(rtrim(number_format((float) $v->commission_pct, 2), '0'), '.') }}%</td>
      <td class="small">{{ $V::COLLECTION[$v->collection] ?? $v->collection }}</td>
      <td class="num">{{ $v->activities_count }}</td>
      <td class="right nowrap">@if($manage)<button class="btn btn-sm" data-modal="vendorModal" data-title="Edit {{ $v->name }}" data-action="{{ route('concierge.vendor.update', $v) }}" data-f_method="PUT"
          data-fname="{{ $v->name }}" data-fkind="{{ $v->kind }}" data-fcontact_name="{{ $v->contact_name }}" data-fphone="{{ $v->phone }}" data-femail="{{ $v->email }}" data-fpayout_details="{{ $v->payout_details }}"
          data-flicence_no="{{ $v->licence_no }}" data-flicence_expires="{{ $v->licence_expires?->toDateString() }}" data-fcommission_pct="{{ (float) $v->commission_pct }}" data-fcollection="{{ $v->collection }}" data-fnotes="{{ $v->notes }}" data-fis_active="{{ $v->is_active ? 1 : 0 }}"><x-icon name="edit"/></button>
        <form method="post" action="{{ route('concierge.vendor.delete', $v) }}" class="inline" data-confirm="Delete {{ $v->name }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Delete"><x-icon name="trash"/></button></form>@endif</td>
    </tr>
  @empty<tr><td colspan="7" class="empty">No operators yet.</td></tr>@endforelse</tbody>
</table></div></div>
<p class="tiny muted mt-1">The hotel sells as agent. Room-bill bookings: the guest's payment is held for the operator (liability) and the operator is paid less commission. Pay-the-vendor bookings: the operator owes the hotel its commission. Both are netted in a settlement.</p>
</div>

@if($manage)
@include('concierge._activity_modal', ['a' => null])
@foreach($activities as $a) @include('concierge._activity_modal', ['a' => $a]) @endforeach

<div class="modal" id="vendorModal"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ route('concierge.vendor.store') }}" data-reset>@csrf <input type="hidden" name="_method" value="POST">
  <div class="modal-head"><h3 data-title>Operator</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Name</label><input name="name" required maxlength="150"></div>
    <div class="field"><label>Type</label><select name="kind">@foreach($V::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Contact person</label><input name="contact_name" maxlength="100"></div>
    <div class="field"><label>Phone</label><input name="phone" maxlength="30"></div>
    <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="150"></div>
    <div class="field"><label>GTA licence no.</label><input name="licence_no" maxlength="60"></div>
    <div class="field"><label>Licence expires</label><input type="date" name="licence_expires"></div>
    <div class="field"><label>Commission %</label><input type="number" step="0.01" min="0" max="60" name="commission_pct" required></div>
    <div class="field"><label>Guest pays</label><select name="collection">@foreach($V::COLLECTION as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Payout details (bank / MoMo)</label><input name="payout_details" maxlength="255"></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="255"></div>
    <label class="small span-all"><input type="checkbox" name="is_active" value="1" checked> Active</label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endif
@endsection
