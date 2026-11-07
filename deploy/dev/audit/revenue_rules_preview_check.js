#!/usr/bin/env node
/*
 * The pricing rules screen is where a manager decides what the yield engine is allowed to do:
 * which nights a rule covers, how far ahead it bites, what it does to the rate, and in what order
 * rules stack. A rule that reads "occupancy 85–100%" but fires on the room type rather than the
 * hotel, or a paused rule shown as active, is a pricing decision made on the wrong information.
 *
 * This renders deploy/dev/previews/revenue-rules.html in jsdom and checks it against
 * deploy/dev/preview/rules-sample.mjs: six rules in priority order with the condition each one
 * really matches on, the two adjustment shapes (a percentage and a fixed amount), the paused
 * rule's actions flipped to Enable, the rate floors and ceilings per room type, and the mode,
 * rounding step and horizon the form would save.
 *
 * Run: node deploy/dev/audit/revenue_rules_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const FILE = 'deploy/dev/previews/revenue-rules.html';
const dom = new JSDOM(readFileSync(FILE, 'utf8'), { url: 'https://preview.local/revenue/rules' });
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

/* ── the screen and its four figures ─────────────────────────────────────── */
ok('the page is titled for the rules screen', doc.title.startsWith('Pricing Rules'), doc.title);
const stats = $$('.rvr-stat').map(stat => ({
  label: text(stat.querySelector('.rvr-stat-label')),
  value: text(stat.querySelector('.rvr-stat-value')),
}));
ok('four figures head the screen', stats.length === 4, JSON.stringify(stats.map(s => s.label)));
ok('the mode is stated, not implied', stats[0]?.value === 'Recommend – I publish', stats[0]?.value);
ok('active rules are counted from the rules, not typed in', stats[1]?.value === '5', stats[1]?.value);
ok('paused rules are counted separately', stats[2]?.value === '1', stats[2]?.value);
ok('room types with a floor or a ceiling are counted as protected', stats[3]?.value === '5 / 5', stats[3]?.value);
ok('the card head repeats the protected count', /5 protected/.test(text(doc.body)));

/* ── the rules table ─────────────────────────────────────────────────────── */
const rows = $$('.rvr-rules-table tbody tr');
ok('every rule is listed', rows.length === 6, `${rows.length} rows`);
ok('the rows are in the order the engine applies them',
  rows.map(row => text(row.querySelector('.rvr-priority'))).join(',') === '1,2,3,4,5,6',
  rows.map(row => text(row.querySelector('.rvr-priority'))).join(','));
ok('and the table says which order that is',
  /lowest priority number first/.test(text(doc.body)));

const conditions = rows.map(row => text(row.querySelector('.rvr-condition')));
ok('a stay-date rule states its dates', conditions[0] === '20 Nov – 29 Nov 2026', conditions[0]);
ok('an occupancy rule states the band and the basis', conditions[1] === 'hotel occupancy 85–100%', conditions[1]);
ok('a nights rule names the nights', conditions[2] === 'Fri/Sat nights', conditions[2]);
ok('a lead-time rule states both ends', conditions[4] === 'booked 60–730 days ahead', conditions[4]);
ok('a rule measuring the room type says room-type, not hotel',
  conditions[3] === 'room-type occupancy 0–45% · booked 0–7 days ahead', conditions[3]);
ok('a rule with several conditions lists them all', conditions[5].includes('Tue nights'), conditions[5]);

const adjustments = rows.map(row => text(row.querySelector('.rvr-adjust')));
ok('a fixed amount prints as money with its sign', adjustments[0] === '+GH₵ 200.00', adjustments[0]);
ok('a percentage prints as a percentage', adjustments[1] === '+18%', adjustments[1]);
ok('a cut prints a minus sign rather than a hyphen inside a number', adjustments[3] === '−12%', adjustments[3]);
ok('a raise and a cut are distinguishable at a glance',
  adjustments[1].startsWith('+') && adjustments[3].startsWith('−') && adjustments[5].startsWith('+'),
  adjustments.join(' '));

ok('a rule scoped to one room type names it',
  text(rows[5].querySelector('th')).includes('Suite quiet Tuesdays')
  && text(rows[5].children[2]).includes('Executive Suite'), text(rows[5].children[2]));
