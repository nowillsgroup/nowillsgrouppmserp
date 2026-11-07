#!/usr/bin/env node
/**
 * Build the Tape Chart preview as the real application screen.
 *
 * The content is resources/views/frontdesk/tapechart.blade.php rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/tape-sample.mjs,
 * inside the application shell from deploy/dev/preview/app-shell.mjs. A change to the
 * chart — the metrics, the window controls, the room rows, the bars, the unassigned
 * chips, the drag-and-drop script — shows up here instead of drifting.
 *
 * Output: deploy/dev/previews/tape-chart.html
 *
 * Run: node deploy/dev/build-tape-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { tapeContext } from './preview/tape-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/tape-chart.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = tapeContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('frontdesk/tapechart', 'content', ctx.scope);
  actionsRaw = renderer.section('frontdesk/tapechart', 'actions', ctx.scope);
  headRaw = renderer.push('frontdesk/tapechart', 'head', ctx.scope);
  scriptsRaw = renderer.push('frontdesk/tapechart', 'scripts', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Tape Chart',
  crumbs: `Front Office · ${esc('Availability map')}`,
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw + '\n',
});

const previous = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : null;
if (previous === html) {
  console.log('tape-chart.html is up to date');
} else if (checkOnly) {
  console.error('tape-chart.html is stale — run node deploy/dev/build-tape-preview.mjs');
  process.exit(1);
} else {
  fs.writeFileSync(OUT, html);
  console.log(`wrote deploy/dev/previews/tape-chart.html (${html.length} bytes)`);
}

if (log.failed.length) {
  console.log(`  unresolved expressions .. ${log.failed.length}`);
  for (const item of log.failed.slice(0, 12)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
}
if (log.unknownComponents.length) console.log(`  unknown components ...... ${[...new Set(log.unknownComponents)].join(', ')}`);
if (log.unknownDirectives.length) console.log(`  unknown directives ...... ${[...new Set(log.unknownDirectives.map(d => d.split(' ')[0]))].join(', ')}`);
if (log.errors.length) log.errors.forEach(error => console.error(`  error: ${error}`));
console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length} | expressions: ${log.expressions}`);
