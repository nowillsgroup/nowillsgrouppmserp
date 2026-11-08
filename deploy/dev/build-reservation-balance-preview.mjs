#!/usr/bin/env node
/** Component preview: real reservation posted balance partial with static, illustrative data. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { walkinContext } from './preview/walkin-sample.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const out = path.join(root, 'deploy/dev/previews/reservation-posted-balance.html');
const log = { expressions: 0, fallbacks: 0, failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [] };
const ctx = walkinContext();
// Two room folios and a reservation advance; figures are posted-only, not a checkout quote.
ctx.scope.summary = { folios: 3, charges: 2760, opening: 0, discount: 0, payments: 1920, refunds: 0, balance: 840, room_invoices: 1, details: [
  { id: 1, number: 'FOL-00124', type: 'guest', status: 'open', stay_id: null, room_no: null, invoice_no: null, balance: -1000 },
  { id: 2, number: 'FOL-00125', type: 'guest', status: 'closed', stay_id: 2, room_no: '202', invoice_no: 'INV-00372', balance: 0 },
  { id: 3, number: 'FOL-00126', type: 'guest', status: 'open', stay_id: 3, room_no: '203', invoice_no: null, balance: 1840 },
] };
const renderer = new BladePreview({ root, ctx, log });
const card = renderer.renderFile('reservations/_posted-balance', ctx.scope);
const banner = '<div class="alert alert-info" role="note">Sample component preview only — no live data or checkout actions. Existing room settlement is unchanged.</div>';
const content = `${banner}<div style="max-width:560px">${card}</div>`;
const html = shell(nav().navHtml, spriteSymbols(), content, { title: 'Reservation RES-00124', crumbs: 'Reservations / RES-00124' }).replace(/[ \t]+$/gm, '');
if (log.failed.length || log.errors.length || log.unknownComponents.length || log.unknownDirectives.length) {
  console.error(JSON.stringify(log, null, 2)); process.exit(1);
}
if (process.argv.includes('--check')) {
  if (!fs.existsSync(out) || fs.readFileSync(out, 'utf8') !== html) { console.error('reservation balance preview is stale'); process.exit(1); }
  console.log('reservation posted balance preview is up to date');
} else { fs.writeFileSync(out, html); console.log(`wrote ${out} (${log.expressions} expressions)`); }
