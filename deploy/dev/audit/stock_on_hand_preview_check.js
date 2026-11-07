#!/usr/bin/env node
/*
 * Stock on hand, read as three questions.
 *
 * The screen listed a quantity per store and a value column: it answered "how many" and never "what is this worth, where
 * is it sitting, and what has stopped moving". v9.23 puts the three readings on the page and this check holds each one to
 * the item table underneath it:
 *
 *   1. the shelf — 14 items holding 90,374.50 at last cost, diesel 43,632.00 of it (48.3 %), the three largest lines
 *      74.9 %, and one item carrying a negative quantity;
 *   2. the split — the Main Store carries 61,166.50 of it (67.7 %), four items sit in more than one store, and the store
 *      values add back to the shelf total;
 *   3. the age — four items worth 10,440.00 have not been drawn from in ninety days, 11.6 % of the shelf, the oldest
 *      the Christmas decoration set at 322 days;
 *   4. the shelf list itself — its columns, per-store cells, total, value and last movement;
 *   5. the drift build — the store tidied up, so the ageing card empties to one item and the value falls with it.
 *
 * Renders deploy/dev/previews/stock-on-hand.html and stock-on-hand-drift.html in jsdom against
 * deploy/dev/preview/stock-sample.mjs.
 *
 * Run: node deploy/dev/audit/stock_on_hand_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const load = (file, url) => new JSDOM(readFileSync(file, 'utf8'), { url }).window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const num = (node) => Number((((text(node).match(/[−-]?[\d,]+\.?\d*/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);
const pctOf = (node) => Number((text(node).match(/([\d.]+)\s*%/) ?? [0, 0])[1]);
const round2 = (v) => Math.round(v * 100) / 100;

const doc = load('deploy/dev/previews/stock-on-hand.html', 'https://preview.local/inventory');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the header ─────────────────────────────────────────────────────────── */
const head = [...$$('.stats.wide')[0].children].map(s => text(s));
ok('the header carries five figures', head.length === 5, `${head.length}`);
ok('the items on hand are counted with the ones at zero', /^14/.test(head[0]) && /14 items with stock, 1 at zero/.test(head[0]), head[0]);
ok('the value on the shelf is the figure the whole screen turns on', /GH₵ 90,374\.50/.test(head[1])
  && /the figure the counts and the ledger are both checking/.test(head[1]), head[1]);
ok('the stores holding it are counted', /^4/.test(head[2]) && /held in more than one store/.test(head[2]), head[2]);
ok('the largest holding is named with its quantity and its share',
  /GH₵ 43,632\.00/.test(head[3]) && /Diesel — 1,818 L, 48\.3 % of the shelf/.test(head[3]), head[3]);
ok('and what has stopped moving is on the header, in money', /^4/.test(head[4]) && /11\.6 % of the value on the shelf/.test(head[4]), head[4]);

/* ── the shelf ──────────────────────────────────────────────────────────── */
const shelf = $('#shShelf');
ok('the page asks what is on the shelf tonight', /What is on the shelf tonight\?/.test(text(shelf)));
ok('the head totals the shelf and names the concentration',
  /14 items · GH₵ 90,374\.50 · the three largest are 74\.9 % of it/.test(text(shelf.querySelector('.card-head'))), text(shelf.querySelector('.card-head')));
const sStats = [...shelf.querySelectorAll('.stat')].map(s => text(s));
ok('stock value is the first figure', /Stock value/.test(sStats[0]) && /90,374\.50/.test(sStats[0]), sStats[0]);
ok('items held is the second, with the ones at zero excluded', /Items held/.test(sStats[1]) && /^14/.test(sStats[1])
  && /1 active item at zero/.test(sStats[1]), sStats[1]);
ok('the top three lines are stated as a share, because that is the risk', /Top three items/.test(sStats[2]) && /74\.9 %/.test(sStats[2]), sStats[2]);
ok('an item below zero is on the card, not left in the table', /Below zero/.test(sStats[3]) && /^1/.test(sStats[3])
  && shelf.querySelectorAll('.stat')[3].classList.contains('tone-red'), sStats[3]);
ok('the verdict names the largest holding with its store and its share',
  /The largest holding is Diesel: 1,818 L in the Main Store, GH₵ 43,632\.00 — 48\.3 % of everything on the shelf\./.test(text($('#shShelfState'))),
  text($('#shShelfState')));
