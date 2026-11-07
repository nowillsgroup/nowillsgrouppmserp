#!/usr/bin/env node
/*
 * Company ledger — one company's city-ledger history, read as at a date.
 *
 * The city ledger is what a corporate account queries, so the screen has to survive being read line by line. This check
 * holds four things:
 *
 *   1. the running balance is real — every row's balance is the one above it plus its debit less its credit, the last row
 *      is the closing figure, and the two columns add to the same number;
 *   2. it is read as at a date — the control exists, it cannot be set past the business date, and the date it is read at
 *      is the date the tie is stated at;
 *   3. it agrees with the receivables ageing — 48,400 for Ashanti Builders Co-op, the company that is over its credit
 *      limit there, because the ageing reads the same charges FIFO and this screen adds them up;
 *   4. the receipt modal is usable — it defaults to what is owed, on today's date, through a real mode list.
 *
 * Renders deploy/dev/previews/company-ledger.html in jsdom against deploy/dev/preview/company-ledger-sample.mjs.
 *
 * Run: node deploy/dev/audit/company_ledger_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const raw = readFileSync('deploy/dev/previews/company-ledger.html', 'utf8');
const dom = new JSDOM(raw, { url: 'https://preview.local/accounts/city-ledger/21?as_of=2026-11-07' });
const doc = dom.window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];
const num = (cell) => Number((((text(cell).match(/[−-]?[\d,]+\.\d{2}/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);

/* ── the document ───────────────────────────────────────────────────────── */
ok('the page is one company’s ledger', /Ledger – Ashanti Builders Co-op/.test(doc.title), doc.title);
ok('it is reached from the city ledger', /City Ledger \/ Ashanti Builders Co-op/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the as-at date is a working control', $('input[name="as_of"]')?.value === '2026-11-07', $('input[name="as_of"]')?.value);
ok('it cannot be read into the future', $('input[name="as_of"]')?.getAttribute('max') === '2026-11-07', $('input[name="as_of"]')?.getAttribute('max'));
ok('it prints', $$('.actions button').some(b => /Print/.test(text(b))));
ok('and a receipt can be taken', $$('.actions button').some(b => /Receive Payment/.test(text(b))) && $('#rcModal') !== null);

/* ── the tie to the ageing ──────────────────────────────────────────────── */
const tie = $('#companyTie');
ok('the ledger says whether it agrees with the ageing', tie !== null);
const tieStats = tie ? [...tie.querySelectorAll('.stat')].map(s => text(s)) : [];
ok('it prints three figures: this ledger, the ageing, the difference', tieStats.length === 3, `${tieStats.length}`);
ok('the ledger closes at the balance below', /48,400\.00/.test(tieStats[0]) && /This ledger closes at/.test(tieStats[0]), tieStats[0]);
ok('the ageing is asked at the same date', /The ageing says/.test(tieStats[1]) && /07 Nov 2026/.test(tieStats[1]), tieStats[1]);
ok('a nil difference says the two agree', /Difference/.test(tieStats[2]) && /0\.00/.test(tieStats[2]) && /the two agree/.test(tieStats[2]), tieStats[2]);
ok('and a nil difference is green', tie?.querySelectorAll('.stat')[2].classList.contains('tone-green') === true);
ok('the screen says the company can be sent this ledger', /says the same/.test(text($('#companyTieState')))
  && /can be sent this ledger/.test(text($('#companyTieState'))), text($('#companyTieState')));
ok('it does not cry defect when they agree', !/a difference of/.test(text($('#companyTieState'))));

/* ── the lines ──────────────────────────────────────────────────────────── */
const table = $('.card.flush table');
const lines = [...table.querySelectorAll('tbody tr')];
ok('one row per document', lines.length === 3, `${lines.length}`);
ok('the charges link to the folio they came from',
  lines.filter(r => r.querySelector('a')).length === 2
  && lines.filter(r => r.querySelector('a')).every(r => /#folios-show-\d+/.test(r.querySelector('a').getAttribute('href'))),
  lines.map(r => r.querySelector('a')?.getAttribute('href')).join(' | '));
ok('receipts are not links: there is nothing to open', lines.filter(r => !r.querySelector('a')).length === 1);
ok('dates print as dates, not as ISO', !/\b2026-\d\d-\d\d\b/.test(text(table)));

let running = 0;
const broken = [];
for (const row of lines) {
  const debit = num(row.children[4]);
  const credit = num(row.children[5]);
  running = Math.round((running + debit - credit) * 100) / 100;
  if (Math.abs(running - num(row.children[6])) > 0.004) broken.push(`${text(row.children[2])}: ${running} vs ${num(row.children[6])}`);
}
ok('every row’s balance is the one above it plus its debit less its credit', broken.length === 0, broken.join(' | '));
ok('the settled charge comes back to nothing', num(lines[1].children[6]) === 0);
ok('the open bill is the balance', num(lines[2].children[6]) === 48400, text(lines[2]));

const close = table.querySelector('tfoot tr');
ok('the closing line prints the term columns’ sums', num(close.children[1]) === 52400 && num(close.children[2]) === 4000, text(close));
ok('and a balance that is the two subtracted', num(close.children[3]) === 48400 && num(close.children[3]) === 52400 - 4000, text(close));
ok('the closing balance is the last row’s balance', num(close.children[3]) === running && running === 48400, `${running}`);

/* ── the same balance on the ageing screen ──────────────────────────────── */
const ageing = text(new JSDOM(readFileSync('deploy/dev/previews/receivables.html', 'utf8')).window.document.body);
ok('the receivables ageing prints the same 48,400 for this company', /48,400\.00/.test(ageing), ageing.match(/48,400\.00.{0,50}/)?.[0]);
ok('and the same company is the one over its limit there', /Ashanti Builders Co-op/.test(ageing) && /OVER LIMIT/.test(ageing));

/* ── the receipt modal ──────────────────────────────────────────────────── */
const modal = $('#rcModal');
ok('the modal names the company', /Receive Payment – Ashanti Builders Co-op/.test(text(modal)), text(modal?.querySelector('h3')));
ok('it defaults to what is owed', modal?.querySelector('input[name="amount"]')?.value === '48400',
  modal?.querySelector('input[name="amount"]')?.value);
ok('on the date the ledger is read at', modal?.querySelector('input[name="date"]')?.value === '2026-11-07',
  modal?.querySelector('input[name="date"]')?.value);
ok('through a real list of modes, not a stringified object',
  modal?.querySelectorAll('select[name="payment_mode_id"] option').length === 4
  && !/\[object Object\]/.test(text(modal)),
  [...(modal?.querySelectorAll('select[name="payment_mode_id"] option') ?? [])].map(o => text(o)).join(' | '));
ok('it posts to this company’s receipt route', /#accounts-company-receipt-21$/.test(modal?.querySelector('form')?.getAttribute('action') ?? ''),
  modal?.querySelector('form')?.getAttribute('action'));
ok('a split receipt is offered as well', /data-split-tender-widget/.test(raw) && /Split company receipt across methods/.test(text(modal)));

/* ── nothing leaked ─────────────────────────────────────────────────────── */
const copy = doc.body.cloneNode(true);
copy.querySelectorAll('script, style').forEach(node => node.remove());
const clean = text(copy);
ok('no Blade directive survived', !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless|isset|endisset)\b/.test(clean));
ok('no undefined, NaN or [object Object] reached the screen', !/undefined|NaN|\[object Object\]/.test(clean));
ok('no raw placeholder was printed', !/\{\{|\}\}/.test(clean));
ok('no escaped markup was printed as text', !/&lt;(nav|span|a|div|td)\b/.test(clean));

if (failures.length) {
  console.error(`company ledger check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`company ledger check: ${passed}/${passed} passed`);
