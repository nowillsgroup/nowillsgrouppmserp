#!/usr/bin/env node
/**
 * Build the voucher register preview as the real application screen.
 *
 * The content is resources/views/accounts/vouchers.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/vouchers-sample.mjs, inside the application shell.
 *
 * v9.11 the register says what only the register can say: every receipt and payment written in the period becomes a ledger
 * entry at its account head, so the page analyses the period by head and then holds each head's vouchers — those dated on
 * or before the posted-through day — against that head's posted movement. Two files are built: the ordinary period, where
 * all nine posted vouchers are in the ledger, and the drift build, where the 7,800.00 fuel payment never reached it and the
 * page has to name Fuel rather than print a difference nobody can open.
 *
 * Output: deploy/dev/previews/vouchers.html and deploy/dev/previews/vouchers-drift.html
 *
 * Run: node deploy/dev/build-vouchers-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { vouchersContext } from './preview/vouchers-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'vouchers.html', drift: false },
  { file: 'vouchers-drift.html', drift: true },
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
  const ctx = vouchersContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('accounts/vouchers', 'content', ctx.scope);
    actionsRaw = renderer.section('accounts/vouchers', 'actions', ctx.scope);   // payment and receipt buttons
    headRaw = renderer.push('accounts/vouchers', 'head', ctx.scope);
    scriptsRaw = renderer.push('accounts/vouchers', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Vouchers',
    crumbs: 'Accounts / Voucher Register',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'vouchers',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-vouchers-preview.mjs`);
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
