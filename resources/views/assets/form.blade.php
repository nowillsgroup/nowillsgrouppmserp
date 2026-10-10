@extends('layouts.app')
@section('title', $a->exists ? 'Edit '.$a->asset_no : 'Add Asset')
@section('crumbs') <a href="{{ route('assets.index') }}">Asset register</a> / {{ $a->exists ? $a->asset_no : 'New asset' }} @endsection
@section('content')
@php $via = old('acquired_via', $a->acquired_via ?? 'direct'); $d = fn ($v) => $v instanceof \Carbon\Carbon ? $v->toDateString() : $v; @endphp
<form method="post" action="{{ $a->exists ? route('assets.update', $a) : route('assets.store') }}" id="assetForm">@csrf @if($a->exists) @method('PUT') @endif
<div class="grid g-2-1">
<div>
  <div class="card"><div class="card-head"><h3>Asset</h3></div><div class="card-body form-grid">
    <x-field name="name" label="Name / description" required :value="$a->name" class="span-all" placeholder="e.g. Split AC 1.5 T – Room 101"/>
    @if($locked)
      <div class="field"><label>Category</label><input class="input" value="{{ $a->category?->name }}" readonly><input type="hidden" name="asset_category_id" value="{{ $a->asset_category_id }}"></div>
    @else
      <div class="field"><label for="f_asset_category_id">Category <span class="req">*</span></label>
        <select name="asset_category_id" id="f_asset_category_id" required class="@error('asset_category_id') is-invalid @enderror"><option value="">— Select —</option>
          @foreach($categories as $c)<option value="{{ $c->id }}" data-method="{{ $c->method }}" data-life="{{ $c->life_months }}" data-rate="{{ (float) $c->wdv_rate }}" data-res="{{ (float) $c->residual_pct }}" @selected((string) old('asset_category_id', $a->asset_category_id) === (string) $c->id)>{{ $c->name }} – {{ $c->ruleLabel() }}</option>@endforeach
        </select>@error('asset_category_id')<span class="err">{{ $message }}</span>@enderror</div>
    @endif
    <x-field name="serial_no" label="Serial no." :value="$a->serial_no"/>
    <x-field name="make" label="Make / brand" :value="$a->make"/>
    <x-field name="model" label="Model" :value="$a->model"/>
  </div></div>

  <div class="card"><div class="card-head"><h3>Where is it?</h3></div><div class="card-body form-grid">
    <x-field name="room_id" label="Room" type="select" :options="$rooms" :value="$a->room_id"/>
    <x-field name="location" label="Or area / location" :value="$a->location" placeholder="e.g. Main kitchen, Lobby, Roof"/>
    <x-field name="department" label="Department" type="select" :options="array_combine(\App\Models\Asset::DEPARTMENTS, \App\Models\Asset::DEPARTMENTS)" :value="$a->department"/>
    <x-field name="custodian_id" label="Custodian (responsible)" type="select" :options="$staff" :value="$a->custodian_id"/>
  </div></div>

  <div class="card"><div class="card-head"><h3>Acquisition</h3>@if($locked)<span class="badge badge-slate">Locked – depreciated</span>@endif</div><div class="card-body">
    @if($locked)
      @foreach(['acquired_via', 'supplier_invoice_id', 'payment_mode_id', 'method', 'life_months', 'wdv_rate'] as $f)<input type="hidden" name="{{ $f }}" value="{{ $a->$f }}">@endforeach
      @foreach(['purchase_date', 'dep_start'] as $f)<input type="hidden" name="{{ $f }}" value="{{ $a->$f?->toDateString() }}">@endforeach
      @foreach(['cost', 'residual_value', 'opening_acc_dep'] as $f)<input type="hidden" name="{{ $f }}" value="{{ $a->$f }}">@endforeach
      <div class="kv-grid">
        <div><span>Acquired</span><b>{{ \App\Models\Asset::VIA[$a->acquired_via] }}</b></div>
        <div><span>Purchased</span><b>{{ $a->purchase_date->format('d M Y') }}</b><span class="tiny muted">depreciated from {{ $a->dep_start->format('d M Y') }}</span></div>
        <div><span>Cost</span><b>{{ money($a->cost) }}</b><span class="tiny muted">residual {{ money($a->residual_value) }}</span></div>
        <div><span>Method</span><b>{{ $a->method === 'wdv' ? 'Reducing balance '.(float) $a->wdv_rate.' %' : 'Straight line, '.$a->life_months.' months' }}</b></div>
      </div>
      <p class="tiny muted mb-0">The cost, dates and depreciation settings are frozen once the asset has been depreciated. Reverse the depreciation runs to change them.</p>
    @else
    <div class="seg mb-1" id="viaSeg">@foreach(\App\Models\Asset::VIA as $k => $v)<label class="check"><input type="radio" name="acquired_via" value="{{ $k }}" @checked($via === $k)> {{ $v }}</label>@endforeach</div>
    <div class="form-grid">
      <x-field name="purchase_date" label="Purchase date" type="date" required :value="$d($a->purchase_date)"/>
      <x-field name="cost" label="Cost (capitalised amount)" type="number" step="0.01" min="0.01" required :value="$a->cost" hint="Price + freight + installation, excluding recoverable tax"/>
      <div class="field via via-direct"><label for="f_payment_mode_id">Paid from <span class="req">*</span></label><select name="payment_mode_id" id="f_payment_mode_id"><option value="">— Select —</option>@foreach($modes as $id => $n)<option value="{{ $id }}" @selected((string) old('payment_mode_id', $a->payment_mode_id) === (string) $id)>{{ $n }}</option>@endforeach</select><span class="hint">Posts Dr Fixed assets / Cr this account</span></div>
      <div class="field via via-direct"><label for="f_supplier_id">Supplier</label><select name="supplier_id" id="f_supplier_id"><option value="">— None —</option>@foreach($suppliers as $id => $n)<option value="{{ $id }}" @selected((string) old('supplier_id', $a->supplier_id) === (string) $id)>{{ $n }}</option>@endforeach</select></div>
      <div class="field via via-bill span-all"><label for="f_supplier_invoice_id">Supplier bill <span class="req">*</span></label><select name="supplier_invoice_id" id="f_supplier_invoice_id"><option value="">— Select a bill with a fixed-asset line —</option>@foreach($bills as $id => $n)<option value="{{ $id }}" @selected((string) old('supplier_invoice_id', $a->supplier_invoice_id) === (string) $id)>{{ $n }}</option>@endforeach</select>
        <span class="hint">The bill already posted Dr Fixed assets / Cr Accounts payable, so no extra journal is made. Only bills with a line coded to <i>Furniture, Fixtures &amp; Equipment</i> are listed.</span></div>
      <div class="field via via-opening"><label for="f_opening_acc_dep">Accumulated depreciation brought in</label><input type="number" step="0.01" min="0" name="opening_acc_dep" id="f_opening_acc_dep" value="{{ old('opening_acc_dep', $a->opening_acc_dep) }}"><span class="hint">Depreciation already charged before go-live. Opening assets are part of the opening balances – no journal is made.</span></div>
      <x-field name="dep_start" label="Depreciate from" type="date" :value="$d($a->dep_start)" hint="Blank = purchase date (opening assets: your go-live date)"/>
      <x-field name="reference" label="Invoice / reference" :value="$a->reference"/>
    </div>
    @endif
  </div></div>
