@extends('layouts.app')
@section('title', 'Kitchen Display')
@section('crumbs') POS / Kitchen Display (KDS) @endsection
@section('actions')
  <select class="input input-sm" id="kdsOutlet" style="width:auto"><option value="">All outlets</option>@foreach($outlets as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
  <select class="input input-sm" id="kdsStation" style="width:auto"><option value="">All stations</option>@foreach($stations as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select>
  <button class="btn btn-sm" id="kdsSound" title="Beep on new tickets"><x-icon name="bell" size="sm"/> <span>Sound off</span></button>
  <a class="btn btn-sm" href="{{ route('kitchen.station') }}"><x-icon name="print" size="sm"/> Print station</a>
  <button class="btn btn-sm" id="kdsFull"><x-icon name="grid" size="sm"/> Full screen</button>
@endsection
@push('head')
<style>
.kds{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;align-items:start}
.kds-col h3{display:flex;justify-content:space-between;align-items:center;margin:0 0 .6rem;font-size:.95rem;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-2)}
.kds-col h3 .n{background:var(--c-slate-50);border-radius:999px;padding:.1rem .6rem;font-size:.85rem}
.kds-list{display:flex;flex-direction:column;gap:.7rem;min-height:120px}
.tkt{background:var(--c-surface);border:1px solid var(--c-border);border-left:6px solid var(--c-blue);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden}
.tkt.held{border-left-color:var(--c-slate)} .tkt.preparing{border-left-color:var(--c-amber)} .tkt.ready{border-left-color:var(--c-green)} .tkt.served{opacity:.55;border-left-color:var(--c-slate)} .tkt.cancelled{border-left-color:var(--c-red);opacity:.7}
.tkt.late .tkt-h{background:var(--c-amber-50)} .tkt.very-late .tkt-h{background:var(--c-red-50)}
.tkt-h{display:flex;justify-content:space-between;align-items:center;padding:.5rem .7rem;border-bottom:1px solid var(--c-border)}
.tkt-h b{font-size:1.05rem} .tkt-t{font-family:var(--mono);font-weight:700}
.tkt ul{list-style:none;margin:0;padding:.3rem .2rem}
.tkt li{display:flex;gap:.5rem;align-items:flex-start;padding:.35rem .5rem;border-radius:6px;cursor:pointer;font-size:1rem}
.tkt li:hover{background:var(--c-slate-50)}
.tkt li .q{font-weight:800;min-width:2.2rem} .tkt li .st{margin-left:auto;font-size:.7rem}
.tkt li.cx{text-decoration:line-through;color:var(--c-red);cursor:default}
.tkt li.s-held{cursor:default} .tkt li.s-ready .nm{color:var(--c-green)} .tkt li.s-served .nm{color:var(--c-muted);text-decoration:line-through}
.tkt-f{display:flex;gap:.4rem;padding:.5rem .7rem;border-top:1px solid var(--c-border);background:var(--c-bg)}
.tkt-f .btn{flex:1}
.tkt-allergy{background:var(--c-red);color:#fff;font-weight:800;font-size:.85rem;padding:.35rem .7rem;letter-spacing:.02em}
body.kds-full .sidebar, body.kds-full .topbar{display:none} body.kds-full .main{margin-left:0} body.kds-full .content{padding-top:1rem}
@media (max-width:900px){.kds{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
<div class="flex justify-between items-center mb-1 small muted"><span>Tap an item to advance it. Held dishes wait until the captain fires them. Tickets turn amber after 10 minutes and red after 20.</span><span>Updated <b id="kdsAt">…</b></span></div>
<div class="kds" id="kds">
  @foreach(['held' => 'On hold', 'new' => 'New', 'preparing' => 'Preparing', 'ready' => 'Ready to serve'] as $k => $l)
  <div class="kds-col"><h3>{{ $l }} <span class="n" id="n-{{ $k }}">0</span></h3><div class="kds-list" id="col-{{ $k }}"></div></div>
  @endforeach
</div>
<details class="mt-2"><summary class="small muted">Recently served / cancelled (recall)</summary><div class="kds-list mt-1" id="col-done" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr))"></div></details>
@endsection
@push('scripts')
<script>
(() => {
  const feedUrl = @json(route('kitchen.feed')), bumpUrl = @json(route('kitchen.bump'));
  const token = document.querySelector('meta[name=csrf-token]').content;
  const $o = document.getElementById('kdsOutlet'), $s = document.getElementById('kdsStation');
  const keyOf = k => 'kds.' + k;
  $o.value = localStorage.getItem(keyOf('outlet')) || ''; $s.value = localStorage.getItem(keyOf('station')) || '';
  let sound = localStorage.getItem(keyOf('sound')) === '1', seen = null, busy = false;
  const sb = document.getElementById('kdsSound'); const paintSound = () => sb.querySelector('span').textContent = sound ? 'Sound on' : 'Sound off'; paintSound();
  sb.onclick = () => { sound = !sound; localStorage.setItem(keyOf('sound'), sound ? '1' : '0'); paintSound(); if (sound) beep(); };
  document.getElementById('kdsFull').onclick = () => { document.body.classList.toggle('kds-full'); if (document.body.classList.contains('kds-full')) document.documentElement.requestFullscreen?.().catch(() => {}); else document.exitFullscreen?.().catch(() => {}); };
  [$o, $s].forEach(el => el.onchange = () => { localStorage.setItem(keyOf('outlet'), $o.value); localStorage.setItem(keyOf('station'), $s.value); seen = null; load(); });
  function beep() { try { const a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain(); o.frequency.value = 880; o.connect(g); g.connect(a.destination); g.gain.setValueAtTime(.2, a.currentTime); o.start(); o.stop(a.currentTime + .25); } catch (e) {} }
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const next = {new: 'Start', preparing: 'Ready', ready: 'Served'};
  function ticket(t) {
    const late = t.status === 'served' || t.status === 'cancelled' || t.status === 'held' ? '' : (t.minutes >= 20 ? 'very-late' : (t.minutes >= 10 ? 'late' : ''));
    const items = t.items.map(i => `<li class="${i.cancelled ? 'cx' : 's-' + i.status}" data-item="${i.id}" data-cx="${i.cancelled ? 1 : 0}" data-hold="${i.hold ? 1 : 0}"><span class="q">${i.qty}×</span><span><span class="nm">${esc(i.name)}</span>${i.hold ? ' <span class="badge badge-slate">HOLD</span>' : ''}${i.open ? ' <span class="badge badge-amber">SPECIAL</span>' : ''}${i.comp ? ' <span class="badge badge-purple">COMP</span>' : ''}${i.modifiers?.length ? `<div class="tiny text-blue">Options: ${i.modifiers.map(esc).join(', ')}</div>` : ''}${i.notes ? `<div class="tiny muted">${esc(i.notes)}</div>` : ''}${i.flag && !i.cancelled ? `<div class="tiny text-red bold">⚠ ${esc(i.flag)}</div>` : ''}${$s.value ? '' : `<div class="tiny muted">${esc(i.station)}${i.printer && i.printer !== i.station ? ' · ' + esc(i.printer) : ''}</div>`}</span><span class="st badge ${i.cancelled ? 'badge-red' : ({held: 'badge-slate', new: 'badge-blue', preparing: 'badge-amber', ready: 'badge-green', served: 'badge-slate'})[i.status]}">${i.cancelled ? 'CANCELLED' : (i.status === 'held' ? 'HOLD' : i.status)}</span></li>`).join('');
    const foot = `<div class="tkt-f">${t.print ? `<a class="btn btn-sm" target="_blank" href="${esc(t.print)}">Print</a>` : ''}${t.status === 'cancelled' || t.status === 'held' ? (t.status === 'held' ? '<span class="tiny muted">Waiting for the captain to fire</span>' : '') : `${t.status !== 'new' ? `<button class="btn btn-sm" data-undo="1" data-order="${t.order_id}" data-kot="${t.kot}">Undo</button>` : ''}${t.status !== 'served' ? `<button class="btn btn-sm ${t.status === 'ready' ? 'btn-success' : 'btn-primary'}" data-order="${t.order_id}" data-kot="${t.kot}">${next[t.status]}</button>` : ''}`}</div>`;
    return `<div class="tkt ${t.status} ${late}" data-key="${t.key}"><div class="tkt-h"><div><b>${esc(t.where)}</b> <span class="tiny muted">KOT #${t.kot} · ${esc(t.order_no)}${t.steward ? ' · ' + esc(t.steward) : ''}</span><div class="tiny muted">${esc(t.outlet)} · ${t.covers} cover(s) · in ${t.since}</div></div><span class="tkt-t">${t.minutes}′</span></div>${t.allergy ? `<div class="tkt-allergy">⚠ ALLERGY: ${esc(t.allergy)}</div>` : ''}<ul>${items}</ul>${foot}</div>`;
  }
  async function load() {
    if (busy) return;
    try {
      const r = await fetch(feedUrl + '?' + new URLSearchParams({outlet: $o.value, station: $s.value}), {headers: {Accept: 'application/json'}});
      if (!r.ok) return;
      const d = await r.json();
      const cols = {held: [], new: [], preparing: [], ready: [], done: []};
      d.tickets.forEach(t => (cols[['served', 'cancelled'].includes(t.status) ? 'done' : t.status]).push(ticket(t)));
      Object.entries(cols).forEach(([k, v]) => { document.getElementById('col-' + k).innerHTML = v.join('') || (k === 'done' ? '' : '<div class="muted small center" style="padding:1.5rem;border:2px dashed var(--c-border);border-radius:var(--radius)">No tickets</div>'); const n = document.getElementById('n-' + k); if (n) n.textContent = v.length; });
      document.getElementById('kdsAt').textContent = d.at;
      const keys = new Set(d.tickets.filter(t => t.status === 'new').map(t => t.key));
      if (seen && sound && [...keys].some(k => !seen.has(k))) beep();
      seen = keys;
    } catch (e) {}
  }
  async function bump(body) {
    busy = true;
    try { await fetch(bumpUrl, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token}, body: JSON.stringify(body)}); } finally { busy = false; load(); }
  }
  document.getElementById('kds').parentElement.addEventListener('click', e => {
    const b = e.target.closest('button[data-order]');
    if (b) return bump({order_id: +b.dataset.order, kot: +b.dataset.kot, station: $s.value || null, undo: !!b.dataset.undo});
    const li = e.target.closest('li[data-item]');
    if (li && li.dataset.cx !== '1' && li.dataset.hold !== '1') bump({item_id: +li.dataset.item});
  });
  load(); setInterval(load, 5000);
})();
</script>
@endpush
