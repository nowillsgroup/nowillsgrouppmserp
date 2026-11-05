#!/usr/bin/env node
/*
 * The Reservation Workspace is a working desk screen, so the preview is checked the
 * way a receptionist uses it: read the day's numbers, filter the board, switch to the
 * rack, group by floor, open a room. The page's own script is executed here, not just
 * parsed, so a broken handler or a view switch that does not switch fails the check.
 *
 * Run: node deploy/dev/audit/workspace_preview_check.js
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const FILE = resolve(ROOT, 'deploy/dev/previews/reservations-workspace.html');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('workspace preview check: SKIP (jsdom is not installed)');
  process.exit(0);
}

let passed = 0;
const failures = [];
const ok = (label, condition, detail = '') => {
  if (condition) { passed += 1; return; }
  failures.push(detail ? `${label} — ${detail}` : label);
};

ok('the preview exists', existsSync(FILE));
if (!existsSync(FILE)) {
  console.log(`workspace preview check: 1 FAILED\n  ✗ ${failures[0]}`);
  process.exit(1);
}

const dom = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously',
  url: 'https://preview.local/reservations/workspace?date=2026-11-05',
  pretendToBeVisual: true,
});
const { window } = dom;
const doc = window.document;
const $ = (selector) => doc.querySelector(selector);
const $$ = (selector) => [...doc.querySelectorAll(selector)];
const cards = $$('#wsGrid .ws-card');

/* ── the day, at a glance ─────────────────────────────────────────────────── */
ok('the screen is titled', ($('.ws-title')?.textContent ?? '').includes('Reservation Workspace'));
ok('the board names its date', /November 2026/.test($('.ws-date-nav')?.textContent ?? ''));
ok('the day queues sit in the head', $$('.ws-head-stat').length === 4, `${$$('.ws-head-stat').length}`);
ok('arrivals and departures are counted', /Arrivals/.test($('.ws-head-stats')?.textContent ?? '')
  && /Departures/.test($('.ws-head-stats')?.textContent ?? ''));
ok('the KPI strip renders every tile', $$('.ws-kpi').length === 9, `${$$('.ws-kpi').length}`);
ok('each tile shows a sparkline', $$('.ws-kpi .ws-spark').length === 9);

/* ── the command bar ─────────────────────────────────────────────────────── */
ok('the command bar is above the board', Boolean($('#wsCmd')));
ok('search, filters and layout live together', Boolean($('#wsCmd #fSearch')) && Boolean($('#wsCmd #wsTabRack')));
ok('the board and rack are offered as two layouts', $$('[data-ws-view]').length === 2);
ok('the board is the default layout', $('#wsGrid')?.hidden === false && $('#wsRack')?.hidden === true);
ok('grouping is available', Boolean($('#wsGroupBy')));
ok('the keyboard hints are shown', Boolean($('.ws-keys')));

/* ── the board itself ────────────────────────────────────────────────────── */
ok('every room has a card', cards.length === 15, `${cards.length} cards`);
ok('cards carry the names the filters and rack need',
  cards.every(card => card.dataset.status && card.dataset.typeName && card.dataset.floorName && card.dataset.statusLabel));
ok('the board says how many rooms it is showing', /15 \/ 15 rooms/.test($('#wsVisibleCount')?.textContent ?? ''),
  $('#wsVisibleCount')?.textContent);

