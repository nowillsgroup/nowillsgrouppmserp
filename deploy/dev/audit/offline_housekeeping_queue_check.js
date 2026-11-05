#!/usr/bin/env node
/*
 * The housekeeping workspace used to keep its own outbox and its own snapshot
 * store. It now writes into the shared queue (/js/offline-core.js) and reads its
 * snapshot from the shared cache, so this harness drives the real
 * /js/offline-sync.js against a stubbed core and an in-memory IndexedDB:
 *
 *   · rows the older version queued are moved over with operation id and the
 *     base timestamp kept, and removed from the old store;
 *   · a room status tap is queued as `housekeeping.room_status` with the room, the
 *     status and the base timestamp; a second tap on the same room replaces it;
 *   · a second action on a task that already has a queued change is refused;
 *   · a maintenance issue is queued with its title, category and priority;
 *   · the screen never POSTs the sync endpoint itself.
 *
 * Run: node deploy/dev/audit/offline_housekeeping_queue_check.js
 */
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const read = path => readFileSync(resolve(ROOT, path), 'utf8');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('offline housekeeping queue check: SKIP (jsdom is not installed)');
  process.exit(0);
}

const failures = [];
let checks = 0;
const check = (label, condition, detail) => {
  checks += 1;
  if (!condition) failures.push(label + (detail ? ' — ' + detail : ''));
};

// ── a very small IndexedDB, enough for the one legacy store ──────────────────
function memoryIndexedDb(seed = {}) {
  const stores = { snapshots: new Map(), outbox: new Map(), requests: new Map() };
  const keyPath = { snapshots: 'key', outbox: 'operation_id', requests: 'id' };
  for (const row of seed.outbox || []) stores.outbox.set(row.operation_id, row);

  const tick = fn => new Promise((res, rej) => { try { res(fn()); } catch (e) { rej(e); } });

  function transaction(name) {
    const store = stores[name] instanceof Map ? stores[name] : (stores[name] = new Map());
    const tx = { oncomplete: null, onerror: null, onabort: null, _open: 1 };
    const settle = () => { tx._open -= 1; if (tx._open === 0) setTimeout(() => tx.oncomplete && tx.oncomplete(), 0); };
    const request = fn => {
      const req = { result: undefined, onsuccess: null, onerror: null, error: null };
      tx._open += 1;
      tick(fn).then(value => { req.result = value; if (req.onsuccess) req.onsuccess(); settle(); },
        error => { req.error = error; if (req.onerror) req.onerror(); settle(); });
      return req;
    };
    tx.objectStore = () => ({
      get: key => request(() => store.get(key)),
      getAll: () => request(() => Array.from(store.values())),
      put: value => request(() => { store.set(value[keyPath[name]], value); return value[keyPath[name]]; }),
      delete: key => request(() => { store.delete(key); return undefined; }),
    });
    settle();
    return tx;
  }

  return {
    stores,
    openDb: () => ({
      objectStoreNames: { contains: name => name in stores },
      createObjectStore: name => { stores[name] = stores[name] || new Map(); },
      transaction,
      close: () => {},
    }),
  };
}

