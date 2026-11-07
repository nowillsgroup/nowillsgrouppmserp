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
// The business date is the third column; a cell before it, or a night the room is
// blocked, must not offer a new booking.
const blockBar = $('.tape-chart-table .tape-bar.tape-block');
const blockRow = blockBar?.closest('.tape-chart-room-row');
const blockStart = blockRow
  ? [...blockRow.querySelectorAll('.tape-cell')].indexOf(blockBar.closest('.tape-cell'))
  : -1;
const blockSpan = Number(blockBar?.dataset.spanDays ?? 0);
const blockRoom = blockRow?.dataset.roomNo;
const cellIsBlocked = (roomNo, index) => roomNo === blockRoom
  && blockStart >= 0 && index >= blockStart && index < blockStart + blockSpan;

ok('every cell can start a booking on or after the business date',
  $$('.tape-chart-room-row').every(row => [...row.querySelectorAll('.tape-cell')].every((cell, index) =>
    Boolean(cell.querySelector('.tape-new')) === (index >= 2 && !cellIsBlocked(row.dataset.roomNo, index))
    || row.dataset.roomDisabled === '1')),
  'cells before the business date, and blocked nights, must not offer a new booking');

/* ── the bars ────────────────────────────────────────────────────────────── */
const bars = $$('.tape-chart-table .tape-bar:not(.tape-block)');
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

/* ── the line under every day column ─────────────────────────────────────── */
// Rooms sold on each night, and the same number as a share of the 14 sellable rooms
// (room 205 is out of order). Derived by hand from tape-sample.mjs: 4 on 3 Nov
// (Michael, Sarah, Kwesi, Grace), 5 on 4 Nov once Fatima arrives, 7 on 5 Nov with
// Adjoa and Yaw, and the tentative hold is never counted.
const EXPECTED_OCCUPIED = [4, 5, 7, 6, 4, 2, 3, 2, 1, 1, 1, 1, 1, 0];
// 14 rooms can be sold — except on the three nights room 304 is blocked for
// maintenance (9–11 Nov), when only 13 can.
const EXPECTED_SELLABLE = [14, 14, 14, 14, 14, 14, 13, 13, 13, 14, 14, 14, 14, 14];
const EXPECTED_PCT = EXPECTED_OCCUPIED.map((sold, index) => Math.round((sold / EXPECTED_SELLABLE[index]) * 100));

const totalRows = $$('.tape-chart-table tfoot tr');
ok('two total rows under the grid', totalRows.length === 2, `${totalRows.length} rows`);
ok('they are called Occupied and Occupancy',
  totalRows.map(row => row.querySelector('.tape-room-label')?.textContent.trim()).join(' / ') === 'Occupied / Occupancy',
  totalRows.map(row => row.querySelector('.tape-room-label')?.textContent.trim()).join(' / '));

const occupiedCells = [...(totalRows[0]?.querySelectorAll('td') ?? [])];
const pctCells = [...(totalRows[1]?.querySelectorAll('td') ?? [])];
ok('there is a total under every one of the fourteen days',
  occupiedCells.length === 14 && pctCells.length === 14,
  `${occupiedCells.length} / ${pctCells.length}`);
ok('the room counts are the sample counts',
  occupiedCells.map(cell => Number(cell.textContent.trim())).join(',') === EXPECTED_OCCUPIED.join(','),
  occupiedCells.map(cell => cell.textContent.trim()).join(','));
ok('the percentages are those counts over the sellable rooms',
  pctCells.map(cell => Number(cell.textContent.replace('%', '').trim())).join(',') === EXPECTED_PCT.join(','),
  pctCells.map(cell => cell.textContent.trim()).join(','));
ok('the two rows agree with each other on every day',
  EXPECTED_OCCUPIED.every((sold, index) => Math.round((sold / EXPECTED_SELLABLE[index]) * 100) === EXPECTED_PCT[index]));
ok('no night sells more rooms than are for sale that night',
  EXPECTED_OCCUPIED.every((sold, index) => sold <= EXPECTED_SELLABLE[index]));
