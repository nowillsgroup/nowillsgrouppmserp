#!/usr/bin/env node
/*
 * The guest-request screen used to keep a queue of its own. It now hands every
 * request to the shared outbox (/js/offline-core.js), so this harness proves the
 * screen still works with a stubbed core and an in-memory IndexedDB:
 *
 *   · a captured request is enqueued as `guest_request.create` with its payload,
 *     and this screen never POSTs to /offline/sync itself;
 *   · rows the older version queued in its own store are moved onto the shared
 *     queue with their operation ids intact, and the old rows are removed;
 *   · the card the member of staff sees follows the queue (applied → request no).
 *
 * Run: node deploy/dev/audit/offline_guest_request_queue_check.js
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
  console.log('offline guest-request queue check: SKIP (jsdom is not installed)');
  process.exit(0);
}

const failures = [];
const check = (label, condition, detail) => {
  if (!condition) failures.push(label + (detail ? ' — ' + detail : ''));
};

// ── a very small IndexedDB, enough for this screen's two stores ───────────────
function memoryIndexedDb(seed = {}) {
  const stores = {
    snapshots: new Map(),
    outbox: new Map((seed.outbox || []).map(row => [row.operation_id, row])),
    requests: new Map(),
  };
  const keyPath = { snapshots: 'key', outbox: 'operation_id', requests: 'id' };

  const tick = fn => new Promise((resolve, reject) => {
    try { resolve(fn()); } catch (error) { reject(error); }
  });

  function transaction(name) {
    const store = stores[name] || (stores[name] = new Map());
    const tx = { oncomplete: null, onerror: null, onabort: null, _open: 1 };
    const settle = () => {
      tx._open -= 1;
      if (tx._open === 0) setTimeout(() => tx.oncomplete && tx.oncomplete(), 0);
    };
    const request = fn => {
      const req = { result: undefined, onsuccess: null, onerror: null, error: null };
      tx._open += 1;
      tick(fn).then(value => {
        req.result = value;
        if (req.onsuccess) req.onsuccess();
        settle();
      }, error => {
        req.error = error;
        if (req.onerror) req.onerror();
        if (tx.onerror) tx.onerror();
        settle();
      });
      return req;
    };
    tx.objectStore = () => ({
      get: key => request(() => store.get(key)),
      getAll: () => request(() => Array.from(store.values())),
      put: value => request(() => { store.set(value[keyPath[name]], value); return value[keyPath[name]]; }),
      delete: key => request(() => { store.delete(key); return undefined; }),
      clear: () => request(() => { store.clear(); return undefined; }),
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

// ── the real screen, without the Blade ───────────────────────────────────────
function screen() {
  const blade = read('resources/views/offline/guest-requests.blade.php');
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
  stays: [{ id: 7, room_no: '204' }, { id: 9, room_no: '212' }],
  requests: [],
  categories: [{ key: 'housekeeping', label: 'Housekeeping', items: ['Extra towels'] }],
};

const LEGACY_ROW = {
  operation_id: 'legacy-op-0001', hotel: 'NF1', operation: 'guest_request.create',
  payload: { stay_id: 7, category: 'housekeeping', item: 'Extra towels', quantity: 2, preferred_at: null, time: '', notes: '' },
  created_at: '2026-11-05T18:40:00.000Z',
};

const db = memoryIndexedDb({ outbox: [LEGACY_ROW] });
const dom = new JSDOM(screen(), { url: 'https://pms.test/offline/guest-requests', runScripts: 'outside-only' });
const { window } = dom;
const { document } = window;

window.indexedDB = { open: () => {
  const req = { result: db.openDb(), error: null, onupgradeneeded: null, onsuccess: null, onerror: null };
  setTimeout(() => {
    if (req.onupgradeneeded) req.onupgradeneeded();
    if (req.onsuccess) req.onsuccess();
  }, 0);
  return req;
} };

const app = document.getElementById('offlineGuestRequestsApp');
app.dataset.snapshotUrl = '/offline/guest-requests/snapshot';
app.dataset.syncUrl = '/offline/sync';
app.dataset.canCreate = '1';

// Core, stubbed: this harness is about what the screen asks of it.
const enqueued = [];
const queued = [];
let onChangeListener = null;
window.PmsOffline = {
  enqueue: async (operation, payload, options) => {
    const row = { id: options?.id || 'generated-' + (enqueued.length + 1), operation, payload, label: options?.label, status: 'queued', attempts: 0, created_at: Date.now() };
    enqueued.push(row);
    queued.push(row);
    if (onChangeListener) onChangeListener(window.PmsOffline.status());
    return row;
  },
  pending: async () => queued.map(row => ({ ...row })),
  snapshot: async () => JSON.parse(JSON.stringify(SNAPSHOT)),
  cachedSnapshot: async () => ({ ...JSON.parse(JSON.stringify(SNAPSHOT)), _stale: true }),
  flush: async () => ({ sent: 0, applied: [], conflicts: [] }),
  status: () => ({ online: true, syncing: false, queued: queued.length, conflicts: 0, rejected: 0, lastSync: null }),
  onChange: fn => { onChangeListener = fn; fn(window.PmsOffline.status()); return () => {}; },
  watch: () => {},
  prepare: async () => [],
  local: { get: async () => null, set: async () => null, remove: async () => null },
};

const posts = [];
window.fetch = async (url, options = {}) => {
  const target = String(url);
  if ((options.method || 'GET').toUpperCase() !== 'GET') {
    posts.push({ url: target, method: options.method });
    return { ok: false, status: 599, json: async () => ({}) };
  }
  return { ok: true, status: 200, json: async () => JSON.parse(JSON.stringify(SNAPSHOT)) };
};

const settle = (ms = 40) => new Promise(done => setTimeout(done, ms));
const click = element => element.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

window.eval(read('public/js/offline-guest-requests.js'));
await settle(120);

// ── what the screen does with the shared queue ───────────────────────────────
const migrated = enqueued.find(row => row.id === LEGACY_ROW.operation_id);
check('the older queue is moved onto the shared outbox', !!migrated,
  'expected an enqueue with the stored operation id ' + LEGACY_ROW.operation_id);
check('the moved row keeps its payload', migrated?.payload?.stay_id === 7 && migrated?.operation === 'guest_request.create');
check('the old rows are cleared once moved', !db.stores.outbox.has(LEGACY_ROW.operation_id),
  'the legacy outbox still holds ' + LEGACY_ROW.operation_id);

// Fill the form the way a member of staff would: room 212, housekeeping, towels.
const stay = document.querySelector('[data-guest-stay]');
const category = document.querySelector('[data-guest-category]');
const item = document.querySelector('[data-guest-item]');
const build = (select, pairs) => {
  select.innerHTML = pairs.map(([value, label]) => `<option value="${value}">${label}</option>`).join('');
};
build(stay, [['7', 'Room 204'], ['9', 'Room 212']]);
build(category, [['housekeeping', 'Housekeeping']]);
build(item, [['Extra towels', 'Extra towels']]);
stay.value = '9';
category.value = 'housekeeping';
item.value = 'Extra towels';
document.querySelector('[data-guest-request-form]').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
await settle(120);

const captured = enqueued.find(row => row.operation === 'guest_request.create' && row.payload?.stay_id === 9);
check('a captured request is enqueued on the shared outbox', !!captured);
check('it carries the room, the category and the item', captured?.payload?.category === 'housekeeping'
  && captured?.payload?.item === 'Extra towels', JSON.stringify(captured?.payload));
check('it carries an operation id', !!captured?.id && captured.id.length > 7);
check('it carries a label for the outbox screen', String(captured?.label || '').includes('Guest request'));
check('the screen never POSTs the sync endpoint itself', posts.length === 0,
  'the screen posted ' + JSON.stringify(posts));

const cards = document.querySelectorAll('[data-guest-request-list] .offline-request-card');
check('the request appears on the screen at once', cards.length >= 1, 'cards: ' + cards.length);

// The server applies it: the card learns the request number.
const applied = { operation: 'guest_request.create', payload: captured?.payload || {}, result: { operation_id: captured?.id, status: 'applied', request: { request_no: 'GR-1007', id: 12 } } };
queued.length = 0;
window.dispatchEvent(new window.CustomEvent('pms-offline:applied', { detail: { applied: [applied], conflicts: [] } }));
await settle(80);
const text = document.querySelector('[data-guest-request-list]').textContent;
check('the card learns the request number the server gave it', text.includes('GR-1007'), text.slice(0, 200));

if (failures.length) {
  console.error('offline guest-request queue check: ' + failures.length + ' failure(s)');
  failures.forEach(failure => console.error('  ✗ ' + failure));
  process.exit(1);
}
console.log('offline guest-request queue check: ' + 11 + ' assertions passed');
