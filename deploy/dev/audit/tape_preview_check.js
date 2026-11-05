#!/usr/bin/env node
/*
 * The tape chart is a grid, and a grid fails quietly: a missing day column, a bar in
 * the wrong cell, or a span of one night where there should be five all still look
 * like a chart. Nothing in the view's own code would complain.
 *
 * This renders deploy/dev/previews/tape-chart.html in jsdom and checks the geometry
 * against deploy/dev/preview/tape-sample.mjs: fourteen days, fifteen rooms in five
 * type groups, one bar per stay in the cell where the stay starts, the span equal to
 * the nights, the status colours, the unassigned chips, the housekeeping dots, the
 * out-of-order room and the print rules.
 *
 * Run: node deploy/dev/audit/tape_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const FILE = 'deploy/dev/previews/tape-chart.html';
const dom = new JSDOM(readFileSync(FILE, 'utf8'), { url: 'https://preview.local/frontdesk/tape-chart' });
const doc = dom.window.document;

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};
const $ = (selector) => doc.querySelector(selector);
const $$ = (selector) => [...doc.querySelectorAll(selector)];

/* ── the window ──────────────────────────────────────────────────────────── */
ok('the page is titled Tape Chart', doc.title.startsWith('Tape Chart'), doc.title);
ok('the hero names the window in words',
  /3 Nov 2026 – 16 Nov 2026/.test($('.tape-chart-window')?.textContent ?? ''),
  $('.tape-chart-window')?.textContent?.trim());

const dayHeads = $$('.tape-chart-table thead th:not(.tape-room-heading)');
ok('fourteen day columns', dayHeads.length === 14, `${dayHeads.length} columns`);
ok('the first day column is the window start',
  (dayHeads[0]?.querySelector('time')?.getAttribute('datetime')) === '2026-11-03',
  dayHeads[0]?.querySelector('time')?.getAttribute('datetime'));
ok('the last day column is the day before the exclusive end',
  (dayHeads.at(-1)?.querySelector('time')?.getAttribute('datetime')) === '2026-11-16');
ok('the business date is marked once',
  dayHeads.filter(th => th.classList.contains('tape-today')).length === 1,
  `${dayHeads.filter(th => th.classList.contains('tape-today')).length} marked`);
ok('weekends are shaded', dayHeads.filter(th => th.classList.contains('tape-weekend')).length === 4,
  `${dayHeads.filter(th => th.classList.contains('tape-weekend')).length} weekend columns`);

/* ── the five counts ─────────────────────────────────────────────────────── */
const metrics = $$('.tape-chart-metric').map(node => [
  node.querySelector('b')?.textContent.trim(),
  node.querySelector('span')?.textContent.trim(),
]);
ok('five summary tiles', metrics.length === 5, `${metrics.length} tiles`);
ok('the counts are the sample counts',
  JSON.stringify(metrics) === JSON.stringify([
    ['15', 'Active rooms'], ['10', 'Arrivals in window'], ['5', 'In-house stays in window'],
    ['5', 'Due-outs in window'], ['3', 'Unassigned stays'],
  ]), JSON.stringify(metrics));

/* ── the room rows ───────────────────────────────────────────────────────── */
const rows = $$('.tape-chart-room-row');
ok('one row per active room', rows.length === 15, `${rows.length} rows`);
ok('the rooms are grouped by type', $$('.tape-chart-group').length === 5,
  `${$$('.tape-chart-group').length} groups`);
const groupLabels = $$('.tape-chart-group').map(node => node.textContent.replace(/\s+/g, ' ').trim());
ok('a group header counts its rooms in the plural',
  groupLabels.includes('Deluxe Sea View · 5 rooms'), groupLabels[0]);
ok('a group with one room says "room"',
  groupLabels.some(label => /· 1 room$/.test(label)), groupLabels.at(-1));
ok('every row has fourteen day cells',
  rows.every(row => row.querySelectorAll('.tape-cell').length === 14),
  rows.map(row => row.querySelectorAll('.tape-cell').length).join(','));
ok('every cell can start a booking on or after the business date',
  rows.every(row => [...row.querySelectorAll('.tape-cell')].every((cell, index) =>
    Boolean(cell.querySelector('.tape-new')) === (index >= 2) || row.dataset.roomDisabled === '1')),
  'cells before the business date must not offer a new booking');

/* ── the bars ────────────────────────────────────────────────────────────── */
const bars = $$('.tape-chart-table .tape-bar');
ok('one bar per assigned stay', bars.length === 13, `${bars.length} bars`);
const byClass = (name) => bars.filter(bar => bar.classList.contains(`b-${name}`)).length;
ok('bars are coloured by status',
  byClass('checked_in') === 5 && byClass('reserved') === 6 && byClass('checked_out') === 1 && byClass('tentative') === 1,
  `in-house ${byClass('checked_in')}, reserved ${byClass('reserved')}, out ${byClass('checked_out')}, tentative ${byClass('tentative')}`);
