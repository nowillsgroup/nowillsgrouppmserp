#!/usr/bin/env node
/**
 * Build the supplier payment preview as the real application screen.
 *
 * The content is resources/views/ap/payment-show.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/ap-payment-show-sample.mjs, inside the application shell. One payment, what it settled, and what is
 * left of it.
 *
 * v9.5 the screen answers the question a clerk asks after a part payment — "is this money on the ageing?" — from the
 * credit rule itself: ApService::openCredits() either returns this payment or it does not. The sample's payment is 8,000
 * paid on account, so it is a credit, and it is part of the 11,600 the ageing nets off for Coastal Beverages.
 *
 * Output: deploy/dev/previews/ap-payment-show.html
 *
 * Run: node deploy/dev/build-ap-payment-show-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { apPaymentShowContext } from './preview/ap-payment-show-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/ap-payment-show.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = apPaymentShowContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('ap/payment-show', 'content', ctx.scope);
  actionsRaw = renderer.section('ap/payment-show', 'actions', ctx.scope);   // remittance, certificate, statement, cancel
  headRaw = renderer.push('ap/payment-show', 'head', ctx.scope);
  scriptsRaw = renderer.push('ap/payment-show', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Supplier Payment PAY-2026-0102',
  crumbs: 'Supplier Payments / PAY-2026-0102',
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw,
  activeNav: 'ap-payments',
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
