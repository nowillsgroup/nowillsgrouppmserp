#!/usr/bin/env node
/**
 * Build the v10.6 "this module is not licensed here" page as the real application screen.
 *
 * v10.3 refused an unlicensed module with a bare 403 and one sentence, which left the reader unable
 * to tell whether the group had never bought the module or the property they were standing in had
 * switched it off — two different people fix those, and one of them is not the person reading.
 *
 * The content is resources/views/errors/licence.blade.php rendered by deploy/dev/preview/blade-render.mjs
 * against deploy/dev/preview/properties-sample.mjs (licenceRefusedContext), inside the app shell.
 *
 * Output: deploy/dev/previews/licence-refused.html
 *
 * Run: node deploy/dev/build-licence-refused-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { licenceRefusedContext } from './preview/properties-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/licence-refused.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [],
};

// The consumer's sidebar no longer offers the module this page refuses — switching a module off
// "hides its screens" — so the preview hides the same three items the property would not see.
const { navHtml } = nav({ hide: ['Appointments', 'Services & Activities', 'Treatment Rooms'] });
const sprite = spriteSymbols();
const ctx = licenceRefusedContext();

let contentRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('errors/licence', 'content', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Spa & Activities is not licensed',
  crumbs: `Dashboard / Licence`,
  actions: '',
  head: '',
  scripts: '',
  activeNav: 'properties',
});

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-licence-refused-preview.mjs`);
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
