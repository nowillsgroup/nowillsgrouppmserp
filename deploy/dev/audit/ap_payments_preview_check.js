#!/usr/bin/env node
/*
 * Supplier payments — what has gone out, and what is left of it.
 *
 * The screen exists because a payment is allowed to be larger than the bills it settles. Its two credit figures are not
 * its own opinion: v9.2 they come from ApService::creditSources(), which the ageing's credit column, the bills screen's
 * payable figure and the ageing's own credits total also read. So this check holds three things:
 *
 *   1. the four figures add up — 8,000 unapplied + 3,600 of open debit note = the 11,600 the ageing nets off, and
 *      39,440 paid in the thirty days to today with a cancelled 5,000 left out;
 *   2. the rule is applied at the edges — a foreign payment with a 240 gap is an exchange difference, not a credit, and
 *      a cancelled payment is not a credit either, so neither reaches the 8,000 the list adds up to;
 *   3. the screen works — its tabs keep the query string, the supplier and date filters are there, and every row opens
 *      its own payment.
 *
 * Renders deploy/dev/previews/ap-payments.html in jsdom against deploy/dev/preview/ap-payments-sample.mjs.
 *
 * Run: node deploy/dev/audit/ap_payments_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const raw = readFileSync('deploy/dev/previews/ap-payments.html', 'utf8');
const dom = new JSDOM(raw, { url: 'https://preview.local/ap/payments' });
const doc = dom.window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];
// the first money figure in a cell: a foreign amount carries its rate and exchange difference beneath it
const num = (cell) => Number((((text(cell).match(/[−-]?[\d,]+\.\d{2}/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);

/* ── the screen ─────────────────────────────────────────────────────────── */
ok('the page is the supplier payments list', /Supplier Payments/.test(doc.title), doc.title);
ok('it says where it sits', /Accounts \/ Payables/.test(text($('.crumbs'))), text($('.crumbs')));
const actions = [...$('.actions').querySelectorAll('a')].map(a => text(a));
ok('it offers the bills and a new payment',
  actions.some(a => /Bills/.test(a)) && actions.some(a => /Pay supplier/.test(a)), actions.join(' | '));
ok('every header button goes somewhere', $$('.actions a').every(a => (a.getAttribute('href') ?? '').length > 1));

