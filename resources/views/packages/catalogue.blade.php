@extends('layouts.app')
@section('title', 'Package Catalogue & Inclusions')
@section('crumbs') <a href="{{ route('packages.index') }}">Packages & meals</a> / Catalogue @endsection
@php
  $P = \App\Models\Package::class; $manage = can_do('packages.manage');
  $e = request('edit') ? $pkgs->firstWhere('id', (int) request('edit')) : null;
  $f = fn ($k, $def = null) => old($k, $e ? $e->{$k} : $def);
  $cats = config('pms.charge_categories');
@endphp
@section('content')
<div class="tabs" data-tabs>
  <button class="active" data-tab="packages">Packages ({{ $pkgs->count() }})</button>
  <button data-tab="inclusions">Rate plan inclusions</button>
  <button data-tab="settings">Settings</button>
</div>

<div class="tab-pane active" id="packages">
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="pkgTable">
  <thead><tr><th>Package</th><th>Price</th><th>Posting</th><th>Redeemed</th><th>Revenue</th><th class="num">Rate plans</th><th></th></tr></thead>
  <tbody>@forelse($pkgs as $p)
    <tr data-pkg="{{ $p->code }}" class="{{ $p->is_active ? '' : 'muted' }}"><td><b>{{ $p->name }}</b> <span class="tiny muted">{{ $p->code }}</span> @if(! $p->is_active)<span class="badge badge-slate">off</span>@endif @if($p->meal)<span class="badge badge-amber">{{ $P::MEALS[$p->meal] }}</span>@endif
        <div class="tiny muted">{{ $p->description }}</div></td>
      <td class="small">{{ $p->priceLabel() }}@if((float) $p->overage_price > 0)<div class="tiny muted">extra cover {{ money($p->overage_price) }}</div>@endif</td>
      <td class="small">{{ $P::RHYTHMS[$p->rhythm] }}<div class="tiny muted">{{ $P::SERVICE_DAYS[$p->service_day] }}</div></td>
      <td class="small">@if($p->is_allowance){{ $p->redeem_mode === 'amount' ? 'Allowance (amount)' : 'Covers' }}<div class="tiny muted">{{ $p->outlet?->name ?? 'any outlet' }}</div>@else<span class="muted">fixed – no redemption</span>@endif</td>
      <td class="small">{{ $cats[$p->category] ?? $p->category }}<div class="tiny muted">{{ $p->is_sellable ? 'sold as add-on' : 'inclusion only' }}{{ $p->apply_tax ? ' · taxed' : '' }}</div></td>
      <td class="num">{{ $p->rate_plans_count }}</td>
      <td class="nowrap right">@if($manage)
        <a class="btn btn-sm btn-ghost" href="{{ route('packages.catalogue', ['edit' => $p->id]) }}#pkgForm" title="Edit"><x-icon name="edit" size="sm"/></a>
        <form method="post" action="{{ route('packages.toggle', $p) }}" style="display:inline">@csrf<button class="btn btn-sm">{{ $p->is_active ? 'Disable' : 'Enable' }}</button></form>@endif</td></tr>
  @empty <tr><td colspan="7"><x-empty text="No packages yet." icon="coffee"/></td></tr> @endforelse</tbody>
</table></div></div>

