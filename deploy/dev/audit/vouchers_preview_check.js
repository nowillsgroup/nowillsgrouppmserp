#!/usr/bin/env node
/*
 * Voucher register — every receipt and payment written in the period, analysed by head, and held to the posted ledger.
 *
 * A voucher is the only document the module writes that must end up in the general ledger at a named account head, so
 * this check is about two things: the register's own arithmetic, and whether the period's vouchers got there.
 *
 *   1. the register totals itself — 18,800.00 in, 46,100.00 out, 11 vouchers — and the analysis by head adds to the same
 *      two figures, head by head, with the net of each head the difference of its columns;
 *   2. every head is named and typed, and the table's footer is the sum of the rows above it, not a second figure;
 *   3. the analysis is the same money the P&L prints for the same period — the six payment heads total 46,100.00 and
 *      Other Income is its 6,800.00 line, read out of deploy/dev/previews/pnl.html rather than asserted from the sample;
 *   4. the tie is head by head against the ledger, over the nine vouchers dated on or before the posted-through day, and
 *      the two vouchers dated after it are counted and excluded rather than quietly skewing the comparison;
 *   5. the drift build names Fuel — one head, 7,800.00 — and does not claim the period is whole.
 *
 * Renders deploy/dev/previews/vouchers.html and vouchers-drift.html in jsdom against
 * deploy/dev/preview/vouchers-sample.mjs.
 *
 * Run: node deploy/dev/audit/vouchers_preview_check.js
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
const blank = (cell) => text(cell) === '' || text(cell) === '—';

const doc = load('deploy/dev/previews/vouchers.html', 'https://preview.local/accounts/vouchers?from=2026-11-01&to=2026-11-07');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the document ───────────────────────────────────────────────────────── */
ok('the page is the voucher register', /Vouchers/.test(doc.title), doc.title);
ok('it sits under accounts', /Accounts \/ Voucher Register/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the period is a control that cannot run into the future',
  $('input[name="from"]')?.getAttribute('max') === '2026-11-07' && $('input[name="to"]')?.getAttribute('max') === '2026-11-07'
  && $('input[name="from"]')?.value === '2026-11-01' && $('input[name="to"]')?.value === '2026-11-07',
  `${$('input[name="from"]')?.value} → ${$('input[name="to"]')?.value}`);
ok('a voucher can be written from the page', $$('.actions button').some(b => /Payment/.test(text(b))) && $$('.actions button').some(b => /Receipt/.test(text(b))));

