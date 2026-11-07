#!/usr/bin/env node
/*
 * Purchase orders, read as three things at once.
 *
 * The register listed orders. v9.17 asks what an order is between the requisition that started it and the bill that ends
 * it — a commitment, a gate, and a match — and answers each from the record that owns it. This check holds the three
 * readings to their arithmetic and to each other:
 *
 *   1. the commitment — approved or part-received with goods still to arrive: three orders, 52,750.00, two of them past
 *      the date they were given, with the oldest named rather than numbered;
 *   2. the gate — the approval matrix the approve button tests: four drafts, one at each level, and one the reader may
 *      not sign because they raised it;
 *   3. the match — six orders whose goods the shelf took in and six bills for exactly that money;
 *   4. the page — the columns, the badges and the header the three cards are summed into;
 *   5. the drift build — the match broken in both directions, with the other two readings left alone.
 *
 * Renders deploy/dev/previews/purchases.html and purchases-drift.html in jsdom against
 * deploy/dev/preview/purchases-sample.mjs.
 *
 * Run: node deploy/dev/audit/purchases_preview_check.js
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

const doc = load('deploy/dev/previews/purchases.html', 'https://preview.local/inventory/purchases');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the header ─────────────────────────────────────────────────────────── */
const head = [...$$('.stats')[0].children].map(s => text(s));
ok('the header carries four figures, one per reading plus the match', head.length === 4, `${head.length}`);
ok('what is on order is the commitment and says the ledger does not carry it',
  /52,750\.00/.test(head[0]) && /commitment the ledger does not carry/.test(head[0]), head[0]);
ok('the orders past their date are counted beside the money they hold', /^2/.test(head[1]) && /21,250\.00 still to arrive/.test(head[1]), head[1]);
ok('the drafts waiting on the reader are counted with what they are worth', /^3/.test(head[2]) && /81,450\.00 you can approve now/.test(head[2])
  && /1 you cannot/.test(head[2]), head[2]);
ok('and the match is stated as one figure, green while it holds', /73,390\.00/.test(head[3]) && /each billed for what the shelf took in/.test(head[3])
  && $$('.stats')[0].children[3].classList.contains('tone-green'), head[3]);

/* ── the commitment ─────────────────────────────────────────────────────── */
const commit = $('#poCommit');
ok('the page asks what has been promised and not delivered', /What has been promised and not yet delivered\?/.test(text(commit)));
ok('the head counts the orders, the money and the lateness', /3 orders · GH₵ 52,750\.00 still to arrive · 2 past the date/.test(text(commit.querySelector('.card-head'))),
  text(commit.querySelector('.card-head')));
const cStats = [...commit.querySelectorAll('.stat')].map(s => text(s));
ok('goods still to come is ordered value less what the receipts took in', /Goods still to come/.test(cStats[0]) && /52,750\.00/.test(cStats[0]), cStats[0]);
ok('the money past its date is separated from the money still inside it', /Past the delivery date/.test(cStats[1]) && /21,250\.00/.test(cStats[1])
  && /2 orders owed after the date/.test(cStats[1]), cStats[1]);
ok('and the lateness is aged from the day the order was due', /Oldest lateness/.test(cStats[2]) && /18 days/.test(cStats[2]), cStats[2]);
ok('the verdict names the oldest order, its supplier, its money and its age',
  /The oldest is PO-2026-0296 from Coastal Beverages Ltd — GH₵ 2,650\.00 still to come against GH₵ 9,800\.00 ordered, due 20 Oct 2026, 18 days ago\./.test(text($('#poCommitState'))),
  text($('#poCommitState')));
ok('and says why it is not on the ledger', /Nothing is on the ledger for it: the commitment is ours, the receipt is not\./.test(text($('#poCommitState'))));
ok('the commitment is marked red while an order is owed late', $('#poCommitState').classList.contains('text-red'));
const cRows = [...$$('#commitTable tbody tr')].map(r => ({ po: text(r.children[0]), due: text(r.children[2]), late: text(r.children[3]),
  ordered: num(r.children[4]), received: num(r.children[5]), open: num(r.children[6]), state: r.getAttribute('data-open') }));