@if($manage)
<div class="card" id="pkgForm"><div class="card-head"><h3><x-icon name="{{ $e ? 'edit' : 'plus' }}"/> {{ $e ? 'Edit '.$e->code : 'New package' }}</h3>@if($e)<a class="small" href="{{ route('packages.catalogue') }}">Cancel</a>@endif</div>
<div class="card-body"><form method="post" action="{{ $e ? route('packages.update', $e) : route('packages.store') }}">@csrf @if($e) @method('PUT') @endif
<div class="form-grid">
  <div class="field"><label for="pCode">Code <span class="req">*</span></label><input name="code" id="pCode" value="{{ $f('code') }}" required maxlength="12" placeholder="BRKF"></div>
  <div class="field"><label for="pName">Name <span class="req">*</span></label><input name="name" id="pName" value="{{ $f('name') }}" required maxlength="80" placeholder="Breakfast buffet"></div>
  <div class="field span-2"><label for="pDesc">Description</label><input name="description" id="pDesc" value="{{ $f('description') }}" maxlength="255"></div>
  <div class="field"><label for="pCat">Revenue department</label><select name="category" id="pCat">@foreach($cats as $k => $l)<option value="{{ $k }}" @selected($f('category', 'fnb') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label for="pMeal">Meal</label><select name="meal" id="pMeal"><option value="">— not a meal —</option>@foreach($P::MEALS as $k => $l)<option value="{{ $k }}" @selected($f('meal') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label for="pOutlet">Redeemed at</label><select name="outlet_id" id="pOutlet"><option value="">Any outlet</option>@foreach($outlets as $o)<option value="{{ $o->id }}" @selected((int) $f('outlet_id') === $o->id)>{{ $o->name }}</option>@endforeach</select></div>
  <div class="field"><label for="pCalc">Priced</label><select name="calc" id="pCalc">@foreach($P::CALCS as $k => $l)<option value="{{ $k }}" @selected($f('calc', 'per_person') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label for="pPa">Price per adult / room <span class="req">*</span></label><input type="number" step="0.01" min="0" name="price_adult" id="pPa" value="{{ $f('price_adult') }}" required></div>
  <div class="field"><label for="pPc">Price per child</label><input type="number" step="0.01" min="0" name="price_child" id="pPc" value="{{ $f('price_child', 0) }}"></div>
  <div class="field"><label for="pRh">Earned</label><select name="rhythm" id="pRh">@foreach($P::RHYTHMS as $k => $l)<option value="{{ $k }}" @selected($f('rhythm', 'every_night') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label for="pSd">Used</label><select name="service_day" id="pSd">@foreach($P::SERVICE_DAYS as $k => $l)<option value="{{ $k }}" @selected($f('service_day', 'same_day') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label for="pMode">Redeem by</label><select name="redeem_mode" id="pMode">@foreach($P::MODES as $k => $l)<option value="{{ $k }}" @selected($f('redeem_mode', 'covers') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label for="pOv">Walk-in price per extra cover</label><input type="number" step="0.01" min="0" name="overage_price" id="pOv" value="{{ $f('overage_price', 0) }}"></div>
  <div class="field"><label for="pSort">Sort</label><input type="number" min="0" max="999" name="sort" id="pSort" value="{{ $f('sort', 0) }}"></div>
  <div class="field span-all flex gap-2" style="flex-wrap:wrap">
    <label class="check"><input type="checkbox" name="is_allowance" value="1" @checked($f('is_allowance', true))> <span>Redeemed at an outlet (unused value = breakage)</span></label>
    <label class="check"><input type="checkbox" name="is_sellable" value="1" @checked($f('is_sellable', true))> <span>Can be sold as an add-on</span></label>
    <label class="check"><input type="checkbox" name="apply_tax" value="1" @checked($f('apply_tax', true))> <span>Add-on sales are taxed</span></label>
  </div>
</div>
<div class="right" style="margin-top:.8rem"><button class="btn btn-primary">{{ $e ? 'Save changes' : 'Create package' }}</button></div>
</form></div></div>
@endif
</div>

<div class="tab-pane" id="inclusions">
<form method="post" action="{{ route('packages.inclusions') }}">@csrf
<div class="card flush"><div class="card-head"><h3><x-icon name="layers"/> What each rate includes</h3><span class="small muted">the package value is allocated out of the nightly room tariff – the folio still shows one room line</span></div>
<div class="card-body table-wrap"><table class="table pkg-matrix" id="incMatrix">
  <thead><tr><th>Rate plan</th><th class="num">Rate</th><th>Meal plan</th>@foreach($pkgs->where('is_active', true) as $p)<th class="center">{{ $p->code }}<div class="tiny muted">{{ money($p->price_adult, false) }}</div></th>@endforeach<th class="num">Included value (2A)</th><th class="num">Room only</th></tr></thead>
  <tbody>@foreach($plans as $plan) @php $inc = $plan->packages->pluck('id')->all(); $val = $plan->packages->where('is_active', true)->sum(fn ($p) => $p->quote(2, 0)[1]); @endphp
    <tr data-plan="{{ $plan->code }}"><td class="small"><b>{{ $plan->code }}</b> <span class="muted">{{ $plan->roomType?->name }}</span></td>
      <td class="num small">{{ money($plan->rate, false) }}</td>
      <td><span class="badge badge-{{ $plan->meal_plan === 'EP' ? 'slate' : 'green' }}" title="{{ \App\Models\RatePlan::MEAL_PLANS[$plan->meal_plan] ?? '' }}">{{ $plan->meal_plan }}</span></td>
      @foreach($pkgs->where('is_active', true) as $p)<td class="center"><input type="checkbox" name="inc[{{ $plan->id }}][]" value="{{ $p->id }}" @checked(in_array($p->id, $inc)) @disabled(! $manage) aria-label="{{ $plan->code }} includes {{ $p->code }}"></td>@endforeach
      <td class="num small">{{ $val > 0 ? money($val, false) : '–' }}</td>
      <td class="num small {{ $val > (float) $plan->rate ? 'text-red bold' : '' }}">{{ money(max(0, (float) $plan->rate - $val), false) }}</td></tr>
  @endforeach</tbody>
</table></div></div>
@if($manage)<div class="right"><button class="btn btn-primary" id="saveInclusions">Save inclusions</button></div>@endif
</form>
<p class="small muted">Meal plan codes follow the meals included: breakfast = CP (bed &amp; breakfast), breakfast + lunch or dinner = MAP (half board), all three = AP (full board), none = EP (room only).</p>
</div>

<div class="tab-pane" id="settings">
<div class="card"><div class="card-body"><form method="post" action="{{ route('packages.settings') }}">@csrf
<div class="form-grid">
  <div class="field"><label for="sGrace">{{ \App\Http\Controllers\PackageController::SETTINGS['pkg_grace_days'][1] }}</label><input type="number" min="0" max="3" name="pkg_grace_days" id="sGrace" value="{{ $settings['pkg_grace_days'] }}" @disabled(! $manage)></div>
  <div class="field"><label for="sOv">{{ \App\Http\Controllers\PackageController::SETTINGS['pkg_overage_default'][1] }}</label><select name="pkg_overage_default" id="sOv" @disabled(! $manage)>@foreach(\App\Models\PackageRedemption::OVERAGE as $k => $l)<option value="{{ $k }}" @selected($settings['pkg_overage_default'] === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label for="sBr">{{ \App\Http\Controllers\PackageController::SETTINGS['pkg_breakage_to'][1] }}</label><select name="pkg_breakage_to" id="sBr" @disabled(! $manage)>
    <option value="breakage" @selected($settings['pkg_breakage_to'] === 'breakage')>4092 Package Breakage Income</option>
    <option value="room" @selected($settings['pkg_breakage_to'] === 'room')>Room revenue (back where it came from)</option>
    <option value="package" @selected($settings['pkg_breakage_to'] === 'package')>The package's own department (F&amp;B, spa …)</option></select></div>
</div>
@if($manage)<div class="right" style="margin-top:.8rem"><button class="btn btn-primary">Save settings</button></div>@endif
</form></div></div>
<div class="card small"><div class="card-body"><h3 style="margin-top:0">How packages post</h3>
<ul class="muted">
  <li>The folio always shows the full room tariff. Every night the audit allocates the included packages out of it: <b>Dr Room revenue / Cr 2135 Package allowances</b> (meals, credits) or <b>Cr the package department</b> (fixed inclusions such as Wi-Fi).</li>
  <li>When the restaurant records covers at the Meal Desk the allowance is released: <b>Dr 2135 / Cr F&amp;B revenue</b>. Extras beyond the entitlement are charged to the room at the walk-in price.</li>
  <li>Entitlements not used by their service date (plus grace days) expire in the night audit as breakage: <b>Dr 2135 / Cr the account chosen above</b>.</li>
  <li>Add-on packages post their own folio line (a fixed charge); redeemable add-ons are deferred into 2135 the same way until used.</li>
  <li>If a package is worth more than the room rate (comp or discounted rate) its value is scaled down so room revenue never goes negative – the guest keeps the covers.</li>
</ul></div></div>
</div>
@endsection
