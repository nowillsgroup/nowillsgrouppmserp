#!/usr/bin/env node
/**
 * Build the Banquet venue search preview as the real application screen.
 *
 * The content is resources/views/banquet/availability.blade.php rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/banquet-sample.mjs,
 * inside the application shell from deploy/dev/preview/app-shell.mjs. Two jobs, so two
 * screens:
 *
 *   banquet-availability.html      the desk has typed a slot: one venue held by a definite
 *                                  wedding, two free, two too small for the set-up
 *   banquet-availability-idle.html the page before anything is typed — what it asks for,
 *                                  and why a room free at 18:30 is not free at 19:00
 *
 * Output: deploy/dev/previews/banquet-availability.html
 *         deploy/dev/previews/banquet-availability-idle.html
 *
 * Run: node deploy/dev/build-banquet-availability-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { availabilityContext } from './preview/banquet-sample.mjs';

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
    slug: 'banquet-availability',
    variant: 'mixed',
    // the words the view itself puts in @section('title') — the tab, as the app shows it
    title: 'Find a venue',
    crumbs: `Banquet / ${esc('Find a venue')}`,
  },
  {
    slug: 'banquet-availability-idle',
    variant: 'idle',
    title: 'Find a venue',
    crumbs: `Banquet / ${esc('Find a venue')}`,
  },
];

let stale = 0;

for (const screen of SCREENS) {
  const log = {
    expressions: 0, fallbacks: 0,
    failed: [], conditionsDefaulted: [], phpSkipped: [],
    unknownComponents: [], unknownDirectives: [], errors: [],
  };
  const ctx = availabilityContext(screen.variant);
  let content = '';
  let actions = '';
  let head = '';
  try {
    const renderer = new BladePreview({ root: ROOT, ctx, log });
    content = renderer.section('banquet/availability', 'content', ctx.scope);
    actions = renderer.section('banquet/availability', 'actions', ctx.scope); // New enquiry
    head = renderer.push('banquet/availability', 'head', ctx.scope);          // the page's own CSS
  } catch (error) {
    console.error(`render failed (${screen.slug}): ${error.message}`);
    process.exit(1);
  }

  if (log.errors.length) {
    console.error(`  errors: ${log.errors.join(' | ')}`);
    process.exit(1);
  }
  if (log.failed.length) {
    console.error(`  failed expressions: ${log.failed.length}`);
    for (const failure of log.failed.slice(0, 5)) console.error(`    ${failure}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(content), {
    title: screen.title,
    crumbs: screen.crumbs,
    actions: indent(actions, '        '),
    head: `  ${head}\n`,
  });

  const file = path.join(ROOT, 'deploy/dev/previews', `${screen.slug}.html`);
  const previous = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '';
  if (previous !== html) {
    if (!checkOnly) fs.writeFileSync(file, html);
    stale += 1;
  }

  const verbose = !checkOnly || stale > 0;
  if (verbose) {
    console.log(`${checkOnly ? 'STALE' : 'wrote'} deploy/dev/previews/${screen.slug}.html (${html.length} bytes)`);
    console.log(`  ${screen.slug}: expressions ${log.expressions} | fallbacks ${log.fallbacks}`
      + (log.conditionsDefaulted.length ? ` | defaulted ${log.conditionsDefaulted.length}` : '')
      + (log.phpSkipped.length ? ` | php skipped ${log.phpSkipped.length}` : ''));
  }
}

const summary = checkOnly
  ? (stale ? `${stale} stale screen${stale === 1 ? '' : 's'}` : 'up to date')
  : `${SCREENS.length - stale} unchanged`;
console.log(`banquet venue search: ${summary}`);

if (checkOnly && stale) process.exit(1);
