#!/usr/bin/env node
/*
 * Company receivables ageing — the city ledger, aged FIFO, with the charges behind every bucket.
 *
 * The payables ageing has shown its documents since v8.8; this is the other half. It checks the three things a
 * receivables ageing has to get right and gets quietly wrong when it does not:
 *
 *   1. FIFO is real — a company that has paid most of an old bill shows the remainder of the *old* bill, not a paid one
 *      and not the newer one (Zenith Oil: 28,000 left on a June conference, 46,500 untouched on an October dinner);
 *   2. the buckets add up — 0–30 + 31–60 + 61–90 + over 90, less the credit on account, is the outstanding balance, per
 *      company and in total, and each bucket says how many documents are in it;
 *   3. the credit limit is enforced as a flag, and a company paid in advance reads as a negative outstanding rather than
 *      being dropped from the screen.
 *
 * Renders deploy/dev/previews/receivables.html in jsdom against deploy/dev/preview/receivables-sample.mjs.
 *
 * Run: node deploy/dev/audit/receivables_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const raw = readFileSync('deploy/dev/previews/receivables.html', 'utf8');
const dom = new JSDOM(raw, { url: 'https://preview.local/accounts/receivables?as_of=2026-11-07' });
const doc = dom.window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];
const body = text(doc.body);
const num = (cell) => Number(text(cell).replace(/−/g, '-').replace(/[^\d.-]/g, '') || 0);

/* ── the screen ─────────────────────────────────────────────────────────── */
ok('the page is the receivables ageing', /Company Receivables Ageing/.test(doc.title), doc.title);
ok('it says what it is aged as at', /as at 7 Nov 2026/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the as-at date is a working control', $('input[name="as_of"]')?.value === '2026-11-07', $('input[name="as_of"]')?.value);
ok('it cannot be aged into the future', $('input[name="as_of"]')?.getAttribute('max') === '2026-11-07');
const csv = $$('a').find(a => /CSV/.test(text(a)));
ok('it exports as the AR ageing report, as at the date on screen',
  Boolean(csv) && /ar_aging/.test(csv.getAttribute('href')) && /2026-11-07/.test(csv.getAttribute('href')) && /csv/.test(csv.getAttribute('href')),
  csv?.getAttribute('href'));

/* ── the four figures ───────────────────────────────────────────────────── */
// the screen's own figures are the first stats block; the control-tie card has three of its own below
const stats = [...doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')].map(s => text(s));
ok('four figures lead the screen', stats.length === 4, `${stats.length}`);
ok('total receivable is the outstanding balance', stats.some(s => /120,200\.00/.test(s) && /Total receivable/.test(s)), stats.join(' | '));
ok('overdue is every past-due bucket, 31–60 and over', stats.some(s => /76,400\.00/.test(s) && /Overdue/.test(s)), stats.join(' | '));
ok('one company is over its credit limit, by name of count', stats.some(s => /1 company/.test(s) && /Over the credit limit/.test(s)));
ok('receipts on account are their own figure', stats.some(s => /2,700\.00/.test(s) && /Receipts on account/.test(s)));

/* ── the bucket table ───────────────────────────────────────────────────── */
const table = $('#arTable');
ok('one row per company with a balance', table?.querySelectorAll('tbody tr').length === 3,
  `${table?.querySelectorAll('tbody tr').length}`);
const heads = [...table.querySelectorAll('thead th')].map(th => text(th)).join(' | ');
ok('the four FIFO buckets are the columns', ['0–30 days', '31–60 days', '61–90 days', 'Over 90 days'].every(b => heads.includes(b)), heads);
ok('each bucket counts its documents', /0–30 days\s*1 doc/.test(heads) && /31–60 days\s*1 doc/.test(heads) && /Over 90 days\s*1 doc/.test(heads), heads);
ok('an empty bucket carries no count', !/61–90 days\s*\d/.test(heads), heads);

const rowOf = (name) => $$('#arTable tbody tr').find(r => text(r).includes(name));
const zenith = rowOf('Zenith Oil');
const ashanti = rowOf('Ashanti Builders');
const meridian = rowOf('Meridian Travel');
const cellsOf = (row) => [...row.querySelectorAll('td')];
ok('FIFO puts the remainder of the old bill in the oldest bucket',
  /28,000\.00/.test(text(cellsOf(zenith)[5])) && /1 doc/.test(text(cellsOf(zenith)[5])), text(cellsOf(zenith)[5]));
ok('and leaves the newer charge untouched in its own bucket',
  /46,500\.00/.test(text(cellsOf(zenith)[2])), text(cellsOf(zenith)[2]));
ok('the company balance is the buckets added up', /74,500\.00/.test(text(zenith)), text(zenith));
ok('a company over its limit says so, in red', /OVER LIMIT/.test(text(ashanti)) && Boolean(ashanti.querySelector('.text-red')));
ok('a limit is printed beside the balance, so the flag can be checked', /40,000\.00/.test(text(ashanti)), text(ashanti));
ok('a company paid in advance reads as a credit, not as nothing owed',
  /−2,700\.00/.test(text(meridian)) && /-2,700\.00/.test(text(meridian)), text(meridian));
ok('and its negative balance is coloured as a credit', cellsOf(meridian)[7].classList.contains('text-green'), cellsOf(meridian)[7].className);
ok('the oldest days past due are stated per company', /112 d/.test(text(zenith)) && /42 d/.test(text(ashanti)));

const foot = [...table.querySelectorAll('tfoot td')];
const footText = text(table.querySelector('tfoot'));
ok('the footer totals every bucket and the balance', ['46,500.00', '48,400.00', '0.00', '28,000.00', '−2,700.00', '120,200.00'].every(v => footText.includes(v)), footText);
const fB = [1, 2, 3, 4].map(i => num(foot[i]));
ok('the footer balance is the buckets less the credit on account',
  Math.abs(fB[0] + fB[1] + fB[2] + fB[3] + num(foot[5]) - num(foot[6])) < 0.01,
  footText);
ok('the screen says how the FIFO ages a charge', /receipts are applied oldest charge first/i.test(body));
ok('and where the balance ties to', /ties to the City Ledger control account/.test(body));

/* ── the charges behind the figures ─────────────────────────────────────── */
const card = $('#arItemsCard');
ok('the charges behind the ageing are listed', Boolean(card) && Boolean($('#arItems')));
ok('the card counts them', /4 of them/.test(text(card.querySelector('.card-head'))), text(card.querySelector('.card-head')));
const items = $$('#arItems tbody tr');
ok('one row per open document', items.length === 4, `${items.length}`);
ok('they are ordered oldest first', text(items[0]).includes('INV-8841') && text(items[3]).includes('on account'),
  `${text(items[0]).slice(0, 30)} … ${text(items[3]).slice(0, 30)}`);
ok('a part-paid charge is listed at its remainder, not its face value', /28,000\.00/.test(text(items[0])) && !/88,000/.test(text(items[0])), text(items[0]));
ok('the row says which bucket it sits in', items.every(r => ['0–30 days', '31–60 days', '61–90 days', 'Over 90 days'].some(b => text(r).includes(b))));
ok('a company paid in advance is a negative line, coloured as a credit',
  /−2,700\.00/.test(text(items[3])) && Boolean(items[3].querySelector('.text-green')), text(items[3]));
ok('an unapplied receipt says it is a credit on account', /paid, not yet billed|credit/.test(text(items[3])));
ok('dates are printed as dates', /18 Jun 2026/.test(text(items[0])) && /18 Jul 2026/.test(text(items[0])), text(items[0]));
const itemSum = Math.round(items.map(r => num(r.querySelectorAll('td')[7])).reduce((t, v) => t + v, 0) * 100) / 100;
ok('the charges add up to the outstanding balance', Math.abs(itemSum - 120200) < 0.01, `GH₵${itemSum}`);
ok('the list totals itself', /120,200\.00/.test(text($('#arItems tfoot'))), text($('#arItems tfoot')));
ok('the tie is stated on the screen', Boolean($('#arTie')) &&
  /add up to GH₵ 120,200\.00, which is the outstanding balance above/.test(text($('#arTie'))), text($('#arTie')));
ok('a difference would be reported instead of a tie', !/a difference of/.test(text($('#arTie'))));
ok('the card explains FIFO and the negative line', /after<\/b> the FIFO application/.test($('#arItemsCard').innerHTML) || /after the FIFO application/.test(text($('#arItemsCard'))) ||
  /FIFO application of receipts/.test(text($('#arItemsCard'))));

/* ── does it tie to the ledger? ─────────────────────────────────────────── */
const tie = $('#controlTie');
ok('the ageing shows the control account it has to agree with', Boolean(tie) && /Does it tie to the ledger/.test(text(tie)));
ok('it names the city ledger control and the date', /City ledger \(company receivables\)/.test(text(tie)) && /as at 07 Nov 2026/.test(text(tie)), text(tie));
const ties = $$('#controlTie .stat').map(s => text(s));
ok('the screen figure is its own outstanding total', ties.some(s => /120,200\.00/.test(s) && /This screen/.test(s)), ties.join(' | '));
ok('the posted control balance is printed beside it', ties.some(s => /118,500\.00/.test(s) && /Control account/.test(s)), ties.join(' | '));
ok('the difference is stated, not hidden', ties.some(s => /1,700\.00/.test(s) && /Difference/.test(s)), ties.join(' | '));
ok('a difference at an unposted date is amber, not red', /tone-amber/.test($('#controlTie .stat:last-child')?.className ?? ''),
  $('#controlTie .stat:last-child')?.className);
const state = text($('#controlTieState'));
ok('and it is explained as the day that has not been posted', /The ledger is posted through/.test(state) && /06 Nov 2026/.test(state) &&
  /07 Nov 2026 is not in it yet/.test(state), state);
ok('the explanation says a difference that survives the posting is real', /a difference that survives the posting is a real one, not a timing one/.test(state));
ok('a difference at a posted date would say not to close the month instead', !/Do not close the month/.test(text(tie)));

/* ── nothing leaked ─────────────────────────────────────────────────────── */
const copy = doc.body.cloneNode(true);
copy.querySelectorAll('script, style').forEach(node => node.remove());
const clean = text(copy);
ok('no Blade directive survived', !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless)\b/.test(clean));
ok('no undefined, NaN or [object Object] reached the screen', !/undefined|NaN|\[object Object\]/.test(clean));
ok('no raw placeholder was printed', !/\{\{|\}\}/.test(clean));
ok('no ISO date was left unformatted in the list', !/\b2026-\d\d-\d\d\b/.test(text($('#arItems'))));

if (failures.length) {
  console.error(`receivables check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`receivables check: ${passed}/${passed} passed`);
