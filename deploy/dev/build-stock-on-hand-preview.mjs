#!/usr/bin/env node
/**
 * Build the stock-on-hand preview as the real application screen.
 *
 * The content is resources/views/inventory/index.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/stock-sample.mjs, inside the application shell.
 *
 * v9.23 the screen listed quantities per store and a value column: it answered "how many" and never "what is this worth,
 * where is it sitting, and what has stopped moving". Fifteen active lines, fourteen of them holding 90,374.50, of which
 * diesel is 43,632.00 (48.3 %) and the three largest lines are 74.9 % — value that sits in one fuel tank and one linen
 * store is a different risk from value spread across a hundred lines. The Main Store carries 67.7 % of it, four items are
 * held in more than one store, and four items worth 10,440.00 have not been drawn from in ninety days, the oldest the
 * Christmas decoration set at 322 days — stock that was bought, is still counted and is not being used.
 *
 * The clean build is the shelf as it stands; the drift build is the quarter after the store is tidied — the chair covers
 * gone, the chlorine drawn down, one more menu run printed — so the ageing card empties to one item and the value falls
 * with it, while the counts on the shelf stay whole.
 *
 * Output: deploy/dev/previews/stock-on-hand.html and deploy/dev/previews/stock-on-hand-drift.html
 *
 * Run: node deploy/dev/build-stock-on-hand-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { stockContext } from './preview/stock-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'stock-on-hand.html', drift: false },
  { file: 'stock-on-hand-drift.html', drift: true },
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
  const ctx = stockContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('inventory/index', 'content', ctx.scope);
    actionsRaw = renderer.section('inventory/index', 'actions', ctx.scope);
    headRaw = renderer.push('inventory/index', 'head', ctx.scope);
    scriptsRaw = renderer.push('inventory/index', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Stock Summary',
    crumbs: 'Inventory / Stock on Hand',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'inventory-stock-on-hand',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-stock-on-hand-preview.mjs`);
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
