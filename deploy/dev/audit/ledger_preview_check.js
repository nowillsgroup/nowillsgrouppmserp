#!/usr/bin/env node
/*
 * The stock ledger, read three ways.
 *
 * Every other inventory document ends on this screen, and the screen used to list quantities and total nothing — which
 * makes it a list rather than a book. v9.22 reads the same rows three ways and this check holds each reading to them:
 *
 *   1. the flow — 30 movements, 77,760.00 in against 37,809.50 out, a net 39,950.50, grouped by type with a footing
 *      row, and the largest single line named (the 1,200 L diesel receipt, 28,800.00);
 *   2. the balance — ten items rolled from 39,984.00 to 79,934.50, and the engine oil closing at −6 L because eighteen
 *      litres were issued from a book that had twelve;
 *   3. the gaps — four movements out with no reference and no department, 1,197.50, 3.2 % of the value that left;
 *   4. the movements themselves — the columns, the rows and the data attributes behind the cards;
 *   5. the drift build — a late receipt and one wastage form, and nothing else on the shelf moves.
 *
 * The point of the round is that the cards are computed from the same rows the table lists, so the check re-adds the
 * table's own rows rather than trusting the summary.
 *
 * Renders deploy/dev/previews/ledger.html and ledger-drift.html in jsdom against deploy/dev/preview/ledger-sample.mjs.
 *
 * Run: node deploy/dev/audit/ledger_preview_check.js
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

const doc = load('deploy/dev/previews/ledger.html', 'https://preview.local/inventory/ledger');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the header ─────────────────────────────────────────────────────────── */
const head = [...$$('.stats.wide')[0].children].map(s => text(s));
ok('the header carries five figures', head.length === 5, `${head.length}`);
ok('the window is counted with its two directions', /^30/.test(head[0]) && /12 in · 18 out/.test(head[0]), head[0]);
ok('what came in is stated in money and in units', /GH₵ 77,760\.00/.test(head[1]) && /3,410 units on 12 inward movements/.test(head[1]), head[1]);
ok('what went out is stated the same way', /GH₵ 37,809\.50/.test(head[2]) && /1,579 units left the stores/.test(head[2]), head[2]);
ok('and the net is the difference, not a total', /GH₵ 39,950\.50/.test(head[3]) && /value received less value issued/.test(head[3]), head[3]);
ok('an item that closes below zero is on the header, not buried in a table',
  /^1/.test(head[4]) && /below zero/.test(head[4]) && $$('.stats.wide')[0].children[4].classList.contains('tone-red'), head[4]);

/* ── the flow ───────────────────────────────────────────────────────────── */
const flow = $('#ldFlow');
ok('the page asks what moved, and what it was worth', /What moved, and what was it worth\?/.test(text(flow)));
ok('the head totals the window three ways',
  /30 movements · GH₵ 77,760\.00 in · GH₵ 37,809\.50 out · GH₵ 39,950\.50 net/.test(text(flow.querySelector('.card-head'))),
  text(flow.querySelector('.card-head')));
const fStats = [...flow.querySelectorAll('.stat')].map(s => text(s));
ok('value received is a figure of its own', /Value received/.test(fStats[0]) && /77,760\.00/.test(fStats[0]), fStats[0]);
ok('value issued is the other half', /Value issued/.test(fStats[1]) && /37,809\.50/.test(fStats[1]), fStats[1]);
ok('the net is the one the header prints', /Net/.test(fStats[2]) && /39,950\.50/.test(fStats[2]), fStats[2]);
ok('and the largest single line is in the card, not only in the sentence',
  /Largest line/.test(fStats[3]) && /28,800\.00/.test(fStats[3]) && /Diesel · 09 Oct 2026/.test(fStats[3]), fStats[3]);
ok('the verdict names the largest line with its quantity, its money and the store',
  /The largest single line is the 1,200 L Diesel receipt on 09 Oct 2026 — GH₵ 28,800\.00 into the Main Store\./.test(text($('#ldFlowState'))),
  text($('#ldFlowState')));
ok('and says why a list of quantities is not a ledger',
  /A ledger that lists quantities and totals nothing cannot be audited/.test(text($('#ldFlowState')))
  && /so the stores are GH₵ 39,950\.50 ahead on the month/.test(text($('#ldFlowState'))));
const fCols = [...$$('#flowTable thead th')].map(th => text(th));
ok('the flow is broken down by type in seven columns',
  fCols.join('|') === 'Type|Movements|Units in|Units out|Value in|Value out|Net', fCols.join('|'));
