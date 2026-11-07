#!/usr/bin/env node
/**
 * Build the supplier statement preview as the real application screen.
 *
 * The content is resources/views/ap/statement.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/ap-statement-sample.mjs, inside the application shell. The document a supplier is sent, with the
 * running balance a supplier checks line by line.
 *
 * v9.3 the screen also says whether it agrees with our own ageing of the same supplier at the same date: the statement
 * closes at 40,900 and Coastal Beverages' ageing row says 40,900, because both are built from the same documents by
 * different routes. A difference is a defect to explain before the statement goes out, not a rounding.
 *
 * Output: deploy/dev/previews/ap-statement.html
 *
 * Run: node deploy/dev/build-ap-statement-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { apStatementContext } from './preview/ap-statement-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/ap-statement.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = apStatementContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('ap/statement', 'content', ctx.scope);
  actionsRaw = renderer.section('ap/statement', 'actions', ctx.scope);   // the period and the print link
  headRaw = renderer.push('ap/statement', 'head', ctx.scope);
  scriptsRaw = renderer.push('ap/statement', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Supplier Statement – Coastal Beverages Ltd',
  crumbs: 'AP Ageing / Coastal Beverages Ltd',
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw,
  activeNav: 'ap-statement',
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
