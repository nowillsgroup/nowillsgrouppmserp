#!/usr/bin/env node
/*
 * The club settings card is sixteen numbers that decide what the club charges and how many
 * people it lets in. Every one of them used to be printed the same way: `min="0" step="any"`
 * and no ceiling, while the brief and the controller said 0–100000 and the service quietly
 * raised a check-out window of 0 to 1 hour. A capacity of 999999 passed the box; 2.5 hours was
 * stored as 2; the in-house flag was a number you had to know to type as 1.
 *
 * This renders deploy/dev/previews/club-plans.html in jsdom and checks the card field by field
 * against the band it now prints, then checks the plan table the card sits under.
 *
 * Run: node deploy/dev/audit/club_plans_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const FILE = 'deploy/dev/previews/club-plans.html';
const dom = new JSDOM(readFileSync(FILE, 'utf8'), { url: 'https://preview.local/club/plans' });
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

/* ── the screen ──────────────────────────────────────────────────────────── */
ok('the page is titled for the club plans screen', doc.title.includes('Club Plans'), doc.title);
ok('the breadcrumb says where the screen sits', /Health Club/.test(text(doc.body)));

/* ── the plan table ──────────────────────────────────────────────────────── */
const rows = $$('#plansTable tbody tr');
ok('every starter plan is listed', rows.length === 9, `${rows.length} rows`);
ok('a withdrawn plan is kept and marked, not deleted',
  rows.filter(row => /withdrawn/.test(text(row))).length === 1,
  `${rows.filter(row => /withdrawn/.test(text(row))).length} marked`);
ok('the rows carry the plan code the desk quotes',
  rows.map(row => row.getAttribute('data-plan')).join(',') ===
  'GYM-M,FULL-M,FULL-Q,FULL-Y,CPL-Y,FAM-Y,OFF-M,STU-M,CORP-Y',
  rows.map(row => row.getAttribute('data-plan')).join(','));
ok('prices print in cedis with two decimals, tax excluded',
  text(rows[0]).includes('450.00') && text(rows[3]).includes('6,500.00'), text(rows[3]));

const offPeak = rows.find(row => row.getAttribute('data-plan') === 'OFF-M');
ok('the off-peak plan prints its own access hours', text(offPeak).includes('06:00–16:00'), text(offPeak));
ok('a plan that is on sale all day prints no hours', !/\d\d:\d\d/.test(text(rows[0])), text(rows[0]));
ok('each plan states its facilities by name', text(rows[0]).includes('Gym, Group classes'), text(rows[0]));
ok('and the benefits it carries',
  /2 guest passes · 10% F&B · 10% spa · 10 freeze days/.test(text(rows[1])), text(rows[1]));
ok('the live membership count is shown per plan', text(rows[1]).includes('64'), text(rows[1]));
ok('the tax rate is named on the table it applies to',
  /21% VAT, NHIL, GETFund & Tourism Levy/.test(text(doc.body)));

/* ── the settings card: sixteen fields ───────────────────────────────────── */
const card = $('#clubSettings');
ok('the settings card is on the screen', Boolean(card));
const inputs = $$('#clubSettings input[type=number]');
const selects = $$('#clubSettings select');
ok('sixteen settings are editable, fifteen numbers and one yes/no', inputs.length + selects.length === 16,
  `${inputs.length} numbers + ${selects.length} selects`);

const band = (name) => {
  const input = card.querySelector(`input[name="${name}"]`);
  return input ? { step: input.getAttribute('step'), min: input.getAttribute('min'), max: input.getAttribute('max') } : null;
};

ok('every number the card prints carries the ceiling the controller enforces',
  inputs.every(input => input.getAttribute('max') === '100000'),
  inputs.filter(input => input.getAttribute('max') !== '100000').map(input => input.name).join(', '));
ok('no field falls back to a loose band', inputs.every(input => ['1', '0.01'].includes(input.getAttribute('step'))),
  inputs.filter(input => !['1', '0.01'].includes(input.getAttribute('step'))).map(input => input.name).join(', '));

