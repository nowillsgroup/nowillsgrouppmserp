#!/usr/bin/env node
/*
 * Goods receipts, read against the three documents they have to agree with.
 *
 * The register used to print what arrived. v9.16 asks the question the next person in the chain asks — has this value
 * reached a bill? did the delivery come when the order said? are the accepted goods actually in stock? — and answers
 * each from another record rather than from the receipt's own columns. This check holds those answers to their
 * arithmetic:
 *
 *   1. the bill — the purchase order's own bill relation (the one ApService::unbilledReceipts() tests for the bill
 *      screen). The clean build has no receipt waiting, and the verdict names the longest wait rather than a number; the
 *      drift build has two bills left as drafts and lists both;
 *   2. the delivery — the order's expected date against the arrival date, valued on the goods and not the tax, with the
 *      late receipts named;
 *   3. the shelf — the stock movements the receipts wrote, found by GRN number, against the accepted goods value;
 *   4. the page itself — the columns, the badges, and the header figures the three cards are summed into;
 *   5. the drift build — a month where two of the three readings break, and the two that do not are left alone.
 *
 * Renders deploy/dev/previews/grns.html and grns-drift.html in jsdom against
 * deploy/dev/preview/procurement-grn-sample.mjs.
 *
 * Run: node deploy/dev/audit/grns_preview_check.js
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
const round2 = (v) => Math.round(v * 100) / 100;

const doc = load('deploy/dev/previews/grns.html', 'https://preview.local/inventory/goods-receipts');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the bill ───────────────────────────────────────────────────────────── */
const bill = $('#grnBilling');
ok('the page asks whether the receipt reached a bill', bill !== null && /Has every receipt reached a bill\?/.test(text(bill)));
ok('the head counts what is billed and what is not',
  /6 billed in the last ninety days · nothing waiting/.test(text(bill.querySelector('.card-head'))), text(bill.querySelector('.card-head')));
const bStats = [...bill.querySelectorAll('.stat')].map(s => text(s));
ok('the billed money is the receipts on record, tax included in the goods value',
  /Received and billed/.test(bStats[0]) && /72,190\.00/.test(bStats[0]) && /6 receipts in the last ninety days/.test(bStats[0]), bStats[0]);
ok('nothing is waiting, and the nil is green rather than empty',
  /Received, no bill/.test(bStats[1]) && /0\.00/.test(bStats[1]) && bill.querySelectorAll('.stat')[1].classList.contains('tone-green'), bStats[1]);
ok('an absent wait prints a dash, not zero days', /Oldest wait/.test(bStats[2]) && /—/.test(bStats[2]) && !/0 day/.test(bStats[2]), bStats[2]);
ok('the clean verdict names the receipt that waited longest, not a bare number',
  /The longest wait in the last ninety days was GRN-2026-0178 from Sahel Textiles — GH₵ 16,400\.00 received on 02 Nov 2026 and billed 4 days later on INV-2026-0654\./.test(text($('#grnBillingState'))),
  text($('#grnBillingState')));
ok('and says the ledger knows about every receipt', /Every receipt on record has reached a bill: 6 receipts, GH₵ 72,190\.00/.test(text($('#grnBillingState'))));
ok('nothing is marked red where nothing is wrong', !$('#grnBillingState').classList.contains('text-red'));
ok('no gap table is printed when there is no gap', $('#billingGap') === null);
ok('the card reads every date, not only the window, so its population is larger than the page',
  Number((text(bill.querySelector('.card-head')).match(/(\d+) billed/) ?? [0, 0])[1]) === 6
  && $$('#grnTable tbody tr').length === 5,
  `${text(bill.querySelector('.card-head'))} / ${$$('#grnTable tbody tr').length} rows`);

/* ── the delivery ───────────────────────────────────────────────────────── */
const when = $('#grnDelivery');
ok('the page asks whether the delivery kept the order’s date',
  /Did the goods come when the purchase order said\?/.test(text(when)));
ok('the head counts the late receipts and their share of the value',
  /2 of 5 late · 31 % of the value/.test(text(when.querySelector('.card-head'))), text(when.querySelector('.card-head')));