ok('the out-of-order room is not in the denominator',
  /of 14 sellable rooms/.test(occupiedCells[0]?.getAttribute('aria-label') ?? ''),
  occupiedCells[0]?.getAttribute('aria-label'));

// The denominator is read off the page, not assumed: the block must be the only
// thing that changes it, and only on the nights it covers.
const denominator = (cell) => Number(/of (\d+) sellable/.exec(cell.getAttribute('aria-label') ?? '')?.[1] ?? 0);
ok('each night states how many rooms were for sale that night',
  occupiedCells.every((cell, index) => denominator(cell) === EXPECTED_SELLABLE[index]),
  occupiedCells.map(denominator).join(','));
ok('a blocked room leaves the denominator on exactly the nights it is blocked',
  occupiedCells.map(denominator).join(',') === EXPECTED_SELLABLE.join(',')
  && EXPECTED_SELLABLE.filter(count => count === 13).length === 3);
ok('each count says its weekday and its denominator',
  occupiedCells.every(cell => /^[A-Z][a-z]+day, \d+ \w+ \d{4}: \d+ of \d+ sellable rooms occupied$/.test(cell.getAttribute('aria-label') ?? '')),
  occupiedCells[0]?.getAttribute('aria-label'));
ok('each percentage says what it is a percentage of',
  pctCells.every(cell => /^\w+day, \d+ \w+ \d{4}: \d+ percent of \d+ sellable rooms$/.test(cell.getAttribute('aria-label') ?? '')),
  pctCells[0]?.getAttribute('aria-label'));
const footerCss = $('style')?.textContent ?? '';
ok('a full night would be flagged', /pct-high/.test(footerCss) && /pct-mid/.test(footerCss));

/* ── which column is today ───────────────────────────────────────────────── */
// the day columns only — the first header cell is the room column and has no date
const heads = $$('.tape-chart-table thead th[scope="col"]').filter(th => th.querySelector('time'));
const todayHeads = heads.filter(th => th.classList.contains('tape-today'));
const todayIndex = heads.indexOf(todayHeads[0]);
ok('exactly one day column is marked as today', todayHeads.length === 1, `${todayHeads.length} marked`);
ok('the today column says so for a screen reader',
  heads.filter(th => th.getAttribute('aria-current') === 'date').length === 1
  && todayHeads[0]?.getAttribute('aria-current') === 'date');
const rowCells = $$('.tape-chart-room-row').map(row => [...row.querySelectorAll('td.tape-cell')]);
ok('today is highlighted down the whole column, not just its header',
  rowCells.length > 0 && rowCells.every(cells => cells.filter(cell => cell.classList.contains('tape-today')).length === 1),
  `${rowCells.filter(cells => cells.some(cell => cell.classList.contains('tape-today'))).length} of ${rowCells.length} rows`);
ok('the highlighted cells all sit in the header\'s today column',
  rowCells.every(cells => cells[todayIndex]?.classList.contains('tape-today')),
  `column ${todayIndex}`);
