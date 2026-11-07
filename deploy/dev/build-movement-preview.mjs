#!/usr/bin/env node
/**
 * Build the stock-movement preview as the real application screen.
 *
 * The content is resources/views/inventory/movement.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/movement-sample.mjs, inside the application shell.
 *
 * v9.25 this is the only stock document written by hand, and it had no reference field at all — every movement it wrote
 * reached the ledger with nothing behind it, which is what the ledger's gap card could only count. The form now asks for
 * the document, and the screen carries the three things a clerk needs first: what is actually in the store they are taking
 * from (Kitchen Store, 10 items, 10,159.00, rice 180 kg against a 300 kg level), what has already left it this month
 * (8 movements, 4,765.00, every one of them a ledger line that cannot be edited), and which of those nobody can account
 * for (4 movements, 319.00, because there was nowhere to write the document).
 *
 * The clean build is the month that shows the cost of the missing field; the drift build is the same store with the
 * reference field in use — the two wastages and two adjustments re-written against reports, so the gap card empties.
 *
 * Output: deploy/dev/previews/movement.html and movement-drift.html
 *
 * Run: node deploy/dev/build-movement-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { movementContext } from './preview/movement-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'movement.html', drift: false },
  { file: 'movement-drift.html', drift: true },
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
  const ctx = movementContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('inventory/movement', 'content', ctx.scope);
    actionsRaw = renderer.section('inventory/movement', 'actions', ctx.scope);
    headRaw = renderer.push('inventory/movement', 'head', ctx.scope);
    scriptsRaw = renderer.push('inventory/movement', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Stock Issue / Transfer / Adjustment',
    crumbs: 'Inventory / Movement',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'inventory-stock-on-hand',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-movement-preview.mjs`);
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