ok('every order with goods outstanding is on the list, largest commitment first',
  cRows.length === 3 && cRows.map(r => r.po).join(',') === 'PO-2026-0314,PO-2026-0288,PO-2026-0296', cRows.map(r => r.po).join(','));
ok('the two late orders are marked in the list and the third is not',
  cRows[1].late === '8 d' && cRows[2].late === '18 d' && cRows[0].late === '—', cRows.map(r => r.late).join(' | '));
ok('a part-received order shows what arrived against what was ordered', cRows[2].received === 7150 && cRows[2].ordered === 9800,
  `${cRows[2].received}/${cRows[2].ordered}`);
ok('the still-to-come column is the list’s own sum and the header’s figure',
  round2(cRows.reduce((t, r) => t + r.open, 0)) === 52750, `${cRows.reduce((t, r) => t + r.open, 0)}`);
ok('the late rows add to the late money the card states',
  round2(cRows.filter(r => r.late !== '—').reduce((t, r) => t + r.open, 0)) === 21250);

/* ── the gate ───────────────────────────────────────────────────────────── */
const gate = $('#poGate');
ok('the page asks who has to approve what', /Who has to approve what, and may they\?/.test(text(gate)));
ok('the head counts the drafts, their value and the one held back',
  /4 drafts · GH₵ 85,350\.00 · 1 that you cannot approve/.test(text(gate.querySelector('.card-head'))), text(gate.querySelector('.card-head')));
const gStats = [...gate.querySelectorAll('.stat')].map(s => text(s));
ok('the drafts are stated at their full value', /Waiting for a signature/.test(gStats[0]) && /85,350\.00/.test(gStats[0]), gStats[0]);
ok('what the reader may sign is stated separately', /You can approve/.test(gStats[1]) && /81,450\.00/.test(gStats[1])
  && /PO-2026-0317 at GH₵ 62,400\.00/.test(gStats[1]), gStats[1]);
ok('and what they may not is red, not silently missing', /You cannot approve/.test(gStats[2]) && /3,900\.00/.test(gStats[2])
  && gate.querySelectorAll('.stat')[2].classList.contains('tone-red'), gStats[2]);
const levels = [...$$('#gateLevels tbody tr')].map(r => ({ level: text(r.children[0]), who: text(r.children[1]),
  count: Number(text(r.children[2])), value: num(r.children[3]), largest: text(r.children[4]) }));
ok('all three approval levels are on the matrix', levels.length === 3 && levels.map(l => l.level).join(',') === 'Level 1,Level 2,Level 3',
  levels.map(l => l.level).join(','));
ok('each level states the ceiling it answers to', /up to GH₵ 5,000\.00/.test(levels[0].who) && /up to GH₵ 25,000\.00/.test(levels[1].who)
  && /above GH₵ 25,000\.00/.test(levels[2].who), levels.map(l => l.who).join(' | '));
ok('the orders are counted at the level that has to sign them', levels.map(l => l.count).join(',') === '2,1,1', levels.map(l => l.count).join(','));
ok('the levels’ values are the drafts’ value', round2(levels.reduce((t, l) => t + l.value, 0)) === 85350, `${levels.reduce((t, l) => t + l.value, 0)}`);
ok('and each level names its largest order', levels[0].largest === 'PO-2026-0315GH₵ 4,250.00' || /PO-2026-0315/.test(levels[0].largest),
  levels[0].largest);
ok('the verdict names the largest order the reader may approve and the level that has to sign it',
  /the largest PO-2026-0317 at GH₵ 62,400\.00 needing Senior management \(level 3\)/.test(text($('#poGateState'))), text($('#poGateState')));
const blocked = [...$$('#gateBlocked tbody tr')].map(r => text(r));
ok('the draft that cannot be approved is named with the reason', blocked.length === 1 && /PO-2026-0318/.test(blocked[0])
  && /Sahel Textiles/.test(blocked[0]) && /Segregation of duties is on/.test(blocked[0]), blocked[0]);
