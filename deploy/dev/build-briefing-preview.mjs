#!/usr/bin/env node
/**
 * Build the daily-briefing previews as the real application screens.
 *
 * The briefings are management documents, so the previews are the screens a head of
 * department and the general manager actually open, rendered from the application views by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/briefing-sample.mjs,
 * inside the application shell from deploy/dev/preview/app-shell.mjs:
 *
 *   briefing-hub.html          resources/views/briefings/index.blade.php — the hub: every
 *                              pack the user may open, with today's sign-off state
 *   briefing-fnb.html          resources/views/briefings/show.blade.php  — a full trading
 *                              pack: headline, the house band, outlet/covers/leakage blocks
 *   briefing-housekeeping.html the same view for a floor still turning rooms
 *   briefing-laundry.html      the same view before the laundry opens: every empty state
 *   briefing-revenue.html      the same show view for Revenue: the fortnight's pace, the mix
 *                              against base rate, the rate decisions waiting and leakage
 *   briefing-maintenance.html  the same show view for Engineering: the overdue list, the
 *                              planned work coming up and the rooms out of the sale
 *   briefing-sales.html        the same show view for Sales: the pipeline, the enquiries that
 *                              have gone quiet, and what was won and lost with the reasons
 *   briefing-guestexp.html     the same show view for Guest Experience: the reviews, the cases
 *                              that need an answer and what the guests actually wrote
 *   briefing-banquet.html      the same show view for Banquet: the floor today, the event
 *                              orders still unpublished and the closed events not yet billed
 *   briefing-it.html           the same show view for IT: what failed today command by
 *                              command, and the endpoints that are not answering
 *   briefing-kitchen.html      the same show view for the Kitchen: what the pass sent out,
 *                              what is still on it, the allergen lines and the cold chain
 *   briefing-stores.html       the same show view for Purchasing & Stores: what to buy, the
 *                              counter queue and the deliveries received
 *   briefing-frontoffice.html  the same show view for Front Office: the arrivals still to come,
 *                              the departures to settle, the desk's cash and its open items
 *   briefing-security.html     the same show view for Security: who is on site now, the keys off
 *                              the board and the gate passes still out
 *   briefing-hr.html           the same show view for People & HR: attendance by department,
 *                              the leave queue, contracts running out
 *   briefing-parking.html      the same show view for Parking & Valet: the bays by zone,
 *                              the drop-off curve, permits falling due
 *   briefing-finance.html      the same show view for the Financial Controller, with the profit
 *                              and budget bands the GM reads
 *   briefing-exec.html         resources/views/briefings/exec.blade.php  — the GM/CEO brief:
 *                              flash table, scorecard, revenue split, cash, trend, AR, risks
 *   briefing-book.html         .../briefings/book.blade.php   — the whole book: contents, the
 *                              executive brief and all 18 department packs, one per page
 *   briefing-print.html        resources/views/briefings/print.blade.php — the printed pack
 *
 * Run: node deploy/dev/build-briefing-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import {
  briefingHubContext, briefingFnbContext, briefingHousekeepingContext,
  briefingLaundryContext, briefingExecContext, briefingPrintContext, briefingBookContext, briefingFinanceContext, briefingHrContext, briefingParkingContext, briefingFrontOfficeContext, briefingSecurityContext, briefingKitchenContext, briefingStoresContext, briefingRevenueContext, briefingMaintenanceContext, briefingSalesContext, briefingGuestExpContext, briefingBanquetContext, briefingItContext, briefingFleetContext, briefingRecreationContext,
} from './preview/briefing-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const { menu, navHtml } = nav();
const sprite = spriteSymbols();

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const screens = [
  { view: 'briefings/index', file: 'briefing-hub.html', title: 'Daily briefings', crumbs: 'Reports / Daily briefings', context: briefingHubContext },
  { view: 'briefings/show', file: 'briefing-fnb.html', title: 'F&B Manager — daily briefing', crumbs: 'Daily briefings / Food & Beverage', context: briefingFnbContext },
  { view: 'briefings/show', file: 'briefing-housekeeping.html', title: 'Executive Housekeeper — daily briefing', crumbs: 'Daily briefings / Housekeeping', context: briefingHousekeepingContext },
  { view: 'briefings/show', file: 'briefing-laundry.html', title: 'Laundry Manager — daily briefing', crumbs: 'Daily briefings / Laundry & Linen', context: briefingLaundryContext },
  { view: 'briefings/show', file: 'briefing-finance.html', title: 'Financial Controller — daily briefing', crumbs: 'Daily briefings / Finance & Accounts', context: briefingFinanceContext },
  { view: 'briefings/show', file: 'briefing-banquet.html', title: 'Banquet Manager — daily briefing', crumbs: 'Daily briefings / Banquet', context: briefingBanquetContext },
  { view: 'briefings/show', file: 'briefing-it.html', title: 'IT Manager — daily briefing', crumbs: 'Daily briefings / IT', context: briefingItContext },
  { view: 'briefings/show', file: 'briefing-sales.html', title: 'Sales Manager — daily briefing', crumbs: 'Daily briefings / Sales', context: briefingSalesContext },
  { view: 'briefings/show', file: 'briefing-guestexp.html', title: 'Guest Relations Manager — daily briefing', crumbs: 'Daily briefings / Guest Experience', context: briefingGuestExpContext },
  { view: 'briefings/show', file: 'briefing-revenue.html', title: 'Revenue Manager — daily briefing', crumbs: 'Daily briefings / Revenue', context: briefingRevenueContext },
  { view: 'briefings/show', file: 'briefing-maintenance.html', title: 'Chief Engineer — daily briefing', crumbs: 'Daily briefings / Engineering', context: briefingMaintenanceContext },
  { view: 'briefings/show', file: 'briefing-kitchen.html', title: 'Executive Chef — daily briefing', crumbs: 'Daily briefings / Kitchen', context: briefingKitchenContext },
  { view: 'briefings/show', file: 'briefing-stores.html', title: 'Purchasing & Stores Manager — daily briefing', crumbs: 'Daily briefings / Purchasing & Stores', context: briefingStoresContext },
  { view: 'briefings/show', file: 'briefing-frontoffice.html', title: 'Front Office Manager — daily briefing', crumbs: 'Daily briefings / Front Office', context: briefingFrontOfficeContext },
  { view: 'briefings/show', file: 'briefing-security.html', title: 'Security Manager — daily briefing', crumbs: 'Daily briefings / Security', context: briefingSecurityContext },
  { view: 'briefings/show', file: 'briefing-hr.html', title: 'Human Resources Manager — daily briefing', crumbs: 'Daily briefings / People & HR', context: briefingHrContext },
  { view: 'briefings/show', file: 'briefing-parking.html', title: 'Parking Supervisor — daily briefing', crumbs: 'Daily briefings / Parking & Valet', context: briefingParkingContext },
  { view: 'briefings/show', file: 'briefing-fleet.html', title: 'Transport Supervisor — daily briefing', crumbs: 'Daily briefings / Transport & Fleet', context: briefingFleetContext },
  { view: 'briefings/show', file: 'briefing-recreation.html', title: 'Recreation Manager — daily briefing', crumbs: 'Daily briefings / Spa & Recreation', context: briefingRecreationContext },
  { view: 'briefings/exec', file: 'briefing-exec.html', title: 'Daily executive brief', crumbs: 'Daily briefings / Executive brief', context: briefingExecContext },
  { view: 'briefings/print', file: 'briefing-print.html', title: 'F&B Manager — daily briefing', crumbs: 'Daily briefings / Print', context: briefingPrintContext, raw: true },
  { view: 'briefings/book', file: 'briefing-book.html', title: 'Daily briefing book', crumbs: 'Daily briefings / Book', context: briefingBookContext, raw: true },
];

let failures = 0;

for (const screen of screens) {
  const log = { expressions: 0, fallbacks: 0, failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [] };
  const ctx = screen.context();

  if (screen.raw) {
    // A printed document: resources/views/briefings/print.blade.php extends layouts.print,
    // so the preview renders its content section and wraps it in the same page the print
    // layout produces (hotel head, document title, meta line, print button).
    let raw = '';
    try {
      const renderer = new BladePreview({ root: ROOT, ctx, log });
      const content = renderer.section(screen.view, 'content', ctx.scope);
      const take = (name) => { try { return renderer.section(screen.view, name, ctx.scope); } catch { return ''; } };
      const docTitle = take('doctitle') || screen.title;
      const docMeta = take('docmeta');
      raw = `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>${screen.title} · The Grove Essipong Beach Resort</title>
<link rel="stylesheet" href="/css/app.css">
<style>body{background:#eef1f6}.print-head>div{min-width:0}.print-head .small{overflow-wrap:anywhere}</style>
</head><body>
<div class="print-bar no-print"><button class="btn btn-primary" onclick="window.print()">Print</button></div>
<div class="print-page">
  <div class="print-head">
    <div><h2>The Grove Essipong Beach Resort</h2>
      <div class="small text-2">Beach Road, Essipong, Takoradi, Western Region</div>
      <div class="small text-2">Ph: +233 31 202 4471 · reservations@groveessipong.com</div>
      <div class="small"><b>TIN:</b> C0012345678</div>
    </div>
    <div class="right"><h2 style="color:#166534">${docTitle.trim()}</h2>${docMeta.trim()}</div>
  </div>
${content}
</div>
</body></html>`;
    } catch (error) {
      console.error(`${screen.file}: render failed — ${error.message}`);
      process.exit(1);
    }
    const outRaw = path.join(ROOT, 'deploy/dev/previews', screen.file);
    const before = fs.existsSync(outRaw) ? fs.readFileSync(outRaw, 'utf8') : null;
    if (before === raw) console.log(`${screen.file} is up to date`);
    else if (checkOnly) { console.error(`${screen.file} is stale — run node deploy/dev/build-briefing-preview.mjs`); failures += 1; }
    else { fs.writeFileSync(outRaw, raw); console.log(`wrote deploy/dev/previews/${screen.file} (${raw.length} bytes)`); }
    if (log.failed.length) {
      failures += 1;
      console.log(`  unresolved expressions .. ${log.failed.length}`);
      for (const item of log.failed.slice(0, 20)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
    }
    continue;
  }

  let contentRaw = '', actionsRaw = '', headRaw = '', scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section(screen.view, 'content', ctx.scope);
    actionsRaw = renderer.section(screen.view, 'actions', ctx.scope);
    headRaw = renderer.push(screen.view, 'head', ctx.scope);
    scriptsRaw = renderer.push(screen.view, 'scripts', ctx.scope);
  } catch (error) {
    console.error(`${screen.file}: render failed — ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: screen.title,
    crumbs: screen.crumbs,
    actions: indent(actionsRaw, '        '),
    head: headRaw ? `  ${headRaw}\n` : '',
    scripts: scriptsRaw + '\n',
  });

  const out = path.join(ROOT, 'deploy/dev/previews', screen.file);
  const previous = fs.existsSync(out) ? fs.readFileSync(out, 'utf8') : null;
  if (previous === html) console.log(`${screen.file} is up to date`);
  else if (checkOnly) { console.error(`${screen.file} is stale — run node deploy/dev/build-briefing-preview.mjs`); failures += 1; }
  else { fs.writeFileSync(out, html); console.log(`wrote deploy/dev/previews/${screen.file} (${html.length} bytes)`); }

  if (log.failed.length) {
    failures += 1;
    console.log(`  unresolved expressions .. ${log.failed.length}`);
    for (const item of log.failed.slice(0, 20)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
  }
  if (log.conditionsDefaulted.length) console.log(`  conditions defaulted .... ${log.conditionsDefaulted.length} (${[...new Set(log.conditionsDefaulted)].slice(0, 6).join(' | ')})`);
  if (log.unknownComponents.length) console.log(`  unknown components ...... ${[...new Set(log.unknownComponents)].join(', ')}`);
  if (log.unknownDirectives.length) console.log(`  unknown directives ...... ${[...new Set(log.unknownDirectives.map(d => d.split(' ')[0]))].join(', ')}`);
  if (log.errors.length) log.errors.forEach(error => console.error(`  error: ${error}`));
}

console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length}`);
if (failures) process.exit(1);