const fRows = [...$$('#flowTable tbody tr')].map(r => ({ type: r.getAttribute('data-type'), label: text(r.children[0]),
  count: num(r.children[1]), unitsIn: text(r.children[2]), unitsOut: text(r.children[3]),
  valueIn: num(r.children[4]), valueOut: num(r.children[5]), net: num(r.children[6]), attr: Number(r.getAttribute('data-net')) }));
ok('every type that moved is on the list', fRows.length === 6
  && fRows.map(r => r.type).join(',') === 'purchase,issue,adjustment,wastage,transfer_in,transfer_out', fRows.map(r => r.type).join(','));
ok('a receipt type shows its units in and nothing out',
  fRows[0].unitsIn === '3,324' && fRows[0].unitsOut === '—', `${fRows[0].unitsIn}/${fRows[0].unitsOut}`);
ok('an issue type shows units out and nothing in', fRows[1].unitsOut === '1,435' && fRows[1].unitsIn === '—');
ok('an adjustment can go both ways in one row, because it does',
  fRows[2].unitsIn === '6' && fRows[2].unitsOut === '23' && fRows[2].valueIn === 390 && fRows[2].valueOut === 848, text($$('#flowTable tbody tr')[2]));
ok('the net on every row is value in less value out', fRows.every(r => round2(r.valueIn - r.valueOut) === r.net),
  fRows.map(r => `${r.valueIn}-${r.valueOut}=${r.net}`).join(' | '));
ok('the row data carries the net the column shows', fRows.every(r => r.attr === r.net));
ok('the two sides of a transfer net to nothing between them',
  fRows[4].net === 760 && fRows[5].net === -760 && round2(fRows[4].net + fRows[5].net) === 0);
ok('a row that took value out is red, a row that brought it in is not',
  $$('#flowTable tbody tr')[1].children[6].classList.contains('text-red')
  && !$$('#flowTable tbody tr')[0].children[6].classList.contains('text-red'));
const foot = [...$$('#flowTable tfoot th')].map(th => text(th));
ok('the table foots to the header it sits under',
  foot.join('|') === 'All types|30|3,410|1,579|77,760.00|37,809.50|39,950.50', foot.join('|'));
ok('the rows add to the footing themselves',
  round2(fRows.reduce((t, r) => t + r.net, 0)) === 39950.5 && round2(fRows.reduce((t, r) => t + r.valueIn, 0)) === 77760);

/* ── the balance ────────────────────────────────────────────────────────── */
const balance = $('#ldBalance');
ok('the page asks whether the window adds up', /Does the window add up\?/.test(text(balance)));
ok('the head states the items and both ends of the window',
  /10 items · GH₵ 39,984\.00 opening · GH₵ 79,934\.50 closing/.test(text(balance.querySelector('.card-head'))), text(balance.querySelector('.card-head')));
const bStats = [...balance.querySelectorAll('.stat')].map(s => text(s));
ok('opening value is a figure of its own', /Opening value/.test(bStats[0]) && /39,984\.00/.test(bStats[0]), bStats[0]);
ok('closing value is the other', /Closing value/.test(bStats[1]) && /79,934\.50/.test(bStats[1]), bStats[1]);
ok('the movement between them is the third', /Moved/.test(bStats[2]) && /39,950\.50/.test(bStats[2]), bStats[2]);
ok('an item below zero is named with its quantity, not just counted',
  /Below zero/.test(bStats[3]) && /^1/.test(bStats[3]) && /Engine oil at -6 L/.test(bStats[3]), bStats[3]);
ok('the verdict names the item, its opening, its receipts and its issues',
  /Engine oil closed the window at -6 L \(GH₵ -270\.00\): 12 L on the book when it opened, 0 received and 18 issued against it\./.test(text($('#ldBalanceState'))),
  text($('#ldBalanceState')));
ok('and says what a negative book actually means',
  /Stock cannot leave a store it was never in — the issue was cut from a shelf the ledger has no receipt for\./.test(text($('#ldBalanceState'))));
ok('the verdict is red while an item is below zero', $('#ldBalanceState').classList.contains('text-red'));
const balCols = [...$$('#balanceTable thead th')].map(th => text(th));
ok('the roll-forward is six columns', balCols.join('|') === 'Item|Opening|In|Out|Closing|Value', balCols.join('|'));
const bRows = [...$$('#balanceTable tbody tr')].map(r => ({ item: text(r.children[0]).split(/\s{2,}|L @|kg @|pcs @|cylinders @/)[0].trim(),
  opening: num(r.children[1]), in: num(r.children[2]), out: num(r.children[3]), closing: num(r.children[4]),
  value: num(r.children[5]), attr: Number(r.getAttribute('data-closing')) }));
