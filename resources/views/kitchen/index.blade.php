@extends('layouts.app')
@section('title', 'Kitchen Display')
@section('crumbs') POS / Kitchen Display (KDS) @endsection
@section('actions')
  <label class="sr-only" for="kdsOutlet">Filter by outlet</label><select class="input input-sm" id="kdsOutlet" aria-label="Filter tickets by outlet" style="width:auto"><option value="">All outlets</option>@foreach($outlets as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
  <label class="sr-only" for="kdsStation">Filter by kitchen station</label><select class="input input-sm" id="kdsStation" aria-label="Filter tickets by kitchen station" style="width:auto"><option value="">All stations</option>@foreach($stations as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select>
  <button type="button" class="btn btn-sm" id="kdsSound" title="Beep on new tickets" aria-pressed="false"><x-icon name="bell" size="sm"/> <span>Sound off</span></button>
  <a class="btn btn-sm" href="{{ route('kitchen.station') }}" aria-label="Open KOT print station"><x-icon name="print" size="sm"/> Print station</a>
  <button type="button" class="btn btn-sm" id="kdsFull" aria-pressed="false"><x-icon name="grid" size="sm"/> <span>Full screen</span></button>
@endsection
@push('head')
<style>
.kds{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;align-items:start}
.kds-statusbar{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:1rem}
.kds-statusbar p{margin:0;max-width:70ch}
.kds-live-status{display:flex;align-items:center;justify-content:flex-end;gap:.55rem;flex-wrap:wrap}
.kds-live-status #kdsActionStatus{max-width:28ch}
.kds-arrival-notice{padding:.18rem .5rem;border-radius:999px;background:var(--c-blue-50);color:var(--c-blue);font-size:.72rem;font-weight:750}
.kds-arrival-notice:empty{display:none}
.tkt.fresh{outline:2px solid var(--c-blue);outline-offset:1px}
.kds-col h2{display:flex;justify-content:space-between;align-items:center;margin:0 0 .6rem;font-size:.95rem;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-2)}
.kds-col h2 .n{background:var(--c-slate-50);border-radius:999px;padding:.1rem .6rem;font-size:.85rem;font-variant-numeric:tabular-nums}
.kds-list{display:flex;flex-direction:column;gap:.7rem;min-height:120px}
.tkt{background:var(--c-surface);border:1px solid var(--c-border);border-left:6px solid var(--c-blue);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden}
.tkt.held{border-left-color:var(--c-slate)} .tkt.preparing{border-left-color:var(--c-amber)} .tkt.ready{border-left-color:var(--c-green)} .tkt.served{opacity:.55;border-left-color:var(--c-slate)} .tkt.cancelled{border-left-color:var(--c-red);opacity:.7}
.tkt.late .tkt-h{background:var(--c-amber-50)} .tkt.very-late .tkt-h{background:var(--c-red-50)}
.tkt-h{display:flex;justify-content:space-between;align-items:center;padding:.5rem .7rem;border-bottom:1px solid var(--c-border)}
.tkt-h b{font-size:1.05rem} .tkt-t{font-family:var(--mono);font-weight:700}
.tkt ul{list-style:none;margin:0;padding:.3rem .2rem}
.tkt li{display:flex;gap:.5rem;align-items:flex-start;padding:.4rem .5rem;border-radius:6px;font-size:1rem}
.tkt li[role=button]{cursor:pointer}
.tkt li[role=button]:hover{background:var(--c-slate-50)}
.tkt li[role=button]:focus-visible{outline:3px solid var(--c-blue);outline-offset:1px;background:var(--c-blue-50)}
.tkt li .q{font-weight:800;min-width:2.2rem} .tkt li .st{margin-left:auto;font-size:.7rem}
.tkt li.cx{text-decoration:line-through;color:var(--c-red)}
.tkt li.s-ready .nm{color:var(--c-green)} .tkt li.s-served .nm{color:var(--c-muted);text-decoration:line-through}
.tkt-f{display:flex;gap:.4rem;padding:.5rem .7rem;border-top:1px solid var(--c-border);background:var(--c-bg)}
.tkt-f .btn{flex:1}
.tkt-allergy{background:var(--c-red);color:#fff;font-weight:800;font-size:.85rem;padding:.35rem .7rem;letter-spacing:.02em}
body.kds-full .sidebar, body.kds-full .topbar{display:none} body.kds-full .main{margin-left:0} body.kds-full .content{padding-top:1rem}
@media (max-width:1100px){.kds{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.kds{grid-template-columns:1fr}.kds-statusbar{display:block}.kds-live-status{justify-content:flex-start;margin-top:.4rem}}
</style>
@endpush
@section('content')
<div class="kds-statusbar">
  <p class="small muted">Tap an active dish to advance it. Held dishes wait until the captain fires them. Tickets turn amber after 10 minutes and red after 20.</p>
  <div class="kds-live-status"><span id="kdsConnection" class="badge badge-slate" role="status" aria-live="polite">Connecting…</span><span id="kdsArrivalNotice" class="kds-arrival-notice" role="status" aria-live="polite"></span><span class="tiny muted">Updated <time id="kdsAt">—</time></span><span id="kdsActionStatus" class="tiny" role="status" aria-live="polite"></span></div>
</div>
<div class="kds" id="kds" aria-label="Kitchen ticket lanes">
  @foreach(['held' => 'On hold', 'new' => 'New', 'preparing' => 'Preparing', 'ready' => 'Ready to serve'] as $k => $l)
  <section class="kds-col" aria-labelledby="kds-title-{{ $k }}"><h2 id="kds-title-{{ $k }}">{{ $l }} <span class="n" id="n-{{ $k }}" aria-label="0 tickets">0</span></h2><div class="kds-list" id="col-{{ $k }}" role="list" aria-label="{{ $l }} tickets"></div></section>
  @endforeach
</div>
<details class="mt-2"><summary class="small muted">Recently served / cancelled (recall)</summary><div class="kds-list mt-1" id="col-done" role="list" aria-label="Recently served or cancelled tickets" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr))"></div></details>
@endsection
@push('scripts')
<script>
(() => {
  const feedUrl = @json(route('kitchen.feed')), bumpUrl = @json(route('kitchen.bump'));
  const token = document.querySelector('meta[name=csrf-token]').content;
  const $o = document.getElementById('kdsOutlet'), $s = document.getElementById('kdsStation');
  const keyOf = k => 'kds.' + k;
  const storage = {get(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }, set(key, value) { try { localStorage.setItem(key, value); } catch (e) {} }};
  $o.value = storage.get(keyOf('outlet')) || ''; $s.value = storage.get(keyOf('station')) || '';
  let sound = storage.get(keyOf('sound')) === '1', seen = null, busy = false, loading = false, noticeTimer = null;
  const freshUntil = new Map();
  const sb = document.getElementById('kdsSound');
  const paintSound = () => { sb.querySelector('span').textContent = sound ? 'Sound on' : 'Sound off'; sb.setAttribute('aria-pressed', sound ? 'true' : 'false'); };
  const connection = document.getElementById('kdsConnection');
  const actionStatus = document.getElementById('kdsActionStatus');
  function setConnection(text, tone = 'badge-slate') { connection.classList.remove('badge-slate', 'badge-green', 'badge-red', 'badge-amber'); connection.classList.add(tone); if (connection.textContent !== text) connection.textContent = text; }
  function setActionStatus(text, failed = false) { actionStatus.textContent = text; actionStatus.classList.toggle('text-red', failed); actionStatus.classList.toggle('text-green', !!text && !failed); }
  function announceArrival(count) {
    const notice = document.getElementById('kdsArrivalNotice');
    notice.textContent = `${count} new kitchen ticket${count === 1 ? '' : 's'}`;
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => { notice.textContent = ''; }, 10000);
  }
  paintSound();
  sb.addEventListener('click', () => { sound = !sound; storage.set(keyOf('sound'), sound ? '1' : '0'); paintSound(); if (sound) beep(); });
  const fullButton = document.getElementById('kdsFull');
  function syncFullscreen() { const active = !!document.fullscreenElement; document.body.classList.toggle('kds-full', active); fullButton.setAttribute('aria-pressed', active ? 'true' : 'false'); fullButton.querySelector('span').textContent = active ? 'Exit full screen' : 'Full screen'; }
  fullButton.addEventListener('click', async () => { try { if (document.fullscreenElement) await document.exitFullscreen(); else if (document.documentElement.requestFullscreen) await document.documentElement.requestFullscreen(); else throw new Error('Full-screen mode is unavailable.'); } catch (error) { setActionStatus(error.message || 'Could not enter full-screen mode.', true); } });
  document.addEventListener('fullscreenchange', syncFullscreen); syncFullscreen();
  [$o, $s].forEach(el => el.addEventListener('change', () => { storage.set(keyOf('outlet'), $o.value); storage.set(keyOf('station'), $s.value); seen = null; load(); }));
  function beep() { try { const a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain(); o.frequency.value = 880; o.connect(g); g.connect(a.destination); g.gain.setValueAtTime(.2, a.currentTime); o.onended = () => a.close().catch(() => {}); o.start(); o.stop(a.currentTime + .25); } catch (e) {} }
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const next = {new: 'Start', preparing: 'Ready', ready: 'Served'};
  function ticket(t, fresh = false) {
    const late = t.status === 'served' || t.status === 'cancelled' || t.status === 'held' ? '' : (t.minutes >= 20 ? 'very-late' : (t.minutes >= 10 ? 'late' : ''));
    const items = t.items.map(i => {
      const canAdvance = !i.cancelled && !i.hold && i.status !== 'served';
      const interactive = canAdvance ? `role="button" tabindex="0" aria-label="${esc(`Advance ${i.name}, quantity ${i.qty}, currently ${i.status}`)}"` : '';
      const badge = i.cancelled ? 'badge-red' : ({held: 'badge-slate', new: 'badge-blue', preparing: 'badge-amber', ready: 'badge-green', served: 'badge-slate'})[i.status] || 'badge-slate';
      return `<li class="${i.cancelled ? 'cx' : 's-' + i.status}" ${interactive} data-item="${i.id}" data-cx="${i.cancelled ? 1 : 0}" data-hold="${i.hold ? 1 : 0}"><span class="q">${esc(i.qty)}×</span><span><span class="nm">${esc(i.name)}</span>${i.hold ? ' <span class="badge badge-slate">HOLD</span>' : ''}${i.open ? ' <span class="badge badge-amber">SPECIAL</span>' : ''}${i.comp ? ' <span class="badge badge-purple">COMP</span>' : ''}${i.modifiers?.length ? `<div class="tiny text-blue">Options: ${i.modifiers.map(esc).join(', ')}</div>` : ''}${i.notes ? `<div class="tiny muted">${esc(i.notes)}</div>` : ''}${i.flag && !i.cancelled ? `<div class="tiny text-red bold">⚠ ${esc(i.flag)}</div>` : ''}${$s.value ? '' : `<div class="tiny muted">${esc(i.station)}${i.printer && i.printer !== i.station ? ' · ' + esc(i.printer) : ''}</div>`}</span><span class="st badge ${badge}">${i.cancelled ? 'CANCELLED' : (i.status === 'held' ? 'HOLD' : esc(i.status))}</span></li>`;
    }).join('');
    const printLink = t.print ? `<a class="btn btn-sm" target="_blank" rel="noopener noreferrer" href="${esc(t.print)}" aria-label="Print KOT #${esc(t.kot)} for ${esc(t.where)}">Print KOT</a>` : '';
    const controls = t.status === 'cancelled' || t.status === 'held' ? (t.status === 'held' ? '<span class="tiny muted">Waiting for the captain to fire</span>' : '') : `${t.status !== 'new' ? `<button type="button" class="btn btn-sm" data-undo="1" data-order="${esc(t.order_id)}" data-kot="${esc(t.kot)}" aria-label="Undo status for KOT #${esc(t.kot)}">Undo</button>` : ''}${t.status !== 'served' ? `<button type="button" class="btn btn-sm ${t.status === 'ready' ? 'btn-success' : 'btn-primary'}" data-order="${esc(t.order_id)}" data-kot="${esc(t.kot)}" aria-label="${esc(next[t.status] || 'Advance')} KOT #${esc(t.kot)}">${esc(next[t.status] || 'Advance')}</button>` : ''}`;
    return `<article class="tkt ${esc(t.status)} ${late}${fresh ? ' fresh' : ''}" data-key="${esc(t.key)}" aria-label="KOT #${esc(t.kot)} for ${esc(t.where)}, ${esc(t.status)}"><div class="tkt-h"><div><b>${esc(t.where)}</b> <span class="tiny muted">KOT #${esc(t.kot)} · ${esc(t.order_no)}${t.steward ? ' · ' + esc(t.steward) : ''}</span><div class="tiny muted">${esc(t.outlet)} · ${esc(t.covers)} cover(s) · in ${esc(t.since)}</div></div><span class="tkt-t" aria-label="${esc(t.minutes)} minutes elapsed">${esc(t.minutes)}′</span></div>${t.allergy ? `<div class="tkt-allergy">⚠ ALLERGY: ${esc(t.allergy)}</div>` : ''}<ul>${items}</ul><div class="tkt-f">${printLink}${controls}</div></article>`;
  }
  async function load() {
    if (busy || loading || document.hidden) return;
    loading = true;
    try {
      const r = await fetch(feedUrl + '?' + new URLSearchParams({outlet: $o.value, station: $s.value}), {headers: {Accept: 'application/json'}});
      if (!r.ok) throw new Error(`Feed returned ${r.status}`);
      const d = await r.json();
      if (!Array.isArray(d.tickets)) throw new Error('Invalid kitchen feed');
      const keys = new Set(d.tickets.filter(t => t.status === 'new').map(t => t.key));
      const arrivals = seen ? [...keys].filter(k => !seen.has(k)) : [];
      const now = Date.now();
      arrivals.forEach(key => freshUntil.set(key, now + 30000));
      for (const [key, expires] of freshUntil) if (expires <= now) freshUntil.delete(key);
      const cols = {held: [], new: [], preparing: [], ready: [], done: []};
      d.tickets.forEach(t => (cols[['served', 'cancelled'].includes(t.status) ? 'done' : t.status] || cols.new).push(ticket(t, (freshUntil.get(t.key) || 0) > now)));
      Object.entries(cols).forEach(([k, v]) => {
        const column = document.getElementById('col-' + k);
        column.innerHTML = v.join('') || (k === 'done' ? '' : '<div class="muted small center" style="padding:1.5rem;border:2px dashed var(--c-border);border-radius:var(--radius)">No tickets</div>');
        const n = document.getElementById('n-' + k);
        if (n) { n.textContent = v.length; n.setAttribute('aria-label', `${v.length} ticket${v.length === 1 ? '' : 's'}`); }
      });
      document.getElementById('kdsAt').textContent = d.at;
      setConnection('Live', 'badge-green');
      if (arrivals.length) { announceArrival(arrivals.length); if (sound) beep(); }
      seen = keys;
    } catch (e) {
      setConnection('Connection lost · retrying', 'badge-red');
    } finally { loading = false; }
  }
  async function bump(body) {
    if (busy) return;
    busy = true;
    setActionStatus('Updating ticket…');
    try {
      const r = await fetch(bumpUrl, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token}, body: JSON.stringify(body)});
      const result = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(result.message || `Ticket update failed (${r.status})`);
      setActionStatus('Ticket updated.');
    } catch (error) {
      setActionStatus(error.message || 'Ticket update failed. Try again.', true);
    } finally { busy = false; load(); }
  }
  function handleBoardClick(e) {
    const b = e.target.closest('button[data-order]');
    if (b) return bump({order_id: +b.dataset.order, kot: +b.dataset.kot, station: $s.value || null, undo: !!b.dataset.undo});
    const li = e.target.closest('li[data-item]');
    if (li?.getAttribute('role') === 'button') bump({item_id: +li.dataset.item});
  }
  const board = document.getElementById('kds');
  board.addEventListener('click', handleBoardClick);
  board.addEventListener('keydown', e => {
    if (!['Enter', ' '].includes(e.key)) return;
    const li = e.target.closest('li[data-item][role="button"]');
    if (!li) return;
    e.preventDefault(); bump({item_id: +li.dataset.item});
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
  load(); setInterval(() => { if (!document.hidden) load(); }, 5000);
})();
</script>
@endpush
