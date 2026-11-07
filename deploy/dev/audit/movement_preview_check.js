#!/usr/bin/env node
/*
 * The stock movement form, read before anything is posted.
 *
 * This is the only stock document written by hand, and it had no reference field: every movement it wrote reached the
 * ledger with nothing behind it, which is exactly what the ledger's gap card could only count. v9.25 adds the field and
 * puts three readings around the form, each computed from the same two lists the form works from:
 *
 *   1. what is in the store you are taking from — Kitchen Store, 10 items, 10,159.00, three at or below level, rice
 *      180 kg against a 300 kg level the tightest, and one item at zero;
 *   2. what has left it this month — 8 movements, 228 units, 4,765.00, split by type: 3 issues for 3,686.00, a
 *      transfer out, 2 wastages and 2 adjustments;
 *   3. what nobody can answer for — 4 of those movements, 319.00, with no reference and no department, the largest the
 *      6 L dishwashing adjustment at 132.00 — and the note that the form now asks for the document;
 *   4. the form itself — the new Document field, the store it opens on, and the controls that still post;
 *   5. the drift build — the same month with the field in use, so the gap card empties and nothing else moves.
 *
 * Renders deploy/dev/previews/movement.html and movement-drift.html in jsdom against deploy/dev/preview/movement-sample.mjs.
 *
 * Run: node deploy/dev/audit/movement_preview_check.js
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
const round2 = (v) => Math.round(v * 100) / 100;

const doc = load('deploy/dev/previews/movement.html', 'https://preview.local/inventory/movement');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the header ─────────────────────────────────────────────────────────── */
const head = [...$$('.stats.wide')[0].children].map(s => text(s));
ok('the header carries five figures', head.length === 5, `${head.length}`);
ok('the store’s holdings are counted with the ones below level',
  /^10/.test(head[0]) && /10 items in the Kitchen Store, 3 at or below level/.test(head[0]), head[0]);
ok('its value is the figure an issue is taking out', /GH₵ 10,159\.00/.test(head[1])
  && /what an issue from here is taking out/.test(head[1]), head[1]);
ok('the lines at or below level are on the header in red',
  /^3/.test(head[2]) && /issuing these makes a shortage worse/.test(head[2])
  && $$('.stats.wide')[0].children[2].classList.contains('tone-red'), head[2]);
ok('what has left the store this month is money, with the count behind it',
  /GH₵ 4,765\.00/.test(head[3]) && /8 movements since 01 Nov 2026/.test(head[3]), head[3]);
ok('and the movements nobody can account for are counted beside it',
  /^4/.test(head[4]) && /319\.00 left this store with nothing behind it/.test(head[4]), head[4]);

/* ── what is in the store ───────────────────────────────────────────────── */
const stock = $('#mvStock');
ok('the page asks what is in the store you are taking from', /What is in the store you are taking from\?/.test(text(stock)));
ok('the head names the store, its items and its value',
  /Kitchen Store · 10 items · GH₵ 10,159\.00/.test(text(stock.querySelector('.card-head'))), text(stock.querySelector('.card-head')));
const sStats = [...stock.querySelectorAll('.stat')].map(s => text(s));
ok('items held is the first figure, with the empty ones called out', /Items held/.test(sStats[0]) && /^10/.test(sStats[0])
  && /1 item with nothing in the store/.test(sStats[0]), sStats[0]);
ok('the value on the shelf is the second', /Value on the shelf/.test(sStats[1]) && /10,159\.00/.test(sStats[1]), sStats[1]);
ok('the largest holding is named with its quantity', /Largest holding/.test(sStats[2]) && /2,584\.00/.test(sStats[2])
  && /Chicken — 68 kg, the most there is to issue/.test(sStats[2]), sStats[2]);
ok('and the tightest line is the one against its own level',
  /Tightest line/.test(sStats[3]) && /^180 kg/.test(sStats[3]) && /Rice against a level of 300/.test(sStats[3]), sStats[3]);
ok('the verdict says why the shelf quantity is the one that matters',
  /The form below checks the shelf before it posts anything: an issue for more than the store holds is refused/.test(text($('#mvStockState'))),
  text($('#mvStockState')));
ok('the holdings are listed by value, largest first', (() => {
  const rows = [...$$('#holdTable tbody tr')].map(r => num(r.children[3]));

  return rows.length === 10 && rows.every((v, i) => i === 0 || v <= rows[i - 1]);
})());
const holds = [...$$('#holdTable tbody tr')].map(r => ({ item: text(r.children[0]), qty: text(r.children[1]),
  standing: r.getAttribute('data-standing'), badge: text(r.children[4]) }));
ok('a line below its level is marked as such and only those are',
  holds.filter(h => h.standing === 'below level').length === 3
  && holds.filter(h => h.standing === 'below level').map(h => h.item).sort().join(',') === 'Cooking gas,Rice,Sugar',
  holds.filter(h => h.standing === 'below level').map(h => h.item).join(','));
