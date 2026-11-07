#!/usr/bin/env node
/**
 * Build the v10.7 consolidated group dashboard preview as the real application screen.
 *
 * The one screen the licensing story touches that had no preview: four trading properties added up,
 * each on its own business date, and — new in v10.7 — the property licensed for nothing left OUT of
 * the totals and named above them, because a shut property cannot post and its rooms would flatten
 * the group's occupancy.
 *
 * Content: resources/views/group/dashboard.blade.php, rendered by deploy/dev/preview/blade-render.mjs
 * against deploy/dev/preview/properties-sample.mjs (groupDashboardContext), inside the app shell.
 *
 * Output: deploy/dev/previews/group-dashboard.html
 *
 * Run: node deploy/dev/build-group-dashboard-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { groupDashboardContext } from './preview/properties-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'deploy/dev/previews/group-dashboard.html');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [],
};

const { navHtml } = nav();
const sprite = spriteSymbols();
const ctx = groupDashboardContext();

let contentRaw = '';
let actionsRaw = '';
try {
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  contentRaw = renderer.section('group/dashboard', 'content', ctx.scope);
  actionsRaw = renderer.section('group/dashboard', 'actions', ctx.scope);
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const html = shell(navHtml, sprite, indent(contentRaw), {
  title: 'Group Dashboard',
  crumbs: `Nowills Group / Consolidated view`,
  actions: indent(actionsRaw, '        '),
  head: '',
  scripts: '',
  activeNav: 'group-dashboard',
});

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== html) {
    console.error(`${path.relative(ROOT, OUT)} is stale — run node deploy/dev/build-group-dashboard-preview.mjs`);
    process.exit(1);
  }
  console.log(`${path.relative(ROOT, OUT)} is up to date`);
  process.exit(0);
}

fs.writeFileSync(OUT, html);
console.log(`wrote ${path.relative(ROOT, OUT)} (${html.length.toLocaleString('en-US')} bytes)`);
for (const [kind, list] of [['php', log.phpSkipped], ['conditions', log.conditionsDefaulted], ['components', log.unknownComponents], ['directives', log.unknownDirectives], ['errors', log.errors]]) {
  if (list.length) console.log(`  ${kind}: ${list.slice(0, 4).join(' · ')}${list.length > 4 ? ` … (${list.length})` : ''}`);
}
