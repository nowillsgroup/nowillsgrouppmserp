#!/usr/bin/env node
/*
 * The offline hub is the page the service worker falls back to, so it has to work
 * with nothing but the device: no network, no server-rendered data, no surprises.
 *
 * This harness renders the real hub (its Blade content and its inline script) in
 * jsdom with browser APIs stubbed, and checks that:
 *
 *   · the device health card reports the worker, the cache, storage, Background
 *     Sync and how old each saved list is — including telling the truth when the
 *     browser has no Background Sync;
 *   · Prepare now asks the server what to prepare, pulls each slice through the
 *     shared queue, records when it happened and marks each screen ready;
 *   · the queue summary and rows come from the device, not from the server.
 *
 * Run: node deploy/dev/audit/offline_hub_check.js
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
  console.log('offline hub check: SKIP (jsdom is not installed)');
  process.exit(0);
}

const failures = [];
let checks = 0;
const check = (label, condition, detail) => {
  checks += 1;
  if (!condition) failures.push(label + (detail ? ' — ' + detail : ''));
};

const blade = read('resources/views/offline/index.blade.php');
const contentStart = blade.indexOf("@section('content')");
const contentEnd = blade.indexOf('@endsection', contentStart);
let html = blade.slice(contentStart + "@section('content')".length, contentEnd);
html = html.replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/<x-icon[^>]*\/>/g, '').replace(/\{\{[^}]*\}\}/g, '');

const scriptStart = blade.indexOf('<script>');
const scriptEnd = blade.indexOf('</script>', scriptStart);
const script = blade.slice(scriptStart + '<script>'.length, scriptEnd);

const dom = new JSDOM(`<!doctype html><html><body>${html}</body></html>`, {
  url: 'https://pms.test/offline', runScripts: 'outside-only',
});
const { window } = dom;
const { document } = window;

const root = document.querySelector('.offline-hub');
root.dataset.snapshotUrl = '/offline/pos/snapshot';
root.dataset.preparationUrl = '/offline/preparation';

const PLAN = {
  version: 1,
  business_date: '2026-11-05',
  slices: [
    { key: 'pos', label: 'Restaurant orders', page: '/offline/pos', url: '/offline/pos/snapshot', snapshot_key: 'pos' },
    { key: 'frontdesk', label: 'Front desk', page: '/offline/frontdesk', url: '/offline/frontdesk/snapshot', snapshot_key: 'frontdesk' },
  ],
};

// ── browser APIs ────────────────────────────────────────────────────────────
Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => false });
window.navigator.serviceWorker = {
  getRegistration: async () => ({ active: { state: 'activated' } }),
  controller: {},
  ready: Promise.resolve({ sync: { register: async () => {} } }),
};
window.caches = {
  keys: async () => ['nowills-pms-v4', 'other-cache'],
  open: async () => ({ keys: async () => new Array(9).fill({}) }),
};
window.navigator.storage = { estimate: async () => ({ usage: 4.2 * 1024 * 1024, quota: 2 * 1024 * 1024 * 1024 }) };

const prepared = [];
const queue = [
  { id: 'op-1', operation: 'pos.order.kot', label: 'Sent dishes to the kitchen · T1', status: 'queued', created_at: Date.now() },
  { id: 'op-2', operation: 'frontdesk.checkin', label: 'Check in Kwabena Osei', status: 'rejected', error: 'Room 103 was taken by the time this check-in reached the server.', created_at: Date.now() - 60000 },
];
const store = {};

window.PmsOffline = {
  status: () => ({ online: false, syncing: false, queued: 1, conflicts: 0, rejected: 1, lastSync: null }),
  pending: async () => queue.map(row => ({ ...row })),
  onChange: fn => { fn(window.PmsOffline.status()); return () => {}; },
  flush: async () => ({ sent: 0 }),
  prepare: async plan => {
    prepared.push(plan);
    return plan.map(slice => ({ key: slice.key, label: slice.label, page: true, snapshot: true, error: null }));
  },
  cachedSnapshot: async key => ({ _saved_at: '2026-11-05T19:05:00+00:00', _stale: true, key }),
  local: {
    get: async key => store[key] ?? null,
    set: async (key, value) => { store[key] = value; return value; },
    remove: async key => { delete store[key]; },
  },
};

window.fetch = async url => {
  if (String(url).includes('/offline/preparation')) {
    return { ok: true, status: 200, json: async () => PLAN };
  }
  return { ok: true, status: 200, json: async () => ({}) };
};

const settle = (ms = 60) => new Promise(done => setTimeout(done, ms));
const click = element => element.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

window.eval(script);
await settle(150);

// ── this device ─────────────────────────────────────────────────────────────
const health = root.querySelector('[data-hub-health]').textContent;
check('the worker is reported', /Service worker/.test(health) && /controlling this page/.test(health), health.slice(0, 200));
check('the cache and its file count are reported', /nowills-pms-v4/.test(health) && /9 file\(s\)/.test(health), health.slice(0, 300));
check('storage is reported as used of quota', /4\.2 MB used of 2\.0 GB/.test(health), health.slice(0, 400));
check('a browser without Background Sync is told the truth', /Not in this browser/.test(health), health.slice(0, 500));
check('the age of each saved list is shown', /saved 05 Nov, 19:05/.test(health), health.slice(-300));

// ── the queue comes from the device ─────────────────────────────────────────
const summary = root.querySelector('[data-hub-summary]').textContent;
check('the summary counts what is waiting and what needs a person', /Waiting to send/.test(summary) && /Needs a person/.test(summary), summary);
const queueText = root.querySelector('[data-hub-queue]').textContent;
check('queued and refused work is listed', /Sent dishes to the kitchen/.test(queueText) && /Room 103 was taken/.test(queueText), queueText.slice(0, 200));
check('the hub says it is offline', /Offline/.test(root.querySelector('[data-hub-state]').textContent));

// ── prepare now ─────────────────────────────────────────────────────────────
click(root.querySelector('[data-hub-prepare]'));
await settle(200);
check('preparation asks the queue to pull the plan', prepared.length === 1 && prepared[0].length === 2, JSON.stringify(prepared));
check('the result is kept for the next visit', !!store.prepared && (store.prepared.slices || []).length === 2, JSON.stringify(store.prepared));
const prepareText = root.querySelector('[data-hub-prepare-list]').textContent;
check('each screen is marked ready', /Restaurant orders/.test(prepareText) && /Front desk/.test(prepareText) && /ready/.test(prepareText), prepareText);
check('the note says the device is ready', /Ready\. Every screen/.test(root.querySelector('[data-hub-prepare-note]').textContent));

if (failures.length) {
  console.error('offline hub check: ' + failures.length + ' failure(s)');
  failures.forEach(failure => console.error('  ✗ ' + failure));
  process.exit(1);
}
console.log('offline hub check: ' + checks + ' assertions passed');
