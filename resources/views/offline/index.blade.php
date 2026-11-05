@extends('layouts.app')

@section('title', 'Offline Workspaces')
@section('actions')
  <button type="button" class="btn btn-primary" data-offline-sync-now><x-icon name="refresh"/> Sync now</button>
@endsection

@section('content')
{{--
  The offline hub. The service worker sends every navigation it cannot answer to
  this page, so it is the first thing staff see when the connection drops: what
  still works on this device, and what is waiting to be sent.
--}}
<div class="offline-hub"
     data-snapshot-url="{{ route('offline.pos.snapshot') }}"
     data-preparation-url="{{ route('offline.preparation') }}">

  <div class="card mb-1">
    <div class="card-body flex justify-between gap-1 wrap">
      <div>
        <h3><x-icon name="wifi"/> This device keeps working</h3>
        <p class="small muted mb-0">
          The screens below run from what this device already holds. Work done on them is queued here and sent to the
          server when the connection returns — nothing is lost, and nothing is applied twice.
        </p>
      </div>
      <div class="offline-pos-state" data-hub-state role="status" aria-live="polite">Checking this device…</div>
    </div>
  </div>

  <div class="grid g-2 mb-1">
    <a class="card offline-hub-card" href="{{ route('offline.frontdesk') }}">
      <div class="card-body">
        <h3><x-icon name="key"/> Front desk</h3>
        <p class="small muted">Arrivals, in-house guests and the room list from the last snapshot. Check a reserved guest in and post folio charges with no connection.</p>
        <span class="badge badge-green">Check-in and charges</span>
        <span class="badge">Payment online</span>
      </div>
    </a>
    <a class="card offline-hub-card" href="{{ route('offline.pos') }}">
      <div class="card-body">
        <h3><x-icon name="cup"/> Restaurant orders</h3>
        <p class="small muted">Menu, tables, side dishes and running orders from the last snapshot. Open a bill and send dishes to the kitchen with no connection.</p>
        <span class="badge badge-green">Orders and tickets</span>
        <span class="badge">Settlement online</span>
      </div>
    </a>
    <a class="card offline-hub-card" href="{{ route('offline.housekeeping') }}">
      <div class="card-body">
        <h3><x-icon name="broom"/> Housekeeping</h3>
        <p class="small muted">Room statuses, assigned tasks, checklists and maintenance issues for today's list.</p>
        <span class="badge badge-green">Room status and tasks</span>
      </div>
    </a>
    <a class="card offline-hub-card" href="{{ route('offline.guest_requests') }}">
      <div class="card-body">
        <h3><x-icon name="bell"/> Guest requests</h3>
        <p class="small muted">Log a request against an in-house room; it reaches the department when the connection returns.</p>
        <span class="badge badge-green">Request capture</span>
      </div>
    </a>
    <a class="card offline-hub-card" href="{{ route('offline.queue') }}">
      <div class="card-body">
        <h3><x-icon name="layers"/> Outbox</h3>
        <p class="small muted">Everything this device is still holding, with the server's reason when it refused something, and a decision for the desk supervisor.</p>
        <span class="badge badge-green">Retry or discard</span>
      </div>
    </a>
    <a class="card offline-hub-card" href="{{ route('offline.desktop.workspace') }}">
      <div class="card-body">
        <h3><x-icon name="download"/> Desk packs</h3>
        <p class="small muted">Download a multi-day pack for a front-desk machine, then review what it has sent back.</p>
        <span class="badge">Front desk</span>
      </div>
    </a>
  </div>

  <section class="card flush">
    <div class="card-head">
      <h3>Prepare this device</h3>
      <button type="button" class="btn btn-sm btn-primary" data-hub-prepare>Prepare now</button>
    </div>
    <div class="card-body">
      <p class="small muted" data-hub-prepare-note>
        Pulls the screens and lists your login may work onto this device, so they open when the line is gone.
        Do it once on a good connection; press again whenever the menu or the room list has moved.
      </p>
      <div class="offline-hub-prepare" data-hub-prepare-list aria-live="polite"></div>
    </div>
  </section>

  <section class="card flush">
    <div class="card-head">
      <h3>Saved on this device</h3>
      <button type="button" class="btn btn-sm" data-outline-refresh>Refresh from the server</button>
    </div>
    <div class="card-body">
      <div class="offline-hub-summary" data-hub-summary aria-live="polite"></div>
      <div data-hub-queue class="offline-hub-queue"></div>
      <p class="small muted mb-0" data-hub-hint>
        Keep this tab open once you are back online, or open any screen in the app: the queue is sent automatically either way.
      </p>
    </div>
  </section>
</div>
@endsection