</div>
<div>
  <div class="card"><div class="card-head"><h3>Depreciation</h3></div><div class="card-body form-grid one">
    @unless($locked)
    <x-field name="method" label="Method" type="select" :options="\App\Models\AssetCategory::METHODS" :value="$a->method" :placeholder="false"/>
    <div class="field m-slm"><label for="f_life_months">Useful life (months)</label><input type="number" name="life_months" id="f_life_months" min="1" max="600" value="{{ old('life_months', $a->life_months) }}"><span class="hint" id="lifeHint"></span></div>
    <div class="field m-wdv"><label for="f_wdv_rate">Rate (% a year, on the reducing balance)</label><input type="number" name="wdv_rate" id="f_wdv_rate" step="0.01" min="0" max="100" value="{{ old('wdv_rate', (float) $a->wdv_rate ?: null) }}"></div>
    <div class="field"><label for="f_residual_value">Residual (salvage) value</label><input type="number" name="residual_value" id="f_residual_value" step="0.01" min="0" value="{{ old('residual_value', $a->exists ? $a->residual_value : null) }}"><span class="hint" id="resHint">Blank = category default</span></div>
    <div class="small muted" id="depPreview"></div>
    @else
    <p class="small muted m-0">Book value {{ money($a->nbv()) }} after {{ money($a->accumulated()) }} accumulated depreciation.</p>
    @endunless
  </div></div>
  <div class="card"><div class="card-head"><h3>Warranty &amp; AMC</h3></div><div class="card-body form-grid one">
    <x-field name="warranty_until" label="Warranty until" type="date" :value="$d($a->warranty_until)"/>
    <x-field name="amc_vendor" label="AMC / service vendor" :value="$a->amc_vendor"/>
    <x-field name="amc_until" label="AMC valid until" type="date" :value="$d($a->amc_until)"/>
    <x-field name="notes" label="Notes" type="textarea" :value="$a->notes"/>
  </div></div>
  <div class="flex gap-1 justify-end"><a class="btn" href="{{ $a->exists ? route('assets.show', $a) : route('assets.index') }}">Cancel</a><button class="btn btn-primary" id="saveAsset"><x-icon name="check"/> {{ $a->exists ? 'Save changes' : 'Add to register' }}</button></div>
