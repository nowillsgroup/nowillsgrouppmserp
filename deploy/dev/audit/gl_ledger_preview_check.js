#!/usr/bin/env node
/*
 * The general ledger is the screen an auditor trusts to say whether the books agree with
 * themselves. If the trial balance total is the sum of the wrong column, if a mismatch is shown
 * as "indicative", or if the lock form lets a date past the posted-up-to day through, the figure
 * on the page is a promise the code does not keep.
 *
 * This renders deploy/dev/previews/gl-ledger.html in jsdom and checks it against
 * deploy/dev/preview/gl-sample.mjs: the four figures across the top, the documents that failed
 * the last sync, each control account against its sub-ledger, the lock form's ceiling, and a
 * trial balance whose printed totals are the sums of the rows above them.
 *
 * Run: node deploy/dev/audit/gl_ledger_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const FILE = 'deploy/dev/previews/gl-ledger.html';
const dom = new JSDOM(readFileSync(FILE, 'utf8'), { url: 'https://preview.local/gl' });
const doc = dom.window.document;

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};
const $ = (selector) => doc.querySelector(selector);
const $$ = (selector) => [...doc.querySelectorAll(selector)];
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const money = (value) => Number(String(value).replace(/[^0-9.-]/g, ''));

/* ── the screen ──────────────────────────────────────────────────────────── */
ok('the page is titled for the general ledger', doc.title.startsWith('General Ledger'), doc.title);

/* ── the four figures ────────────────────────────────────────────────────── */
const stats = $$('.stat').map(stat => ({
  label: text(stat.querySelector('.l')),
  value: text(stat.querySelector('.v')),
  note: text(stat.querySelector('.tiny')),
}));
ok('four figures head the ledger', stats.length === 4, `${stats.length} cards`);
ok('the posted-up-to day is stated', stats[0]?.value === '04 Nov 2026' && stats[0]?.label === 'Posted up to',
  JSON.stringify(stats[0]));
ok('and the day the next sync will post to', /Next sync posts up to 05 Nov 2026/.test(stats[0]?.note), stats[0]?.note);
ok('the last sync says how long ago, not just a timestamp', stats[1]?.value === '20 minutes ago', stats[1]?.value);
ok('with the size of the ledger beside it', /2431 journals/.test(stats[1]?.note), stats[1]?.note);
ok('the lock date is shown as a date, not a flag', stats[2]?.value === '31 Oct 2026', stats[2]?.value);
ok('the trial balance is summarised', stats[3]?.value === 'Balanced', stats[3]?.value);

/* ── documents that failed the last sync ─────────────────────────────────── */
const alert = $('.alert-error');
ok('failed source documents are surfaced, not swallowed', Boolean(alert));
ok('the count is stated', /3 source document\(s\) could not be posted/.test(text(alert)), text(alert));
ok('and the reader is told they retry on their own', /retried automatically on every sync/.test(text(alert)));
const alertItems = $$('.alert-error li').map(text);
ok('each failure is named', alertItems.length === 3
  && alertItems[0].includes("category 'spa' has no revenue account")
  && alertItems[1].includes('on or before the lock date'), alertItems.join(' | '));

/* ── control accounts ────────────────────────────────────────────────────── */
const controlRows = $$('#gl-controls tbody tr');
ok('every control account has a row', controlRows.length === 5, `${controlRows.length} rows`);
ok('a control that agrees says so', controlRows.filter(row => /Reconciled/.test(text(row))).length === 3,
  controlRows.map(row => text(row)).find(row => /Reconciled/.test(row)));
ok('a strict control that does not agree is called a mismatch, not a note',
  controlRows.filter(row => /Mismatch/.test(text(row))).length === 1,
  controlRows.map(row => text(row)).join(' | '));
ok('the one account compared at latest cost is marked indicative',
  controlRows.filter(row => /Indicative/.test(text(row))).length === 1);
ok('the mismatch shows its amount', text(controlRows.find(row => /Mismatch/.test(text(row)))).includes('20.00'),
  text(controlRows.find(row => /Mismatch/.test(text(row)))));
ok('the page says why one of them is only indicative',
  /Inventory is compared with quantity × latest cost, so a difference there is indicative only/.test(text(doc.body)));
ok('the rule that guest and city ledger must reconcile to the cent is stated',
  /Guest and city ledger must reconcile to the cent/.test(text(doc.body)));

/* v9.6 the count, and the way from a difference to the documents that made it */
const summary = $('#controlSummary');
ok('the page counts how many of the controls agree', summary !== null && /3 of 5/.test(text(summary)), text(summary));
ok('a strict control that is out is called out by name and amount',
  /POS tills awaiting settlement, out by GH₵ 20\.00/.test(text(summary)), text(summary));
ok('and the reader is told to reconcile it before the month closes',
  /Reconcile it before the month is closed/.test(text(summary)), text(summary));
ok('a page with a strict difference does not claim the books are clean', /text-red/.test(summary.className), summary.className);
const rowFor = (label) => controlRows.find(row => text(row).includes(label));
const linkIn = (row) => row?.querySelector('td a')?.getAttribute('href');
ok('the payables control points at the ageing that owns it', /#ap-ageing/.test(rowFor('Accounts payable')?.querySelector('td a')?.getAttribute('href') ?? ''),
  rowFor('Accounts payable')?.querySelector('td a')?.getAttribute('href'));
