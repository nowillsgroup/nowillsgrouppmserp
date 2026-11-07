#!/usr/bin/env node
/**
 * Build the Recreation outlet previews as the real application screens.
 *
 * The outlet is a revenue point of sale, so the previews are the three screens a cashier
 * and a duty manager actually work with, rendered from the application views by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/recreation-sample.mjs,
 * inside the application shell from deploy/dev/preview/app-shell.mjs:
 *
 *   recreation-outlet.html  resources/views/recreation/outlet.blade.php — the till: shift
 *                           strip, catalogue grid, cart, split/direct tenders, room charge
 *   recreation-outlet-closed.html  the same till before a shift is opened, at an outlet that
 *                           requires one: the banner, and the till refusing to take money
 *   recreation-shift.html   resources/views/recreation/shift.blade.php  — X report: takings
 *                           by tender, the denomination count and the close-out
 *   recreation-day.html     resources/views/recreation/day.blade.php    — Z report: the
 *                           day's takings by mode, category, item, cashier and shift
 *   recreation-z.html       resources/views/recreation/day-print.blade.php — the printed Z
 *   recreation-gate.html    resources/views/recreation/gate.blade.php    — the gate: the code
 *                           entry that admits a stub, and who has come through today
 *   recreation-receipt.html resources/views/recreation/receipt.blade.php — the receipt and
 *                           the entry tickets the counter hands the visitor
 *   recreation-sales.html   resources/views/recreation/sales.blade.php  — the outlet ledger,
 *                           with the shift each sale belonged to and who took the money
 *
 * Run: node deploy/dev/build-recreation-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import {
  recreationOutletContext, recreationShiftContext, recreationDayContext,
  recreationReceiptContext, recreationSalesContext, recreationOutletNoShiftContext,
  recreationGateContext, recreationTypesContext,
} from './preview/recreation-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const { menu, navHtml } = nav();
const sprite = spriteSymbols();

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const screens = [
  {
    view: 'recreation/outlet', file: 'recreation-outlet.html', title: 'Ticket Outlet',
    crumbs: 'Wellness & recreation / Ticket outlet', context: recreationOutletContext,
  },
  {
    view: 'recreation/outlet', file: 'recreation-outlet-closed.html', title: 'Ticket Outlet',
    crumbs: 'Wellness & recreation / Ticket outlet', context: recreationOutletNoShiftContext,
  },
  {
    view: 'recreation/shift', file: 'recreation-shift.html', title: 'Outlet Shift',
    crumbs: 'Wellness & recreation / Ticket outlet / Shift', context: recreationShiftContext,
  },
  {
    view: 'recreation/day', file: 'recreation-day.html', title: 'Recreation Day Report',
    crumbs: 'Wellness & recreation / Ticket outlet / Day report', context: recreationDayContext,
  },
  {
    view: 'recreation/day-print', file: 'recreation-z.html', title: 'Z report · 05 Nov 2026',
    crumbs: 'Wellness & recreation / Ticket outlet / Day report', context: recreationDayContext, raw: true,
  },
  {
    view: 'recreation/gate', file: 'recreation-gate.html', title: 'Ticket Gate',
    crumbs: 'Wellness & recreation / Ticket outlet / Gate', context: recreationGateContext,
  },
  {
    view: 'recreation/receipt', file: 'recreation-receipt.html', title: 'Receipt TKT-004512',
    crumbs: 'Wellness & recreation / Ticket sales / TKT-004512', context: recreationReceiptContext,
  },
  {
    view: 'recreation/sales', file: 'recreation-sales.html', title: 'Ticket Sales',
    crumbs: 'Wellness & recreation / Ticket sales', context: recreationSalesContext,
  },
  {
    view: 'recreation/types', file: 'recreation-types.html', title: 'Ticket Types',
    crumbs: 'Wellness & recreation / Ticket types', context: recreationTypesContext,
  },
];

let failures = 0;

for (const screen of screens) {
  const log = {
    expressions: 0, fallbacks: 0, failed: [], conditionsDefaulted: [], phpSkipped: [],
    unknownComponents: [], unknownDirectives: [], errors: [],
  };
  const ctx = screen.context();
  if (screen.raw) {
    // A printed document (no application shell): the Z report is its own HTML page.
    let raw = '';
    try {
      raw = new BladePreview({ root: ROOT, ctx, log }).renderFile(screen.view, ctx.scope);
    } catch (error) {
      console.error(`${screen.file}: render failed — ${error.message}`);
      process.exit(1);
    }
    const outRaw = path.join(ROOT, 'deploy/dev/previews', screen.file);
    const beforeRaw = fs.existsSync(outRaw) ? fs.readFileSync(outRaw, 'utf8') : null;
    if (beforeRaw === raw) console.log(`${screen.file} is up to date`);
    else if (checkOnly) { console.error(`${screen.file} is stale — run node deploy/dev/build-recreation-preview.mjs`); failures += 1; }
    else { fs.writeFileSync(outRaw, raw); console.log(`wrote deploy/dev/previews/${screen.file} (${raw.length} bytes)`); }
    if (log.failed.length) {
      failures += 1;
      console.log(`  unresolved expressions .. ${log.failed.length}`);
      for (const item of log.failed.slice(0, 20)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
    }
    continue;
  }
  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
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
  if (previous === html) {
    console.log(`${screen.file} is up to date`);
  } else if (checkOnly) {
    console.error(`${screen.file} is stale — run node deploy/dev/build-recreation-preview.mjs`);
    failures += 1;
  } else {
    fs.writeFileSync(out, html);
    console.log(`wrote deploy/dev/previews/${screen.file} (${html.length} bytes)`);
  }

  if (log.failed.length) {
    failures += 1;
    console.log(`  unresolved expressions .. ${log.failed.length}`);
    for (const item of log.failed.slice(0, 20)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
  }
  if (log.conditionsDefaulted.length) {
    console.log(`  conditions defaulted .... ${log.conditionsDefaulted.length} (${[...new Set(log.conditionsDefaulted)].slice(0, 6).join(' | ')})`);
  }
  if (log.unknownComponents.length) console.log(`  unknown components ...... ${[...new Set(log.unknownComponents)].join(', ')}`);
  if (log.unknownDirectives.length) console.log(`  unknown directives ...... ${[...new Set(log.unknownDirectives.map(d => d.split(' ')[0]))].join(', ')}`);
  if (log.errors.length) log.errors.forEach(error => console.error(`  error: ${error}`));
}

console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length}`);
if (failures) process.exit(1);
