#!/usr/bin/env node
/*
 * Supplier bills — the list the payables ageing's figures are made of.
 *
 * Since v9.1 both AP screens take their numbers from one rule (`ApService::dueStats()`), so this check exists to hold
 * the other end of the ageing's assertions:
 *
 *   1. the five figures are the ageing's five figures — 181,700 on the bills, 12,400 received and not billed,
 *      155,700 overdue, 26,000 due in the next seven days, and 182,500 payable once 11,600 of credits is taken off;
 *   2. the documents behind them are real — the only bill inside the seven-day window carries no overdue marker, the
 *      past-due ones do, a part-paid bill shows what is left rather than its total, and a debit note reduces the debt;
 *   3. the list is usable — its tabs and filters survive a query string, every row links to its own bill, the unbilled
 *      receipts get their own card, and the goods bill shows the FX it was entered at.
 *
 * Renders deploy/dev/previews/ap-bills.html in jsdom against deploy/dev/preview/ap-bills-sample.mjs.
 *
 * Run: node deploy/dev/audit/ap_bills_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const raw = readFileSync('deploy/dev/previews/ap-bills.html', 'utf8');
const dom = new JSDOM(raw, { url: 'https://preview.local/ap/bills' });
const doc = dom.window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];
const body = text(doc.body);
const money = (cell) => Number(text(cell).replace(/−/g, '-').replace(/[^\d.-]/g, '') || 0);

/* ── the screen ─────────────────────────────────────────────────────────── */
ok('the page is the supplier bills list', /Supplier Bills/.test(doc.title), doc.title);
ok('it says where it sits', /Accounts \/ Payables/.test(text($('.crumbs'))), text($('.crumbs')));
const actions = [...$('.actions').querySelectorAll('a')].map(a => text(a));
ok('it offers the ageing, a payment, a debit note and a new bill',
  actions.length === 4 && actions.some(a => /Ageing/.test(a)) && actions.some(a => /Pay supplier/.test(a))
  && actions.some(a => /Debit note/.test(a)) && actions.some(a => /Enter bill/.test(a)), actions.join(' | '));
ok('every header button goes somewhere', $$('.actions a').every(a => (a.getAttribute('href') ?? '').length > 1),
  $$('.actions a').map(a => a.getAttribute('href')).join(' | '));