ok('and says why units are not added across items',
  /Units are not added across items, because a litre of diesel and a piece of towel are not the same thing/.test(text($('#shShelfState'))));
ok('and why a stale cost is the safe direction to be wrong in',
  /a stale cost understates the shelf rather than overstating it/.test(text($('#shShelfState'))));
const sCols = [...$$('#shelfTable thead th')].map(th => text(th));
ok('the shelf is five columns', sCols.join('|') === 'Item|Category|Quantity|Value|Share', sCols.join('|'));
const sRows = [...$$('#shelfTable tbody tr')].map(r => ({ item: text(r.children[0]), qty: text(r.children[2]),
  value: num(r.children[3]), share: pctOf(r.children[4]), attr: Number(r.getAttribute('data-value')) }));
ok('the holdings are listed largest first', sRows.length === 8 && sRows[0].item === 'Diesel' && sRows[1].item === 'Bath towel',
  sRows.map(r => r.item).join(','));
ok('the value descends down the table', sRows.every((r, i) => i === 0 || r.value <= sRows[i - 1].value));
ok('each share is its value against the shelf total', sRows.every(r => Math.abs(r.share - Math.round(r.value / 90374.5 * 1000) / 10) < 0.05),
  sRows.map(r => `${r.item}:${r.share}`).join(' '));
ok('the row data carries the same value the column shows', sRows.every(r => r.attr === r.value));
ok('the quantity is printed with its unit', /^1,818 L$/.test(sRows[0].qty), sRows[0].qty);

/* ── the split ──────────────────────────────────────────────────────────── */
const where = $('#shWhere');
ok('the page asks where the value sits', /Where does the value sit\?/.test(text(where)));
const wStats = [...where.querySelectorAll('.stat')].map(s => text(s));
ok('the heaviest store is named with its item count and its share',
  /Heaviest store/.test(wStats[0]) && /61,166\.50/.test(wStats[0]) && /Main Store — 9 items, 67\.7 % of the value/.test(wStats[0]), wStats[0]);
ok('items split across stores are counted, because they are worth moving before buying',
  /Split across stores/.test(wStats[1]) && /^4/.test(wStats[1]), wStats[1]);
ok('the stores on the list are counted', /Stores counted/.test(wStats[2]) && /^4/.test(wStats[2]), wStats[2]);
ok('and the store total is the same number the shelf card prints', /Against the book/.test(wStats[3]) && /90,374\.50/.test(wStats[3]), wStats[3]);
ok('the verdict puts the largest store against the whole shelf',
  /The Main Store carries GH₵ 61,166\.50 of the GH₵ 90,374\.50 on the shelf, 67\.7 % of it across 9 items\./.test(text($('#shWhereState'))),
  text($('#shWhereState')));
ok('and says a store is worth what will be used from it', /A store is not worth what it holds but what it holds that the hotel will use/.test(text($('#shWhereState'))));
const wCols = [...$$('#storeTable thead th')].map(th => text(th));
ok('the split is four columns', wCols.join('|') === 'Store|Items|Value|Share', wCols.join('|'));
const wRows = [...$$('#storeTable tbody tr')].map(r => ({ store: text(r.children[0]), items: num(r.children[1]),
  value: num(r.children[2]), share: pctOf(r.children[3]) }));
ok('every store with stock is listed, heaviest first', wRows.length === 4 && wRows.map(r => r.store).join(',')
  === 'Main Store,Housekeeping Store,Kitchen Store,Engineering Store', wRows.map(r => r.store).join(','));
ok('the store values add back to the shelf total', round2(wRows.reduce((t, r) => t + r.value, 0)) === 90374.5);
ok('and the shares add to a hundred, to the rounding', Math.abs(wRows.reduce((t, r) => t + r.share, 0) - 100) < 0.3, wRows.map(r => r.share).join('+'));

/* ── the age ────────────────────────────────────────────────────────────── */
const still = $('#shStill');
ok('the page asks what has stopped moving', /What has stopped moving\?/.test(text(still)));
ok('the head counts the items, the money and the share',
  /4 items untouched for ninety days · GH₵ 10,440\.00 · 11\.6 % of the value on the shelf/.test(text(still.querySelector('.card-head'))),
  text(still.querySelector('.card-head')));
