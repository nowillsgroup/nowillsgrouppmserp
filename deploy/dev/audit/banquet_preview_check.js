#!/usr/bin/env node
/*
 * The Banquet booking form is a working screen, so the preview is checked the way a
 * salesperson uses it: fill in a function, pick a venue, watch the capacity hint, choose a
 * package, add an extra, read the estimate. The page's own script is executed here, not
 * just parsed, so a handler that does not fire fails the check.
 *
 * Two things this check is deliberately honest about:
 *
 *   1. the form knows capacity live and nothing about availability — the whole point of
 *      the preview is that a clash only shows up when the form is saved, and
 *   2. the booking rules themselves (clash buffer, deposit gate, guarantee lock) are not
 *      exercised anywhere, because tests/ has none for banquet. The assertions below say
 *      what the screen does, not what the service promises.
 *
 * Run: node deploy/dev/audit/banquet_preview_check.js
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const CREATE = resolve(ROOT, 'deploy/dev/previews/banquet-form.html');
const EDIT = resolve(ROOT, 'deploy/dev/previews/banquet-form-edit.html');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('banquet preview check: SKIP (jsdom is not installed)');
  process.exit(0);
}

let passed = 0;
const failures = [];
const ok = (label, condition, detail = '') => {
  if (condition) { passed += 1; return; }
  failures.push(detail ? `${label} — ${detail}` : label);
};

ok('both previews exist', existsSync(CREATE) && existsSync(EDIT));
if (!existsSync(CREATE) || !existsSync(EDIT)) {
  console.log(`banquet preview check: 1 FAILED\n  ✗ ${failures[0]}`);
  process.exit(1);
}

const load = (file, url) => {
  const dom = new JSDOM(readFileSync(file, 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true, url });
  const { window } = dom;
  const doc = window.document;
  const fire = (el, type = 'input') => el.dispatchEvent(new window.Event(type, { bubbles: true }));
  return { window, doc, fire, $: sel => doc.querySelector(sel), $$: sel => [...doc.querySelectorAll(sel)] };
};

const create = load(CREATE, 'http://preview.local/banquet/create');
const edit = load(EDIT, 'http://preview.local/banquet/418/edit');

/* ── the enquiry form ────────────────────────────────────────────────────── */
ok('the booking form is titled the way the view titles it',
  /New Banquet Enquiry/.test(create.doc.title), create.doc.title);
ok('a new enquiry is walked through three steps',
  create.$$('.bq-form-steps a').length === 3,
  `${create.$$('.bq-form-steps a').length} steps`);
ok('the three steps are the brief, the function plan and the estimate',
  ['Event & client', 'Function plan', 'Estimate & save'].every(label => create.$('.bq-form-steps')?.textContent.includes(label)));
ok('the form says what an enquiry is worth before it is saved',
  /An enquiry is non-binding/.test(create.$('.bq-form-note')?.textContent ?? ''));
ok('the client brief asks for a contact and an event type',
  Boolean(create.$('#f_contact_name')) && Boolean(create.$('#f_event_type')));
ok('the event type carries every category the model defines',
  create.$$('#f_event_type option').length === 9,
  `${create.$$('#f_event_type option').length} types`);
ok('an enquiry can be started as a tentative hold, or not',
  create.$$('input[name=status]').length === 2
    && /Non-binding/.test(create.$('.bq-status-options')?.textContent ?? '')
    && /Temporarily reserves the venue/.test(create.$('.bq-status-options')?.textContent ?? ''));
ok('the option date is out of the way until a hold is chosen',
  create.$('#f_option_date')?.closest('.field')?.hidden === true);
ok('there is one blank function to fill in',
  create.$$('#fnList .fn-card').length === 1,
  `${create.$$('#fnList .fn-card').length} functions`);

/* ── the function plan ───────────────────────────────────────────────────── */
const fn = create.$('#fnList .fn-card');
const tplFn = create.doc.getElementById('fnTpl').content;
const tplItem = create.doc.getElementById('itemTpl').content;
ok('a function asks for a venue, a date, a time, a set-up and a guest count',
  ['banquet_hall_id', 'date', 'start_time', 'end_time', 'setup_style', 'expected_pax']
    .every(field => Boolean(fn.querySelector(`[data-f=${field}]`))));
ok('every active venue is offered', tplFn.querySelectorAll('[data-f=banquet_hall_id] option').length === 6,
  `${tplFn.querySelectorAll('[data-f=banquet_hall_id] option').length} venues incl. the placeholder`);
ok('every set-up style is offered', fn.querySelectorAll('[data-f=setup_style] option').length === 6);
ok('the menu packages are grouped by type, not one flat list',
  [...tplFn.querySelectorAll('optgroup')].map(group => group.label).join('|') === 'Buffet|Plated|Cocktail|Coffee',
  [...tplFn.querySelectorAll('optgroup')].map(group => group.label).join(', '));
ok('the extras are grouped by their own category',
  [...tplItem.querySelectorAll('optgroup')].map(group => group.label)
    .join('|') === 'Audio-visual|Décor & flowers|Entertainment|Equipment & furniture|Staffing|Other',
  [...tplItem.querySelectorAll('optgroup')].map(group => group.label).join(', '));

/* ── capacity, live, before anything is saved ────────────────────────────── */
const venue = fn.querySelector('[data-f=banquet_hall_id]');
ok('a function with no venue says so instead of guessing capacity',
  /Select a venue to check capacity/.test(fn.querySelector('.fn-cap')?.textContent ?? ''));
