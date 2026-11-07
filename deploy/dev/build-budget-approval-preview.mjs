#!/usr/bin/env node
/**
 * Build the budget approval preview as the real application screen.
 *
 * The content is resources/views/budgets/show.blade.php rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/budget-approval-sample.mjs, inside
 * the application shell: a version submitted on 3 November and signed once, GH₵6,180,000 against a
 * GH₵5,000,000 approval threshold, seen by the second approver — the note under the header says which
 * signature is outstanding, the button reads "Second approval", and the dialog says this is the final
 * one. The Details panel prints both signatures and the threshold the version is measured against.
 *
 * Output: deploy/dev/previews/budget-approval.html
 *
 * Run: node deploy/dev/build-budget-approval-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { budgetApprovalContext } from './preview/budget-approval-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/budget-approval.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = budgetApprovalContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('budgets/show', 'content', ctx.scope);
  actionsRaw = renderer.section('budgets/show', 'actions', ctx.scope);   // the header buttons: return, second approval
  headRaw = renderer.push('budgets/show', 'head', ctx.scope);
  scriptsRaw = renderer.push('budgets/show', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'BUD-2026-27-02 · Revised after conference season',
  crumbs: `Budgets · ${esc('BUD-2026-27-02 · Revised after conference season')}`,
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw,
  activeNav: 'gl',
});

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-budget-approval-preview.mjs`);
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