const aStats = [...still.querySelectorAll('.stat')].map(s => text(s));
ok('the sleeping items are counted with the date the clock started',
  /Asleep/.test(aStats[0]) && /^4/.test(aStats[0]) && /not drawn from since 09 Aug 2026/.test(aStats[0]), aStats[0]);
ok('the money asleep is stated as capital held for no reason',
  /Value asleep/.test(aStats[1]) && /10,440\.00/.test(aStats[1]) && /capital a store is holding for no reason/.test(aStats[1]), aStats[1]);
ok('items that never moved at all are counted separately', /Never moved/.test(aStats[2]) && /^0/.test(aStats[2]), aStats[2]);
ok('the oldest is aged and named', /Oldest/.test(aStats[3]) && /322 d/.test(aStats[3])
  && /Christmas decoration set — 12 sets, GH₵ 2,160\.00/.test(aStats[3]), aStats[3]);
ok('the verdict names it with its last movement date and its age',
  /The oldest is Christmas decoration set: 12 sets worth GH₵ 2,160\.00, last touched 20 Dec 2025 — 322 days ago\./.test(text($('#shStillState'))),
  text($('#shStillState')));
ok('and says why nothing on the re-order screen would ever show it',
  /Nothing here is a re-order problem; it is the opposite one/.test(text($('#shStillState'))));
ok('and what would actually move it', /only an ageing write-down or a transfer moves it/.test(text($('#shStillState'))));
const aCols = [...$$('#stillTable thead th')].map(th => text(th));
ok('the ageing list is five columns', aCols.join('|') === 'Item|Quantity|Value|Last movement|Days', aCols.join('|'));
const aRows = [...$$('#stillTable tbody tr')].map(r => ({ item: text(r.children[0]), value: num(r.children[2]),
  date: text(r.children[3]), days: num(r.children[4]), attr: Number(r.getAttribute('data-days')) }));
ok('every sleeping item is listed, most valuable first', aRows.length === 4
  && aRows.map(r => r.item).join(',') === 'Banqueting chair cover,Christmas decoration set,Pool chlorine,Printed menu folder',
  aRows.map(r => r.item).join(','));
ok('the rows add to the money the card states', round2(aRows.reduce((t, r) => t + r.value, 0)) === 10440);
ok('nothing on the list is younger than ninety days', aRows.every(r => r.days >= 90), aRows.map(r => r.days).join(','));
ok('the ages match the days since the last movement', aRows.find(r => r.item === 'Pool chlorine').days === 97
  && aRows.find(r => r.item === 'Pool chlorine').date === '02 Aug 2026');
ok('the row data carries the same age the column shows', aRows.every(r => r.attr === r.days));

/* ── the shelf list ─────────────────────────────────────────────────────── */
const cols = [...$$('#stockTable thead th')].map(th => text(th));
ok('the stock list keeps a column per store plus total, reorder, value and the last movement',
  cols.length === 12 && cols.slice(-4).join('|') === 'Total|Reorder|Value|Last moved'
  && cols.slice(4, 8).join('|') === 'Engineering Store|Housekeeping Store|Kitchen Store|Main Store', cols.join('|'));
const rows = [...$$('#stockTable tbody tr')].map(r => ({ code: text(r.children[0]), item: text(r.children[1]),
  eng: text(r.children[4]), house: text(r.children[5]), kitchen: text(r.children[6]), main: text(r.children[7]),
  total: num(r.children[8]), value: num(r.children[10]), last: text(r.children[11]), attr: Number(r.getAttribute('data-total')) }));
ok('every active line is on the list, at zero included', rows.length === 15, `${rows.length}`);
ok('an empty store cell is a dash, not a nought', rows[0].eng === '—' && rows[0].house === '276', `${rows[0].eng}/${rows[0].house}`);
ok('an item held in two stores shows it in both cells',
  rows.find(r => r.item === 'Diesel').eng === '60' && rows.find(r => r.item === 'Diesel').main === '1,758',
  `${rows.find(r => r.item === 'Diesel').eng}/${rows.find(r => r.item === 'Diesel').main}`);
ok('the total is the sum of the store cells it sits beside',
  rows.every(r => r.total === [r.eng, r.house, r.kitchen, r.main].reduce((t, c) => t + (c === '—' ? 0 : Number(c.replace(/,/g, ''))), 0)),
  rows.map(r => `${r.item}:${r.total}`).join(' '));
