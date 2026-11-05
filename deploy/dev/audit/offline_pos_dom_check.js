#!/usr/bin/env node
/*
 * Functional check of the offline restaurant terminal.
 *
 * The Blade screen is rendered into jsdom, the queue is replaced by an in-memory
 * recorder, and the real /js/offline-pos.js drives the page: pick a table, tap a
 * dish, answer the side-dish sheet, send the ticket. What we assert is what the
 * server would receive.
 *
 * Run: node deploy/dev/audit/offline_pos_dom_check.js
 * Needs jsdom: npm install --no-save jsdom   (it skips itself when that is absent)
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
  console.log('offline POS terminal: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

let failures = 0;
const ok = (label, condition, detail = '') => {
  if (condition) {
    console.log(`  ✓ ${label}`);
  } else {
    failures += 1;
    console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ''}`);
  }
};

// ── the screen, without Blade ────────────────────────────────────────────────
function renderBlade() {
  const blade = read('resources/views/offline/pos.blade.php');
  const start = blade.indexOf("@section('content')");
  const end = blade.indexOf('@endsection', start);
  let html = blade.slice(start + "@section('content')".length, end);
  html = html.replace(/\{\{--[\s\S]*?--\}\}/g, '');
  html = html.replace(/<x-icon[^>]*\/>/g, '');
  html = html.replace(/\{\{[^}]*\}\}/g, '');
  html = html.replace(/@push\([^)]*\)|@endpush/g, '');
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="csrf-token" content="test-token"></head><body>${html}</body></html>`;
}

const dom = new JSDOM(renderBlade(), { url: 'https://pms.test/offline/pos', runScripts: 'outside-only', pretendToBeVisual: true });
const { window } = dom;
const { document } = window;

// The page would get these from Blade/blade runtime.
const root = document.getElementById('offlinePos');
root.dataset.snapshotUrl = '/offline/pos/snapshot';
root.dataset.canOrder = '1';
root.dataset.businessDate = '2026-11-05';
root.dataset.businessDateLabel = 'Thu, 05 Nov 2026';

const FIXTURE = {
  version: 2,
  generated_at: '2026-11-05T18:30:00+00:00',
  business_date: '2026-11-05',
  can_order: true,
  needs_shift: false,
  outlets: [{ id: 1, name: 'Garden Restaurant', service_charge_pct: 10, tables: [
    { id: 11, table_no: 'T1', seats: 4 },
    { id: 12, table_no: 'T2', seats: 2 },
  ] }],
  categories: [{ id: 1, name: 'Mains' }],
  items: [
    { id: 101, name: 'Grilled chicken', category_id: 1, price: 60, tax_rate: 15, sides: [{ id: 501, name: 'French fries', rate: 12.5 }] },
    { id: 102, name: 'Jollof rice', category_id: 1, price: 45, tax_rate: 15, sides: [] },
  ],
  orders: [],
  stays: [{ id: 9, room_no: '204' }],
};

// ── the queue, recorded instead of written to IndexedDB ─────────────────────
const enqueued = [];
const store = {};
window.PmsOffline = {
  uuid: () => 'uuid-1234',
  snapshot: async () => JSON.parse(JSON.stringify(FIXTURE)),
  enqueue: async (operation, payload, options) => {
    const row = { id: `op-${enqueued.length + 1}`, operation, payload, created_at: Date.now(), status: 'queued', label: options?.label, attempts: 0 };
    enqueued.push(row);
    return row;
  },
  pending: async () => enqueued.map(row => ({ ...row })),
  flush: async () => ({ sent: enqueued.length, applied: [], conflicts: [] }),
  status: () => ({ online: false, syncing: false, queued: enqueued.length, conflicts: 0, rejected: 0, lastSync: null }),
  onChange: fn => { fn(window.PmsOffline.status()); return () => {}; },
  watch: () => {},
  local: {
    get: async key => store[key] ?? null,
    set: async (key, value) => { store[key] = value; return value; },
  },
};

const settle = () => new Promise(done => setTimeout(done, 40));
const click = element => element.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

window.eval(read('public/js/offline-pos.js'));
await settle();

console.log('offline POS terminal (jsdom)');

// ── it draws from the snapshot ───────────────────────────────────────────────
const tables = document.querySelectorAll('[data-pos-tables] .offline-pos-table');
ok('the tables in the snapshot are drawn', tables.length === 2, `found ${tables.length}`);
const items = document.querySelectorAll('[data-pos-items] .offline-pos-item');
ok('the menu is drawn for the first category', items.length === 2, `found ${items.length}`);
ok('the room list carries the in-house room', document.querySelector('[data-pos-stay]').options.length === 2);
ok('the status line says the device is offline', /Offline/i.test(document.querySelector('[data-pos-state]').textContent));

// ── pick a table, add a dish without sides ───────────────────────────────────
click(tables[0]);
await settle();
ok('the table becomes the active bill', document.querySelector('[data-pos-tables] .offline-pos-table').classList.contains('is-active'));

const beforeDish = document.querySelectorAll('[data-pos-items] .offline-pos-item')[1];
click(beforeDish);
await settle();
let lines = document.querySelectorAll('[data-pos-draft] .offline-pos-line');
ok('tapping a dish puts it on the ticket', lines.length === 1, `lines: ${lines.length}`);
ok('the ticket total follows the menu price', document.querySelector('[data-pos-draft-total]').textContent === 'GH₵ 45.00',
  document.querySelector('[data-pos-draft-total]').textContent);

// ── a dish with sides asks for the side first ────────────────────────────────
click(document.querySelectorAll('[data-pos-items] .offline-pos-item')[0]);
await settle();
const sheet = document.querySelector('[data-pos-sides]');
ok('a dish with sides opens the side sheet', !sheet.hidden);
ok('the side sheet names a side dish and its rate', /French fries/.test(sheet.textContent) && /12\.50/.test(sheet.textContent));
ok('nothing is added to the ticket before the side is chosen', document.querySelectorAll('[data-pos-draft] .offline-pos-line').length === 1);

const radio = sheet.querySelector('input[type="radio"]');
radio.checked = true;
click(document.querySelector('[data-pos-sides-add]'));
await settle();
lines = document.querySelectorAll('[data-pos-draft] .offline-pos-line');
ok('the side dish completes the line', lines.length === 2, `lines: ${lines.length}`);
ok('the side-dish rate is on the ticket', document.querySelector('[data-pos-draft-total]').textContent === 'GH₵ 117.50',
  document.querySelector('[data-pos-draft-total]').textContent);

// ── sending queues the intent, not a network call ────────────────────────────
click(document.querySelector('[data-pos-send]'));
await settle();

ok('two intents are queued: open the order, then the ticket', enqueued.length === 2, JSON.stringify(enqueued.map(r => r.operation)));
const [create, kot] = enqueued;
ok('the first intent opens the order', create.operation === 'pos.order.create');
ok('the order carries the device reference', /^ref-/.test(create.payload.local_ref || ''), create.payload.local_ref);
ok('the order carries the table', create.payload.pos_table_id === 11);
ok('the order carries the business date the device was working', create.payload.business_date === '2026-11-05');
ok('the ticket follows the same reference', kot.operation === 'pos.order.kot' && kot.payload.local_ref === create.payload.local_ref);
ok('the ticket carries both dishes', (kot.payload.lines || []).length === 2, JSON.stringify(kot.payload.lines));
const chicken = (kot.payload.lines || []).find(line => line.menu_item_id === 101);
ok('the chosen side dish travels with its dish', chicken && JSON.stringify(chicken.variants) === '[501]', JSON.stringify(chicken));
ok('the ticket names quantities', (kot.payload.lines || []).every(line => line.qty >= 0.5));

// ── the queue is on screen ───────────────────────────────────────────────────
const queueRows = document.querySelectorAll('[data-pos-queue] .offline-pos-queue-row');
ok('the queued intents are listed for the waiter', queueRows.length === 2, `rows: ${queueRows.length}`);
ok('the ticket is emptied after it is queued', document.querySelectorAll('[data-pos-draft] .offline-pos-line').length === 0);

console.log(failures === 0 ? '\nall terminal checks passed' : `\n${failures} terminal check(s) failed`);
process.exit(failures === 0 ? 0 : 1);