function screen() {
  const blade = read('resources/views/offline/housekeeping.blade.php');
  const start = blade.indexOf("@section('content')");
  const end = blade.indexOf('@endsection', start);
  let html = blade.slice(start + "@section('content')".length, end);
  html = html.replace(/\{\{--[\s\S]*?--\}\}/g, '');
  html = html.replace(/<x-icon[^>]*\/>/g, '');
  html = html.replace(/\{\{[^}]*\}\}/g, '');
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="hotel-code" content="NF1"></head><body>${html}</body></html>`;
}

const SNAPSHOT = {
  version: 1,
  generated_at: '2026-11-05T19:50:00+00:00',
  business_date: '2026-11-05',
  can_edit: true,
  checklist_required: true,
  checklist_items: ['Beds', 'Bath'],
  rooms: [
    { id: 5, room_no: '204', room_type: 'Deluxe King', floor: 'Second', status: 'occupied', housekeeping: 'dirty', updated_at: '2026-11-05T18:00:00+00:00', guest: 'Yaw Antwi', departure: '2026-11-07' },
    { id: 6, room_no: '205', room_type: 'Deluxe King', floor: 'Second', status: 'vacant', housekeeping: 'clean', updated_at: '2026-11-05T16:30:00+00:00' },
  ],
  tasks: [
    { id: 21, room_id: 5, room_no: '204', task_type: 'departure', task_label: 'Departure clean', status: 'pending', priority: 'high', updated_at: '2026-11-05T18:10:00+00:00', notes: null, checklist: [] },
  ],
};

const LEGACY_ROWS = [
  { operation_id: 'legacy-hk-0001', hotel: 'NF1', operation: 'housekeeping.room_status', payload: { room_id: 5, hk_status: 'inspected' }, base_updated_at: '2026-11-05T18:00:00+00:00', created_at: '2026-11-05T18:05:00.000Z' },
];

const db = memoryIndexedDb({ outbox: LEGACY_ROWS });
const dom = new JSDOM(screen(), { url: 'https://pms.test/offline/housekeeping', runScripts: 'outside-only' });
const { window } = dom;
const { document } = window;

window.indexedDB = { open: () => {
  const req = { result: db.openDb(), error: null, onupgradeneeded: null, onsuccess: null, onerror: null };
  setTimeout(() => { if (req.onupgradeneeded) req.onupgradeneeded(); if (req.onsuccess) req.onsuccess(); }, 0);
  return req;
} };

const app = document.getElementById('offlineHousekeepingApp');
app.dataset.snapshotUrl = '/offline/housekeeping/snapshot';
app.dataset.syncUrl = '/offline/sync';
app.dataset.canEdit = '1';

const enqueued = [];
const queued = [];
let onChange = null;
window.PmsOffline = {
  enqueue: async (operation, payload, options) => {
    const row = { id: options?.id || 'generated-' + (enqueued.length + 1), operation, payload, label: options?.label, status: 'queued', attempts: 0, created_at: Date.now(), base_updated_at: options?.baseUpdatedAt };
    enqueued.push(row);
    queued.push(row);
    if (onChange) onChange(window.PmsOffline.status());
    return row;
  },
  discard: async id => {
    const index = queued.findIndex(row => row.id === id);
    if (index >= 0) queued.splice(index, 1);
    return true;
  },
  pending: async () => queued.map(row => ({ ...row })),
  flush: async () => ({ sent: 0, applied: [], conflicts: [] }),
  snapshot: async () => JSON.parse(JSON.stringify(SNAPSHOT)),
  cachedSnapshot: async () => ({ ...JSON.parse(JSON.stringify(SNAPSHOT)), _stale: true }),
  status: () => ({ online: true, syncing: false, queued: queued.length, conflicts: 0, rejected: 0, lastSync: null }),
  onChange: fn => { onChange = fn; fn(window.PmsOffline.status()); return () => {}; },
  watch: () => {},
  prepare: async () => [],
  local: { get: async () => null, set: async () => null, remove: async () => null },
};

const posts = [];
window.fetch = async (url, options = {}) => {
  if ((options.method || 'GET').toUpperCase() !== 'GET') {
    posts.push({ url: String(url), method: options.method });
    return { ok: false, status: 599, json: async () => ({}) };
  }
  return { ok: true, status: 200, json: async () => JSON.parse(JSON.stringify(SNAPSHOT)) };
};

const settle = (ms = 40) => new Promise(done => setTimeout(done, ms));
const click = element => element.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

window.eval(read('public/js/offline-sync.js'));
await settle(150);

// ── the older queue moves over ───────────────────────────────────────────────
const migrated = enqueued.find(row => row.id === LEGACY_ROWS[0].operation_id);
check('the older queue is moved onto the shared outbox', !!migrated);
check('the moved row keeps its base timestamp', migrated?.base_updated_at === LEGACY_ROWS[0].base_updated_at,
  String(migrated?.base_updated_at));
check('the old rows are cleared once moved', !db.stores.outbox.has(LEGACY_ROWS[0].operation_id));

// ── the screen is drawn from the shared snapshot ─────────────────────────────
const cards = document.querySelectorAll('[data-offline-room-list] .offline-room-card');
check('rooms are drawn from the shared snapshot', cards.length === 2, 'cards: ' + cards.length);
check('the tasks are drawn too', document.querySelectorAll('[data-offline-task-list] .offline-task-card').length === 1);

// ── a room status tap ────────────────────────────────────────────────────────
const roomButton = document.querySelector('[data-offline-room-status="clean"][data-room-id="5"]');
check('a room status button is on the card', !!roomButton);
click(roomButton);
await settle(120);

const statusRows = () => enqueued.filter(row => row.operation === 'housekeeping.room_status' && row.id !== LEGACY_ROWS[0].operation_id);
const first = statusRows()[0];
check('the tap is queued with the room, the status and the base timestamp',
  first?.payload?.room_id === 5 && first?.payload?.hk_status === 'clean' && first?.base_updated_at === SNAPSHOT.rooms[0].updated_at,
  JSON.stringify(first));

// A second tap on the same room replaces the first, instead of queueing two.
click(document.querySelector('[data-offline-room-status="touch_up"][data-room-id="5"]'));
await settle(120);
const stillQueued = queued.filter(row => row.operation === 'housekeeping.room_status' && row.payload.room_id === 5);
check('one room status per room stays queued', stillQueued.length === 1, 'queued: ' + stillQueued.length);
check('the newest tap is the one kept', stillQueued[0]?.payload?.hk_status === 'touch_up', stillQueued[0]?.payload?.hk_status);

// ── a task action, then a second one while it is still queued ────────────────
click(document.querySelector('[data-offline-task-action="start"][data-task-id="21"]'));
await settle(120);
const taskRow = enqueued.find(row => row.operation === 'housekeeping.task');
check('the task action is queued', taskRow?.payload?.action === 'start' && taskRow?.payload?.task_id === 21, JSON.stringify(taskRow?.payload));

// A second action while the first is still queued is refused, not stacked.
const liveCard = () => document.querySelector('[data-offline-task-list] .offline-task-card');
const tickAll = () => liveCard().querySelectorAll('[data-offline-check]').forEach(box => { box.checked = true; });
tickAll();
click(liveCard().querySelector('[data-offline-task-action="complete"]'));
await settle(80);
const taskRows = () => enqueued.filter(row => row.operation === 'housekeeping.task');
check('a second action on a queued task is refused', taskRows().length === 1, 'task rows: ' + taskRows().length);
check('the refusal is explained to the member of staff',
  /already has a queued change/.test(document.querySelector('[data-offline-notice]')?.textContent || ''),
  document.querySelector('[data-offline-notice]')?.textContent);

// Once the queue is clear, completing with the whole checklist ticked is accepted.
queued.length = 0;
tickAll();
click(liveCard().querySelector('[data-offline-task-action="complete"]'));
await settle(80);
const complete = taskRows()[1];
check('completing carries the ticked checklist', complete?.payload?.action === 'complete'
  && (complete?.payload?.checked || []).length === 2, JSON.stringify(complete?.payload));

// ── a maintenance issue ──────────────────────────────────────────────────────
// The card is redrawn after the task action, so it is looked up again here.
const issueCard = () => document.querySelector('[data-offline-task-list] .offline-task-card');
issueCard().querySelector('[data-offline-issue-title]').value = 'Shower head leaking';
issueCard().querySelector('[data-offline-issue-category]').value = 'plumbing';
issueCard().querySelector('[data-offline-issue-priority]').value = 'urgent';
click(issueCard().querySelector('[data-offline-issue]'));
await settle(120);
const issue = enqueued.find(row => row.operation === 'housekeeping.maintenance');
check('the maintenance issue is queued with its detail',
  issue?.payload?.title === 'Shower head leaking' && issue?.payload?.category === 'plumbing' && issue?.payload?.priority === 'urgent',
  JSON.stringify(issue?.payload));

check('the screen never POSTs the sync endpoint itself', posts.length === 0, JSON.stringify(posts));

if (failures.length) {
  console.error('offline housekeeping queue check: ' + failures.length + ' failure(s)');
  failures.forEach(failure => console.error('  ✗ ' + failure));
  process.exit(1);
}
console.log('offline housekeeping queue check: ' + checks + ' assertions passed');