ok('the item with nothing in the store reads at zero, not below level',
  holds.find(h => h.item === 'Tilapia').standing === 'at zero'
  && holds.find(h => h.item === 'Tilapia').badge === 'at zero');
ok('the badge on each row says what the row is', holds.every(h => h.badge === h.standing));
ok('the item with most in it is in stock, whatever its value', holds.find(h => h.item === 'Chicken').standing === 'in stock');

/* ── what has left the store ────────────────────────────────────────────── */
const out = $('#mvOut');
ok('the page asks what has left this store this month', /What has left this store this month\?/.test(text(out)));
ok('the head totals the movements, the units and the money',
  /8 movements · 228 units · GH₵ 4,765\.00/.test(text(out.querySelector('.card-head'))), text(out.querySelector('.card-head')));
const oStats = [...out.querySelectorAll('.stat')].map(s => text(s));
ok('movements out are counted', /Movements out/.test(oStats[0]) && /^8/.test(oStats[0]) && /every one of them a ledger line/.test(oStats[0]), oStats[0]);
ok('and valued at the rate each line carries', /Value out/.test(oStats[1]) && /4,765\.00/.test(oStats[1])
  && /the cost the department is charged/.test(oStats[1]), oStats[1]);
ok('the largest single movement is named with its item and its department',
  /Largest/.test(oStats[2]) && /1,710\.00/.test(oStats[2]) && /180 kg of Rice to Kitchen/.test(oStats[2]), oStats[2]);
ok('corrections are counted apart, because they are the only way back',
  /Corrections/.test(oStats[3]) && /^2/.test(oStats[3]) && /256\.00 corrected by hand/.test(oStats[3]), oStats[3]);
ok('the verdict names the largest movement out with its date',
  /The largest single movement out is 180 kg of Rice on 01 Nov 2026, GH₵ 1,710\.00 to the Kitchen\./.test(text($('#mvOutState'))),
  text($('#mvOutState')));
ok('and says nothing posted can be taken back',
  /Every line here is already in the stock ledger and cannot be taken back/.test(text($('#mvOutState'))));
const oCols = [...$$('#outTable thead th')].map(th => text(th));
ok('the month is split by the kind of movement', oCols.join('|') === 'Type|Movements|Units|Value', oCols.join('|'));
const oRows = [...$$('#outTable tbody tr')].map(r => ({ type: r.getAttribute('data-type'), label: text(r.children[0]),
  count: num(r.children[1]), units: num(r.children[2]), value: num(r.children[3]) }));
ok('every kind that moved is on the list, largest value first', oRows.length === 4
  && oRows.map(r => r.type).join(',') === 'issue,transfer_out,adjustment,wastage', oRows.map(r => r.type).join(','));
ok('the issues are the bulk of it', oRows[0].count === 3 && oRows[0].value === 3686 && oRows[0].units === 196);
ok('the rows add to the footings and to the header',
  oRows.reduce((t, r) => t + r.count, 0) === 8 && round2(oRows.reduce((t, r) => t + r.value, 0)) === 4765
  && round2(oRows.reduce((t, r) => t + r.units, 0)) === 228);
ok('the corrections figure is the adjustment row, not the largest row',
  oRows.find(r => r.type === 'adjustment').count === 2 && oRows.find(r => r.type === 'adjustment').value === 256);

/* ── what nobody can answer for ─────────────────────────────────────────── */
const gaps = $('#mvGaps');
ok('the page asks what nobody can answer for', /What can nobody answer for\?/.test(text(gaps)));
ok('the head counts them and puts a value on them',
  /4 movements out of this store with no reference and nobody named · GH₵ 319\.00/.test(text(gaps.querySelector('.card-head'))),
  text(gaps.querySelector('.card-head')));
const gStats = [...gaps.querySelectorAll('.stat')].map(s => text(s));
ok('the count with no reference is the first figure', /No reference/.test(gStats[0]) && /^4/.test(gStats[0]), gStats[0]);
ok('their value is stated as stock that cannot be tied to anything', /Their value/.test(gStats[1]) && /319\.00/.test(gStats[1])
  && /cannot be tied to an issue note, a transfer sheet or a report/.test(gStats[1]), gStats[1]);
ok('the largest of them is named with its date', /Largest/.test(gStats[2]) && /132\.00/.test(gStats[2])
  && /6 L of Dishwashing liquid on 06 Nov 2026/.test(gStats[2]), gStats[2]);
ok('and the wastage among them is counted, because it is the plausible excuse',
  /Wastage in them/.test(gStats[3]) && /^2/.test(gStats[3]), gStats[3]);
ok('the verdict says the form had nowhere to record the document',
  /Until this version the form had nowhere to record the document a movement rested on/.test(text($('#mvGapsState'))),
  text($('#mvGapsState')));
