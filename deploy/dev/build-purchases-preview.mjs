#!/usr/bin/env node
/**
 * Build the purchase order preview as the real application screen.
 *
 * The content is resources/views/inventory/purchases.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/purchases-sample.mjs, inside the application shell. What was ordered, and the three things an order
 * has to be able to prove.
 *
 * v9.17 the register stops being a list. An order is a commitment the ledger does not carry until the goods arrive, a
 * gate somebody with the right level has to pass, and a document the shelf and the invoice both have to match. Each
 * reading comes from the record that owns it: the goods receipts, the approval matrix in ProcurementService (the same
 * requiredLevel()/approvalBlocker() the approve button tests), and the order's own bill relation. The clean build is a
 * month where all three agree; the drift build is a month where the match does not — one bill 500.00 short of its receipt
 * and one billed for goods that never arrived.
 *
 * Output: deploy/dev/previews/purchases.html and deploy/dev/previews/purchases-drift.html
 *
 * Run: node deploy/dev/build-purchases-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { purchasesContext } from './preview/purchases-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'purchases.html', drift: false },
  { file: 'purchases-drift.html', drift: true },
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
  const ctx = purchasesContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('inventory/purchases', 'content', ctx.scope);
    actionsRaw = renderer.section('inventory/purchases', 'actions', ctx.scope);
    headRaw = renderer.push('inventory/purchases', 'head', ctx.scope);
    scriptsRaw = renderer.push('inventory/purchases', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Purchase Orders',
    crumbs: 'Inventory / Purchase Orders',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'purchase-orders',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-purchases-preview.mjs`);
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
