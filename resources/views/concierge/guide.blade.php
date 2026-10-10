@extends('layouts.app')
@section('title', 'Local Guide')
@section('crumbs') Concierge / Local guide @endsection
@php $P = \App\Models\ConciergePlace::class; $manage = can_do('concierge.manage'); @endphp
@section('actions')
  <a class="btn" href="{{ route('concierge.guide.print') }}" target="_blank"><x-icon name="print"/> Guest sheet</a>
  @if($manage)<button class="btn btn-primary" data-modal="placeModal" data-title="New place" data-action="{{ route('concierge.place.store') }}" data-f_method="POST" data-fportal_visible="1" id="newPlaceBtn"><x-icon name="plus"/> Place</button>@endif
@endsection
@section('content')
<p class="small muted mt-0">Recommendations the desk gives guests – sights, markets, beaches, restaurants, hospitals, airport and emergency numbers. Places marked "portal" also appear on the guest portal and the printed guest sheet.</p>
@forelse($P::CATEGORIES as $cat => $label) @continue(! isset($places[$cat]))
<div class="card flush mb-1" data-cat="{{ $cat }}"><div class="card-head"><h3><x-icon name="{{ $P::ICONS[$cat] ?? 'globe' }}"/> {{ $label }}</h3></div><div class="card-body table-wrap"><table class="table table-compact">
  <tbody>@foreach($places[$cat] as $p)
    <tr data-place="{{ $p->id }}"><td style="width:28%"><b>{{ $p->name }}</b>@if($p->price_level) <span class="tiny muted">{{ str_repeat('₵', $p->price_level) }}</span>@endif<div class="tiny muted">{{ collect([$p->area, $p->distance_km !== null ? rtrim(rtrim(number_format((float) $p->distance_km, 1), '0'), '.').' km' : null])->filter()->implode(' · ') }}{{ $p->portal_visible ? '' : ' · desk only' }}</div></td>
      <td class="small">{{ $p->description }}@if($p->tip)<div class="tiny" style="color:var(--c-primary)">Tip: {{ $p->tip }}</div>@endif</td>
      <td class="small" style="width:22%">{{ $p->hours }}<div class="tiny muted">{{ $p->phone }}{{ $p->address ? ' · '.$p->address : '' }}</div></td>
      <td class="right nowrap">@if($manage)<button class="btn btn-sm" data-modal="placeModal" data-title="Edit {{ $p->name }}" data-action="{{ route('concierge.place.update', $p) }}" data-f_method="PUT"
        data-fname="{{ $p->name }}" data-fcategory="{{ $p->category }}" data-farea="{{ $p->area }}" data-faddress="{{ $p->address }}" data-fphone="{{ $p->phone }}" data-fwebsite="{{ $p->website }}" data-fdistance_km="{{ $p->distance_km }}"
        data-fhours="{{ $p->hours }}" data-fprice_level="{{ $p->price_level }}" data-fdescription="{{ $p->description }}" data-ftip="{{ $p->tip }}" data-fportal_visible="{{ $p->portal_visible ? 1 : 0 }}" data-fsort_order="{{ $p->sort_order }}"><x-icon name="edit"/></button>
        <form method="post" action="{{ route('concierge.place.delete', $p) }}" class="inline" data-confirm="Remove {{ $p->name }} from the guide?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost"><x-icon name="trash"/></button></form>@endif</td></tr>
  @endforeach</tbody>
</table></div></div>
@empty @endforelse
@if($places->isEmpty())<div class="card"><div class="card-body empty">The guide is empty – add the places your concierge recommends.</div></div>@endif

@if($manage)
<div class="modal" id="placeModal"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ route('concierge.place.store') }}" data-reset>@csrf <input type="hidden" name="_method" value="POST">
  <div class="modal-head"><h3 data-title>Place</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Name</label><input name="name" required maxlength="150"></div>
    <div class="field"><label>Category</label><select name="category">@foreach($P::CATEGORIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Area</label><input name="area" maxlength="100" placeholder="Osu"></div>
    <div class="field span-all"><label>Address</label><input name="address" maxlength="190"></div>
    <div class="field"><label>Phone</label><input name="phone" maxlength="30"></div>
    <div class="field"><label>Website</label><input type="url" name="website" maxlength="190"></div>
    <div class="field"><label>Distance from hotel (km)</label><input type="number" step="0.1" min="0" name="distance_km"></div>
    <div class="field"><label>Opening hours</label><input name="hours" maxlength="120"></div>
    <div class="field"><label>Price level</label><select name="price_level"><option value="">—</option>@for($i = 1; $i <= 4; $i++)<option value="{{ $i }}">{{ str_repeat('₵', $i) }}</option>@endfor</select></div>
    <div class="field"><label>Sort order</label><input type="number" min="0" name="sort_order" value="0"></div>
    <div class="field span-all"><label>Description</label><textarea name="description" rows="2" maxlength="500"></textarea></div>
    <div class="field span-all"><label>Concierge tip</label><input name="tip" maxlength="255"></div>
    <label class="small span-all"><input type="checkbox" name="portal_visible" value="1" checked> Show on the guest portal and guest sheet</label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endif
@endsection