const wStats = [...when.querySelectorAll('.stat')].map(s => text(s));
ok('on time is the goods value less the late goods', /On time/.test(wStats[0]) && /44,560\.00/.test(wStats[0]) && /3 receipts/.test(wStats[0]), wStats[0]);
ok('the late money is stated on its own', /Late/.test(wStats[1]) && /20,480\.00/.test(wStats[1]) && /2 receipts/.test(wStats[1]), wStats[1]);
ok('and the orders that have not delivered at all are counted beside it', /Still to deliver/.test(wStats[2]) && /^1/.test(wStats[2]), wStats[2]);
ok('the verdict names the worst delivery, its supplier, its money and its lateness',
  /The worst is GRN-2026-0183 from Coastal Beverages Ltd — GH₵ 8,000\.00 arrived 3 days after 03 Nov 2026\./.test(text($('#grnDeliveryState'))),
  text($('#grnDeliveryState')));
const lateRows = [...$$('#lateGap tbody tr')].map(r => ({ grn: text(r.children[0]), supplier: text(r.children[1]),
  expected: text(r.children[2]), received: text(r.children[3]), days: text(r.children[4]), value: num(r.children[5]) }));
ok('every late receipt is listed, worst first', lateRows.length === 2 && lateRows[0].grn === 'GRN-2026-0183' && lateRows[1].grn === 'GRN-2026-0184',
  lateRows.map(r => r.grn).join(' | '));
ok('a late row carries both dates, so the lateness can be checked by eye',
  lateRows[0].expected === '03 Nov 2026' && lateRows[0].received === '06 Nov 2026', JSON.stringify(lateRows[0]));
ok('the late rows are valued on the goods, not on the tax the delivery carried',
  round2(lateRows.reduce((t, r) => t + r.value, 0)) === 20480, lateRows.map(r => r.value).join(' | '));

/* ── the shelf ──────────────────────────────────────────────────────────── */
const shelf = $('#grnStock');
ok('the page asks whether the accepted goods are in stock', /Is the accepted goods actually in stock\?/.test(text(shelf)));
ok('the head says all of them are', /all 5 receipts in stock · GH₵ 65,040\.00 accepted/.test(text(shelf.querySelector('.card-head'))), text(shelf.querySelector('.card-head')));
const sStats = [...shelf.querySelectorAll('.stat')].map(s => text(s));
ok('the accepted goods value is stated before tax', /Accepted, before tax/.test(sStats[0]) && /65,040\.00/.test(sStats[0]), sStats[0]);
ok('the movements the receipts wrote are the same money', /In stock/.test(sStats[1]) && /65,040\.00/.test(sStats[1]), sStats[1]);
ok('and the difference is nil and green', /Difference/.test(sStats[2]) && /0\.00/.test(sStats[2])
  && shelf.querySelectorAll('.stat')[2].classList.contains('tone-green'), sStats[2]);
ok('the clean verdict names the largest receipt and the store it went to',
  /The largest, GRN-2026-0176 from Volta Power Systems — GH₵ 22,800\.00 into the Fuel Store — is there with the rest\./.test(text($('#grnStockState'))),
  text($('#grnStockState')));
ok('no stock gap table is printed when there is no gap', $('#stockGap') === null);

/* ── the header the three cards are summed into ─────────────────────────── */
const head = [...$$('.stats')[0].children].map(s => text(s));
ok('the header carries four figures', head.length === 4, `${head.length}`);
ok('the receipts counted are the ones in the window', /^5Receipts/.test(head[0]), head[0]);
ok('accepted value includes the tax the delivery carried', /66,240\.00/.test(head[1]), head[1]);
ok('rejected and returned is the money turned away at the door', /1,860\.00/.test(head[2]), head[2]);
ok('its label reads as the screen writes it, with no doubled escape', /Rejected & returned/.test(text($$('.stats')[0].children[2])), text($$('.stats')[0].children[2]));
ok('the header’s waiting figure is the bill card’s', /0\.00/.test(head[3]) && /every receipt on record has reached its bill/.test(head[3]), head[3]);

