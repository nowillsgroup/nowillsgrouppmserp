#!/usr/bin/env node
/**
 * Build the management-dashboard preview as a full application screen.
 *
 * The dashboard page on its own is only the content pane; in the application it
 * always appears inside the shell — sidebar, top bar, page head. This builder
 * reproduces that shell from the real sources instead of hand-copying it:
 *
 *   sidebar   ← config/pms.php  ('menu' — every section, group and item)
 *   sprite    ← resources/views/partials/icons.blade.php
 *   top bar   ← resources/views/layouts/app.blade.php
 *   content   ← resources/views/dashboard.blade.php and its two partials,
 *               rendered with the fixed sample in deploy/dev/preview/
 *
 * The content pane is not hand-copied: deploy/dev/preview/blade-render.mjs runs
 * the real Blade source (conditionals, loops, includes, icons) against sample
 * data, so a change to the dashboard shows up here instead of drifting.
 *
 * Output: deploy/dev/previews/management-dashboard.html
 *
 * Run: node deploy/dev/build-dashboard-preview.mjs [--check]
 *      --check exits 1 when the file on disk is stale instead of rewriting it.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc, version, hotel } from './preview/app-shell.mjs';
import { sampleContext, HOTEL } from './preview/dashboard-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/management-dashboard.html');
const checkOnly = process.argv.includes('--check');

/* ── the shell, shared with every other preview builder ───────────────────── */
/* ── render the real dashboard views ──────────────────────────────────────── */

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};
const ctx = sampleContext({ version });
const renderer = new BladePreview({ root: ROOT, ctx, log });
// The three mix builders use PHP loops and array appends beyond this renderer's
// faithful subset. Their precomputed sample equivalents are supplied by the fixture;
// do not partially execute those blocks and overwrite a correct chart with empty data.
const runPhp = renderer.runPhp.bind(renderer);
renderer.runPhp = (code, scope) => {
  if (['$revenueSlices = collect([', '$sourcePalette = [', '$posStatusMeta = ['].some(marker => String(code).includes(marker))) return;
  return runPhp(code, scope);
};
const contentRaw = renderer.section('dashboard', 'content', ctx.scope);
const actionsRaw = renderer.section('dashboard', 'actions', ctx.scope);
const crumbsRaw = renderer.section('dashboard', 'crumbs', ctx.scope);

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : ''))
  .join('\n');

const { menu, navHtml } = nav();
const html = shell(navHtml, spriteSymbols(), indent(contentRaw), {
  title: 'Dashboard',
  crumbs: crumbsRaw || `Operations · ${esc(hotel.name)}`,
  actions: indent(actionsRaw || '', '          '),
});

/* ── render report ────────────────────────────────────────────────────────── */

const uniq = (list) => [...new Set(list)];
console.log(`rendered dashboard.blade.php + dashboard/management-overview + dashboard/management-trends + dashboard/management-pulse`);
console.log(`  echoes evaluated ....... ${log.expressions}`);
console.log(`  fallback values ........ ${log.fallbacks}`);
if (log.failed.length) {
  console.log(`  unevaluated exprs ...... ${log.failed.length}`);
  for (const f of log.failed.slice(0, 25)) console.log(`     · ${f.expr.slice(0, 90)}  [${f.reason.slice(0, 60)}]`);
}
if (log.conditionsDefaulted.length) {
  console.log(`  conditions defaulted ... ${uniq(log.conditionsDefaulted).length}`);
  for (const c of uniq(log.conditionsDefaulted).slice(0, 15)) console.log(`     · ${c.slice(0, 100)}`);
}
if (log.phpSkipped.length) console.log(`  @php blocks skipped .... ${log.phpSkipped.length} (values supplied by the sample)`);
if (log.unknownComponents.length) console.log(`  unknown components ..... ${uniq(log.unknownComponents).join(', ')}`);
if (log.unknownDirectives.length) console.log(`  unknown directives ..... ${uniq(log.unknownDirectives).join(', ')}`);
if (log.errors.length) for (const e of log.errors) console.log(`  error: ${e}`);

console.log(`menu sections: ${menu.length} | nav items: ${(html.match(/class="nav-item /g) || []).length} | sprite symbols: ${spriteSymbols().length}`);

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error('management-dashboard.html is stale — run node deploy/dev/build-dashboard-preview.mjs');
    process.exit(1);
  }
  console.log('management-dashboard.html is up to date');
} else {
  fs.writeFileSync(OUT, html);
  console.log(`wrote ${path.relative(ROOT, OUT)} (${html.length} bytes)`);
}
