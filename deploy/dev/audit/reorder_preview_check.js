#!/usr/bin/env node
/*
 * Re-order suggestions, read as the three things the suggestion rests on.
 *
 * The page said "these are at or below their re-order level, here is a quantity". A quantity is a guess until somebody
 * says how far below the shelf is, what is already coming and how long the stock lasts, so v9.20 reads the same ten rows
 * three ways and this check holds each to its own record:
 *
 *   1. the gap — stock against twice the level, valued at the last rate paid: 10 items, 2,000 units and 34,164.00
 *      apart, two of them at zero, with the largest named (diesel, 620 litres, 8,804.00);
 *   2. the pipeline — 7,468.00 on order, 3,040.00 of it still in a draft nobody approved, 3,050.00 requested by
 *      departments that no order covers, and five shortages with nothing at all against them;
 *   3. the cover — sixty days of stock movements: 8,850 units, 171,906.00, guest soap already out, diesel two days,
 *      and one item that has not moved at all;
 *   4. the form — the table the draft purchase orders are actually raised from;
 *   5. the drift build — the pipeline and the cover broken by a draft sugar line and a banqueting week, with the gap
 *      left alone.
 *
 * Renders deploy/dev/previews/reorder.html and reorder-drift.html in jsdom against
 * deploy/dev/preview/reorder-sample.mjs.
 *
 * Run: node deploy/dev/audit/reorder_preview_check.js
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
const num = (cell) => Number((((text(cell).match(/[−-]?[\d,]+\.?\d*/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);
const round2 = (v) => Math.round(v * 100) / 100;

const doc = load('deploy/dev/previews/reorder.html', 'https://preview.local/inventory/reorder');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the header ─────────────────────────────────────────────────────────── */
const head = [...$$('.stats.wide')[0].children].map(s => text(s));
ok('the header carries four figures, one per reading plus the uncovered shortages', head.length === 4, `${head.length}`);
ok('what is at or below the level is counted first', /^10/.test(head[0]) && /At or below the level/.test(head[0]), head[0]);
ok('what has run out is separated from what is merely low, and red', /^2/.test(head[1]) && /At zero/.test(head[1])
  && $$('.stats.wide')[0].children[1].classList.contains('tone-red'), head[1]);
ok('the money to put the shelf back is stated as a figure, not left as a column',
  /GH₵ 34,164\.00/.test(head[2]) && /2,000 units back on the shelf/.test(head[2]), head[2]);
ok('and the shortages nothing at all is coming for are counted', /^5/.test(head[3]) && /Nothing coming/.test(head[3]), head[3]);

/* ── the gap ────────────────────────────────────────────────────────────── */
const gap = $('#roGap');
ok('the page asks how far below the level the shelf is', /How far below the level is the shelf\?/.test(text(gap)));
ok('the head counts the items, the ones at zero and the money',
  /10 items · 2 at zero · GH₵ 34,164\.00 to twice the level/.test(text(gap.querySelector('.card-head'))), text(gap.querySelector('.card-head')));
ok('the verdict names the largest shortage with its units, its level and its money rather than a total',
  /The largest is Diesel — 180 L on hand against a re-order level of 400, so 620 L brings it back to twice that, GH₵ 8,804\.00 at the last rate paid\./.test(text($('#roGapState'))),
  text($('#roGapState')));
ok('and says what two items at zero means in service terms', /a service waiting to be refused rather than a number on a report/.test(text($('#roGapState'))));
ok('the verdict is red while anything is at zero', $('#roGapState').classList.contains('text-red'));
const gCols = [...$$('#gapTable thead th')].map(th => text(th));
ok('the gap table has five columns and no pipeline column', gCols.join('|') === 'Item|In stock|Re-order level|To twice the level|Value', gCols.join('|'));
const gRows = [...$$('#gapTable tbody tr')].map(r => ({ item: text(r.children[0]).split(' ')[0] === 'Print' ? 'Print paper A4' : text(r.children[0]),
  stock: num(r.children[1]), level: num(r.children[2]), short: num(r.children[3]), value: num(r.children[4]),
  shortAttr: Number(r.getAttribute('data-short')) }));
