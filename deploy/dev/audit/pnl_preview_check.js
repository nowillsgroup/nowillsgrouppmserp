#!/usr/bin/env node
/*
 * Profit & loss — the period's income, its expenses, its result, and whether the ledger posted the same thing.
 *
 * The statement is built from documents (folio charges, POS takings, receipt and payment vouchers); the general ledger is
 * built from posted journals. One period read twice, so the check is mostly arithmetic and then agreement:
 *
 *   1. the tables add up — income lines total 203,600.00, expense heads total 46,100.00, the printed totals are those sums,
 *      and the result below is income less expense;
 *   2. every income line is named, not keyed — a category that never got a label prints as `fnb`, which is how a chart of
 *      accounts leaks onto a statement;
 *   3. the ledger column is the same money — 203,600.00 and 46,100.00 — and the page says the statement can be issued;
 *   4. when it is not, the drift build names the line that is out (revenue, by 6,800.00), leaves the other alone, and does
 *      not claim the statement is ready.
 *
 * Renders deploy/dev/previews/pnl.html and pnl-drift.html in jsdom against deploy/dev/preview/pnl-sample.mjs.
 *
 * Run: node deploy/dev/audit/pnl_preview_check.js
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

const doc = load('deploy/dev/previews/pnl.html', 'https://preview.local/accounts/profit-loss?from=2026-11-01&to=2026-11-07');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the document ───────────────────────────────────────────────────────── */
ok('the page is the P&L', /Profit & Loss/.test(doc.title), doc.title);
ok('it sits under accounts', /Accounts \/ Profit & Loss/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the period is a working control', $('input[name="from"]')?.value === '2026-11-01' && $('input[name="to"]')?.value === '2026-11-07',
  `${$('input[name="from"]')?.value} → ${$('input[name="to"]')?.value}`);
ok('and cannot be run into the future', $('input[name="from"]')?.getAttribute('max') === '2026-11-07' && $('input[name="to"]')?.getAttribute('max') === '2026-11-07');
ok('it prints', $$('.actions button').some(b => /Print/.test(text(b))));

/* ── the income table ───────────────────────────────────────────────────── */
const incomeRows = [...$$('#pnlIncome tbody tr')];
ok('income is itemised', incomeRows.length === 12, `${incomeRows.length}`);
const incomeSum = Math.round(incomeRows.reduce((t, r) => t + num(r.children[1]), 0) * 100) / 100;
ok('the income lines add to the total the footer prints', incomeSum === 203600 && num($('#pnlIncomeTotal')) === incomeSum,
  `${incomeSum} vs ${num($('#pnlIncomeTotal'))}`);
const incomeLabels = incomeRows.map(r => text(r.children[0]));
ok('every income line is named, not keyed', incomeLabels.every(l => !/^[a-z_]+$/.test(l)), incomeLabels.join(' | '));
ok('the categories print their chart-of-accounts names', incomeLabels.includes('Room Tariff') && incomeLabels.includes('Food & Beverage') && incomeLabels.includes('Spa'), incomeLabels.join(' | '));
ok('discounts are shown as a deduction', incomeLabels.some(l => /Less: Discounts/.test(l)) && incomeRows.some(r => /Less: Discounts/.test(text(r.children[0])) && num(r.children[1]) < 0));
ok('direct POS settlements are a line of their own', incomeLabels.includes('POS sales (direct settlement)') && incomeRows.some(r => /POS sales/.test(text(r.children[0])) && num(r.children[1]) === 28400));
ok('receipts that are not company settlements are income too', incomeLabels.some(l => /Banquet hall hire/.test(l)), incomeLabels.join(' | '));

/* ── the expense table ──────────────────────────────────────────────────── */
const expenseRows = [...$$('#pnlExpenses tbody tr')];
ok('expenses are itemised by head', expenseRows.length === 6, `${expenseRows.length}`);
const expenseSum = Math.round(expenseRows.reduce((t, r) => t + num(r.children[1]), 0) * 100) / 100;
ok('the expense heads add to the total the footer prints', expenseSum === 46100 && num($('#pnlExpenseTotal')) === expenseSum,
  `${expenseSum} vs ${num($('#pnlExpenseTotal'))}`);
ok('the card says how many heads it is', /6 heads from the voucher register/.test(text($('#pnlExpenses').closest('.card').querySelector('.card-head'))), text($('#pnlExpenses').closest('.card').querySelector('.card-head')));

