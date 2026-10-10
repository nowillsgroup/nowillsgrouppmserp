<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex"><title>Menu · {{ $table->outlet->name }} · {{ $hotel->name }}</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
<style>
body{background:#f6f7fb;padding-bottom:6rem}
.qm-head{background:linear-gradient(135deg,#0b1b3a,#1d4ed8);color:#fff;padding:1.3rem 1rem 1.1rem}
.qm-head h1{font-size:1.35rem;margin:0}.qm-head .sub{opacity:.85;font-size:.85rem}
.qm-table{display:inline-block;background:rgba(255,255,255,.18);border-radius:999px;padding:.15rem .7rem;font-size:.8rem;margin-top:.4rem}
.qm-cats{position:sticky;top:0;z-index:5;background:#fff;border-bottom:1px solid var(--c-border);display:flex;gap:.4rem;overflow-x:auto;padding:.6rem .8rem;scrollbar-width:none}
.qm-cats a{white-space:nowrap;padding:.35rem .8rem;border-radius:999px;background:#eef2ff;color:#1e3a8a;font-size:.85rem;text-decoration:none}
.qm-cats a.active{background:#1d4ed8;color:#fff}
.qm-wrap{max-width:720px;margin:0 auto;padding:.6rem .8rem}
.qm-cat h2{font-size:1rem;margin:1.1rem 0 .5rem;color:#334155}
.qm-item{background:#fff;border:1px solid var(--c-border);border-radius:12px;padding:.8rem;display:flex;gap:.8rem;align-items:center;margin-bottom:.55rem}
.qm-photo{width:72px;height:72px;object-fit:cover;border-radius:10px;flex:none;background:#f1f5f9}
.qm-item .info{flex:1;min-width:0}.qm-item .nm{font-weight:600}.qm-item .ds{font-size:.8rem;color:#64748b;margin-top:.15rem}
.qm-item .pr{font-weight:700;margin-top:.25rem}
.qty{display:flex;align-items:center;gap:.35rem}.qty button{width:34px;height:34px;border-radius:9px;border:1px solid #c7d2fe;background:#eef2ff;color:#1d4ed8;font-size:1.1rem;font-weight:700;cursor:pointer}
.qty .n{min-width:1.3rem;text-align:center;font-weight:700}
.qm-add{border:1px solid #1d4ed8;color:#1d4ed8;background:#fff;border-radius:9px;padding:.45rem .95rem;font-weight:700;cursor:pointer}
.qm-bar{position:fixed;left:0;right:0;bottom:0;background:#1d4ed8;color:#fff;padding:.8rem 1rem calc(.8rem + env(safe-area-inset-bottom));display:none;align-items:center;justify-content:space-between;z-index:20;box-shadow:0 -6px 20px rgba(0,0,0,.15)}
.qm-bar.show{display:flex}.qm-bar button{background:#fff;color:#1d4ed8;border:0;border-radius:10px;padding:.6rem 1.1rem;font-weight:700;cursor:pointer}
.qm-sheet{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;z-index:30;align-items:flex-end;justify-content:center}
.qm-sheet.show{display:flex}.qm-sheet .box{background:#fff;width:100%;max-width:720px;border-radius:18px 18px 0 0;max-height:85vh;overflow:auto;padding:1rem}
.al{display:inline-block;font-size:.66rem;font-weight:700;padding:.05rem .35rem;border-radius:5px;background:#f1f5f9;color:#475569;margin:.25rem .2rem 0 0}
.al.hit{background:#fee2e2;color:#991b1b}.qm-item.hit{border-color:#fca5a5;background:#fff7f7}.qm-warn{font-size:.75rem;color:#b91c1c;font-weight:700;margin-top:.2rem}
.qm-allergy{background:rgba(255,255,255,.18);color:#fff;border:0;border-radius:999px;padding:.2rem .75rem;font-size:.8rem;margin:.4rem 0 0 .4rem;cursor:pointer}
.al-picks{display:grid;grid-template-columns:1fr 1fr;gap:.35rem .8rem;margin:.6rem 0}.al-picks label{font-size:.88rem;display:flex;gap:.4rem;align-items:center}
.st{font-size:.72rem;padding:.1rem .45rem;border-radius:999px}.st.pending{background:#fef3c7;color:#92400e}.st.accepted{background:#dcfce7;color:#166534}.st.cancelled{background:#fee2e2;color:#991b1b}
</style></head>
<body>@include('partials.icons')
<div class="qm-head"><h1>{{ $table->outlet->name }}</h1><div class="sub">{{ $hotel->name }}</div><span class="qm-table">Table {{ $table->table_no }}</span><button type="button" class="qm-allergy" id="qmAllergyBtn" onclick="document.getElementById('alSheet').classList.add('show')">⚠ Allergies <span id="qmAllergyN"></span></button></div>

@if($cats->isNotEmpty())
<nav class="qm-cats">@foreach($cats as $c)<a href="#cat{{ $c->id }}" class="{{ $loop->first ? 'active' : '' }}">{{ $c->name }}</a>@endforeach</nav>
@endif

<div class="qm-wrap">
@if(session('ordered'))<div class="alert alert-success"><x-icon name="check"/><div><b>Order sent!</b> Our staff will confirm it shortly. You can keep adding items.</div></div>@endif
@if($errors->any())<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first() }}</div></div>@endif

@if($order && $order->items->isNotEmpty())
<div class="card" id="myOrder"><div class="card-head"><h3>Your order</h3><span class="small muted">{{ $order->order_no }}</span></div>
<div class="card-body" style="padding-top:.2rem"><ul class="list" id="myItems">
@foreach($order->items as $i)<li><span>{{ (float) $i->qty }} × {{ $i->item_name }}@if($i->modifiers)<small class="muted"><br>{{ collect($i->modifiers)->pluck('name')->implode(', ') }}</small>@endif</span><span class="st {{ $i->is_cancelled ? 'cancelled' : ($i->is_pending ? 'pending' : 'accepted') }}">{{ $i->is_cancelled ? 'Unavailable' : ($i->is_pending ? 'Awaiting confirmation' : 'Preparing') }}</span></li>@endforeach
</ul>@if($order->status === 'billed')<div class="small mt-1 bold">Your bill is ready – our staff will bring it to your table.</div>@endif</div></div>
@endif

@forelse($cats as $c)
<section class="qm-cat" id="cat{{ $c->id }}"><h2>{{ $c->name }}</h2>
@foreach($items->where('menu_category_id', $c->id) as $i)
  @php $al = $allergenMap[$i->id] ?? ['contains' => [], 'may' => []]; @endphp
  <div class="qm-item" data-id="{{ $i->id }}" data-name="{{ $i->name }}" data-price="{{ $i->price }}" data-allergens="{{ implode(',', $al['contains']) }}" data-may="{{ implode(',', $al['may']) }}" data-modifiers="{{ e(json_encode($i->modifierGroups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'min' => $g->is_required ? max(1, $g->min_selections) : $g->min_selections, 'max' => $g->max_selections, 'required' => $g->is_required, 'options' => $g->modifiers->where('is_available', true)->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price_delta' => (float) $m->price_delta])->values()])->values())) }}">
    @if($i->imageUrl())<img class="qm-photo" src="{{ $i->imageUrl() }}" alt="{{ $i->name }}">@endif
    <div class="info"><div class="nm"><span class="veg-dot {{ $i->food_type }}"></span> {{ $i->name }}</div>@if($i->description)<div class="ds">{{ $i->description }}</div>@endif
      @if($al['contains'] || $al['may'])<div>@foreach($al['contains'] as $k)<span class="al" data-al="{{ $k }}">{{ \App\Services\AllergenService::short($k) }}</span>@endforeach @foreach($al['may'] as $k)<span class="al" data-al="{{ $k }}">may contain {{ strtolower(\App\Services\AllergenService::short($k)) }}</span>@endforeach</div>@endif
      <div class="qm-warn" hidden></div><div class="pr">{{ money($i->price) }}</div></div>
    <div class="ctl"><button type="button" class="qm-add">ADD</button></div>
  </div>
@endforeach
</section>
@empty
<x-empty title="Menu unavailable" text="Please ask our staff for assistance."/>
@endforelse
<p class="center tiny muted mt-2">Allergen information is declared by our chefs for the 14 major allergens. Our kitchen handles all of them – if you have a severe allergy, please also tell your server.</p>
<p class="center tiny muted mt-2">Prices exclusive of applicable taxes{{ $table->outlet->service_charge_pct > 0 ? ' and '.(float) $table->outlet->service_charge_pct.'% service charge' : '' }}. Powered by Nowills PMS</p>
</div>

<div class="qm-bar" id="bar"><div><b id="barCount">0</b> item(s) · <b id="barTotal">0</b></div><button type="button" onclick="openSheet()">View order</button></div>

<div class="qm-sheet" id="modifierSheet" onclick="if(event.target===this)closeModifiers()"><div class="box">
  <div class="flex justify-between items-center"><h3 id="modifierTitle">Choose options</h3><button type="button" class="btn btn-ghost btn-icon" onclick="closeModifiers()"><x-icon name="x"/></button></div>
  <div id="modifierChoices"></div>
  <button type="button" class="btn btn-primary btn-block mt-1" id="modifierAdd">Add to order</button>
</div></div>

<div class="qm-sheet" id="sheet" onclick="if(event.target===this)closeSheet()"><div class="box">
  <form method="post" action="{{ route('qrmenu.order', $table->qr_token) }}" id="orderForm">@csrf
    <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
    <div class="flex justify-between items-center mb-1"><h3>Review your order</h3><button type="button" class="btn btn-ghost btn-icon" onclick="closeSheet()"><x-icon name="x"/></button></div>
    <div id="sheetLines"></div>
    <div id="alHidden"></div><div class="qm-warn" id="alSummary" hidden></div>
    <div class="field mt-1"><label>Your name (optional)</label><input name="guest_name" maxlength="60" class="input"></div>
    <div class="flex justify-between mt-1 bold"><span>Estimated total</span><span id="sheetTotal">0</span></div>
    <button class="btn btn-primary btn-lg btn-block mt-1" id="placeBtn">Place order</button>
    <p class="tiny muted center mt-1">Your order goes to our staff for confirmation before it is prepared.</p>
  </form>
</div></div>

<div class="qm-sheet" id="alSheet" onclick="if(event.target===this)this.classList.remove('show')"><div class="box">
  <div class="flex justify-between items-center"><h3>Do you have any food allergies?</h3><button type="button" class="btn btn-ghost btn-icon" onclick="document.getElementById('alSheet').classList.remove('show')"><x-icon name="x"/></button></div>
  <p class="small muted" style="margin:.2rem 0 0">Dishes containing them are highlighted, and your allergies are sent to the kitchen with your order.</p>
  <div class="al-picks">@foreach(\App\Services\AllergenService::ALLERGENS as $k => [$lbl])<label><input type="checkbox" value="{{ $k }}" class="al-pick"> {{ $lbl }}</label>@endforeach</div>
  <button type="button" class="btn btn-primary btn-block" id="alApply" onclick="document.getElementById('alSheet').classList.remove('show')">Done</button>
</div></div>

<script>
const cur = @json($hotel->currency_symbol ?? 'GH₵');
const cart = {}; // key -> {menu_id, name, price, qty, notes, modifiers}
const fmt = v => cur + ' ' + v.toFixed(2);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let modifierTarget = null, modifierGroups = [];
function groupsFor(item){ try { return JSON.parse(item.dataset.modifiers || '[]'); } catch (e) { return []; } }
function addItem(item, modifiers = []) {
  const ids = modifiers.map(m => m.id).sort((a, b) => a - b), key = item.dataset.id + ':' + ids.join(',');
  if (!cart[key]) {
    const delta = modifiers.reduce((sum, m) => sum + Number(m.price_delta || 0), 0);
    cart[key] = { menu_id: item.dataset.id, name: item.dataset.name, price: +item.dataset.price + delta, qty: 0, modifiers };
  }
  cart[key].qty = Math.min(20, cart[key].qty + 1);
  draw();
}
function openModifiers(item){
  modifierTarget = item; modifierGroups = groupsFor(item);
  document.getElementById('modifierTitle').textContent = 'Options for ' + item.dataset.name;
  document.getElementById('modifierChoices').innerHTML = modifierGroups.map(group => `<fieldset class="field" style="border:0;padding:0;margin:0 0 1rem"><legend class="bold small">${esc(group.name)} <span class="tiny muted">${group.min}–${group.max}${group.required ? ' · required' : ''}</span></legend>${group.options.map(option => `<label class="check"><input type="checkbox" data-modifier-group="${group.id}" data-modifier-id="${option.id}" data-modifier-name="${esc(option.name)}" data-price-delta="${option.price_delta}"> ${esc(option.name)} <span class="tiny muted">${Number(option.price_delta) ? (Number(option.price_delta) > 0 ? '+' : '') + Number(option.price_delta).toFixed(2) : 'included'}</span></label>`).join('')}</fieldset>`).join('');
  document.getElementById('modifierSheet').classList.add('show');
}
function closeModifiers(){ document.getElementById('modifierSheet').classList.remove('show'); }
document.getElementById('modifierAdd').addEventListener('click', () => {
  const selected = [...document.querySelectorAll('#modifierChoices [data-modifier-id]:checked')];
  for (const group of modifierGroups) {
    const count = selected.filter(input => Number(input.dataset.modifierGroup) === Number(group.id)).length;
    if (count < Number(group.min) || count > Number(group.max)) { alert(`Choose between ${group.min} and ${group.max} option(s) for ${group.name}.`); return; }
  }
  addItem(modifierTarget, selected.map(input => ({id: Number(input.dataset.modifierId), name: input.dataset.modifierName, price_delta: Number(input.dataset.priceDelta), group_id: Number(input.dataset.modifierGroup)})));
  closeModifiers();
});
function draw(){
  document.querySelectorAll('.qm-item').forEach(el => {
    const id = el.dataset.id, groups = groupsFor(el), q = Object.values(cart).filter(l => l.menu_id === id).reduce((sum, l) => sum + l.qty, 0), ctl = el.querySelector('.ctl');
    if (groups.length) ctl.innerHTML = `<button type="button" class="qm-add">ADD${q ? ' (' + q + ')' : ''}</button>`;
    else { const key = id + ':'; ctl.innerHTML = q ? `<div class="qty"><button type="button" data-cart-key="${key}" data-d="-1">−</button><span class="n">${q}</span><button type="button" data-cart-key="${key}" data-d="1">+</button></div>` : '<button type="button" class="qm-add">ADD</button>'; }
  });
  const lines = Object.entries(cart), count = lines.reduce((a, [, l]) => a + l.qty, 0), total = lines.reduce((a, [, l]) => a + l.qty * l.price, 0);
  document.getElementById('bar').classList.toggle('show', count > 0);
  document.getElementById('barCount').textContent = count; document.getElementById('barTotal').textContent = fmt(total);
  document.getElementById('sheetTotal').textContent = fmt(total);
  document.getElementById('sheetLines').innerHTML = lines.map(([key, l], i) => `<div class="cart-line" style="align-items:flex-start">
    <div style="flex:1"><b class="small">${l.qty} × ${esc(l.name)}</b>${l.modifiers?.length ? `<div class="tiny muted">${l.modifiers.map(m => esc(m.name) + (Number(m.price_delta) ? ' ' + (Number(m.price_delta) > 0 ? '+' : '') + Number(m.price_delta).toFixed(2) : '')).join(' · ')}</div>` : ''}<input class="input input-sm mt-1" placeholder="Note (e.g. less spicy)" maxlength="120" name="items[${i}][notes]" value="${esc(l.notes||'')}" oninput="cart['${key}'].notes=this.value">
    <input type="hidden" name="items[${i}][id]" value="${l.menu_id}"><input type="hidden" name="items[${i}][qty]" value="${l.qty}">${(l.modifiers || []).map(m => `<input type="hidden" name="items[${i}][modifiers][]" value="${m.id}">`).join('')}</div><div class="qty"><button type="button" class="cart-adjust" data-key="${key}" data-d="-1">−</button><span class="n">${fmt(l.qty * l.price)}</span><button type="button" class="cart-adjust" data-key="${key}" data-d="1">+</button></div></div>`).join('');
  if (!count) closeSheet();
}
document.addEventListener('click', e => {
  const adjust = e.target.closest('.cart-adjust');
  if (adjust) { const line = cart[adjust.dataset.key]; if (line) { line.qty = Math.min(20, line.qty + Number(adjust.dataset.d)); if (line.qty <= 0) delete cart[adjust.dataset.key]; draw(); } return; }
  const item = e.target.closest('.qm-item'); if (!item) return;
  if (e.target.closest('.qm-add')) { const groups = groupsFor(item); if (groups.length) openModifiers(item); else addItem(item); return; }
  if (e.target.dataset.d) { const key = e.target.dataset.cartKey, line = cart[key]; if (line) { line.qty = Math.min(20, line.qty + Number(e.target.dataset.d)); if (line.qty <= 0) delete cart[key]; draw(); } }
});
// v5.0 allergy filter – remembered on this phone, sent with the order
const alNames = @json(collect(\App\Services\AllergenService::ALLERGENS)->map(fn ($a, $k) => \App\Services\AllergenService::short($k)));
let mine = []; try { mine = JSON.parse(localStorage.getItem('qm.allergies') || '[]'); } catch (e) {}
function paintAllergies(){
  document.querySelectorAll('.al-pick').forEach(c => c.checked = mine.includes(c.value));
  document.getElementById('qmAllergyN').textContent = mine.length ? '(' + mine.length + ')' : '';
  document.querySelectorAll('.qm-item').forEach(el => {
    const has = (el.dataset.allergens ? el.dataset.allergens.split(',') : []).filter(a => mine.includes(a)), may = (el.dataset.may ? el.dataset.may.split(',') : []).filter(a => mine.includes(a));
    el.classList.toggle('hit', has.length + may.length > 0);
    el.querySelectorAll('.al').forEach(s => s.classList.toggle('hit', mine.includes(s.dataset.al)));
    const w = el.querySelector('.qm-warn'); w.hidden = !(has.length || may.length);
    w.textContent = has.length ? '⚠ Contains ' + has.map(a => alNames[a]).join(', ') : (may.length ? '⚠ May contain ' + may.map(a => alNames[a]).join(', ') : '');
  });
  document.getElementById('alHidden').innerHTML = mine.map(a => `<input type="hidden" name="allergens[]" value="${a}">`).join('');
  const s = document.getElementById('alSummary'); s.hidden = !mine.length; s.textContent = mine.length ? '⚠ Your allergies will be sent to the kitchen: ' + mine.map(a => alNames[a]).join(', ') : '';
}
document.querySelectorAll('.al-pick').forEach(c => c.addEventListener('change', () => { mine = [...document.querySelectorAll('.al-pick:checked')].map(x => x.value); localStorage.setItem('qm.allergies', JSON.stringify(mine)); paintAllergies(); }));
paintAllergies();
function openSheet(){ document.getElementById('sheet').classList.add('show'); }
function closeSheet(){ document.getElementById('sheet').classList.remove('show'); }
document.getElementById('orderForm').addEventListener('submit', () => { document.getElementById('placeBtn').disabled = true; });
// highlight the category in view
const links = [...document.querySelectorAll('.qm-cats a')];
const io = new IntersectionObserver(es => es.forEach(en => { if (en.isIntersecting) links.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + en.target.id)); }), { rootMargin: '-40% 0px -55% 0px' });
document.querySelectorAll('.qm-cat').forEach(s => io.observe(s));
// live status of submitted items
@if($order)
const labels = { pending: 'Awaiting confirmation', accepted: 'Preparing', cancelled: 'Unavailable' };
setInterval(() => fetch(@json(route('qrmenu.status', $table->qr_token)), { headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => {
  const ul = document.getElementById('myItems'); if (!ul || !d.items) return;
  ul.innerHTML = d.items.map(i => `<li><span>${i.qty} × ${i.name.replace(/</g,'&lt;')}</span><span class="st ${i.state}">${labels[i.state]}</span></li>`).join('');
}).catch(() => {}), 15000);
@endif
draw();
</script>
</body></html>
