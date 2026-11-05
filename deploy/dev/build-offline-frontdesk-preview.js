#!/usr/bin/env node
/*
 * Builds deploy/dev/previews/offline-frontdesk.html from the real screen.
 *
 * The Blade view is rendered, the real /js/offline-frontdesk.js drives it with a
 * sample snapshot (one arrival checked in, one minibar charge posted, both waiting
 * in the outbox), and the result is frozen as a static page for the preview server.
 * Nobody types this markup by hand, so the preview cannot drift from the screen.
 *
 * Run: node deploy/dev/build-offline-frontdesk-preview.js [--check]
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = resolve(ROOT, 'deploy/dev/previews/offline-frontdesk.html');
const read = path => readFileSync(resolve(ROOT, path), 'utf8');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('offline front desk preview: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

function screen() {
  const blade = read('resources/views/offline/frontdesk.blade.php');
  const start = blade.indexOf("@section('content')");
  const end = blade.indexOf('@endsection', start);
  let html = blade.slice(start + "@section('content')".length, end);
  html = html.replace(/\{\{--[\s\S]*?--\}\}/g, '');
  html = html.replace(/<x-icon[^>]*\/>/g, '');
  html = html.replace(/\{\{[^}]*\}\}/g, '');
  return `<!doctype html><html><head><meta charset="utf-8"></head><body>${html}</body></html>`;
}

const SNAPSHOT = {
  version: 1,
  generated_at: '2026-11-05T19:24:00+00:00',
  business_date: '2026-11-05',
  hotel: 'NF1',
  staff: 'Desk',
  can_checkin: true,
  can_charge: true,
  arrivals: [
    { stay_id: 41, reservation_no: 'RSV-2418', guest: 'Akosua Mensah', room_type: 'Deluxe King', room_type_id: 2,
      arrival_date: '2026-11-05', departure_date: '2026-11-08', nights: 3, rate: 780, room_id: null },
    { stay_id: 42, reservation_no: 'RSV-2419', guest: 'Kwabena Osei', room_type: 'Standard Twin', room_type_id: 1,
      arrival_date: '2026-11-05', departure_date: '2026-11-06', nights: 1, rate: 520, room_id: null },
    { stay_id: 43, reservation_no: 'RSV-2421', guest: 'Efua Boateng', room_type: 'Executive Suite', room_type_id: 3,
      arrival_date: '2026-11-05', departure_date: '2026-11-10', nights: 5, rate: 1450, room_id: null },
  ],
  in_house: [
    { stay_id: 31, room_id: 5, room_no: '204', guest: 'Yaw Antwi', departure_date: '2026-11-07', balance: 1840 },
    { stay_id: 32, room_id: 8, room_no: '212', guest: 'Adjoa Sarpong', departure_date: '2026-11-06', balance: 960.5 },
    { stay_id: 33, room_id: 11, room_no: '305', guest: 'Kojo Danso', departure_date: '2026-11-09', balance: 2405 },
    { stay_id: 34, room_id: 14, room_no: '311', guest: 'Abena Nyarko', departure_date: '2026-11-08', balance: 615 },
  ],
  rooms: [
    { id: 1, room_no: '101', room_type_id: 1, status: 'vacant', housekeeping: 'clean' },
    { id: 2, room_no: '102', room_type_id: 1, status: 'occupied', housekeeping: 'dirty' },
    { id: 3, room_no: '103', room_type_id: 1, status: 'vacant', housekeeping: 'clean' },
    { id: 4, room_no: '201', room_type_id: 2, status: 'vacant', housekeeping: 'clean' },
    { id: 5, room_no: '204', room_type_id: 2, status: 'occupied', housekeeping: 'dirty' },
    { id: 6, room_no: '205', room_type_id: 2, status: 'vacant', housekeeping: 'inspected' },
    { id: 7, room_no: '208', room_type_id: 2, status: 'out_of_order', housekeeping: 'dirty' },
    { id: 8, room_no: '212', room_type_id: 2, status: 'occupied', housekeeping: 'dirty' },
    { id: 9, room_no: '301', room_type_id: 3, status: 'vacant', housekeeping: 'clean' },
    { id: 10, room_no: '302', room_type_id: 3, status: 'vacant', housekeeping: 'clean' },
  ],
  categories: {
    minibar: 'Minibar', laundry: 'Laundry', restaurant: 'Restaurant', spa: 'Spa', transport: 'Transport',
    misc: 'Sundry', damage: 'Damage', telephone: 'Telephone',
  },
};

const dom = new JSDOM(screen(), { url: 'https://pms.test/offline/frontdesk', runScripts: 'outside-only' });
const { window } = dom;
const { document } = window;

const root = document.getElementById('offlineDesk');
root.dataset.snapshotUrl = '/offline/frontdesk/snapshot';
root.dataset.canCheckin = '1';
root.dataset.canCharge = '1';
root.dataset.businessDate = '2026-11-05';

// The preview shows the screen as it behaves with no connection.
Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => false });

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

window.eval(read('public/js/offline-frontdesk.js'));
await settle();

// The desk checks the first arrival into the first vacant room of the booked type.
root.querySelector('[data-checkin="41"]').setAttribute('data-checkin', '41');
click(root.querySelector('[data-checkin="41"]'));
await settle();

// A minibar charge on room 204.
const staySelect = root.querySelector('[data-charge-stay]');
staySelect.value = '31';
root.querySelector('[data-charge-category]').value = 'minibar';
root.querySelector('[data-charge-description]').value = 'Minibar — 2 bottles of water';
root.querySelector('[data-charge-amount]').value = '45';
click(root.querySelector('[data-charge-post]'));
await settle();

const body = document.getElementById('offlineDesk').outerHTML;

const page = `<!doctype html>
<!-- Generated by deploy/dev/build-offline-frontdesk-preview.js from resources/views/offline/frontdesk.blade.php
     and the real public/js/offline-frontdesk.js, driven with a sample snapshot. The figures are a
     fixed sample: the live screen reads the tenant's own arrivals, in-house rooms and charge list. -->
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Offline front desk · Nowills PMS</title>
<link rel="stylesheet" href="/css/app.css">
<link rel="stylesheet" href="/css/offline.css">
<link rel="stylesheet" href="/css/dashboard.css">
<style>body{background:#eef2ef;margin:0;padding:clamp(12px,2vw,24px)}.offline-desk{max-width:1500px;margin:0 auto}</style>
</head>
<body class="is-offline">
${body}
</body>
</html>
`;

if (process.argv.includes('--check')) {
  if (!existsSync(OUT) || readFileSync(OUT, 'utf8') !== page) {
    console.error('offline front desk preview is stale — run node deploy/dev/build-offline-frontdesk-preview.js');
    process.exit(1);
  }
  console.log('offline front desk preview is current');
  process.exit(0);
}

writeFileSync(OUT, page);
console.log(`wrote deploy/dev/previews/offline-frontdesk.html (${Buffer.byteLength(page)} bytes)`);
