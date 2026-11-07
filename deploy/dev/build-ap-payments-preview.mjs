#!/usr/bin/env node
/**
 * Build the supplier payments preview as the real application screen.
 *
 * The content is resources/views/ap/payments.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/ap-payments-sample.mjs, inside the application shell. Money out, and what is left of it.
 *
 * v9.2 the two credit figures come from ApService::creditSources() — the same method the ageing's credit column and the
 * bills list's payable figure read — so the 11,600 on this screen is the 11,600 the ageing nets off, not a third
 * opinion. The sample shows why the rule needs saying: a foreign payment's remainder is an exchange difference, and a
 * cancelled payment is not a credit either.
 *
 * v9.15 the unapplied money is aged from the day it left the bank, and the two records a payment can get wrong on its own
 * are read back over the payments: the certificate for tax withheld, and the allocated column against the allocations on
 * file. The drift build is a payment file that does not add up in two places — the Sahel certificate missing and the Volta
 * payment's allocated figure 500.00 adrift from its allocations.
 *
 * Output: deploy/dev/previews/ap-payments.html and deploy/dev/previews/ap-payments-drift.html
 *
 * Run: node deploy/dev/build-ap-payments-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { apPaymentsContext } from './preview/ap-payments-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'ap-payments.html', drift: false },
  { file: 'ap-payments-drift.html', drift: true },
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
  const ctx = apPaymentsContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('ap/payments', 'content', ctx.scope);
    actionsRaw = renderer.section('ap/payments', 'actions', ctx.scope);
    headRaw = renderer.push('ap/payments', 'head', ctx.scope);
    scriptsRaw = renderer.push('ap/payments', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Supplier Payments',
    crumbs: 'Accounts / Payables',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'ap-payments',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-ap-payments-preview.mjs`);
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
