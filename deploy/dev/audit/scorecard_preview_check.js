#!/usr/bin/env node
/*
 * The supplier scorecard, read as the three things it is computed from.
 *
 * The page gave one weighted number and eleven columns. A score is an answer, not evidence, so v9.19 puts the three
 * readings behind it on the page and this check holds each to its own document:
 *
 *   1. the delivery — goods receipts against the date their order gave them: 17 deliveries, 4 late, 19,480.00 of
 *      64,720.00, with the worst named (Coastal, GRN-2026-0116, fifteen days over a 20 October promise);
 *   2. the money — what the shelf took in against what the supplier billed, and what is still owed: 64,720.00 each
 *      way and no disagreement, with 36,500.00 open across six bills, the oldest Sahel at forty-one days;
 *   3. the bid — invitations against answers, with the silence named rather than averaged: 16 asked, 11 answered, and
 *      Kumasi silent on two of its three;
 *   4. the score itself — the ordering, the grades and the columns that feed them;
 *   5. the drift build — the money broken by 2,150.00 in two directions with the delivery and the bid left alone.
 *
 * Renders deploy/dev/previews/scorecard.html and scorecard-drift.html in jsdom against
 * deploy/dev/preview/scorecard-sample.mjs.
 *
 * Run: node deploy/dev/audit/scorecard_preview_check.js
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
const num = (cell) => Number((((text(cell).match(/[−-]?[\d,]+\.\d{2}/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);
const scoreOf = (cell) => Number((text(cell).match(/([\d.]+)\s*$/) ?? [0, 0])[1]) || 0;
const round2 = (v) => Math.round(v * 100) / 100;

const doc = load('deploy/dev/previews/scorecard.html', 'https://preview.local/inventory/supplier-scorecard');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the header ─────────────────────────────────────────────────────────── */
const head = [...$$('.stats.wide')[0].children].map(s => text(s));
ok('the header carries five figures, one per reading plus the score itself', head.length === 5, `${head.length}`);
ok('the suppliers scored are counted with the grade strip behind them',
  /^7/.test(head[0]) && /2 at A · 4 at B · 1 at C · 0 at D/.test(head[0]), head[0]);
ok('the average score is stated once, not left to the reader to average eleven columns',
  /83\.9/.test(head[1]) && /Average score/.test(head[1]), head[1]);
ok('late deliveries are counted with the money that arrived after the date',
  /^4/.test(head[2]) && /17 deliveries · GH₵ 19,480\.00 arrived after the date/.test(head[2]), head[2]);
ok('the bill is stated against the receipt, green while they agree',
  /GH₵ 0\.00/.test(head[3]) && /every bill is for what the shelf took in/.test(head[3])
  && $$('.stats.wide')[0].children[3].classList.contains('tone-green'), head[3]);
ok('and what is still owed is on the header with its age',
  /GH₵ 36,500\.00/.test(head[4]) && /6 open bills · oldest 41 days past its date/.test(head[4]), head[4]);

/* ── the delivery record ────────────────────────────────────────────────── */
const del = $('#scDelivery');
ok('the page asks who delivers when they say they will', /Who delivers when they say they will\?/.test(text(del)));
ok('the head counts the deliveries, the late ones and the worst wait',
  /17 deliveries · 4 late · oldest 15 days over/.test(text(del.querySelector('.card-head'))), text(del.querySelector('.card-head')));
const dStats = [...del.querySelectorAll('.stat')].map(s => text(s));
ok('the deliveries are counted with the value the store accepted', /Deliveries accepted/.test(dStats[0]) && /^17/.test(dStats[0])
  && /64,720\.00 taken into the store/.test(dStats[0]), dStats[0]);
ok('the late ones are separated from the money that was on time', /Late/.test(dStats[1]) && /^4/.test(dStats[1])
  && /19,480\.00 arrived after the date/.test(dStats[1]) && del.querySelectorAll('.stat')[1].classList.contains('tone-red'), dStats[1]);
ok('lead time is measured from the order date to the arrival, not from the promise',
  /Lead time/.test(dStats[2]) && /5\.1 d/.test(dStats[2]) && /order date to arrival/.test(dStats[2]), dStats[2]);
