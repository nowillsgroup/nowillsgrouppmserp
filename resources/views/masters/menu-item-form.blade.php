@extends('layouts.app')
@section('title', ($row->exists ? 'Edit ' : 'New ').\Illuminate\Support\Str::singular($def['title']))
@section('crumbs') Masters / <a href="{{ route('masters.index', $def['slug']) }}">{{ $def['title'] }}</a> / {{ $row->exists ? 'Edit' : 'New' }} @endsection
@section('actions')<a class="btn" href="{{ route('masters.index', $def['slug']) }}"><x-icon name="chev"/> Back to menu items</a>@endsection
@section('content')
@php
  $photo = $row->imageUrl();
  $priceSymbol = hotel()?->currency_symbol ?? 'GH₵';
  $taxInclusive = \App\Services\TaxService::pricesInclusive();
  $variantRows = old('variants', $row->exists ? $row->variants->map(fn ($v) => ['name' => $v->name, 'rate' => $v->rate, 'cost' => $v->cost])->all() : []);
  $variantRows = is_array($variantRows) ? $variantRows : [];
  $nextVariantIndex = $variantRows ? max(array_map('intval', array_keys($variantRows))) + 1 : 0;
  $sections = [
    'details' => ['title' => 'Item details', 'description' => 'Name and classify this dish or beverage for your POS menu.', 'fields' => ['code', 'name', 'menu_category_id', 'outlet_id', 'food_type', 'description']],
    'pricing' => ['title' => 'Price & kitchen routing', 'description' => 'Set the selling price, tax rate and where the kitchen ticket should print.', 'fields' => ['price', 'tax_rate', 'kot_printer_id', 'is_available']],
  ];
@endphp
@if($errors->any())
  <div class="alert alert-warn menu-item-errors" role="alert"><x-icon name="alert"/><div><strong>Review the highlighted fields.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
@endif

