#!/usr/bin/env node
/**
 * Build the RFQ award screen preview as the real application screen.
 *
 * The content is resources/views/procurement/rfq-show.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/rfq-show-sample.mjs, inside the application shell.
 *
 * v9.28 the screen where the award is made. The comparative statement ranks the bids on landed price line by line, which
 * is the only fair way to compare them — and on its own it is not enough to decide: RFQ-2026-0055 has five lines, three
 * quotes and one date it must arrive by.
 *
 *   • Coastal Beverages is cheapest at 5,639.60 landed, and quotes seven days from the order, so the goods land on
 *     14 November — four days after the store needs them;
 *   • Kaneshie Wholesale quotes two days and is on time, but its price expired on 31 October;
 *   • Accra Fresh Foods is 141.45 dearer than Coastal and lands on the needed date — the only bid that is both valid and
 *     deliverable.
 *
 * Delivery is counted from today, the day the order would be placed, not from the day the quote was given. Against the
 * rates last paid for the same lines (5,122.00 net) the cheapest bid saves 218.00.
 *
 * Output: deploy/dev/previews/rfq-show.html
 *
 * Run: node deploy/dev/build-rfq-show-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { rfqShowContext } from './preview/rfq-show-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'rfq-show.html', drift: false },
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
  const ctx = rfqShowContext();

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('procurement/rfq-show', 'content', ctx.scope);
    actionsRaw = renderer.section('procurement/rfq-show', 'actions', ctx.scope);
    headRaw = renderer.push('procurement/rfq-show', 'head', ctx.scope);
    scriptsRaw = renderer.push('procurement/rfq-show', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'RFQ-2026-0055 · Beverage restock — December',
    crumbs: 'RFQs / RFQ-2026-0055',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'rfqs',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-rfq-show-preview.mjs`);
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