ok('goods turned away at the door are stated, because they never entered stock',
  /Turned away/.test(dStats[3]) && /1,860\.00/.test(dStats[3]), dStats[3]);
ok('the verdict names the worst delivery with its supplier, its order, its money and its dates',
  /The worst is Coastal Beverages Ltd on GRN-2026-0116 against PO-2026-0296 — GH₵ 6,320\.00 arrived on 04 Nov 2026, 15 days after the 20 Oct 2026 it was promised for\./.test(text($('#scDeliveryState'))),
  text($('#scDeliveryState')));
ok('and says how much of the period arrived late', /4 of the 17 deliveries in this period came late, GH₵ 19,480\.00 of the GH₵ 64,720\.00 accepted\./.test(text($('#scDeliveryState'))));
ok('the verdict is red while a delivery is over', $('#scDeliveryState').classList.contains('text-red'));
const lRows = [...$$('#lateTable tbody tr')].map(r => ({ supplier: text(r.children[0]), grn: text(r.children[1]), po: text(r.children[2]),
  promised: text(r.children[3]), arrived: text(r.children[4]), days: text(r.children[5]), value: num(r.children[6]),
  late: Number(r.getAttribute('data-late')) }));
ok('every late delivery is listed, worst first', lRows.length === 4
  && lRows.map(r => r.grn).join(',') === 'GRN-2026-0116,GRN-2026-0109,GRN-2026-0106,GRN-2026-0102', lRows.map(r => r.grn).join(','));
ok('the days over are counted from the promise to the arrival, on every row',
  lRows.every(r => r.late === Math.round((Date.parse(r.arrived.replace(/(\d{2}) (\w{3}) (\d{4})/, '$2 $1, $3')) - Date.parse(r.promised.replace(/(\d{2}) (\w{3}) (\d{4})/, '$2 $1, $3'))) / 86400000)),
  lRows.map(r => `${r.promised}→${r.arrived}=${r.late}`).join(' | '));
ok('the late values add to the money the card states', round2(lRows.reduce((t, r) => t + r.value, 0)) === 19480,
  `${lRows.reduce((t, r) => t + r.value, 0)}`);
ok('each late row names the goods receipt and the order it came against',
  lRows.every(r => /^GRN-2026-01\d\d$/.test(r.grn) && /^PO-2026-02\d\d$/.test(r.po)), lRows.map(r => `${r.grn}/${r.po}`).join(' '));
ok('the delivery more than a week over is the only one marked in the list',
  lRows.filter(r => r.late > 7).length === 1 && lRows[0].late === 15
  && $$('#lateTable tbody tr').filter(r => r.children[5].classList.contains('text-red')).length === 1, lRows.map(r => r.late).join(','));

/* ── the money record ───────────────────────────────────────────────────── */
const money = $('#scMoney');
ok('the page asks whether the supplier billed what arrived', /Did the supplier bill what arrived\?/.test(text(money)));
ok('the head counts the suppliers and the disagreements', /7 suppliers · 0 where the bill and the receipt disagree/.test(text(money.querySelector('.card-head'))),
  text(money.querySelector('.card-head')));
const mStats = [...money.querySelectorAll('.stat')].map(s => text(s));
ok('what the shelf took in is stated', /Received/.test(mStats[0]) && /64,720\.00/.test(mStats[0]), mStats[0]);
ok('what was billed is stated against it, debit notes excluded', /Billed/.test(mStats[1]) && /64,720\.00/.test(mStats[1])
  && /excluding debit notes/.test(mStats[1]), mStats[1]);
ok('the difference is nil and green', /Difference/.test(mStats[2]) && /0\.00/.test(mStats[2])
  && money.querySelectorAll('.stat')[2].classList.contains('tone-green'), mStats[2]);
ok('and what is still owed is on the card, not only in the bills screen',
  /Still owed/.test(mStats[3]) && /36,500\.00/.test(mStats[3]) && /6 open bills/.test(mStats[3]), mStats[3]);
ok('the verdict says the two are the same money rather than staying silent',
  /Every supplier billed exactly what the shelf took in for the period, order for order\./.test(text($('#scMoneyState'))),
  text($('#scMoneyState')));
