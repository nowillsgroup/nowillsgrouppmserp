#!/usr/bin/env node
/*
 * The revenue dashboard is arithmetic shown as a picture, and arithmetic fails quietly:
 * a bar scaled against the wrong denominator, a totals row that sums the wrong column,
 * a pickup figure that goes missing when the snapshot is older than the night. None of
 * that throws; the screen simply looks right and is not.
 *
 * This renders deploy/dev/previews/revenue-dashboard.html in jsdom and checks it against
 * deploy/dev/preview/revenue-sample.mjs: fourteen nights, one bar per night, the totals
 * row equal to the sum of the rows above it, the stat cards equal to the aggregates, the
 * pickup bars proportional to the largest source, and the empty states staying empty.
 *
 * Run: node deploy/dev/audit/revenue_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const FILE = 'deploy/dev/previews/revenue-dashboard.html';
const dom = new JSDOM(readFileSync(FILE, 'utf8'), { url: 'https://preview.local/revenue' });
const doc = dom.window.document;

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};
const $ = (selector) => doc.querySelector(selector);
const $$ = (selector) => [...doc.querySelectorAll(selector)];
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const money = (value) => `GH₵ ${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/* ── the page and its window ─────────────────────────────────────────────── */
ok('the page is titled Revenue Dashboard', doc.title.startsWith('Revenue Dashboard'), doc.title);
ok('the pricing mode is shown in the actions bar', /Recommend – I publish/.test(text($('.rm-mode'))), text($('.rm-mode')));
ok('the forecast window control offers the four windows',
  $$('#rvdDays option').map(o => o.getAttribute('value')).join(',') === '14,30,60,90',
  $$('#rvdDays option').map(o => o.getAttribute('value')).join(','));
ok('the pricing grid button carries the pending count', /6/.test(text($('.rvd-action'))), text($('.rvd-action')));

/* ── the chart ───────────────────────────────────────────────────────────── */
const bars = $$('.rvd-chart rect.b-otb');
ok('one booked-rooms bar per night', bars.length === 14, `${bars.length} bars`);
ok('every bar sits inside the plot (no negative or overflowing height)',
  bars.every(bar => Number(bar.getAttribute('height')) >= 0 && Number(bar.getAttribute('width')) > 0));
ok('the two weekends in the window are shaded', $$('.rvd-chart rect.wk').length === 4, `${$$('.rvd-chart rect.wk').length} shaded`);
ok('the 100% capacity line is drawn once', $$('.rvd-chart line.cap').length === 1);
ok('the legend names all five marks', $$('.rvd-legend span').length === 5, `${$$('.rvd-legend span').length} marks`);

/* ── the stat cards ──────────────────────────────────────────────────────── */
const stats = $$('.rvd-stats .stat');
ok('four headline figures', stats.length === 4, `${stats.length} cards`);
// The sample's own numbers, spelled the way the screen spells them.
ok('the 7-night occupancy is the first week of the sample, to one decimal', /^62\.6%/.test(text(stats[0])), text(stats[0]));
ok('the 30-day card says the window it actually covers', /Forecast/.test(text(stats[1])), text(stats[1]));
ok('room revenue is money, not a bare number', /^GH₵ /.test(text(stats[2].querySelector('.v'))), text(stats[2].querySelector('.v')));
ok('pickup names the bookings and the nights they are worth', /23 \(61 nts\)/.test(text(stats[3])), text(stats[3]));

/* ── the pickups and the totals ──────────────────────────────────────────── */
const sourceRows = $$('.rvd-source-row');
ok('five booking sources, largest first',
  sourceRows.length === 5 && /Website/.test(text(sourceRows[0])), `${sourceRows.length} sources`);
const widths = sourceRows.map(row => Number((text(row.querySelector('.rvd-source-track span')) || row.querySelector('.rvd-source-track span')?.getAttribute('style') || '').replace(/[^\d.]/g, '')));
ok('the pickup bars are proportional to the largest source',
  widths[0] === 100 && widths.every((w, i) => i === 0 || w <= 100), widths.join(','));
const totalRow = $$('.rvd-table tfoot tr td').map(cell => text(cell));
ok('the totals row sums the booked column',
  totalRow[1] === String(753), totalRow[1]);
ok('the totals row sums the revenue column',
  totalRow[5] === money(584150), totalRow[5]);
ok('the totals row counts the group holds separately from the rooms',
  totalRow[2] === String(32), totalRow[2]);
ok('a pickup total over a partly-snapshotted window is marked, not silently short',
  totalRow[6] === '21*' && totalRow[7] === '74*', `${totalRow[6]} / ${totalRow[7]}`);
ok('and the mark explains itself on the row and in the note',
  /13 of 14 nights/.test($$('.rvd-table tfoot tr td')[6].getAttribute('title') ?? '')
  && /covers only the nights that have a snapshot/.test(text(doc.body)));
ok('a night with no pickup reading shows a dash, not a zero',
  $$('.rvd-table tbody tr td.muted').length >= 1, `${$$('.rvd-table tbody tr td.muted').length} dashes`);

/* ── the table ───────────────────────────────────────────────────────────── */
const bodyRows = $$('.rvd-table tbody tr');
ok('fourteen nights listed', bodyRows.length === 14, `${bodyRows.length} rows`);
ok('the same four nights are marked in the table', $$('.rvd-table tr.rvd-weekend').length === 4, `${$$('.rvd-table tr.rvd-weekend').length} rows`);
ok('the first row is the business date and says so', /today/.test(text(bodyRows[0])), text(bodyRows[0]));
ok('every night offers a pricing action', $$('.rvd-table .rvd-price-action a').length === 14);
ok('a rate of zero is printed as a dash rather than GH₵ 0.00',
  !/GH₵ 0\.00/.test(text($('.rvd-table'))), 'a zero rate is printed as money');

/* ── the honest footnotes ────────────────────────────────────────────────── */
ok('the sheet says how to read the forecast', /On the books/.test(text(doc.body)) && /Forecast/.test(text(doc.body)));
ok('the rule count and the link to the rules are both on the page',
  /4 active yield rule/.test(text(doc.body)) && /Review Pricing Rules/.test(text(doc.body)));
ok('the rebuild action is offered only with its warning', /Rebuild historical pickup snapshots/.test(text(doc.body)));

if (failures.length) {
  console.error(`revenue preview check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`revenue preview check: ${passed}/${passed} passed`);
