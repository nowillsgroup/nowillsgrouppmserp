#!/usr/bin/env node
/**
 * Build the company receivables ageing preview as the real application screen.
 *
 * The content is resources/views/accounts/receivables.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/receivables-sample.mjs, inside the application shell. This is the other half of the payables
 * ageing: the city ledger read the way the month-end reads it, FIFO against receipts, with the credit limit beside it
 * and the charges behind every bucket.
 *
 * The sample proves the FIFO rule rather than describing it: Zenith Oil's June conference is part-paid at 28,000 and
 * over 90 days past due, while its October dinner is untouched at 46,500 and inside its terms — and the four documents
 * total exactly the 120,200 the buckets print.
 *
 * Output: deploy/dev/previews/receivables.html
 *
 * Run: node deploy/dev/build-receivables-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { receivablesContext } from './preview/receivables-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/receivables.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = receivablesContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('accounts/receivables', 'content', ctx.scope);
  actionsRaw = renderer.section('accounts/receivables', 'actions', ctx.scope);   // the as-at date and the CSV export
  headRaw = renderer.push('accounts/receivables', 'head', ctx.scope);
  scriptsRaw = renderer.push('accounts/receivables', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Company Receivables Ageing',
  crumbs: `Accounts / Receivables · ${esc('as at 7 Nov 2026')}`,
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw,
  activeNav: 'accounts-receivables',
});

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-receivables-preview.mjs`);
    process.exit(1);
  }
  console.log(`${path.relative(ROOT, OUT)} is up to date`);
  process.exit(0);
}

fs.writeFileSync(OUT, html);
console.log(`wrote ${path.relative(ROOT, OUT)} (${html.length.toLocaleString('en-US')} bytes)`);
for (const [kind, list] of [['php', log.phpSkipped], ['conditions', log.conditionsDefaulted], ['components', log.unknownComponents], ['directives', log.unknownDirectives], ['errors', log.errors]]) {
  if (list.length) console.log(`  ${kind}: ${list.slice(0, 4).join(' · ')}${list.length > 4 ? ` … (${list.length})` : ''}`);
}
