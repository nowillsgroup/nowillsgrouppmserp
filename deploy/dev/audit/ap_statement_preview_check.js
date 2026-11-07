#!/usr/bin/env node
/*
 * Supplier statement — the document a supplier is sent, and whether it agrees with what we say internally.
 *
 * A statement is checked by the person who receives it, line by line, against their own ledger. So this check holds
 * three things:
 *
 *   1. the running balance is real — the opening balance plus every line's credit less its debit is the balance printed
 *      against that line, all the way to the closing 40,900, and the two period columns add to the same number;
 *   2. the statement agrees with the ageing — 40,900 on both, and the card says which of the two states it is in, so an
 *      unexplained difference would be visible before the statement left the building;
 *   3. it reads as a document — goods are owed from the date they arrive, a matched bill adds only its variance, a debit
 *      note reads as a payment, dates are dates, and the printed copy is the same document without the internal card.
 *
 * Renders deploy/dev/previews/ap-statement.html in jsdom against deploy/dev/preview/ap-statement-sample.mjs.
 *
 * Run: node deploy/dev/audit/ap_statement_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const raw = readFileSync('deploy/dev/previews/ap-statement.html', 'utf8');
const dom = new JSDOM(raw, { url: 'https://preview.local/ap/suppliers/7/statement?from=2026-08-10&to=2026-11-07' });
const doc = dom.window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];
// the first money figure in a cell, so a particulars line that carries an invoice amount cannot confuse the reading
const num = (cell) => Number((((text(cell).match(/[−-]?[\d,]+\.\d{2}/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);

/* ── the document ───────────────────────────────────────────────────────── */
ok('the page is a supplier statement', /Supplier Statement – Coastal Beverages Ltd/.test(doc.title), doc.title);
ok('it is the supplier’s statement, reached from the ageing', /AP Ageing \/ Coastal Beverages Ltd/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the period is a working control with both ends',
  $('input[name="from"]')?.value === '2026-08-10' && $('input[name="to"]')?.value === '2026-11-07',
  `${$('input[name="from"]')?.value} – ${$('input[name="to"]')?.value}`);
// the sidebar has a "Print Station" and a "KOT Printers" link; the statement's own button is in the page head
const print = $$('.actions a').find(a => /Print/.test(text(a)));
ok('the printed copy is the same statement, for the same period',
  Boolean(print) && /#ap-statement-7\?/.test(print.getAttribute('href')) && /from=2026-08-10/.test(print.getAttribute('href'))
  && /to=2026-11-07/.test(print.getAttribute('href')) && /print=1/.test(print.getAttribute('href')),
  print?.getAttribute('href'));
ok('and it can be paid from here', $$('.actions a').some(a => /^Pay$/.test(text(a))));

/* ── the summary, as the ageing sees the same supplier ──────────────────── */
const summary = [...doc.querySelectorAll('.ap-summary > div')].map(d => ({ label: text(d.querySelector('.l')), value: num(d.querySelector('.v')) }));
const summaryOf = (label) => summary.find(s => s.label === label)?.value;
ok('the summary leads with what is not yet due', summaryOf('Not yet due') === 26000, JSON.stringify(summary));
ok('then what is overdue', summaryOf('Overdue') === 18500, JSON.stringify(summary));
ok('then the credit on account', summaryOf('Unapplied credit') === 3600, JSON.stringify(summary));
ok('and the balance owed', summaryOf('Balance owed') === 40900, JSON.stringify(summary));
ok('the balance is the bills less the credit', 26000 + 18500 - 3600 === 40900 && summaryOf('Balance owed') === 40900);
ok('an overdue balance is marked in red', /red/.test(doc.querySelectorAll('.ap-summary > div')[1].querySelector('.v').className),
  doc.querySelectorAll('.ap-summary > div')[1].querySelector('.v').className);

/* ── the tie to the ageing ──────────────────────────────────────────────── */
const tie = $('#stmtTie');
ok('the statement says whether it agrees with the ageing', tie !== null);
const tieStats = tie ? [...tie.querySelectorAll('.stat')].map(s => text(s)) : [];
ok('it prints three figures: this statement, the ageing, the difference', tieStats.length === 3, `${tieStats.length}`);
ok('the statement closes at the balance above', /40,900\.00/.test(tieStats[0]) && /This statement closes at/.test(tieStats[0]), tieStats[0]);
ok('the ageing is asked for the same supplier and date', /The ageing says/.test(tieStats[1]) && /40,900\.00/.test(tieStats[1]) && /07 Nov 2026/.test(tieStats[1]), tieStats[1]);
ok('the difference is nil and says so', /Difference/.test(tieStats[2]) && /0\.00/.test(tieStats[2]) && /the two agree/.test(tieStats[2]), tieStats[2]);
ok('a nil difference is green, not amber', tie?.querySelectorAll('.stat')[2].classList.contains('tone-green') === true,
  tie?.querySelectorAll('.stat')[2].className);
