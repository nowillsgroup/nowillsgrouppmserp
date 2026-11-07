#!/usr/bin/env node
/**
 * Build the Cost centre budgets preview as the real application screen.
 *
 * The content is resources/views/budgets/cost-centres.blade.php rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/cost-centres-sample.mjs, inside the
 * application shell: every department's plan, the plan pro-rated to the days elapsed, what the ledger
 * charged to that cost centre, and the two reconciliation rows – plan not yet given to a department,
 * and expense that carries no cost centre at all.
 *
 * Output: deploy/dev/previews/budget-cost-centres.html
 *
 * Run: node deploy/dev/build-budget-cost-centres-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { costCentresContext } from './preview/cost-centres-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/budget-cost-centres.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = costCentresContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('budgets/cost-centres', 'content', ctx.scope);
  actionsRaw = renderer.section('budgets/cost-centres', 'actions', ctx.scope);   // the header buttons: settings, new version, cost centres
  headRaw = renderer.push('budgets/cost-centres', 'head', ctx.scope);
  scriptsRaw = renderer.push('budgets/cost-centres', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Cost centre budgets',
  crumbs: `Budgets · ${esc('Cost centres · FY 2026-27')}`,
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw,
  activeNav: 'gl',
});

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-budget-cost-centres-preview.mjs`);
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
