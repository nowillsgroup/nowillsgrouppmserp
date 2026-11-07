#!/usr/bin/env node
/**
 * Build the agent commissions preview as the real application screen.
 *
 * The content is resources/views/accounts/commissions.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/commissions-sample.mjs, inside the application shell.
 *
 * v9.12 the screen reads as an ageing — days since check-out on the same 30/60/90 rule as the receivables, summarised by
 * agent from the same aged collection the table is built from — and it checks its own documents: a commission marked paid
 * must name the voucher that paid it. Two files are built, because the fault the control exists for is the one nobody
 * expects: the drift build is the 1,120.00 settlement marked paid with no voucher behind it.
 *
 * Output: deploy/dev/previews/commissions.html and deploy/dev/previews/commissions-drift.html
 *
 * Run: node deploy/dev/build-commissions-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { commissionsContext } from './preview/commissions-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'commissions.html', drift: false },
  { file: 'commissions-drift.html', drift: true },
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
  const ctx = commissionsContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('accounts/commissions', 'content', ctx.scope);   // the screen has no actions bar
    headRaw = renderer.push('accounts/commissions', 'head', ctx.scope);
    scriptsRaw = renderer.push('accounts/commissions', 'scripts', ctx.scope);   // the selection total
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Agent Commissions',
    crumbs: 'Accounts / Agent Commissions',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'commissions',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-commissions-preview.mjs`);
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