/* the card is a picture of the room, with the facts laid over it */
ok('each card opens with the room’s picture', cards.every(card => card.firstElementChild?.classList.contains('ws-photo')));
ok('the picture is actually a photograph',
  cards.every(card => /\/preview-assets\//.test(card.querySelector('.ws-photo-img')?.getAttribute('src') ?? '')),
  cards[0]?.querySelector('.ws-photo-img')?.getAttribute('src'));
ok('off-screen photographs are deferred', cards.every(card => card.querySelector('.ws-photo-img')?.getAttribute('loading') === 'lazy'));
ok('a room with no picture still gets its block', /\.ws-photo\{[^}]*background:#eef2f0/.test($('style')?.textContent ?? ''));
ok('the room number and status sit on the picture',
  cards.every(card => card.querySelector('.ws-photo .ws-card-head .ws-room-no')
    && card.querySelector('.ws-photo .ws-card-head .badge')));
ok('the picture carries the rate and the room facts',
  cards.every(card => card.querySelector('.ws-photo .ws-photo-rate') && card.querySelector('.ws-photo .ws-photo-meta')));
ok('the facts under the picture are the room’s own',
  (cards[0].querySelector('.ws-photo-meta')?.textContent ?? '').includes('m²')
    && /guests/.test(cards[0].querySelector('.ws-photo-meta')?.textContent ?? ''),
  cards[0].querySelector('.ws-photo-meta')?.textContent?.trim());
ok('the body holds the amenities, the guest and the actions',
  cards.every(card => card.querySelector('.ws-card-body .ws-amen')
    && card.querySelector('.ws-card-body .ws-guest')
    && card.querySelector('.ws-card-body .ws-actions')));
ok('the board gives the cards room to breathe', /\.ws-grid\{[^}]*minmax\(min\(348px/.test($('style')?.textContent ?? ''),
  ($('style')?.textContent ?? '').match(/\.ws-grid\{[^}]*\}/)?.[0]);

/* ── the needs-attention rail ────────────────────────────────────────────── */
const attention = $$('#wsAttention .ws-attention-row');
ok('the rail opens with what needs acting on', attention.length === 5, `${attention.length} rows`);
ok('each item carries its count', attention.every(row => /^\d+$/.test(row.querySelector('.ws-attention-n')?.textContent?.trim() ?? '')));
ok('the total is badged', ($('#wsAttention .ws-attention-count')?.textContent ?? '').trim() === '8',
  $('#wsAttention .ws-attention-count')?.textContent);
ok('the attention card comes before the calendar', $('#wsAttention')?.nextElementSibling
  ? /ws-cal|ws-rail-card/.test($('#wsAttention').nextElementSibling.className) : false);

/* ── filtering ───────────────────────────────────────────────────────────── */
const statusSelect = $('#fStatus');
statusSelect.value = 'occupied';
statusSelect.dispatchEvent(new window.Event('change'));
const visibleCards = cards.filter(card => !card.hidden);
ok('filtering actually hides cards', visibleCards.length === 5, `${visibleCards.length} visible`);
ok('filtering updates the count', /5 \/ 15 rooms/.test($('#wsVisibleCount')?.textContent ?? ''), $('#wsVisibleCount')?.textContent);
ok('the active filter becomes a chip', $$('#wsChips .ws-chip').length === 1 && /Occupied/.test($('#wsChips')?.textContent ?? ''),
  $('#wsChips')?.textContent);

const chipButton = $('#wsChips .ws-chip button');
chipButton?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('a chip can be removed', $('#fStatus')?.value === '', $('#fStatus')?.value);
ok('removing the chip restores the board', cards.every(card => !card.hidden));

/* ── search ──────────────────────────────────────────────────────────────── */
const search = $('#fSearch');
search.value = 'Osei';
search.dispatchEvent(new window.Event('input'));
ok('search narrows the board', cards.filter(card => !card.hidden).length === 1,
  `${cards.filter(card => !card.hidden).length} visible`);
search.value = '';
search.dispatchEvent(new window.Event('input'));

/* ── grouping ────────────────────────────────────────────────────────────── */
const groupBy = $('#wsGroupBy');
groupBy.value = 'floor';
groupBy.dispatchEvent(new window.Event('change'));
const groups = $$('#wsGrid .ws-group');
ok('grouping by floor builds a section per floor', groups.length === 3, `${groups.length} groups`);
ok('each group is titled', groups.every(group => (group.querySelector('.ws-group-head')?.textContent ?? '').trim().length > 0));
ok('the group counts add up to the board', groups.reduce((total, group) => total + Number(group.querySelector('.ws-group-count')?.textContent ?? 0), 0) === cards.length);
ok('no card is lost when grouping', $$('#wsGrid .ws-card').length === cards.length);
ok('the grouping is in the address bar', /(^|[?&])group=floor(&|$)/.test(window.location.search), window.location.search);
ok('the filters keep the layout and grouping',
  /view=|group=floor/.test(window.location.search) && !/group=$/.test(window.location.search));

groupBy.value = '';
groupBy.dispatchEvent(new window.Event('change'));
ok('turning grouping off returns the flat board', $$('#wsGrid .ws-group').length === 0);

/* ── the rack ────────────────────────────────────────────────────────────── */
$('#wsTabRack')?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('the rack replaces the board', $('#wsRack')?.hidden === false && $('#wsGrid')?.hidden === true);
ok('the layout is in the address bar', /(^|[?&])view=rack(&|$)/.test(window.location.search), window.location.search);
const rows = $$('#wsRackBody tr');
ok('every room has a rack row', rows.length === cards.length, `${rows.length} rows`);
ok('the rack shows the room number', (rows[0]?.querySelector('.ws-rack-room b')?.textContent ?? '').length === 3,
  rows[0]?.querySelector('.ws-rack-room b')?.textContent);
ok('the rack shows status, guest, rate and an action',
  Boolean(rows[0]?.querySelector('.badge')) && rows[0].querySelectorAll('td').length >= 7,
  `${rows[0]?.querySelectorAll('td').length} cells`);
ok('the rack can open a room', Boolean(rows[0]?.querySelector('.ws-room-open')));
ok('the rack shows the room’s picture too', rows.filter(row => row.querySelector('.ws-rack-thumb')).length === rows.length,
  `${rows.filter(row => row.querySelector('.ws-rack-thumb')).length} of ${rows.length} rows`);
ok('the rack thumbnail is the card’s own photograph',
  /url\(["']?\/preview-assets\//.test(rows[0]?.querySelector('.ws-rack-thumb')?.style.backgroundImage ?? ''),
  rows[0]?.querySelector('.ws-rack-thumb')?.style.backgroundImage);
ok('the rack headers sort', $$('.ws-rack-sort').length === 5, `${$$('.ws-rack-sort').length}`);

const rateHeader = $$('.ws-rack-sort').find(button => button.dataset.wsSort === 'rate');
rateHeader?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
const firstRate = Number(($$('#wsRackBody tr')[0]?.dataset.rate ?? '').replace(/[^\d.]/g, ''));
const rates = $$('#wsRackBody tr').map(row => Number((row.dataset.rate ?? '').replace(/[^\d.]/g, '')));
ok('sorting by rate orders the rack', firstRate === Math.min(...rates), `${firstRate} vs min ${Math.min(...rates)}`);
ok('the sorted column says so', rateHeader?.closest('th')?.getAttribute('aria-sort') === 'ascending',
  rateHeader?.closest('th')?.getAttribute('aria-sort'));
ok('the chosen order is remembered', window.sessionStorage.getItem('wsSort') === 'rate:asc',
  window.sessionStorage.getItem('wsSort'));

const statusHeader = $$('.ws-rack-sort').find(button => button.dataset.wsSort === 'room');
statusHeader?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('sorting by room starts at 101', /101/.test($$('#wsRackBody tr')[0]?.textContent ?? ''), $$('#wsRackBody tr')[0]?.textContent?.slice(0, 20));
ok('only one column is marked as sorted', $$('.ws-rack-table th[aria-sort]').length === 1,
  `${$$('.ws-rack-table th[aria-sort]').length}`);
rateHeader?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('a new column starts ascending', rateHeader?.closest('th')?.getAttribute('aria-sort') === 'ascending');
rateHeader?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('clicking the same header again reverses it', rateHeader?.closest('th')?.getAttribute('aria-sort') === 'descending'
  && Number(($$('#wsRackBody tr')[0]?.dataset.rate ?? '').replace(/[^\d.]/g, '')) === Math.max(...rates),
  rateHeader?.closest('th')?.getAttribute('aria-sort'));
statusHeader?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

/* the rack's action forms are copies — they still have to confirm the right thing */
const rackExtend = $$('#wsRackBody .ws-extend-form')[0];
ok('the rack carries the board\u2019s actions', Boolean(rackExtend));
if (rackExtend) {
  const dateInput = rackExtend.querySelector('[name="departure_date"]');
  dateInput.value = '2026-11-09';
  rackExtend.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  ok('extending from the rack names the date it will use',
    /2026-11-09|9 November|Nov 9/.test(rackExtend.dataset.confirm ?? ''), rackExtend.dataset.confirm);
}

statusSelect.value = 'reserved';
statusSelect.dispatchEvent(new window.Event('change'));
ok('the rack obeys the same filters', $$('#wsRackBody tr').filter(row => !row.hidden).length === 4,
  `${$$('#wsRackBody tr').filter(row => !row.hidden).length} visible rows`);
statusSelect.value = '';
statusSelect.dispatchEvent(new window.Event('change'));

/* ── keyboard ────────────────────────────────────────────────────────────── */
doc.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'b', bubbles: true }));
ok('B returns to the board', $('#wsGrid')?.hidden === false && $('#wsRack')?.hidden === true);
doc.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'r', bubbles: true }));
ok('R opens the rack', $('#wsRack')?.hidden === false);
search.value = 'zzz-not-a-room';
search.dispatchEvent(new window.Event('input', { bubbles: true }));
ok('a search that matches nothing says so', $('#wsNoResults')?.hidden === false);
search.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
ok('Escape clears the search first', search.value === '' && $('#wsNoResults')?.hidden === true,
  `value "${search.value}"`);
