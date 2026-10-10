/* Nowills PMS — offline guest-request capture */
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
  const deviceKey = 'nowills-offline-device:' + hotel;
  const device = localStorage.getItem(deviceKey) || (window.crypto?.randomUUID ? window.crypto.randomUUID() : 'device-' + Date.now() + '-' + Math.random().toString(16).slice(2));
  localStorage.setItem(deviceKey, device);

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
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const snapshotKey = () => hotel + ':guest-requests';
  let snapshot = null;
  let syncing = false;

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
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error || new Error('Offline storage failed.'));
      tx.oncomplete = () => db.close();
      tx.onerror = () => reject(tx.error || new Error('Offline storage transaction failed.'));
    });
  }

  const readSnapshot = () => dbRequest(SNAPSHOTS, 'readonly', store => store.get(snapshotKey()));
  const writeSnapshot = data => dbRequest(SNAPSHOTS, 'readwrite', store => store.put({ key: snapshotKey(), hotel, ...data, saved_at: new Date().toISOString() }));
  const allOutbox = async () => (await dbRequest(OUTBOX, 'readonly', store => store.getAll())).filter(row => row.hotel === hotel);
  const saveOutbox = row => dbRequest(OUTBOX, 'readwrite', store => store.put(row));
  const deleteOutbox = id => dbRequest(OUTBOX, 'readwrite', store => store.delete(id));

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
    const local = snapshot?.local_requests || [];
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
    const count = (await allOutbox()).length;
    const pending = document.querySelector('[data-offline-pending]');
    if (pending) pending.textContent = count ? ' · ' + count + ' pending' : '';
    document.body.classList.toggle('offline-has-pending', count > 0);
    return count;
  }

  async function fetchSnapshot() {
    try {
      if (navigator.onLine) {
        const response = await fetch(snapshotUrl, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Snapshot returned HTTP ' + response.status);
        const fresh = await response.json();
        fresh.local_requests = snapshot?.local_requests || (await readSnapshot())?.local_requests || [];
        snapshot = fresh;
        await writeSnapshot(snapshot);
      } else if (!snapshot) {
        snapshot = await readSnapshot();
      }
      renderForm();
      renderRequests();
      setState(navigator.onLine ? 'Online · ready for offline capture' : 'Offline · using the last saved snapshot');
    } catch (error) {
      snapshot = snapshot || await readSnapshot().catch(() => null);
      renderForm();
      renderRequests();
      setState(snapshot ? 'Offline · using the last saved snapshot' : 'Connect once to download room data');
    }
  }

  async function refreshCsrf() {
    try {
      const response = await fetch(window.location.pathname || '/offline/guest-requests', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
      if (!response.ok) return false;
      const html = await response.text();
      const match = html.match(/<meta\s+name=["']csrf-token["']\s+content=["']([^"']+)/i);
      if (match) document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', match[1]);
      return !!match;
    } catch (_) {
      return false;
    }
  }

  async function syncOutbox() {
    if (syncing || !navigator.onLine) return;
    syncing = true;
    try {
      const rows = await allOutbox();
      await updatePending();
      const requestRows = rows.filter(row => row.operation === 'guest_request.create');
      if (!requestRows.length) return;
      setState('Online · syncing ' + requestRows.length + ' request' + (requestRows.length === 1 ? '' : 's'));
      const send = () => fetch(syncUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Offline-Device': device },
        body: JSON.stringify({ operations: requestRows.map(row => ({ operation_id: row.operation_id, operation: row.operation, payload: row.payload })) }),
      });
      let response = await send();
      if (response.status === 419 && await refreshCsrf()) response = await send();
      if (!response.ok) {
        if (response.status === 401 || response.status === 403) notify('Offline requests are waiting for an authenticated session.', 'error');
        throw new Error('Sync returned HTTP ' + response.status);
      }
      const data = await response.json();
      for (const result of data.results || []) {
        const local = (snapshot?.local_requests || []).find(request => request.operation_id === result.operation_id);
        if (result.status === 'applied') {
          if (local) Object.assign(local, result.request || {}, { sync_status: null });
          notify('Guest request ' + (result.request?.request_no || 'created') + ' synced.', 'info');
        } else if (result.status === 'rejected') {
          if (local) Object.assign(local, { sync_status: 'rejected', error: result.error || 'The request was rejected.' });
          notify(result.error || 'The guest request was rejected.', 'error');
        } else if (result.status === 'conflict') {
          if (local) Object.assign(local, { sync_status: 'conflict', error: result.error || 'The request needs review.' });
          notify('The guest request needs review before it can be created.', 'error');
        }
        if (result.operation_id && ['applied', 'rejected', 'conflict'].includes(result.status)) await deleteOutbox(result.operation_id);
      }
      await updatePending();
      await fetchSnapshot();
      setState('Online · synced guest requests');
    } catch (error) {
      if (navigator.onLine) console.debug('Guest-request sync postponed:', error.message);
      setState(navigator.onLine ? 'Online · requests remain queued' : 'Offline · requests remain queued');
    } finally {
      syncing = false;
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
    const row = {
      operation_id: operationId, hotel, operation: 'guest_request.create',
      payload: { stay_id: Number(staySelect.value), category: categoryKey, item: itemSelect.value, quantity: request.quantity, preferred_at: preferredAt, time: timeInput?.value || '', notes: notesInput?.value.trim() || '' },
      created_at: request.created_at,
    };
    try {
      await saveOutbox(row);
      snapshot = snapshot || { local_requests: [] };
      snapshot.local_requests = [request, ...(snapshot.local_requests || [])].slice(0, 30);
      await writeSnapshot(snapshot);
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

  updatePending();
  fetchSnapshot().then(syncOutbox);
  setInterval(() => { if (navigator.onLine) syncOutbox(); }, 30000);
})();