ok('every item that moved is rolled forward', bRows.length === 10, `${bRows.length}`);
ok('opening plus in less out is closing on every row', bRows.every(r => round2(r.opening + r.in - r.out) === r.closing),
  bRows.map(r => `${r.opening}+${r.in}-${r.out}=${r.closing}`).join(' | '));
ok('the row data carries the closing the column shows', bRows.every(r => r.attr === r.closing));
ok('the rows are listed worst first, so the negative one leads', bRows[0].item === 'Engine oil' && bRows[0].closing === -6, bRows[0].item);
ok('and the largest holding is last', bRows[9].item === 'Diesel' && bRows[9].closing === 1818, bRows[9].item);
ok('the value column is closing at the rate the item carries', round2(bRows[0].value) === -270 && round2(bRows[9].value) === 43632);
ok('only the row below zero is painted red',
  $$('#balanceTable tbody tr').filter(r => r.children[4].classList.contains('text-red')).length === 1);
ok('the rows agree with the card’s opening and closing totals',
  round2(bRows.reduce((t, r) => t + r.closing * (r.value / (r.closing || 1)), 0)) === 79934.5);

/* ── the gaps ───────────────────────────────────────────────────────────── */
const gaps = $('#ldGaps');
ok('the page asks what has no document behind it', /What has no document behind it\?/.test(text(gaps)));
ok('the head counts the movements, the money and the share',
  /4 movements out with no reference and no department · GH₵ 1,197\.50 · 3\.2 % of the value that left the store/.test(text(gaps.querySelector('.card-head'))),
  text(gaps.querySelector('.card-head')));
const gStats = [...gaps.querySelectorAll('.stat')].map(s => text(s));
ok('the unexplained money is the first figure', /No document/.test(gStats[0]) && /1,197\.50/.test(gStats[0])
  && /4 movements out that name neither a reference nor a department/.test(gStats[0]), gStats[0]);
ok('adjustments are counted separately, because they are legitimate', /Adjustments/.test(gStats[1]) && /^3/.test(gStats[1]), gStats[1]);
ok('and wastage with them', /Wastage/.test(gStats[2]) && /805\.50/.test(gStats[2]) && /spoiled or broken/.test(gStats[2]), gStats[2]);
ok('the hand that wrote the most of them is named', /Most in one hand/.test(gStats[3]) && /Kwesi/.test(gStats[3]), gStats[3]);
ok('the verdict names the largest unexplained movement with its remark',
  /The largest is 22 L of Diesel on 19 Oct 2026, Adjustment GH₵ 528\.00 by Kwesi — “Dip reading, no document”\./.test(text($('#ldGapsState'))),
  text($('#ldGapsState')));
ok('and closes the argument the round is making',
  /one with no reference and no department is a number somebody typed/.test(text($('#ldGapsState'))));
const gapCols = [...$$('#gapTable thead th')].map(th => text(th));
ok('the gap list is seven columns', gapCols.join('|') === 'Date|Item|Store|Type|Qty|Value|By', gapCols.join('|'));
const gRows = [...$$('#gapTable tbody tr')].map(r => ({ date: text(r.children[0]), item: text(r.children[1]),
  type: text(r.children[3]), value: num(r.children[5]), user: text(r.children[6]), attr: Number(r.getAttribute('data-value')) }));
ok('every unexplained movement is listed, newest first', gRows.length === 4
  && gRows.map(r => r.item).join(',') === 'Toner cartridge,Rice,Laundry powder,Diesel', gRows.map(r => r.item).join(','));
ok('the rows add to the card’s money', round2(gRows.reduce((t, r) => t + r.value, 0)) === 1197.5);
ok('each row names who wrote it', gRows.every(r => r.user && r.user !== '—'), gRows.map(r => r.user).join(','));

/* ── the movements themselves ───────────────────────────────────────────── */
const mCols = [...$$('#ledgerTable thead th')].map(th => text(th));
ok('the movement list keeps its ten columns',
  mCols.join('|') === 'Date|Item|Store|Type|In|Out|Rate|Dept / Ref|Remarks|By', mCols.join('|'));