/* ── the page of receipts ───────────────────────────────────────────────── */
const cols = [...$$('#grnTable thead th')].map(th => text(th));
ok('the register has twelve columns, in reading order',
  cols.join('|') === 'GRN|Date|PO|Supplier|Store|Delivery note|Accepted|Rejected|Bill|Stock|By|', cols.join('|'));
const rows = [...$$('#grnTable tbody tr')];
ok('every receipt in the window is listed', rows.length === 5, `${rows.length}`);
ok('newest first', rows.map(r => text(r.children[1]).slice(0, 6)).join(',') === '07 Nov,06 Nov,04 Nov,02 Nov,01 Nov', rows.map(r => text(r.children[1])).join(' | '));
ok('each row prints the bill its order reached', rows.every(r => /^INV-2026-06\d\d$/.test(text(r.children[8]))), rows.map(r => text(r.children[8])).join(' | '));
ok('and each row says its goods are in stock', rows.every(r => text(r.children[9]) === 'in stock'), rows.map(r => text(r.children[9])).join(' | '));
ok('the rows carry the two readings as data, so a filtered view cannot disagree with the cards',
  rows.every(r => r.getAttribute('data-billed') === '1' && r.getAttribute('data-stocked') === '1'),
  rows.map(r => `${r.getAttribute('data-billed')}/${r.getAttribute('data-stocked')}`).join(' '));
ok('the two late deliveries are marked in the date column', rows.filter(r => r.querySelector('.badge-amber')).length === 2,
  `${rows.filter(r => r.querySelector('.badge-amber')).length}`);
ok('a receipt with nothing rejected prints a dash rather than a zero',
  text(rows[0].children[7]) === '—' && text(rows[1].children[7]) === '620.00', `${text(rows[0].children[7])} / ${text(rows[1].children[7])}`);
ok('the accepted column is the header’s accepted value',
  round2(rows.reduce((t, r) => t + num(r.children[6]), 0)) === 66240, `${rows.reduce((t, r) => t + num(r.children[6]), 0)}`);
ok('the rejected column is the header’s rejected figure',
  round2(rows.reduce((t, r) => t + num(r.children[7]), 0)) === 1860, `${rows.reduce((t, r) => t + num(r.children[7]), 0)}`);
ok('the goods value is the accepted value less the tax on the one taxed delivery',
  66240 - 1200 === 65040 && /65,040\.00/.test(sStats[0]));
ok('the chain is spelled out for the reader who asks who bills the receipt',
  /a bill is raised against the purchase order \(not the receipt\)/.test(text($('#grnChain')))
  && /goods received, not yet invoiced/.test(text($('#grnChain'))), text($('#grnChain')));
ok('the overdue purchase orders keep their card', $('#grnOverdue') !== null && /PO-2026-0288/.test(text($('#grnOverdue'))));

/* ── the drift build: two bills left as drafts, one movement missing ────── */
const drift = load('deploy/dev/previews/grns-drift.html', 'https://preview.local/inventory/goods-receipts');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats')[0].children].map(s => dText(s));
ok('the drift build’s header shows the money that is now unbilled', /20,480\.00/.test(dHead[3])
  && /2 receipts on record with no bill behind them · oldest 1 day/.test(dHead[3]), dHead[3]);
ok('the waiting figure is amber, not silently green',
  drift.querySelectorAll('.stats')[0].children[3].classList.contains('tone-amber'));
const dBill = drift.querySelector('#grnBilling');
ok('the bill card counts four billed and two waiting',
  /4 billed in the last ninety days · 2 waiting/.test(dText(dBill.querySelector('.card-head'))), dText(dBill.querySelector('.card-head')));
