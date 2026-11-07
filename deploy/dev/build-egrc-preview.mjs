#!/usr/bin/env node
/**
 * Build the e-GRC signature screen — the tablet the guest signs on — as a full
 * application screen.
 *
 * resources/views/frontdesk/grc-sign.blade.php is rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/egrc-sample.mjs (which
 * sits on the same record as the printed card), inside the application shell from
 * deploy/dev/preview/app-shell.mjs.
 *
 * Output: deploy/dev/previews/egrc-signature.html
 *
 * Run: node deploy/dev/build-egrc-preview.mjs [--check]
 *      --check exits 1 when the file on disk is stale instead of rewriting it.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { egrcContext } from './preview/egrc-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/egrc-signature.html');
const checkOnly = process.argv.includes('--check');

const newLog = () => ({
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
});

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

/** Renders the screen for one sample state and returns the whole document. */
export function renderEgrc(options = {}) {
  const ctx = egrcContext(options);
  const log = newLog();
  const renderer = new BladePreview({ root: ROOT, ctx, log });

  const content = renderer.section('frontdesk/grc-sign', 'content', ctx.scope);
  const actions = renderer.section('frontdesk/grc-sign', 'actions', ctx.scope);
  const crumbs = renderer.section('frontdesk/grc-sign', 'crumbs', ctx.scope);
  const head = renderer.push('frontdesk/grc-sign', 'head', ctx.scope);
  const scripts = renderer.push('frontdesk/grc-sign', 'scripts', ctx.scope);

  const { menu, navHtml } = nav();
  const sprite = spriteSymbols();

  const html = shell(navHtml, sprite, indent(content), {
    title: 'e-Registration card',
    crumbs: crumbs.replace(/\s+/g, ' ').trim(),
    actions: actions.trim(),
    head,
    scripts,
  });

  return { html, log, ctx, menu, sprite };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const { html, log, menu, sprite } = renderEgrc();
  const stale = !fs.existsSync(OUT) || fs.readFileSync(OUT, 'utf8') !== html;

  if (checkOnly) {
    if (stale) {
      console.error('egrc-signature.html is out of date — run node deploy/dev/build-egrc-preview.mjs');
      process.exit(1);
    }
    console.log('egrc-signature.html is up to date');
  } else if (stale) {
    fs.writeFileSync(OUT, html);
    console.log(`wrote deploy/dev/previews/egrc-signature.html (${html.length} bytes)`);
  } else {
    console.log('egrc-signature.html is up to date');
  }

  if (log.failed.length) {
    console.log(`  unresolved expressions .. ${log.failed.length}`);
    for (const item of log.failed.slice(0, 12)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
  }
  if (log.unknownComponents.length) {
    console.log(`  unknown components ...... ${[...new Set(log.unknownComponents)].join(', ')}`);
  }
  if (log.unknownDirectives.length) {
    console.log(`  unknown directives ...... ${[...new Set(log.unknownDirectives.map(d => d.split(' ')[0]))].join(', ')}`);
  }
  if (log.errors.length) log.errors.forEach(error => console.error(`  error: ${error}`));
  console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length} | expressions: ${log.expressions}`);
}