const mRows = [...$$('#ledgerTable tbody tr')];
ok('the window lists its thirty movements', mRows.length === 30, `${mRows.length}`);
ok('newest first, so the two 06 Nov issues lead', text(mRows[0].children[0]) === '06 Nov 2026', text(mRows[0].children[0]));
ok('the quantity is carried on the row, sign and all', mRows.filter(r => Number(r.getAttribute('data-qty')) < 0).length === 18);
ok('a movement in shows its quantity in the In column only',
  text(mRows.find(r => r.getAttribute('data-type') === 'purchase').children[4]) !== ''
  && text(mRows.find(r => r.getAttribute('data-type') === 'purchase').children[5]) === '');
ok('the paginator says how many lines the readings were taken from',
  /30 lines · the three readings above are worked out from exactly these rows/.test(text($('#ledgerTable').closest('.card').querySelector('.card-head'))));
ok('the filters are still on the page', $$('.filters select[name="item"]').length === 1 && $$('.filters select[name="store"]').length === 1
  && $$('.filters select[name="type"]').length === 1 && $$('.filters input[name="from"]').length === 1);
ok('and they open on the window the cards describe', $('.filters input[name="from"]').getAttribute('value') === '2026-10-09'
  && $('.filters input[name="to"]').getAttribute('value') === '2026-11-07');

/* ── the loop behind the ledger ─────────────────────────────────────────── */
ok('the page says what ends here and what is checked against it',
  /Every other inventory document ends here/.test(text($('#ldChain')))
  && /goods receipt/.test(text($('#ldChain'))) && /issue/.test(text($('#ldChain')))
  && /stock take/.test(text($('#ldChain'))), text($('#ldChain')));
ok('and draws the line the round draws',
  /a line with no document behind it, or an item that closes below zero, is the difference between a book and a list of quantities/.test(text($('#ldChain'))));

/* ── the drift build ────────────────────────────────────────────────────── */
const drift = load('deploy/dev/previews/ledger-drift.html', 'https://preview.local/inventory/ledger');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats.wide')[0].children].map(s => dText(s));
const dRows = [...drift.querySelectorAll('#balanceTable tbody tr')];
const dGaps = [...drift.querySelectorAll('#gapTable tbody tr')];
ok('the drift adds one movement, the late engine-oil receipt', /^31/.test(dHead[0]) && /13 in · 18 out/.test(dHead[0]), dHead[0]);
ok('the window takes in what the receipt was worth', /GH₵ 78,840\.00/.test(dHead[1]) && /GH₵ 41,030\.50/.test(dHead[3]), dHead[1]);
ok('the book closes higher by exactly that receipt',
  /GH₵ 39,984\.00 opening · GH₵ 81,014\.50 closing/.test(dText(drift.querySelector('#ldBalance .card-head'))),
  dText(drift.querySelector('#ldBalance .card-head')));
ok('nothing closes below zero any more', /^0/.test(dHead[4]), dHead[4]);
ok('the engine oil is above water and sits where its value puts it',
  dText(dRows[1]).includes('Engine oil') && dText(dRows[1]).includes('12') && dText(dRows[1]).includes('810.00'), dText(dRows[1]));
ok('the verdict changes with it rather than keeping the old sentence',
  /nothing closed the window below zero, so no issue was cut against stock the book never received/.test(dText(drift.querySelector('#ldBalanceState'))),
  dText(drift.querySelector('#ldBalanceState')));
ok('one wastage form takes one movement off the gap list', dGaps.length === 3 && /GH₵ 960\.00/.test(dText(drift.querySelector('#ldGaps .card-head'))),
  dText(drift.querySelector('#ldGaps .card-head')));
ok('and the rice is the one that left it', !dGaps.some(r => dText(r).includes('Rice')), dGaps.map(r => dText(r.children[1])).join(','));
ok('the share falls with it', /2\.5 % of the value that left the store/.test(dText(drift.querySelector('#ldGaps .card-head'))));
ok('nothing else about the shelf changes',
  dText(dRows[dRows.length - 1]).includes('Diesel') && dText(dRows[dRows.length - 1]).includes('43,632.00'));
ok('the movements list grows by the one row, and the cards move with it',
  [...drift.querySelectorAll('#ledgerTable tbody tr')].length === 31);

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|endforelse|php|endphp|unless|endunless|error|enderror)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder or leaked attribute`, !/\{\{|\}\}|:sub=|:tone=/.test(clean));
  ok(`${name} holds no unpluralised shorthand`, !/movement\(s\)|item\(s\)|line\(s\)|type\(s\)/.test(clean));
}

if (failures.length) {
  console.error(`ledger check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`ledger check: ${passed}/${passed} passed`);
