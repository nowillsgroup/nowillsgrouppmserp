#!/usr/bin/env node
/*
 * The Banquet booking form is a working screen, so the preview is checked the way a
 * salesperson uses it: fill in a function, pick a venue, watch the capacity hint, choose a
 * package, add an extra, read the estimate. The page's own script is executed here, not
 * just parsed, so a handler that does not fire fails the check.
 *
 * Two things this check is deliberately honest about:
 *
 *   1. the form knows capacity and venue availability live — the clash warning reads the
 *      season's holds that came with the page, so a held room is flagged as the date and
 *      clock are typed rather than when Save comes back, and
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
  [...tplFn.querySelectorAll('[data-f=banquet_package_id] optgroup')].map(group => group.label).join('|') === 'Buffet|Plated|Cocktail|Coffee|Beverage',
  [...tplFn.querySelectorAll('[data-f=banquet_package_id] optgroup')].map(group => group.label).join(', '));
ok('the drinks slot offers the same register, grouped the same way',
  [...tplFn.querySelectorAll('[data-f=banquet_bar_package_id] optgroup')].map(group => group.label).join('|') === 'Buffet|Plated|Cocktail|Coffee|Beverage',
  [...tplFn.querySelectorAll('[data-f=banquet_bar_package_id] optgroup')].map(group => group.label).join(', '));
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

/* ── what the form knows about the room, as the desk types ───────────────── */
// The form used to know nothing about what was already in a venue: a clash surfaced as a
// validation error after Save. It now carries the season's holds and answers on the keystroke.
const formScript = create.$$('script').map(script => script.textContent).join('\n');
const setSlot = (card, hall, date, start, end) => {
  const venue = card.querySelector('[data-f=banquet_hall_id]');
  venue.value = hall; create.fire(venue, 'change');
  const day = card.querySelector('[data-f=date]'); day.value = date; create.fire(day);
  const from = card.querySelector('[data-f=start_time]'); from.value = start; create.fire(from);
  const to = card.querySelector('[data-f=end_time]'); to.value = end; create.fire(to);
  return card.querySelector('.fn-avail');
};
ok('a venue already held that night is flagged before the form is saved', (() => {
  const hint = setSlot(fn, '1', '2026-11-19', '19:00', '23:00');
  return /BQT-26039/.test(hint.textContent) && /holds this venue 19:00–23:00/.test(hint.textContent);
})(), fn.querySelector('.fn-avail')?.textContent);
ok('a definite hold is shown in red', fn.querySelector('.fn-avail')?.classList.contains('text-red'));
ok('a tentative hold is a warning, not a refusal', (() => {
  const hint = setSlot(fn, '3', '2026-11-21', '12:00', '16:00');
  return /BQT-26044/.test(hint.textContent) && /tentative/.test(hint.textContent)
    && hint.classList.contains('text-amber') && !hint.classList.contains('text-red');
})(), fn.querySelector('.fn-avail')?.textContent);
ok('the turn-around is part of the test: 22:00 out blocks a 22:30 start', (() => {
  const hint = setSlot(fn, '2', '2026-11-14', '22:30', '23:30');
  return /BQT-26037/.test(hint.textContent) && /60 minutes/.test(hint.textContent);
})(), fn.querySelector('.fn-avail')?.textContent);
ok('a free venue says so rather than staying silent', (() => {
  const hint = setSlot(fn, '2', '2026-11-19', '19:00', '23:00');
  return /Free for this slot/.test(hint.textContent) && hint.classList.contains('text-green');
})(), fn.querySelector('.fn-avail')?.textContent);
ok('the answer is on the page — no round trip while the desk is on the telephone',
  /const BUSY = \[/.test(formScript) && !/fetch\(|XMLHttpRequest/.test(formScript));
ok('the form links out to the venue search', (() => {
  const link = create.$('#findVenue');
  return !!link && /Find a free venue/.test(link.textContent) && link.getAttribute('href') === '/banquet/availability';
})());

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

/* ── the drinks package beside the food package ──────────────────────────── */
ok('a function starts with no drinks package', fn.querySelector('[data-f=banquet_bar_package_id]').value === '');
ok('choosing a drinks package refills the drinks rate, leaving the food rate alone',
  (() => {
    const foodRate = fn.querySelector('[data-f=rate_per_pax]').value;
    const bar = fn.querySelector('[data-f=banquet_bar_package_id]');
    bar.value = '18';
    create.fire(bar, 'change');
    return Number(fn.querySelector('[data-f=bar_rate_per_pax]').value) === 95
      && fn.querySelector('[data-f=rate_per_pax]').value === foodRate;
  })(),
  fn.querySelector('[data-f=bar_rate_per_pax]').value);
ok('the drinks package is named beside the rate that goes with it',
  /House drinks package/.test(fn.querySelector('.fn-bar')?.textContent ?? ''),
  fn.querySelector('.fn-bar')?.textContent);

/* ── dietary needs, recorded per function ────────────────────────────────── */
ok('a new function starts with no dietary needs to its name',
  /None recorded/.test(fn.querySelector('.fn-diet-count')?.textContent ?? ''),
  fn.querySelector('.fn-diet-count')?.textContent);
ok('adding a dietary need is one row, named the way the request is read',
  (() => {
    fn.querySelector('.fn-adddiet').click();
    const row = fn.querySelector('.fn-diet');
    if (!row) return false;
    return row.querySelector('[data-d=diet]').name === 'functions[0][dietary][0][diet]'
      && row.querySelector('[data-d=covers]').name === 'functions[0][dietary][0][covers]'
      && row.querySelector('[data-d=dish]').name === 'functions[0][dietary][0][dish]';
  })(),
  fn.querySelector('.fn-diet [data-d=diet]')?.name);
ok('the diet list is the six the sheet prints',
  fn.querySelectorAll('.fn-diet [data-d=diet] option').length === 7,
  String(fn.querySelectorAll('.fn-diet [data-d=diet] option').length));
ok('the chip counts the covers as they are typed',
  (() => {
    const row = fn.querySelector('.fn-diet');
    const select = row.querySelector('[data-d=diet]');
    const covers = row.querySelector('[data-d=covers]');
    select.value = 'coeliac';
    create.fire(select, 'change');
    covers.value = '4';
    create.fire(covers);
    return /1 need · 4 covers/.test(fn.querySelector('.fn-diet-count')?.textContent ?? '');
  })(),
  fn.querySelector('.fn-diet-count')?.textContent);
ok('a dietary row can be taken back off the function',
  (() => {
    fn.querySelector('.fn-diet .dt-del').click();
    return fn.querySelectorAll('.fn-diet').length === 0
      && /None recorded/.test(fn.querySelector('.fn-diet-count')?.textContent ?? '');
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
ok('the held reception brings its drinks package back onto the form',
  (() => {
    const reception = edit.$$('#fnList .fn-card')[1];
    return reception.querySelector('[data-f=banquet_bar_package_id]').value === '18'
      && Number(reception.querySelector('[data-f=bar_rate_per_pax]').value) === 95;
  })(),
  edit.$$('#fnList .fn-card')[1]?.querySelector('[data-f=banquet_bar_package_id]')?.value);
ok('the ceremony has the food only, with no drinks package',
  edit.$$('#fnList .fn-card')[0].querySelector('[data-f=banquet_bar_package_id]').value === ''
  && Number(edit.$$('#fnList .fn-card')[0].querySelector('[data-f=bar_rate_per_pax]').value || 0) === 0,
  edit.$$('#fnList .fn-card')[0].querySelector('[data-f=bar_rate_per_pax]').value);
ok('the estimate names the drinks rate beside the food rate',
  /drinks/.test(edit.$$('#estList li')[1]?.textContent ?? ''),
  edit.$$('#estList li')[1]?.textContent?.replace(/\s+/g, ' '));
ok('the dietary needs saved on a function come back on the form',
  (() => {
    const reception = edit.$$('#fnList .fn-card')[1];
    const rows = [...reception.querySelectorAll('.fn-diet')].map(row => [
      row.querySelector('[data-d=diet]').value,
      row.querySelector('[data-d=covers]').value,
    ].join(':'));
    return rows.join(' ') === 'vegetarian:12 coeliac:2 nut_allergy:1';
  })(),
  [...(edit.$$('#fnList .fn-card')[1]?.querySelectorAll('.fn-diet [data-d=diet]') ?? [])].map(el => el.value).join(','));
ok('and the function says how many covers need a different plate',
  /3 needs · 15 covers/.test(edit.$$('#fnList .fn-card')[1].querySelector('.fn-diet-count')?.textContent ?? ''),
  edit.$$('#fnList .fn-card')[1].querySelector('.fn-diet-count')?.textContent);
ok('a function with no dietary needs says so rather than showing a blank row',
  edit.$$('#fnList .fn-card')[0].querySelectorAll('.fn-diet').length === 0
  && /None recorded/.test(edit.$$('#fnList .fn-card')[0].querySelector('.fn-diet-count')?.textContent ?? ''));
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

/* ── the sheet reads down the clock (v10.35) ─────────────────────────────── */
// The sample hands the form its functions in the order a desk keys them in — the evening reception first, the
// afternoon ceremony second, which is how a wedding is actually booked. The plan is printed by the day and the clock.
const keyedIn = (() => {
  const match = /const INIT = (\[[\s\S]*?\]);\n/.exec(readFileSync(EDIT, 'utf8'));
  return match ? JSON.parse(match[1]).map(f => f.id) : [];
})();
ok('the form was handed the evening function first, the way it was keyed in',
  keyedIn.join(',') === '4102,4101', keyedIn.join(','));
ok('and printed the afternoon ceremony first, because the sheet reads down the clock',
  edit.$$('#fnList [data-f=start_time]').map(el => el.value).join(' ') === '14:00 19:00',
  edit.$$('#fnList [data-f=start_time]').map(el => el.value).join(' '));
ok('the numbering follows the clock, not the keying order',
  edit.$('#fnList .fn-card [data-f=name]').id === 'bq-function-1-name'
  && edit.$('#fnList .fn-card [data-f=name]').name === 'functions[0][name]',
  edit.$('#fnList .fn-card [data-f=name]').id);
ok('the venue and guests travel with the card, so function 1 is still the ceremony',
  edit.$('#fnList .fn-card [data-f=banquet_hall_id]').value === '3'
  && edit.$('#fnList .fn-card [data-f=expected_pax]').value === '150');

// A clock that changes is the moment a card knows where it belongs in the day.
const movedCard = edit.$('#fnList .fn-card');
const movedClock = movedCard.querySelector('[data-f=start_time]');
movedClock.value = '21:00';
edit.fire(movedClock, 'change');
ok('a later clock moves the card down the day', (() => {
  const clocks = edit.$$('#fnList [data-f=start_time]').map(el => el.value).join(' ');
  return clocks === '19:00 21:00'
    && edit.$('#fnList .fn-card [data-f=banquet_hall_id]').value === '1';
})(), edit.$$('#fnList [data-f=start_time]').map(el => el.value).join(' '));
ok('and the renumbered card is submitted in its new place',
  movedCard.querySelector('[data-f=name]').name === 'functions[1][name]'
  && edit.$('#fnList .fn-card [data-f=name]').name === 'functions[0][name]',
  movedCard.querySelector('[data-f=name]').name);
movedClock.value = '14:00';
edit.fire(movedClock, 'change');

// The rule is not a habit of one screen: it lives in the models, and every banquet list asks for it.
const functionModel = readFileSync(resolve(ROOT, 'app/Models/BanquetFunction.php'), 'utf8');
const eventModel = readFileSync(resolve(ROOT, 'app/Models/BanquetEvent.php'), 'utf8');
const controllerSource = readFileSync(resolve(ROOT, 'app/Http/Controllers/BanquetController.php'), 'utf8');
ok('the rule is the day, then the clock, then entry order — written once, on the function',
  /scopeInTimeOrder[\s\S]{0,200}orderBy\('date'\)->orderBy\('start_time'\)->orderBy\('id'\)/.test(functionModel));
ok('the event relation and the events list speak it too',
  /hasMany\(BanquetFunction::class\)->inTimeOrder\(\)/.test(eventModel)
  && /scopeInTimeOrder[\s\S]{0,200}orderBy\('event_date'\)->orderBy\('start_time'\)->orderBy\('id'\)/.test(eventModel));
ok('every stage of the events list asks for the clock order, not just the day',
  ["'upcoming' => $q->where('status', 'definite')->whereDate('end_date', '>=', $today)->inTimeOrder()",
    "'tentative' => $q->where('status', 'tentative')->orderBy('option_date')->inTimeOrder()",
    "'enquiry' => $q->where('status', 'enquiry')->inTimeOrder()",
    "'to_bill' => $q->where('status', 'definite')->whereNull('billed_at')->whereDate('end_date', '<=', $today)->orderBy('end_date')->inTimeOrder()",
    'default => $q->inTimeOrder()'].every(line => controllerSource.includes(line)));
ok('the function sheet asks the model for the order instead of spelling it out',
  /whereHas\('event', fn \(\$q\) => \$q->whereIn\('status', \['tentative', 'definite', 'completed'\]\)\)->inTimeOrder\(\)->get\(\)/.test(controllerSource));

if (failures.length) {
  console.log(`banquet preview check: ${failures.length} FAILED, ${passed} passed`);
  failures.slice(0, 12).forEach(failure => console.log(`  ✗ ${failure}`));
  process.exit(1);
}
console.log(`banquet preview check: ${passed} assertions passed`);
