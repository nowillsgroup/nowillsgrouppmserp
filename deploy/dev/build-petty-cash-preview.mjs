#!/usr/bin/env node
/**
 * Build the petty cash preview as the real application screen.
 *
 * The content is resources/views/petty/index.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/petty-cash-sample.mjs, inside the application shell.
 *
 * v9.13 the screen holds the tin to two controls that a status column cannot satisfy: the float's ledger account (posted at
 * the night audit, so a movement dated after the last close is an explained difference and anything else is money the books
 * cannot see), and the receipt behind every spend over the threshold. The register ages both — days since the tin was
 * counted, days since it was last topped up. Two files are built, because the fault the receipt control exists for is the
 * one nobody reports: the drift build is the 640.00 water bill with no receipt and no reason, in a tin whose imprest and
 * ledger ties are untouched by it.
 *
 * Output: deploy/dev/previews/petty-cash.html and deploy/dev/previews/petty-cash-drift.html
 *
 * Run: node deploy/dev/build-petty-cash-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { pettyContext } from './preview/petty-cash-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'petty-cash.html', drift: false },
  { file: 'petty-cash-drift.html', drift: true },
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
  const ctx = pettyContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('petty/index', 'content', ctx.scope);
    actionsRaw = renderer.section('petty/index', 'actions', ctx.scope);   // new float, cash book report, expense claims
    headRaw = renderer.push('petty/index', 'head', ctx.scope);
    scriptsRaw = renderer.push('petty/index', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Petty Cash',
    crumbs: 'Accounts / Petty cash',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'petty-cash',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-petty-cash-preview.mjs`);
      stale = true;
      continue;
    }
    console.log(`${path.relative(ROOT, OUT)} is up to date`);
    continue;
  }

  fs.writeFileSync(OUT, html);
  console.log(`wrote ${path.relative(ROOT, OUT)} (${html.length.toLocaleString('en-US')} bytes)`);
  for (const [kind, list] of [['php', log.phpSkipped], ['conditions', log.conditionsDefaulted], ['components', log.unknownComponents], ['directives', log.unknownDirectives], ['errors', log.errors]]) {
    if (list.length) console.log(`  ${kind}: ${list.slice(0, 4).join(' · ')}${list.length > 4 ? ` … (${list.length})` : ''}`);
  }
}

if (stale) process.exit(1);