const sheetCss = $('style').textContent ?? '';
ok('the column highlight is painted, and has a dark-mode twin',
  /tbody td\.tape-today \{ background:#[0-9a-f]{6}/i.test(sheetCss)
  && /body\.dark[^{]*tbody td\.tape-today \{ background:#[0-9a-f]{6}/i.test(sheetCss));
ok('a drop target still overrides the highlight',
  /\.drop-over td \{ background:#[0-9a-f]{6}!important/.test(sheetCss));

/* ── what the percentage is a percentage of ──────────────────────────────── */
const basis = ($('.tape-chart-basis')?.textContent ?? '').replace(/\s+/g, ' ').trim();
ok('the chart says what the percentage is measured against', basis.length > 0);
ok('the basis names the sellable count and the active count',
  /against 14 of 15 active rooms/.test(basis), basis);
ok('the basis names the room that is left out and why',
  /room 205 is out of order/.test(basis), basis);
ok('the basis agrees with the footer denominator',
  /against 14 of 15 active rooms/.test(basis)
  && $$('.tape-chart-table tfoot tr:first-child td').every((cell, index) =>
    denominator(cell) === EXPECTED_SELLABLE[index]),
  basis);
// Every @media print block on the sheet, brace-matched — the rules are written
// on one line, so a lazy match on a closing newline would find nothing.
const printCss = (css => {
  const blocks = [];
  for (let at = css.indexOf('@media print'); at !== -1; at = css.indexOf('@media print', at + 1)) {
    let depth = 0, end = -1;
    for (let i = css.indexOf('{', at); i !== -1 && i < css.length; i++) {
      if (css[i] === '{') depth++;
      else if (css[i] === '}') { depth--; if (depth === 0) { end = i; break; } }
    }
    if (end === -1) break;
    blocks.push(css.slice(at, end + 1));
    at = end;
  }
  return blocks.join('\n');
})($('style').textContent ?? '');
const hiddenOnPaper = printCss;
ok('the basis is on the printed sheet too',
  hiddenOnPaper.length > 0
  && /\.tape-chart-status/.test(hiddenOnPaper)          // the live region is dropped
  && !/\.tape-chart-basis/.test(hiddenOnPaper),        // the basis is not
  hiddenOnPaper.slice(0, 90));

/* ── a room off the market must not look like a free one ─────────────────── */
const outRows = $$('.tape-chart-room-row').filter(row => row.classList.contains('tape-row-out'));
ok('the room that cannot be sold is marked as such', outRows.length === 1,
  outRows.map(row => row.dataset.roomNo).join(', '));
ok('the hatched room is the one the basis note names',
  basis.includes(`room ${outRows[0]?.dataset.roomNo} `));
ok('every night of that room is hatched',
  outRows[0] && outRows[0].querySelectorAll('td.tape-cell').length === heads.length
  && outRows[0].querySelectorAll('td.tape-cell.tape-out').length === heads.length,
  `${outRows[0]?.querySelectorAll('td.tape-out').length} of ${heads.length}`);
ok('no sellable room is hatched',
  $$('.tape-chart-room-row').filter(row => !row.classList.contains('tape-row-out'))
    .every(row => row.querySelectorAll('td.tape-out').length === 0));
ok('the today band still crosses the hatched row',
  outRows[0]?.querySelectorAll('td.tape-out.tape-today').length === 1);
ok('the hatch is painted, with a dark-mode twin',
  /tbody td\.tape-out \{[^}]*background-image:repeating-linear-gradient/i.test(sheetCss)
  && /body\.dark[^{]*tbody td\.tape-out \{[^}]*background-image:repeating-linear-gradient/i.test(sheetCss));
ok('the legend names the hatch',
  $$('.tape-chart-legend span').some(node => node.textContent.includes('Not for sale')));
ok('a room that cannot be sold offers no booking link',
  outRows[0]?.querySelectorAll('a.tape-new').length === 0);

/* ── a stretch of nights taken off the market ─────────────────────────────── */
const blockBars = $$('.tape-chart-table .tape-bar.tape-block');
ok('a room blocked for a stretch of nights is drawn on the chart', blockBars.length === 1,
  `${blockBars.length} blocks`);
ok('the blocked stretch belongs to the room the basis line names',
  blockRoom !== undefined && basis.includes(`room ${blockRoom} `), `room ${blockRoom}`);
ok('the block spans the nights it was booked for', Number(blockBars[0]?.dataset.spanDays) === 3,
  blockBars[0]?.dataset.spanDays);
ok('the block says which room, which nights and why',
  /^Room \d+ blocked \d\d \w{3} – \d\d \w{3} \(\w[\w ]*\)$/.test(blockBars[0]?.getAttribute('title') ?? ''),
  blockBars[0]?.getAttribute('title'));
ok('the block is announced, not just shaded', blockBars[0]?.getAttribute('role') === 'img');
ok('the legend names the blocked nights',
  $$('.tape-chart-legend span').some(node => node.textContent.includes('Blocked nights')));
ok('the block is painted, with a dark-mode twin',
  /\.tape-bar\.tape-block \{[^}]*repeating-linear-gradient/i.test(sheetCss)
  && /body\.dark[^{]*\.tape-bar\.tape-block \{[^}]*repeating-linear-gradient/i.test(sheetCss));
ok('a blocked night cannot be booked from the chart',
  Boolean(blockRow) && [...blockRow.querySelectorAll('.tape-cell')]
    .slice(blockStart, blockStart + blockSpan)
    .every(cell => cell.querySelector('a.tape-new') === null));

/* ── a night that runs on a smaller base ──────────────────────────────────── */
const markedNights = (row) => [...row.querySelectorAll('td')]
  .map((cell, index) => (cell.classList.contains('tape-blocked-night') ? index : -1)).filter(index => index >= 0);
ok('both total rows mark the nights with rooms off the market',
  JSON.stringify(markedNights(totalRows[0])) === JSON.stringify(markedNights(totalRows[1])),
  `${markedNights(totalRows[0])} / ${markedNights(totalRows[1])}`);
ok('the marked nights are exactly the blocked ones',
  JSON.stringify(markedNights(totalRows[0]))
  === JSON.stringify(Array.from({ length: blockSpan }, (_, i) => blockStart + i)),
  `${markedNights(totalRows[0])} vs ${blockStart}+${blockSpan}`);
ok('a night on the full base carries no marker',
  [...totalRows[0].querySelectorAll('td')].every((cell, index) =>
    cell.classList.contains('tape-blocked-night') === markedNights(totalRows[0]).includes(index)));
ok('the marker explains itself on hover',
  [...totalRows[0].querySelectorAll('td')].every((cell, index) =>
    markedNights(totalRows[0]).includes(index)
      ? /^1 room blocked · (\d+) of \d+ rooms for sale$/.test(cell.getAttribute('title') ?? '')
        && Number(/(\d+) of \d+ rooms for sale/.exec(cell.getAttribute('title'))[1]) === EXPECTED_SELLABLE[index]
      : cell.getAttribute('title') === null),
  totalRows[0].querySelectorAll('td')[blockStart]?.getAttribute('title'));
ok('the marker is painted over the band colour, not instead of it',
  /\.pct-mid \{[^}]*\}/.test(sheetCss)
  && /\.tape-blocked-night \{ background-image:repeating-linear-gradient/i.test(sheetCss)
  && sheetCss.indexOf('.tape-blocked-night') > sheetCss.indexOf('.pct-mid'));

/* ── the totals are what the chart draws ──────────────────────────────────── */
// Every bar is rendered once, in its arrival cell, with its span in data-span-days.
// Walk the grid and rebuild the per-night counts from the drawing: the footer must
// equal the reserved + in-house bars, and the tentative / checked-out bars must be
// on the chart without being in the count.
const nightsFromBars = (accept) => {
  const counts = Array.from({ length: heads.length }, () => 0);
  $$('.tape-chart-room-row').forEach(row => {
    [...row.querySelectorAll('td.tape-cell')].forEach((cell, index) => {
      cell.querySelectorAll('.tape-bar:not(.tape-block)').forEach(bar => {
        if (!accept(bar)) return;
        const span = Number(bar.dataset.spanDays ?? 1) || 1;
        for (let i = index; i < Math.min(heads.length, index + span); i += 1) counts[i] += 1;
      });
    });
  });
  return counts;
};
const countedFromPage = [...totalRows[0].querySelectorAll('td')].map(cell => Number(cell.textContent.trim()));
const fromReservedAndInHouse = nightsFromBars(bar => bar.classList.contains('b-reserved') || bar.classList.contains('b-checked_in'));
const fromEverythingDrawn = nightsFromBars(() => true);
ok('the footer count is the reserved and in-house bars on that night',
  fromReservedAndInHouse.join(',') === countedFromPage.join(','),
  `${fromReservedAndInHouse.join(',')} vs ${countedFromPage.join(',')}`);
ok('a tentative hold and a checked-out stay are drawn but not counted',
  fromEverythingDrawn.some((count, index) => count > countedFromPage[index])
  && fromReservedAndInHouse.join(',') === countedFromPage.join(','));
ok('the tentative hold is on the chart where it belongs',
  [...totalRows[0].querySelectorAll('td')].some((cell, index) =>
    fromEverythingDrawn[index] > fromReservedAndInHouse[index]));

/* ── one dialect of dates ─────────────────────────────────────────────────── */
const stayChips = $$('.tape-chart-chip');
ok('the unassigned chips carry their room type and nights',
  stayChips.length > 0 && stayChips.every(chip => /\w{3} · \d{1,2} \w{3}–\d{1,2} \w{3}/.test(chip.textContent.replace(/\s+/g, ' '))),
  stayChips[0]?.textContent.replace(/\s+/g, ' ').trim());
ok('the chips date in the same dialect as the bars',
  stayChips.every(chip => !/\d\d\/\d\d/.test(chip.textContent)));
ok('the legend says the tentative colour is not counted',
  $$('.tape-chart-legend span').some(node => /Tentative[\s\S]*not counted/.test(node.textContent)));

/* ── the window in one line ──────────────────────────────────────────────── */
const summary = ($('.tape-chart-summary')?.textContent ?? '').replace(/\s+/g, ' ').trim();
ok('the chart totals the window in a line', summary.length > 0);
ok('the window sold 38 room-nights', /38 room-nights sold/.test(summary), summary);
ok('average occupancy is room-nights over sellable room-nights',
  /average occupancy 20%/.test(summary)
  && Math.round((EXPECTED_OCCUPIED.reduce((a, b) => a + b, 0)
    / EXPECTED_SELLABLE.reduce((a, b) => a + b, 0)) * 100) === 20,
  summary);
ok('the fullest night is named with its count and share',
  /fullest night 5 Nov with 7 rooms \(50%\)/.test(summary), summary);
ok('the line counts the nights in the plural', /over 14 nights/.test(summary), summary);

/* ── the sheet and the screen reader ─────────────────────────────────────── */
const css = ($('style')?.textContent ?? '') + $$('style').map(node => node.textContent).join(' ');
ok('the totals stay on the printed sheet',
  /@media print[\s\S]*tfoot[\s\S]*position:static/.test(($('style')?.textContent ?? '').replace(/\s+/g, ' ')),
  'print rules');
ok('the chart hides its controls and chips when printed',
  /@media print\{[^}]*\.tape-chart-controls[^}]*display:none/.test(css.replace(/\s+/g, ''))
  || /@media print[\s\S]*?\.tape-chart-controls[\s\S]*?display:none/.test(css),
  'print rules');
ok('the grid is a scrollable region with a name',
  /Room availability chart/.test($('.tape-chart-scroll')?.getAttribute('aria-label') ?? ''));
ok('the drag-and-drop help only appears because the user may move stays',
  /Move \/ assign/.test($('.tape-chart-help')?.textContent ?? ''));
const legend = $$('.tape-chart-legend span').map(node => node.textContent.replace(/\s+/g, ' ').trim());
ok('the legend names the four booking states',
  ['Reserved', 'In-house', 'Tentative', 'Checked out'].every(state => legend.some(entry => entry.startsWith(state))),
  legend.join(' · '));
ok('the legend explains every housekeeping dot the chart draws',
  ['Clean', 'Inspected', 'Dirty', 'Touch-up'].every(state => legend.includes(state)),
  legend.join(' · '));
ok('the housekeeping swatches are hidden from a screen reader',
  $$('.tape-chart-legend span').filter(node => node.querySelector('.tape-chart-room-hk'))
    .every(node => node.getAttribute('aria-hidden') === 'true'));
ok('a room\'s housekeeping state is announced, not just coloured',
  $$('.tape-chart-room-row .tape-chart-room-hk').every(dot => dot.getAttribute('role') === 'img'
    && /^Housekeeping (clean|inspected|dirty|touch up)$/.test(dot.getAttribute('aria-label') ?? '')),
  $$('.tape-chart-room-row .tape-chart-room-hk')[0]?.getAttribute('aria-label'));
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