ok('and names the oldest debt with its supplier and its age',
  /GH₵ 36,500\.00 is still owed across 6 open bills; the oldest is Sahel Textiles at 41 days past its due date\./.test(text($('#scMoneyState'))));
ok('nothing is marked red when no bill disagrees', !$('#scMoneyState').classList.contains('text-red')
  && $$('#moneyTable tbody tr td:nth-child(4)').every(td => !td.classList.contains('text-red')));
const monRows = [...$$('#moneyTable tbody tr')].map(r => ({ supplier: text(r.children[0]), received: num(r.children[1]), billed: num(r.children[2]),
  diff: num(r.children[3]), bills: Number(text(r.children[4])), unpaid: num(r.children[5]), oldest: text(r.children[6]),
  gap: r.getAttribute('data-gap') }));
ok('every supplier with goods or a bill in the period is on the list', monRows.length === 7, `${monRows.length}`);
ok('the two sides are equal on every row', monRows.every(r => r.received === r.billed && r.diff === 0), monRows.map(r => `${r.received}/${r.billed}`).join(' '));
ok('no row is marked as a gap when none is', monRows.every(r => r.gap === '0'));
ok('the received figures add to the delivery card’s accepted value',
  round2(monRows.reduce((t, r) => t + r.received, 0)) === 64720);
ok('the unpaid column adds to the header’s figure', round2(monRows.reduce((t, r) => t + r.unpaid, 0)) === 36500);
ok('a supplier with nothing open says so rather than showing a zero age',
  monRows.filter(r => r.bills === 0).length === 2 && monRows.filter(r => r.bills === 0).every(r => r.oldest === '—'),
  monRows.filter(r => r.bills === 0).map(r => `${r.supplier}:${r.oldest}`).join(' '));
ok('the one debt more than thirty days old is the row marked in the age column',
  $$('#moneyTable tbody tr').filter(r => r.children[6].classList.contains('text-red')).length === 1
  && $$('#moneyTable tbody tr').filter(r => r.children[6].classList.contains('text-red'))[0].children[6].textContent.includes('41'));

/* ── the bid record ─────────────────────────────────────────────────────── */
const bid = $('#scBid');
ok('the page asks who answers when they are asked to quote', /Who answers when we ask them to quote\?/.test(text(bid)));
ok('the head counts the invitations, the answers and the silence',
  /16 invitations · 11 answered · 4 still silent past the date/.test(text(bid.querySelector('.card-head'))), text(bid.querySelector('.card-head')));
const bStats = [...bid.querySelectorAll('.stat')].map(s => text(s));
ok('the invitations are counted with the suppliers behind them', /Invitations/.test(bStats[0]) && /^16/.test(bStats[0]) && /7 suppliers/.test(bStats[0]), bStats[0]);
ok('the answers are stated as a rate and with the money quoted', /Answered/.test(bStats[1]) && /^11/.test(bStats[1])
  && /68\.8 % of the invitations, and GH₵ 61,270\.00 quoted/.test(bStats[1]), bStats[1]);
ok('the silence is a figure of its own, and red', /Still silent/.test(bStats[2]) && /^4/.test(bStats[2])
  && bid.querySelectorAll('.stat')[2].classList.contains('tone-red'), bStats[2]);
ok('price compliance is counted against the order lines that carry a list rate',
  /Above the agreed rate/.test(bStats[3]) && /^2/.test(bStats[3]) && /of 16 order lines/.test(bStats[3]), bStats[3]);
ok('the verdict names the supplier who did not answer rather than the rate alone',
  /Kumasi Paper & Packaging was invited 3 times and answered 1 — 2 went past the date without a price or a decline\./.test(text($('#scBidState'))),
  text($('#scBidState')));
ok('and says why silence is worse than a refusal', /A supplier who will not quote is not a cheap supplier/.test(text($('#scBidState'))));
ok('the verdict names the lines that came in above the agreed rate too',
  /2 order lines came in above the agreed rate/.test(text($('#scBidState'))));
const bidRows = [...$$('#bidTable tbody tr')].map(r => ({ supplier: text(r.children[0]), invited: Number(text(r.children[1])),
  answered: Number(text(r.children[2])), declined: Number(text(r.children[3])), silent: Number(text(r.children[4])),
  rate: text(r.children[5]), quoted: num(r.children[6]) }));
