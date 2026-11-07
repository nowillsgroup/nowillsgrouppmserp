#!/usr/bin/env node
/**
 * Build the RFQ register preview as the real application screen.
 *
 * The content is resources/views/procurement/rfqs.blade.php rendered by deploy/dev/preview/blade-render.mjs against
 * deploy/dev/preview/rfqs-sample.mjs, inside the application shell.
 *
 * v9.27 the register of the only document where a price is decided by competition. It could say how many requests were
 * sent and how many quotes came back; it could not say whether anybody answered — an invited supplier who opened the
 * link and never replied is not the same as one who declined — whether the deadline had run out with no award made, or
 * what the competition actually bought.
 *
 * Ten requests, thirteen invitations, nine quotes (69.2 %). Three requests are past their deadline with no award, the
 * oldest eight days; one of them holds two quotes where this property asks for three. Three awards this month placed
 * 21,460.00: 2,650.00 under the dearest bid, 350.00 over the cheapest, on three lines that went to a supplier who was not
 * the lowest bidder and all three carry a written justification.
 *
 * The drift build is the month the competition did not happen: the same three requests, the same three quotes each, and
 * every award taken at the dearest bid with nobody writing a justification — 24,110.00 placed instead of 21,460.00, ten
 * lines away from the lowest bidder, 3,000.00 over the cheapest, and the award card red.
 *
 * Output: deploy/dev/previews/rfqs.html and rfqs-drift.html
 *
 * Run: node deploy/dev/build-rfqs-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { rfqsContext } from './preview/rfqs-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const PAGES = [
  { file: 'rfqs.html', drift: false },
  { file: 'rfqs-drift.html', drift: true },
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
  const ctx = rfqsContext({ drift: page.drift });

  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section('procurement/rfqs', 'content', ctx.scope);
    actionsRaw = renderer.section('procurement/rfqs', 'actions', ctx.scope);
    headRaw = renderer.push('procurement/rfqs', 'head', ctx.scope);
    scriptsRaw = renderer.push('procurement/rfqs', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`render failed (${page.file}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: 'RFQs & Quotations',
    crumbs: 'Purchasing / RFQs',
    actions: indent(actionsRaw, '        '),
    head: `  ${headRaw}\n`,
    scripts: scriptsRaw,
    activeNav: 'rfqs',
  });

  if (checkOnly) {
    const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
    if (current !== html) {
      console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-rfqs-preview.mjs`);
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
