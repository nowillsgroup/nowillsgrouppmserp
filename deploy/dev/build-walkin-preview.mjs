#!/usr/bin/env node
/**
 * Build the Walk-in Check-in preview as the real application screen.
 *
 * The content is resources/views/frontdesk/walkin.blade.php rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/walkin-sample.mjs,
 * inside the application shell from deploy/dev/preview/app-shell.mjs. The screen opens
 * on the day-use stay so the new stay-type control, the "room needed until" time and
 * the day-use charge are the first thing you see.
 *
 * Output: deploy/dev/previews/walkin-checkin.html
 *
 * Run: node deploy/dev/build-walkin-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { walkinContext } from './preview/walkin-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/walkin-checkin.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = walkinContext();

let contentRaw = '';
let actionsRaw = '';
let headRaw = '';
let scriptsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('frontdesk/walkin', 'content', ctx.scope);
  // This screen has no @section('actions'); the header actions stay empty.
  headRaw = renderer.push('frontdesk/walkin', 'head', ctx.scope);
  scriptsRaw = renderer.push('frontdesk/walkin', 'scripts', ctx.scope);
  // The guest capture block carries its own behaviour; the desk includes it with the
  // custom-title picker switched on, so that script belongs on this page too.
  scriptsRaw += renderer.push('reservations/_guest', 'scripts',
    Object.assign(Object.create(ctx.scope), { allowCustomGuestTitle: true }));
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Walk-in Check-in',
  crumbs: `Front Office · ${esc('Walk-in check-in')}`,
  actions: indent(actionsRaw, '        '),
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw + '\n',
});

const previous = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : null;
if (previous === html) {
  console.log('walkin-checkin.html is up to date');
} else if (checkOnly) {
  console.error('walkin-checkin.html is stale — run node deploy/dev/build-walkin-preview.mjs');
  process.exit(1);
} else {
  fs.writeFileSync(OUT, html);
  console.log(`wrote deploy/dev/previews/walkin-checkin.html (${html.length} bytes)`);
}

if (log.failed.length) {
  console.log(`  unresolved expressions .. ${log.failed.length}`);
  for (const item of log.failed.slice(0, 12)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
}
if (log.unknownComponents.length) console.log(`  unknown components ...... ${[...new Set(log.unknownComponents)].join(', ')}`);
if (log.unknownDirectives.length) console.log(`  unknown directives ...... ${[...new Set(log.unknownDirectives.map(d => d.split(' ')[0]))].join(', ')}`);
if (log.errors.length) log.errors.forEach(error => console.error(`  error: ${error}`));
console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length} | expressions: ${log.expressions}`);
