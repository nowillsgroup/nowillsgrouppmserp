#!/usr/bin/env node
/**
 * Build the purchase order document preview as the real application screen.
 *
 * The content is resources/views/inventory/po-show.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/po-show-sample.mjs, inside the application shell.
 *
 * v9.26 the order is the one document the whole procure-to-AP chain points at — the requisition, the goods receipts, the
 * supplier bill and the payment all carry its number — and it could say what was ordered and what arrived without ever
 * saying whether the three agreed. The screen now carries five figures over it (ordered, arrived, still owed, billed,
 * overdue), the deliveries that came through the gate, and the three-way match between what the store received and what
 * the supplier charged for.
 *
 * The order is PO-2026-0302, Coastal Beverages\' beverage run: 8,579.00 with VAT, part delivered eight days past its
 * expected date, and one line — Coca-Cola at 38.00 against 36.50 agreed — above the agreed rate. Two deliveries, the
 * second six days late with two crates of Club Beer refused as broken; 7,465.80 accepted. The bill CBL-2026-0629 is for
 * exactly that, so the match closes: 1,113.20 of beer is still owed on 22 crates and nothing has been charged for what the
 * store never received.
 *
 * The drift build enters the bill for the whole order, 8,579.00. The supplier has then been charged 1,113.20 for beer that
 * never came, and the match card turns red.
 *
 * Output: deploy/dev/previews/po-show.html and po-show-drift.html
 *
 * Run: node deploy/dev/build-po-show-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { poShowContext } from './preview/po-show-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'po-show.html', drift: false },
  { file: 'po-show-drift.html', drift: true },
];

let stale = false;
for (const page of PAGES) {
  const OUT = path.join(ROOT, 'deploy/dev/previews', page.file);
  const log = {
    expressions: 0, fallbacks: 0,
    failed: [], conditionsDefaulted: [], phpSkipped: [],
    unknownComponents: [], unknownDirectives: [], errors: [],
  };
  const { navHtml } = nav();
  const sprite = spriteSymbols();
  const ctx = poShowContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('inventory/po-show', 'content', ctx.scope);
    actionsRaw = renderer.section('inventory/po-show', 'actions', ctx.scope);
    headRaw = renderer.push('inventory/po-show', 'head', ctx.scope);
    scriptsRaw = renderer.push('inventory/po-show', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Purchase Order PO-2026-0302',
    crumbs: 'Purchase Orders / PO-2026-0302',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'purchase-orders',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-po-show-preview.mjs`);
      stale = true;
      continue;
    }
    console.log(`${path.relative(ROOT, OUT)} is up to date`);
    continue;
  }

  fs.writeFileSync(OUT, html);
  console.log(`wrote ${path.relative(ROOT, OUT)} (${html.length.toLocaleString('en-US')} bytes)`);
  for (const [kind, list] of [['php', log.phpSkipped], ['conditions', log.conditionsDefaulted], ['components', log.unknownComponents], ['directives', log.unknownDirectives], ['errors', log.errors]]) {
    if (list.length) console.log(`  ${kind}: ${list.slice(0, 4).join(' · ')}${list.length > 4 ? ` … (${list.length})` : ''}`);
  }
}

if (stale) process.exit(1);
