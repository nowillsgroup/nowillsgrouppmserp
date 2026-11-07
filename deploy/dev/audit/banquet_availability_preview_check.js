#!/usr/bin/env node
/*
 * The Banquet venue search is the screen that answers the question before the desk promises
 * a date. It is checked the way the desk uses it: give it a date, a clock, a guest count and
 * a set-up, read the room cards.
 *
 * What this check is deliberately honest about:
 *
 *   1. the status on every card is the same rule the service applies — a venue is held when
 *      a hold in the season's list overlaps the slot plus the turn-around, too small when
 *      the guests do not fit that set-up, free otherwise — but the assertions read the
 *      rendered page, not the service, and
 *   2. the held card has to be more use than a red badge: it names the event, its hours and
 *      the turn-around, or the desk cannot make a decision from it.
 *
 * Run: node deploy/dev/audit/banquet_availability_preview_check.js
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const MIXED = resolve(ROOT, 'deploy/dev/previews/banquet-availability.html');
const IDLE = resolve(ROOT, 'deploy/dev/previews/banquet-availability-idle.html');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('banquet venue search check: SKIP (jsdom is not installed)');
  process.exit(0);
}

let passed = 0;
const failures = [];
const ok = (label, condition, detail = '') => {
  if (condition) { passed += 1; return; }
  failures.push(detail ? `${label} — ${detail}` : label);
};

ok('both venue search previews exist', existsSync(MIXED) && existsSync(IDLE));
if (!existsSync(MIXED) || !existsSync(IDLE)) {
  console.log(`banquet venue search check: 1 FAILED\n  ✗ ${failures[0]}`);
  process.exit(1);
}

const load = (file, url) => {
  const dom = new JSDOM(readFileSync(file, 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true, url });
  const { window } = dom;
  return { window, doc: window.document, $: s => window.document.querySelector(s), $$: s => [...window.document.querySelectorAll(s)] };
};

const mixed = load(MIXED, 'http://preview.local/banquet/availability?date=2026-11-19&start=19:00&end=23:00&pax=120&style=banquet');
const idle = load(IDLE, 'http://preview.local/banquet/availability');

const text = el => (el?.textContent ?? '').replace(/\s+/g, ' ').trim();
const cards = mixed.$$('.bq-venue');
const cardIn = status => cards.filter(card => card.classList.contains(`is-${status}`));

/* ── the page is the page ────────────────────────────────────────────────── */
ok('the tab is titled the words the view puts in @section(\'title\')',
  mixed.doc.title.startsWith('Find a venue ·'), mixed.doc.title);
ok('the heading repeats it, so the desk knows what it is looking at',
  text(mixed.$('h1')) === 'Find a venue', text(mixed.$('h1')));
ok('the breadcrumb puts it under Banquet', /Banquet \/ Find a venue/.test(text(mixed.$('.crumbs'))), text(mixed.$('.crumbs')));
ok('the search is a GET, so a slot can be sent to a colleague as a link',
  mixed.$('form.bq-find')?.getAttribute('method')?.toLowerCase() === 'get'
  && mixed.$('form.bq-find')?.getAttribute('action') === '/banquet/availability',
  mixed.$('form.bq-find')?.getAttribute('action'));

/* ── it asks for the four things a venue answers to ──────────────────────── */
const searchNames = mixed.$$('form.bq-find [name]').map(el => el.getAttribute('name'));
ok('the search asks for the date, both clocks, the guests and the set-up',
  ['date', 'start', 'end', 'pax', 'style'].every(name => searchNames.includes(name)),
  searchNames.join(', '));
ok('every search field is labelled, not placeheld',
  mixed.$$('form.bq-find input, form.bq-find select').every(el =>
    Boolean(mixed.$(`label[for="${el.getAttribute('id')}"]`))),
  mixed.$$('form.bq-find input, form.bq-find select').map(el => el.getAttribute('id')).join(', '));
ok('the slot it was asked about is what it prints back',
  mixed.$('#faDate').value === '2026-11-19' && mixed.$('#faStart').value === '19:00'
  && mixed.$('#faEnd').value === '23:00' && mixed.$('#faPax').value === '120'
  && mixed.$('#faStyle').value === 'banquet');

/* ── every venue answers ─────────────────────────────────────────────────── */
ok('all five venues answer, none is left off the list', cards.length === 5, `${cards.length} cards`);
ok('the free venues come first, then the held one, then the ones that are too small',
  cards.map(card => card.className.match(/is-(free|held|small)/)[1]).join(',') === 'free,free,held,small,small',
  cards.map(card => text(card.querySelector('h3'))).join(' · '));