doc.dispatchEvent(new window.KeyboardEvent('keydown', { key: '/' }));
ok('slash focuses the search', doc.activeElement === search, doc.activeElement?.id);
doc.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'b' }));

/* ── the screen, not a mock-up of it ─────────────────────────────────────── */
ok('the stylesheet is linked', $$('link[rel="stylesheet"]').some(link => /app\.css/.test(link.getAttribute('href') ?? '')));
ok('the page carries its own styles', /\.ws-rack-table\{/.test($('style')?.textContent ?? ''));
ok('a printed rack fits the page', /\.ws-rack-table\{min-width:0/.test($('style')?.textContent ?? ''));
ok('hidden rooms are really hidden by the stylesheet',
  /\.ws-card\[hidden\]/.test($('style')?.textContent ?? ''));
ok('the page carries its own script', /applyWsFilters/.test($('script')?.textContent ?? ''));
ok('the sidebar is around it', Boolean($('aside.sidebar')) && Boolean($('.page-head h1')));
ok('no preview furniture on the screen',
  !/sample|preview|illustrative|not linked|static screen/i.test($('main')?.textContent ?? ''));

/* ── what a screen reader gets ───────────────────────────────────────────── */
const controls = [...doc.querySelectorAll('button, a[href], [role="button"]')];
const named = (element) => {
  const label = (element.getAttribute('aria-label') || '').trim();
  if (label) return true;
  if (element.getAttribute('aria-labelledby')) return true;
  const text = (element.textContent || '').replace(/\s+/g, ' ').trim();
  return text.length > 0;
};
const anonymous = controls.filter(control => !named(control));
ok('every control has a name', anonymous.length === 0,
  anonymous.slice(0, 3).map(node => node.outerHTML.slice(0, 70)).join(' · '));
ok('every picture is described or marked decorative',
  $$('#wsGrid img').every(img => img.hasAttribute('alt')));
ok('the housekeeping badges are read out in full',
  $$('.ws-hk-badge').every(badge => /Current .*housekeeping/i.test(badge.textContent.replace(/\s+/g, ' '))
    || Boolean(badge.getAttribute('title'))));
ok('the sortable rack headers say what they sort by',
  $$('.ws-rack-sort').every(button => (button.textContent || '').trim().length > 0));

/* ── the copy says the right number ──────────────────────────────────────── */
const pluralTrap = () => {
  const said = [doc.body.textContent, ...[...doc.querySelectorAll('[aria-label], [title]')]
    .flatMap(node => [node.getAttribute('aria-label'), node.getAttribute('title')])].join(' ');
  return said.match(/\b1 (guests|rooms|nights|adults|persons|photos|stays|days)\b/gi) ?? [];
};
ok('nothing says "1 guests"', pluralTrap().length === 0, pluralTrap().slice(0, 3).join(', '));

const single = cards.find(card => (card.getAttribute('data-room') === '304')
  || Boolean(card.querySelector('[data-room="304"]')));
ok('a single room reads "1 guest"',
  /\b1 guest\b/.test(single?.querySelector('.ws-photo-meta')?.textContent ?? '')
    && !/1 guests/.test(single?.querySelector('.ws-photo-meta')?.textContent ?? ''),
  single?.querySelector('.ws-photo-meta')?.textContent?.replace(/\s+/g, ' ').trim());

/* ── the modals the board opens ──────────────────────────────────────────── */
ok('every room has a details dialog', $$('.ws-modal').length === cards.length, `${$$('.ws-modal').length}`);
$('#wsTabBoard')?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
$('.ws-photo-link')?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('opening a room shows its dialog', $$('.ws-modal.show').length === 1);
ok('the dialog names the room', /Room|Deluxe|Standard/.test($('.ws-modal.show')?.textContent ?? ''));
ok('the dialog shows the room’s picture', /url\(["']?\/preview-assets\//.test($('.ws-modal.show .ws-modal-hero')?.style.background ?? ''),
  $('.ws-modal.show .ws-modal-hero')?.style.background?.slice(0, 60));
ok('the focus ring sits inside the picture', /outline-offset:-4px/.test($('style')?.textContent ?? ''));

/* ── coming back to the screen ───────────────────────────────────────────── */
// A desk keeps its layout and its order between refreshes; the page stores both.
const reopened = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously',
  pretendToBeVisual: true,
  beforeParse(instance) {
    instance.sessionStorage.setItem('wsSort', 'rate:desc');
  },
  url: 'https://preview.local/reservations/workspace?date=2026-11-05&view=rack&group=floor',
});
const again = reopened.window.document;
const reopenedRates = [...again.querySelectorAll('#wsRackBody tr')]
  .map(row => Number((row.dataset.rate ?? '').replace(/[^\d.]/g, '')));
ok('the saved order comes back', again.querySelector('#wsRack')?.hidden === false
  && reopenedRates[0] === Math.max(...reopenedRates), `${reopenedRates[0]} of ${reopenedRates.length} rows`);
ok('a link opens the rack it points at', again.querySelector('#wsRack')?.hidden === false
  && again.querySelector('#wsTabRack')?.getAttribute('aria-selected') === 'true');
ok('a link opens the grouping it points at', again.querySelectorAll('#wsGrid .ws-group').length === 3,
  `${again.querySelectorAll('#wsGrid .ws-group').length} groups`);
const reopenedNav = [...again.querySelectorAll('a[data-ws-nav]')];
ok('changing the date keeps the layout and grouping',
  reopenedNav.length > 0 && reopenedNav.every(link => /view=rack/.test(link.getAttribute('href') ?? '')
    && /group=floor/.test(link.getAttribute('href') ?? '')),
  reopenedNav[0]?.getAttribute('href'));
ok('the saved order comes back marked on its column',
  again.querySelector('.ws-rack-sort[data-ws-sort="rate"]')?.closest('th')?.getAttribute('aria-sort') === 'descending');
reopened.window.close();

// A pasted link filters the board; an invalid value clears the filter instead of
// quietly emptying it.
const linked = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously', pretendToBeVisual: true,
  url: 'https://preview.local/reservations/workspace?date=2026-11-05&status=occupied&hk=dirty',
});
const linkedDoc = linked.window.document;
ok('a pasted link sets the filters',
  linkedDoc.querySelector('#fStatus').value === 'occupied' && linkedDoc.querySelector('#fHousekeeping').value === 'dirty');
