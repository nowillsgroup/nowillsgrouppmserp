#!/usr/bin/env node
/**
 * Build the store requisition preview as the real application screen.
 *
 * The content is resources/views/requisitions/index.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/requisitions-sample.mjs, inside the application shell. The top of the chain: requisition → order →
 * receipt → bill, and the one document in it that a person, not a supplier, holds up.
 *
 * v9.18 the register stops being a list. A requisition is goods going the other way, so it is read three ways: how long
 * each queue has waited on a person (from the tab that owns the state — submitted_at for the head of department,
 * approved_at for the store), what is left of each department's month (issued + still committed, straight from
 * RequisitionService::budgetStatus()), and what has left the store with nobody's signature (StoreIssue rows whose
 * received_at is still null). The clean build is a month where the queues are short, no department is over and every
 * issue is signed; the drift build is a month where a late delivery takes the Front Office 760.00 over its allowance and
 * one maintenance issue note loses its signature, leaving 10,940.00 of stock the store cannot prove arrived.
 *
 * Output: deploy/dev/previews/requisitions.html and deploy/dev/previews/requisitions-drift.html
 *
 * Run: node deploy/dev/build-requisitions-preview.mjs [--check]
 */import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { requisitionsContext } from './preview/requisitions-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'requisitions.html', drift: false },
  { file: 'requisitions-drift.html', drift: true },
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
  const ctx = requisitionsContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('requisitions', 'content', ctx.scope);
    actionsRaw = renderer.section('requisitions', 'actions', ctx.scope);
    headRaw = renderer.push('requisitions', 'head', ctx.scope);
    scriptsRaw = renderer.push('requisitions', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Store Requisitions',
    crumbs: 'Inventory / Store Requisitions',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'requisitions',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-requisitions-preview.mjs`);
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
