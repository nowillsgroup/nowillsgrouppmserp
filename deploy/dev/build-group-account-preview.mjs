#!/usr/bin/env node
/**
 * Build the v10.0 group account preview as the real application screen.
 *
 * The content is resources/views/group/account.blade.php rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/properties-sample.mjs, inside the
 * application shell: the tenant account itself — identity, the defaults every new property inherits,
 * the property entitlement, the people who administer it, the switch that suspends the whole group,
 * and the portfolio and audit trail that sit under it.
 *
 * Output: deploy/dev/previews/group-account.html
 *
 * Run: node deploy/dev/build-group-account-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { groupAccountContext } from './preview/properties-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/group-account.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [],
};

const { navHtml } = nav();
const sprite = spriteSymbols();
const ctx = groupAccountContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('group/account', 'content', ctx.scope);
  actionsRaw = renderer.section('group/account', 'actions', ctx.scope);
  headRaw = renderer.push('group/account', 'head', ctx.scope);
  scriptsRaw = renderer.push('group/account', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Group account',
  crumbs: `Properties / ${esc('Nowills Group')}`,
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw,
  activeNav: 'properties',
});

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-group-account-preview.mjs`);
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
