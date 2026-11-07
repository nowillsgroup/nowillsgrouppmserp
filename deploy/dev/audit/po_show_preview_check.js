#!/usr/bin/env node
/*
 * The purchase order document, read the way the whole chain reads it.
 *
 * This is the one screen every other document in procure-to-AP points at — the requisition that asked for the goods, the
 * goods receipts that delivered them, the supplier bill that charges for them and the payment that clears that bill all
 * carry the order number. v9.26 gives it the three readings it was missing:
 *
 *   1. the order in five figures — 8,579.00 ordered, 7,465.80 arrived, 1,113.20 still owed, 7,465.80 billed, ten days
 *      past the expected date;
 *   2. the gate — two deliveries, the second six days late, 101.20 of broken crates refused and never entering the store;
 *   3. the three-way match — what arrived against what the supplier charged for, which is the only place in the
 *      application where those two figures are set against each other for a single order;
 *   4. the rate card — the one line priced above the supplier's agreed rate (Coca-Cola at 38.00 against 36.50, 30.00
 *      over), because that is the cost nobody approved;
 *   5. the drift build — the same order billed for the whole of it: the match turns red with 1,113.20 invoiced and never
 *      received, and every other figure on the screen stays exactly where it was.
 *
 * Renders deploy/dev/previews/po-show.html and po-show-drift.html in jsdom against deploy/dev/preview/po-show-sample.mjs.
 *
 * Run: node deploy/dev/audit/po_show_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const load = (file) => new JSDOM(readFileSync(file, 'utf8'), { url: 'https://preview.local/purchases/302' }).window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const num = (node) => Number((((text(node).match(/[−-]?[\d,]+\.?\d*/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);
const round2 = (v) => Math.round(v * 100) / 100;

const doc = load('deploy/dev/previews/po-show.html');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the order in five figures ──────────────────────────────────────────── */
const head = [...$$('.stats.wide')[0].children].map(s => text(s));
ok('the header carries five figures', head.length === 5, `${head.length}`);
ok('what was ordered is the first, with the VAT inside it',
  /8,579\.00/.test(head[0]) && /^GH₵ 8,579\.00/.test(head[0]) && /4 lines into Beverage Store — VAT GH₵ 1,119\.00/.test(head[0]), head[0]);
ok('what arrived is the second, with the late delivery called out',
  /7,465\.80/.test(head[1]) && /1 of 2 arrived after the expected date/.test(head[1]), head[1]);
ok('what is still owed is stated with VAT, not net',
  /^GH₵ 1,113\.20/.test(head[2]) && !/GH₵ 968\.00/.test(head[2]) && /22 of Club Beer/.test(head[2])
  && /1 line still to come/.test(head[2]), head[2]);
ok('what the supplier charged for is the fourth', /7,465\.80/.test(head[3]) && /the bill matches what arrived/.test(head[3]), head[3]);
ok('and how late the order is, with the money riding on it',
  /^10 days/.test(head[4]) && /28 Oct 2026/.test(head[4]) && /GH₵ 1,113\.20 of the order is still out there/.test(head[4]), head[4]);
ok('the still-owed figure is the third stat, in amber because something is owed',
  $$('.stats.wide')[0].children[2].classList.contains('tone-amber'));

/* ── the order itself ───────────────────────────────────────────────────── */
const order = $('#poOrder');
ok('the order card names the document, the supplier, the store and the day it was raised',
  /PO-2026-0302 · Coastal Beverages Ltd · Beverage Store · raised 24 Oct 2026/.test(text(order.querySelector('.card-head'))),
  text(order.querySelector('.card-head')));
const kv = text(order);
ok('the supplier panel carries the contact and the TIN', /Esi Danquah/.test(kv) && /030 221 4478/.test(kv) && /C0012345678/.test(kv));
ok('the dates panel carries both dates, and the overdue badge', /28 Oct 2026/.test(kv)
  && order.querySelector('#poOverdue') !== null, text(order.querySelector('#poOverdue')));
ok('the approval panel names who approved it at which level',
  /Kofi Mensah · 24 Oct 2026 L2/.test(kv), kv.slice(kv.indexOf('Approved'), kv.indexOf('Approved') + 60));