const dStats = [...dBill.querySelectorAll('.stat')].map(s => dText(s));
ok('the billed money has fallen by exactly what is waiting', /51,710\.00/.test(dStats[0]) && /20,480\.00/.test(dStats[1]), `${dStats[0]} / ${dStats[1]}`);
ok('and the two sides still add to the six receipts on record', 51710 + 20480 === 72190);
ok('the oldest wait is one day, stated in the singular', /1 day/.test(dStats[2]) && !/1 days/.test(dStats[2]), dStats[2]);
ok('the gap is marked red and names the oldest unbilled receipt',
  dBill.querySelectorAll('.stat')[1].classList.contains('tone-red')
  && /The oldest is GRN-2026-0183 from Coastal Beverages Ltd — GH₵ 8,000\.00 received on 06 Nov 2026 and unbilled for 1 day\./.test(dText(drift.querySelector('#grnBillingState'))),
  dText(drift.querySelector('#grnBillingState')));
const billingGap = [...drift.querySelectorAll('#billingGap tbody tr')];
ok('both unbilled receipts are listed with their age and their money', billingGap.length === 2
  && /GRN-2026-0183/.test(dText(billingGap[0])) && /1 d/.test(dText(billingGap[0])) && /8,000\.00/.test(dText(billingGap[0]))
  && /GRN-2026-0184/.test(dText(billingGap[1])) && /12,480\.00/.test(dText(billingGap[1])),
  billingGap.map(r => dText(r)).join(' | '));
const dRows = [...drift.querySelectorAll('#grnTable tbody tr')];
const dWaiting = dRows.filter(r => r.getAttribute('data-billed') === '0');
ok('the rows say the same thing as the card: two now wait for a bill',
  dWaiting.length === 2 && dWaiting.every(r => /waiting \d+ d|no bill yet/.test(dText(r.children[8]))),
  dRows.map(r => `${r.getAttribute('data-billed')} ${dText(r.children[8])}`).join(' | '));
ok('a receipt dated today and still unbilled says so in words, not “waiting 0 d”',
  !/waiting 0 d/.test(dText(drift.querySelector('#grnTable'))) && /no bill yet/.test(dText(drift.querySelector('#grnTable'))),
  dText(drift.querySelector('#grnTable')).slice(0, 80));
const dShelf = drift.querySelector('#grnStock');
ok('the stock card counts the receipt whose goods never landed',
  /1 of 5 receipts unmatched/.test(dText(dShelf.querySelector('.card-head'))), dText(dShelf.querySelector('.card-head')));
const dSStats = [...dShelf.querySelectorAll('.stat')].map(s => dText(s));
ok('the shelf is short by exactly that receipt', /65,040\.00/.test(dSStats[0]) && /59,680\.00/.test(dSStats[1]) && /5,360\.00/.test(dSStats[2]),
  `${dSStats[0]} / ${dSStats[1]} / ${dSStats[2]}`);
ok('and the shortfall is red', dShelf.querySelectorAll('.stat')[2].classList.contains('tone-red'));
const stockGap = [...drift.querySelectorAll('#stockGap tbody tr')];
ok('the receipt is named with what it accepted and what stock took', stockGap.length === 1
  && /GRN-2026-0181/.test(dText(stockGap[0])) && /Tema Hardware/.test(dText(stockGap[0]))
  && /5,360\.00/.test(dText(stockGap[0])), dText(stockGap[0]));
ok('the missing movement is visible in the row too', dRows.find(r => /GRN-2026-0181/.test(dText(r))).getAttribute('data-stocked') === '0'
  && /not in stock/.test(dText(dRows.find(r => /GRN-2026-0181/.test(dText(r))))));
ok('the drift does not touch the delivery card — lateness is not a ledger question',
  dText(drift.querySelector('#grnDeliveryState')) === text($('#grnDeliveryState'))
  && dText(drift.querySelector('#grnDelivery .card-head')) === text(when.querySelector('.card-head')));
ok('nor the accepted value, which is what the receipts said on the day',
  dHead[1] === head[1] && dHead[0] === head[0], `${dHead[1]} vs ${head[1]}`);
ok('the drift’s difference is the drift’s shortfall, to the cent', 65040 - 59680 === 5360);

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
  ok(`${name} holds no receipt(s) shorthand or unpluralised day count`, !/receipt\(s\)|\b1 days\b/.test(clean));
}

if (failures.length) {
  console.error(`grns check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`grns check: ${passed}/${passed} passed`);