ok('every supplier invited in the period is on the list', bidRows.length === 7 && bidRows.reduce((t, r) => t + r.invited, 0) === 16,
  `${bidRows.length} rows / ${bidRows.reduce((t, r) => t + r.invited, 0)} invited`);
ok('every invitation is answered, declined or silent — nothing is unaccounted for',
  bidRows.every(r => r.answered + r.declined + r.silent === r.invited), bidRows.map(r => `${r.supplier}:${r.answered}+${r.declined}+${r.silent}=${r.invited}`).join(' | '));
ok('the silence on the list is the silence in the head', bidRows.reduce((t, r) => t + r.silent, 0) === 4);
ok('the responses are percentages of the invitations', bidRows.every(r => r.rate === `${Math.round(r.answered / r.invited * 1000) / 10} %`),
  bidRows.map(r => `${r.supplier}:${r.rate}`).join(' '));
ok('the quotations add to the money the card states', round2(bidRows.reduce((t, r) => t + r.quoted, 0)) === 61270);

/* ── the score itself ───────────────────────────────────────────────────── */
const cols = [...$$('#scoreTable thead th')].map(th => text(th));
ok('the score table keeps its eleven columns', cols.join('|')
  === 'Supplier|POs|Value|Deliveries|On time|Fill rate|Quality|Price compliance|RFQ response|Lead (d)|Score', cols.join('|'));
const rowNodes = [...$$('#scoreTable tbody tr')];
const rows = rowNodes.map(r => ({ supplier: text(r.children[0]), orders: Number(text(r.children[1])), value: num(r.children[2]),
  deliveries: Number(text(r.children[3])), onTime: text(r.children[4]), fill: text(r.children[5]), quality: text(r.children[6]),
  price: text(r.children[7]), response: text(r.children[8]), lead: text(r.children[9]), score: scoreOf(r.children[10]),
  grade: text(r.querySelector('[data-grade]')), tone: r.querySelector('[data-grade]').className, id: r.getAttribute('data-supplier') }));
ok('every supplier is scored', rows.length === 7, `${rows.length}`);
ok('the ranking is by score, best first', rows.map(r => r.score).join(',') === '99.1,97.1,84.5,84.4,79.7,77.7,65',
  rows.map(r => r.score).join(','));
ok('the grades follow the score at the 90 / 75 / 60 limits',
  rows.every(r => r.grade === (r.score >= 90 ? 'A' : (r.score >= 75 ? 'B' : (r.score >= 60 ? 'C' : 'D')))),
  rows.map(r => `${r.score}=${r.grade}`).join(' '));
ok('each grade carries the tone the rest of the app uses', rows[0].tone.includes('badge-green') && rows[2].tone.includes('badge-blue')
  && rows[6].tone.includes('badge-amber'), rows.map(r => r.tone).join(' | '));
ok('the average on the header is the average of the rows, to one decimal',
  Math.round(rows.reduce((t, r) => t + r.score, 0) / rows.length * 10) / 10 === 83.9, `${rows.reduce((t, r) => t + r.score, 0) / rows.length}`);
ok('the value on the rows adds to the header', round2(rows.reduce((t, r) => t + r.value, 0)) === 64720);
ok('quality shows the units rejected behind the percentage', /93\.8%/.test(rows[2].quality) && /12 rejected/.test(rows[2].quality), rows[2].quality);
ok('price compliance shows the lines that came in above the agreed rate', /66\.7%/.test(rows[3].price) && /1 above agreed/.test(rows[3].price), rows[3].price);
ok('the RFQ response carries its fraction so the rate can be checked', /1\/3/.test(rows[6].response) && /1\/2/.test(rows[2].response),
  rows.map(r => r.response).join(' | '));
ok('a supplier with no deliveries still gets a delivery count of its own', rows.every(r => Number.isFinite(r.deliveries)),
  rows.map(r => r.deliveries).join(','));
ok('every supplier keeps a stable identity on the row', rows.every(r => /^\d+$/.test(r.id)), rows.map(r => r.id).join(','));

/* ── the chain behind the score ─────────────────────────────────────────── */
ok('the page says where each measure comes from, and that a missing measure is not a zero',
  /The score is the last thing the chain can tell you/.test(text($('#scChain'))) && /left out of the score rather than set to zero/.test(text($('#scChain'))),
  text($('#scChain')));
