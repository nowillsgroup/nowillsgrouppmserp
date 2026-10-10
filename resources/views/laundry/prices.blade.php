@extends('layouts.app')
@section('title', 'Laundry Price List')
@section('crumbs') <a href="{{ route('laundry.index') }}">Laundry & Linen</a> / Price list @endsection
@section('actions')
@if(can_do('laundry.manage'))<button class="btn btn-primary" data-modal="priceModal" data-fid="" data-fname="" data-fcategory="gents" data-fwash_rate="" data-fdryclean_rate="" data-fpress_rate="" data-fsort_order=""><x-icon name="plus"/> Add item</button>@endif
@endsection
@section('content')
<div class="grid g-2-1">
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="priceTable">
<thead><tr><th>Item</th>@foreach(\App\Models\LaundryItem::SERVICES as $sl)<th class="num">{{ $sl }}</th>@endforeach<th></th></tr></thead><tbody>
@forelse($items as $cat => $rows)
<tr class="group-row"><td colspan="5">{{ \App\Models\LaundryItem::CATEGORIES[$cat] ?? ucfirst($cat) }}</td></tr>
@foreach($rows as $it)
<tr class="{{ $it->is_active ? '' : 'muted' }}"><td>{{ $it->name }}@unless($it->is_active) <span class="badge badge-slate">Not offered</span>@endunless</td>
@foreach(array_keys(\App\Models\LaundryItem::SERVICES) as $sk)<td class="num">{{ $it->rate($sk) === null ? '—' : money($it->rate($sk)) }}</td>@endforeach
<td class="right nowrap">@if(can_do('laundry.manage'))<button class="btn btn-sm btn-ghost" title="Edit" data-modal="priceModal" data-fid="{{ $it->id }}" data-fname="{{ $it->name }}" data-fcategory="{{ $it->category }}" data-fwash_rate="{{ $it->wash_rate }}" data-fdryclean_rate="{{ $it->dryclean_rate }}" data-fpress_rate="{{ $it->press_rate }}" data-fsort_order="{{ $it->sort_order }}"><x-icon name="edit" size="sm"/></button>
<form method="post" action="{{ route('laundry.prices.toggle', $it) }}" class="inline">@csrf<button class="btn btn-sm btn-ghost" title="{{ $it->is_active ? 'Stop offering' : 'Offer again' }}"><x-icon name="{{ $it->is_active ? 'eye' : 'refresh' }}" size="sm"/></button></form>@endif</td></tr>
@endforeach
@empty<tr><td colspan="5"><x-empty text="No laundry items yet." icon="shirt"/></td></tr>@endforelse
</tbody></table></div></div>
<div>
  <div class="card"><div class="card-head"><h3>Laundry settings</h3></div><div class="card-body">
  @if(can_do('laundry.manage'))
  <form method="post" action="{{ route('laundry.settings.save') }}" class="form-grid">@csrf
    <div class="field span-all"><label>Express surcharge (%)</label><input type="number" name="laundry_express_pct" min="0" max="300" step="0.5" class="input" value="{{ old('laundry_express_pct', $v['laundry_express_pct'] ?? 50) }}"></div>
    <div class="field"><label>Normal turnaround (h)</label><input type="number" name="laundry_normal_hours" min="1" max="168" class="input" value="{{ old('laundry_normal_hours', $v['laundry_normal_hours'] ?? 24) }}"></div>
    <div class="field"><label>Express turnaround (h)</label><input type="number" name="laundry_express_hours" min="1" max="48" class="input" value="{{ old('laundry_express_hours', $v['laundry_express_hours'] ?? 6) }}"></div>
    <button class="btn btn-primary span-all"><x-icon name="check"/> Save settings</button>
  </form>
  @else<ul class="list"><li><span>Express surcharge</span><b>{{ $v['laundry_express_pct'] ?? 50 }} %</b></li><li><span>Normal / express</span><b>{{ $v['laundry_normal_hours'] ?? 24 }} h / {{ $v['laundry_express_hours'] ?? 6 }} h</b></li></ul>@endif
  </div></div>
  <div class="card"><div class="card-body small muted">Guests see this price list in the mobile portal when they ask for a laundry pick-up. The laundry tax rate comes from <b>Masters › Taxes</b> (category <i>Laundry</i>).</div></div>
</div>
</div>
@if(can_do('laundry.manage'))
<div class="modal" id="priceModal"><div class="modal-box"><form method="post" action="{{ route('laundry.prices.save') }}">@csrf<input type="hidden" name="id">
  <div class="modal-head"><h3>Laundry item</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field span-2"><label>Name</label><input name="name" class="input" required maxlength="80"></div>
    <div class="field"><label>Category</label><select name="category" class="input">@foreach(\App\Models\LaundryItem::CATEGORIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Wash & iron</label><input type="number" name="wash_rate" min="0" step="0.01" class="input" placeholder="not offered"></div>
    <div class="field"><label>Dry clean</label><input type="number" name="dryclean_rate" min="0" step="0.01" class="input" placeholder="not offered"></div>
    <div class="field"><label>Press only</label><input type="number" name="press_rate" min="0" step="0.01" class="input" placeholder="not offered"></div>
    <div class="field"><label>Sort order</label><input type="number" name="sort_order" min="0" max="999" class="input"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endif
@endsection
