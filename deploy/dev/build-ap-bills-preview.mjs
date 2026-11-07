#!/usr/bin/env node
/**
 * Build the supplier bills preview as the real application screen.
 *
 * The content is resources/views/ap/bills.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/ap-bills-sample.mjs, inside the application shell. This is the AP inbox — the documents, the page of
 * them, and the four figures a payables clerk works from.
 *
 * v9.1 those figures come from ApService::dueStats(), the same banding rule the payables ageing prints, so this screen
 * and the ageing cannot report different overdue numbers — which they did when this screen computed its own with
 * `due_date < today` and skipped bills with no due date. The sample's numbers are the ageing's: 181,700 on open bills,
 * 12,400 goods received and not yet billed, 11,600 credits, 182,500 payable.
 *
 * Output: deploy/dev/previews/ap-bills.html
 *
 * Run: node deploy/dev/build-ap-bills-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { apBillsContext } from './preview/ap-bills-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/ap-bills.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = apBillsContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('ap/bills', 'content', ctx.scope);
  actionsRaw = renderer.section('ap/bills', 'actions', ctx.scope);   // the as-at date and the CSV export
  headRaw = renderer.push('ap/bills', 'head', ctx.scope);
  scriptsRaw = renderer.push('ap/bills', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Supplier Bills',
  crumbs: `Accounts / Payables · ${esc('as at 7 Nov 2026')}`,
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