<form method="post" action="{{ $row->exists ? route('masters.update', [$def['slug'], $row->id]) : route('masters.store', $def['slug']) }}" class="menu-item-form" enctype="multipart/form-data">
  @csrf
  @if($row->exists) @method('PUT') @endif
  <input type="hidden" name="variants_present" value="1">

  @foreach($sections as $sectionKey => $section)
    <section class="card menu-item-form-card" aria-labelledby="menu-item-{{ $sectionKey }}-title">
      <div class="card-head"><div><h3 id="menu-item-{{ $sectionKey }}-title">{{ $section['title'] }}</h3><p class="small muted">{{ $section['description'] }}</p></div></div>
      <div class="card-body menu-item-form-grid">
        @foreach($section['fields'] as $name)
          @php [$label, $type, $rules] = $def['fields'][$name]; $required = str_contains($rules, 'required'); $value = $row->{$name}; @endphp
          @if($type === 'select')
            <x-field :name="$name" :label="$label" type="select" :options="$options[$name] ?? []" :value="$value" :required="$required" :placeholder="$name === 'outlet_id' ? 'All outlets' : ($name === 'kot_printer_id' ? 'Use category / station' : '— Select —')" :hint="$name === 'outlet_id' ? 'Leave blank to show this item in every outlet.' : ($name === 'kot_printer_id' ? 'Leave blank to use the category or kitchen-station routing.' : null)"/>
          @elseif($name === 'description')
            <x-field :name="$name" :label="$label" type="textarea" :value="$value" :required="$required" class="span-all" rows="3" maxlength="255" placeholder="Short description shown to staff and guests" hint="Up to 255 characters."/>
          @elseif($type === 'number')
            <x-field :name="$name" :label="$label" type="number" step="any" min="0" :value="$value" :required="$required" :hint="$name === 'price' ? 'Selling price before any service charge.' : 'Enter the applicable tax percentage.'"/>
          @elseif($type === 'bool')
            <div class="field menu-item-availability-field">
              <span class="menu-item-field-label">POS availability</span>
              <label class="check menu-item-availability-toggle"><input type="hidden" name="{{ $name }}" value="0"><input type="checkbox" name="{{ $name }}" id="f_{{ $name }}" value="1" @checked(old($name, $value))> {{ $label }}</label>
              <span class="hint">Turn this off to hide the item from POS without deleting its menu record.</span>
              @error($name)<span class="err">{{ $message }}</span>@enderror
            </div>
          @else
            <x-field :name="$name" :label="$label" :type="$type" :value="$value" :required="$required" :maxlength="$name === 'code' ? 20 : 120" :placeholder="$name === 'code' ? 'Optional short item code' : null"/>
          @endif
        @endforeach
        @if($sectionKey === 'pricing')
          <aside class="menu-item-tax-preview span-all" id="menuItemTaxPreview" data-inclusive="{{ $taxInclusive ? '1' : '0' }}" data-currency="{{ $priceSymbol }}" aria-labelledby="menu-item-tax-preview-title">
            <div class="menu-item-tax-preview-head"><div><h4 id="menu-item-tax-preview-title">Price preview</h4><p class="tiny muted">Estimated per-item amount using this hotel’s tax-pricing mode.</p></div><span class="badge {{ $taxInclusive ? 'badge-blue' : 'badge-slate' }}">{{ $taxInclusive ? 'Tax-inclusive' : 'Tax-exclusive' }}</span></div>
            <div class="menu-item-tax-preview-values">
              <div><span>Price entered</span><strong id="menuItemEnteredPrice">—</strong></div>
              <div><span>{{ $taxInclusive ? 'Tax included' : 'Tax added' }}</span><strong id="menuItemTaxAmount">—</strong></div>
              <div class="menu-item-tax-total"><span>Estimated guest price <small>(excluding service charge)</small></span><strong id="menuItemGuestPrice" aria-live="polite">—</strong></div>
            </div>
            <p class="tiny muted menu-item-tax-note">Modifiers and outlet service charge are separate. The preview updates as you change price or tax; save the form to persist the values.</p>
          </aside>
        @endif
      </div>
    </section>
  @endforeach

  <section class="card menu-item-form-card" aria-labelledby="menu-item-variants-title">
    <div class="card-head"><div><h3 id="menu-item-variants-title">Variants / side dishes <span class="badge badge-slate" id="menuItemVariantCount" role="status" aria-live="polite">{{ count($variantRows) }}</span></h3><p class="small muted">Add the side dishes offered with this item; the waiter is asked to pick one when the dish is rung up. <b>Rate and cost are optional</b> — leave them blank for a side dish included in the item's price, or when the kitchen does not cost it separately. Add as many as needed (up to 30).</p></div></div>
    <div class="card-body">
      <div id="menuItemVariantRows" class="menu-item-variant-rows" data-next-index="{{ $nextVariantIndex }}">
        @foreach($variantRows as $index => $variant)
          @php $variant = is_array($variant) ? $variant : []; @endphp
          <div class="menu-item-variant-row" data-variant-row role="group" aria-label="Side dish variant {{ $loop->iteration }}">
            <div class="field"><label for="variant-{{ $index }}-name">Name of side dish</label><input id="variant-{{ $index }}-name" name="variants[{{ $index }}][name]" value="{{ old('variants.'.$index.'.name', $variant['name'] ?? '') }}" maxlength="100" autocomplete="off" data-variant-name>
              @error('variants.'.$index.'.name')<span class="err">{{ $message }}</span>@enderror
            </div>
            <div class="field"><label for="variant-{{ $index }}-rate">Rate / price ({{ $priceSymbol }}) <span class="tiny muted">optional</span></label><input id="variant-{{ $index }}-rate" name="variants[{{ $index }}][rate]" type="number" min="0" step="0.01" value="{{ old('variants.'.$index.'.rate', $variant['rate'] ?? '') }}" inputmode="decimal" placeholder="Blank = included">
              @error('variants.'.$index.'.rate')<span class="err">{{ $message }}</span>@enderror
            </div>
            <div class="field"><label for="variant-{{ $index }}-cost">Cost ({{ $priceSymbol }}) <span class="tiny muted">optional</span></label><input id="variant-{{ $index }}-cost" name="variants[{{ $index }}][cost]" type="number" min="0" step="0.01" value="{{ old('variants.'.$index.'.cost', $variant['cost'] ?? '') }}" inputmode="decimal" placeholder="Blank = 0.00">
              @error('variants.'.$index.'.cost')<span class="err">{{ $message }}</span>@enderror
            </div>
            <button type="button" class="btn btn-sm btn-ghost menu-item-variant-remove" data-remove-variant aria-label="Remove side dish variant {{ $index + 1 }}"><x-icon name="trash" size="sm"/> Remove</button>
          </div>
        @endforeach
      </div>
      <p id="menuItemVariantsEmpty" class="small muted menu-item-variants-empty" @if(count($variantRows)) hidden @endif>No side dish variants added. You can save this item without variants.</p>
      <button type="button" class="btn btn-sm menu-item-variant-add" id="addMenuItemVariant"><x-icon name="plus" size="sm"/> Add side dish variant</button>
      @if($errors->has('variants'))<span class="err">{{ $errors->first('variants') }}</span>@endif
      <label class="check menu-item-side-required">
        <input type="hidden" name="side_dish_required" value="0">
        <input type="checkbox" name="side_dish_required" value="1" @checked(old('side_dish_required', $row->side_dish_required ?? false))>
        <span><b>A side dish must be chosen with this dish</b> <span class="tiny muted">— the waiter, the guest's phone and the offline terminal all refuse to ring it up without one, and the server refuses a line that leaves it out. Only a dish that offers side dishes can insist on one.</span></span>
      </label>
      @error('side_dish_required')<span class="err">{{ $message }}</span>@enderror
      <template id="menuItemVariantTemplate">
        <div class="menu-item-variant-row" data-variant-row role="group" aria-label="Side dish variant __INDEX_LABEL__">
          <div class="field"><label for="variant-__INDEX__-name">Name of side dish</label><input id="variant-__INDEX__-name" name="variants[__INDEX__][name]" maxlength="100" autocomplete="off" data-variant-name></div>
          <div class="field"><label for="variant-__INDEX__-rate">Rate / price ({{ $priceSymbol }}) <span class="tiny muted">optional</span></label><input id="variant-__INDEX__-rate" name="variants[__INDEX__][rate]" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Blank = included"></div>
          <div class="field"><label for="variant-__INDEX__-cost">Cost ({{ $priceSymbol }}) <span class="tiny muted">optional</span></label><input id="variant-__INDEX__-cost" name="variants[__INDEX__][cost]" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Blank = 0.00"></div>
          <button type="button" class="btn btn-sm btn-ghost menu-item-variant-remove" data-remove-variant aria-label="Remove side dish variant"><x-icon name="trash" size="sm"/> Remove</button>
        </div>
      </template>
    </div>
  </section>

  @php $photoLabel = $def['fields']['image'][0]; @endphp
  <section class="card menu-item-form-card" aria-labelledby="menu-item-photo-title">
    <div class="card-head"><div><h3 id="menu-item-photo-title">Menu photo <span class="badge badge-slate">Optional</span></h3><p class="small muted">A clear food photo helps staff recognize the item and appears in supported digital menus.</p></div></div>
    <div class="card-body menu-item-photo-layout">
      <div class="menu-item-photo-frame">
        <img id="menuItemPhotoPreview" src="{{ $photo ?? '' }}" data-original-src="{{ $photo ?? '' }}" alt="{{ $row->name ? $row->name.' menu photo preview' : 'Selected menu photo preview' }}" @if(!$photo) hidden @endif>
        <div id="menuItemPhotoEmpty" class="menu-item-photo-empty" @if($photo) hidden @endif><x-icon name="cup"/><span>Photo preview</span></div>
      </div>
      <div class="menu-item-photo-controls">
        <label for="f_image">{{ $photoLabel }}</label>
        <input type="file" name="image" id="f_image" accept="image/jpeg,image/png,image/webp" aria-describedby="menuItemPhotoHint" class="@error('image') is-invalid @enderror">
        <span class="hint" id="menuItemPhotoHint">JPG, PNG or WebP · up to 2 MB. The saved image is resized to a maximum 800 px and stored as JPEG.</span>
        @if($photo)<label class="check menu-item-remove-photo"><input type="checkbox" name="remove_image" value="1" @checked(old('remove_image'))> Remove current photo</label>@endif
        @error('image')<span class="err">{{ $message }}</span>@enderror
        <span id="menuItemPhotoStatus" class="tiny muted" role="status" aria-live="polite"></span>
      </div>
    </div>
  </section>

  <div class="card menu-item-form-actions">
    <div class="card-body"><p class="small muted"><span class="req">*</span> Required fields. Saving keeps this item's existing POS and order history.</p><div class="menu-item-form-buttons"><a class="btn" href="{{ route('masters.index', $def['slug']) }}">Cancel</a><button class="btn btn-primary"><x-icon name="check"/> {{ $row->exists ? 'Save changes' : 'Save menu item' }}</button></div></div>
  </div>
