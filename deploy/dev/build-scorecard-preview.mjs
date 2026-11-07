#!/usr/bin/env node
/**
 * Build the supplier scorecard preview as the real application screen.
 *
 * The content is resources/views/procurement/scorecard.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/scorecard-sample.mjs, inside the application shell. The last screen the procurement chain can feed,
 * because it needs all of it.
 *
 * v9.19 the scorecard was one weighted number and eleven columns. A score is only an answer, so the page now shows the
 * three readings it is computed from, each with a document behind it: the delivery record (goods receipts against the
 * date their order gave them, with the worst lateness named), the money record (what the shelf took in against what the
 * supplier billed, and what is still owed against it) and the bid record (invitations against answers, with the suppliers
 * who never replied named rather than averaged away). The clean build is a period where the money matches exactly and
 * four deliveries are late; the drift build is the period where two bills disagree with their receipts by 2,150.00 in
 * all — the same two bills round 106 found from the order side.
 *
 * Output: deploy/dev/previews/scorecard.html and deploy/dev/previews/scorecard-drift.html
 *
 * Run: node deploy/dev/build-scorecard-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { scorecardContext } from './preview/scorecard-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'scorecard.html', drift: false },
  { file: 'scorecard-drift.html', drift: true },
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
  const ctx = scorecardContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('procurement/scorecard', 'content', ctx.scope);
    actionsRaw = renderer.section('procurement/scorecard', 'actions', ctx.scope);
    headRaw = renderer.push('procurement/scorecard', 'head', ctx.scope);
    scriptsRaw = renderer.push('procurement/scorecard', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'Supplier Scorecard',
    crumbs: 'Inventory / Supplier scorecard',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'supplier-scorecard',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-scorecard-preview.mjs`);
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
