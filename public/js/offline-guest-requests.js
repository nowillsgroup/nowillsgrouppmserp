/*
 * Nowills PMS — offline guest-request capture.
 *
 * The screen captures a request and hands it to the shared outbox in
 * /js/offline-core.js, so guest requests travel on the same queue, the same
 * backoff, the same Background Sync and the same retry/discard screen as the
 * restaurant and front-desk work. This file keeps only what is specific to the
 * screen: the form, the room list and the recent-request cards.
 *
 * The room/request snapshot comes from the shared snapshot cache and the requests
 * this device captured but has not had confirmed are kept in the queue's own
 * key/value corner, so preparation and the queue cover this screen completely.
 *
 * Rows queued by the older version of this file (its own `outbox` store in
 * `nowills-pms-offline`) are moved onto the shared queue on load, keeping the
 * operation id they were stored with.
 */
(function () {
  'use strict';

  const DB_NAME = 'nowills-pms-offline';
  const DB_VERSION = 1;
  const SNAPSHOTS = 'snapshots';
  const OUTBOX = 'outbox';
  const app = document.getElementById('offlineGuestRequestsApp');
  if (!app) return;

  const hotel = document.querySelector('meta[name="hotel-code"]')?.content || 'current';
  const snapshotUrl = app.dataset.snapshotUrl;
  const syncUrl = app.dataset.syncUrl;
  const canCreate = app.dataset.canCreate === '1';
  // The shared queue owns the device id and the CSRF token; this screen is a form.

  const stateLabel = document.querySelector('[data-guest-request-state]');
  const staySelect = document.querySelector('[data-guest-stay]');
  const stayHint = document.querySelector('[data-guest-stay-hint]');
  const categorySelect = document.querySelector('[data-guest-category]');
  const itemSelect = document.querySelector('[data-guest-item]');
  const quantityInput = document.querySelector('[data-guest-quantity]');
  const preferredInput = document.querySelector('[data-guest-preferred]');
  const preferredLabel = document.querySelector('[data-guest-preferred-label]');
  const preferredHint = document.querySelector('[data-guest-preferred-hint]');
  const timeInput = document.querySelector('[data-guest-time]');
  const notesInput = document.querySelector('[data-guest-notes]');
  const notesCount = document.querySelector('[data-guest-notes-count]');
  const form = document.querySelector('[data-guest-request-form]');
  const submit = document.querySelector('[data-guest-request-submit]');
  const formHint = document.querySelector('[data-guest-form-hint]');
  const requestList = document.querySelector('[data-guest-request-list]');
  const requestEmpty = document.querySelector('[data-guest-request-empty]');
  const requestCount = document.querySelector('[data-guest-request-count]');
  const snapshotKey = () => hotel + ':guest-requests';
  let snapshot = null;
  let local = [];
  const core = window.PmsOffline || null;
  // The shared cache key for this screen's snapshot; preparation uses the same one.
  const SNAPSHOT_KEY = 'guestRequests';
  const LOCAL_KEY = 'guestRequests.local';

  function openDb() {
    return new Promise((resolve, reject) => {
      if (!window.indexedDB) return reject(new Error('This browser does not support local offline storage.'));
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(SNAPSHOTS)) db.createObjectStore(SNAPSHOTS, { keyPath: 'key' });
        if (!db.objectStoreNames.contains(OUTBOX)) db.createObjectStore(OUTBOX, { keyPath: 'operation_id' });
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error || new Error('Could not open offline storage.'));
    });
  }

  async function dbRequest(store, mode, action) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(store, mode);
      const request = action(tx.objectStore(store));
      let result;
      request.onsuccess = () => { result = request.result; };
      request.onerror = () => { db.close(); reject(request.error || new Error('Offline storage failed.')); };
      tx.oncomplete = () => { db.close(); resolve(result); };
      tx.onerror = () => { db.close(); reject(tx.error || new Error('Offline storage transaction failed.')); };
      tx.onabort = () => { db.close(); reject(tx.error || new Error('Offline storage transaction aborted.')); };
    });
  }

  const readSnapshot = () => dbRequest(SNAPSHOTS, 'readonly', store => store.get(snapshotKey()));
  const allOutbox = async () => (await dbRequest(OUTBOX, 'readonly', store => store.getAll())).filter(row => row.hotel === hotel);
  const saveOutbox = row => dbRequest(OUTBOX, 'readwrite', store => store.put(row));
  const deleteOutbox = id => dbRequest(OUTBOX, 'readwrite', store => store.delete(id));

  const readLocal = () => (core ? core.local.get(LOCAL_KEY).catch(() => null) : readSnapshot().then(row => row?.local_requests || null).catch(() => null))
    .then(value => (Array.isArray(value) ? value : []));
  const saveLocal = () => (core ? core.local.set(LOCAL_KEY, local.slice(0, 30)).catch(() => null) : Promise.resolve(null));

  function uuid() {
    return window.crypto?.randomUUID ? window.crypto.randomUUID() : 'op-' + Date.now() + '-' + Math.random().toString(16).slice(2);
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
  }

  function notify(message, tone) {
    let box = document.querySelector('[data-offline-notice]');
    if (!box) {
      box = document.createElement('div');
      box.setAttribute('data-offline-notice', '');
      box.className = 'offline-notice';
      document.body.appendChild(box);
    }
    box.className = 'offline-notice ' + (tone || 'info');
    box.textContent = message;
    box.hidden = false;
    clearTimeout(box._timer);
    box._timer = setTimeout(() => { box.hidden = true; }, tone === 'error' ? 7000 : 4000);
  }

  function setState(text) {
    if (stateLabel) stateLabel.textContent = text;
  }

  function category(key) {
    return (snapshot?.categories || []).find(item => item.key === key) || null;
  }

  function isTimedCategory(key) {
    return key === 'wakeup' || key === 'transport';
  }

  function updateFormState() {
    const hasStay = !!staySelect?.value;
    const cat = categorySelect?.value || '';
    const items = category(cat)?.items || [];
    const timed = isTimedCategory(cat);
    if (itemSelect) {
      itemSelect.innerHTML = items.map(item => '<option value="' + escapeHtml(item) + '">' + escapeHtml(item) + '</option>').join('');
      itemSelect.disabled = !items.length;
    }
    if (quantityInput) quantityInput.disabled = !hasStay || cat !== 'amenity';
    if (preferredInput) {
      preferredInput.required = timed;
      preferredInput.disabled = !hasStay;
    }
    if (preferredLabel) preferredLabel.textContent = timed ? 'For date & time *' : 'For date & time';
    if (preferredHint) preferredHint.textContent = timed ? 'Required for this request type.' : 'Optional. Use late check-out time for late check-out requests.';
    if (timeInput) timeInput.disabled = !hasStay;
    if (submit) submit.disabled = !canCreate || !hasStay || !items.length;
    if (formHint) {
      if (!canCreate) formHint.textContent = 'Your login can view the workspace but cannot create requests.';
      else if (!snapshot?.stays?.length) formHint.textContent = 'No checked-in rooms are available in the saved snapshot.';
      else if (!hasStay) formHint.textContent = 'Select a checked-in room to begin.';
      else formHint.textContent = navigator.onLine ? 'Online · the request will sync immediately.' : 'Offline · the request will be queued on this device.';
    }
  }

  function renderForm() {
    const stays = snapshot?.stays || [];
    const selectedStay = staySelect?.value;
    if (staySelect) {
      staySelect.innerHTML = '<option value="">— choose a room —</option>' + stays.map(stay => '<option value="' + escapeHtml(stay.id) + '">Room ' + escapeHtml(stay.room_no || '—') + '</option>').join('');
      if (stays.some(stay => String(stay.id) === String(selectedStay))) staySelect.value = selectedStay;
    }
    if (categorySelect && !categorySelect.options.length) {
      categorySelect.innerHTML = '<option value="">— choose a type —</option>' + (snapshot?.categories || []).map(cat => '<option value="' + escapeHtml(cat.key) + '">' + escapeHtml(cat.label) + '</option>').join('');
    }
    if (stayHint) stayHint.textContent = stays.length ? stays.length + ' checked-in room' + (stays.length === 1 ? '' : 's') + ' · guest names are not downloaded' : 'Connect once to download checked-in rooms.';
    updateFormState();
  }

  function statusBadge(request) {
    if (request.sync_status === 'queued') return '<span class="badge badge-amber">Queued offline</span>';
    if (request.sync_status === 'rejected') return '<span class="badge badge-red">Not created</span>';
    if (request.sync_status === 'conflict') return '<span class="badge badge-red">Needs review</span>';
    const tones = { new: 'red', acknowledged: 'blue', in_progress: 'amber', done: 'green', declined: 'slate', cancelled: 'slate' };
    const labels = { new: 'New', acknowledged: 'Acknowledged', in_progress: 'In progress', done: 'Done', declined: 'Declined', cancelled: 'Cancelled' };
    return '<span class="badge badge-' + (tones[request.status] || 'slate') + '">' + escapeHtml(labels[request.status] || request.status || 'Saved') + '</span>';
  }

  function formatDate(value) {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString([], { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  }

  function recentRequests() {
    const server = snapshot?.requests || [];
    const seen = new Set();
    return [...local, ...server].filter(request => {
      const key = request.id ? 'request:' + request.id : 'operation:' + request.operation_id;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    }).sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')));
  }

  function renderRequests() {
    if (!requestList) return;
    const requests = recentRequests();
    requestList.innerHTML = '';
    if (requestCount) requestCount.textContent = requests.length ? requests.length + ' recent request' + (requests.length === 1 ? '' : 's') : 'No local requests yet';
    if (requestEmpty) requestEmpty.hidden = requests.length > 0;
    requestList.hidden = requests.length === 0;
    requests.forEach(request => {
      const card = document.createElement('article');
      card.className = 'offline-request-card';
      card.innerHTML = '<div class="flex justify-between gap-1"><div><b>Room ' + escapeHtml(request.room_no || '—') + '</b><div class="tiny muted">' + escapeHtml(category(request.category)?.label || request.category || 'Request') + '</div></div>' + statusBadge(request) + '</div>'
        + '<div class="small mt-1">' + escapeHtml((request.quantity > 1 ? request.quantity + ' × ' : '') + (request.item || 'Request')) + '</div>'
        + (request.preferred_at ? '<div class="tiny muted mt-1">For ' + escapeHtml(formatDate(request.preferred_at)) + '</div>' : '')
        + (request.error ? '<div class="tiny text-red mt-1">' + escapeHtml(request.error) + '</div>' : '')
        + '<div class="tiny muted mt-1">' + escapeHtml(request.request_no || 'Saved on this device') + (request.created_at ? ' · ' + escapeHtml(formatDate(request.created_at)) : '') + '</div>';
      requestList.appendChild(card);
    });
  }

  async function updatePending() {
    // Only this screen's own rows: the shared queue also carries the restaurant,
    // front desk and housekeeping work, which is not this screen's business.
    const rows = core ? await core.pending().catch(() => []) : await allOutbox();
    const count = core
      ? rows.filter(row => row.operation === 'guest_request.create' && (row.status === 'queued' || row.status === 'retry')).length
      : rows.length;
    const pending = document.querySelector('[data-offline-pending]');
    if (pending) pending.textContent = count ? ' · ' + count + ' pending' : '';
    document.body.classList.toggle('offline-has-pending', count > 0);
    return count;
  }

  async function fetchSnapshot() {
    try {
      if (core) {
        snapshot = await core.snapshot(SNAPSHOT_KEY, snapshotUrl);
      } else if (navigator.onLine) {
        const response = await fetch(snapshotUrl, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Snapshot returned HTTP ' + response.status);
        snapshot = await response.json();
      }
    } catch (error) {
      snapshot = core ? await core.cachedSnapshot(SNAPSHOT_KEY).catch(() => snapshot) : snapshot;
    }
    renderForm();
    renderRequests();
    setState(snapshot
      ? (navigator.onLine ? 'Online · ready for offline capture' : 'Offline · using the last saved snapshot')
      : 'Connect once to download room data');
  }

  /** Send what this device holds, through the shared queue. */
  async function syncOutbox() {
    if (!core) return;
    if (!navigator.onLine) return;
    await core.flush({ force: true });
    await applyQueued();
    await updatePending();
    setState(navigator.onLine ? 'Online · ready for offline capture' : 'Offline · changes will sync automatically');
  }

  /**
   * Move rows the older version of this screen queued in its own store onto the
   * shared queue, keeping each operation id so the server still recognises work it
   * may already have applied.
   */
  async function migrateLegacyQueue() {
    if (!core) return 0;
    let moved = 0;
    try {
      const rows = (await allOutbox()).filter(row => row.operation === 'guest_request.create' && row.payload);
      for (const row of rows) {
        await core.enqueue('guest_request.create', row.payload, {
          id: row.operation_id,
          label: 'Guest request · room ' + (row.payload?.room_no || (row.payload?.stay_id ? '#' + row.payload.stay_id : '')),
        });
        await deleteOutbox(row.operation_id);
        moved += 1;
      }
      const legacy = await readSnapshot().catch(() => null);
      if (legacy?.local_requests?.length && !(await readLocal()).length) {
        local = legacy.local_requests.slice(0, 30);
        await saveLocal();
      }
    } catch (_) {
      // A device with no legacy store simply has nothing to move.
    }
    return moved;
  }

  /** Reflect the queue's state on the request cards this device captured. */
  async function applyQueued() {
    if (!core) return;
    const rows = await core.pending().catch(() => []);
    const byId = new Map(rows.map(row => [row.id, row]));
    let changed = false;
    local.forEach(request => {
      const row = byId.get(request.operation_id);
      const status = row ? row.status : request.request_no ? null : 'sent';
      if (request.sync_status === status && (request.error || null) === (row?.error || null)) return;
      request.sync_status = status;
      request.error = row ? row.error : null;
      changed = true;
    });
    if (changed) {
      await saveLocal();
      renderRequests();
    }
  }

  form?.addEventListener('submit', async event => {
    event.preventDefault();
    if (!canCreate || !staySelect?.value || !categorySelect?.value || !itemSelect?.value) return notify('Choose a checked-in room, request type and item.', 'error');
    const categoryKey = categorySelect.value;
    const preferredAt = preferredInput?.value || null;
    if (isTimedCategory(categoryKey) && !preferredAt) return notify('Choose a date and time for this request.', 'error');
    const operationId = uuid();
    const stay = (snapshot?.stays || []).find(item => String(item.id) === String(staySelect.value));
    const request = {
      operation_id: operationId, room_no: stay?.room_no, category: categoryKey, item: itemSelect.value,
      quantity: Math.max(1, Math.min(10, Number(quantityInput?.value || 1))), preferred_at: preferredAt,
      status: null, sync_status: 'queued', created_at: new Date().toISOString(), request_no: null,
    };
    const payload = {
      stay_id: Number(staySelect.value), category: categoryKey, item: itemSelect.value,
      quantity: request.quantity, preferred_at: preferredAt,
      time: timeInput?.value || '', notes: notesInput?.value.trim() || '',
    };
    try {
      if (core) {
        await core.enqueue('guest_request.create', payload, {
          id: operationId,
          label: 'Guest request · room ' + (request.room_no || '#' + payload.stay_id) + ' · ' + request.item,
        });
      } else {
        await saveOutbox({ operation_id: operationId, hotel, operation: 'guest_request.create', payload, created_at: request.created_at });
      }
      local = [request, ...local].slice(0, 30);
      await saveLocal();
      renderRequests();
      form.reset();
      if (quantityInput) quantityInput.value = '1';
      if (timeInput) timeInput.value = '14:00';
      renderForm();
      await updatePending();
      notify(navigator.onLine ? 'Guest request saved. Syncing now.' : 'Guest request saved on this device.', 'info');
      await syncOutbox();
    } catch (error) {
      notify('This request could not be saved locally.', 'error');
    }
  });

  staySelect?.addEventListener('change', updateFormState);
  categorySelect?.addEventListener('change', updateFormState);
  notesInput?.addEventListener('input', () => { if (notesCount) notesCount.textContent = notesInput.value.length; });
  document.querySelector('[data-guest-request-sync]')?.addEventListener('click', async () => {
    if (!navigator.onLine) return notify('You are offline. Requests will sync when the connection returns.', 'info');
    await syncOutbox();
    await fetchSnapshot();
  });
  window.addEventListener('online', () => { fetchSnapshot().then(syncOutbox); });
  window.addEventListener('offline', () => { setState('Offline · changes will sync automatically'); updateFormState(); });

  // The queue sends itself; this only keeps the cards and the count honest.
  if (core) {
    core.onChange(() => { updatePending(); applyQueued(); });
    window.addEventListener('pms-offline:applied', event => {
      (event.detail?.applied || []).forEach(entry => {
        if (entry.operation !== 'guest_request.create') return;
        const captured = local.find(request => request.operation_id === entry.result?.operation_id
          || request.operation_id === entry.payload?.operation_id);
        if (captured && entry.result?.request) {
          Object.assign(captured, { request_no: entry.result.request.request_no || captured.request_no, sync_status: null });
          notify('Guest request ' + (captured.request_no || 'created') + ' is on the server.', 'info');
        }
      });
      applyQueued();
      renderRequests();
    });
  }

  readLocal().then(rows => { local = rows; renderRequests(); });
  updatePending();
  migrateLegacyQueue().then(moved => {
    if (moved) notify(moved + ' queued request' + (moved === 1 ? ' was' : 's were') + ' moved onto the shared outbox.', 'info');
    updatePending();
    applyQueued();
  });
  fetchSnapshot().then(syncOutbox);
})();
