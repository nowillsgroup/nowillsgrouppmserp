#!/usr/bin/env node
/**
 * Build the daily cash book preview as the real application screen.
 *
 * The content is resources/views/accounts/cashbook.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/cash-book-sample.mjs, inside the application shell.
 *
 * v9.9 the cash book is a book: brought forward per mode, the day's receipts and payments, carried forward per mode, and
 * the posting state of the day — the ledger is posted through yesterday, so today's takings are not in it yet. Two files
 * are built, because the state that matters most is the one nobody wants to see: the overdrawn build, where the cash
 * drawer has paid out 250.00 more than it held and the page has to say so instead of printing a negative balance.
 *
 * Output: deploy/dev/previews/cash-book.html and deploy/dev/previews/cash-book-overdrawn.html
 *
 * Run: node deploy/dev/build-cash-book-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { cashBookContext } from './preview/cash-book-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'cash-book.html', overdrawn: false },
  { file: 'cash-book-overdrawn.html', overdrawn: true },
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
  const ctx = cashBookContext({ overdrawn: page.overdrawn });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('accounts/cashbook', 'content', ctx.scope);
    actionsRaw = renderer.section('accounts/cashbook', 'actions', ctx.scope);   // the date and print
    headRaw = renderer.push('accounts/cashbook', 'head', ctx.scope);
    scriptsRaw = renderer.push('accounts/cashbook', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Cash Book / Daily Collection',
    crumbs: 'Accounts / Cash Book',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'cash-book',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-cash-book-preview.mjs`);
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
