#!/usr/bin/env node
/**
 * Build the side-dish costing preview as the real application screen.
 *
 * The content is resources/views/fbc/sides.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/fbc-sides-sample.mjs, inside the application shell. The screen exists because a side dish is priced
 * and costed on its own: until v9.34 its cost was maintained on the menu master and read by nothing, so every side sold
 * quietly improved the food-cost percentage it never paid for.
 *
 * Output: deploy/dev/previews/fbc-sides.html
 *
 * Run: node deploy/dev/build-fbc-sides-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { fbcSidesContext } from './preview/fbc-sides-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/fbc-sides.html');
const checkOnly = process.argv.includes('--check');

const log = { expressions: 0, fallbacks: 0, failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [] };

const { menu, navHtml } = nav();
const sprite = spriteSymbols();
const ctx = fbcSidesContext();

let contentRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('fbc/sides', 'content', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Side dishes sold',
  crumbs: `F&amp;B Cost Control / Side dishes`,
  activeNav: 'fbc-sides',
});

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-fbc-sides-preview.mjs`);
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
void menu; void esc;
