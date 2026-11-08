#!/usr/bin/env node
/**
 * Build the Settings › Configuration previews as the real application screens.
 *
 * The register is the one file that has to be complete: every module, every setting
 * and where each value is changed. The previews therefore render the real
 * resources/views/configuration/*.blade.php against deploy/dev/preview/configuration-sample.mjs,
 * which in turn reads config/settings.php — so the screen cannot show a module list the
 * application does not ship.
 *
 *   configuration.html          the hub: every module grouped, with how many of its
 *                              settings are set and what changed last
 *   configuration-search.html   the same hub answering a search ("valet"), which is how
 *                              a manager finds a setting they cannot place
 *   configuration-module.html   one module in full — the banquet pack's settings, each
 *                              with its key, its value and whether it is on the default
 *   configuration-module-findings.html  a module whose settings contradict something —
 *                              the payments screen, with the finding that sends you there
 *   rentals-settings.html     Car Rental › Settings — the rate card the return desk applies,
 *                              which used to be constants inside RentalService
 *   hr-settings.html          Human Resources › Settings — the leave answer window and
 *                              whether leave is counted in working or calendar days
 *   configuration-register.html the printed register: every module, every key, on paper
 *
 * Run: node deploy/dev/build-configuration-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import {
  configurationIndexContext, configurationSearchContext,
  configurationShowContext, configurationPrintContext, rentalSettingsContext, hrSettingsContext,
  configurationModuleFindingsContext,
  configurationChangedContext, configurationOnNullContext,
  configurationHistoryContext, configurationHandoverContext,
} from './preview/configuration-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const { navHtml } = nav();
const sprite = spriteSymbols();

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const screens = [
  { view: 'configuration/index', file: 'configuration.html', title: 'Configuration',
    crumbs: 'Administration / Configuration', context: configurationIndexContext },
  { view: 'configuration/index', file: 'configuration-search.html', title: 'Configuration',
    crumbs: 'Administration / Configuration', context: configurationSearchContext },
  { view: 'configuration/show', file: 'configuration-module.html', title: 'Banquet & events — configuration',
    crumbs: 'Administration / Configuration / Banquet & events', context: configurationShowContext },
  { view: 'configuration/show', file: 'configuration-module-findings.html', title: 'Online payments — configuration',
    crumbs: 'Administration / Configuration / Online payments', context: configurationModuleFindingsContext },
  { view: 'configuration/changed', file: 'configuration-changed.html', title: 'Configuration — changed here',
    crumbs: 'Administration / Configuration / Changed here', context: configurationChangedContext },
  { view: 'configuration/on-null', file: 'configuration-on-null.html', title: 'Configuration — what an unset setting runs on',
    crumbs: 'Administration / Configuration / Unset', context: configurationOnNullContext },
  { view: 'configuration/history', file: 'configuration-history.html', title: 'Configuration — what it was before',
    crumbs: 'Administration / Configuration / Value trail', context: configurationHistoryContext },
  { view: 'rentals/settings', file: 'rentals-settings.html', title: 'Car Rental Settings',
    crumbs: 'Car Rental / Settings', context: rentalSettingsContext },
  { view: 'hr/settings', file: 'hr-settings.html', title: 'HR Settings',
    crumbs: 'People / Human resources / Settings', context: hrSettingsContext },
  { view: 'configuration/handover', file: 'configuration-handover.html', title: 'Configuration handover',
    crumbs: 'Administration / Configuration / Handover', context: configurationHandoverContext, raw: true },
  { view: 'configuration/print', file: 'configuration-register.html', title: 'Configuration register',
    crumbs: 'Administration / Configuration / Register', context: configurationPrintContext, raw: true },
];

let failures = 0;

for (const screen of screens) {
  const log = { expressions: 0, fallbacks: 0, failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [] };
  const ctx = screen.context();
  const outPath = path.join(ROOT, 'deploy/dev/previews', screen.file);
  const before = fs.existsSync(outPath) ? fs.readFileSync(outPath, 'utf8') : null;

  let html;
  try {
    if (screen.raw) {
      // A printed document: configuration/print extends layouts.print, so the preview
      // renders its content section and wraps it in the same page the print layout
      // produces (hotel head, document title, meta line, print button).
      const renderer = new BladePreview({ root: ROOT, ctx, log });
      const content = renderer.section(screen.view, 'content', ctx.scope);
      const take = (name) => { try { return renderer.section(screen.view, name, ctx.scope); } catch { return ''; } };
      html = `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>${screen.title} · The Grove Essipong Beach Resort</title>
<link rel="stylesheet" href="/css/app.css">
<style>body{background:#eef1f6}.print-head>div{min-width:0}.print-head .small{overflow-wrap:anywhere}</style>
</head><body>
<div class="print-bar no-print"><button class="btn btn-primary" onclick="window.print()">Print</button></div>
<div class="print-page">
  <div class="print-head">
    <div><h2>The Grove Essipong Beach Resort</h2>
      <div class="small text-2">Beach Road, Essipong, Takoradi, Western Region</div>
      <div class="small text-2">Ph: +233 31 202 4471 · reservations@groveessipong.com</div>
      <div class="small"><b>TIN:</b> C0012345678</div>
    </div>
    <div class="right"><h2 style="color:#166534">${(take('doctitle') || screen.title).trim()}</h2>${take('docmeta').trim()}</div>
  </div>
${content}
</div>
</body></html>`;
    } else {
      const renderer = new BladePreview({ root: ROOT, ctx, log });
      const content = renderer.section(screen.view, 'content', ctx.scope);
      const actions = renderer.section(screen.view, 'actions', ctx.scope);
      const head = renderer.push(screen.view, 'head', ctx.scope);
      const scripts = renderer.push(screen.view, 'scripts', ctx.scope);
      html = shell(navHtml, sprite, indent(content), {
        title: screen.title, crumbs: screen.crumbs, actions: indent(actions, '        '),
        head: head ? `  ${head}\n` : '', scripts: scripts + '\n',
      });
    }
  } catch (error) {
    console.error(`${screen.file}: render failed — ${error.message}`);
    process.exit(1);
  }

  if (log.failed.length) {
    failures += 1;
    console.log(`${screen.file}: ${log.failed.length} unresolved expression(s)`);
    for (const item of log.failed.slice(0, 12)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
  }
  if (log.unknownComponents.length || log.unknownDirectives.length) {
    failures += 1;
    console.log(`${screen.file}: unknown ${[...log.unknownComponents, ...log.unknownDirectives].join(', ')}`);
  }

  // Blade conditionals may leave trailing spaces; keep every generated page clean.
  html = html.replace(/[ \t]+$/gm, '');
  if (before === html) console.log(`${screen.file} is up to date`);
  else if (checkOnly) { console.error(`${screen.file} is stale — run node deploy/dev/build-configuration-preview.mjs`); failures += 1; }
  else { fs.writeFileSync(outPath, html); console.log(`wrote deploy/dev/previews/${screen.file} (${html.length} bytes)`); }
}

process.exit(failures ? 1 : 0);
