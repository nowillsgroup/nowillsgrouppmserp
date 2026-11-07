#!/usr/bin/env node
/**
 * Build the accounts-payable ageing preview as the real application screen.
 *
 * The content is resources/views/ap/ageing.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/ap-ageing-sample.mjs, inside the application shell. This is the screen the month-end close reads
 * first, and it had no preview at all: a change to a bucket boundary or to what counts as a credit could only be
 * reviewed by deploying it.
 *
 * v8.8 the screen carries the documents behind every bucket, so the sample is built to prove the two halves agree — the
 * eight aged documents total exactly the balance the bucket table prints.
 *
 * Output: deploy/dev/previews/ap-ageing.html
 *
 * Run: node deploy/dev/build-ap-ageing-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { apAgeingContext } from './preview/ap-ageing-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/ap-ageing.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = apAgeingContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('ap/ageing', 'content', ctx.scope);
  actionsRaw = renderer.section('ap/ageing', 'actions', ctx.scope);   // the as-at date and the CSV export
  headRaw = renderer.push('ap/ageing', 'head', ctx.scope);
  scriptsRaw = renderer.push('ap/ageing', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Accounts Payable Ageing',
  crumbs: `Accounts / Payables · ${esc('as at 7 Nov 2026')}`,
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw,
  activeNav: 'ap-ageing',
});

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-ap-ageing-preview.mjs`);
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