/* ── the four figures ───────────────────────────────────────────────────── */
const stats = [...doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')].map(s => text(s));
ok('four figures lead the screen', stats.length === 4, `${stats.length}`);
const stat = (label) => [...doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')].find(s => new RegExp(label).test(text(s)));
const value = (label) => num(stat(label)?.querySelector('.v'));

ok('unapplied payments is what was paid and never applied', /8,000\.00/.test(text(stat('Unapplied payments'))) && /1 payment\(s\)/.test(text(stat('Unapplied payments'))), text(stat('Unapplied payments')));
ok('unapplied payments is amber, because it is money sitting idle', stat('Unapplied payments')?.classList.contains('tone-amber'), stat('Unapplied payments')?.className);
ok('open debit notes is its own figure', value('Open debit notes') === 3600 && /1 note\(s\)/.test(text(stat('Open debit notes'))), text(stat('Open debit notes')));
ok('the debit note tile can be opened', /^GH₵ 3,600\.00/.test(text(stat('Open debit notes'))));
ok('the two credits together are the ageing\u2019s credit', value('Credits on the ageing') === 11600 && value('Unapplied payments') + value('Open debit notes') === 11600,
  `${value('Unapplied payments')} + ${value('Open debit notes')} = ${value('Credits on the ageing')}`);
ok('and the tile says so in words',
  /the figure the ageing nets off/.test(text(stat('Credits on the ageing'))) && /takes off what is payable/.test(text(stat('Credits on the ageing'))),
  text(stat('Credits on the ageing')));
ok('paid in the last thirty days is money out, not money owed', value('Paid in the last 30 days') === 39440, text(stat('Paid in the last 30 days')));
ok('and it says cancelled payments are left out', /cancelled ones are left out/.test(text(stat('Paid in the last 30 days'))));

/* ── the tabs and filters ───────────────────────────────────────────────── */
const tabs = $$('.tabs a');
ok('the list has its three tabs', tabs.length === 3, `${tabs.length}`);
ok('every tab keeps the query string it was built from', tabs.every(a => (a.getAttribute('href') ?? '').startsWith('?')), tabs.map(a => a.getAttribute('href')).join(' | '));
ok('the tabs are all, unapplied and cancelled',
  tabs.some(a => a.getAttribute('href').includes('unapplied=1')) && tabs.some(a => a.getAttribute('href').includes('cancelled=1')),
  tabs.map(a => a.getAttribute('href')).join(' | '));
ok('all is the tab you are on', /^All$/.test(text(tabs[0])) && tabs[0].className === 'active', `${text(tabs[0])} / ${tabs[0].className}`);
const selects = $$('.filters select');
ok('the filters offer the four suppliers', selects[0]?.querySelectorAll('option').length === 5, `${selects[0]?.querySelectorAll('option').length}`);
ok('the filters carry the unapplied tab through', $('.filters input[name="unapplied"]') !== null);
ok('there is a date range and a search box',
  $('.filters input[name="from"]') !== null && $('.filters input[name="to"]') !== null && $('.filters input[name="q"]') !== null);

/* ── the list ───────────────────────────────────────────────────────────── */
const rows = [...$('#payTable').querySelectorAll('tbody tr')];
ok('one row per payment on the page', rows.length === 5, `${rows.length}`);
ok('every row opens its own payment',
  rows.every(r => /^#ap-payments-show-\d+$/.test(r.querySelector('a.bold')?.getAttribute('href') ?? '')),
  rows.map(r => r.querySelector('a.bold')?.getAttribute('href')).join(' | '));
ok('dates print as dates, not as ISO', !/\b2026-\d\d-\d\d\b/.test(text($('#payTable'))));

const onAccount = rows.find(r => /PAY-2026-0102/.test(text(r)));
ok('a payment made on account shows nothing allocated and the rest unapplied',
  onAccount !== undefined && num(onAccount.children[5]) === 8000 && num(onAccount.children[6]) === 0 && num(onAccount.children[7]) === 8000,
  text(onAccount));
ok('the unapplied column is the one that is highlighted', onAccount?.children[7].classList.contains('text-amber') === true,
  onAccount?.children[7].className);
ok('it is badged as a credit', onAccount?.querySelector('.badge')?.className === 'badge badge-amber', onAccount?.querySelector('.badge')?.className);

const foreign = rows.find(r => /PAY-2026-0105/.test(text(r)));
ok('a foreign payment shows what it cost and what the supplier was paid',
  foreign !== undefined && num(foreign.children[5]) === 31440 && /EUR 2,400\.00 @ 13\.1000/.test(text(foreign)), text(foreign));
ok('the exchange difference is signed for the payer, not hidden', /240\.00 loss/.test(text(foreign)), text(foreign));
ok('what is left of a foreign payment is not a credit',
  foreign !== undefined && num(foreign.children[7]) === 0 && foreign.querySelector('.badge-green') !== null, text(foreign));

const cancelled = rows.find(r => /PAY-2026-0093/.test(text(r)));
ok('a cancelled payment is marked, not deleted', cancelled?.classList.contains('muted') === true && /Cancelled/.test(text(cancelled)));
ok('and it is not a credit, whatever its allocation says', cancelled !== undefined && num(cancelled.children[7]) === 0
  && num(cancelled.children[6]) === 0 && cancelled.querySelector('.badge-red') !== null, text(cancelled));

ok('the credits are one row, not three', rows.filter(r => r.querySelector('.badge-amber')).length === 1,
  rows.map(r => text(r.querySelector('.badge'))).join(' | '));
ok('the rest of the list is settled', rows.filter(r => r.querySelector('.badge-green')).length === 3);

const listUnapplied = rows.reduce((sum, r) => sum + num(r.children[7]), 0);
ok('the list adds up to the unapplied figure above it', listUnapplied === 8000 && listUnapplied === value('Unapplied payments'),
  `${listUnapplied} vs ${value('Unapplied payments')}`);

/* the thirty-day window, read off the rows rather than trusted */
const DAY_MS = 86400000;
const asOf = Date.parse('2026-11-07T00:00:00Z');
const stamp = (cell) => {
  const [d, m, y] = text(cell).split(' ');
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return Date.parse(`${y}-${String(months.indexOf(m) + 1).padStart(2, '0')}-${d}T00:00:00Z`);
};
const paidRecently = rows.filter(r => !r.classList.contains('muted') && stamp(r.children[1]) >= asOf - 29 * DAY_MS)
  .reduce((sum, r) => sum + num(r.children[5]), 0);
ok('the money out in thirty days is the payments inside the window, and no cancelled one',
  paidRecently === 39440 && paidRecently === value('Paid in the last 30 days'), `${paidRecently} vs ${value('Paid in the last 30 days')}`);

ok('the list is paged at the foot', $('nav.pager') !== null);

/* ── the same figure on the other two screens that print it ─────────────── */
const ageing = text(new JSDOM(readFileSync('deploy/dev/previews/ap-ageing.html', 'utf8')).window.document.body);
const bills = text(new JSDOM(readFileSync('deploy/dev/previews/ap-bills.html', 'utf8')).window.document.body);
ok('the payables ageing prints the same 11,600 of credits', /11,600\.00/.test(ageing), ageing.match(/11,600\.00.{0,60}/)?.[0]);
ok('the bills list takes the same 11,600 off what is payable', /182,500\.00/.test(bills), bills.match(/182,500\.00.{0,60}/)?.[0]);

/* ── nothing leaked ─────────────────────────────────────────────────────── */
const copy = doc.body.cloneNode(true);
copy.querySelectorAll('script, style').forEach(node => node.remove());
const clean = text(copy);
ok('no Blade directive survived', !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless)\b/.test(clean));
ok('no undefined, NaN or [object Object] reached the screen', !/undefined|NaN|\[object Object\]/.test(clean));
ok('no raw placeholder was printed', !/\{\{|\}\}/.test(clean));
ok('no escaped markup was printed as text', !/&lt;(nav|span|a|div)\b/.test(clean));

if (failures.length) {
  console.error(`ap payments check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`ap payments check: ${passed}/${passed} passed`);
