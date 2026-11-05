#!/usr/bin/env node
/*
 * deploy/dev/previews/management-dashboard.html is the dashboard as staff see it.
 * It is generated from the real views by deploy/dev/build-dashboard-preview.mjs, so
 * the thing worth checking is that the image still contains the screen:
 *
 *   · every static heading in dashboard.blade.php and its two partials appears;
 *   · every section the dashboard is made of is there (Action centre, Pinned
 *     reports, arrivals, departures, room status, shortcuts, activity,
 *     department snapshots, trends, business mix);
 *   · nothing Blade-shaped leaked through, and no expression failed to render;
 *   · every icon the page asks for exists in the page's own sprite;
 *   · the preview carries no "this is a sample" furniture on the screen.
 *
 * Run: node deploy/dev/audit/dashboard_preview_check.js
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const read = (path) => readFileSync(resolve(ROOT, path), 'utf8');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('dashboard preview check: SKIP (jsdom is not installed)');
  process.exit(0);
}

const failures = [];
let checks = 0;
const check = (label, condition, detail) => {
  checks += 1;
  if (!condition) failures.push(label + (detail ? ' — ' + detail : ''));
};

const PREVIEW = 'deploy/dev/previews/management-dashboard.html';
const VIEWS = [
  'resources/views/dashboard.blade.php',
  'resources/views/dashboard/management-overview.blade.php',
  'resources/views/dashboard/management-trends.blade.php',
];

check('the dashboard preview has been built', existsSync(resolve(ROOT, PREVIEW)), PREVIEW);
const html = read(PREVIEW);
const dom = new JSDOM(html);
const doc = dom.window.document;
const bodyText = doc.body.textContent.replace(/\s+/g, ' ');

/* ── 1. nothing Blade-shaped survived the render ─────────────────────────── */
for (const leak of ['{{', '{!!', '@if', '@endif', '@elseif', '@foreach', '@endforeach', '@forelse', '@php', '@endphp', '@include', '@empty']) {
  check(`no "${leak}" in the rendered page`, !html.includes(leak));
}
for (const bad of ['undefined', 'NaN', '[object Object]']) {
  check(`no ${bad} in the rendered page`, !bodyText.includes(bad));
}

/* ── 2. every static heading in the real views is on the screen ──────────── */
const normalise = (s) => s.replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
const headings = new Set();
for (const file of VIEWS) {
  const src = read(file);
  for (const m of src.matchAll(/<h([123])[^>]*>([\s\S]*?)<\/h\1>/g)) {
    const raw = m[2]
      .replace(/\{\{--[\s\S]*?--\}\}/g, ' ')
      .replace(/\{\{[\s\S]*?\}\}/g, ' ')
      .replace(/@\w+(\([^)]*\))?/g, ' ')
      .replace(/<[^>]+>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    if (raw.length >= 6) headings.add(normalise(raw));
  }
}
check('the views contribute headings to check', headings.size >= 12, `${headings.size} found`);
for (const heading of headings) {
  check(`heading "${heading}" is on the screen`, bodyText.includes(heading));
}

/* ── 3. the sections the dashboard is made of ────────────────────────────── */
const sections = [
  ['Management overview', '.dash-exec-hero'],
  ['KPI tiles', '.dash-exec-kpi'],
  ['Department pulse', '.dash-management-modules'],
  ['Action centre', '#dashAttention'],
  ['KPI alerts', '#dashKpi'],
  ['Pinned reports', '#dashPinned'],
  ['Expected arrivals', '.dash-stay-grid .card:first-child'],
  ['Due departures', '.dash-stay-grid .card:last-child'],
  ['Recent activity', '.dash-activity-card'],
  ['Room status', '.dash-room-card'],
  ['Shortcuts', '.dash-shortcuts-card'],
  ['Department snapshots', '.dash-module-grid'],
  ['Performance trend', '.dash-management-trends'],
  ['Business mix', '.dash-mix-grid'],
];
for (const [label, selector] of sections) {
  check(`${label} is rendered`, Boolean(doc.querySelector(selector)), selector);
}

check('six executive KPI tiles', doc.querySelectorAll('.dash-exec-kpi').length === 6,
  `${doc.querySelectorAll('.dash-exec-kpi').length}`);
check('the action centre lists real work', doc.querySelectorAll('.dash-alert-list li').length >= 20,
  `${doc.querySelectorAll('.dash-alert-list li').length} rows`);
check('the action centre rows carry values', [...doc.querySelectorAll('.dash-alert-list li')]
  .every(li => li.querySelector('b')?.textContent.trim()), 'a row has no number');
check('14 revenue bars', doc.querySelectorAll('.dash-history-chart:nth-of-type(1) .col').length === 14
  || doc.querySelectorAll('.dash-chart-grid .dash-chart-panel:first-child .col').length === 14,
  `${doc.querySelectorAll('.dash-chart-grid .dash-chart-panel:first-child .col').length}`);
check('14 occupancy bars', doc.querySelectorAll('.dash-chart-grid .dash-chart-panel:last-child .col').length === 14,
  `${doc.querySelectorAll('.dash-chart-grid .dash-chart-panel:last-child .col').length}`);
check('three mix charts', doc.querySelectorAll('.dash-mix-card').length === 3,
  `${doc.querySelectorAll('.dash-mix-card').length}`);
check('every mix legend totals 100%', [...doc.querySelectorAll('.dash-mix-card')].every((card) => {
  const pcts = [...card.querySelectorAll('.dash-mix-legend small')]
    .map(el => Number(el.textContent.replace('%', '').trim()))
    .filter(n => Number.isFinite(n));
  const total = pcts.reduce((t, n) => t + n, 0);
  return pcts.length === 0 || Math.abs(total - 100) < 0.6;
}));
check('the shortcut tray is populated', doc.querySelectorAll('.dash-shortcuts-card a').length >= 5,
  `${doc.querySelectorAll('.dash-shortcuts-card a').length}`);
check('the room donut has a gradient', /conic-gradient\(/.test(doc.querySelector('.donut')?.getAttribute('style') ?? ''));

/* ── 4. icons resolve inside the page ────────────────────────────────────── */
const symbols = new Set([...doc.querySelectorAll('symbol[id]')].map(s => s.id));
const uses = [...doc.querySelectorAll('use[href^="#i-"]')].map(u => u.getAttribute('href').slice(1));
const missing = [...new Set(uses.filter(u => !symbols.has(u)))];
check('every icon on the screen exists in the sprite', missing.length === 0, missing.join(', '));

/* ── 5. the screen is the application screen, not a wireframe ───────────── */
check('the sidebar is present', Boolean(doc.querySelector('.sidebar .nav-item')));
check('the top bar is present', Boolean(doc.querySelector('.topbar')));
check('the page head says Dashboard', doc.querySelector('.page-head h1')?.textContent.trim() === 'Dashboard');
for (const word of ['illustrative', 'UI-only preview', 'no records are created', 'static preview']) {
  check(`no "${word}" furniture on the screen`, !bodyText.toLowerCase().includes(word.toLowerCase()));
}
check('the business date is shown', /\d{2} \w{3} \d{4}/.test(doc.querySelector('.bdate')?.textContent ?? ''),
  doc.querySelector('.bdate')?.textContent.trim());

/* ── report ─────────────────────────────────────────────────────────────── */
if (failures.length) {
  console.error(`dashboard preview check: ${failures.length} of ${checks} failed`);
  for (const f of failures) console.error('  ✗ ' + f);
  process.exit(1);
}
console.log(`dashboard preview check: ${checks} assertions passed`);
