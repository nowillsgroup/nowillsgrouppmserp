#!/usr/bin/env node
/**
 * Build the Reservation Workspace preview as the real application screen.
 *
 * The content is resources/views/reservations/workspace.blade.php rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/workspace-sample.mjs,
 * inside the application shell from deploy/dev/preview/app-shell.mjs. A change to the
 * board — the command bar, the rack, the attention rail, the room cards — shows up
 * here instead of drifting.
 *
 * Output: deploy/dev/previews/reservations-workspace.html
 *
 * Run: node deploy/dev/build-workspace-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { workspaceContext } from './preview/workspace-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/reservations-workspace.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = workspaceContext();

// the head, the actions and the content sections are all part of the screen
let contentRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('reservations/workspace', 'content', ctx.scope);
  headRaw = renderer.push('reservations/workspace', 'head', ctx.scope);
  scriptsRaw = renderer.push('reservations/workspace', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Reservation Workspace',
  crumbs: `Front Office · ${esc('Daily room operations')}`,
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw + '\n',
});

const previous = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : null;
if (previous === html) {
  console.log('reservations-workspace.html is up to date');
} else if (checkOnly) {
  console.error('reservations-workspace.html is stale — run node deploy/dev/build-workspace-preview.mjs');
  process.exit(1);
} else {
  fs.writeFileSync(OUT, html);
  console.log(`wrote deploy/dev/previews/reservations-workspace.html (${html.length} bytes)`);
}

if (log.failed.length) {
  console.log(`  unresolved expressions .. ${log.failed.length}`);
  for (const item of log.failed.slice(0, 12)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
}
if (log.unknownComponents.length) console.log(`  unknown components ...... ${[...new Set(log.unknownComponents)].join(', ')}`);
if (log.unknownDirectives.length) console.log(`  unknown directives ...... ${[...new Set(log.unknownDirectives.map(d => d.split(' ')[0]))].join(', ')}`);
if (log.errors.length) log.errors.forEach(error => console.error(`  error: ${error}`));
console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length} | expressions: ${log.expressions}`);
