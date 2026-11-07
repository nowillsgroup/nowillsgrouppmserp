#!/usr/bin/env node
/*
 * Accounts payable ageing — the screen the month-end close reads first.
 *
 * It had no preview: a change to a bucket boundary, to what counts as a credit, or to what is aged from the receipt date
 * could only be reviewed by deploying it. This renders deploy/dev/previews/ap-ageing.html in jsdom against
 * deploy/dev/preview/ap-ageing-sample.mjs and checks the two things that make an ageing trustworthy:
 *
 *   1. the buckets add up — current + 1–30 + 31–60 + 61–90 + over 90, less credits, is the balance, per supplier and in
 *      total, and each bucket says how many documents are in it;
 *   2. the documents add up — every open bill, credit, unapplied payment and unbilled receipt is listed under the
 *      bucket table, oldest first, and the list totals exactly the balance the table prints.
 *
 * Run: node deploy/dev/audit/ap_ageing_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const raw = readFileSync('deploy/dev/previews/ap-ageing.html', 'utf8');
const dom = new JSDOM(raw, { url: 'https://preview.local/ap/ageing?as_of=2026-11-07' });
const doc = dom.window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];
const body = text(doc.body);
// a credit is printed with a typographic minus, which Number() would read as a positive: normalise it first
const num = (cell) => Number(text(cell).replace(/−/g, '-').replace(/[^\d.-]/g, '') || 0);

/* ── the screen ─────────────────────────────────────────────────────────── */
ok('the page is the AP ageing', /Accounts Payable Ageing/.test(doc.title), doc.title);
ok('it says what it is aged as at', /as at 7 Nov 2026/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the as-at date is a working control, not a label', Boolean($('input[name="as_of"]')), 'no as_at input');
ok('the date cannot be moved into the future from this screen', $('input[name="as_of"]')?.getAttribute('max') === '2026-11-07',
  $('input[name="as_of"]')?.getAttribute('max'));
const csv = $$('a').find(a => /CSV/.test(text(a)));
ok('the ageing can be exported as the ap_ageing report, as at the date on screen',
  Boolean(csv) && /ap_ageing/.test(csv.getAttribute('href')) && /2026-11-07/.test(csv.getAttribute('href')) && /csv/.test(csv.getAttribute('href')),
  csv?.getAttribute('href'));

/* ── the four figures at the top ────────────────────────────────────────── */
// the screen's own figures are the first stats block; the control-tie card has three of its own below
const stats = [...doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')].map(s => text(s));
ok('five figures lead the screen', stats.length === 5, `${stats.length}`);
ok('total payable is the balance the buckets come to', stats.some(s => /182,500\.00/.test(s) && /Total payable/.test(s)), stats.join(' | '));
ok('not yet due is the current bucket', stats.some(s => /38,400\.00/.test(s) && /Not yet due/.test(s)));
ok('overdue is every past-due bucket, not just the worst one', stats.some(s => /155,700\.00/.test(s) && /Overdue/.test(s)), stats.join(' | '));
ok('unapplied credits are their own figure', stats.some(s => /11,600\.00/.test(s) && /Unapplied credits/.test(s)));
// v9.2 the figure says what it is made of, and where the money can be seen: one payment and one debit note
ok('the credit figure says what it is made of, and where the money is listed',
  stats.some(s => /1 payment\(s\) and 1 debit note\(s\)/.test(s) && /payments screen lists the money/.test(s)), stats.join(' | '));
ok('the figure due inside a week is printed from the same rule the bills screen uses',
  stats.some(s => /26,000\.00/.test(s) && /Due in next 7 days/.test(s)), stats.join(' | '));
ok('and it says so, so the two screens are known to agree',
  /the same figure the supplier bills screen prints/.test(text($('.stats'))), text($('.stats')).slice(0, 260));
ok('the overdue figure counts documents, not just money', /4 documents past the due date/.test(text($('.stats'))), text($('.stats')).slice(0, 260));

/* ── the bucket table ───────────────────────────────────────────────────── */
const table = $('#ageTable');
ok('the ageing table is rendered with a row per supplier', table?.querySelectorAll('tbody tr').length === 4,
  `${table?.querySelectorAll('tbody tr').length} rows`);
const heads = [...table.querySelectorAll('thead th')].map(th => text(th));
ok('the five buckets are the column headings', ['Not yet due', '1–30', '31–60', '61–90', 'Over 90'].every((b, i) => heads.some(h => h.startsWith(b))),
  heads.join(' | '));
ok('a bucket says how many documents are in it, in total', /Not yet due\s*2 docs/.test(heads.join(' | ')) && /1–30\s*1 doc/.test(heads.join(' | ')) &&
  /31–60\s*2 docs/.test(heads.join(' | ')) && /Over 90\s*1 doc/.test(heads.join(' | ')), heads.join(' | '));
ok('an empty bucket carries no count to mislead', !/61–90\s*\d/.test(heads.join(' | ')), heads.join(' | '));

const rowOf = (name) => $$('#ageTable tbody tr').find(r => text(r).includes(name));
const sahel = rowOf('Sahel Textiles');
const accra = rowOf('Accra Fresh Foods');
const coastal = rowOf('Coastal Beverages Ltd');
ok('a supplier row carries its own document counts', /1 doc/.test(text(sahel.querySelectorAll('td')[6])), text(sahel));
ok('the oldest debt is dated, in red when it is past 30 days', /oldest 115 days past due/.test(text(sahel)) &&
  Boolean(sahel.querySelector('.text-red')), text(sahel));
ok('credits are printed negative', /−8,000\.00/.test(text(sahel)), text(sahel));
ok('goods received but not billed are shown as owed and marked', /12,400\.00/.test(text(accra)) && /12,400\.00/.test(text(accra.querySelectorAll('td')[9])),
  text(accra));
ok('a supplier with a credit still shows the balance it nets to', /56,000\.00/.test(text(sahel)));
ok('the row offers the statement and, with the right permission, payment', Boolean(sahel.querySelector('a[href*="ap-statement"]')) &&
  Boolean(sahel.querySelector('a[href*="ap-payments-create"]')));

const foot = text(table.querySelector('tfoot'));
ok('the footer totals every bucket', ['38,400.00', '18,500.00', '73,200.00', '64,000.00', '11,600.00', '182,500.00'].every(v => foot.includes(v)), foot);
const footNums = [...table.querySelectorAll('tfoot td')].map(num);
ok('the footer balance is the buckets less the credits',
  Math.abs((footNums[1] + footNums[2] + footNums[3] + footNums[4] + footNums[5]) - Math.abs(footNums[6]) - footNums[7]) < 0.01,
  table.querySelector('tfoot').textContent.replace(/\s+/g, ' '));
ok('the screen says what a bucket means and where the balance ties to', /Buckets are days past the due date/.test(body) &&
  /ties to the Accounts Payable control account/.test(body));

/* ── the documents behind the figures ───────────────────────────────────── */
const card = $('#agedItemsCard');
ok('the documents behind the ageing are listed', Boolean(card) && Boolean($('#agedItems')), 'no #agedItems');
ok('the card counts them', /8 of them/.test(text(card)), text(card.querySelector('.card-head')));
const items = $$('#agedItems tbody tr');
ok('one row per open document', items.length === 8, `${items.length} rows`);
ok('they are ordered oldest first', text(items[0]).includes('ST-2026-0114') && text(items[7]).includes('PAY-2026-0188'),
  `${text(items[0]).slice(0, 40)} … ${text(items[7]).slice(0, 40)}`);
ok('the days past due are printed, and nothing is claimed for a credit', /115/.test(text(items[0])) &&
  /—/.test(text(items[7]).replace(/−/g, '')), text(items[7]));
const kinds = items.map(r => r.dataset.kind);
ok('bills, an unbilled receipt, a debit note and an unapplied payment all appear',
  ['Bill', 'Goods received', 'Debit note', 'Unapplied payment'].every(k => kinds.includes(k)), kinds.join(' | '));
ok('the row says which bucket each document sits in', items.every(r => ['Not yet due', '1–30', '31–60', '61–90', 'Over 90'].some(b => text(r).includes(b))));
ok('a credit is negative and coloured as one', /−3,600\.00/.test(text(items[6])) && Boolean(items[6].querySelector('.text-green')), text(items[6]));
ok('the unbilled receipt says it is not yet billed', /not yet billed/.test(text(items[5])), text(items[5]));
ok('a foreign bill says so, so the balance can be read in two currencies', /EUR bill/.test(text(items[1])), text(items[1]));
ok('dates are printed as dates, not as stored strings', /15 Jul 2026/.test(text(items[0])), text(items[0]));
ok('a credit with no due date shows a dash', /—/.test(text(items[6].querySelectorAll('td')[4])), text(items[6]));

const itemsFoot = text($('#agedItems tfoot'));
ok('the list totals itself', /182,500\.00/.test(itemsFoot), itemsFoot);
const itemNums = items.map(r => num(r.querySelectorAll('td')[7]));
const itemSum = Math.round(itemNums.reduce((t, v) => t + v, 0) * 100) / 100;
ok('the documents add up to the balance the buckets print', Math.abs(itemSum - 182500) < 0.01, `GH₵${itemSum}`);
ok('the tie is stated on the screen, not left for the reader', Boolean($('#itemsTie')) &&
  /add up to GH₵ 182,500\.00, which is the balance above/.test(text($('#itemsTie'))), text($('#itemsTie')));
ok('a difference would be reported loudly instead', !/a difference of/.test(text($('#itemsTie'))), text($('#itemsTie')));
ok('the card explains why a credit is negative and an unbilled receipt is listed',
  /printed negative, because they\s+reduce what is owed/.test(text(card)) && /real obligations with no bill to point at/.test(text(card)));

/* ── the foreign-currency card is still there ───────────────────────────── */
const fx = $('#fxExposure');
ok('the foreign-currency exposure is still shown', Boolean(fx));
ok('it prices the open bill at today\'s board', /EUR/.test(text(fx)) && /31,440\.00/.test(text(fx)), text(fx));
ok('and shows the movement that board creates', /\+240\.00/.test(text(fx)), text(fx));

/* ── does it tie to the ledger? ─────────────────────────────────────────── */
const tie = $('#controlTie');
ok('the ageing shows the control account it has to agree with', Boolean(tie) && /Does it tie to the ledger/.test(text(tie)));
ok('it names the control account and the date it is read at', /Accounts payable \(supplier sub-ledger\)/.test(text(tie)) && /as at 07 Nov 2026/.test(text(tie)), text(tie.head ?? ''));
const ties = $$('#controlTie .stat').map(s => text(s));
ok('three figures: the screen, the control account, the difference', ties.length === 3, `${ties.length}`);
ok('the sub-ledger and the control account are both printed', ties.some(s => /182,500\.00/.test(s) && /This screen/.test(s)) &&
  ties.some(s => /182,500\.00/.test(s) && /Control account/.test(s)), ties.join(' | '));
ok('a difference of nothing is stated as agreement, in green',
  ties.some(s => /0\.00/.test(s) && /Difference/.test(s)) && /tone-green/.test($('#controlTie .stat:last-child')?.className ?? ''), ties.join(' | '));
ok('and the screen says the month can be closed on it', /agree to the cent at 07 Nov 2026/.test(text($('#controlTieState'))) &&
  /can be reported and the month closed on it/.test(text($('#controlTieState'))), text($('#controlTieState')));
ok('a real difference would be reported instead', !/Do not close the month/.test(text($('#controlTie'))));

/* ── nothing leaked ─────────────────────────────────────────────────────── */
const copy = doc.body.cloneNode(true);
copy.querySelectorAll('script, style').forEach(node => node.remove());
const clean = text(copy);
ok('no Blade directive survived', !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless)\b/.test(clean));
ok('no undefined, NaN or [object Object] reached the screen', !/undefined|NaN|\[object Object\]/.test(clean));
ok('no raw placeholder was printed', !/\{\{|\}\}/.test(clean));
ok('no ISO date was left unformatted in a date column', !/\b2026-0\d-\d\d\b(?!\?)/.test(text($('#agedItems'))));

if (failures.length) {
  console.error(`AP ageing check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`AP ageing check: ${passed}/${passed} passed`);
