@extends('portal.layout')
@section('title', 'In-room dining')
@section('heading', 'In-room dining')
@section('sub', $outlet->name.' · delivered to room '.$stay->room?->room_no.' · charged to your bill')
@section('content')
<style>
.qm-cats{position:sticky;top:0;z-index:5;background:#f4f6fb;display:flex;gap:.4rem;overflow-x:auto;padding:.5rem 0;scrollbar-width:none}
.qm-cats a{white-space:nowrap;padding:.35rem .8rem;border-radius:999px;background:#fff;border:1px solid #bbf7d0;color:#1e3a8a;font-size:.85rem;text-decoration:none}
.qm-cat h2{font-size:1rem;margin:1rem 0 .5rem;color:#334155}
.qm-item{background:#fff;border:1px solid var(--c-border);border-radius:12px;padding:.8rem;display:flex;gap:.8rem;align-items:center;margin-bottom:.55rem}
.qm-photo{width:72px;height:72px;object-fit:cover;border-radius:10px;flex:none;background:#f1f5f9}
.qm-item .info{flex:1;min-width:0}.qm-item .nm{font-weight:600}.qm-item .ds{font-size:.8rem;color:#64748b;margin-top:.15rem}.qm-item .pr{font-weight:700;margin-top:.25rem}
.qty{display:flex;align-items:center;gap:.35rem}.qty button{width:34px;height:34px;border-radius:9px;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;font-size:1.1rem;font-weight:700;cursor:pointer}
.qty .n{min-width:1.3rem;text-align:center;font-weight:700}
.qm-add{border:1px solid #166534;color:#166534;background:#fff;border-radius:9px;padding:.45rem .95rem;font-weight:700;cursor:pointer}
.qm-bar{position:fixed;left:.6rem;right:.6rem;bottom:calc(4.4rem + env(safe-area-inset-bottom));background:#166534;color:#fff;padding:.75rem 1rem;display:none;align-items:center;justify-content:space-between;z-index:45;border-radius:14px;box-shadow:0 6px 20px rgba(22,101,52,.30)}
.qm-bar.show{display:flex}.qm-bar button{background:#fff;color:#166534;border:0;border-radius:10px;padding:.55rem 1rem;font-weight:700;cursor:pointer}
.qm-sheet{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;z-index:60;align-items:flex-end;justify-content:center}
.qm-sheet.show{display:flex}.qm-sheet .box{background:#fff;width:100%;max-width:720px;border-radius:18px 18px 0 0;max-height:85vh;overflow:auto;padding:1rem}
</style>

@if($orders->isNotEmpty())
<div class="gp-card"><h3>My orders</h3>
@foreach($orders as $o)
  <div class="tiny muted mb-1">{{ $o->order_no }} · {{ $o->created_at->format('d M H:i') }} · <span class="st {{ in_array($o->status, ['settled', 'closed']) ? 'done' : ($o->status === 'cancelled' ? 'cancelled' : 'acknowledged') }}">{{ in_array($o->status, ['settled', 'closed']) ? 'Charged to room' : ucfirst($o->status) }}</span></div>
  @foreach($o->items as $i)<div class="gp-row" style="padding:.35rem 0"><span class="small">{{ (float) $i->qty }} × {{ $i->item_name }}</span>
    <span class="st {{ $i->is_cancelled ? 'cancelled' : ($i->is_pending ? 'pending' : 'accepted') }}">{{ $i->is_cancelled ? 'Unavailable' : ($i->is_pending ? 'Awaiting confirmation' : 'Preparing / served') }}</span></div>@endforeach
@endforeach
</div>
@endif

@if($cats->isNotEmpty())
<nav class="qm-cats">@foreach($cats as $c)<a href="#cat{{ $c->id }}">{{ $c->name }}</a>@endforeach</nav>
@endif
@forelse($cats as $c)
<section class="qm-cat" id="cat{{ $c->id }}"><h2>{{ $c->name }}</h2>
@foreach($items->where('menu_category_id', $c->id) as $i)
  <div class="qm-item" data-id="{{ $i->id }}" data-name="{{ $i->name }}" data-price="{{ $i->price }}" data-modifiers="{{ e(json_encode($i->modifierGroups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'min' => $g->is_required ? max(1, $g->min_selections) : $g->min_selections, 'max' => $g->max_selections, 'required' => $g->is_required, 'options' => $g->modifiers->where('is_available', true)->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price_delta' => (float) $m->price_delta])->values()])->values())) }}">
    @if($i->imageUrl())<img class="qm-photo" src="{{ $i->imageUrl() }}" alt="{{ $i->name }}">@endif
    <div class="info"><div class="nm"><span class="veg-dot {{ $i->food_type }}"></span> {{ $i->name }}</div>@if($i->description)<div class="ds">{{ $i->description }}</div>@endif<div class="pr">{{ money($i->price) }}</div></div>
    <div class="ctl"><button type="button" class="qm-add">ADD</button></div>
  </div>
@endforeach
</section>
@empty
<div class="gp-card"><p class="m-0 small muted">The menu is not available right now. Please call room service.</p></div>
@endforelse
<p class="center tiny muted">Prices exclusive of applicable taxes{{ $outlet->service_charge_pct > 0 ? ' and '.(float) $outlet->service_charge_pct.'% service charge' : '' }}.</p>

<div class="qm-bar" id="bar"><div><b id="barCount">0</b> item(s) · <b id="barTotal">0</b></div><button type="button" onclick="openSheet()">Review order</button></div>
<div class="qm-sheet" id="modifierSheet" onclick="if(event.target===this)closeModifiers()"><div class="box">
  <div class="flex justify-between items-center"><h3 id="modifierTitle">Choose options</h3><button type="button" class="btn btn-ghost btn-icon" onclick="closeModifiers()"><x-icon name="x"/></button></div>
  <div id="modifierChoices"></div><button type="button" class="btn btn-primary btn-block mt-1" id="modifierAdd">Add to order</button>
</div></div>
<div class="qm-sheet" id="sheet" onclick="if(event.target===this)closeSheet()"><div class="box">
  <form method="post" action="{{ route('portal.dining.order', $token) }}" id="orderForm">@csrf
    <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
    <div class="flex justify-between items-center mb-1"><h3>Your order</h3><button type="button" class="btn btn-ghost btn-icon" onclick="closeSheet()"><x-icon name="x"/></button></div>
    <div id="sheetLines"></div>
    <div class="flex justify-between mt-1 bold"><span>Estimated total</span><span id="sheetTotal">0</span></div>
    <button class="btn btn-primary btn-lg btn-block mt-1" id="placeBtn">Order to room {{ $stay->room?->room_no }}</button>
    <p class="tiny muted center mt-1">The restaurant confirms your order before preparing it. It will be added to your room bill.</p>
  </form>
</div></div>
@push('scripts')
<script>
const cur = @json($hotel->currency_symbol ?? '');
const cart = {};
const fmt = v => cur + ' ' + v.toFixed(2);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let modifierTarget = null, modifierGroups = [];
function groupsFor(item){ try { return JSON.parse(item.dataset.modifiers || '[]'); } catch (e) { return []; } }
function addItem(item, modifiers = []) {
  const ids = modifiers.map(m => m.id).sort((a, b) => a - b), key = item.dataset.id + ':' + ids.join(',');
  if (!cart[key]) { const delta = modifiers.reduce((sum, m) => sum + Number(m.price_delta || 0), 0); cart[key] = { menu_id: item.dataset.id, name: item.dataset.name, price: +item.dataset.price + delta, qty: 0, modifiers }; }
  cart[key].qty = Math.min(20, cart[key].qty + 1); draw();
}
function openModifiers(item){
  modifierTarget = item; modifierGroups = groupsFor(item); document.getElementById('modifierTitle').textContent = 'Options for ' + item.dataset.name;
  document.getElementById('modifierChoices').innerHTML = modifierGroups.map(group => `<fieldset class="field" style="border:0;padding:0;margin:0 0 1rem"><legend class="bold small">${esc(group.name)} <span class="tiny muted">${group.min}–${group.max}${group.required ? ' · required' : ''}</span></legend>${group.options.map(option => `<label class="check"><input type="checkbox" data-modifier-group="${group.id}" data-modifier-id="${option.id}" data-modifier-name="${esc(option.name)}" data-price-delta="${option.price_delta}"> ${esc(option.name)} <span class="tiny muted">${Number(option.price_delta) ? (Number(option.price_delta) > 0 ? '+' : '') + Number(option.price_delta).toFixed(2) : 'included'}</span></label>`).join('')}</fieldset>`).join('');
  document.getElementById('modifierSheet').classList.add('show');
}
function closeModifiers(){ document.getElementById('modifierSheet').classList.remove('show'); }
document.getElementById('modifierAdd').addEventListener('click', () => {
  const selected = [...document.querySelectorAll('#modifierChoices [data-modifier-id]:checked')];
  for (const group of modifierGroups) { const count = selected.filter(input => Number(input.dataset.modifierGroup) === Number(group.id)).length; if (count < Number(group.min) || count > Number(group.max)) { alert(`Choose between ${group.min} and ${group.max} option(s) for ${group.name}.`); return; } }
  addItem(modifierTarget, selected.map(input => ({id: Number(input.dataset.modifierId), name: input.dataset.modifierName, price_delta: Number(input.dataset.priceDelta), group_id: Number(input.dataset.modifierGroup)}))); closeModifiers();
});
function draw(){
  document.querySelectorAll('.qm-item').forEach(el => { const id = el.dataset.id, groups = groupsFor(el), q = Object.values(cart).filter(l => l.menu_id === id).reduce((sum, l) => sum + l.qty, 0), ctl = el.querySelector('.ctl'); if (groups.length) ctl.innerHTML = `<button type="button" class="qm-add">ADD${q ? ' (' + q + ')' : ''}</button>`; else { const key = id + ':'; ctl.innerHTML = q ? `<div class="qty"><button type="button" data-cart-key="${key}" data-d="-1">−</button><span class="n">${q}</span><button type="button" data-cart-key="${key}" data-d="1">+</button></div>` : '<button type="button" class="qm-add">ADD</button>'; } });
  const lines = Object.entries(cart), count = lines.reduce((a, [, l]) => a + l.qty, 0), total = lines.reduce((a, [, l]) => a + l.qty * l.price, 0);
  document.getElementById('bar').classList.toggle('show', count > 0); document.getElementById('barCount').textContent = count; document.getElementById('barTotal').textContent = fmt(total); document.getElementById('sheetTotal').textContent = fmt(total);
  document.getElementById('sheetLines').innerHTML = lines.map(([key, l], i) => `<div class="cart-line" style="align-items:flex-start"><div style="flex:1"><b class="small">${l.qty} × ${esc(l.name)}</b>${l.modifiers?.length ? `<div class="tiny muted">${l.modifiers.map(m => esc(m.name) + (Number(m.price_delta) ? ' ' + (Number(m.price_delta) > 0 ? '+' : '') + Number(m.price_delta).toFixed(2) : '')).join(' · ')}</div>` : ''}<input class="input input-sm mt-1" placeholder="Note (e.g. no onions)" maxlength="120" name="items[${i}][notes]" value="${esc(l.notes||'')}" oninput="cart['${key}'].notes=this.value"><input type="hidden" name="items[${i}][id]" value="${l.menu_id}"><input type="hidden" name="items[${i}][qty]" value="${l.qty}">${(l.modifiers || []).map(m => `<input type="hidden" name="items[${i}][modifiers][]" value="${m.id}">`).join('')}</div><div class="qty"><button type="button" class="cart-adjust" data-key="${key}" data-d="-1">−</button><span class="n">${fmt(l.qty*l.price)}</span><button type="button" class="cart-adjust" data-key="${key}" data-d="1">+</button></div></div>`).join('');
  if (!count) closeSheet();
}
document.addEventListener('click', e => { const adjust = e.target.closest('.cart-adjust'); if (adjust) { const l = cart[adjust.dataset.key]; if (l) { l.qty = Math.min(20, l.qty + Number(adjust.dataset.d)); if (l.qty <= 0) delete cart[adjust.dataset.key]; draw(); } return; } const item = e.target.closest('.qm-item'); if (!item) return; if (e.target.closest('.qm-add')) { const groups = groupsFor(item); if (groups.length) openModifiers(item); else addItem(item); return; } if (e.target.dataset.d) { const key = e.target.dataset.cartKey, l = cart[key]; if (l) { l.qty = Math.min(20, l.qty + Number(e.target.dataset.d)); if (l.qty <= 0) delete cart[key]; draw(); } } });
function openSheet(){ document.getElementById('sheet').classList.add('show'); }
function closeSheet(){ document.getElementById('sheet').classList.remove('show'); }
draw();
@if($orders->contains(fn ($o) => $o->items->contains('is_pending', true)))
setTimeout(() => { if (!Object.keys(cart).length) location.reload(); }, 20000); // refresh until the kitchen confirms
@endif
</script>
@endpush
@endsection