ok('the order was approved by somebody other than the person who raised it',
  /Created by/.test(kv) && /Ama Boateng/.test(kv) && /Kofi Mensah/.test(kv)
  && text(order.querySelectorAll('.kv')[2].children[1]) === 'Ama Boateng'
  && /Kofi Mensah/.test(text(order.querySelectorAll('.kv')[2].children[3])),
  text(order.querySelectorAll('.kv')[2]));
const oStats = [...order.querySelectorAll('.stat')].map(s => text(s));
ok('the order’s own strip counts its lines', /Lines/.test(oStats[0]) && /^4/.test(oStats[0]), oStats[0]);
ok('and splits the value into net and VAT', /Order value/.test(oStats[1]) && /8,579\.00/.test(oStats[1])
  && /VAT GH₵ 1,119\.00 on a net GH₵ 7,460\.00/.test(oStats[1]), oStats[1]);
ok('the one line above the agreed rate is named with both rates',
  /Above the agreed rate/.test(oStats[2]) && /^1/.test(oStats[2]) && /Coca-Cola/.test(oStats[2])
  && /38\.00 against 36\.50 agreed/.test(oStats[2]) && /GH₵ 30\.00 over on the order/.test(oStats[2]), oStats[2]);
ok('the approver is on the strip with the level label', /Approved by/.test(oStats[3]) && /Kofi Mensah/.test(oStats[3])
  && /Manager approval · 24 Oct 2026/.test(oStats[3]), oStats[3]);

/* ── line by line ───────────────────────────────────────────────────────── */
const cols = [...$$('#poItems thead th')].map(th => text(th));
ok('the line table keeps its ten columns',
  cols.join('|') === '#|Item|Ordered|Received|Rejected|Outstanding|Rate · GHS|Agreed · GHS|Tax %|Amount · GHS', cols.join('|'));
const rows = [...$$('#poItems tbody tr')].map(r => ({
  n: num(r.children[0]), item: text(r.children[1]), ordered: num(r.children[2]), received: num(r.children[3]),
  rejected: text(r.children[4]), outstanding: text(r.children[5]), rate: num(r.children[6]), agreed: num(r.children[7]),
  tax: num(r.children[8]), amount: num(r.children[9]), above: r.querySelector('[data-above]') !== null,
}));
ok('all four lines are listed in the order they were raised',
  rows.length === 4 && rows.map(r => r.n).join(',') === '1,2,3,4' && /Club Beer/.test(rows[0].item) && /Coca-Cola/.test(rows[3].item));
ok('the ordered quantities are the order’s own',
  rows.map(r => r.ordered).join(',') === '100,30,50,20', rows.map(r => r.ordered).join(','));
ok('what the gate accepted is on each line, and the refused crates with it',
  rows[0].received === 78 && rows[0].rejected === '2' && rows[1].received === 30 && rows[3].received === 20);
ok('the outstanding balance is the difference, not a separate figure',
  rows.map(r => num({ textContent: r.outstanding })).join(',') === '22,0,0,0',
  rows.map(r => r.outstanding).join(','));
ok('the amounts add to the order and the outstanding adds to what is owed',
  round2(rows.reduce((t, r) => t + r.amount, 0)) === 7460
  && round2(rows.reduce((t, r) => t + num({ textContent: r.outstanding }) * r.rate, 0)) === 968);
ok('only the line priced above the agreed rate is marked', rows.filter(r => r.above).length === 1
  && rows[3].above && rows[3].rate === 38 && rows[3].agreed === 36.5);
const foot = [...$$('#poItems tfoot tr')].map(r => text(r));
ok('the foot carries subtotal, tax, total and the received value',
  /Subtotal · GHS\s*GH₵ 7,460\.00/.test(foot[0]) && /Tax · GHS\s*GH₵ 1,119\.00/.test(foot[1])
  && /Total · GHS\s*GH₵ 8,579\.00/.test(foot[2]) && /Received value · GHS\s*GH₵ 7,465\.80/.test(foot[3]), foot.join(' || '));

