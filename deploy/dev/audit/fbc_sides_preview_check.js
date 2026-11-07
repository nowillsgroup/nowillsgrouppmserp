#!/usr/bin/env node
/*
 * The side-dish costing screen, held to its own arithmetic.
 *
 * The screen exists because a side dish is priced and costed on its own, so the check reads the built page the way a
 * cost controller does: does every row's gross profit equal its revenue less its cost, do the rows add up to the total
 * the header claims, and does the free side dish show the loss it really is?
 *
 * Run: node deploy/dev/audit/fbc_sides_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const read = path => readFileSync(resolve(ROOT, path), 'utf8');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('F&B side dishes preview: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) {
    passed += 1;
    return;
  }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const html = read('deploy/dev/previews/fbc-sides.html');
const dom = new JSDOM(html, { url: 'https://preview.local/fbc/side-dishes' });
const doc = dom.window.document;

const money = text => Number(String(text).replace(/[^0-9.-]/g, ''));
const rows = [...doc.querySelectorAll('#fbcSides tbody tr')];
const cell = (row, i) => row.querySelectorAll('td')[i].textContent.trim();
const footer = [...doc.querySelectorAll('#fbcSides tfoot td')].map(td => td.textContent.trim());
const stats = [...doc.querySelectorAll('#fbcSideStats .stat')].map(stat => ({
  value: stat.querySelector('.v').textContent.trim(),
  label: stat.querySelector('.l').textContent.trim(),
}));
const stat = label => stats.find(s => s.label === label);

// ── the screen ─────────────────────────────────────────────────────────────
ok('the screen is the side-dish report', doc.title.includes('Side dishes sold'));
ok('the period is on the screen', doc.body.textContent.includes('2026-10-10 to 2026-11-06'));
ok('the sidebar carries the new screen', [...doc.querySelectorAll('nav a, .nav-link-item a')].some(a => a.textContent.includes('Side Dishes Sold')));
ok('six side dishes are listed', rows.length === 6, `${rows.length} rows`);

// ── the header ─────────────────────────────────────────────────────────────
ok('portions sold', stat('Side dishes sold')?.value === '720', stat('Side dishes sold')?.value);
ok('revenue', stat('Side dish revenue')?.value === 'GH₵ 8,532.00', stat('Side dish revenue')?.value);
ok('cost', stat('Side dish cost')?.value === 'GH₵ 3,517.35', stat('Side dish cost')?.value);
ok('gross profit', stat('Gross profit')?.value === 'GH₵ 5,014.65', stat('Gross profit')?.value);
ok('margin', stats.find(s => s.label === 'Gross profit')?.value && doc.body.textContent.includes('58.8% margin'));

// ── the rows ───────────────────────────────────────────────────────────────
ok('the best earner comes first', cell(rows[0], 0) === 'French fries');
ok('it sold 214 portions', cell(rows[0], 1) === '214');
ok('its revenue is its rate times its portions', money(cell(rows[0], 2)) === 5350);
ok('its costs are its unit cost times its portions', money(cell(rows[0], 4)) === 7.5 && money(cell(rows[0], 5)) === 1605);
ok('its gross profit is the difference', money(cell(rows[0], 6)) === 3745);

const free = rows.find(row => cell(row, 0) === 'Garden salad');
ok('the free side dish is on the screen', !!free);
ok('it earned nothing', money(cell(free, 2)) === 0);
ok('and still cost money', money(cell(free, 5)) === 1041.6);
ok('so it shows a loss, not a blank', money(cell(free, 6)) === -1041.6, cell(free, 6));
ok('and no margin is invented for it', cell(free, 7) === '—');

// ── a side dish sold with no cost ──────────────────────────────────────────
const gap = rows.find(row => cell(row, 0).startsWith('Kelewele'));
ok('a side dish with no cost is on the screen', !!gap);
ok('it shows the revenue it earned', money(cell(gap, 2)) === 380);
ok('no cost behind it', money(cell(gap, 5)) === 0);
ok('so its margin is a guess', cell(gap, 7) === '100%');
ok('the row says why', gap.textContent.includes('no cost'));
ok('the screen raises it before the numbers', !!doc.querySelector('#fbcSidesNoCost'));
ok('and names the dish', doc.querySelector('#fbcSidesNoCost').textContent.includes('Kelewele bites'));
ok('with the revenue it stands for', doc.querySelector('#fbcSidesNoCost').textContent.includes('GH₵ 380.00'));
ok('and points at the menu master', [...doc.querySelectorAll('#fbcSidesNoCost a')].some(a => a.textContent.includes('menu master')));
ok('the footnote says what a missing cost costs the reader',
  doc.body.textContent.includes('shows a 100 % margin that is not earned'));

// ── the arithmetic, row by row ─────────────────────────────────────────────
const each = rows.map(row => ({ name: cell(row, 0), revenue: money(cell(row, 2)), cost: money(cell(row, 5)), gp: money(cell(row, 6)) }));
ok('every row\'s gross profit is revenue less cost',
  each.every(r => Math.abs(r.revenue - r.cost - r.gp) < 0.005),
  JSON.stringify(each.filter(r => Math.abs(r.revenue - r.cost - r.gp) >= 0.005)));

const totals = {
  units: Number(footer[1].replace(/[^0-9.]/g, '')),
  revenue: money(footer[2]),
  cost: money(footer[5]),
  gp: money(footer[6]),
};
const portions = rows.reduce((sum, row) => sum + Number(cell(row, 1).replace(/[^0-9.]/g, '')), 0);
ok('the rows add up to the portions claimed', Math.abs(portions - totals.units) < 0.0005 && totals.units === 720, `${portions} vs ${totals.units}`);
ok('the rows add up to the revenue claimed', Math.abs(totals.revenue - 8532) < 0.005, String(totals.revenue));
ok('the rows add up to the cost claimed', Math.abs(totals.cost - 3517.35) < 0.005, String(totals.cost));
ok('the rows add up to the gross profit claimed', Math.abs(totals.gp - 5014.65) < 0.005, String(totals.gp));
ok('the footer agrees with the header', stat('Side dish revenue')?.value === footer[2] && stat('Side dish cost')?.value === footer[5]);

// ── what the screen says about itself ──────────────────────────────────────
ok('the screen explains that cost is the cost at the time of sale', doc.body.textContent.includes('at the moment of sale'));
ok('no stale-cost note is shown when every line carries its cost', !doc.body.textContent.includes('bills written before the side cost'));
ok('the menu master is one link away', [...doc.querySelectorAll('a')].some(a => a.textContent.includes('side dishes and their costs')));
ok('the menu-engineering screen is one link away', [...doc.querySelectorAll('a')].some(a => a.textContent.includes('Menu engineering')));
ok('the cost statement is one link away', [...doc.querySelectorAll('a')].some(a => a.textContent.includes('Cost statement')));

console.log(`F&B side dishes preview: ${passed} assertion(s) passed${failures.length ? `, ${failures.length} failed` : ''}`);
if (failures.length) {
  failures.forEach(failure => console.log(`  ✗ ${failure}`));
  process.exit(1);
}