create.fire(venue, 'change');
venue.value = '5';
create.fire(venue, 'change');
const pax = fn.querySelector('[data-f=expected_pax]');
pax.value = '40';
create.fire(pax);
ok('the set-up style decides the capacity, and the board keeps to it',
  /18 seats in this set-up/.test(fn.querySelector('.fn-cap')?.textContent ?? '')
    && fn.querySelector('.fn-cap').classList.contains('text-red'),
  fn.querySelector('.fn-cap')?.textContent);
pax.value = '12';
create.fire(pax);
ok('and the warning goes away when the party fits',
  !fn.querySelector('.fn-cap').classList.contains('text-red'));
const pkg = fn.querySelector('[data-f=banquet_package_id]');
pkg.value = '17';
create.fire(pkg, 'change');
ok('choosing a package fills its rate per guest',
  fn.querySelector('[data-f=rate_per_pax]').value === '520',
  fn.querySelector('[data-f=rate_per_pax]').value);
ok('a party below the package floor is flagged, not blocked',
  /Package minimum 80 guests/.test(fn.querySelector('.fn-cap')?.textContent ?? ''),
  fn.querySelector('.fn-cap')?.textContent);
ok('the venue rent follows the clock: half day, then full day',
  /Half-day rate/.test(fn.querySelector('.fn-rent')?.textContent ?? ''),
  fn.querySelector('.fn-rent')?.textContent);

/* ── what the form does NOT know ─────────────────────────────────────────── */
const formScript = create.$$('script').map(script => script.textContent).join('\n');
ok('the form prices the booking but cannot see what is already in the venue',
  !/clash|availability|already held|overlap/i.test(formScript),
  'no availability data reaches the form — a clash is an error on save, not a warning as you type');
ok('nothing in the form reaches the server before it is submitted',
  !/fetch\(|XMLHttpRequest/.test(formScript));

/* ── the estimate ────────────────────────────────────────────────────────── */
ok('the estimate is a running total, not a promise',
  /Not a bill or payment request/.test(create.$('#estList')?.textContent ?? ''));
ok('adding an extra line prices it from the service register',
  (() => {
    fn.querySelector('.fn-additem').click();
    const row = fn.querySelector('.fn-item');
    if (!row) return false;
    const select = row.querySelector('[data-i=banquet_service_id]');
    select.value = '29';
    create.fire(select, 'change');
    return row.querySelector('[data-i=description]').value === 'Chiavari chair'
      && Number(row.querySelector('[data-i=unit_price]').value) === 35
      && Number(row.querySelector('[data-i=tax_rate]').value) === 15;
  })());
ok('a quantity priced line adds to the estimate',
  (() => {
    const row = fn.querySelector('.fn-item');
    const qty = row.querySelector('[data-i=qty]');
    qty.value = '12';
    create.fire(qty);
    const amount = row.querySelector('.it-amt')?.textContent ?? '';
    return Number(amount.replace(/[^\d.]/g, '')) === 420;
  })());

/* ── the edit screen ─────────────────────────────────────────────────────── */
ok('editing drops the enquiry-only controls', edit.$$('.bq-form-steps a').length === 0);
ok('and the tab names the event being edited', /Edit BQT-26041/.test(edit.doc.title), edit.doc.title);
ok('the held event loads its two functions',
  edit.$$('#fnList .fn-card').length === 2,
  `${edit.$$('#fnList .fn-card').length} functions`);
ok('each function opens on its own venue, set-up and guest count',
  edit.$$('#fnList .fn-card').map(card => [
    card.querySelector('[data-f=banquet_hall_id]').value,
    card.querySelector('[data-f=setup_style]').value,
    card.querySelector('[data-f=expected_pax]').value,
  ].join('/')).join(' ') === '3/theatre/150 1/banquet/180',
  edit.$$('#fnList .fn-card').map(card => card.querySelector('[data-f=banquet_hall_id]').value).join(','));
ok('the extras the client asked for are on the function',
  edit.$$('#fnList .fn-item').length === 5,
  `${edit.$$('#fnList .fn-item').length} extra lines`);
ok('a final guarantee is shown as final, and cannot be lowered',
  (() => {
    const locked = edit.$$('.fn-lock').filter(chip => !chip.hidden);
    const first = edit.$('#fnList .fn-card');
    return locked.length === 1
      && Number(first.querySelector('[data-f=guaranteed_pax]').min) === 150;
  })());
ok('the held event is estimated, not left at zero',
  (() => {
    const total = [...edit.$$('#estList li')].pop()?.textContent ?? '';
    return Number(total.replace(/[^\d.]/g, '')) > 100000;
  })(),
  [...edit.$$('#estList li')].pop()?.textContent?.replace(/\s+/g, ' '));
ok('the estimate lists each function beside its own line',
  edit.$$('#estList li').length === 5,
  `${edit.$$('#estList li').length} lines — two functions, net, tax, total`);

/* ── across both screens ─────────────────────────────────────────────────── */
for (const [name, page] of [['create', create], ['edit', edit]]) {
  ok(`the ${name} form names the property it belongs to`,
    (page.doc.title ?? '').includes('The Grove Essipong Beach Resort'), page.doc.title);
  ok(`the ${name} form keeps the save action in the aside, not at the bottom of a long page`,
    Boolean(page.$('.bq-save-card #saveEvent')));
  ok(`the ${name} form offers the way out beside the way forward`,
    Boolean(page.$('.bq-form-actions a[href]')) && Boolean(page.$('.bq-form-actions #saveEvent')));
}

if (failures.length) {
  console.log(`banquet preview check: ${failures.length} FAILED, ${passed} passed`);
  failures.slice(0, 12).forEach(failure => console.log(`  ✗ ${failure}`));
  process.exit(1);
}
console.log(`banquet preview check: ${passed} assertions passed`);