</form>

<style>
.menu-item-errors{display:flex;align-items:flex-start;gap:.65rem}.menu-item-errors svg{flex:0 0 auto;margin-top:.1rem}.menu-item-errors ul{margin:.35rem 0 0;padding-left:1.2rem}
.menu-item-form{max-width:980px}.menu-item-form-card{margin-bottom:1rem}.menu-item-form-card .card-head p{margin:.3rem 0 0}.menu-item-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem 1.15rem}.menu-item-form-grid .field{margin:0;min-width:0}.menu-item-form-grid .span-all{grid-column:1/-1}.menu-item-field-label{display:block;font-size:.78rem;font-weight:650;color:var(--muted,#718096);margin-bottom:.45rem}.menu-item-availability-field{align-self:start}.menu-item-availability-toggle{font-weight:650}.menu-item-availability-field .hint{display:block;margin-top:.45rem}
.menu-item-tax-preview{border:1px solid var(--border,#d7dee8);border-radius:12px;padding:1rem;background:var(--surface-2,#f8fafc)}.menu-item-tax-preview-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem}.menu-item-tax-preview-head h4{margin:0;font-size:.92rem}.menu-item-tax-preview-head p{margin:.2rem 0 0}.menu-item-tax-preview-values{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;margin-top:.9rem}.menu-item-tax-preview-values>div{display:grid;gap:.25rem}.menu-item-tax-preview-values span{font-size:.75rem;color:var(--muted,#718096)}.menu-item-tax-preview-values strong{font-size:1rem;font-variant-numeric:tabular-nums}.menu-item-tax-preview-values .menu-item-tax-total{border-left:1px solid var(--border,#d7dee8);padding-left:.8rem}.menu-item-tax-total strong{font-size:1.1rem;color:var(--primary,#2563eb)}.menu-item-tax-total small{font-weight:400}.menu-item-tax-note{margin:.8rem 0 0}
.menu-item-variant-rows{display:grid;gap:.75rem}.menu-item-variant-row{display:grid;grid-template-columns:minmax(180px,1.5fr) repeat(2,minmax(130px,1fr)) auto;align-items:end;gap:.75rem;padding:.8rem;border:1px solid var(--border,#d7dee8);border-radius:10px;background:var(--surface-2,#f8fafc)}.menu-item-variant-row .field{margin:0;min-width:0}.menu-item-variant-remove{white-space:nowrap}.menu-item-variants-empty{padding:.5rem 0;margin:0}.menu-item-variant-add{margin-top:.8rem}
.menu-item-photo-layout{display:grid;grid-template-columns:minmax(170px,240px) minmax(0,1fr);align-items:start;gap:1.25rem}.menu-item-photo-frame{width:100%;aspect-ratio:4/3;border:1px dashed var(--border,#cbd5e1);border-radius:12px;overflow:hidden;background:var(--surface-2,#f8fafc)}.menu-item-photo-frame img{width:100%;height:100%;object-fit:cover}.menu-item-photo-empty{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;color:var(--muted,#718096);font-size:.82rem}.menu-item-photo-empty svg{width:28px;height:28px}.menu-item-photo-controls{display:grid;gap:.6rem;min-width:0}#menuItemPhotoStatus.text-red{color:var(--c-red,#dc2626)}.menu-item-photo-controls>label:first-child{font-size:.78rem;font-weight:650;color:var(--muted,#718096)}.menu-item-remove-photo{width:max-content}.menu-item-form-actions{margin-bottom:1rem}.menu-item-form-actions .card-body{display:flex;align-items:center;justify-content:space-between;gap:1rem}.menu-item-form-actions p{margin:0}.menu-item-form-buttons{display:flex;gap:.55rem;white-space:nowrap}
@media(max-width:820px){.menu-item-variant-row{grid-template-columns:repeat(2,minmax(0,1fr))}.menu-item-variant-remove{justify-self:start}}
@media(max-width:640px){.menu-item-variant-row{grid-template-columns:1fr}.menu-item-form-grid{grid-template-columns:1fr}.menu-item-form-grid .span-all{grid-column:auto}.menu-item-tax-preview-values{grid-template-columns:1fr}.menu-item-tax-preview-values .menu-item-tax-total{border-left:0;border-top:1px solid var(--border,#d7dee8);padding:.65rem 0 0}.menu-item-tax-preview-head{align-items:flex-start;flex-direction:column}.menu-item-photo-layout{grid-template-columns:1fr}.menu-item-photo-frame{max-width:280px}.menu-item-form-actions .card-body{align-items:stretch;flex-direction:column}.menu-item-form-buttons{justify-content:stretch}.menu-item-form-buttons .btn{flex:1;justify-content:center}}
</style>
<script>
(() => {
  const variantRows = document.getElementById('menuItemVariantRows');
  const variantTemplate = document.getElementById('menuItemVariantTemplate');
  const addVariant = document.getElementById('addMenuItemVariant');
  const emptyVariants = document.getElementById('menuItemVariantsEmpty');
  const variantCount = document.getElementById('menuItemVariantCount');
  if (variantRows && variantTemplate && addVariant) {
    let nextIndex = Number.parseInt(variantRows.dataset.nextIndex || '0', 10);
    const updateVariantState = () => {
      const count = variantRows.querySelectorAll('[data-variant-row]').length;
      if (emptyVariants) emptyVariants.hidden = count > 0;
      if (variantCount) variantCount.textContent = String(count);
      addVariant.disabled = count >= 30;
      if (count >= 30) addVariant.setAttribute('aria-label', 'Maximum of 30 side dish variants reached');
      else addVariant.removeAttribute('aria-label');
    };
    addVariant.addEventListener('click', () => {
      if (variantRows.querySelectorAll('[data-variant-row]').length >= 30) return;
      const index = nextIndex++;
      variantRows.dataset.nextIndex = String(nextIndex);
      const wrapper = document.createElement('div');
      wrapper.innerHTML = variantTemplate.innerHTML.trim().replaceAll('__INDEX_LABEL__', String(index + 1)).replaceAll('__INDEX__', String(index));
      const row = wrapper.firstElementChild;
      if (!row) return;
      variantRows.appendChild(row);
      updateVariantState();
      row.querySelector('[data-variant-name]')?.focus();
    });
    variantRows.addEventListener('click', event => {
      const button = event.target.closest('[data-remove-variant]');
      if (!button) return;
      button.closest('[data-variant-row]')?.remove();
      updateVariantState();
    });
    updateVariantState();
  }

  const input = document.getElementById('f_image');
  const image = document.getElementById('menuItemPhotoPreview');
  const empty = document.getElementById('menuItemPhotoEmpty');
  const status = document.getElementById('menuItemPhotoStatus');
  if (!input || !image || !empty) return;
  let previewUrl = null;
  input.addEventListener('change', () => {
    const file = input.files && input.files[0];
    if (!file) return;
    const extensionIsAllowed = /\.(jpe?g|png|webp)$/i.test(file.name);
    const mimeIsAllowed = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || (!file.type && extensionIsAllowed);
    let problem = '';
    if (!mimeIsAllowed || !extensionIsAllowed) problem = 'Choose a JPG, PNG or WebP image.';
    else if (file.size === 0) problem = 'This image file is empty. Choose another file.';
    else if (file.size > 2 * 1024 * 1024) problem = 'The image must be 2 MB or smaller.';
    if (problem) {
      input.value = '';
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = null;
      const original = image.dataset.originalSrc || '';
      image.src = original;
      image.hidden = !original;
      empty.hidden = !!original;
      if (status) { status.textContent = problem; status.classList.add('text-red'); }
      return;
    }
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = URL.createObjectURL(file);
    image.src = previewUrl;
    image.hidden = false;
    empty.hidden = true;
    if (status) { status.textContent = `${file.name} selected · ${(file.size / 1024 / 1024).toFixed(2)} MB`; status.classList.remove('text-red'); }
    const remove = document.querySelector('[name="remove_image"]');
    if (remove) remove.checked = false;
  });
  window.addEventListener('pagehide', () => { if (previewUrl) URL.revokeObjectURL(previewUrl); }, {once: true});

  const taxPreview = document.getElementById('menuItemTaxPreview');
  const priceInput = document.getElementById('f_price');
  const rateInput = document.getElementById('f_tax_rate');
  if (!taxPreview || !priceInput || !rateInput) return;
  const inclusive = taxPreview.dataset.inclusive === '1';
  const currency = taxPreview.dataset.currency || '';
  const enteredOutput = document.getElementById('menuItemEnteredPrice');
  const taxOutput = document.getElementById('menuItemTaxAmount');
  const guestOutput = document.getElementById('menuItemGuestPrice');
  const asMoney = value => `${currency} ${value.toFixed(2)}`.trim();
  const roundMoney = value => Math.round((value + Number.EPSILON) * 100) / 100;
  const refreshPricePreview = () => {
    const price = Number.parseFloat(priceInput.value);
    const rate = Number.parseFloat(rateInput.value);
    if (!Number.isFinite(price) || !Number.isFinite(rate) || price < 0 || rate < 0) {
      enteredOutput.textContent = taxOutput.textContent = guestOutput.textContent = '—';
      return;
    }
    const net = inclusive ? roundMoney(price / (1 + rate / 100)) : roundMoney(price);
    const tax = inclusive ? roundMoney(price - net) : roundMoney(price * rate / 100);
    const guestPrice = inclusive ? roundMoney(price) : roundMoney(price + tax);
    enteredOutput.textContent = asMoney(price);
    taxOutput.textContent = asMoney(tax);
    guestOutput.textContent = asMoney(guestPrice);
  };
  priceInput.addEventListener('input', refreshPricePreview);
  rateInput.addEventListener('input', refreshPricePreview);
  refreshPricePreview();
})();
</script>
@endsection
