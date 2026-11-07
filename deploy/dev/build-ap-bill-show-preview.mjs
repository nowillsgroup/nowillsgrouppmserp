#!/usr/bin/env node
/**
 * Build the supplier bill preview as the real application screen.
 *
 * The content is resources/views/ap/bill-show.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/ap-bill-show-sample.mjs, inside the application shell. One document, everything behind it.
 *
 * v9.4 the apply-credit list is not a query of its own any more: it is ApService::openCredits(), the same documents the
 * ageing nets off and the payments screen lists, narrowed to this bill's currency because a credit in another currency
 * cannot be applied to it. The sample's bill is Coastal Beverages' 23,000 part-paid expense bill — 4,500 applied,
 * 18,500 outstanding, 13 days past due — which is the 18,500 the ageing prints in its 1–30 bucket.
 *
 * Output: deploy/dev/previews/ap-bill-show.html
 *
 * Run: node deploy/dev/build-ap-bill-show-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { apBillShowContext } from './preview/ap-bill-show-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/ap-bill-show.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = apBillShowContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('ap/bill-show', 'content', ctx.scope);
  actionsRaw = renderer.section('ap/bill-show', 'actions', ctx.scope);   // status, statement, pay, debit note, cancel
  headRaw = renderer.push('ap/bill-show', 'head', ctx.scope);
  scriptsRaw = renderer.push('ap/bill-show', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Supplier Bill CBL-2026-0771',
  crumbs: 'Supplier Bills / CBL-2026-0771',
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw,
  activeNav: 'ap-bills',
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
