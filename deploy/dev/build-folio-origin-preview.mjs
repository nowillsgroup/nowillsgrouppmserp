#!/usr/bin/env node
/** Static sample of the actual line-origin Blade partial; consolidation is not live yet. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { walkinContext } from './preview/walkin-sample.mjs';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const out = path.join(root, 'deploy/dev/previews/folio-origin.html');
const ctx = walkinContext();
const log = { expressions: 0, fallbacks: 0, failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [] };
const renderer = new BladePreview({ root, ctx, log });
const render = tx => renderer.renderFile('folios/_line-origin', Object.assign(Object.create(ctx.scope), { transaction: tx }));
const room = render({ consolidationTransfer: { source_folio_id: 27, sourceFolio: { folio_no: 'FOL-0027', reservation_room_id: 11, stay: { room: { room_no: '202' } } } } });
const reservation = render({ consolidationTransfer: { source_folio_id: 28, sourceFolio: { folio_no: 'FOL-0028', reservation_room_id: null, stay: null } } });
const normal = render({ consolidationTransfer: null });
const missing = render({ consolidationTransfer: { source_folio_id: 29, sourceFolio: null } });
const banner = '<div class="alert alert-info" role="note">Illustrative component preview only. Combined billing is not yet available in checkout.</div>';
const content = `${banner}<div class="card flush"><div class="card-head"><h3>Sample transaction origins</h3></div><div class="card-body table-wrap"><table class="table"><thead><tr><th>Entry</th><th>Source at consolidation</th></tr></thead><tbody><tr><td>Room tariff</td><td>${room}</td></tr><tr><td>Advance received</td><td>${reservation}</td></tr><tr><td>Historical charge</td><td>${missing}</td></tr><tr><td>Direct payment on this folio</td><td>${normal.trim() || 'No consolidation source'}</td></tr></tbody></table></div></div>`;
const html = shell(nav().navHtml, spriteSymbols(), content, { title: 'Folio FOL-00124', crumbs: 'Folios / FOL-00124' }).replace(/[ \t]+$/gm, '');
if (log.failed.length || log.errors.length || log.unknownComponents.length || log.unknownDirectives.length) { console.error(JSON.stringify(log, null, 2)); process.exit(1); }
if (process.argv.includes('--check')) {
  if (!fs.existsSync(out) || fs.readFileSync(out, 'utf8') !== html) { console.error('folio origin preview is stale'); process.exit(1); }
  console.log('folio origin preview is up to date');
} else { fs.writeFileSync(out, html); console.log(`wrote ${out} (${log.expressions} expressions)`); }
