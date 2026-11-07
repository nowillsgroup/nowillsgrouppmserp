#!/usr/bin/env node
/**
 * Build the company ledger preview as the real application screen.
 *
 * The content is resources/views/accounts/company.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/company-ledger-sample.mjs, inside the application shell. One company's city-ledger history, read as
 * at a date.
 *
 * v9.7 the closing balance is a claim that can be checked: the receivables ageing reads the same charges and receipts
 * FIFO into buckets, and this screen adds them up, so both have to land on 48,400 for Ashanti Builders Co-op — the
 * company that is over its credit limit on the ageing. The as-at date is a control for the same reason: a charge dated
 * after it has not happened and a receipt dated after it has not been banked.
 *
 * Output: deploy/dev/previews/company-ledger.html
 *
 * Run: node deploy/dev/build-company-ledger-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { companyLedgerContext } from './preview/company-ledger-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/company-ledger.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = companyLedgerContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('accounts/company', 'content', ctx.scope);
  actionsRaw = renderer.section('accounts/company', 'actions', ctx.scope);   // the as-at date, print, receive payment
  headRaw = renderer.push('accounts/company', 'head', ctx.scope);
  scriptsRaw = renderer.push('accounts/company', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Ledger – Ashanti Builders Co-op',
  crumbs: 'City Ledger / Ashanti Builders Co-op',
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw,
  activeNav: 'city-ledger',
});

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-ap-bills-preview.mjs`);
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