/* ── the three-way match ────────────────────────────────────────────────── */
const match = $('#poMatch');
ok('the match card asks the question the screen exists for',
  /What arrived, and what the supplier charged for/.test(text(match)));
ok('and answers it in a badge', /the bill matches what arrived/.test(text(match.querySelector('.badge'))),
  text(match.querySelector('.badge')));
const mStats = [...match.querySelectorAll('.stat')].map(s => text(s));
ok('ordered, arrived, billed and the difference are the four figures',
  /Ordered/.test(mStats[0]) && /8,579\.00/.test(mStats[0]) && /Arrived/.test(mStats[1]) && /7,465\.80/.test(mStats[1])
  && /Billed/.test(mStats[2]) && /7,465\.80/.test(mStats[2]) && /Difference/.test(mStats[3]) && /0\.00/.test(mStats[3]),
  mStats.join(' || '));
ok('the billed figure names the bill and its status', /CBL-2026-0629 · open/.test(mStats[2]), mStats[2]);
ok('a clean match says so in words rather than by leaving the number at zero',
  /the bill and the gate agree to the pesewa/.test(mStats[3]));
ok('and the refused goods are on the arrived figure', /2 deliveries, GH₵ 101\.20 refused at the gate/.test(mStats[1]), mStats[1]);
ok('the verdict states the match and then what is still owed',
  /nothing has been charged for that the store did not receive/.test(text($('#poMatchState')))
  && /22 units of goods are still owed on 1 line/.test(text($('#poMatchState')))
  && /GH₵ 968\.00 before tax .* GH₵ 1,113\.20 with VAT/.test(text($('#poMatchState'))), text($('#poMatchState')));
const owed = [...$$('#poOwed tbody tr')].map(r => ({ item: text(r.children[0]), qty: num(r.children[1]), value: num(r.children[2]) }));
ok('the balance still to come is a table, not a sentence', owed.length === 1
  && /Club Beer/.test(owed[0].item) && owed[0].qty === 22 && owed[0].value === 968, JSON.stringify(owed));
ok('its foot totals to what the header claims', round2(owed.reduce((t, r) => t + r.value, 0)) === 968
  && /Outstanding\s*22\s*968\.00/.test(text($('#poOwed tfoot'))), text($('#poOwed tfoot')));

/* ── the gate ───────────────────────────────────────────────────────────── */
const arrival = $('#poArrival');
ok('the arrival card totals the deliveries, their dates and what was accepted',
  /2 deliveries · GH₵ 7,465\.80 accepted · 28 Oct 2026 to 03 Nov 2026/.test(text(arrival.querySelector('.card-head'))),
  text(arrival.querySelector('.card-head')));
const aStats = [...arrival.querySelectorAll('.stat')].map(s => text(s));
ok('deliveries, accepted, refused and the worst delay are the four readings',
  /Deliveries/.test(aStats[0]) && /^2/.test(aStats[0]) && /Accepted/.test(aStats[1]) && /7,465\.80/.test(aStats[1])
  && /Refused/.test(aStats[2]) && /101\.20/.test(aStats[2]) && /Worst delay/.test(aStats[3]) && /6 days/.test(aStats[3]),
  aStats.join(' || '));
ok('what was refused says which item and why',
  /2 of Club Beer .* — broken crates/.test(aStats[2]), aStats[2]);
ok('the worst delay names the receipt that was late', /GRN-2026-0051 came after the 28 Oct 2026 expected date/.test(aStats[3]), aStats[3]);
const grns = [...$$('#poReceipts tbody tr')].map(r => ({ no: text(r.children[0]), date: text(r.children[1]),
  note: text(r.children[2]), items: text(r.children[3]), accepted: num(r.children[4]), rejected: text(r.children[5]),
  by: text(r.children[6]), late: r.children[1].querySelector('.badge') !== null }));
ok('both receipts are listed', grns.length === 2 && grns.map(g => g.no).join(',') === 'GRN-2026-0043,GRN-2026-0051');
ok('the late one is the only one marked late', grns[1].late && !grns[0].late);
ok('the accepted values are each receipt’s own', grns[0].accepted === 5681 && grns[1].accepted === 1784.8,
  `${grns[0].accepted} / ${grns[1].accepted}`);