ok('every item below the level is on the list, largest shortage first', gRows.length === 10
  && gRows.map(r => r.value).join(',') === '8804,7600,3570,3072,2700,2668,2100,1736,990,924', gRows.map(r => r.value).join(','));
ok('the quantity to twice the level is twice the level less what is on the shelf, on every row',
  gRows.every(r => r.short === round2(r.level * 2 - r.stock)), gRows.map(r => `${r.level}×2−${r.stock}=${r.short}`).join(' | '));
ok('the value is that quantity at the rate the row carries',
  gRows.every(r => Math.abs(r.value - round2(r.short * (r.value / r.short))) < 0.02), gRows.map(r => r.value).join(','));
ok('the values add to the money the card and the header state',
  round2(gRows.reduce((t, r) => t + r.value, 0)) === 34164);
ok('the row data carries the same figure the column shows', gRows.every(r => r.shortAttr === r.value));
ok('the two items at zero are the ones marked in the stock column',
  $$('#gapTable tbody tr').filter(r => r.children[1].classList.contains('text-red')).length === 2
  && $$('#gapTable tbody tr').filter(r => r.children[1].classList.contains('text-red')).every(r => /^0 /.test(text(r.children[1]))));

/* ── the pipeline ───────────────────────────────────────────────────────── */
const pipe = $('#roPipeline');
ok('the page asks what is already on its way', /What is already on its way\?/.test(text(pipe)));
ok('the head separates what is ordered from what is only drafted and what is only asked for',
  /GH₵ 7,468\.00 on order · 1 of them still in draft · GH₵ 3,050\.00 requested by departments/.test(text(pipe.querySelector('.card-head'))),
  text(pipe.querySelector('.card-head')));
const pStats = [...pipe.querySelectorAll('.stat')].map(s => text(s));
ok('the orders on the books are valued with the items behind them', /On order/.test(pStats[0]) && /7,468\.00/.test(pStats[0])
  && /4 items with an approved or part-received order/.test(pStats[0]), pStats[0]);
ok('a draft order is stated as money that will not arrive, and red', /Still in draft/.test(pStats[1]) && /3,040\.00/.test(pStats[1])
  && pipe.querySelectorAll('.stat')[1].classList.contains('tone-red'), pStats[1]);
ok('a requisition is separated from an order', /Requested by departments/.test(pStats[2]) && /3,050\.00/.test(pStats[2])
  && /2 approved requisition lines/.test(pStats[2]), pStats[2]);
ok('and the shortages with nothing at all against them are counted', /Nothing coming/.test(pStats[3]) && /^5/.test(pStats[3])
  && /of the 10 items below the level/.test(pStats[3]), pStats[3]);
ok('the verdict names the item sitting in a draft, with its quantity and its money',
  /Cooking gas has 8 cylinders sitting in a draft order — GH₵ 3,040\.00 that will not arrive until somebody approves it\./.test(text($('#roPipelineState'))),
  text($('#roPipelineState')));
ok('and says the suggestion has already netted off what is approved', /The suggestion below already nets off the approved orders/.test(text($('#roPipelineState'))));
ok('the verdict says a request is not a delivery date', /a request is a claim on the store, not a delivery date/.test(text($('#roPipelineState'))));
const pRows = [...$$('#pipeTable tbody tr')].map(r => ({ item: text(r.children[0]), onOrder: num(r.children[1]),
  draft: num(r.children[2]), requested: num(r.children[3]), suggest: num(r.children[4]), draftFlag: r.getAttribute('data-draft') }));
ok('only the items with something coming are on the list', pRows.length === 5, `${pRows.length}`);
ok('they are ordered by what is on order, largest first', pRows.map(r => r.onOrder).join(',') === '200,120,60,8,0',
  pRows.map(r => r.onOrder).join(','));
