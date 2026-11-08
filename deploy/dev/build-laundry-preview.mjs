#!/usr/bin/env node
/**
 * Build the Laundry previews as the real application screens.
 *
 * Both screens come from the application views, rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/laundry-sample.mjs,
 * inside the application shell from deploy/dev/preview/app-shell.mjs:
 *
 *   laundry-order.html  resources/views/laundry/show.blade.php   — one order, with the
 *                       delivery dialog that now settles to the room or at the counter
 *   laundry-prices.html resources/views/laundry/prices.blade.php — the price list and the
 *                       tax every laundry charge carries
 *   laundry-settled.html resources/views/laundry/show.blade.php  — the same order after the
 *                       guest paid at the counter: delivered, invoiced, tax on the line
 *   laundry-list.html   resources/views/laundry/index.blade.php  — the desk list, priced
 *
 * Run: node deploy/dev/build-laundry-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { laundryOrderContext, laundryPricesContext, laundrySettledContext, laundryIndexContext, laundryPreservationContext, laundryRequestContext } from './preview/laundry-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const { menu, navHtml } = nav();
const sprite = spriteSymbols();

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const screens = [
  {
    view: 'laundry/create', file: 'laundry-request.html', title: 'Collect Laundry', crumbs: 'Laundry & linen / Collect',
    context: laundryRequestContext,
    sections: ['actions', 'content'],
  },
  {
    view: 'laundry/show', file: 'laundry-order.html', title: 'Laundry LND-000412', crumbs: 'Laundry & linen / LND-000412',
    context: laundryOrderContext,
    sections: ['actions', 'content'],
  },
  {
    view: 'laundry/show', file: 'laundry-preservation.html', title: 'Laundry LND-000412', crumbs: 'Laundry & linen / LND-000412',
    context: laundryPreservationContext,
    sections: ['actions', 'content'],
  },
  {
    view: 'laundry/prices', file: 'laundry-prices.html', title: 'Laundry Price List', crumbs: 'Laundry & linen / Price list',
    context: laundryPricesContext,
    sections: ['actions', 'content'],
  },
  {
    view: 'laundry/show', file: 'laundry-settled.html', title: 'Laundry LND-000412', crumbs: 'Laundry & linen / LND-000412',
    context: laundrySettledContext,
    sections: ['actions', 'content'],
  },
  {
    view: 'laundry/index', file: 'laundry-list.html', title: 'Laundry Orders', crumbs: 'Laundry & linen / Laundry orders',
    context: laundryIndexContext,
    sections: ['actions', 'content'],
  },
];

let failures = 0;

for (const screen of screens) {
  const log = {
    expressions: 0, fallbacks: 0, failed: [], conditionsDefaulted: [], phpSkipped: [],
    unknownComponents: [], unknownDirectives: [], errors: [],
  };
  const ctx = screen.context();
  let contentRaw = '';
  let actionsRaw = '';
  let headRaw = '';
  let scriptsRaw = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    contentRaw = renderer.section(screen.view, 'content', ctx.scope);
    actionsRaw = screen.view === 'laundry/create' ? '' : renderer.section(screen.view, 'actions', ctx.scope);
    headRaw = renderer.push(screen.view, 'head', ctx.scope);
    scriptsRaw = renderer.push(screen.view, 'scripts', ctx.scope);
    // The itemising grid carries its own live total script.
    scriptsRaw += renderer.push('laundry/_grid', 'scripts', ctx.scope);
  } catch (error) {
    console.error(`${screen.file}: render failed — ${error.message}`);
    process.exit(1);
  }

  const notice = '<div class="alert alert-info" role="note"><b>Sample preview.</b> Fictional laundry order; changes cannot be saved.</div>';
  const html = shell(navHtml, sprite, notice + indent(contentRaw), {
    title: screen.title,
    crumbs: screen.crumbs,
    actions: indent(actionsRaw, '        '),
    head: headRaw ? `  ${headRaw}\n` : '',
    scripts: '<script>document.addEventListener("submit", e => e.preventDefault());</script>' + scriptsRaw + '\n',
  }).replace(/[ \t]+$/gm, '');

  const out = path.join(ROOT, 'deploy/dev/previews', screen.file);
  const previous = fs.existsSync(out) ? fs.readFileSync(out, 'utf8') : null;
  if (previous === html) {
    console.log(`${screen.file} is up to date`);
  } else if (checkOnly) {
    console.error(`${screen.file} is stale — run node deploy/dev/build-laundry-preview.mjs`);
    failures += 1;
  } else {
    fs.writeFileSync(out, html);
    console.log(`wrote deploy/dev/previews/${screen.file} (${html.length} bytes)`);
  }

  if (log.failed.length) {
    failures += 1;
    console.log(`  unresolved expressions .. ${log.failed.length}`);
    for (const item of log.failed.slice(0, 12)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
  }
  if (log.unknownComponents.length) console.log(`  unknown components ...... ${[...new Set(log.unknownComponents)].join(', ')}`);
  if (log.unknownDirectives.length) console.log(`  unknown directives ...... ${[...new Set(log.unknownDirectives.map(d => d.split(' ')[0]))].join(', ')}`);
  if (log.errors.length) log.errors.forEach(error => console.error(`  error: ${error}`));
}

console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length}`);
if (failures) process.exit(1);
