#!/usr/bin/env node
/**
 * Build the expense claims preview as the real application screen.
 *
 * The content is resources/views/claims/index.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/claims-sample.mjs, inside the application shell.
 *
 * v9.14 the screen reads a claim as money an employee has already spent: the two queues are aged from the day the queue
 * started, and the two rules the service enforces at entry — the receipt above the threshold and the claim window — are
 * read back over the claims that already exist. Two files are built, because the fault the receipt rule exists for cannot
 * be entered any more but can certainly be inherited: the drift build is the 940.00 pump-parts line with no receipt and no
 * reference, on a claim that otherwise looks ordinary.
 *
 * Output: deploy/dev/previews/claims.html and deploy/dev/previews/claims-drift.html
 *
 * Run: node deploy/dev/build-claims-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { claimsContext } from './preview/claims-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'claims.html', drift: false },
  { file: 'claims-drift.html', drift: true },
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
  const ctx = claimsContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('claims/index', 'content', ctx.scope);
    actionsRaw = renderer.section('claims/index', 'actions', ctx.scope);   // new claim, claims register, my claims
    headRaw = renderer.push('claims/index', 'head', ctx.scope);
    scriptsRaw = renderer.push('claims/index', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Expense Claims',
    crumbs: 'Accounts / Expense claims',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'claims',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-claims-preview.mjs`);
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
