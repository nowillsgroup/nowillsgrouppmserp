#!/usr/bin/env node
/**
 * Build the Banquet booking form preview as the real application screen.
 *
 * The content is resources/views/banquet/form.blade.php rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/banquet-sample.mjs,
 * inside the application shell from deploy/dev/preview/app-shell.mjs. Two modes, because
 * the form is two different jobs:
 *
 *   banquet-form.html       create()  — an empty enquiry, one blank function
 *   banquet-form-edit.html  edit()    — a tentative hold with two functions, a locked
 *                                       guarantee and extras on the reception
 *
 * Output: deploy/dev/previews/banquet-form.html
 *         deploy/dev/previews/banquet-form-edit.html
 *
 * Run: node deploy/dev/build-banquet-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { banquetContext } from './preview/banquet-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const { menu, navHtml } = nav();
const sprite = spriteSymbols();

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const SCREENS = [
  {
    slug: 'banquet-form',
    mode: 'new',
    // the words the view itself puts in @section('title') — the tab, as the app shows it
    title: 'New Banquet Enquiry',
    crumbs: `Banquet / ${esc('New enquiry')}`,
    heading: 'Banquet booking — new enquiry',
    note: 'create() — an enquiry with one blank function. Nothing is held until a tentative hold is taken.',
  },
  {
    slug: 'banquet-form-edit',
    mode: 'edit',
    title: 'Edit BQT-26041',
    crumbs: `Banquet / ${esc('BQT-26041')} / Edit`,
    heading: 'Banquet booking — editing a hold',
    note: 'edit() — a tentative hold with two functions, the ceremony guarantee already final and extras on the reception.',
  },
];

let stale = 0;

for (const screen of SCREENS) {
  const log = {
    expressions: 0, fallbacks: 0,
    failed: [], conditionsDefaulted: [], phpSkipped: [],
    unknownComponents: [], unknownDirectives: [], errors: [],
  };
  const ctx = banquetContext(screen.mode);
  let content = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    content = renderer.section('banquet/form', 'content', ctx.scope);
  } catch (error) {
    console.error(`render failed (${screen.slug}): ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(content), {
    title: screen.title,
    crumbs: screen.crumbs,
    heading: screen.heading,
  });

  const out = path.join(ROOT, `deploy/dev/previews/${screen.slug}.html`);
  const previous = fs.existsSync(out) ? fs.readFileSync(out, 'utf8') : null;
  if (previous === html) {
    console.log(`${screen.slug}.html is up to date`);
  } else if (checkOnly) {
    console.error(`${screen.slug}.html is stale — run node deploy/dev/build-banquet-preview.mjs`);
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
console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length}`);
