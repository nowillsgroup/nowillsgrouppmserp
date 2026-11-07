#!/usr/bin/env node
/*
 * Builds deploy/dev/previews/offline-pos.html from the real screen.
 *
 * The Blade view is rendered, the real /js/offline-pos.js drives it with a sample
 * snapshot (table picked, two dishes on the ticket, one of them with a side dish),
 * and the result is frozen as a static page for the preview server. Nobody types
 * this markup by hand, so the preview cannot drift from the screen.
 *
 * Run: node deploy/dev/build-offline-pos-preview.js [--check]
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = resolve(ROOT, 'deploy/dev/previews/offline-pos.html');
const read = path => readFileSync(resolve(ROOT, path), 'utf8');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('offline POS preview: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

function screen() {
  const blade = read('resources/views/offline/pos.blade.php');
  const start = blade.indexOf("@section('content')");
  const end = blade.indexOf('@endsection', start);
  let html = blade.slice(start + "@section('content')".length, end);
  html = html.replace(/\{\{--[\s\S]*?--\}\}/g, '');
  html = html.replace(/<x-icon[^>]*\/>/g, '');
  html = html.replace(/\{\{[^}]*\}\}/g, '');
  return `<!doctype html><html><head><meta charset="utf-8"></head><body>${html}</body></html>`;
}

const SNAPSHOT = {
  version: 2,
  generated_at: '2026-11-05T19:20:00+00:00',
  business_date: '2026-11-05',
  can_order: true,
  needs_shift: false,
  outlets: [{ id: 1, name: 'Garden Restaurant', service_charge_pct: 10, tables: [
    { id: 11, table_no: 'T1', seats: 4 }, { id: 12, table_no: 'T2', seats: 2 },
    { id: 13, table_no: 'T3', seats: 4 }, { id: 14, table_no: 'T5', seats: 6 },
    { id: 15, table_no: 'T7', seats: 2 }, { id: 16, table_no: 'T9', seats: 4 },
  ] }],
  categories: [{ id: 1, name: 'Mains' }, { id: 2, name: 'Grills' }, { id: 3, name: 'Desserts' }],
  items: [
    { id: 101, name: 'Grilled chicken', category_id: 1, price: 60, tax_rate: 15, sides: [{ id: 501, name: 'French fries', rate: 12.5 }, { id: 502, name: 'Garden salad', rate: 0 }] },
    { id: 102, name: 'Jollof rice', category_id: 1, price: 45, tax_rate: 15, sides: [] },
    { id: 103, name: 'Banku & tilapia', category_id: 1, price: 85, tax_rate: 15, sides: [] },
    { id: 104, name: 'Waakye special', category_id: 1, price: 55, tax_rate: 15, sides: [] },
    { id: 105, name: 'Kejetia kelewele', category_id: 1, price: 25, tax_rate: 15, sides: [] },
    { id: 106, name: 'Fried rice & chicken', category_id: 1, price: 70, tax_rate: 15, sides: [] },
  ],
  orders: [],
  stays: [{ id: 9, room_no: '204' }, { id: 10, room_no: '212' }],
};

const dom = new JSDOM(screen(), { url: 'https://pms.test/offline/pos', runScripts: 'outside-only' });
const { window } = dom;
const { document } = window;

const root = document.getElementById('offlinePos');
root.dataset.snapshotUrl = '/offline/pos/snapshot';
root.dataset.canOrder = '1';
root.dataset.businessDate = '2026-11-05';
root.dataset.businessDateLabel = 'Thu, 05 Nov 2026';

const queue = [];
const store = {};
window.PmsOffline = {
  uuid: () => 'uuid-preview',
  snapshot: async () => JSON.parse(JSON.stringify(SNAPSHOT)),
  enqueue: async (operation, payload, options) => {
    const row = { id: `op-${queue.length + 1}`, operation, payload, created_at: Date.now(), status: 'queued', label: options?.label, attempts: 0 };
    queue.push(row);
    return row;
  },
  pending: async () => queue.map(row => ({ ...row })),
  flush: async () => ({ sent: 0, applied: [], conflicts: [] }),
  status: () => ({ online: false, syncing: false, queued: 0, conflicts: 0, rejected: 0, lastSync: null }),
  onChange: fn => { fn(window.PmsOffline.status()); return () => {}; },
  watch: () => {},
  local: { get: async key => store[key] ?? null, set: async (key, value) => { store[key] = value; return value; } },
};

const settle = () => new Promise(done => setTimeout(done, 30));
const click = element => element.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

window.eval(read('public/js/offline-pos.js'));
await settle();

// Table T1, jollof, then grilled chicken with french fries, then a ticket sent.
click(document.querySelectorAll('[data-pos-tables] .offline-pos-table')[0]);
await settle();
click(document.querySelectorAll('[data-pos-items] .offline-pos-item')[1]);
await settle();
click(document.querySelectorAll('[data-pos-items] .offline-pos-item')[0]);
await settle();
const sheet = document.querySelector('[data-pos-sides]');
// v9.30 the sheet ticks sides, so the dish can carry a charged one and a free one together;
// v9.31 it also counts them, so the screenshot shows a double portion beside an included one
sheet.querySelectorAll('input[type="checkbox"]').forEach(box => { box.checked = true; });
click(sheet.querySelector('.offline-pos-side-row [data-side-step="1"]'));
click(document.querySelector('[data-pos-sides-add]'));
await settle();

const body = document.getElementById('offlinePos').outerHTML;
const modal = document.querySelector('[data-pos-sides]').outerHTML;

const page = `<!doctype html>
<!-- Generated by deploy/dev/build-offline-pos-preview.js from resources/views/offline/pos.blade.php
     and the real public/js/offline-pos.js, driven with a sample snapshot. The figures are a
     fixed sample: the live screen reads the tenant's own menu, tables and running orders. -->
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Offline POS terminal · Nowills PMS</title>
<link rel="stylesheet" href="/css/app.css">
<link rel="stylesheet" href="/css/offline.css">
<link rel="stylesheet" href="/css/dashboard.css">
<style>body{background:#eef2ef;margin:0;padding:clamp(12px,2vw,24px)}.offline-pos{max-width:1500px;margin:0 auto}</style>
</head>
<body class="is-offline">
${body}
${modal.replace('hidden', 'hidden')}
</body>
</html>
`;

if (process.argv.includes('--check')) {
  if (!existsSync(OUT) || readFileSync(OUT, 'utf8') !== page) {
    console.error('offline POS preview is stale — run node deploy/dev/build-offline-pos-preview.js');
    process.exit(1);
  }
  console.log('offline POS preview is current');
  process.exit(0);
}

writeFileSync(OUT, page);
console.log(`wrote deploy/dev/previews/offline-pos.html (${Buffer.byteLength(page)} bytes)`);
