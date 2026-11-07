#!/usr/bin/env node
/**
 * Build the re-order suggestions preview as the real application screen.
 *
 * The content is resources/views/inventory/reorder.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/reorder-sample.mjs, inside the application shell. The screen where the procurement chain starts
 * again: a level says what somebody once thought was enough, a usage figure says what the shelf is really doing.
 *
 * v9.20 the page was an alert and a table of ten columns with a suggestion in it. The suggestion is now read three ways,
 * each from the record that owns it: the gap (stock against twice the level, valued at the last rate paid), the pipeline
 * (open purchase orders, the part of them still in draft — which is not coming until somebody approves it — and the
 * requisition lines departments have asked for that no order covers) and the cover (sixty days of stock movements, which
 * turns the suggestion into days of stock left and a date it runs out). The clean build is ten items below the level,
 * 34,164.00 short and five with nothing against them; the drift build breaks the pipeline and the cover — a sugar line
 * appears in a draft order and a banqueting week lifts the draw — and leaves the gap alone.
 *
 * Output: deploy/dev/previews/reorder.html and deploy/dev/previews/reorder-drift.html
 *
 * Run: node deploy/dev/build-reorder-preview.mjs [--check]
 */import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { reorderContext } from './preview/reorder-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'reorder.html', drift: false },
  { file: 'reorder-drift.html', drift: true },
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
  const ctx = reorderContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('inventory/reorder', 'content', ctx.scope);
    actionsRaw = renderer.section('inventory/reorder', 'actions', ctx.scope);
    headRaw = renderer.push('inventory/reorder', 'head', ctx.scope);
    scriptsRaw = renderer.push('inventory/reorder', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Re-order Suggestions',
    crumbs: 'Inventory / Supplier reorder',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'supplier-reorder',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-reorder-preview.mjs`);
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
