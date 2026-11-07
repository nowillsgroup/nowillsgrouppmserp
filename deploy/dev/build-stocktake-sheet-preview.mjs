#!/usr/bin/env node
/**
 * Build the count-sheet preview as the real application screen.
 *
 * The content is resources/views/inventory/stocktake-show.blade.php rendered by deploy/dev/preview/blade-render.mjs
 * against deploy/dev/preview/stocktake-show-sample.mjs, inside the application shell.
 *
 * v9.24 a blank line and a line counted at zero look identical in a grid of inputs and post completely differently: the
 * first leaves the book alone, the second writes the whole quantity off. So the sheet is read three ways before anything
 * is posted — how far through the count is by category (a count is done aisle by aisle), what the counted lines have
 * found (2,078.00 short against 25.00 over, the tilapia counted at zero being 810.00 of it), and what posting will do line
 * by line, including the 16,154.00 that "treat blank counts as zero" would write off in one click, the bed sheets 8,550.00
 * of it.
 *
 * The clean build is the sheet as it stands; the drift build finishes the linen run, so the postable value rises to
 * 2,291.00 and the cost of the box falls to nothing.
 *
 * Output: deploy/dev/previews/stocktake-sheet.html and stocktake-sheet-drift.html
 *
 * Run: node deploy/dev/build-stocktake-sheet-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { stocktakeShowContext } from './preview/stocktake-show-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'stocktake-sheet.html', drift: false },
  { file: 'stocktake-sheet-drift.html', drift: true },
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
  const ctx = stocktakeShowContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('inventory/stocktake-show', 'content', ctx.scope);
    actionsRaw = renderer.section('inventory/stocktake-show', 'actions', ctx.scope);
    headRaw = renderer.push('inventory/stocktake-show', 'head', ctx.scope);
    scriptsRaw = renderer.push('inventory/stocktake-show', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Stock Take ST-2026-0121',
    crumbs: 'Stock Take / ST-2026-0121',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'inventory-stock-on-hand',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-stocktake-sheet-preview.mjs`);
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
