#!/usr/bin/env node
/*
 * The pricing grid is the one screen where a wrong number becomes money: a recommendation
 * published on a protected date, a fixed contract rate overwritten, a floor not applied,
 * or an editable box on a night the grid promised to leave alone.
 *
 * This renders deploy/dev/previews/revenue-pricing.html in jsdom and checks it against
 * deploy/dev/preview/pricing-sample.mjs: seven nights across five room types, the fixed
 * and stop-sell cells that must not carry an input, the guard-rails printed on each room
 * row, the rounding step the settings supply, the four summary figures, the legend, and
 * the reset form's range.
 *
 * Run: node deploy/dev/audit/revenue_pricing_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const FILE = 'deploy/dev/previews/revenue-pricing.html';
const dom = new JSDOM(readFileSync(FILE, 'utf8'), { url: 'https://preview.local/revenue/pricing' });
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

/* ── the screen and its window ───────────────────────────────────────────── */
ok('the page is titled for the pricing grid', doc.title.startsWith('Revenue Pricing Grid'), doc.title);
ok('the mode is shown, not implied', /Recommend – I publish/.test(text($('.rm-mode'))), text($('.rm-mode')));
ok('the window control offers 7, 14, 21 and 28 nights',
  $$('select[name=days] option').map(option => option.value).join(',') === '7,14,21,28',
  $$('select[name=days] option').map(option => option.value).join(','));
ok('the grid says which window it covers in words', /5 Nov – 11 Nov 2026/.test(text(doc.body)), 'window line missing');

/* ── the columns ─────────────────────────────────────────────────────────── */
const heads = $$('.rmp-grid thead th');
ok('one room-type column plus seven nights', heads.length === 8, `${heads.length} columns`);
ok('the two weekend nights of the window are marked', $$('.rmp-grid thead th.rmp-weekend').length === 2,
  `${$$('.rmp-grid thead th.rmp-weekend').length} marked`);
ok('the business date is marked once as Today', $$('.rmp-grid thead th.rmp-today').length === 1
  && /Today/.test(text($('.rmp-grid thead th.rmp-today'))));

/* ── the occupancy row ───────────────────────────────────────────────────── */
const occupancyCells = $$('.rmp-occ-row td');
ok('occupancy is printed for all seven nights', occupancyCells.length === 7, `${occupancyCells.length} cells`);
ok('each occupancy cell carries the sold-of-capacity detail behind the percentage',
  text(occupancyCells[0]) === '68%65/96', text(occupancyCells[0]));

/* ── the room rows and their guard-rails ─────────────────────────────────── */
const rows = $$('.rmp-grid tbody tr');
ok('five room types below the occupancy row', rows.length === 6, `${rows.length} rows`);
ok('each room row prints its base rate',
  rows.slice(1).every(row => row.querySelector('.rmp-room-base')), 'a room row has no base rate');
ok('the guard-rails are printed, both ends', /Floor 480 · ceiling 900/.test(text(doc.body)), 'floor/ceiling missing');
ok('a room type with no floor prints a dash, and one with no ceiling prints a dash too',
  /Floor — · ceiling 1,800/.test(text(doc.body)) && /Floor 420 · ceiling —/.test(text(doc.body)), 'missing-floor label');

/* ── the cells ───────────────────────────────────────────────────────────── */
const cells = $$('td.rmp-cell');
ok('thirty-five cells: five types over seven nights', cells.length === 35, `${cells.length} cells`);
ok('only the protecting cells are without an input',
  $$('input.rmp-price').length === 33, `${$$('input.rmp-price').length} inputs`);
ok('the two contract-fixed nights carry no editable price', $$('.rmp-cell.fixed').length === 2
  && $$('.rmp-cell.fixed input').length === 0, `${$$('.rmp-cell.fixed input').length} inputs on fixed cells`);
