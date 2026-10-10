@extends('layouts.app')
@section('title', 'Asset Categories')
@section('crumbs') <a href="{{ route('assets.index') }}">Fixed Assets</a> / Categories @endsection
@section('actions')@if(can_do('assets.manage'))<button class="btn btn-primary" data-modal="catModal" id="newCat"><x-icon name="plus"/> New category</button>@endif @endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="catTable">
<thead><tr><th>Code</th><th>Category</th><th>Depreciation rule</th><th class="num">Residual</th><th class="num">Assets</th><th class="num">Cost</th><th></th></tr></thead><tbody>
@foreach($categories as $c)
<tr class="{{ $c->is_active ? '' : 'muted' }}"><td class="bold">{{ $c->code }}</td><td><a href="{{ route('assets.index', ['category' => $c->id]) }}">{{ $c->name }}</a>@unless($c->is_active) <span class="badge badge-slate">Inactive</span>@endunless</td>
  <td>{{ $c->ruleLabel() }}<div class="tiny muted">{{ $c->method === 'slm' ? $c->life_months.' months' : 'written-down value (WDV)' }}</div></td>
  <td class="num">{{ (float) $c->residual_pct }} %</td><td class="num">{{ $c->assets_count }}</td><td class="num">{{ money($c->assets_sum_cost ?? 0, false) }}</td>
  <td>@if(can_do('assets.manage'))<button class="btn btn-sm" data-modal="catEdit{{ $c->id }}">Edit</button>@endif</td></tr>
@endforeach
</tbody></table></div></div>
<div class="card"><div class="card-body small muted">
  <b>Straight line</b> writes off (cost − residual) evenly over the useful life. <b>Reducing balance (WDV)</b> charges a fixed % of the remaining book value each year – more in the early years, typical for IT and vehicles. The first month is pro-rated by days in service, and no asset is depreciated below its residual value. A category's rule is the default for new assets; each asset keeps its own settings.
</div></div>
@if(can_do('assets.manage'))
@foreach(array_merge([null], $categories->all()) as $c)
<div class="modal" id="{{ $c ? 'catEdit'.$c->id : 'catModal' }}"><div class="modal-box"><form method="post" action="{{ $c ? route('assets.categories.update', $c) : route('assets.categories.store') }}">@csrf @if($c) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $c ? 'Edit '.$c->name : 'New asset category' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="code" label="Code" required :value="$c?->code" maxlength="12"/>
    <x-field name="name" label="Name" required :value="$c?->name"/>
    <x-field name="method" label="Method" type="select" :options="\App\Models\AssetCategory::METHODS" :value="$c?->method ?? 'slm'" :placeholder="false"/>
    <x-field name="life_months" label="Useful life (months, straight line)" type="number" min="1" max="600" :value="$c?->life_months ?? 60"/>
    <x-field name="wdv_rate" label="Rate % a year (reducing balance)" type="number" step="0.01" min="0" max="100" :value="$c ? (float) $c->wdv_rate : null"/>
    <x-field name="residual_pct" label="Residual value (% of cost)" type="number" step="0.01" min="0" max="90" :value="$c ? (float) $c->residual_pct : 5" required/>
    <input type="hidden" name="is_active" value="0"><label class="check span-all"><input type="checkbox" name="is_active" value="1" @checked($c?->is_active ?? true)> Active</label>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endforeach
@endif
@endsection