ok('a pasted link shows its chips', linkedDoc.querySelectorAll('#wsChips .ws-chip').length === 2,
  `${linkedDoc.querySelectorAll('#wsChips .ws-chip').length} chips`);
ok('a pasted link filters the board',
  [...linkedDoc.querySelectorAll('#wsGrid .ws-card')].filter(card => !card.hidden).length < linkedDoc.querySelectorAll('#wsGrid .ws-card').length);
linked.window.close();

// A link that asks for the rack, a filter and a grouping at once has to honour all three
const compound = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously', pretendToBeVisual: true,
  url: 'https://preview.local/reservations/workspace?date=2026-11-05&view=rack&status=occupied&group=floor',
});
const compoundDoc = compound.window.document;
const compoundRows = [...compoundDoc.querySelectorAll('#wsRackBody tr')];
ok('a link can ask for the rack, a filter and a grouping at once',
  compoundDoc.querySelector('#wsRack').hidden === false && compoundDoc.querySelectorAll('#wsGrid .ws-group').length === 3);
ok('the rack obeys the filter on first paint',
  compoundRows.filter(row => !row.hidden).length
    === [...compoundDoc.querySelectorAll('#wsGrid .ws-card')].filter(card => !card.hidden).length,
  `${compoundRows.filter(row => !row.hidden).length} rows`);
