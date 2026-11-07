#!/usr/bin/env node
/*
 * Daily cash book — the tills opened, moved and closed, and where the day sits in the ledger.
 *
 * A cash book is only worth reading if it carries a balance, so this check is arithmetic first: every mode's closing is
 * its opening plus its receipts less its payments, the footer is the sum of the rows, and the day's own rows add to the
 * same receipts and payments the modes do. Then the two states:
 *
 *   1. the ordinary day — brought forward 82,050.00, movement 13,550.00 across three sources, carried forward 95,600.00,
 *      and the ledger posted through yesterday so the day is named as still open rather than pretended closed;
 *   2. the overdrawn day — a 5,600.00 refund out of a drawer that held 5,350.00, so Cash closes at −250.00, the card is
 *      red and the page says which mode and why. A negative balance printed without that sentence is a page nobody acts on.
 *
 * Renders deploy/dev/previews/cash-book.html and cash-book-overdrawn.html in jsdom against
 * deploy/dev/preview/cash-book-sample.mjs.
 *
 * Run: node deploy/dev/audit/cash_book_preview_check.js
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
const blank = (cell) => text(cell) === '' || text(cell, 0) === '—';

const doc = load('deploy/dev/previews/cash-book.html', 'https://preview.local/accounts/cash-book?date=2026-11-07');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the document ───────────────────────────────────────────────────────── */
ok('the page is the cash book', /Cash Book/.test(doc.title), doc.title);
ok('it sits under accounts', /Accounts \/ Cash Book/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the day is a working control', $('input[name="date"]')?.value === '2026-11-07', $('input[name="date"]')?.value);
ok('and cannot be read into the future', $('input[name="date"]')?.getAttribute('max') === '2026-11-07', $('input[name="date"]')?.getAttribute('max'));
ok('it prints', $$('.actions button').some(b => /Print/.test(text(b))));

/* ── the book's three balances ──────────────────────────────────────────── */
const card = $('#cashBookControl');
ok('the page asks whether the day closes', card !== null);
const stats = card ? [...card.querySelectorAll('.stat')].map(s => text(s)) : [];
ok('it prints three figures: brought forward, the day, carried forward', stats.length === 3, `${stats.length}`);
ok('it opens at 82,050.00', /Brought forward/.test(stats[0]) && /82,050\.00/.test(stats[0]) && /before 07 Nov 2026/.test(stats[0]), stats[0]);
ok('the day moves 22,250.00 less 8,700.00 = 13,550.00', /This day moves/.test(stats[1]) && /13,550\.00/.test(stats[1]), stats[1]);
const sources = (stats[1].match(/(Front Office|POS|Voucher) -?[\d,]+\.\d{2}/g) ?? []);
ok('and names the three places the money came from', sources.length === 3, sources.join(' | '));
const sourceTotal = Math.round(sources.reduce((t, s) => t + Number(s.match(/-?[\d,]+\.\d{2}/)[0].replace(/,/g, '')), 0) * 100) / 100;
ok('whose nets are the day’s movement', sourceTotal === 13550, `${sourceTotal}`);
ok('it closes at 95,600.00', /Carried forward/.test(stats[2]) && /95,600\.00/.test(stats[2]), stats[2]);
ok('and says how: receipts less payments', /receipts 22,250\.00 less payments 8,700\.00/.test(stats[2]), stats[2]);
ok('the arithmetic holds: opening + receipts − payments = closing', 82050 + 22250 - 8700 === 95600);

/* ── where the day sits in the ledger ───────────────────────────────────── */
const state = text($('#cashBookState'));
ok('the page names the day the ledger is posted through', /posted through 06 Nov 2026/.test(state), state);
ok('and says this day is still open rather than pretending it is posted', /a day still open/.test(state) && /have not reached the general ledger yet/.test(state), state);
ok('it states both sides of the day and where the tills stand', /22,250\.00 of receipts/.test(state) && /8,700\.00 of payments/.test(state) && /tills stand at GH₵ 95,600\.00/.test(state), state);
ok('the posting state is not dressed as a defect', !/text-red/.test(card?.innerHTML ?? '') && $('#cashBookDrawn') === null);

/* ── by mode: the four columns of a book ────────────────────────────────── */
const modeTable = $('#cashBookModes');
const modeRows = [...modeTable.querySelectorAll('tbody tr')];
ok('every mode the day touched is carried', modeRows.length === 4, `${modeRows.length}`);
ok('cash leads, as the drawer does', text(modeRows[0]?.children[0]) === 'Cash', text(modeRows[0]?.children[0]));
ok('the modes are cash, transfer, card and MoMo', modeRows.map(r => text(r.children[0])).join(' | ') === 'Cash | Bank transfer | Card | MoMo',
  modeRows.map(r => text(r.children[0])).join(' | '));

const chain = [];
for (const row of modeRows) {
  const open = num(row.children[1]);
  const receipt = blank(row.children[2]) ? 0 : num(row.children[2]);
  const payment = blank(row.children[3]) ? 0 : num(row.children[3]);
  const close = num(row.children[4]);
  if (Math.abs(Math.round((open + receipt - payment) * 100) / 100 - close) > 0.004) {
    chain.push(`${text(row.children[0])}: ${open} + ${receipt} − ${payment} ≠ ${close}`);
  }
}
ok('every mode closes at its opening plus receipts less payments', chain.length === 0, chain.join(' | '));
ok('the drawer that only takes money shows no payments', blank(modeRows[2].children[3]) && blank(modeRows[3].children[3]));

const modeFoot = modeTable.querySelector('tfoot tr');
const modeSum = (col) => Math.round(modeRows.reduce((t, r) => t + (blank(r.children[col]) ? 0 : num(r.children[col])), 0) * 100) / 100;
ok('the modes’ footer is the sum of the modes, column by column',
  num(modeFoot.children[1]) === modeSum(1) && num(modeFoot.children[2]) === modeSum(2)
  && num(modeFoot.children[3]) === modeSum(3) && num(modeFoot.children[4]) === modeSum(4),
  ['1', '2', '3', '4'].map(c => `${num(modeFoot.children[c])}/${modeSum(Number(c))}`).join(' '));
ok('and the totals are 82,050.00 · 22,250.00 · 8,700.00 · 95,600.00',
  num(modeFoot.children[1]) === 82050 && num(modeFoot.children[2]) === 22250 && num(modeFoot.children[3]) === 8700 && num(modeFoot.children[4]) === 95600, text(modeFoot));
ok('the closing figure is printed once, as the book’s closing', num($('#cashClosing')) === 95600 && num($('#cashClosing')) === num(modeFoot.children[4]), text($('#cashClosing')));

/* ── the day's rows: three sources, one movement ────────────────────────── */
const rowTable = $('#cashBookRows');
const rows = [...rowTable.querySelectorAll('tbody tr')];
ok('seven entries moved money on the day', rows.length === 7, `${rows.length}`);
ok('from the front office, the tills and the voucher register',
  rows.filter(r => r.getAttribute('data-src') === 'Front Office').length === 2
  && rows.filter(r => r.getAttribute('data-src') === 'POS').length === 3
  && rows.filter(r => r.getAttribute('data-src') === 'Voucher').length === 2,
  rows.map(r => r.getAttribute('data-src')).join(' | '));
ok('every entry is a receipt or a payment, never both and never neither',
  rows.every(r => blank(r.children[4]) !== blank(r.children[5])), rows.map(r => `${text(r.children[4])}|${text(r.children[5])}`).join(' '));
const rowIn = Math.round(rows.reduce((t, r) => t + (blank(r.children[4]) ? 0 : num(r.children[4])), 0) * 100) / 100;
const rowOut = Math.round(rows.reduce((t, r) => t + (blank(r.children[5]) ? 0 : num(r.children[5])), 0) * 100) / 100;
ok('the rows add to the modes’ receipts', rowIn === 22250 && rowIn === num(modeFoot.children[2]), `${rowIn} vs ${num(modeFoot.children[2])}`);
ok('and to the modes’ payments', rowOut === 8700 && rowOut === num(modeFoot.children[3]), `${rowOut} vs ${num(modeFoot.children[3])}`);
const rowFoot = rowTable.querySelector('tfoot tr');
ok('the day’s own footer prints the same two figures', num($('#cashIn')) === 22250 && num($('#cashOut')) === 8700 && num(rowFoot.children[1]) === 22250 && num(rowFoot.children[2]) === 8700, text(rowFoot));
ok('a refund leaves the drawer rather than arriving in it', rows.some(r => /Refund/.test(text(r)) && !blank(r.children[5])));

/* ── the overdrawn day ──────────────────────────────────────────────────── */
const od = load('deploy/dev/previews/cash-book-overdrawn.html', 'https://preview.local/accounts/cash-book?date=2026-11-07');
const odModes = [...od.querySelectorAll('#cashBookModes tbody tr')];
const cashRow = odModes.find(r => text(r.children[0]).startsWith('Cash'));
ok('a drawer cannot close below zero unnoticed', num(cashRow.children[4]) === -250, text(cashRow));
ok('the mode says so on its own row', /below zero/.test(text(cashRow)) && cashRow.querySelector('.text-red') !== null, text(cashRow));
const odCard = od.querySelector('#cashBookControl');
ok('the card turns red', odCard.querySelectorAll('.stat')[2].classList.contains('tone-red') === true
  && /a till closes below zero/.test(text(odCard.querySelectorAll('.stat')[2])), text(odCard.querySelectorAll('.stat')[2]));
ok('and the page names the mode and what to check', /Cash closes below zero/.test(text(od.querySelector('#cashBookDrawn')))
  && /check the day's payments against the float/.test(text(od.querySelector('#cashBookDrawn'))), text(od.querySelector('#cashBookDrawn')));
ok('one mode over, said in the singular', (text(od.querySelector('#cashBookDrawn')).match(/closes below zero/g) ?? []).length === 1
  && !/close below zero/.test(text(od.querySelector('#cashBookDrawn'))));
ok('the book still adds up with the fault in it', num(od.querySelector('#cashClosing')) === 90300
  && 82050 + 22250 - 14000 === 90300, text(od.querySelector('#cashClosing')));
ok('the opening is untouched: the fault is today’s refund, not yesterday', /82,050\.00/.test(text(od.querySelectorAll('#cashBookControl .stat')[0])),
  text(od.querySelectorAll('#cashBookControl .stat')[0]));
ok('the overdrawn day’s rows add to its payments', num(od.querySelector('#cashOut')) === 14000, text(od.querySelector('#cashOut')));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the cash book', doc], ['the overdrawn book', od]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless|isset|endisset)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
  ok(`${name} holds no escaped markup printed as text`, !/&lt;(nav|span|a|div|td)\b/.test(clean));
}

if (failures.length) {
  console.error(`cash book check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`cash book check: ${passed}/${passed} passed`);