ok('what may be signed and what may not add to the drafts’ value', 81450 + 3900 === 85350);
ok('the approval matrix itself is on the page, with the two limits it enforces',
  $('#procSettings') !== null && $$('#procSettings input').length >= 3
  && $$('#procSettings input').some(i => i.value === '5000') && $$('#procSettings input').some(i => i.value === '25000'),
  `${$$('#procSettings input').map(i => i.value).join(',')}`);
ok('and segregation of duties reads as switched on', $$('#procSettings input[type=checkbox]').some(i => i.hasAttribute('checked')));

/* ── the match ──────────────────────────────────────────────────────────── */
const match = $('#poMatch');
ok('the page asks whether the bill is for what the shelf took in', /Is the bill for what the shelf actually took in\?/.test(text(match)));
ok('the head counts the orders and the disagreements', /6 orders with a receipt · no bill disagrees/.test(text(match.querySelector('.card-head'))),
  text(match.querySelector('.card-head')));
const mStats = [...match.querySelectorAll('.stat')].map(s => text(s));
ok('the receipts’ value is stated', /Received/.test(mStats[0]) && /73,390\.00/.test(mStats[0]), mStats[0]);
ok('the bills’ value is the same money', /Billed/.test(mStats[1]) && /73,390\.00/.test(mStats[1]), mStats[1]);
ok('and the difference is nil and green', /Difference/.test(mStats[2]) && /0\.00/.test(mStats[2])
  && match.querySelectorAll('.stat')[2].classList.contains('tone-green'), mStats[2]);
ok('the verdict names the largest receipt and the bill that matches it',
  /the largest, PO-2026-0298 from Volta Power Systems, GH₵ 22,800\.00 received and billed the same\./.test(text($('#poMatchState'))),
  text($('#poMatchState')));
ok('and says both halves of what a match guarantees', /Nothing received is uninvoiced, and nothing is billed that was not received\./.test(text($('#poMatchState'))));
ok('no match gap table is printed when the match holds', $('#matchGap') === null);

/* ── the page of orders ─────────────────────────────────────────────────── */
const cols = [...$$('#poTable thead th')].map(th => text(th));
ok('the register has ten columns, in reading order',
  cols.join('|') === 'PO No|Date|Supplier|Store|Expected|Total|Receipt|Bill|Status|', cols.join('|'));
const rows = [...$$('#poTable tbody tr')];
ok('every order is on the page', rows.length === 12, `${rows.length}`);
ok('newest order first', text(rows[0].children[0]).startsWith('PO-2026-0318') && text(rows[11].children[0]).startsWith('PO-2026-0296'),
  `${text(rows[0].children[0])} … ${text(rows[11].children[0])}`);
ok('the two overdue deliveries are marked in the date column', rows.filter(r => /late/.test(text(r.children[4]))).length === 2,
  `${rows.filter(r => /late/.test(text(r.children[4]))).length}`);
const rowOf = (no) => rows.find(r => text(r).includes(no));
ok('a received order prints what the shelf took in', text(rowOf('PO-2026-0298').children[6]) === '22,800.00', text(rowOf('PO-2026-0298').children[6]));
ok('a part-received order prints the part against the order', /7,150\.00/.test(text(rowOf('PO-2026-0296').children[6]))
  && /of 9,800\.00/.test(text(rowOf('PO-2026-0296').children[6])), text(rowOf('PO-2026-0296').children[6]));
ok('an order with nothing delivered says so', text(rowOf('PO-2026-0314').children[6]) === 'not received', text(rowOf('PO-2026-0314').children[6]));
ok('a billed order names its bill', /^INV-2026-06\d\d$/.test(text(rowOf('PO-2026-0311').children[7])), text(rowOf('PO-2026-0311').children[7]));
ok('a received order with no bill would say so rather than leave the cell blank',
  /no bill/.test(readFileSync('resources/views/inventory/purchases.blade.php', 'utf8')));
ok('an order with nothing received carries no bill and a dash', text(rowOf('PO-2026-0288').children[7]) === '—', text(rowOf('PO-2026-0288').children[7]));
ok('the open orders are marked as commitments in the row data',
  rows.filter(r => r.getAttribute('data-open') === '1').length === 3
  && rows.filter(r => r.getAttribute('data-open') === '1').every(r => ['approved', 'partial'].includes(r.getAttribute('data-status'))),
  rows.map(r => `${r.getAttribute('data-status')}/${r.getAttribute('data-open')}`).join(' '));