ok('the refused crates are on the receipt that refused them, and only there',
  grns[1].rejected === '101.20' && grns[0].rejected === '—', `${grns[0].rejected} / ${grns[1].rejected}`);
ok('the reason travels with the refused quantity', /−2: broken crates/.test(grns[1].items), grns[1].items);
ok('the two receipts add up to the arrived figure', round2(grns.reduce((t, g) => t + g.accepted, 0)) === 7465.8);
ok('the delivery notes are quoted, so the gate can be checked against the paperwork',
  /CBL\/DN\/00412/.test(grns[0].note) && /CBL\/DN\/00467/.test(grns[1].note));
ok('each receipt names who received it', grns.every(g => g.by === 'Yaw Antwi'));

/* ── the bill, and the chain ────────────────────────────────────────────── */
const bill = $('#poBill');
ok('the matched bill is on the screen with what it still owes',
  /CBL-2026-0629/.test(text(bill)) && /outstanding GH₵ 7,465\.80/.test(text(bill)) && /due 04 Dec 2026/.test(text(bill)), text(bill));
ok('and it offers the payment', /Pay/.test(text(bill)));
ok('the page places the order in the chain it anchors',
  /A purchase order is the only document the whole chain points at/.test(text($('#poChain')))
  && /the requisition that asked for it/.test(text($('#poChain')))
  && /a bill matched to an order the gate never filled is money out of the door/.test(text($('#poChain'))), text($('#poChain')));
ok('nothing reads as the old bare list any more', !/delivery\(ies\)/.test(text(doc.body)));

/* ── the drift build ────────────────────────────────────────────────────── */
const drift = load('deploy/dev/previews/po-show-drift.html');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats.wide')[0].children].map(s => dText(s));
ok('the drifted build bills the whole order', /^GH₵ 8,579\.00/.test(dHead[3]), dHead[3]);
ok('and says what that means', /the supplier has billed for goods that never arrived/.test(dHead[3]), dHead[3]);
const dStats = [...drift.querySelectorAll('#poMatch .stat')].map(s => dText(s));
ok('the difference is the money invoiced and never received',
  /^GH₵ 1,113\.20/.test(dStats[3]) && /invoiced and never received/.test(dStats[3]), dStats[3]);
ok('the match is red, because this one is a loss and not a note',
  drift.querySelector('#poMatch .badge').classList.contains('badge-red'),
  drift.querySelector('#poMatch .badge').className);
ok('the verdict says which of the two documents is missing',
  /billed GH₵ 1,113\.20 more than was received — the goods or the credit note is missing/.test(dText(drift.querySelector('#poMatchState'))),
  dText(drift.querySelector('#poMatchState')));
ok('nothing else on the order moved: same order, same arrival, same eleven hundred owed',
  /GH₵ 8,579\.00/.test(dHead[0]) && /GH₵ 7,465\.80/.test(dHead[1]) && /^GH₵ 1,113\.20/.test(dHead[2]) && /^10 days/.test(dHead[4]));
ok('the gate did not change because the invoice did',
  dText(drift.querySelectorAll('#poArrival .stat')[2]) === text(doc.querySelectorAll('#poArrival .stat')[2]));
ok('the bill itself is what moved', /GH₵ 8,579\.00/.test(dText(drift.querySelector('#poBill'))), dText(drift.querySelector('#poBill')));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|php|endphp|unless|selected|checked|error|enderror|push|endpush)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder or leaked attribute`, !/\{\{|\}\}|:sub=|:tone=/.test(clean));
  ok(`${name} holds no unpluralised shorthand`, !/\(ies\)|delivery\(ies\)|line\(s\)/.test(clean));
  ok(`${name} prints no figure with a bare decimal`, !/GH₵ [\d,]+\.\d(?!\d)/.test(clean));
}

if (failures.length) {
  console.error(`purchase order check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`purchase order check: ${passed}/${passed} passed`);