ok('the group counts match the filtered board',
  [...compoundDoc.querySelectorAll('#wsGrid .ws-group-count')].reduce((total, node) => total + Number(node.textContent), 0)
    === [...compoundDoc.querySelectorAll('#wsGrid .ws-card')].filter(card => !card.hidden).length);
compound.window.close();

const bogus = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously', pretendToBeVisual: true,
  url: 'https://preview.local/reservations/workspace?date=2026-11-05&status=banana',
});
const bogusDoc = bogus.window.document;
ok('a filter value that does not exist is ignored',
  bogusDoc.querySelector('#fStatus').value === ''
    && [...bogusDoc.querySelectorAll('#wsGrid .ws-card')].every(card => !card.hidden));
bogus.window.close();

// No link, no session — a plain reload still reopens the layout last used.
const remembered = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously',
  url: 'https://preview.local/reservations/workspace?date=2026-11-05',
  pretendToBeVisual: true,
  beforeParse(instance) { instance.sessionStorage.setItem('wsView', 'rack'); },
});
const rememberedDoc = remembered.window.document;
ok('a plain reload reopens the layout last used', rememberedDoc.querySelector('#wsRack')?.hidden === false
  && rememberedDoc.querySelector('#wsGrid')?.hidden === true);
remembered.window.close();

if (failures.length) {
  console.log(`workspace preview check: ${failures.length} FAILED, ${passed} passed`);
  failures.forEach(failure => console.log(`  ✗ ${failure}`));
  process.exit(1);
}
console.log(`workspace preview check: ${passed} assertions passed`);
window.close();
