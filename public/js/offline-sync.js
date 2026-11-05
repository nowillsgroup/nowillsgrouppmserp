/*
 * Nowills PMS — housekeeping workspace, offline.
 *
 * Room statuses, task actions, inspections and maintenance issues are written into
 * the shared outbox in /js/offline-core.js, and the room/task snapshot is read from
 * the shared snapshot cache. That gives this screen the same backoff, the same
 * Background Sync, the same retry/discard review on /offline/queue and the same
 * one-press preparation as the restaurant, front desk and guest requests.
 *
 * Rows and the snapshot this file used to keep in `nowills-pms-offline` are moved
 * onto the shared stores on first load, keeping each operation id so the server
 * still recognises work it may already have applied.
 */
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
  const core = window.PmsOffline || null;
  // The shared cache key for this screen's snapshot; preparation uses the same one.
  const SNAPSHOT_KEY = 'housekeeping';
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
      let result;
      request.onsuccess = () => { result = request.result; };
      request.onerror = () => { db.close(); reject(request.error || new Error('Offline storage failed.')); };
      tx.oncomplete = () => { db.close(); resolve(result); };
      tx.onerror = () => { db.close(); reject(tx.error || new Error('Offline storage transaction failed.')); };
      tx.onabort = () => { db.close(); reject(tx.error || new Error('Offline storage transaction aborted.')); };
    });
  }

  const allOutbox = async () => (await dbRequest(OUTBOX, 'readonly', store => store.getAll())).filter(row => row.hotel === hotel);
  const deleteOutbox = id => dbRequest(OUTBOX, 'readwrite', store => store.delete(id));

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

  const OWN = ['housekeeping.room_status', 'housekeeping.task', 'housekeeping.maintenance'];

  async function ownRows() {
    if (!core) return [];
    const rows = await core.pending().catch(() => []);
    return rows.filter(row => OWN.includes(row.operation));
  }

  async function updatePending() {
    const rows = core ? await ownRows() : await allOutbox();
    const queued = core ? rows.filter(row => row.status === 'queued' || row.status === 'retry') : rows;
    const count = queued.length;
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

  // The server's answer is applied to what is on screen; the saved copy in the
  // shared cache is refreshed from the server after a sync, never guessed at here.
  function updateRoomInSnapshot(room) {
    if (!snapshot?.rooms || !room) return;
    const index = snapshot.rooms.findIndex(item => Number(item.id) === Number(room.id));
    if (index >= 0) snapshot.rooms[index] = { ...snapshot.rooms[index], ...room };
  }

  function updateTaskInSnapshot(task) {
    if (!snapshot?.tasks || !task) return;
    const index = snapshot.tasks.findIndex(item => Number(item.id) === Number(task.id));
    if (index >= 0) snapshot.tasks[index] = { ...snapshot.tasks[index], ...task };
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

  /** Send what this device holds, through the shared queue. */
  async function syncOutbox() {
    if (!core || !navigator.onLine) return;
    const before = (await ownRows()).filter(row => row.status === 'queued' || row.status === 'retry').length;
    await core.flush({ force: true });
    const after = await ownRows();
    const left = after.filter(row => row.status === 'queued' || row.status === 'retry').length;
    await updatePending();
    if (workspaceState) {
      workspaceState.textContent = navigator.onLine
        ? (left ? 'Online · some changes remain pending' : 'Online · ready for offline work')
        : 'Offline · using the last saved snapshot';
    }
    if (before && !left) notify(before + ' housekeeping change' + (before === 1 ? '' : 's') + ' synced.', 'info');
    if (app) await fetchSnapshot();
  }

  /**
   * Move what the older version of this file queued in its own store onto the
   * shared queue, keeping each operation id so the server still recognises work it
   * may already have applied.
   */
  async function migrateLegacyQueue() {
    if (!core) return 0;
    let moved = 0;
    try {
      const rows = await allOutbox();
      for (const row of rows) {
        if (!OWN.includes(row.operation)) continue;
        await core.enqueue(row.operation, row.payload, {
          id: row.operation_id,
          baseUpdatedAt: row.base_updated_at,
          label: row.operation === 'housekeeping.room_status'
            ? 'Room status · ' + (row.payload?.hk_status || '').replace('_', '-')
            : row.operation === 'housekeeping.task'
              ? 'Task · ' + (row.payload?.action || '')
              : 'Maintenance · ' + (row.payload?.title || 'issue'),
        });
        await deleteOutbox(row.operation_id);
        moved += 1;
      }
    } catch (_) {
      // A device with no legacy store simply has nothing to move.
    }
    return moved;
  }

  async function queueRoomStatus(roomId, status, baseUpdatedAt) {
    if (!core) throw new Error('The offline queue could not be loaded on this device.');
    // One room status per room on the device: the newest tap replaces the older one.
    const current = await core.pending().catch(() => []);
    await Promise.all(current
      .filter(row => row.operation === 'housekeeping.room_status' && Number(row.payload?.room_id) === Number(roomId))
      .map(row => core.discard(row.id)));
    await core.enqueue('housekeeping.room_status', { room_id: Number(roomId), hk_status: status }, {
      baseUpdatedAt,
      label: 'Room ' + (snapshot?.rooms?.find(item => Number(item.id) === Number(roomId))?.room_no || roomId)
        + ' → ' + String(status).replace('_', '-'),
    });
    await updatePending();
    await syncOutbox();
  }

  async function queueTaskAction(taskId, action, baseUpdatedAt, checked = [], notes = null) {
    if (!core) throw new Error('The offline queue could not be loaded on this device.');
    const current = await core.pending().catch(() => []);
    if (current.some(row => row.operation === 'housekeeping.task' && Number(row.payload?.task_id) === Number(taskId))) {
      throw new Error('This task already has a queued change. Sync it before recording another action.');
    }
    await core.enqueue('housekeeping.task', { task_id: Number(taskId), action, checked, notes }, {
      baseUpdatedAt,
      label: 'Task · ' + action + ' · room ' + (snapshot?.tasks?.find(item => Number(item.id) === Number(taskId))?.room_no || ''),
    });
    await updatePending();
    await syncOutbox();
  }

  async function queueMaintenance(taskId, category, title, priority) {
    if (!core) throw new Error('The offline queue could not be loaded on this device.');
    await core.enqueue('housekeeping.maintenance', {
      task_id: Number(taskId), category, title, priority, description: '',
    }, { label: 'Maintenance · ' + title });
    await updatePending();
    await syncOutbox();
  }

  async function fetchSnapshot() {
    if (!app || !core) return;
    try {
      snapshot = await core.snapshot(SNAPSHOT_KEY, snapshotUrl);
      renderWorkspace();
      if (workspaceState) workspaceState.textContent = navigator.onLine ? 'Online · ready for offline work' : 'Offline · using the last saved snapshot';
    } catch (error) {
      try {
        snapshot = await core.cachedSnapshot(SNAPSHOT_KEY);
        renderWorkspace();
        if (workspaceState) workspaceState.textContent = 'Offline · using the last saved snapshot';
      } catch (_) {
        snapshot = null;
        renderWorkspace();
        if (workspaceState) workspaceState.textContent = 'Connect once to download room data';
      }
    }
    await applyQueued();
  }

  /** Show what the queue says about this screen's work, without inventing states. */
  async function applyQueued() {
    if (!core) return;
    const rows = await ownRows();
    const roomRows = rows.filter(row => row.operation === 'housekeeping.room_status');
    const queued = roomRows.filter(row => row.status === 'queued' || row.status === 'retry');
    if (queued.length && roomList) {
      roomList.querySelectorAll('[data-room-id]').forEach(card => {
        if (queued.some(row => Number(row.payload?.room_id) === Number(card.dataset.roomId))) card.classList.add('is-pending');
      });
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
    // The buttons themselves carry data-task-id, so the card is found by its class:
    // closest('[data-task-id]') would return the button and lose the checklist and notes.
    const card = button.closest('.offline-task-card');
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
    // The buttons themselves carry data-task-id, so the card is found by its class:
    // closest('[data-task-id]') would return the button and lose the checklist and notes.
    const card = button.closest('.offline-task-card');
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
    }).catch(error => { Object.assign(task, previous); renderWorkspace(); notify(error?.message || 'This task change could not be saved locally.', 'error'); });
  });

  window.addEventListener('online', () => { setConnectionState(); fetchSnapshot(); });
  window.addEventListener('offline', setConnectionState);

  // The queue sends itself; this screen follows what the queue says happened.
  if (core) {
    core.onChange(() => { updatePending(); applyQueued(); });
    window.addEventListener('pms-offline:applied', event => {
      (event.detail?.applied || []).forEach(entry => {
        if (!OWN.includes(entry.operation)) return;
        const result = entry.result || {};
        if (result.room) applyRoomToPage(result.room);
        if (result.task) applyTaskToPage(result.task);
        if (result.ticket) notify('Maintenance ticket ' + (result.ticket.ticket_no || '') + ' synced.', 'info');
      });
      updatePending();
    });
  }

  setConnectionState();
  updatePending();
  migrateLegacyQueue().then(moved => {
    if (moved) notify(moved + ' pending change' + (moved === 1 ? ' was' : 's were') + ' moved onto the shared outbox.', 'info');
    updatePending();
    if (app) fetchSnapshot().then(syncOutbox);
  });
})();
