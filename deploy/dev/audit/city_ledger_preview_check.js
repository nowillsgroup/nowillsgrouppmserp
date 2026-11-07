#!/usr/bin/env node
/*
 * City ledger index — every company's balance, read as at a date, and held to the ageing it indexes.
 *
 * This screen is a second query over the same sub-ledger the receivables ageing reads, which is exactly the kind of pair
 * that drifts. So the check is mostly about agreement:
 *
 *   1. the list is arithmetic — each row's outstanding is its billed less its received, and the footer is the sum of the
 *      rows rather than a second figure that happens to look right;
 *   2. it agrees with the ageing on the page — the printed total is the ageing screen's total, and every company's row is
 *      the figure the ageing prints for that company in deploy/dev/previews/receivables.html;
 *   3. the flags are the rule's — a company is over limit because its own limit says so, not because the list re-tested it,
 *      and a company with nothing to its name leaves the list, not a row of zeroes pretending to be a balance;
 *   4. when the two disagree the page names the company — the drift build is checked for the sentence, the row and the red
 *      difference, because an unnamed difference is what sends someone hunting.
 *
 * Renders deploy/dev/previews/city-ledger.html and city-ledger-drift.html in jsdom against
 * deploy/dev/preview/city-ledger-sample.mjs.
 *
 * Run: node deploy/dev/audit/city_ledger_preview_check.js
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

const doc = load('deploy/dev/previews/city-ledger.html', 'https://preview.local/accounts/city-ledger?as_of=2026-11-07');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the document ───────────────────────────────────────────────────────── */
ok('the page is the city ledger index', /City Ledger/.test(doc.title), doc.title);
ok('it sits under accounts', /Accounts \/ City Ledger/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the as-at date is a working control', $('input[name="as_of"]')?.value === '2026-11-07', $('input[name="as_of"]')?.value);
ok('it cannot be read into the future', $('input[name="as_of"]')?.getAttribute('max') === '2026-11-07', $('input[name="as_of"]')?.getAttribute('max'));
ok('it prints', $$('.actions button').some(b => /Print/.test(text(b))));

/* ── the three figures the header states ────────────────────────────────── */
const stats = [...doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')].map(s => text(s));
ok('billed, received and outstanding are the header', stats.length === 3 && /Total billed/.test(stats[0]) && /Received/.test(stats[1]) && /Outstanding/.test(stats[2]), stats.join(' | '));
ok('billed totals 196,700.00', /196,700\.00/.test(stats[0]), stats[0]);
ok('received totals 76,500.00', /76,500\.00/.test(stats[1]), stats[1]);
ok('and outstanding is the two subtracted, 120,200.00', /120,200\.00/.test(stats[2]) && /07 Nov 2026/.test(stats[2]), stats[2]);

/* ── the tie to the ageing ──────────────────────────────────────────────── */
const tie = $('#cityLedgerTie');
ok('the list says whether it agrees with the ageing', tie !== null);
const tieStats = tie ? [...tie.querySelectorAll('.stat')].map(s => text(s)) : [];
ok('it prints three figures: the list, the ageing, the difference', tieStats.length === 3, `${tieStats.length}`);
ok('the list totals what the header says', /120,200\.00/.test(tieStats[0]) && /This list totals/.test(tieStats[0]), tieStats[0]);
ok('the ageing is asked at the same date', /The ageing says/.test(tieStats[1]) && /07 Nov 2026/.test(tieStats[1]) && /120,200\.00/.test(tieStats[1]), tieStats[1]);
ok('a nil difference says the two agree', /Difference/.test(tieStats[2]) && /0\.00/.test(tieStats[2]) && /the two agree/.test(tieStats[2]), tieStats[2]);
ok('and a nil difference is green', tie?.querySelectorAll('.stat')[2].classList.contains('tone-green') === true);
const state = text($('#cityLedgerTieState'));
ok('the page says the two read the same money', /says the same, company by company/.test(state), state);
ok('it does not cry defect when they agree', !/a difference of/.test(state));
ok('and it reports the one company over its limit', /One of these companies is over its credit limit/.test(text($('#cityLedgerLimit'))), text($('#cityLedgerLimit')));
ok('the card counts the companies it lists', /4 companies · as at 07 Nov 2026/.test(text(tie?.querySelector('.card-head'))), text(tie?.querySelector('.card-head')));

/* ── the rows ───────────────────────────────────────────────────────────── */
const table = $('#clTable');
const rows = [...table.querySelectorAll('tbody tr')];
ok('one row per company, including the one with nothing to its name', rows.length === 4, `${rows.length}`);
ok('rows are in name order', rows.map(r => text(r.children[0]).split(/(OVER LIMIT|90\+ overdue)/)[0].trim()).join(' | ')
  === 'Ashanti Builders Co-op | Coconut Grove Pensions | Meridian Travel & Tours | Zenith Oil Ghana Ltd',
  rows.map(r => text(r.children[0])).join(' | '));

let broken = [];
for (const row of rows) {
  const billed = text(row.children[4]) === '—' ? 0 : num(row.children[4]);
  const received = text(row.children[5]) === '—' ? 0 : num(row.children[5]);
  const outstanding = text(row.children[6]).startsWith('—') ? 0 : num(row.children[6]);
  if (Math.abs(Math.round((billed - received) * 100) / 100 - outstanding) > 0.004) {
    broken.push(`${text(row.children[0])}: ${billed} − ${received} ≠ ${outstanding}`);
  }
}
ok('every row’s outstanding is its billed less its received', broken.length === 0, broken.join(' | '));

const share = Math.round(rows.reduce((total, row) => total + (text(row.children[6]).startsWith('—') ? 0 : num(row.children[6])), 0) * 100) / 100;
const foot = table.querySelector('tfoot tr');
ok('the footer is the sum of the rows, not a second figure', num(foot.children[3]) === share && share === 120200, `${num(foot.children[3])} vs ${share}`);
ok('the footer prints the column totals too', num(foot.children[1]) === 196700 && num(foot.children[2]) === 76500, text(foot));
ok('the footer names how many companies it totals', /Total — 4 companies/.test(text(foot)), text(foot));
ok('the footer closing is the header outstanding', num(foot.children[3]) === 120200 && /120,200\.00/.test(text($('#clClosing'))), text($('#clClosing')));

/* ── the flags are the ageing's rule ────────────────────────────────────── */
const rowFor = (name) => rows.find(r => text(r.children[0]).startsWith(name));
const ashanti = rowFor('Ashanti Builders Co-op');
const zenith = rowFor('Zenith Oil Ghana Ltd');
const meridian = rowFor('Meridian Travel & Tours');
const quiet = rowFor('Coconut Grove Pensions');
ok('the company over its limit is flagged as the ageing flags it', /OVER LIMIT/.test(text(ashanti)), text(ashanti));
ok('and it is flagged red, under the name rather than inside the figure', ashanti?.querySelector('td .tiny.text-red') !== null && /OVER LIMIT/.test(text(ashanti.querySelector('td .tiny'))));
ok('the company with a 90-day charge carries that flag', /90\+ overdue/.test(text(zenith)), text(zenith));

const overLimitRows = rows.filter(r => {
  const limit = num(r.children[3]);
  const outstanding = num(r.children[6]);
  return limit > 0 && outstanding > limit;
});
ok('a company is over limit only where its own limit says so', overLimitRows.length === 1
  && /OVER LIMIT/.test(text(overLimitRows[0])), overLimitRows.map(r => text(r.children[0])).join(' | '));
ok('nobody else is flagged', rows.filter(r => /OVER LIMIT/.test(text(r))).length === 1);

/* ── the states a company can be in ─────────────────────────────────────── */
ok('a company paid in advance reads as a credit, not as a debt', num(meridian.children[6]) === -2700 && /credit on account/.test(text(meridian)), text(meridian));
ok('and it is not printed in the red of an amount owed', !meridian.querySelector('td.text-red'), text(meridian));
ok('a company with no entries prints no figures at all', /no entries yet/.test(text(quiet)) && text(quiet.children[4]) === '—' && text(quiet.children[5]) === '—' && text(quiet.children[6]).startsWith('—'), text(quiet));
ok('every row can be opened as a ledger', rows.every(r => /^#accounts-company-\d+$/.test(r.querySelector('a')?.getAttribute('href') ?? '')), rows.map(r => r.querySelector('a')?.getAttribute('href')).join(' | '));
ok('and no row carries a disagreement note when the totals agree', rows.every(r => !/the ageing says/.test(text(r))), rows.map(r => text(r.children[6])).join(' | '));

/* ── the same figures on the ageing screen ──────────────────────────────── */
const ageingDoc = load('deploy/dev/previews/receivables.html', 'https://preview.local/accounts/receivables');
const agedRows = new Map([...ageingDoc.querySelectorAll('#arTable tbody tr[data-company]')]
  .map(r => [Number(r.getAttribute('data-company')), text(r)]));
ok('the ageing screen lists the same companies', agedRows.size === 3 && ashanti && zenith && meridian, `${agedRows.size}`);
ok('the ageing’s total receivable is this list’s outstanding', /120,200\.00/.test(text(ageingDoc.querySelector('.stats'))), text(ageingDoc.querySelector('.stats')).slice(0, 120));
const drifted = [];
for (const [id, agedText] of agedRows) {
  const row = rows.find(r => Number(r.getAttribute('data-company')) === id);
  const aged = Number(((agedText.match(/[−-]?[\d,]+\.\d{2}(?!.*50,000)/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0;
  const cells = [...ageingDoc.querySelector(`#arTable tbody tr[data-company="${id}"]`).children];
  const agedOutstanding = num(cells[cells.length - 4]);
  if (Math.abs(agedOutstanding - num(row.children[6])) > 0.004) drifted.push(`${text(row.children[0])}: list ${num(row.children[6])} vs ageing ${agedOutstanding}`);
}
ok('every company’s row is the figure the ageing prints for it', drifted.length === 0, drifted.join(' | '));
ok('and the two screens count the same money: 4 rows here, 3 aged there', rows.length === 4 && agedRows.size === 3);

/* ── the drift build: the fault the page exists to catch ────────────────── */
const driftDoc = load('deploy/dev/previews/city-ledger-drift.html', 'https://preview.local/accounts/city-ledger?as_of=2026-11-07');
const dTie = driftDoc.querySelector('#cityLedgerTie');
const dStats = [...dTie.querySelectorAll('.stat')].map(s => text(s));
ok('a difference is stated, not hidden', /3,000\.00/.test(dStats[2]) && /the two disagree/.test(dStats[2]), dStats[2]);
ok('and it is red', dTie.querySelectorAll('.stat')[2].classList.contains('tone-red') === true);
ok('the list still totals its own rows', /123,200\.00/.test(dStats[0]) && /123,200\.00/.test(text(driftDoc.querySelector('#clClosing'))), dStats[0]);
const dState = text(driftDoc.querySelector('#cityLedgerTieState'));
ok('the page names the company whose row is out', /Meridian Travel & Tours \(list 300\.00, ageing -2,700\.00\)/.test(dState), dState);
ok('and sends the reader to check its entries', /open each ledger and check its charges and receipts before this list goes out/.test(dState), dState);
const dRows = [...driftDoc.querySelectorAll('#clTable tbody tr')];
ok('the row itself prints both figures', dRows.filter(r => /the ageing says/.test(text(r))).length === 1
  && /the ageing says -2,700\.00/.test(text(dRows.find(r => /Meridian/.test(text(r))))),
  dRows.map(r => text(r.children[6])).join(' | '));
ok('the rows that agree are not marked', dRows.filter(r => /the ageing says/.test(text(r))).length === 1);
ok('the limit note is printed whatever the tie says, counting the same one company',
  /One of these companies is over its credit limit/.test(text(driftDoc.querySelector('#cityLedgerLimit'))),
  text(driftDoc.querySelector('#cityLedgerLimit')));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the list', doc], ['the drift list', driftDoc]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless|isset|endisset)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
  ok(`${name} holds no escaped markup printed as text`, !/&lt;(nav|span|a|div|td)\b/.test(clean));
}

if (failures.length) {
  console.error(`city ledger check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`city ledger check: ${passed}/${passed} passed`);
