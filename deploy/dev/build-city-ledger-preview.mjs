#!/usr/bin/env node
/**
 * Build the city ledger index preview as the real application screen.
 *
 * The content is resources/views/accounts/cityledger.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/city-ledger-sample.mjs, inside the application shell. Every company with its balance, read as at a
 * date.
 *
 * v9.8 the list is a second query over the sub-ledger the ageing reads, so it has to say whether the two land on the same
 * money: the page states its own total against the ageing's, and where a row disagrees it names the company and sends the
 * reader to that company's ledger. Two files are built, because both branches are real: the ordinary page, where the
 * totals agree to the cent, and the drift page, where a receipt the list counted and the ageing did not leaves Meridian
 * Travel 300.00 here against −2,700.00 there.
 *
 * Output: deploy/dev/previews/city-ledger.html and deploy/dev/previews/city-ledger-drift.html
 *
 * Run: node deploy/dev/build-city-ledger-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { cityLedgerContext } from './preview/city-ledger-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'city-ledger.html', drift: false, title: 'City Ledger – Company Receivables', crumbs: 'Accounts / City Ledger' },
  { file: 'city-ledger-drift.html', drift: true, title: 'City Ledger – Company Receivables', crumbs: 'Accounts / City Ledger' },
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
  const ctx = cityLedgerContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('accounts/cityledger', 'content', ctx.scope);
    actionsRaw = renderer.section('accounts/cityledger', 'actions', ctx.scope);   // the as-at date and print
    headRaw = renderer.push('accounts/cityledger', 'head', ctx.scope);
    scriptsRaw = renderer.push('accounts/cityledger', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: page.title,
    crumbs: page.crumbs,
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'city-ledger',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-city-ledger-preview.mjs`);
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