ok('rules that apply everywhere say so in words',
  rows.slice(0, 5).every(row => text(row.children[2]) === 'All room types'), text(rows[0].children[2]));

const stays = rows.map(row => text(row.children[5]));
ok('a minimum stay is shown on the rules that set one', stays[0] === '3' && stays[2] === '2', stays.join(','));
ok('and a dash where no minimum applies', stays[1] === '—', stays[1]);

ok('five rules show as active', rows.filter(row => /Active/.test(text(row))).length === 5);
ok('the paused rule is marked paused, not hidden', /Paused/.test(text(rows[5])), text(rows[5]));
const pausedActions = text(rows[5].querySelector('.rvr-row-actions'));
ok('the paused rule offers Enable', pausedActions.includes('Enable') && !pausedActions.includes('Pause'), pausedActions);
ok('the active rules offer Pause',
  rows.slice(0, 5).every(row => /Pause/.test(text(row))), text(rows[0]));
ok('every rule can be edited', $$('.rvr-rules-table a[aria-label^="Edit"]').length === 6,
  `${$$('.rvr-rules-table a[aria-label^="Edit"]').length} edit links`);
ok('every rule can be deleted, behind a confirmation',
  $$('.rvr-rules-table form[data-confirm]').length === 12,
  `${$$('.rvr-rules-table form[data-confirm]').length} confirmed forms`);

/* ── the settings the form would save ────────────────────────────────────── */
const modes = $$('.rvr-modes input[name=rm_mode]');
ok('all three pricing modes are offered', modes.length === 3, `${modes.length} modes`);
ok('and the one in force is the one selected',
  modes.find(input => input.checked)?.value === 'recommend', modes.find(input => input.checked)?.value);
const roundTo = $('input[name=rm_round_to]');
ok('the rounding step prints the band it accepts',
  roundTo?.getAttribute('min') === '0' && roundTo?.getAttribute('max') === '10000',
  `${roundTo?.getAttribute('min')}–${roundTo?.getAttribute('max')}`);
ok('with the value in force, and what 0 means', roundTo?.value === '50' && /0 = no rounding/.test(text(doc.body)),
  roundTo?.value);
const horizon = $('input[name=rm_horizon_days]');
ok('the forecast horizon prints the band it accepts',
  horizon?.getAttribute('min') === '14' && horizon?.getAttribute('max') === '365',
  `${horizon?.getAttribute('min')}–${horizon?.getAttribute('max')}`);
ok('with the value in force', horizon?.value === '90', horizon?.value);

/* ── the rate guard-rails ────────────────────────────────────────────────── */
const limitRows = $$('.rvr-limits-table tbody tr');
ok('every room type has a row of guard-rails', limitRows.length === 5, `${limitRows.length} rows`);
ok('a floor and a ceiling are editable for each',
  $$('.rvr-limits-table input.rvr-limit-input').length === 10,
  `${$$('.rvr-limits-table input.rvr-limit-input').length} inputs`);
ok('the inputs print the band the controller accepts',
  $$('.rvr-limits-table input.rvr-limit-input').every(input => input.getAttribute('min') === '0' && input.getAttribute('max') === '99999999'));
ok('a stored floor is shown as stored',
  $('input[name="min_rate[4]"]')?.value === '480' && $('input[name="max_rate[4]"]')?.value === '900',
  $('input[name="min_rate[4]"]')?.value);
ok('a room type with no weekend rate says so rather than printing zero',
  text(limitRows[2]).includes('—'), text(limitRows[2]));
ok('and a room type with no ceiling shows an empty box, not a zero',
  $('input[name="max_rate[11]"]')?.value === '', $('input[name="max_rate[11]"]')?.value);
ok('the table says blank removes a limit', /Leave either field blank to remove that limit/.test(text(doc.body)));

/* ── nothing left unrendered ─────────────────────────────────────────────── */
const bodyCopy = doc.body.cloneNode(true);
bodyCopy.querySelectorAll('script, style').forEach(node => node.remove());
const bodyText = text(bodyCopy);
ok('no Blade directive survived the render', !/@(if|foreach|endif|endforeach|php|else)\b/.test(bodyText));
ok('no undefined or NaN reached the screen', !/undefined|NaN/.test(bodyText));

if (failures.length) {
  console.error(`revenue rules check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`revenue rules check: ${passed}/${passed} passed`);
