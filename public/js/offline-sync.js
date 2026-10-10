/* Nowills PMS — offline-first browser outbox (housekeeping room status slice) */
(function () {
  'use strict';

  const DB_NAME = 'nowills-pms-offline';
  const DB_VERSION = 1;
  const SNAPSHOTS = 'snapshots';
  const OUTBOX = 'outbox';
  const hotel = document.querySelector('meta[name="hotel-code"]')?.content || 'current';
  const app = document.getElementById('offlineHousekeepingApp');
  const snapshotUrl = app?.dataset.snapshotUrl || '/offline/housekeeping/snapshot';
  const syncUrl = app?.dataset.syncUrl || '/offline/sync';
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  const deviceKey = 'nowills-offline-device:' + hotel;
  const device = localStorage.getItem(deviceKey) || (window.crypto?.randomUUID ? window.crypto.randomUUID() : 'device-' + Date.now() + '-' + Math.random().toString(16).slice(2));
  localStorage.setItem(deviceKey, device);
  let syncing = false;
  let snapshot = null;

  const statusLabel = document.querySelector('[data-offline-label]');
  const statusDot = document.querySelector('[data-offline-dot]');
  const pendingLabel = document.querySelector('[data-offline-pending]');
  const workspaceState = document.querySelector('[data-offline-workspace-state]');
  const roomList = document.querySelector('[data-offline-room-list]');
  const roomEmpty = document.querySelector('[data-offline-empty]');
  const roomCount = document.querySelector('[data-offline-room-count]');
  const taskList = document.querySelector('[data-offline-task-list]');
  const taskEmpty = document.querySelector('[data-offline-task-empty]');
  const taskCount = document.querySelector('[data-offline-task-count]');
  const canEdit = app?.dataset.canEdit === '1';

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

  const snapshotKey = () => hotel + ':housekeeping';
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

  async function updatePending() {
    const count = (await allOutbox()).length;
    if (pendingLabel) pendingLabel.textContent = count ? ' · ' + count + ' pending' : '';
    document.body.classList.toggle('offline-has-pending', count > 0);
    return count;
  }

  function setConnectionState() {
    const online = navigator.onLine;
    document.body.classList.toggle('is-offline', !online);
    document.body.classList.toggle('is-online', online);
    if (statusLabel) statusLabel.textContent = online ? 'Online' : 'Offline';
    if (statusDot) statusDot.classList.toggle('offline', !online);
    if (workspaceState && !online) workspaceState.textContent = 'Offline · changes will sync automatically';
  }

  function roomBadge(status) {
    const labels = { clean: 'Clean', dirty: 'Dirty', inspected: 'Inspected', touch_up: 'Touch-up' };
    const tones = { clean: 'green', dirty: 'red', inspected: 'blue', touch_up: 'amber' };
    return '<span class="badge badge-' + (tones[status] || 'slate') + '">' + escapeHtml(labels[status] || status || 'Unknown') + '</span>';
  }

  function updateRoomInSnapshot(room) {
    if (!snapshot?.rooms || !room) return;
    const index = snapshot.rooms.findIndex(item => Number(item.id) === Number(room.id));
    if (index >= 0) snapshot.rooms[index] = { ...snapshot.rooms[index], ...room };
    writeSnapshot(snapshot).catch(() => {});
  }

  function updateTaskInSnapshot(task) {
    if (!snapshot?.tasks || !task) return;
    const index = snapshot.tasks.findIndex(item => Number(item.id) === Number(task.id));
    if (index >= 0) snapshot.tasks[index] = { ...snapshot.tasks[index], ...task };
    writeSnapshot(snapshot).catch(() => {});
  }

  function applyRoomToPage(room) {
    if (!room) return;
    updateRoomInSnapshot(room);
    renderWorkspace();
  }

  function applyTaskToPage(task) {
    if (!task) return;
    updateTaskInSnapshot(task);
    renderWorkspace();
  }

  function renderWorkspace() {
    if (!roomList) return;
    const rooms = snapshot?.rooms || [];
    roomList.innerHTML = '';
    if (roomCount) roomCount.textContent = rooms.length ? rooms.length + ' rooms · last saved ' + new Date(snapshot.saved_at || snapshot.generated_at).toLocaleString() : 'No local snapshot';
    if (roomEmpty) roomEmpty.hidden = rooms.length > 0;
    roomList.hidden = rooms.length === 0;
    rooms.forEach(room => {
      const card = document.createElement('article');
      card.className = 'offline-room-card';
      card.dataset.roomId = room.id;
      const roomActions = canEdit ? '<div class="offline-room-actions mt-1">'
        + ['clean', 'dirty', 'inspected', 'touch_up'].map(status => '<button type="button" class="btn btn-sm ' + (room.housekeeping === status ? 'btn-primary' : '') + '" data-offline-room-status="' + status + '" data-room-id="' + room.id + '">' + escapeHtml(status.replace('_', '-')) + '</button>').join('')
        + '</div>' : '';
      card.innerHTML = '<div class="flex justify-between gap-1"><div><b>Room ' + escapeHtml(room.room_no) + '</b><div class="tiny muted">' + escapeHtml([room.room_type, room.floor].filter(Boolean).join(' · ')) + '</div></div>' + roomBadge(room.housekeeping) + '</div>'
        + '<div class="small text-2 mt-1">' + escapeHtml(room.guest ? 'Guest: ' + room.guest : (room.status === 'occupied' ? 'Occupied' : 'Vacant')) + (room.departure ? ' · departs ' + escapeHtml(room.departure) : '') + '</div>'
        + roomActions;
      roomList.appendChild(card);
    });

    if (!taskList) return;
    const tasks = snapshot?.tasks || [];
    taskList.innerHTML = '';
    if (taskCount) taskCount.textContent = tasks.length ? tasks.length + ' task' + (tasks.length === 1 ? '' : 's') + ' · last saved ' + new Date(snapshot.saved_at || snapshot.generated_at).toLocaleString() : 'No local tasks';
    if (taskEmpty) taskEmpty.hidden = tasks.length > 0;
    taskList.hidden = tasks.length === 0;
    const checklistItems = snapshot?.checklist_items || [];
    tasks.forEach(task => {
      const card = document.createElement('article');
      card.className = 'offline-task-card';
      card.dataset.taskId = task.id;
      const checks = new Set(task.checklist || []);
      const checklist = checklistItems.length && task.status !== 'completed'
        ? '<details class="offline-task-checklist"><summary>Room checklist (' + checklistItems.length + ')</summary><div>' + checklistItems.map(item => '<label><input type="checkbox" data-offline-check value="' + escapeHtml(item) + '" ' + (checks.has(item) ? 'checked' : '') + '> ' + escapeHtml(item) + '</label>').join('') + '</div></details>'
        : '';
      let actions = '';
      if (canEdit && task.status !== 'completed') {
        if (task.status === 'pending') actions += '<button type="button" class="btn btn-sm btn-primary" data-offline-task-action="start" data-task-id="' + task.id + '">Start</button>';
        actions += '<button type="button" class="btn btn-sm" data-offline-task-action="dnd" data-task-id="' + task.id + '">DND</button>';
        actions += '<button type="button" class="btn btn-sm btn-success" data-offline-task-action="complete" data-task-id="' + task.id + '">Done</button>';
      }
      const issue = canEdit && task.room_id ? '<details class="offline-issue-form"><summary>Report maintenance issue</summary><div class="offline-issue-fields">'
        + '<input type="text" maxlength="150" placeholder="What is wrong?" data-offline-issue-title>'
        + '<select data-offline-issue-category><option value="general">General</option><option value="electrical">Electrical</option><option value="plumbing">Plumbing</option><option value="ac">AC / HVAC</option><option value="carpentry">Carpentry</option><option value="it">TV / Wi-Fi</option><option value="civil">Civil</option></select>'
        + '<select data-offline-issue-priority><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select>'
        + '<button type="button" class="btn btn-sm btn-primary" data-offline-issue="1" data-task-id="' + task.id + '">Queue issue</button></div></details>' : '';
      const inspection = canEdit && task.status === 'completed' && !task.inspection_status ? '<div class="offline-inspection"><b>Waiting for inspection</b><div class="offline-inspection-actions"><button type="button" class="btn btn-sm btn-success" data-offline-task-action="inspect_pass" data-task-id="' + task.id + '">Pass</button><input type="text" maxlength="200" placeholder="Reason if failing" data-offline-inspect-notes><button type="button" class="btn btn-sm btn-danger" data-offline-task-action="inspect_fail" data-task-id="' + task.id + '">Fail</button></div></div>' : '';
      const state = task.status === 'completed' ? 'Completed' : (task.status === 'in_progress' ? 'In progress' : 'Pending');
      card.innerHTML = '<div class="flex justify-between gap-1"><div><b>' + escapeHtml(task.room_no ? 'Room ' + task.room_no : 'Area') + '</b><div class="tiny muted">' + escapeHtml(task.task_label || task.task_type) + (task.priority === 'high' ? ' · high priority' : '') + '</div></div><span class="badge badge-' + (task.status === 'completed' ? 'green' : (task.status === 'in_progress' ? 'blue' : 'slate')) + '">' + state + '</span></div>'
        + (task.notes ? '<div class="tiny text-2 mt-1">' + escapeHtml(task.notes) + '</div>' : '')
        + (task.dnd ? '<div class="tiny text-2 mt-1">DND / service refused</div>' : '')
        + checklist
        + (actions ? '<div class="offline-task-actions mt-1">' + actions + '</div>' : '')
        + inspection + issue;
      taskList.appendChild(card);
    });
  }

  async function refreshCsrf() {
    try {
      const response = await fetch('/housekeeping', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
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
      let rows = await allOutbox();
      await updatePending();
      if (!rows.length) return;
      if (workspaceState) workspaceState.textContent = 'Online · syncing ' + rows.length + ' change' + (rows.length === 1 ? '' : 's');
      const send = () => fetch(syncUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Offline-Device': device },
        body: JSON.stringify({ operations: rows.map(row => ({ operation_id: row.operation_id, operation: row.operation, payload: row.payload, base_updated_at: row.base_updated_at })) }),
      });
      let response = await send();
      if (response.status === 419 && await refreshCsrf()) response = await send();
      if (!response.ok) {
        if (response.status === 401 || response.status === 403) notify('Offline changes are waiting for an authenticated session.', 'error');
        throw new Error('Sync returned HTTP ' + response.status);
      }
      const data = await response.json();
      let applied = 0;
      let conflicts = 0;
      for (const result of data.results || []) {
        if (!result.operation_id) continue;
        if (result.status === 'applied') applied++;
        if (result.status === 'conflict') conflicts++;
        if (['applied', 'conflict', 'rejected'].includes(result.status)) await deleteOutbox(result.operation_id);
        if (result.room) applyRoomToPage(result.room);
        if (result.task) applyTaskToPage(result.task);
        if (result.ticket) notify('Maintenance ticket ' + result.ticket.ticket_no + ' synced.', 'info');
        if (result.status === 'conflict') notify('A housekeeping record changed while this device was offline. Review the server status.', 'error');
        if (result.status === 'rejected') notify(result.error || 'An offline change was rejected.', 'error');
      }
      rows = await allOutbox();
      await updatePending();
      if (workspaceState) workspaceState.textContent = rows.length ? 'Online · some changes remain pending' : 'Online · synced ' + applied + ' change' + (applied === 1 ? '' : 's');
      if (conflicts) notify(conflicts + ' offline change' + (conflicts === 1 ? '' : 's') + ' need review.', 'error');
      if (app) await fetchSnapshot();
    } catch (error) {
      if (navigator.onLine) console.debug('Offline sync postponed:', error.message);
    } finally {
      syncing = false;
    }
  }

  async function queueRoomStatus(roomId, status, baseUpdatedAt) {
    const current = await allOutbox();
    await Promise.all(current.filter(row => row.operation === 'housekeeping.room_status' && Number(row.payload?.room_id) === Number(roomId)).map(row => deleteOutbox(row.operation_id)));
    const row = { operation_id: uuid(), hotel, operation: 'housekeeping.room_status', payload: { room_id: Number(roomId), hk_status: status }, base_updated_at: baseUpdatedAt, created_at: new Date().toISOString() };
    await saveOutbox(row);
    await updatePending();
    await syncOutbox();
  }

  async function queueTaskAction(taskId, action, baseUpdatedAt, checked = [], notes = null) {
    const current = await allOutbox();
    await Promise.all(current.filter(row => row.operation === 'housekeeping.task' && Number(row.payload?.task_id) === Number(taskId)).map(row => deleteOutbox(row.operation_id)));
    const row = { operation_id: uuid(), hotel, operation: 'housekeeping.task', payload: { task_id: Number(taskId), action, checked, notes }, base_updated_at: baseUpdatedAt, created_at: new Date().toISOString() };
    await saveOutbox(row);
    await updatePending();
    await syncOutbox();
  }

  async function queueMaintenance(taskId, category, title, priority) {
    const row = { operation_id: uuid(), hotel, operation: 'housekeeping.maintenance', payload: { task_id: Number(taskId), category, title, priority, description: '' }, created_at: new Date().toISOString() };
    await saveOutbox(row);
    await updatePending();
    await syncOutbox();
  }

  async function fetchSnapshot() {
    if (!app) return;
    try {
      if (navigator.onLine) {
        const response = await fetch(snapshotUrl, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        if (response.ok) {
          snapshot = await response.json();
          await writeSnapshot(snapshot);
        }
      }
      if (!snapshot) snapshot = await readSnapshot();
      renderWorkspace();
      if (workspaceState) workspaceState.textContent = navigator.onLine ? 'Online · ready for offline work' : 'Offline · using the last saved snapshot';
    } catch (error) {
      snapshot = snapshot || await readSnapshot().catch(() => null);
      renderWorkspace();
      if (workspaceState) workspaceState.textContent = snapshot ? 'Offline · using the last saved snapshot' : 'Connect once to download room data';
    }
  }

  document.querySelector('[data-offline-sync-now]')?.addEventListener('click', async () => {
    if (!navigator.onLine) return notify('You are offline. Changes will sync when the connection returns.', 'info');
    await syncOutbox();
    await fetchSnapshot();
  });

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-offline-room-status]');
    if (!button) return;
    const room = snapshot?.rooms?.find(item => Number(item.id) === Number(button.dataset.roomId));
    if (!room) return;
    const previous = room.housekeeping;
    room.housekeeping = button.dataset.offlineRoomStatus;
    renderWorkspace();
    queueRoomStatus(room.id, button.dataset.offlineRoomStatus, room.updated_at).then(() => {
      if (!navigator.onLine) notify('Saved on this device. It will sync automatically when online.', 'info');
    }).catch(() => { room.housekeeping = previous; renderWorkspace(); notify('This change could not be saved locally.', 'error'); });
  });

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-offline-issue]');
    if (!button) return;
    const card = button.closest('[data-task-id]');
    const titleInput = card?.querySelector('[data-offline-issue-title]');
    const title = titleInput?.value.trim() || '';
    if (!title) return notify('Enter a short description of the maintenance issue.', 'error');
    const category = card.querySelector('[data-offline-issue-category]')?.value || 'general';
    const priority = card.querySelector('[data-offline-issue-priority]')?.value || 'normal';
    queueMaintenance(button.dataset.taskId, category, title, priority).then(() => {
      if (titleInput) titleInput.value = '';
      if (!navigator.onLine) notify('Maintenance issue saved on this device and will sync automatically.', 'info');
    }).catch(() => notify('This maintenance issue could not be saved locally.', 'error'));
  });

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-offline-task-action]');
    if (!button) return;
    const task = snapshot?.tasks?.find(item => Number(item.id) === Number(button.dataset.taskId));
    if (!task || !canEdit) return;
    const action = button.dataset.offlineTaskAction;
    const card = button.closest('[data-task-id]');
    const checked = card ? Array.from(card.querySelectorAll('[data-offline-check]:checked')).map(input => input.value) : [];
    const notes = card?.querySelector('[data-offline-inspect-notes]')?.value.trim() || null;
    if (action === 'complete' && snapshot.checklist_required && task.task_type !== 'public_area' && checked.length < (snapshot.checklist_items || []).length) {
      notify('Tick every checklist item before completing the room.', 'error');
      return;
    }
    if (action === 'inspect_fail' && !notes) {
      notify('Enter a reason before failing the inspection.', 'error');
      return;
    }
    const previous = { status: task.status, dnd: task.dnd, checklist: task.checklist, inspection_status: task.inspection_status, reclean_count: task.reclean_count };
    if (action === 'start') task.status = 'in_progress';
    if (action === 'dnd') { task.status = 'pending'; task.dnd = true; }
    if (action === 'complete') { task.status = 'completed'; task.checklist = checked; }
    if (action === 'inspect_pass') task.inspection_status = 'passed';
    if (action === 'inspect_fail') { task.status = 'pending'; task.dnd = false; task.inspection_status = null; task.reclean_count = (task.reclean_count || 0) + 1; task.inspection_notes = notes; }
    renderWorkspace();
    queueTaskAction(task.id, action, task.updated_at, checked, notes).then(() => {
      if (!navigator.onLine) notify('Saved on this device. It will sync automatically when online.', 'info');
    }).catch(() => { Object.assign(task, previous); renderWorkspace(); notify('This task change could not be saved locally.', 'error'); });
  });

  window.addEventListener('online', () => { setConnectionState(); syncOutbox().then(() => fetchSnapshot()); });
  window.addEventListener('offline', setConnectionState);
  setConnectionState();
  updatePending();
  if (app) fetchSnapshot();
  syncOutbox();
  setInterval(() => { if (navigator.onLine) syncOutbox(); }, 30000);
})();