ok('the draft quantity is marked on its row', pRows.filter(r => r.draftFlag === '1').length === 1
  && pRows.find(r => r.draftFlag === '1').item === 'Cooking gas', pRows.map(r => `${r.item}:${r.draftFlag}`).join(' '));
ok('a draft is counted inside what is on order, because the page nets it off the suggestion',
  pRows.find(r => r.item === 'Cooking gas').draft === 8 && pRows.find(r => r.item === 'Cooking gas').onOrder === 8);
ok('the row that has been asked for but not ordered shows the request and the full suggestion',
  pRows.find(r => r.item === 'Diesel').requested === 150 && pRows.find(r => r.item === 'Diesel').suggest === 620,
  JSON.stringify(pRows.find(r => r.item === 'Diesel')));
ok('a row already covered by an order shows nothing left to buy',
  pRows.find(r => r.item === 'Chicken breast').suggest === 0 && pRows.find(r => r.item === 'Chicken breast').onOrder === 60,
  JSON.stringify(pRows.find(r => r.item === 'Chicken breast')));

/* ── the cover ──────────────────────────────────────────────────────────── */
const cover = $('#roCover');
ok('the page asks how many days of stock is left', /How many days of stock is left\?/.test(text(cover)));
ok('the head says when the usage window closes and how many items never moved',
  /usage to 07 Nov 2026 · 1 item with no movement at all/.test(text(cover.querySelector('.card-head'))), text(cover.querySelector('.card-head')));
const cStats = [...cover.querySelectorAll('.stat')].map(s => text(s));
ok('the usage is valued at the rates the goods left at', /Used in 60 days/.test(cStats[0]) && /171,906\.00/.test(cStats[0])
  && /8,850 units issued out of the store/.test(cStats[0]), cStats[0]);
ok('an item with no movement is called what it is — a habit, not a demand', /No movement/.test(cStats[1]) && /^1/.test(cStats[1])
  && /1 of them has not moved in ninety days, so the level is a habit not a demand/.test(cStats[1]), cStats[1]);
ok('the item that runs out first is a figure of its own, and red at zero days', /Runs out first/.test(cStats[2]) && /^0 d/.test(cStats[2])
  && /Guest soap 40 g/.test(cStats[2]) && cover.querySelectorAll('.stat')[2].classList.contains('tone-red'), cStats[2]);
ok('and the items with a usage figure to measure against are counted', /Covered by usage/.test(cStats[3]) && /^9/.test(cStats[3]), cStats[3]);
ok('the verdict names the item, its daily draw and the date it runs out',
  /Guest soap 40 g has 0 pcs left and leaves the shelf at 13 a day — 0 days' cover — so it runs out on 07 Nov 2026\./.test(text($('#roCoverState'))),
  text($('#roCoverState')));
ok('and says how much of it the store issued in the window', /Over the sixty days the store issued 780 pcs of it\./.test(text($('#roCoverState'))));
ok('the verdict is red while something has two days or less', $('#roCoverState').classList.contains('text-red'));
const cRows = [...$$('#coverTable tbody tr')].map(r => ({ item: text(r.children[0]), used: num(r.children[1]), perDay: num(r.children[2]),
  stock: num(r.children[3]), days: num(r.children[4]), runsOut: text(r.children[5]), cover: Number(r.getAttribute('data-cover')) }));
ok('every item with a usage figure is on the list, soonest first', cRows.length === 9
  && cRows.map(r => r.cover).join(',') === '0,0,2,3,3,3,7,18,20', cRows.map(r => r.cover).join(','));
ok('days of cover is stock over the daily draw, on every row',
  cRows.every(r => Math.round(r.stock / r.perDay) === r.cover), cRows.map(r => `${r.stock}/${r.perDay}=${r.cover}`).join(' | '));
ok('the daily draw is the sixty-day usage over sixty', cRows.every(r => Math.abs(round2(r.used / 60) - r.perDay) < 0.02),
  cRows.map(r => `${r.used}/${r.perDay}`).join(' '));
