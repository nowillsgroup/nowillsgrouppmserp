#!/usr/bin/env node
/**
 * Build the Revenue Dashboard preview as the real application screen.
 *
 * The content is resources/views/revenue/index.blade.php rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/revenue-sample.mjs, inside
 * the application shell from deploy/dev/preview/app-shell.mjs. The revenue dashboard had no
 * preview at all, so its chart, stat cards, pickup bars and nightly table were reviewed by
 * reading the markup. A change to the view now shows up here instead of drifting.
 *
 * Output: deploy/dev/previews/revenue-dashboard.html
 *
 * Run: node deploy/dev/build-revenue-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { revenueContext } from './preview/revenue-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/revenue-dashboard.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = revenueContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('revenue/index', 'content', ctx.scope);
  actionsRaw = renderer.section('revenue/index', 'actions', ctx.scope);
  headRaw = renderer.push('revenue/index', 'head', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Revenue Dashboard',
  crumbs: `Revenue · ${esc('Dashboard')}`,
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  activeNav: 'revenue',
});

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-revenue-preview.mjs`);
    process.exit(1);
  }
  console.log(`${path.relative(ROOT, OUT)} is up to date`);
  process.exit(0);
}

fs.writeFileSync(OUT, html);
console.log(`wrote ${path.relative(ROOT, OUT)} (${html.length.toLocaleString('en-US')} bytes)`);
for (const [kind, list] of [['php', log.phpSkipped], ['conditions', log.conditionsDefaulted], ['components', log.unknownComponents], ['directives', log.unknownDirectives], ['errors', log.errors]]) {
  if (list.length) console.log(`  ${kind}: ${list.slice(0, 6).join(' · ')}${list.length > 6 ? ` … (${list.length})` : ''}`);
}