</div>
</div>
</form>
@unless($locked)
<script>
(function () {
  const f = document.getElementById('assetForm'), cat = document.getElementById('f_asset_category_id'), method = document.getElementById('f_method');
  const life = document.getElementById('f_life_months'), rate = document.getElementById('f_wdv_rate'), res = document.getElementById('f_residual_value'), cost = document.getElementById('f_cost');
  const fmt = n => Number(n).toLocaleString(undefined, {maximumFractionDigits: 2});
  function via() {
    const v = (f.querySelector('input[name=acquired_via]:checked') || {}).value;
    f.querySelectorAll('.via').forEach(el => el.style.display = el.classList.contains('via-' + v) ? '' : 'none');
  }
  function meth() {
    f.querySelectorAll('.m-slm').forEach(el => el.style.display = method.value === 'slm' ? '' : 'none');
    f.querySelectorAll('.m-wdv').forEach(el => el.style.display = method.value === 'wdv' ? '' : 'none');
    preview();
  }
  function fromCat(force) {
    const o = cat && cat.selectedOptions[0];
    if (!o || !o.value) return;
    if (force || !method.dataset.touched) method.value = o.dataset.method;
    if (force || !life.value) life.value = o.dataset.life;
    if (force || !rate.value) rate.value = o.dataset.rate > 0 ? o.dataset.rate : '';
    document.getElementById('resHint').textContent = 'Blank = ' + o.dataset.res + ' % of cost';
    meth();
  }
  function preview() {
    const c = parseFloat(cost.value) || 0, o = cat && cat.selectedOptions[0];
    const r = res.value !== '' ? parseFloat(res.value) : c * ((o && parseFloat(o.dataset.res)) || 0) / 100;
    const out = document.getElementById('depPreview'), lh = document.getElementById('lifeHint');
    lh.textContent = life.value ? (life.value / 12).toFixed(1).replace('.0', '') + ' years' : '';
    if (!c) { out.textContent = ''; return; }
    if (method.value === 'slm' && life.value) out.innerHTML = 'About <b>' + fmt((c - r) / life.value) + '</b> a month (' + fmt((c - r) / life.value * 12) + ' a year).';
    else if (method.value === 'wdv' && rate.value) out.innerHTML = 'First year about <b>' + fmt(c * rate.value / 100) + '</b>, falling each year.';
    else out.textContent = '';
  }
  f.querySelectorAll('input[name=acquired_via]').forEach(r => r.addEventListener('change', via));
  method.addEventListener('change', () => { method.dataset.touched = 1; meth(); });
  cat && cat.addEventListener('change', () => fromCat(true));
  [life, rate, res, cost].forEach(el => el && el.addEventListener('input', preview));
  via(); fromCat(false); meth();
})();
</script>
@endunless
@endsection