/* ── the result ─────────────────────────────────────────────────────────── */
const stats = [...$$('.stats')[0].querySelectorAll('.stat')].map(s => text(s));
ok('the statement states its result', stats.length === 3, `${stats.length}`);
ok('net profit is income less expense, 157,500.00', /157,500\.00/.test(stats[0]) && 203600 - 46100 === 157500, stats[0]);
ok('and says which two figures it came from', /203,600\.00 income less 46,100\.00 expense/.test(stats[0]), stats[0]);
ok('a profit is green', $$('.stats')[0].querySelectorAll('.stat')[0].classList.contains('tone-green') === true);
ok('the margin is 77.4% of income', /77\.4%/.test(stats[1]), stats[1]);
ok('tax collected is stated but kept out of income', /34,600\.00/.test(stats[2]) && /never income/.test(stats[2]), stats[2]);

/* ── the ledger column ──────────────────────────────────────────────────── */
const tieTable = $('#pnlTieTable');
const tieRows = [...tieTable.querySelectorAll('tbody tr')];
ok('the statement states the ledger alongside it', tieRows.length === 2, `${tieRows.length}`);
const revenue = tieRows.find(r => r.getAttribute('data-line') === 'revenue');
const expense = tieRows.find(r => r.getAttribute('data-line') === 'expense');
ok('revenue is 203,600.00 on both sides', num(revenue.children[1]) === 203600 && num(revenue.children[2]) === 203600 && num(revenue.children[3]) === 0, text(revenue));
ok('expenses are 46,100.00 on both sides', num(expense.children[1]) === 46100 && num(expense.children[2]) === 46100 && num(expense.children[3]) === 0, text(expense));
ok('a nil difference is not printed in red', !revenue.querySelector('.text-red') && !expense.querySelector('.text-red'));
const state = text($('#pnlTieState'));
ok('the page says the ledger posted the same money', /The ledger posted GH₵ 203,600\.00 of income and GH₵ 46,100\.00 of expense/.test(state), state);
ok('and that the statement can be issued', /can be issued as it stands/.test(state), state);
ok('the statement does not blame the sync when it agrees', !/sync has not posted/.test(state));
ok('the period is named with its dates', /01 Nov 2026 – 07 Nov 2026/.test(text($('#pnlTie .card-head'))), text($('#pnlTie .card-head')));
ok('a period past the posted-through day says a difference would be expected', /still outside the ledger/.test(text($('#pnlTiePosted'))), text($('#pnlTiePosted')));

/* ── the drift build: one line out, named ───────────────────────────────── */
const drift = load('deploy/dev/previews/pnl-drift.html', 'https://preview.local/accounts/profit-loss?from=2026-11-01&to=2026-11-07');
const dRows = [...drift.querySelectorAll('#pnlTieTable tbody tr')];
const dRevenue = dRows.find(r => r.getAttribute('data-line') === 'revenue');
const dExpense = dRows.find(r => r.getAttribute('data-line') === 'expense');
ok('the drifted revenue is shown on both sides', num(dRevenue.children[1]) === 203600 && num(dRevenue.children[2]) === 196800, text(dRevenue));
ok('with the difference printed and red', num(dRevenue.children[3]) === 6800 && dRevenue.querySelector('.text-red') !== null, text(dRevenue));
ok('the line that agrees stays clean', num(dExpense.children[3]) === 0 && dExpense.querySelector('.text-red') === null, text(dExpense));
const dState = text(drift.querySelector('#pnlTieState'));
ok('the sentence names revenue and only revenue', /Revenue is out by GH₵ 6,800\.00\./.test(dState) && !/expenses are out by/.test(dState), dState);
ok('it says where to look', /sync failures on the general ledger page/.test(dState), dState);
ok('and it does not claim the statement is ready', !/can be issued/.test(dState));
ok('the statement’s own figures are unchanged by the ledger being behind',
  num(drift.querySelector('#pnlIncomeTotal')) === 203600 && num(drift.querySelector('#pnlExpenseTotal')) === 46100
  && /157,500\.00/.test(text(drift.querySelectorAll('.stats')[0])), text(drift.querySelectorAll('.stats')[0]));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the statement', doc], ['the drifted statement', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless|isset|endisset)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
  ok(`${name} holds no escaped markup printed as text`, !/&lt;(nav|span|a|div|td)\b/.test(clean));
}

if (failures.length) {
  console.error(`P&L check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`P&L check: ${passed}/${passed} passed`);