ok('the fixed cell states that it is fixed', /Fixed/.test(text($('.rmp-cell.fixed'))), text($('.rmp-cell.fixed')));
ok('the stop-sell night is labelled Closed', $$('.rmp-cell.stop').length === 1
  && /Closed/.test(text($('.rmp-cell.stop'))), text($('.rmp-cell.stop')));
ok('the cells that recommend a raise and a cut are distinguished',
  $$('.rmp-cell.up').length === 4 && $$('.rmp-cell.down').length === 5,
  `${$$('.rmp-cell.up').length} up / ${$$('.rmp-cell.down').length} down`);
ok('a cell with no change says so rather than leaving a blank', /No change/.test(text(doc.body)));
ok('a minimum-stay rule is shown on the cell it applies to',
  $$('.rmp-tag.min').length === 1 && /Min 2 nts/.test(text(doc.body)), `${$$('.rmp-tag.min').length} tags`);
ok('a live revenue-managed rate is marked RM', $$('.rm-tag').length === 2, `${$$('.rm-tag').length} marks`);
ok('rooms left are shown where the night is nearly full', /left/.test(text($('.rmp-cell'))), text($('.rmp-cell')));

/* ── the tooltip is the audit trail ──────────────────────────────────────── */
const tip = $('.rmp-cell').getAttribute('title') ?? '';
ok('a cell explains the date, the lead time and both occupancies',
  /Thursday 5 November 2026/.test(tip) && /lead 0 days/.test(tip) && /hotel occupancy 68%/.test(tip), tip.slice(0, 80));
ok('and ends with the recommendation beside the live rate', /Recommended GH₵/.test(tip) && /live GH₵/.test(tip), tip.slice(-90));
ok('a cell where a rule fired names the rule and its size', /High occupancy|\+12%|-8%/.test(
  $$('.rmp-cell').map(cell => cell.getAttribute('title') ?? '').join(' ')), 'no rule named in any tooltip');

/* ── the four figures ────────────────────────────────────────────────────── */
const kpis = $$('.rmp-kpi').map(text);
ok('nine recommendations are waiting', /^Recommendations to review9/.test(kpis[0] ?? ''), kpis[0]);
ok('average occupancy is a percentage, not a blank', /^Average hotel occupancy61%/.test(kpis[1] ?? ''), kpis[1]);
ok('two fixed-price cells and one stop-sell cell are counted',
  /^Fixed-price cells2/.test(kpis[2] ?? '') && /^Stop-sell cells1/.test(kpis[3] ?? ''), `${kpis[2]} | ${kpis[3]}`);

/* ── the settings show up here ───────────────────────────────────────────── */
ok('the rounding step is printed from the setting, not typed into the view',
  /rounded to the nearest 50/.test(text(doc.body)), 'rounding step missing');
ok('the legend names every mark the grid uses', $$('.rmp-legend span').length === 6,
  `${$$('.rmp-legend span').length} legend items`);

/* ── the reset form and what it refuses ──────────────────────────────────── */
const dates = $$('.rmp-reset-form input[type=date]').map(input => input.value);
ok('the reset form starts at the window and ends inclusive', dates.join(' → ') === '2026-11-05 → 2026-11-11', dates.join(' → '));
ok('and says that seasonal and fixed rates survive a reset',
  /Seasonal and fixed rates from the Rate Calendar are not removed/.test(text(doc.body)));

/* ── nothing left unrendered ─────────────────────────────────────────────── */
// the inline script mentions Number.isNaN; the page's own text is what has to be clean
const bodyCopy = doc.body.cloneNode(true);
bodyCopy.querySelectorAll('script, style').forEach(node => node.remove());
const bodyText = text(bodyCopy);
ok('no Blade directive survived the render', !/@(if|foreach|endif|endforeach|php)\b/.test(bodyText));
ok('no undefined leaked into a cell', !/undefined|NaN/.test(bodyText));

if (failures.length) {
  console.error(`revenue pricing check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`revenue pricing check: ${passed}/${passed} passed`);
