@extends('layouts.app')
@section('title', 'POS Live Orders')
@section('crumbs') Point of Sale / Live Orders @endsection
@section('actions')
  <a class="btn" href="{{ route('pos.index') }}"><x-icon name="cup"/> POS Terminal</a>
  <a class="btn" href="{{ route('pos.orders') }}"><x-icon name="receipt"/> Orders &amp; Bills</a>
@endsection
@push('head')
<style>
.pos-live-page{--live-ink:#123126;--live-green:#13764d;--live-line:var(--c-border);--live-soft:var(--c-bg);}
.pos-live-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin:0 0 1rem}
.pos-live-hero h1{margin:0;font-size:1.55rem}.pos-live-hero p{margin:.25rem 0 0;max-width:65ch}
.pos-live-tools{display:flex;gap:.5rem;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.pos-live-tools .input{width:auto;min-width:160px}
.pos-live-state{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin:.7rem 0 1rem}
.pos-live-state #posLiveFeedback{min-height:1.25rem}#posLiveRetry[hidden]{display:none}
.pos-live-alert{display:flex;align-items:center;justify-content:space-between;gap:.8rem;margin:0 0 1rem;padding:.8rem 1rem;border:1px solid #70c79b;border-left:5px solid #13844f;border-radius:12px;background:linear-gradient(105deg,#e9fff2,#f8fffb);color:#12482f;box-shadow:0 8px 22px rgba(21,128,74,.12)}
.pos-live-alert[hidden]{display:none}.pos-live-alert b{display:block}.pos-live-alert p{margin:.12rem 0 0;font-size:.84rem}
.pos-live-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;margin-bottom:1rem}
.pos-live-stat{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.8rem 1rem;border:1px solid var(--live-line);border-radius:12px;background:var(--c-surface);box-shadow:var(--shadow-sm)}
.pos-live-stat span{font-size:.78rem;color:var(--c-muted)}.pos-live-stat b{font-size:1.35rem;font-variant-numeric:tabular-nums}.pos-live-stat--ready{border-color:#98ddb5;background:linear-gradient(135deg,#f1fff6,#fff)}.pos-live-stat--ready b{color:#087a43}
.pos-live-filters{display:flex;align-items:center;justify-content:space-between;gap:.8rem;flex-wrap:wrap;padding:.65rem .8rem;margin-bottom:1rem;border:1px solid var(--live-line);border-radius:12px;background:var(--c-surface)}
.pos-live-tabs{display:flex;gap:.35rem;flex-wrap:wrap}.pos-live-tab{display:inline-flex;align-items:center;gap:.42rem;border:1px solid transparent;border-radius:9px;background:transparent;color:var(--c-text);padding:.52rem .72rem;font-weight:700;cursor:pointer}.pos-live-tab:hover{background:var(--c-bg)}.pos-live-tab[aria-pressed="true"]{background:#e8f7ee;border-color:#b6e4c7;color:#0f6842}.pos-live-tab--ready[aria-pressed="true"]{background:#e7f8ed;color:#086637}.pos-live-count{display:inline-grid;place-items:center;min-width:1.4rem;height:1.4rem;padding:0 .25rem;border-radius:999px;background:rgba(15,118,75,.1);font-size:.72rem;font-variant-numeric:tabular-nums}.pos-live-tab--ready .pos-live-count{background:#c8f2d7;color:#086637}
.pos-live-search{max-width:260px;min-width:180px}
.pos-live-orders{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,360px),1fr));gap:.85rem;align-items:start}
.pos-live-card{position:relative;overflow:hidden;border:1px solid var(--live-line);border-left:5px solid #80918a;border-radius:14px;background:var(--c-surface);box-shadow:var(--shadow-sm);transition:box-shadow .16s ease,transform .16s ease}
.pos-live-card:hover{box-shadow:var(--shadow-md);transform:translateY(-1px)}.pos-live-card.has-ready{border-color:#79c994;border-left-color:#128147;background:linear-gradient(180deg,#f1fff6 0,#fff 150px)}.pos-live-card.fresh-ready{animation:pos-live-arrival 1.25s ease-in-out 3}
@keyframes pos-live-arrival{50%{box-shadow:0 0 0 5px rgba(29,164,91,.2),var(--shadow-md)}}
.pos-live-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:.7rem;padding:.85rem .9rem .7rem;border-bottom:1px solid var(--live-line)}
.pos-live-location{font-size:1.12rem;font-weight:850;color:var(--live-ink)}.pos-live-order-no{margin-top:.12rem;font-size:.82rem;font-weight:750}.pos-live-meta{display:flex;gap:.25rem .7rem;flex-wrap:wrap;margin-top:.35rem;color:var(--c-muted);font-size:.74rem}
.pos-live-state-badge{display:inline-flex;align-items:center;gap:.25rem;white-space:nowrap;padding:.3rem .55rem;border-radius:999px;background:#eef2f3;color:#43544b;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.035em}
.pos-live-state-badge.is-ready{background:#d8f7e3;color:#086637}.pos-live-state-badge.is-partial{background:#fff2d7;color:#8b5200}.pos-live-state-badge.is-new{background:#e7f1ff;color:#245da8}.pos-live-state-badge.is-preparing{background:#fff0d7;color:#855100}.pos-live-state-badge.is-held{background:#edf0f2;color:#53616b}.pos-live-state-badge.is-pending{background:#f1eaff;color:#6742a3}.pos-live-state-badge.is-served{background:#e8f1ed;color:#416253}.pos-live-items{list-style:none;margin:0;padding:.35rem .65rem}
.pos-live-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.55rem;align-items:center;padding:.62rem .3rem;border-bottom:1px solid var(--live-line)}.pos-live-item:last-child{border-bottom:0}
.pos-live-item-name{font-weight:700}.pos-live-item-sub{display:flex;gap:.35rem .6rem;flex-wrap:wrap;margin-top:.18rem;color:var(--c-muted);font-size:.75rem}.pos-live-qty{color:var(--c-muted);font-weight:750;white-space:nowrap}.pos-live-item.is-ready{background:#f0fff5;border-radius:8px}.pos-live-item.is-ready .pos-live-item-name{color:#086637}
.pos-live-card-foot{display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.68rem .85rem;border-top:1px solid var(--live-line);background:var(--live-soft)}.pos-live-card-foot .btn{white-space:nowrap}.pos-live-empty{grid-column:1/-1;padding:2.2rem 1rem;text-align:center;border:1px dashed var(--live-line);border-radius:14px;background:var(--c-surface)}.pos-live-empty h2{margin:.1rem 0 .35rem;font-size:1.1rem}.pos-live-empty p{margin:0;color:var(--c-muted)}.pos-live-loading{grid-column:1/-1;display:flex;align-items:center;gap:.8rem;padding:1rem;border:1px solid var(--live-line);border-radius:12px;background:var(--c-surface);color:var(--c-muted)}.pos-live-loading p{margin:.15rem 0 0;font-size:.82rem}.pos-live-spinner{flex:none;width:1.1rem;height:1.1rem;border:2px solid #d8e7df;border-top-color:#13764d;border-radius:50%;animation:pos-live-spin .8s linear infinite}@keyframes pos-live-spin{to{transform:rotate(360deg)}}
@media(max-width:760px){.pos-live-hero{display:block}.pos-live-tools{justify-content:flex-start;margin-top:.8rem}.pos-live-stats{grid-template-columns:repeat(3,minmax(0,1fr));gap:.45rem}.pos-live-stat{display:block;padding:.6rem}.pos-live-stat span{display:block;min-height:2.1em;font-size:.68rem}.pos-live-stat b{font-size:1.15rem}.pos-live-filters{align-items:stretch}.pos-live-search{max-width:none;width:100%}}
@media(max-width:420px){.pos-live-stats{grid-template-columns:1fr 1fr}.pos-live-stat--ready{grid-column:span 2}.pos-live-card-foot{align-items:stretch;flex-direction:column}.pos-live-card-foot .btn{justify-content:center}}
@media(prefers-reduced-motion:reduce){.pos-live-card,.pos-live-card.fresh-ready{transition:none;animation:none}.pos-live-spinner{animation:none}}
</style>
@endpush
@section('content')
@include('pos._workspace-nav')
<div class="pos-live-page" id="posLivePage" data-feed-url="{{ $feedUrl }}" data-can-serve="{{ $canServe ? '1' : '0' }}" data-currency="{{ $currencySymbol }}">
  <div class="pos-live-hero">
    <div><h1>POS Live Orders</h1><p class="small muted">Your active checks stay here. When the kitchen marks an item ready, it is highlighted and you can get a browser alert or sound. Claim an unassigned guest order from All live to add it to My orders.</p></div>
    <div class="pos-live-tools">
      <label class="sr-only" for="posLiveOutlet">Outlet</label><select class="input input-sm" id="posLiveOutlet"><option value="">All outlets</option>@foreach($outlets as $id => $name)<option value="{{ $id }}" @selected((string) $selectedOutlet === (string) $id)>{{ $name }}</option>@endforeach</select>
      <button type="button" class="btn btn-sm" id="posLiveNotifications" aria-pressed="false"><x-icon name="bell" size="sm"/> <span>Enable browser alerts</span></button>
      <button type="button" class="btn btn-sm" id="posLiveSound" aria-pressed="false"><x-icon name="bell" size="sm"/> <span>Sound off</span></button>
    </div>
  </div>
  <div class="pos-live-state"><span class="badge badge-slate" id="posLiveConnection" role="status" aria-live="polite">Connecting…</span><button type="button" class="btn btn-sm" id="posLiveRetry" hidden>Retry now</button><span class="tiny muted">Updated <time id="posLiveUpdated">—</time></span><span class="tiny" id="posLiveFeedback" role="status" aria-live="polite"></span></div>
  <div class="pos-live-alert" id="posLiveAlert" role="alert" aria-live="assertive" hidden><div><b id="posLiveAlertTitle">Kitchen update</b><p id="posLiveAlertText"></p></div><button type="button" class="btn btn-sm btn-success" id="posLiveViewReady">View ready orders</button></div>
  <div class="pos-live-stats" aria-label="Live order counts">
    <div class="pos-live-stat"><span>Orders in this view</span><b id="posLiveOrderCount">0</b></div>
    <div class="pos-live-stat"><span>Items still with kitchen</span><b id="posLiveKitchenCount">0</b></div>
    <div class="pos-live-stat pos-live-stat--ready"><span>Ready items to run</span><b id="posLiveReadyCount">0</b></div>
  </div>
  <div class="pos-live-filters">
    <div class="pos-live-tabs" role="group" aria-label="Choose orders to view">
      <button type="button" class="pos-live-tab" data-scope="mine" aria-pressed="{{ $scope === 'mine' ? 'true' : 'false' }}">My orders <span class="pos-live-count" id="posLiveMyCount">0</span></button>
      <button type="button" class="pos-live-tab" data-scope="all" aria-pressed="{{ $scope === 'all' ? 'true' : 'false' }}">All live <span class="pos-live-count" id="posLiveAllCount">0</span></button>
    </div>
    <div class="pos-live-tabs" role="group" aria-label="Filter kitchen status">
      <button type="button" class="pos-live-tab" data-status="all" aria-pressed="true">All active</button>
      <button type="button" class="pos-live-tab pos-live-tab--ready" data-status="ready" aria-pressed="false">Ready orders <span class="pos-live-count" id="posLiveReadyTabCount">0</span></button>
    </div>
    <label class="sr-only" for="posLiveSearch">Search live orders</label><input class="input input-sm pos-live-search" id="posLiveSearch" type="search" placeholder="Table, order, guest or item">
  </div>
  <div class="pos-live-orders" id="posLiveOrders" aria-live="off" aria-busy="true"><div class="pos-live-loading" role="status"><span class="pos-live-spinner" aria-hidden="true"></span><div><b>Loading active orders…</b><p>Connecting to the live order feed.</p></div></div></div>
</div>
@endsection
@push('scripts')
<script>
(() => {
  const root = document.getElementById('posLivePage');
  if (!root) return;
  const feedUrl = root.dataset.feedUrl;
  const canServe = root.dataset.canServe === '1';
  const currency = root.dataset.currency || 'GH₵';
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const storage = { get(k) { try { return localStorage.getItem('pos-live.' + k); } catch (e) { return null; } }, set(k, v) { try { localStorage.setItem('pos-live.' + k, v); } catch (e) {} } };
  const outlet = document.getElementById('posLiveOutlet');
  const ordersNode = document.getElementById('posLiveOrders');
  const connection = document.getElementById('posLiveConnection');
  const retryButton = document.getElementById('posLiveRetry');
  const feedback = document.getElementById('posLiveFeedback');
  const notificationButton = document.getElementById('posLiveNotifications');
  const soundButton = document.getElementById('posLiveSound');
  const scopeButtons = [...document.querySelectorAll('[data-scope]')];
  const statusButtons = [...document.querySelectorAll('[data-status]')];
  let scope = @json($scope), statusFilter = 'all', query = '', orders = [], initialized = false, hasSuccessfulLoad = false, loading = false, soundOn = storage.get('sound') === '1', notificationsOn = storage.get('notifications') === '1', alertTimer = null, titleTimer = null;
  let readySeen = new Map();
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const formatMoney = value => `${currency} ${Number(value || 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`;
  const cleanText = value => String(value ?? '').trim();
  function setConnection(text, tone) { connection.classList.remove('badge-slate','badge-green','badge-red','badge-amber'); connection.classList.add(tone); connection.textContent = text; }
  function setFeedback(text, failed = false) { feedback.textContent = text; feedback.classList.toggle('text-red', failed); feedback.classList.toggle('text-green', !!text && !failed); }
  function paintControls() {
    soundButton.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
    soundButton.querySelector('span').textContent = soundOn ? 'Sound on' : 'Sound off';
    const supported = 'Notification' in window;
    notificationButton.disabled = !supported || Notification.permission === 'denied';
    notificationButton.setAttribute('aria-pressed', notificationsOn ? 'true' : 'false');
    notificationButton.querySelector('span').textContent = !supported ? 'Browser alerts unavailable' : Notification.permission === 'denied' ? 'Browser alerts blocked' : notificationsOn ? 'Browser alerts on' : 'Enable browser alerts';
  }
  function beep() {
    try {
      const Audio = window.AudioContext || window.webkitAudioContext;
      if (!Audio) return;
      const context = new Audio(), oscillator = context.createOscillator(), gain = context.createGain();
      oscillator.type = 'sine'; oscillator.frequency.value = 880; oscillator.connect(gain); gain.connect(context.destination);
      gain.gain.setValueAtTime(.18, context.currentTime); oscillator.start(); oscillator.stop(context.currentTime + .22);
      oscillator.onended = () => context.close().catch(() => {});
    } catch (e) {}
  }
  function announceReady(groups) {
    if (!groups.length) return;
    const total = groups.reduce((n, group) => n + group.items.length, 0);
    const first = groups[0];
    const extra = groups.length > 1 ? ` and ${groups.length - 1} more order${groups.length > 2 ? 's' : ''}` : '';
    document.getElementById('posLiveAlertTitle').textContent = `${total} kitchen item${total === 1 ? '' : 's'} ready`;
    document.getElementById('posLiveAlertText').textContent = `${first.where} · ${first.order_no}${extra}`;
    const notice = document.getElementById('posLiveAlert');
    notice.hidden = false;
    clearTimeout(alertTimer); alertTimer = setTimeout(() => { notice.hidden = true; }, 12000);
    clearTimeout(titleTimer); document.title = `(${total} ready) POS Live Orders`;
    titleTimer = setTimeout(() => { document.title = 'POS Live Orders'; }, 12000);
    if (soundOn) beep();
    if (notificationsOn && 'Notification' in window && Notification.permission === 'granted') {
      groups.slice(0, 4).forEach(group => {
        const names = group.items.map(item => `${item.qty}× ${item.name}`).join(', ');
        const notification = new Notification(`Kitchen ready · ${group.where}`, {body: `${group.order_no}: ${names}`, tag: `pos-ready-${group.id}`});
        notification.onclick = () => { window.focus(); window.location.href = group.url; };
        setTimeout(() => notification.close(), 10000);
      });
    }
  }
  function readyTransitions(nextOrders) {
    const nextSeen = new Map(), groups = new Map();
    nextOrders.forEach(order => order.items.forEach(item => {
      if (item.status !== 'ready') return;
      const key = `${order.id}:${item.id}`, signature = item.ready_at || 'ready';
      nextSeen.set(key, signature);
      if (initialized && (!readySeen.has(key) || readySeen.get(key) !== signature)) {
        if (!groups.has(order.id)) groups.set(order.id, {id: order.id, order_no: order.order_no, where: order.where, url: order.url, items: []});
        groups.get(order.id).items.push(item);
      }
    }));
    readySeen = nextSeen;
    initialized = true;
    return [...groups.values()];
  }
  function statusLabel(status) {
    return ({new:'New', preparing:'Preparing', ready:'Ready to run', served:'Served', held:'On hold', pending:'Awaiting confirmation'})[status] || status;
  }
  function statusClass(status) {
    return ({new:'is-new', preparing:'is-preparing', ready:'is-ready', served:'is-served', held:'is-held', pending:'is-pending'})[status] || '';
  }
  function orderCard(order, fresh) {
    const ready = order.ready_count > 0;
    const servedCount = order.items.filter(item => item.status === 'served').length;
    const allServed = order.active_count > 0 && servedCount === order.active_count;
    const noItemsSent = order.active_count === 0 && order.pending_count === 0;
    const badgeText = ready ? (order.all_ready ? 'Ready to run' : `${order.ready_count} ready`) : order.pending_count ? 'Guest items pending' : allServed ? 'Served' : noItemsSent ? 'No items sent' : 'With kitchen';
    const badgeClass = ready ? 'is-ready' : order.pending_count ? 'is-partial' : allServed ? 'is-served' : '';
    const lines = order.items.length ? order.items.map(item => {
      const isReady = item.status === 'ready';
      const sub = [item.modifiers?.length ? `Options: ${item.modifiers.join(', ')}` : '', item.notes || ''].filter(Boolean).join(' · ');
      const serve = isReady && canServe ? `<button type="button" class="btn btn-sm btn-success" data-serve-url="${esc(item.serve_url)}" data-item-name="${esc(item.name)}">Mark served</button>` : `<span class="pos-live-state-badge ${statusClass(item.status)}">${esc(statusLabel(item.status))}</span>`;
      return `<li class="pos-live-item ${isReady ? 'is-ready' : ''}"><div><div class="pos-live-item-name">${esc(item.name)}</div>${sub ? `<div class="pos-live-item-sub">${esc(sub)}</div>` : ''}</div><div style="display:flex;align-items:center;gap:.5rem"><span class="pos-live-qty">${esc(item.qty)}×</span>${serve}</div></li>`;
    }).join('') : '<li class="pos-live-item"><span class="muted small">No items sent to the kitchen yet.</span></li>';
    const claim = order.can_claim ? `<button type="button" class="btn btn-sm btn-primary" data-claim-url="${esc(order.claim_url)}">Claim for me</button>` : '';
    const searchText = [order.order_no, order.where, order.guest, order.waiter, ...order.items.map(item => item.name)].join(' ').toLowerCase();
    return `<article class="pos-live-card ${ready ? 'has-ready' : ''} ${fresh ? 'fresh-ready' : ''}" data-order-id="${esc(order.id)}" data-ready="${ready ? '1' : '0'}" data-search="${esc(searchText)}">
      <header class="pos-live-card-head"><div><div class="pos-live-location">${esc(order.where)}</div><div class="pos-live-order-no">${esc(order.order_no)} · ${esc(order.order_type.replaceAll('_',' '))}</div><div class="pos-live-meta"><span>${esc(order.guest)}</span><span>Waiter: ${esc(order.waiter)}</span><span>${esc(order.outlet)}</span><span>${esc(order.time || '')} · ${esc(order.minutes)} min</span></div></div><span class="pos-live-state-badge ${badgeClass}">${esc(badgeText)}</span></header>
      <ul class="pos-live-items">${lines}</ul>
      <footer class="pos-live-card-foot"><span class="small"><b>${esc(order.active_count)}</b> item line${order.active_count === 1 ? '' : 's'} · ${esc(order.covers)} ${Number(order.covers) === 1 ? 'cover' : 'covers'} · ${esc(formatMoney(order.total))}</span><span class="flex gap-1">${claim}<a class="btn btn-sm" href="${esc(order.url)}">Open order</a></span></footer>
    </article>`;
  }
  function render() {
    const visible = orders.filter(order => (statusFilter !== 'ready' || order.ready_count > 0) && (!query || [order.order_no, order.where, order.guest, order.waiter, ...order.items.map(item => item.name)].join(' ').toLowerCase().includes(query)));
    const sorted = [...visible].sort((a,b) => b.ready_count - a.ready_count || String(b.created_at).localeCompare(String(a.created_at)));
    ordersNode.innerHTML = sorted.length ? sorted.map(order => orderCard(order, order.items.some(item => item.status === 'ready' && readySeen.get(`${order.id}:${item.id}`) === (item.ready_at || 'ready') && Date.now() - Date.parse(item.ready_at || order.created_at) < 18000))).join('')
      : `<div class="pos-live-empty"><h2>${statusFilter === 'ready' ? 'Nothing ready to run' : scope === 'mine' ? 'No orders assigned to you right now' : 'No active orders right now'}</h2><p>${statusFilter === 'ready' ? 'Ready kitchen items will appear here as soon as the kitchen marks them ready.' : scope === 'mine' ? 'Switch to All live to see shared orders, or ask the cashier to assign your name to the order.' : 'New open checks will appear here automatically.'}</p></div>`;
    ordersNode.setAttribute('aria-busy', 'false');
    const readyOrders = orders.filter(order => order.ready_count > 0);
    document.getElementById('posLiveOrderCount').textContent = visible.length;
    document.getElementById('posLiveReadyCount').textContent = readyOrders.reduce((n, order) => n + order.ready_count, 0);
    document.getElementById('posLiveKitchenCount').textContent = orders.reduce((n, order) => n + order.items.filter(item => ['new','preparing','held'].includes(item.status)).length, 0);
    document.getElementById('posLiveReadyTabCount').textContent = readyOrders.length;
  }
  async function load() {
    if (loading) return;
    loading = true; ordersNode.setAttribute('aria-busy', 'true');
    try {
      const params = new URLSearchParams({scope, outlet: outlet.value});
      const response = await fetch(`${feedUrl}?${params}`, {headers: {Accept:'application/json'}, credentials:'same-origin'});
      if (!response.ok) throw new Error(`Feed returned ${response.status}`);
      const data = await response.json();
      if (!Array.isArray(data.orders)) throw new Error('Invalid live orders feed');
      const newlyReady = readyTransitions(data.orders);
      hasSuccessfulLoad = true;
      retryButton.hidden = true;
      orders = data.orders;
      document.getElementById('posLiveMyCount').textContent = data.my_count;
      document.getElementById('posLiveAllCount').textContent = data.total_count;
      document.getElementById('posLiveUpdated').textContent = data.at;
      setConnection('Live · refreshes every 5s', 'badge-green');
      setFeedback('');
      if (newlyReady.length) announceReady(newlyReady);
      render();
    } catch (error) {
      setConnection('Connection lost · retrying', 'badge-red');
      setFeedback('Live updates paused. Reconnecting automatically.', true);
      retryButton.hidden = false;
      if (!hasSuccessfulLoad) {
        ordersNode.innerHTML = '<div class="pos-live-empty" role="status"><h2>Live orders haven’t loaded yet</h2><p>We’ll keep trying automatically. You can also retry now.</p></div>';
      }
      ordersNode.setAttribute('aria-busy', 'false');
    } finally { loading = false; }
  }
  retryButton.addEventListener('click', load);
  scopeButtons.forEach(button => button.addEventListener('click', () => {
    scope = button.dataset.scope; scopeButtons.forEach(tab => tab.setAttribute('aria-pressed', tab === button ? 'true' : 'false'));
    readySeen.clear(); initialized = false; load();
  }));
  statusButtons.forEach(button => button.addEventListener('click', () => {
    statusFilter = button.dataset.status; statusButtons.forEach(tab => tab.setAttribute('aria-pressed', tab === button ? 'true' : 'false')); render();
  }));
  outlet.addEventListener('change', () => { readySeen.clear(); initialized = false; load(); });
  document.getElementById('posLiveSearch').addEventListener('input', event => { query = cleanText(event.target.value).toLowerCase(); render(); });
  document.getElementById('posLiveViewReady').addEventListener('click', () => {
    statusFilter = 'ready'; statusButtons.forEach(tab => tab.setAttribute('aria-pressed', tab.dataset.status === 'ready' ? 'true' : 'false')); render();
    document.getElementById('posLiveOrders').scrollIntoView({behavior:'smooth', block:'start'});
  });
  notificationButton.addEventListener('click', async () => {
    if (!('Notification' in window)) return;
    try {
      const permission = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission;
      notificationsOn = permission === 'granted'; storage.set('notifications', notificationsOn ? '1' : '0');
      setFeedback(notificationsOn ? 'Browser alerts are on for new kitchen-ready items.' : permission === 'denied' ? 'Enable notifications for this site in browser settings; visual alerts remain on.' : 'Browser alerts were not enabled.');
    } catch (e) { setFeedback('Could not enable browser alerts. The on-screen Ready tab remains active.', true); }
    paintControls();
  });
  soundButton.addEventListener('click', () => { soundOn = !soundOn; storage.set('sound', soundOn ? '1' : '0'); paintControls(); if (soundOn) beep(); });
  ordersNode.addEventListener('click', async event => {
    const claim = event.target.closest('[data-claim-url]');
    if (claim) {
      claim.disabled = true; setFeedback('Assigning this order to you…');
      try {
        const response = await fetch(claim.dataset.claimUrl, {method:'POST', headers:{'X-CSRF-TOKEN':token,'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'});
        if (!response.ok) throw new Error(`Request returned ${response.status}`);
        setFeedback('Order assigned to you and added to My orders.'); await load();
      } catch (error) { claim.disabled = false; setFeedback('Could not claim this order; it may already be assigned.', true); }
      return;
    }
    const button = event.target.closest('[data-serve-url]');
    if (!button) return;
    button.disabled = true; setFeedback(`Marking ${button.dataset.itemName || 'item'} served…`);
    try {
      const response = await fetch(button.dataset.serveUrl, {method:'POST', headers:{'X-CSRF-TOKEN':token,'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'});
      if (!response.ok) throw new Error(`Request returned ${response.status}`);
      setFeedback(`${button.dataset.itemName || 'Item'} marked served.`); await load();
    } catch (error) { button.disabled = false; setFeedback('Could not mark item served. Reconnect and try again.', true); }
  });
  paintControls();
  if (!notificationsOn || !('Notification' in window) || Notification.permission !== 'granted') {
    notificationsOn = false; storage.set('notifications', '0');
  }
  paintControls();
  load();
  setInterval(load, 5000);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') load(); });
})();
</script>
@endpush