ok('the row data carries the same total the column shows', rows.every(r => r.attr === r.total));
ok('the value column is the total at the item cost', rows.find(r => r.item === 'Diesel').value === 43632
  && rows.find(r => r.item === 'Rice').value === 5652.5);
ok('an item below zero is red on the list as well as on the card',
  rows.find(r => r.item === 'Engine oil').total === -6
  && $$('#stockTable tbody tr').find(r => text(r.children[1]) === 'Engine oil').children[8].classList.contains('text-red'));
ok('an item at zero says so and says it never moved',
  rows.find(r => r.item === 'Chafing fuel').total === 0 && rows.find(r => r.item === 'Chafing fuel').last === 'never');
ok('every other item carries the date it last moved', rows.filter(r => r.item !== 'Chafing fuel').every(r => /\d{2} \w{3} \d{4}/.test(r.last)));
ok('the filters are still on the page', $$('.filters input[name="q"]').length === 1 && $$('.filters select[name="category"]').length === 1
  && $$('.filters input[name="low"]').length === 1);
ok('and the list says how many lines the readings were taken from',
  /15 lines · the three readings above are worked out from these quantities/.test(text($('#stockTable').closest('.card').querySelector('.card-head'))));

/* ── the loop behind the shelf ──────────────────────────────────────────── */
ok('the page says where the four other screens meet',
  /Stock on hand is where the other four screens meet/.test(text($('#shChain')))
  && /receipt/.test(text($('#shChain'))) && /issue/.test(text($('#shChain')))
  && /ledger/.test(text($('#shChain'))) && /stock take/.test(text($('#shChain'))), text($('#shChain')));
ok('and asks the question that comes before the re-order screen',
  /what is already here, and what is it worth/.test(text($('#shChain'))));

/* ── the drift build ────────────────────────────────────────────────────── */
const drift = load('deploy/dev/previews/stock-on-hand-drift.html', 'https://preview.local/inventory');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats.wide')[0].children].map(s => dText(s));
const dStill = [...drift.querySelectorAll('#stillTable tbody tr')];
ok('the drift has one item fewer on the shelf, and two at zero', /^13/.test(dHead[0]) && /2 at zero/.test(dHead[0]), dHead[0]);
ok('the value falls by exactly what left it', /GH₵ 83,366\.50/.test(dHead[1]), dHead[1]);
ok('and the ageing card is down to one item', /^1/.test(dHead[4]) && /2\.6 % of the value on the shelf/.test(dHead[4]), dHead[4]);
ok('which is the decoration set, not the chair covers', dStill.length === 1 && dText(dStill[0]).includes('Christmas decoration set'),
  dStill.map(r => dText(r)).join(' | '));
ok('the concentration rises as the same few lines carry more of a smaller shelf',
  /the three largest are 81\.2 % of it/.test(dText(drift.querySelector('#shShelf .card-head'))),
  dText(drift.querySelector('#shShelf .card-head')));
ok('the stores still add back to the new total',
  round2([...drift.querySelectorAll('#storeTable tbody tr')].reduce((t, r) => t + Number(dText(r.children[2]).replace(/,/g, '')), 0)) === 83366.5);
ok('the chair covers are out of the shelf list but still on it at nothing', (() => {
  const r = [...drift.querySelectorAll('#stockTable tbody tr')].find(x => dText(x.children[1]) === 'Banqueting chair cover');

  return r && dText(r.children[8]) === '0' && dText(r.children[10]) === '0.00';
})());
ok('the chlorine is drawn down and nothing else about the shelf moves', (() => {
  const r = [...drift.querySelectorAll('#stockTable tbody tr')].find(x => dText(x.children[1]) === 'Pool chlorine');

  return r && dText(r.children[8]) === '18' && dText(r.children[11]) === '05 Nov 2026';
})());

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|endforelse|php|endphp|unless|endunless|error|enderror)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder or leaked attribute`, !/\{\{|\}\}|:sub=|:tone=/.test(clean));
  ok(`${name} holds no unpluralised shorthand`, !/item\(s\)|store\(s\)|line\(s\)|row\(s\)/.test(clean));
}

if (failures.length) {
  console.error(`stock on hand check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`stock on hand check: ${passed}/${passed} passed`);