const money = ['club_daypass_adult', 'club_daypass_child', 'club_guestpass'];
ok('the three cedi fields take two decimals', money.every(key => band(key)?.step === '0.01'),
  money.map(key => `${key}=${band(key)?.step}`).join(', '));
ok('and start at zero, because a free pass is a decision the club may make',
  money.every(key => band(key)?.min === '0'), money.map(key => `${key}=${band(key)?.min}`).join(', '));

ok('the auto check-out window starts at one hour, the floor the service applies',
  band('club_checkout_hours')?.min === '1', band('club_checkout_hours')?.min);
ok('and the card says so in words rather than letting 0 fail silently', /At least 1 hour/.test(text(card)));
ok('every other count is a whole number', inputs
  .filter(input => !money.includes(input.name) && input.name !== 'club_checkout_hours')
  .every(input => input.getAttribute('step') === '1'),
  inputs.filter(input => !money.includes(input.name) && input.getAttribute('step') !== '1').map(input => input.name).join(', '));

const caps = inputs.filter(input => input.name.startsWith('club_cap_'));
ok('all seven facility capacities are editable here', caps.length === 7, `${caps.length} capacity fields`);
ok('a capacity of zero is allowed, and printed as allowed', caps.every(input => input.getAttribute('min') === '0'));

const flag = card.querySelector('select[name="club_resident_free"]');
ok('the in-house flag is a choice, not a number to type', Boolean(flag));
ok('with both outcomes spelled out',
  [...(flag?.options ?? [])].map(option => option.value).join(',') === '1,0',
  [...(flag?.options ?? [])].map(option => option.value).join(','));
ok('and the free-for-residents option is the one selected',
  flag?.querySelector('option[selected]')?.value === '1', flag?.querySelector('option[selected]')?.value);
ok('the card says what the numbers mean, units and all',
  /Cedis exclude tax/.test(text(card)) && /whole numbers/.test(text(card)));

ok('the current values are the shipped defaults, not blanks',
  card.querySelector('input[name="club_grace_days"]').value === '7'
  && card.querySelector('input[name="club_cap_pool"]').value === '40'
  && card.querySelector('input[name="club_checkout_hours"]').value === '4',
  card.querySelector('input[name="club_cap_pool"]').value);
ok('the save holds every field',
  $$('#clubSettings form').length === 1
  && $$('#clubSettings form input, #clubSettings form select').length === 16);

/* ── the plan editor ─────────────────────────────────────────────────────── */
const modal = $('#planModal');
ok('a plan can be created from the same screen', Boolean(modal));
ok('the editor offers the plan categories the club knows',
  modal.querySelectorAll('select[name=category] option').length === 6,
  `${modal.querySelectorAll('select[name=category] option').length} categories`);
ok('and the four terms, not a free-text month count',
  modal.querySelectorAll('select[name=term_months] option').length === 4,
  `${modal.querySelectorAll('select[name=term_months] option').length} terms`);
ok('every facility is a checkbox with a name',
  [...modal.querySelectorAll('input[name="facilities[]"]')].map(input => input.value).join(',') ===
  'gym,pool,sauna,tennis,squash,kids,classes',
  [...modal.querySelectorAll('input[name="facilities[]"]')].map(input => input.value).join(','));
ok('the price the editor takes is tax exclusive and says so', /Price per term \(excl. tax\)/.test(text(modal)));

/* ── nothing left unrendered ─────────────────────────────────────────────── */
const bodyCopy = doc.body.cloneNode(true);
bodyCopy.querySelectorAll('script, style').forEach(node => node.remove());
const bodyText = text(bodyCopy);
ok('no Blade directive survived the render', !/@(if|foreach|endif|endforeach|php|else)\b/.test(bodyText));
ok('no undefined or NaN reached the screen', !/undefined|NaN/.test(bodyText));

if (failures.length) {
  console.error(`club plans check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`club plans check: ${passed}/${passed} passed`);