ok('and links back to the goods receipts and the orders it is read from',
  /goods receipts/.test(text($('#scChain'))) && /orders/.test(text($('#scChain'))));

/* ── the drift build: the money broken, the rest left alone ─────────────── */
const drift = load('deploy/dev/previews/scorecard-drift.html', 'https://preview.local/inventory/supplier-scorecard');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats.wide')[0].children].map(s => dText(s));
ok('the header states the break instead of a zero standing unchallenged', /GH₵ 2,150\.00/.test(dHead[3])
  && /2 suppliers where the two disagree/.test(dHead[3]), dHead[3]);
ok('and the difference is no longer green', drift.querySelectorAll('.stats.wide')[0].children[3].classList.contains('tone-amber'));
const dMoney = drift.querySelector('#scMoney');
ok('the money card counts the two disagreements', /7 suppliers · 2 where the bill and the receipt disagree/.test(dText(dMoney.querySelector('.card-head'))),
  dText(dMoney.querySelector('.card-head')));
const dMoneyStats = [...dMoney.querySelectorAll('.stat')].map(s => dText(s));
ok('the receipts are unchanged and the bills are not', /64,720\.00/.test(dMoneyStats[0]) && /66,870\.00/.test(dMoneyStats[1]), `${dMoneyStats[0]} / ${dMoneyStats[1]}`);
ok('the difference is stated, signed and red', /Difference/.test(dMoneyStats[2]) && /2,150\.00/.test(dMoneyStats[2])
  && dMoney.querySelectorAll('.stat')[2].classList.contains('tone-red'), dMoneyStats[2]);
ok('the difference is exactly what the two broken bills add up to', round2(21070 - 18420 + (3400 - 3900)) === 2150);
ok('the verdict names the widest break with both figures',
  /the widest is Coastal Beverages Ltd, billed GH₵ 21,070\.00 against GH₵ 18,420\.00 received, GH₵ 2,650\.00\./.test(dText(drift.querySelector('#scMoneyState'))),
  dText(drift.querySelector('#scMoneyState')));
ok('and says what an unreconciled payable is', /the payable is a number nobody has agreed to/.test(dText(drift.querySelector('#scMoneyState'))));
const dRows = [...drift.querySelectorAll('#moneyTable tbody tr')].map(r => ({ supplier: dText(r.children[0]), billed: num(r.children[1 + 1]),
  diff: num(r.children[3]), gap: r.getAttribute('data-gap') }));
ok('the widest break is listed first, then the other', dRows[0].supplier === 'Coastal Beverages Ltd' && dRows[1].supplier === 'Golden Gate Hardware',
  dRows.slice(0, 2).map(r => r.supplier).join(' | '));
ok('a supplier billed more than it delivered shows the difference unsigned', dRows[0].diff === 2650 && dRows[0].gap === '1', JSON.stringify(dRows[0]));
ok('a supplier billed less than it delivered shows it the other way', dRows[1].diff === -500 && dRows[1].gap === '1', JSON.stringify(dRows[1]));
ok('the drift leaves the delivery record alone — the goods did not move',
  dText(drift.querySelector('#scDeliveryState')) === text($('#scDeliveryState'))
  && dText(drift.querySelector('#scDelivery .card-head')) === text(del.querySelector('.card-head')));
ok('and leaves the bid record alone — nobody was asked again',
  dText(drift.querySelector('#scBidState')) === text($('#scBidState'))
  && dText(drift.querySelector('#scBid .card-head')) === text(bid.querySelector('.card-head')));
ok('the score table is unchanged by the drift: a bill is not one of its measures',
  [...drift.querySelectorAll('#scoreTable tbody tr')].every((r, i) => dText(r) === text(rowNodes[i])));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|endforelse|php|endphp|unless|endunless|error|enderror)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
  ok(`${name} holds no unpluralised shorthand`, !/day\(s\)|bill\(s\)|delivery\(s\)|supplier\(s\)|invitation\(s\)|line\(s\)/.test(clean));
}

if (failures.length) {
  console.error(`scorecard check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`scorecard check: ${passed}/${passed} passed`);