ok('and points at the field that fixes it', /The reference field below is what lets the ledger answer for the next one\./.test(text($('#mvGapsState'))));
const gCols = [...$$('#gapTable thead th')].map(th => text(th));
ok('the unexplained movements are listed in six columns', gCols.join('|') === 'Date|Item|Type|Qty|Value|By', gCols.join('|'));
const gRows = [...$$('#gapTable tbody tr')].map(r => ({ date: text(r.children[0]), item: text(r.children[1]),
  qty: num(r.children[3]), value: num(r.children[4]), user: text(r.children[5]) }));
ok('every one of them is listed, largest value first', gRows.length === 4
  && gRows.map(r => r.item).join(',') === 'Dishwashing liquid,Beef,Tomatoes,Onions', gRows.map(r => r.item).join(','));
ok('the rows add to the card’s money', round2(gRows.reduce((t, r) => t + r.value, 0)) === 319);
ok('each row still names who wrote it, even with no document against it',
  gRows.every(r => r.user && r.user !== '—'), gRows.map(r => r.user).join(','));

/* ── the form ───────────────────────────────────────────────────────────── */
const form = $('#mvForm');
ok('the form is still on the page and still posts to the movement route',
  form.getAttribute('action') === '#inventory-movement-store', form.getAttribute('action'));
ok('it opens on the store the three cards describe', (() => {
  const sel = $('select[name="store_id"]');
  const chosen = sel?.querySelector('option[selected]');

  return sel && chosen && chosen.textContent.trim() === 'Kitchen Store';
})(), $('select[name="store_id"] option[selected]')?.textContent);
ok('the document field is the one this round added', $$('form#mvForm input[name="reference"]').length === 1
  && /The issue note, transfer sheet or breakage report this movement rests on/.test(text(form)));
ok('and it says what a movement without one becomes',
  /Without one the ledger carries a number nobody can ask about\./.test(text(form)));
ok('the type, date, department and remarks fields are all still there',
  ['type', 'date', 'department', 'remarks', 'adjust_sign', 'to_store_id'].every(n => $$(`form#mvForm [name="${n}"]`).length === 1));
ok('the transaction types still include the four that write a movement',
  ['Issue to department', 'Store transfer', 'Stock adjustment', 'Wastage / breakage'].every(t => text(form).includes(t)));
ok('the line table and its add-row template are unchanged', (() => {
  const tpl = $('template#mvRow');

  return $$('#mvLines').length === 1 && tpl && tpl.content.querySelectorAll('option').length === 11;
})(), $('template#mvRow')?.content.querySelectorAll('option').length);
ok('the posting button is still there', /Post Transaction/.test(text(form)));

/* ── the loop the form sits in ──────────────────────────────────────────── */
ok('the page says which document this one is not',
  /A movement is the only stock document written by hand/.test(text($('#mvChain')))
  && /a movement with no document is stock the books can count and nobody can explain/.test(text($('#mvChain'))), text($('#mvChain')));

/* ── the drift build ────────────────────────────────────────────────────── */
const drift = load('deploy/dev/previews/movement-drift.html', 'https://preview.local/inventory/movement');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats.wide')[0].children].map(s => dText(s));
ok('the drift opens with nothing unexplained', /^0/.test(dHead[4]) && /every movement out names a document/.test(dHead[4]), dHead[4]);
ok('the gap card empties to a check rather than a list',
  drift.querySelectorAll('#gapTable tbody tr').length === 0
  && /Every movement out of this store this month names a document or a department, so each one can be asked about\./.test(dText(drift.querySelector('#mvGapsState'))));
ok('the same eight movements still left the store', /GH₵ 4,765\.00/.test(dHead[3]) && /8 movements since 01 Nov 2026/.test(dHead[3]), dHead[3]);
ok('and the same money, because a reference changes what a movement is, not what it costs',
  /8 movements · 228 units · GH₵ 4,765\.00/.test(dText(drift.querySelector('#mvOut .card-head'))),
  dText(drift.querySelector('#mvOut .card-head')));
ok('the shelf itself is untouched', /10 items in the Kitchen Store, 3 at or below level/.test(dHead[0]) && /10,159\.00/.test(dHead[1]));
ok('the corrections still happen — they are just documented now',
  /2/.test(dText(drift.querySelectorAll('#mvOut .stat')[3])) && /256\.00 corrected by hand/.test(dText(drift.querySelectorAll('#mvOut .stat')[3])));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|endforelse|php|endphp|unless|endunless|selected|checked|error|enderror)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder or leaked attribute`, !/\{\{|\}\}|:sub=|:tone=/.test(clean));
  ok(`${name} holds no unpluralised shorthand`, !/movement\(s\)|item\(s\)|line\(s\)/.test(clean));
}

if (failures.length) {
  console.error(`movement check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`movement check: ${passed}/${passed} passed`);
