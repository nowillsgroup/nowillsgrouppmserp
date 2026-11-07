#!/usr/bin/env node
/**
 * Build the Banquet kitchen menu sheet preview.
 *
 * This screen is a printed document, not an application page, so it is rendered whole —
 * resources/views/banquet/menu.blade.php with its own <head>, the shared banquet document
 * styles and its own page rules — and not wrapped in the application shell. What is on
 * screen is what comes out of the printer.
 *
 * Two screens:
 *
 *   banquet-menu.html         BanquetController::menu()             — the whole event, two
 *                                                                     functions, one per page
 *   banquet-menu-single.html  BanquetController::menu()?function=…  — the single function the
 *                                                                     day sheet links to
 *
 * Output: deploy/dev/previews/banquet-menu.html
 *         deploy/dev/previews/banquet-menu-single.html
 *
 * Run: node deploy/dev/build-banquet-menu-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { menuContext, FUNCTIONS } from './preview/banquet-menu-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const SCREENS = [
  {
    slug: 'banquet-menu',
    focus: 0,
    note: 'menu() — the whole event: a ceremony with no F&B, and the reception on the Gold Buffet.',
  },
  {
    slug: 'banquet-menu-single',
    focus: FUNCTIONS[1].id,
    note: 'menu()?function=4102 — the one function the day sheet links to.',
  },
];

let stale = 0;

for (const screen of SCREENS) {
  const log = {
    expressions: 0, fallbacks: 0,
    failed: [], conditionsDefaulted: [], phpSkipped: [],
    unknownComponents: [], unknownDirectives: [], errors: [],
  };
  const ctx = menuContext({ focus: screen.focus });
  let html = '';
  try {
    html = new BladePreview({ root: ROOT, ctx, log }).renderFile('banquet/menu', ctx.scope);
  } catch (error) {
    console.error(`render failed (${screen.slug}): ${error.message}`);
    process.exit(1);
  }

  const out = path.join(ROOT, `deploy/dev/previews/${screen.slug}.html`);
  const previous = fs.existsSync(out) ? fs.readFileSync(out, 'utf8') : null;
  if (previous === html) {
    console.log(`${screen.slug}.html is up to date`);
  } else if (checkOnly) {
    console.error(`${screen.slug}.html is stale — run node deploy/dev/build-banquet-menu-preview.mjs`);
    stale += 1;
  } else {
    fs.writeFileSync(out, html);
    console.log(`wrote deploy/dev/previews/${screen.slug}.html (${html.length} bytes)`);
  }

  if (log.failed.length) {
    console.log(`  unresolved expressions .. ${log.failed.length}`);
    for (const item of log.failed.slice(0, 12)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
  }
  if (log.unknownComponents.length) console.log(`  unknown components ...... ${[...new Set(log.unknownComponents)].join(', ')}`);
  if (log.unknownDirectives.length) console.log(`  unknown directives ...... ${[...new Set(log.unknownDirectives.map(d => d.split(' ')[0]))].join(', ')}`);
  if (log.errors.length) log.errors.forEach(error => console.error(`  error: ${error}`));
  console.log(`  ${screen.slug}: expressions ${log.expressions} | fallbacks ${log.fallbacks}`);
}

if (stale) process.exit(1);
