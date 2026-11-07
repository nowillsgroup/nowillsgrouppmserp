#!/usr/bin/env node
/**
 * Build the stock take preview as the real application screen.
 *
 * The content is resources/views/inventory/stocktakes.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/stocktakes-sample.mjs, inside the application shell. The one document in the app that checks the
 * stock book against the shelf — and the one that is worth nothing until somebody posts it.
 *
 * v9.21 the page was a list of count sheets with a variance column that only appeared once a sheet had been posted. A
 * count is three readings and this build puts all three on the page: the variance (short against over, line by line,
 * with the largest named), the coverage (96 of 102 lines actually counted — the other six were posted untouched) and the
 * posting (two drafts, one of them four days old, holding 1,240.00 the ledger has not been told about). The clean build
 * is a month where the newest count is still a draft; the drift build is the same month after it is posted, and nothing
 * else about the shelf changes.
 *
 * Output: deploy/dev/previews/stocktakes.html and deploy/dev/previews/stocktakes-drift.html
 *
 * Run: node deploy/dev/build-stocktakes-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { stocktakesContext } from './preview/stocktakes-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'stocktakes.html', drift: false },
  { file: 'stocktakes-drift.html', drift: true },
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
  const ctx = stocktakesContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('inventory/stocktakes', 'content', ctx.scope);
    actionsRaw = renderer.section('inventory/stocktakes', 'actions', ctx.scope);
    headRaw = renderer.push('inventory/stocktakes', 'head', ctx.scope);
    scriptsRaw = renderer.push('inventory/stocktakes', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Stock Take',
    crumbs: 'Inventory / Supplier stocktakes',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'supplier-stocktakes',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-stocktakes-preview.mjs`);
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