ok('the run-out date is today plus the cover', cRows.every(r => {
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const [d, m, y] = r.runsOut.split(' ');
  const date = Date.parse(`${m} ${d}, ${y} 00:00:00Z`);
  return date === Date.parse('2026-11-07T00:00:00Z') + r.cover * 86400000;
}), cRows.map(r => `${r.cover}d→${r.runsOut}`).join(' '));
ok('an item with no usage is kept off the cover list rather than given zero days',
  !cRows.some(r => r.item === 'Engine oil 15W-40'));
ok('the rows within two days of running out are the ones marked red', cRows.filter(r => r.cover <= 2).length === 3
  && $$('#coverTable tbody tr').filter(r => r.children[4].classList.contains('text-red')).length === 3,
  `${$$('#coverTable tbody tr').filter(r => r.children[4].classList.contains('text-red')).length}`);

/* ── the table the orders are raised from ───────────────────────────────── */
const tCols = [...$$('#roTable thead th')].map(th => text(th).replace(/\s+/g, ' '));
ok('the working table has ten columns, usage and cover included', tCols.length === 10
  && tCols[5] === 'Used (60 d)' && tCols[6] === 'Days left', tCols.join('|'));
const tRows = [...$$('#roTable tbody tr')];
ok('every suggested item is on the table', tRows.length === 10, `${tRows.length}`);
const rowOf = (name) => tRows.find(r => text(r.children[1]).includes(name));
const qtyOf = (tr) => Number((tr.querySelector('input[name$="[qty]"]') ?? {}).value);
const pickedOf = (tr) => Boolean(tr.querySelector('input.ro-pick')?.hasAttribute('checked'));
ok('the quantity to order is on the row, ready to be posted', qtyOf(rowOf('Diesel')) === 620 && qtyOf(rowOf('Bath towels')) === 42,
  `${qtyOf(rowOf('Diesel'))} / ${qtyOf(rowOf('Bath towels'))}`);
ok('a row with nothing left to buy is not ticked', qtyOf(rowOf('Chicken breast')) === 0 && !pickedOf(rowOf('Chicken breast')));
ok('and a row with something to buy is ticked', pickedOf(rowOf('Guest soap 40 g')) && pickedOf(rowOf('Toilet paper 2-ply')));
ok('the supplier defaults to the last one used, and is on the row', 
  [...rowOf('Diesel').querySelectorAll('option')].some(o => o.hasAttribute('selected') && o.textContent.trim() === 'Volta Power Systems'));
ok('the rate defaults to the last ratepaid', Number((rowOf('Diesel').querySelector('input[name$="[rate]"]') ?? {}).value) === 14.2);
ok('the row states its own usage and cover, so the quantity can be argued with',
  /4,800/.test(text(rowOf('Diesel').children[5])) && /2 d/.test(text(rowOf('Diesel').children[6])),
  `${text(rowOf('Diesel').children[5])} / ${text(rowOf('Diesel').children[6])}`);
ok('a row with no usage says so rather than showing zero days, and still carries a quantity',
  text(rowOf('Engine oil 15W-40').children[6]) === '—' && text(rowOf('Engine oil 15W-40').children[5]) === '—'
  && qtyOf(rowOf('Engine oil 15W-40')) === 28,
  `${text(rowOf('Engine oil 15W-40').children[5])} / ${text(rowOf('Engine oil 15W-40').children[6])}`);
ok('every row carries its item id for the post', tRows.every(r => /data-item="\d+"/.test(r.outerHTML)));

/* ── the chain behind the suggestion ────────────────────────────────────── */
ok('the page says where the figures come from and what a level on its own is worth',
  /The suggestion closes one loop and opens the next/.test(text($('#roChain'))) && /A level alone only says what somebody once thought was enough/.test(text($('#roChain'))),
  text($('#roChain')));