ok('two venues are free for the slot', cardIn('free').length === 2);
ok('one venue is held by another event', cardIn('held').length === 1);
ok('two venues are too small for 120 at rounds', cardIn('small').length === 2);
ok('the count in the header is the count on the page',
  /2 venues are free/.test(text(mixed.$('.card-head'))), text(mixed.$('.card-head')).slice(0, 80));
ok('the summary says how the four answers split, and quotes the turn-around',
  /2 free · 1 held for another event · 2 too small for Banquet \(rounds\) at 120 guests/.test(text(mixed.$('#faSummary')))
  && /Turn-around: 60 minutes/.test(text(mixed.$('#faSummary'))),
  text(mixed.$('#faSummary')));

/* ── a free venue is a decision, not a badge ─────────────────────────────── */
const freeCard = cardIn('free')[0];
ok('a free venue shows its capacity for that set-up and the rent for those hours',
  /Capacity 150 · Banquet \(rounds\)/.test(text(freeCard)) && /Rent .*3,800\.00 for 19:00–23:00/.test(text(freeCard)),
  text(freeCard));
const take = freeCard.querySelector('.bq-venue-actions a');
ok('a free venue hands the whole slot to the enquiry form in one click',
  take?.getAttribute('href')?.startsWith('/banquet/create?')
  && /hall=2/.test(take.getAttribute('href')) && /date=2026-11-19/.test(take.getAttribute('href'))
  && /start=19%3A00/.test(take.getAttribute('href')) && /pax=120/.test(take.getAttribute('href'))
  && /style=banquet/.test(take.getAttribute('href')),
  take?.getAttribute('href'));
ok('that click is labelled in the desk\'s words, with the icon the app uses',
  /Take the enquiry/.test(text(take)) && take?.querySelector('use[href="#i-plus"]') !== null);

/* ── held is the answer the desk actually needs ──────────────────────────── */
const heldCard = cardIn('held')[0];
const held = text(heldCard);
ok('the held venue is the room another event already has', /The Grand Ballroom/.test(held), held.slice(0, 60));
ok('the held card names the event that has it, not just a red rail',
  /BQT-26039/.test(held) && /Zenith Bank staff awards/.test(held), held);
ok('it says how that event sits against the slot, and how long the room needs after it',
  /holds this room 19:00–23:00/.test(held) && /definite/.test(held) && /Allow 60 minutes to turn it round/.test(held), held);
ok('a held venue still offers the way out — what is on that day',
  /What is on that day/.test(text(heldCard.querySelector('.bq-venue-actions a')))
  && heldCard.querySelector('.bq-venue-actions a')?.getAttribute('href')?.startsWith('/banquet/diary?'));
ok('the status is written in words, so it does not rely on the rail colour',
  /Held/.test(text(heldCard.querySelector('.badge'))) && /Free/.test(text(freeCard.querySelector('.badge')))
  && /Too small/.test(text(cardIn('small')[0].querySelector('.badge'))));

/* ── too small says by how much ──────────────────────────────────────────── */
const smallCard = cardIn('small')[0];
ok('a venue that is too small says so in guests, not in a shrug',
  /Too small: 70 at Banquet \(rounds\) — 50 guests over/.test(text(smallCard)), text(smallCard));

/* ── before anything is typed ────────────────────────────────────────────── */
ok('the idle screen lists no venues — it has not been asked anything yet', idle.$$('.bq-venue').length === 0);
ok('the idle screen tells the desk what to give it',
  /date, the clock and the number of guests/.test(text(idle.$('.bq-free-note'))), text(idle.$('.bq-free-note')));
ok('the idle screen explains the turn-around, which is the rule the desk gets wrong',
  /60 minutes to turn round/.test(text(idle.doc.body)) && /free at 18:30 is not free for a function starting at 19:00/.test(text(idle.doc.body)));
ok('the way to a new enquiry is on the page in both states',
  Boolean(idle.$('.actions a[href^="/banquet/create"]')) && Boolean(mixed.$('.actions a[href^="/banquet/create"]')));

/* ── nothing leaked out of the renderer ──────────────────────────────────── */
for (const [name, page] of [['the search', mixed], ['the idle page', idle]]) {
  ok(`${name} has no unrendered Blade left in it`, !/{{|@section|@endforeach/.test(page.doc.body.innerHTML));
  ok(`${name} keeps the application shell around it`,
    Boolean(page.$('.sidebar')) && Boolean(page.$('.topbar')) && Boolean(page.$('.page-head')));
}

if (failures.length) {
  console.log(`banquet venue search check: ${failures.length} FAILED, ${passed} passed`);
  failures.slice(0, 12).forEach(failure => console.log(`  ✗ ${failure}`));
  process.exit(1);
}
console.log(`banquet venue search check: ${passed} assertions passed`);