const spanOf = (reservationNo) => bars
  .find(bar => (bar.getAttribute('title') ?? '').startsWith(reservationNo))
  ?.getAttribute('data-span-days');
ok('a five-night stay inside the window spans five columns', spanOf('RES-24131') === '5', spanOf('RES-24131'));
ok('a stay that started before the window spans only what is visible', spanOf('RES-24088') === '4', spanOf('RES-24088'));
ok('a stay clipped by the window start spans what is visible', spanOf('RES-24101') === '3', spanOf('RES-24101'));
ok('no bar is a zero-width stay', bars.every(bar => Number(bar.getAttribute('data-span-days')) >= 1));
ok('every bar names the reservation, the guest and the dates',
  bars.every(bar => /RES-\d+ · .+ · \d\d \w\w\w \d{4} to \d\d \w\w\w \d{4}/.test(bar.getAttribute('title') ?? '')),
  bars[0]?.getAttribute('title'));
ok('a bar starts in the cell of its arrival, not before',
  bars.every(bar => Boolean(bar.closest('.tape-cell'))));

/* ── the unassigned stays ────────────────────────────────────────────────── */
const chips = $$('.tape-chart-chip');
ok('the unassigned stays are listed as chips', chips.length === 3, `${chips.length} chips`);
ok('each chip can be dragged onto a room',
  chips.every(chip => chip.getAttribute('draggable') === 'true' && chip.dataset.stay));
ok('each chip says what it is',
  chips.every(chip => /Unassigned stay RES-\d+ · .+\. Press Space/.test(chip.getAttribute('aria-label') ?? '')),
  chips[0]?.getAttribute('aria-label'));
ok('the unassigned table repeats them with dates',
  $$('.tape-unassigned-table tbody tr').length === 3,
  `${$$('.tape-unassigned-table tbody tr').length} rows`);

/* ── the room's own state ────────────────────────────────────────────────── */
const ooo = rows.find(row => row.dataset.roomNo === '205');
ok('the out-of-order room is marked and cannot receive a stay',
  ooo?.dataset.roomDisabled === '1' && /OOO/.test(ooo?.textContent ?? ''), ooo?.dataset.roomDisabled);
ok('every room shows its housekeeping state',
  rows.every(row => Boolean(row.querySelector('.tape-chart-room-hk'))));
ok('the housekeeping dots use the four states',
  [...new Set($$('.tape-chart-room-row .tape-chart-room-hk').map(dot => [...dot.classList].find(c => c.startsWith('hk-'))))]
    .sort().join(',') === 'hk-clean,hk-dirty,hk-inspected,hk-touch_up',
  [...new Set($$('.tape-chart-room-row .tape-chart-room-hk').map(dot => [...dot.classList].find(c => c.startsWith('hk-'))))].join(','));

/* ── the sheet and the screen reader ─────────────────────────────────────── */
const css = ($('style')?.textContent ?? '') + $$('style').map(node => node.textContent).join(' ');
ok('the chart hides its controls and chips when printed',
  /@media print\{[^}]*\.tape-chart-controls[^}]*display:none/.test(css.replace(/\s+/g, ''))
  || /@media print[\s\S]*?\.tape-chart-controls[\s\S]*?display:none/.test(css),
  'print rules');
ok('the grid is a scrollable region with a name',
  /Room availability chart/.test($('.tape-chart-scroll')?.getAttribute('aria-label') ?? ''));
ok('the drag-and-drop help only appears because the user may move stays',
  /Move \/ assign/.test($('.tape-chart-help')?.textContent ?? ''));
ok('the chart states its colours',
  $$('.tape-chart-legend span').length >= 4, `${$$('.tape-chart-legend span').length} legend entries`);
const nameless = $$('.tape-chart-table a, .tape-chart-table .tape-bar, .tape-chart-chip, .tape-chart-room-row')
  .filter(node => !(node.getAttribute('aria-label') || node.textContent.trim()));
ok('no control in the grid is nameless', nameless.length === 0,
  nameless.slice(0, 3).map(node => node.outerHTML.slice(0, 60)).join(' · '));

/* ── it must look like the application, not a mock-up ────────────────────── */
const text = doc.body.textContent.replace(/\s+/g, ' ');
ok('no preview furniture in the wording',
  !/preview|sample data|illustrative|not real data/i.test(text),
  (text.match(/preview|sample data|illustrative|not real data/i) ?? [])[0]);

/* ── report ──────────────────────────────────────────────────────────────── */
if (failures.length === 0) {
  console.log(`tape preview check: ${passed} assertions passed`);
  process.exit(0);
}
console.error(`tape preview check: ${failures.length} FAILED, ${passed} passed`);
for (const failure of failures) console.error(`  ✗ ${failure}`);
process.exit(1);
