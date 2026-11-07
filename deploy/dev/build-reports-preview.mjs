#!/usr/bin/env node
/**
 * Build the Reports previews as the real application screens.
 *
 * Both screens come from the application views, rendered by
 * deploy/dev/preview/blade-render.mjs against deploy/dev/preview/reports-sample.mjs,
 * inside the application shell from deploy/dev/preview/app-shell.mjs:
 *
 *   reports-library.html  resources/views/reports/index.blade.php   — the catalogue
 *   reports-report.html   resources/views/reports/show.blade.php    — one report, read
 *   reports-views.html    resources/views/reports/library.blade.php — saved views
 *
 * Run: node deploy/dev/build-reports-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import {
  reportsLibraryContext, reportsDetailContext, reportsSavedViewsContext, reportsSchedulesContext, reportsAlertsContext, reportsAsOnContext,
} from './preview/reports-sample.mjs';

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
    view: 'reports/index', file: 'reports-library.html', title: 'Reports', crumbs: 'Reports',
    context: reportsLibraryContext,
  },
  {
    view: 'reports/show', file: 'reports-report.html', title: 'POS Sales by Outlet', crumbs: 'Reports / POS Sales by Outlet',
    context: reportsDetailContext,
  },
  {
    view: 'reports/library', file: 'reports-views.html', title: 'My Reports & Saved Views', crumbs: 'Reports / My reports',
    context: reportsSavedViewsContext,
  },
  {
    view: 'reports/schedules', file: 'reports-schedules.html', title: 'Scheduled Reports', crumbs: 'Reports / Scheduled reports',
    context: reportsSchedulesContext,
  },
  {
    view: 'reports/alerts', file: 'reports-alerts.html', title: 'KPI Alerts', crumbs: 'Reports / KPI alerts',
    context: reportsAlertsContext,
  },
  {
    view: 'reports/show', file: 'reports-report-date.html', title: 'Trial Balance', crumbs: 'Reports / Trial Balance',
    context: reportsAsOnContext,
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
    actionsRaw = renderer.section(screen.view, 'actions', ctx.scope);
    headRaw = renderer.push(screen.view, 'head', ctx.scope);
    scriptsRaw = renderer.push(screen.view, 'scripts', ctx.scope);
  } catch (error) {
    console.error(`${screen.file}: render failed — ${error.message}`);
    process.exit(1);
  }

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: screen.title,
    crumbs: screen.crumbs,
    actions: indent(actionsRaw, '        '),
    head: headRaw ? `  ${headRaw}\n` : '',
    scripts: scriptsRaw + '\n',
  });

  const out = path.join(ROOT, 'deploy/dev/previews', screen.file);
  const previous = fs.existsSync(out) ? fs.readFileSync(out, 'utf8') : null;
  if (previous === html) {
    console.log(`${screen.file} is up to date`);
  } else if (checkOnly) {
    console.error(`${screen.file} is stale — run node deploy/dev/build-reports-preview.mjs`);
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
  if (log.phpSkipped.length) console.log(`  php skipped ............. ${log.phpSkipped.length}`);
  if (log.errors.length) log.errors.forEach(error => console.error(`  error: ${error}`));
}

console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length}`);
if (failures) process.exit(1);