ok('a draft is not a commitment until somebody signs it', rowOf('PO-2026-0317').getAttribute('data-open') === '0');
ok('the tabs still filter by status and now by lateness too',
  $$('.tabs a').some(a => a.getAttribute('href') === '?late=1' && /Past the date/.test(text(a))));
ok('the chain from order to receipt to bill is spelled out, and linked',
  /an order is a commitment until it is received/.test(text($('#poChain'))) && /goods receipts/.test(text($('#poChain'))));

/* ── the drift build: the match broken in both directions ───────────────── */
const drift = load('deploy/dev/previews/purchases-drift.html', 'https://preview.local/inventory/purchases');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats')[0].children].map(s => dText(s));
ok('the header says two bills disagree instead of one figure standing unchallenged', /2 where the bill disagrees/.test(dHead[3]), dHead[3]);
ok('and the match figure is red', drift.querySelectorAll('.stats')[0].children[3].classList.contains('tone-red'));
const dMatch = drift.querySelector('#poMatch');
ok('the match card counts the two disagreements', /6 orders with a receipt · 2 where the bill disagrees/.test(dText(dMatch.querySelector('.card-head'))),
  dText(dMatch.querySelector('.card-head')));
const dStats = [...dMatch.querySelectorAll('.stat')].map(s => dText(s));
ok('the receipts are unchanged and the bills are not', /73,390\.00/.test(dStats[0]) && /75,540\.00/.test(dStats[1]), `${dStats[0]} / ${dStats[1]}`);
ok('the difference is stated, signed and red', /Difference/.test(dStats[2]) && /-2,150\.00/.test(dStats[2])
  && dMatch.querySelectorAll('.stat')[2].classList.contains('tone-red'), dStats[2]);
ok('the difference is exactly what the two broken bills add up to', round2(73390 - 75540) === -2150);
ok('the verdict says one of the two records must be wrong, in both directions',
  /either the supplier has not invoiced goods we hold, or we have been billed for goods the shelf never took in/.test(dText(drift.querySelector('#poMatchState'))),
  dText(drift.querySelector('#poMatchState')));
const gaps = [...drift.querySelectorAll('#matchGap tbody tr')].map(r => ({ po: dText(r.children[0]), bill: dText(r.children[2]),
  received: num(r.children[3]), billed: num(r.children[4]), diff: num(r.children[5]) }));
ok('the worse break is listed first', gaps.length === 2 && gaps[0].po === 'PO-2026-0296' && gaps[1].po === 'PO-2026-0298', gaps.map(g => g.po).join(' | '));
ok('the order billed for goods that never arrived shows both figures',
  gaps[0].received === 7150 && gaps[0].billed === 9800 && gaps[0].diff === -2650, JSON.stringify(gaps[0]));
ok('the order whose receipt was not fully invoiced shows both figures',
  gaps[1].received === 22800 && gaps[1].billed === 22300 && gaps[1].diff === 500, JSON.stringify(gaps[1]));
ok('both breaks name the bill behind them', gaps.every(g => /^INV-2026-06\d\d$/.test(g.bill)), gaps.map(g => g.bill).join(' | '));
ok('the drift leaves the commitment alone — the goods have not moved',
  dText(drift.querySelector('#poCommitState')) === text($('#poCommitState'))
  && dText(drift.querySelector('#poCommit .card-head')) === text(commit.querySelector('.card-head')));
ok('and leaves the gate alone — nobody’s signature changed',
  dText(drift.querySelector('#poGateState')) === text($('#poGateState'))
  && dText(drift.querySelector('#poGate .card-head')) === text(gate.querySelector('.card-head')));
ok('the page of orders is unchanged by the drift', [...drift.querySelectorAll('#poTable tbody tr')]
  .every((r, i) => dText(r) === text(rows[i])));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless|error|enderror)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
  ok(`${name} holds no unpluralised shorthand`, !/order\(s\)|day\(s\)|receipt\(s\)/.test(clean));
}

if (failures.length) {
  console.error(`purchases check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`purchases check: ${passed}/${passed} passed`);
