#!/usr/bin/env node
/**
 * Build the Club Plans preview as the real application screen.
 *
 * The content is resources/views/club/plans.blade.php rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/club-plans-sample.mjs, inside
 * the application shell. This is the screen the rm_round_to and rm_mode settings actually
 * show up on: the recommendation, the rules that produced it, the guard-rail it hit, and
 * what the grid refuses to let an operator publish.
 *
 * Output: deploy/dev/previews/club-plans.html
 *
 * Run: node deploy/dev/build-club-plans-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { clubPlansContext } from './preview/club-plans-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/club-plans.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = clubPlansContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('club/plans', 'content', ctx.scope);
  actionsRaw = renderer.section('club/plans', 'actions', ctx.scope);
  headRaw = renderer.push('club/plans', 'head', ctx.scope);
  scriptsRaw = renderer.push('club/plans', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Club Plans',
  crumbs: `Health Club · ${esc('Plans & settings')}`,
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw,
  activeNav: 'club',
});

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-club-plans-preview.mjs`);
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
