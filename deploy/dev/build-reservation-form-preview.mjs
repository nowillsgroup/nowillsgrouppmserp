#!/usr/bin/env node
/**
 * Build the New Reservation preview as the real application screen.
 *
 * The content is resources/views/reservations/form.blade.php rendered by
 * deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/reservation-form-sample.mjs, inside the application shell from
 * deploy/dev/preview/app-shell.mjs. The screen is the booking desk as it opens: an
 * empty stay, no room lines yet, and the guest block with the same "Others" title
 * control the walk-in desk carries.
 *
 * Output: deploy/dev/previews/reservation-form.html
 *
 * Run: node deploy/dev/build-reservation-form-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { reservationFormContext } from './preview/reservation-form-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/reservation-form.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = reservationFormContext();

let contentRaw = '';
let scriptsRaw = '';
let headRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('reservations/form', 'content', ctx.scope);
  headRaw = renderer.push('reservations/form', 'head', ctx.scope);
  scriptsRaw = renderer.push('reservations/form', 'scripts', ctx.scope);
  // The guest capture block carries its own behaviour, and the booking desk includes
  // it with the custom-title picker switched on.
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
  title: 'New Reservation',
  crumbs: `Reservations · ${esc('New Reservation')}`,
  actions: '',
  head: `  ${headRaw}\n`,
  scripts: scriptsRaw + '\n',
});

const previous = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : null;
if (previous === html) {
  console.log('reservation-form.html is up to date');
} else if (checkOnly) {
  console.error('reservation-form.html is stale — run node deploy/dev/build-reservation-form-preview.mjs');
  process.exit(1);
} else {
  fs.writeFileSync(OUT, html);
  console.log(`wrote deploy/dev/previews/reservation-form.html (${html.length} bytes)`);
}

if (log.failed.length) {
  console.log(`  unresolved expressions .. ${log.failed.length}`);
  for (const item of log.failed.slice(0, 12)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
}
if (log.unknownComponents.length) console.log(`  unknown components ...... ${[...new Set(log.unknownComponents)].join(', ')}`);
if (log.unknownDirectives.length) console.log(`  unknown directives ...... ${[...new Set(log.unknownDirectives.map(d => d.split(' ')[0]))].join(', ')}`);
if (log.phpSkipped.length) console.log(`  php skipped ............. ${log.phpSkipped.length}`);
if (log.errors.length) log.errors.forEach(error => console.error(`  error: ${error}`));
console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length} | expressions: ${log.expressions}`);