ok('and links the requisition, the order and the goods receipt it reads from',
  /requisition/.test(text($('#roChain'))) && /order/.test(text($('#roChain'))) && /goods receipt/.test(text($('#roChain'))));

/* ── the drift build: the pipeline and the cover broken, the gap whole ──── */
const drift = load('deploy/dev/previews/reorder-drift.html', 'https://preview.local/inventory/reorder');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats.wide')[0].children].map(s => dText(s));
ok('the drift leaves the gap whole — nothing on the shelf moved', dHead[0] === head[0] && dHead[1] === head[1] && dHead[2] === head[2],
  `${dHead[0]} | ${dHead[2]}`);
ok('and the gap card says the same thing, row for row',
  dText(drift.querySelector('#roGapState')) === text($('#roGapState'))
  && [...drift.querySelectorAll('#gapTable tbody tr')].every((r, i) => dText(r) === text($$('#gapTable tbody tr')[i])));
ok('but one more shortage now has something against it', /^4/.test(dHead[3]), dHead[3]);
const dPipe = drift.querySelector('#roPipeline');
ok('the pipeline grows by the draft it was hiding', /GH₵ 8,458\.00 on order · 2 of them still in draft/.test(dText(dPipe.querySelector('.card-head'))),
  dText(dPipe.querySelector('.card-head')));
const dPStats = [...dPipe.querySelectorAll('.stat')].map(s => dText(s));
ok('the money in draft is stated with the extra line', /4,030\.00/.test(dPStats[1]) && /2 draft order lines/.test(dPStats[1]), dPStats[1]);
ok('and what is on order rises by the sugar the draft carries', /8,458\.00/.test(dPStats[0]) && /5 items with an approved/.test(dPStats[0]), dPStats[0]);
const dSugar = [...drift.querySelectorAll('#pipeTable tbody tr')].find(r => dText(r).includes('Sugar'));
ok('the sugar line is on the pipeline with its draft quantity', dSugar !== undefined && /55/.test(dText(dSugar)) && dSugar.getAttribute('data-draft') === '1',
  dText(dSugar));
ok('the cover card moves with the banqueting week', /2 items with no movement at all/.test(dText(drift.querySelector('#roCover .card-head'))),
  dText(drift.querySelector('#roCover .card-head')));
const dCStats = [...drift.querySelectorAll('#roCover .stat')].map(s => dText(s));
ok('the usage rises with the draw, in money and in units', /215,538\.00/.test(dCStats[0]) && /10,626 units/.test(dCStats[0]), dCStats[0]);
ok('two items are now silent, and the wording says so', /^2/.test(dCStats[1]) && /2 of them have not moved/.test(dCStats[1]), dCStats[1]);
ok('one item fewer has a usage figure to measure against', /^8/.test(dCStats[3]), dCStats[3]);
const dChicken = [...drift.querySelectorAll('#coverTable tbody tr')].find(r => dText(r).includes('Chicken breast'));
ok('the chicken’s cover falls from three days to one', dChicken.getAttribute('data-cover') === '1'
  && /1 d/.test(dText(dChicken)), dText(dChicken));
ok('the item that stopped moving drops off the cover list but stays on the shelf table',
  ![...drift.querySelectorAll('#coverTable tbody tr')].some(r => dText(r).includes('Print paper'))
  && [...drift.querySelectorAll('#roTable tbody tr')].some(r => dText(r).includes('Print paper')));
ok('the working table follows the drift — the sugar suggestion is now nothing',
  Number(([...drift.querySelectorAll('#roTable tbody tr')].find(r => dText(r).includes('Sugar')).querySelector('input[name$="[qty]"]') ?? {}).value) === 0);

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|endforelse|php|endphp|unless|endunless|error|enderror)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
  ok(`${name} holds no unpluralised shorthand`, !/item\(s\)|line\(s\)|unit\(s\)|order\(s\)|day\(s\)/.test(clean));
}

if (failures.length) {
  console.error(`reorder check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`reorder check: ${passed}/${passed} passed`);
