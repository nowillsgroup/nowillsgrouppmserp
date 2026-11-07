#!/usr/bin/env node
/**
 * Build the stock ledger preview as the real application screen.
 *
 * The content is resources/views/inventory/ledger.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/ledger-sample.mjs, inside the application shell.
 *
 * v9.22 the ledger listed quantities and totalled nothing, which is a list rather than a book. Every other inventory
 * document ends on this screen, so the window is now read three ways: what moved and what it was worth (77,760.00 in
 * against 37,809.50 out, the largest line the 1,200 L diesel receipt), whether it rolls forward (ten items from 39,984.00
 * to 80,172.00, with the engine oil closing at −6 L because eighteen litres were issued from a book that had twelve), and
 * what has no document behind it (four movements, 1,197.50, 3.2 % of the value that left). Every figure is computed from
 * the same rows the table lists, so the cards cannot disagree with it.
 *
 * The clean build is the window as it stands; the drift build is the morning after a late engine-oil receipt is keyed in
 * and the rice wastage gets its form — the negative balance clears and one movement leaves the gap list, and nothing
 * else on the shelf moves.
 *
 * Output: deploy/dev/previews/ledger.html and deploy/dev/previews/ledger-drift.html
 *
 * Run: node deploy/dev/build-ledger-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { ledgerContext } from './preview/ledger-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'ledger.html', drift: false },
  { file: 'ledger-drift.html', drift: true },
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
  const ctx = ledgerContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('inventory/ledger', 'content', ctx.scope);
    actionsRaw = renderer.section('inventory/ledger', 'actions', ctx.scope);
    headRaw = renderer.push('inventory/ledger', 'head', ctx.scope);
    scriptsRaw = renderer.push('inventory/ledger', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Stock Ledger',
    crumbs: 'Inventory / Stock Ledger',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'inventory-ledger',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-ledger-preview.mjs`);
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