/* ── the register totals itself ─────────────────────────────────────────── */
const stats = [...doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')].map(s => text(s));
ok('the header states receipts, payments, net and count', stats.length === 4, `${stats.length}`);
ok('receipts are 18,800.00', /Receipts/.test(stats[0]) && /18,800\.00/.test(stats[0]), stats[0]);
ok('payments are 46,100.00', /Payments/.test(stats[1]) && /46,100\.00/.test(stats[1]), stats[1]);
ok('the net is the two subtracted', /Net/.test(stats[2]) && /-27,300\.00/.test(stats[2]) && 18800 - 46100 === -27300, stats[2]);
ok('the period is named on the net figure', /01 Nov 2026 – 07 Nov 2026/.test(stats[2]), stats[2]);
ok('and every voucher written is counted', /Vouchers/.test(stats[3]) && /11/.test(stats[3]), stats[3]);

const rows = [...$$('#vouchersTable tbody tr')];
ok('the register lists the period', rows.length === 11, `${rows.length}`);
const rowIn = Math.round(rows.filter(r => r.getAttribute('data-type') === 'receipt').reduce((t, r) => t + num(r.children[8]), 0) * 100) / 100;
const rowOut = Math.round(rows.filter(r => r.getAttribute('data-type') === 'payment').reduce((t, r) => t + num(r.children[8]), 0) * 100) / 100;
ok('its receipts are the header figure', rowIn === 18800, `${rowIn}`);
ok('its payments are the header figure', rowOut === 46100, `${rowOut}`);
const foot = $('#vouchersTable tfoot tr');
ok('the table prints its own total and count', num(foot.children[1]) === 64900 && /Total — 11 vouchers/.test(text(foot)), text(foot));
ok('every voucher names an account head and a voucher number', rows.every(r => text(r.children[0]) !== '' && text(r.children[3]) !== ''));
ok('a receipt and a payment are told apart by badge, not by colour alone',
  rows.filter(r => r.getAttribute('data-type') === 'receipt').every(r => /badge-green/.test(r.children[2].innerHTML))
  && rows.filter(r => r.getAttribute('data-type') === 'payment').every(r => /badge-red/.test(r.children[2].innerHTML)));

/* ── the analysis by head ───────────────────────────────────────────────── */
const headRows = [...$$('#voucherHeads tbody tr')];
ok('the period is analysed by head', headRows.length === 8, `${headRows.length}`);
ok('each head says how many vouchers it holds and of what kind',
  headRows.every(r => Number(text(r.children[2])) > 0) && headRows.every(r => /receipt|payment/.test(text(r.children[1]))));
const headIn = Math.round(headRows.reduce((t, r) => t + (blank(r.children[3]) ? 0 : num(r.children[3])), 0) * 100) / 100;
const headOut = Math.round(headRows.reduce((t, r) => t + (blank(r.children[4]) ? 0 : num(r.children[4])), 0) * 100) / 100;
ok('the heads’ receipts are the register’s receipts', headIn === 18800 && headIn === rowIn, `${headIn}`);
ok('the heads’ payments are the register’s payments', headOut === 46100 && headOut === rowOut, `${headOut}`);
const netBroken = headRows.filter(r => {
  const net = num(r.children[5]);
  const expected = Math.round(((blank(r.children[3]) ? 0 : num(r.children[3])) - (blank(r.children[4]) ? 0 : num(r.children[4]))) * 100) / 100;
  return Math.abs(net - expected) > 0.004;
});
ok('each head’s net is its receipts less its payments', netBroken.length === 0, netBroken.map(r => text(r.children[0])).join(' | '));
const headFoot = $('#voucherHeads tfoot tr');
ok('the analysis totals itself', text(headFoot.children[1]) === '11' && num(headFoot.children[2]) === 18800 && num(headFoot.children[3]) === 46100 && num(headFoot.children[4]) === -27300, text(headFoot));
ok('a head that only takes money shows no payments', headRows.filter(r => /receipt/.test(text(r.children[1]))).every(r => blank(r.children[4])));
ok('a head that only pays out shows no receipts', headRows.filter(r => /payment/.test(r.children[1].innerHTML)).every(r => blank(r.children[3])));

/* ── the analysis is the P&L's own lines ────────────────────────────────── */
const pnl = load('deploy/dev/previews/pnl.html', 'https://preview.local/accounts/profit-loss');
const pnlExpenseTotal = Number((text(pnl.querySelector('#pnlExpenseTotal')).match(/[\d,]+\.\d{2}/) ?? ['0'])[0].replace(/,/g, ''));
const pnlIncomeTotal = Number((text(pnl.querySelector('#pnlIncomeTotal')).match(/[\d,]+\.\d{2}/) ?? ['0'])[0].replace(/,/g, ''));
ok('the payment heads are the P&L’s expense total for the same week', headOut === pnlExpenseTotal && pnlExpenseTotal === 46100, `${headOut} vs ${pnlExpenseTotal}`);
ok('and the P&L’s other income is this register’s Other Income head',
  headRows.some(r => /Other Income/.test(text(r.children[0])) && num(r.children[3]) === 6800)
  && pnlIncomeTotal === 203600, `${pnlIncomeTotal}`);
ok('a company receipt is a voucher but not income', headRows.some(r => /City Ledger Receipts/.test(text(r.children[0])) && num(r.children[3]) === 12000));
ok('the page says where those heads are printed', /profit & (and|amp;) loss|profit & loss/.test(text(doc.body)) && /the payment heads are its expenses/.test(text(doc.body)));

/* ── the tie to the posted ledger ───────────────────────────────────────── */
const tie = $('#voucherTie');
ok('the page asks whether the period is in the ledger', tie !== null && /Are these vouchers in the ledger\?/.test(text(tie)));
ok('it says how many posted and how many have not', /9 posted · 2 not yet/.test(text(tie.querySelector('.card-head'))), text(tie.querySelector('.card-head')));
ok('the posted-through day is named', /posted through 06 Nov 2026/.test(text(tie.querySelector('.card-head'))));
const tieStats = [...tie.querySelectorAll('.stat')].map(s => text(s));
ok('it states the register’s expectation', /The register says/.test(tieStats[0]) && /37,850\.00/.test(tieStats[0]), tieStats[0]);
ok('the ledger’s movement', /The ledger says/.test(tieStats[1]) && /37,850\.00/.test(tieStats[1]), tieStats[1]);
ok('and a nil difference that says so', /Difference/.test(tieStats[2]) && /0\.00/.test(tieStats[2]) && /the two agree/.test(tieStats[2]), tieStats[2]);
ok('a nil difference is green', tie.querySelectorAll('.stat')[2].classList.contains('tone-green') === true);
const state = text($('#voucherTieState'));
ok('the page states the vouchers are in the ledger at their heads', /at its own head, for GH₵ 37,850\.00 of movement/.test(state), state);
ok('and that the two late vouchers are excluded, not missing', /2 vouchers are dated after that day/.test(state) && /have not been posted yet/.test(state), state);
ok('it does not cry defect when it agrees', !/a difference of/.test(state));

/* ── the drift build: one head named ────────────────────────────────────── */
const drift = load('deploy/dev/previews/vouchers-drift.html', 'https://preview.local/accounts/vouchers?from=2026-11-01&to=2026-11-07');
const dTie = drift.querySelector('#voucherTie');
const dStats = [...dTie.querySelectorAll('.stat')].map(s => text(s));
ok('a difference is stated with both figures', /37,850\.00/.test(dStats[0]) && /30,050\.00/.test(dStats[1]), `${dStats[0]} / ${dStats[1]}`);
ok('the difference is 7,800.00 and red', /7,800\.00/.test(dStats[2]) && /the two disagree/.test(dStats[2]) && dTie.querySelectorAll('.stat')[2].classList.contains('tone-red'));
const dState = text(drift.querySelector('#voucherTieState'));
ok('the page names the head that is out', /Fuel \(register 7,800\.00, ledger 0\.00\)/.test(dState), dState);
ok('and only that head', (dState.match(/\(register/g) ?? []).length === 1, dState);
ok('it says what to check', /sync failures on the general ledger page/.test(dState), dState);
ok('the register itself is unchanged by the ledger being behind',
  num(drift.querySelector('#vhOut')) === 46100 && num(drift.querySelector('#vhIn')) === 18800
  && [...drift.querySelectorAll('#vouchersTable tbody tr')].length === 11, text(drift.querySelector('#vhOut')));
ok('the two late vouchers are counted the same way in both builds', /9 posted · 2 not yet/.test(text(dTie.querySelector('.card-head'))));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the register', doc], ['the drifted register', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless|isset|endisset)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
  ok(`${name} holds no escaped markup printed as text`, !/&lt;(nav|span|a|div|td)\b/.test(clean));
}

if (failures.length) {
  console.error(`voucher register check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`voucher register check: ${passed}/${passed} passed`);
