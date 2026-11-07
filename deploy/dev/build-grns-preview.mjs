#!/usr/bin/env node
/**
 * Build the goods receipts preview as the real application screen.
 *
 * The content is resources/views/procurement/grns.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/procurement-grn-sample.mjs, inside the application shell. What arrived, and whether the money
 * behind it reached the ledger.
 *
 * v9.16 the register stops being a list of deliveries. A receipt is a claim on Accounts Payable from the moment the
 * goods are accepted, so the page reads three other documents back against it: the purchase order's bill (the relation
 * ApService::unbilledReceipts() already tests for the bill screen), the order's expected date (lateness), and the stock
 * movement the receipt itself wrote (the shelf). The clean build is a month where all three agree; the drift build is a
 * month where two of them do not — two bills left as drafts, and one receipt whose goods never reached stock.
 *
 * Output: deploy/dev/previews/grns.html and deploy/dev/previews/grns-drift.html
 *
 * Run: node deploy/dev/build-grns-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { grnRegisterContext } from './preview/procurement-grn-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'grns.html', drift: false },
  { file: 'grns-drift.html', drift: true },
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
  const ctx = grnRegisterContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('procurement/grns', 'content', ctx.scope);
    actionsRaw = renderer.section('procurement/grns', 'actions', ctx.scope);
    headRaw = renderer.push('procurement/grns', 'head', ctx.scope);
    scriptsRaw = renderer.push('procurement/grns', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Goods Receipts (GRN)',
    crumbs: 'Inventory / Goods Receipts',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'goods-receipts-grn',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-grns-preview.mjs`);
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