ok('and says which screen that is', /Payables ageing/.test(text(rowFor('Accounts payable'))), text(rowFor('Accounts payable')));
ok('the city ledger points at the receivables ageing', /#accounts-receivables/.test(rowFor('City ledger')?.querySelector('td a')?.getAttribute('href') ?? ''),
  rowFor('City ledger')?.querySelector('td a')?.getAttribute('href'));
ok('the guest ledger points at the folios', /#folios-index/.test(rowFor('Guest ledger')?.querySelector('td a')?.getAttribute('href') ?? ''),
  rowFor('Guest ledger')?.querySelector('td a')?.getAttribute('href'));
ok('stock points at the stock ledger', /#inventory-ledger/.test(rowFor('Inventory at latest cost')?.querySelector('td a')?.getAttribute('href') ?? ''),
  rowFor('Inventory at latest cost')?.querySelector('td a')?.getAttribute('href'));
ok('a control with no screen of its own invents no link', rowFor('POS tills')?.querySelectorAll('td a').length === 1,
  `${rowFor('POS tills')?.querySelectorAll('td a').length}`);

/* ── the lock form ───────────────────────────────────────────────────────── */
const lockDate = $('form[action$="gl-lock"] input[name=lock_date]');
ok('the lock form offers a date', Boolean(lockDate));
ok('it cannot be set past the day the ledger is posted to',
  lockDate?.getAttribute('max') === '2026-11-04', lockDate?.getAttribute('max'));
ok('and it starts at the date the books are already locked to', lockDate?.value === '2026-10-31', lockDate?.value);
ok('moving the lock back requires a reason', Boolean($('form[action$="gl-lock"] input[name=reason]')));
ok('an unlock is offered when the books are locked',
  $$('form[action$="gl-lock"] button').some(button => /Unlock/.test(text(button))));
ok('the close note says postings happen on closed business days only',
  /ledger posts closed business days only/.test(text(doc.body)));
ok('and that an amended closed day is reversed and reposted',
  /the original journal is reversed and a corrected one posted/.test(text(doc.body)));
const full = $('form[action$="gl-sync"] input[name=full]');
ok('a full re-check is a separate, confirmed action', full?.value === '1'
  && /Re-check every source document after the lock date/.test(full?.closest('form')?.getAttribute('data-confirm') ?? ''),
  full?.closest('form')?.getAttribute('data-confirm'));

/* ── the trial balance ───────────────────────────────────────────────────── */
const body = $('#tb tbody');
const groupRows = $$('#tb tbody tr.gl-type').map(row => text(row));
ok('the trial balance is grouped by account type', groupRows.length === 5, groupRows.join(' | '));
ok('every account carries a code and a name',
  $$('#tb tbody tr').length - groupRows.length === 14,
  `${$$('#tb tbody tr').length - groupRows.length} account rows`);

const foot = $$('#tb tfoot td').map(td => text(td));
ok('the footer totals the debit column', money(foot[3]) === money(foot[4]), `${foot[3]} vs ${foot[4]}`);
ok('and the closing columns balance to the same cent', money(foot[5]) === money(foot[6]), `${foot[5]} vs ${foot[6]}`);

// the printed totals must be the sums of the rows above them, column by column
const accountRows = $$('#tb tbody tr').filter(row => !row.classList.contains('gl-type'));
const column = (index) => accountRows.reduce((total, row) => total + (money(text(row.children[index])) || 0), 0);
ok('the debit total is the sum of the debit cells printed above it',
  Math.abs(column(3) - money(foot[3])) < 0.01, `${column(3).toFixed(2)} vs ${foot[3]}`);
ok('the credit total is the sum of the credit cells printed above it',
  Math.abs(column(4) - money(foot[4])) < 0.01, `${column(4).toFixed(2)} vs ${foot[4]}`);
ok('the closing debit total is the sum of the closing debit cells',
  Math.abs(column(5) - money(foot[5])) < 0.01, `${column(5).toFixed(2)} vs ${foot[5]}`);
ok('the closing credit total is the sum of the closing credit cells',
  Math.abs(column(6) - money(foot[6])) < 0.01, `${column(6).toFixed(2)} vs ${foot[6]}`);

ok('the statements the ledger leads to are linked',
  /Profit & Loss/.test(text(doc.body)) && /Balance Sheet/.test(text(doc.body)) && /Journal register/.test(text(doc.body)));
ok('and the mapping and chart of accounts sit beside them',
  /GL mapping/.test(text(doc.body)) && /Chart of accounts/.test(text(doc.body)));

/* ── nothing left unrendered ─────────────────────────────────────────────── */
const bodyCopy = doc.body.cloneNode(true);
bodyCopy.querySelectorAll('script, style').forEach(node => node.remove());
const bodyText = text(bodyCopy);
ok('no Blade directive survived the render', !/@(if|foreach|endif|endforeach|php|else)\b/.test(bodyText));
ok('no undefined or NaN reached the screen', !/undefined|NaN/.test(bodyText));

if (failures.length) {
  console.error(`gl ledger check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`gl ledger check: ${passed}/${passed} passed`);