ok('and the screen says the statement can be sent', /the ageing at 07 Nov 2026 says the same/.test(text($('#stmtTieState')))
  && /can be sent this statement/.test(text($('#stmtTieState'))), text($('#stmtTieState')));
ok('it does not cry defect when the two agree', !/a difference of/.test(text($('#stmtTieState'))));

/* ── the lines ──────────────────────────────────────────────────────────── */
const table = $('#stmtTable');
const lines = [...table.querySelectorAll('tbody tr')].filter(r => !r.classList.contains('stmt-open') && !r.classList.contains('stmt-close'));
ok('one line per document in the period', lines.length === 6, `${lines.length}`);
ok('each line opens the document behind it', lines.every(r => /^#[a-z-]+-\d+$/.test(r.querySelector('a')?.getAttribute('href') ?? '')),
  lines.map(r => r.querySelector('a')?.getAttribute('href')).join(' | '));
ok('dates print as dates, not as ISO', !/\b2026-\d\d-\d\d\b/.test(text(table)));

// the running balance, recomputed from the columns rather than trusted
const opening = num(table.querySelector('.stmt-open td:last-child'));
let running = opening;
const broken = [];
for (const row of lines) {
  const debit = num(row.children[4]);
  const credit = num(row.children[5]);
  running = Math.round((running + credit - debit) * 100) / 100;
  if (Math.abs(running - num(row.children[6])) > 0.004) broken.push(`${text(row.children[1])}: ${running} vs ${num(row.children[6])}`);
}
ok('every line’s balance is the one above it plus its credit less its debit', broken.length === 0, broken.join(' | '));
ok('the running balance ends at the balance the summary prints', running === 40900 && running === summaryOf('Balance owed'), `${running}`);
const close = table.querySelector('.stmt-close');
// the closing line merges the date and document cells, so its money columns are the third to fifth
ok('the closing line prints the same balance', num(close.children[4]) === 40900, text(close));
ok('the two period columns are the sum of the lines',
  num(close.children[2]) === 25600 && num(close.children[3]) === 66500, text(close));

const grn = lines.find(r => /GRN-2026-0312/.test(text(r)));
ok('goods are owed from the date they arrive, not from the invoice', grn !== undefined && num(grn.children[4]) === 0 && num(grn.children[5]) === 22000, text(grn));
const paid = lines.find(r => /PAY-2026-0061/.test(text(r)));
ok('a payment is a debit: it takes the balance back down', paid !== undefined && num(paid.children[4]) === 22000 && num(paid.children[6]) === 0, text(paid));
const matched = lines.find(r => /CBL-2026-0802/.test(text(r)));
ok('a bill matched to a receipt adds only its variance — nothing here', matched !== undefined && num(matched.children[4]) === 0 && num(matched.children[5]) === 0,
  text(matched));
ok('and the matching bill still names what it is matching', /invoice GH₵ 26,000\.00/.test(text(matched)), text(matched));
const expense = lines.find(r => /CBL-2026-0771/.test(text(r)));
ok('an expense bill has no receipt behind it, so it is the credit', expense !== undefined && num(expense.children[5]) === 18500, text(expense));
const debitNote = lines.find(r => /DN-2026-0044/.test(text(r)));
ok('a debit note is a debit — the supplier credited us', debitNote !== undefined && num(debitNote.children[4]) === 3600, text(debitNote));

ok('the screen says goods are owed from the day they arrive', /owed from the date they are received/.test(text(doc.body)));
ok('and that a bill on hold is shown at zero rather than dropped', /Bills on hold are shown for reference at zero/.test(text(doc.body)));

/* ── the same balance on the ageing screen ──────────────────────────────── */
const ageing = text(new JSDOM(readFileSync('deploy/dev/previews/ap-ageing.html', 'utf8')).window.document.body);
ok('the ageing prints the same 40,900 for this supplier', /40,900\.00/.test(ageing), ageing.match(/40,900\.00.{0,50}/)?.[0]);

/* ── nothing leaked ─────────────────────────────────────────────────────── */
const copy = doc.body.cloneNode(true);
copy.querySelectorAll('script, style').forEach(node => node.remove());
const clean = text(copy);
ok('no Blade directive survived', !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless|isset|endisset)\b/.test(clean));
ok('no undefined, NaN or [object Object] reached the screen', !/undefined|NaN|\[object Object\]/.test(clean));
ok('no raw placeholder was printed', !/\{\{|\}\}/.test(clean));
ok('no escaped markup was printed as text', !/&lt;(nav|span|a|div|td)\b/.test(clean));

if (failures.length) {
  console.error(`ap statement check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`ap statement check: ${passed}/${passed} passed`);
