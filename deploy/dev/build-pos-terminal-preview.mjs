#!/usr/bin/env node
/**
 * Build the POS terminal preview as the real application screen.
 *
 * The content is resources/views/pos/terminal.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/pos-terminal-sample.mjs, inside the application shell.
 *
 * v9.29 the screen where an order is written. The reason for this preview is the side-dish picker: four of the eight
 * dishes on the menu come with side dishes, and tapping one of them opens "Side dish for <dish>" before the dish reaches
 * the cart — the sides are ticked, the charged ones show their rate, Continue carries them into the option picker, and
 * the dish is added to the order with its sides attached. "Grilled Chicken & Chips" is already on the check that way.
 *
 * The whole thing is the shipped inline script, not a mockup: tap a tile, pick sides, add, watch the line and the total.
 *
 * Output: deploy/dev/previews/pos-terminal.html
 *
 * Run: node deploy/dev/build-pos-terminal-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { posTerminalContext } from './preview/pos-terminal-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'pos-terminal.html', drift: false },
];

let stale = false;
for (const page of PAGES) {
  const OUT = path.join(ROOT, 'deploy/dev/previews', page.file);
  const log = {
    expressions: 0, fallbacks: 0,
    failed: [], conditionsDefaulted: [], phpSkipped: [],
    unknownComponents: [], unknownDirectives: [], errors: [],
  };
  const { navHtml } = nav();
  const sprite = spriteSymbols();
  const ctx = posTerminalContext();

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('pos/terminal', 'content', ctx.scope);
    actionsRaw = renderer.section('pos/terminal', 'actions', ctx.scope);
    headRaw = renderer.push('pos/terminal', 'head', ctx.scope);
    scriptsRaw = renderer.push('pos/terminal', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Order POS-2026-0412 · Table 12',
    crumbs: 'POS / Terrace Restaurant / Table 12',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'pos',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-pos-terminal-preview.mjs`);
      stale = true;
      continue;
    }
    console.log(`${path.relative(ROOT, OUT)} is up to date`);
    continue;
  }

  fs.writeFileSync(OUT, html);
  console.log(`wrote ${path.relative(ROOT, OUT)} (${html.length.toLocaleString('en-US')} bytes)`);
  for (const [kind, list] of [['php', log.phpSkipped], ['conditions', log.conditionsDefaulted], ['components', log.unknownComponents], ['directives', log.unknownDirectives], ['errors', log.errors], ['failed', log.failed]]) {
    if (list.length) console.log(`  ${kind}: ${list.slice(0, 6).join(' · ')}${list.length > 6 ? ` … (${list.length})` : ''}`);
  }
}

if (stale) process.exit(1);