@push('scripts')
<script>
(function () {
  const root = document.querySelector('.offline-hub');
  if (!root || !window.PmsOffline) return;

  const stateEl = root.querySelector('[data-hub-state]');
  const summaryEl = root.querySelector('[data-hub-summary]');
  const queueEl = root.querySelector('[data-hub-queue]');
  const hintEl = root.querySelector('[data-hub-hint]');
  const prepareBtn = root.querySelector('[data-hub-prepare]');
  const prepareNote = root.querySelector('[data-hub-prepare-note]');
  const prepareList = root.querySelector('[data-hub-prepare-list]');

  const esc = value => String(value == null ? '' : value).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  const when = iso => (iso ? new Date(iso).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'never yet');

  async function paint() {
    const status = window.PmsOffline.status();
    const rows = await window.PmsOffline.pending();
    const waiting = rows.filter(r => r.status === 'queued' || r.status === 'retry');
    const problems = rows.filter(r => r.status === 'conflict' || r.status === 'rejected' || r.status === 'stalled');

    stateEl.textContent = !status.online
      ? 'Offline · working from this device'
      : status.syncing ? 'Syncing…' : (waiting.length ? waiting.length + ' waiting to send' : 'Online · everything sent');

    summaryEl.innerHTML =
      '<div class="offline-hub-stat"><span class="small muted">Connection</span><b>' + (status.online ? 'Online' : 'Offline') + '</b></div>' +
      '<div class="offline-hub-stat"><span class="small muted">Waiting to send</span><b>' + waiting.length + '</b></div>' +
      '<div class="offline-hub-stat"><span class="small muted">Needs a person</span><b>' + problems.length + '</b></div>' +
      '<div class="offline-hub-stat"><span class="small muted">Last sent</span><b>' + esc(when(status.lastSync)) + '</b></div>';

    queueEl.innerHTML = rows.length
      ? rows.map(row =>
          '<div class="offline-hub-row offline-hub-row-' + esc(row.status) + '">' +
            '<div><b>' + esc(row.label || row.operation) + '</b><div class="tiny muted">' + esc(row.operation) + ' · saved ' + esc(when(new Date(row.created_at || Date.now()).toISOString())) + '</div></div>' +
            '<div class="tiny">' + esc(row.status) + (row.error ? ' · ' + esc(row.error) : '') + '</div>' +
          '</div>').join('')
      : '';

    hintEl.hidden = problems.length === 0;
    if (problems.length) hintEl.textContent = 'A change was refused or clashed with the server and is kept here so nothing disappears: open the screen it belongs to and redo it with the current figures.';
  }

  // ── preparing this device ──────────────────────────────────────────────────

  const PREPARED = 'prepared';

  function paintPrepared(saved) {
    const slices = (saved && saved.slices) || [];
    if (!slices.length) { prepareList.innerHTML = ''; return; }

    const done = slices.filter(s => s.page && (s.snapshot || !s.error));
    prepareList.innerHTML =
      '<div class="tiny muted" style="margin-bottom:.35rem">Prepared ' + esc(when(saved.at)) + ' · ' +
        done.length + ' of ' + slices.length + ' screen(s)</div>' +
      slices.map(s => {
        const ok = s.page && !s.error;
        return '<div class="offline-hub-prepare-row">' +
          '<div><b>' + esc(s.label) + '</b><div class="tiny muted">' +
            (ok ? (s.snapshot ? 'screen and list saved' : 'screen saved, list kept by the screen') : esc(s.error || 'not saved')) +
          '</div></div>' +
          '<span class="badge badge-' + (ok ? 'green' : 'amber') + '">' + (ok ? 'ready' : 'failed') + '</span>' +
        '</div>';
      }).join('');
  }

  async function prepare() {
    prepareBtn.disabled = true;
    prepareNote.textContent = 'Preparing — pulling each screen and its list…';
    try {
      const plan = await fetch(root.dataset.preparationUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(r => { if (!r.ok) throw new Error('The server could not say what to prepare (' + r.status + ').'); return r.json(); });
      const slices = plan.slices || [];
      if (!slices.length) {
        prepareNote.textContent = 'This login has no offline screens to prepare.';
        return;
      }
      const results = await window.PmsOffline.prepare(slices);
      const saved = { at: new Date().toISOString(), business_date: plan.business_date, slices: results };
      await window.PmsOffline.local.set(PREPARED, saved);
      paintPrepared(saved);
      const failed = results.filter(r => r.error || !r.page);
      prepareNote.textContent = failed.length
        ? failed.length + ' screen(s) could not be saved. Stay online and press Prepare now again.'
        : 'Ready. Every screen your login may work is on this device.';
    } catch (error) {
      prepareNote.textContent = 'Preparation failed: ' + error.message;
    } finally {
      prepareBtn.disabled = false;
    }
  }

  prepareBtn.addEventListener('click', prepare);
  window.PmsOffline.local.get(PREPARED).then(paintPrepared).catch(() => {});

  window.PmsOffline.onChange(paint);
  root.querySelector('[data-outline-refresh]').addEventListener('click', () => window.PmsOffline.flush({ force: true }));
  document.querySelectorAll('[data-offline-sync-now]').forEach(btn => btn.addEventListener('click', () => window.PmsOffline.flush({ force: true })));
  paint();
})();
</script>
@endpush