/* ── the five figures ───────────────────────────────────────────────────── */
// the screen's own figures are the first stats block; nothing below it prints a .stat
const stats = [...doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')].map(s => text(s));
ok('five figures lead the screen', stats.length === 5, `${stats.length}`);
ok('outstanding on bills is what the list holds', stats.some(s => /181,700\.00/.test(s) && /Outstanding on bills/.test(s)), stats.join(' | '));
ok('overdue is the ageing\u2019s overdue', stats.some(s => /155,700\.00/.test(s) && /Overdue/.test(s)), stats.join(' | '));
ok('overdue is red when there is any', $('.stats .stat.tone-red') !== null);
ok('due in the next seven days is its own figure', stats.some(s => /26,000\.00/.test(s) && /Due in next 7 days/.test(s)), stats.join(' | '));
const holdStat = [...doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')].find(s => /Held for variance/.test(text(s)));
ok('held for variance is a count, not money',
  text(holdStat?.querySelector('.v')) === '1' && /need ap\.approve/.test(text(holdStat)), text(holdStat));
ok('total payable is the ageing\u2019s balance', stats.some(s => /182,500\.00/.test(s) && /Total payable/.test(s)), stats.join(' | '));
ok('total payable is open bills + unbilled goods less credits',
  181700 + 12400 - 11600 === 182500 && stats.some(s => /182,500\.00/.test(s) && /Total payable/.test(s)));
ok('the payable figure says how much of it is not yet invoiced',
  stats.some(s => /12,400\.00 of it is goods received, not yet billed/.test(s)), stats.join(' | '));

/* ── the list ───────────────────────────────────────────────────────────── */
const tabs = $$('.tabs a');
ok('the list has its five tabs', tabs.length === 5, `${tabs.length}`);
ok('every tab keeps the query string it was built from', tabs.every(a => (a.getAttribute('href') ?? '').startsWith('?') && a.getAttribute('href').length > 1),
  tabs.map(a => a.getAttribute('href')).join(' | '));
ok('the tabs filter by status, one each',
  ['status=unpaid', 'status=on_hold', 'status=paid', 'status=cancelled'].every(h => tabs.some(a => a.getAttribute('href').includes(h))),
  tabs.map(a => a.getAttribute('href')).join(' | '));
ok('all is the tab you are on', /^All$/.test(text(tabs[0])) && tabs[0].className === 'active', `${text(tabs[0])} / ${tabs[0].className}`);
const selects = $$('.filters select');
ok('the filters offer the four suppliers', selects[0]?.querySelectorAll('option').length === 5, `${selects[0]?.querySelectorAll('option').length}`);
ok('the filters separate bills from debit notes', selects[1]?.querySelectorAll('option').length === 3, text(selects[1]));
ok('there is an overdue-only filter and a search box',
  $('.filters input[name="overdue"]') !== null && $('.filters input[name="q"]') !== null);

const table = $('#billTable');
const rows = [...table.querySelectorAll('tbody tr')];
ok('one row per document on the page', rows.length === 6, `${rows.length}`);
ok('every row links to its own bill, by id',
  rows.every(r => {
    const href = r.querySelector('a.bold')?.getAttribute('href') ?? '';
    return /^#ap-bills-show-\d+$/.test(href);
  }), rows.map(r => r.querySelector('a.bold')?.getAttribute('href')).join(' | '));
ok('dates print as dates, not as ISO', !/\b2026-\d\d-\d\d\b/.test(text(table)));

// the seven-day figure: one bill, due 12 Nov, not yet overdue
const sevenDay = rows.find(r => /BILL-2026-0412/.test(text(r)));
ok('the bill inside the seven-day window is the 26,000 one', sevenDay !== undefined && money(sevenDay.children[5]) === 26000,
  text(sevenDay));
ok('a bill due in the future is not marked overdue', sevenDay !== undefined && !/days overdue/.test(text(sevenDay)), text(sevenDay));
ok('its due date is the 12th, printed', sevenDay !== undefined && /12 Nov 2026/.test(text(sevenDay.children[4])), text(sevenDay?.children[4]));

const marked = rows.filter(r => /days overdue/.test(text(r)));
ok('the past-due bills say how late they are', marked.length === 3, `${marked.length}`);
ok('the produce bill is 33 days late', rows.some(r => /BILL-2026-0401/.test(text(r)) && /33 days overdue/.test(text(r))));
ok('the linen bill is 115 days late', rows.some(r => /BILL-2026-0355/.test(r ? text(r) : '') && /115 days overdue/.test(text(r))));

const partPaid = rows.find(r => /BILL-2026-0355/.test(text(r)));
ok('a part-paid bill shows its total, what was paid, and what is left',
  partPaid !== undefined && money(partPaid.children[5]) === 80000 && money(partPaid.children[6]) === 16000 && money(partPaid.children[7]) === 64000,
  text(partPaid));
ok('a part-paid bill still says it is part-paid',
  partPaid?.querySelector('.badge')?.className === 'badge badge-amber', partPaid?.querySelector('.badge')?.className);

const held = rows.find(r => /BILL-2026-0404/.test(text(r)));
ok('the held bill is held for 800', held !== undefined && /price\/tax var 800\.00/.test(text(held)), text(held));
ok('the held bill is flagged in red', held?.querySelector('.badge-red') !== null, held?.querySelector('.badge')?.className);
ok('the held goods bill points at the order it came from', held !== undefined && /PO-2026-0349/.test(text(held)), text(held));
ok('a held bill is not counted as overdue', held !== undefined && !/days overdue/.test(text(held)));

const debit = rows.find(r => /DN-2026-0044/.test(text(r)));
ok('a debit note reduces what is owed rather than adding to it',
  debit !== undefined && /−\s*3,600\.00/.test(text(debit.children[5])) && money(debit.children[7]) === 3600, text(debit));
ok('a debit note has no due date of its own', debit !== undefined && /^—/.test(text(debit.children[4])), text(debit?.children[4]));

const foreign = rows.find(r => /BILL-2026-0390/.test(text(r)));
ok('a foreign bill shows the supplier\u2019s own money and the rate it was entered at',
  foreign !== undefined && /EUR 2,400\.00 at 13\.0000/.test(text(foreign)), text(foreign));
ok('the FX difference is signed for the payer', foreign !== undefined && /FX gain 240\.00/.test(text(foreign)), text(foreign));
ok('a goods bill names its order', foreign !== undefined && /PO-2026-0337/.test(text(foreign)), text(foreign));

/* ── goods received, not yet billed ─────────────────────────────────────── */
const unbilled = $('#unbilled');
ok('the receipts without an invoice have their own card', unbilled !== null);
ok('the card counts them and values them', unbilled !== null && /1 receipt\(s\) · GH₵ 12,400\.00/.test(text(unbilled)), text(unbilled));
ok('the receipt is listed by its order', /PO-2026-0341/.test(text(unbilled)), text(unbilled));
ok('the receipt is dated by the day the goods arrived', /20 Oct 2026/.test(text(unbilled)), text(unbilled));
ok('it can be matched to a bill from there', unbilled?.querySelector('a.btn-primary') !== null, text(unbilled?.querySelector('a.btn-primary')));
ok('an unbilled receipt is not also a document in the list', !rows.some(r => /PO-2026-0341/.test(text(r))));

ok('the list is paged at the foot', $('nav.pager') !== null);

/* ── nothing leaked ─────────────────────────────────────────────────────── */
const copy = doc.body.cloneNode(true);
copy.querySelectorAll('script, style').forEach(node => node.remove());
const clean = text(copy);
ok('no Blade directive survived', !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless)\b/.test(clean));
ok('no undefined, NaN or [object Object] reached the screen', !/undefined|NaN|\[object Object\]/.test(clean));
ok('no raw placeholder was printed', !/\{\{|\}\}/.test(clean));
ok('no escaped markup was printed as text', !/&lt;(nav|span|a|div)\b/.test(clean));

if (failures.length) {
  console.error(`ap bills check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`ap bills check: ${passed}/${passed} passed`);
