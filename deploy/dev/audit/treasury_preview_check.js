#!/usr/bin/env node
/*
 * Treasury: the accounts screen holds the settings, and the transfers screen shows what those
 * settings do to a suggested deposit. Two failures are checked here.
 *
 * 1. The approval threshold and the banking float were the only two money settings in Finance with
 *    no band printed anywhere, in a module where five other settings print theirs.
 * 2. TreasuryService::suggest() explained its suggested deposit with "cash in hand less the float",
 *    but the amount also had a deposit already requested taken off and was rounded down to the
 *    nearest 10. The note now names every term, and the arithmetic is checked against the amount.
 *
 * Renders deploy/dev/previews/treasury-accounts.html and treasury-transfers.html in jsdom against
 * deploy/dev/preview/treasury-{accounts,transfers}-sample.mjs.
 *
 * Run: node deploy/dev/audit/treasury_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const load = (file, url) => {
  const raw = readFileSync(file, 'utf8');
  const dom = new JSDOM(raw, { url });
  const doc = dom.window.document;
  const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
  return { raw, dom, doc, text, $: (s) => doc.querySelector(s), $$: (s) => [...doc.querySelectorAll(s)] };
};

const accounts = load('deploy/dev/previews/treasury-accounts.html', 'https://preview.local/treasury/accounts');
const transfers = load('deploy/dev/previews/treasury-transfers.html', 'https://preview.local/treasury/transfers');

/* ── the accounts screen ─────────────────────────────────────────────────── */
ok('the accounts page is titled for bank accounts', accounts.doc.title.startsWith('Bank Accounts'), accounts.doc.title);
const acctRows = accounts.$$('#accountTable tbody tr');
ok('every cash and bank account is listed', acctRows.length === 7, `${acctRows.length} rows`);
ok('each row carries the ledger code it posts to',
  acctRows.every(row => /^\d{4}$/.test(accounts.text(row.querySelector('td')))), accounts.text(acctRows[0].querySelector('td')));
const accountTable = accounts.text(accounts.$('#accountTable'));
ok('the balances come from the ledger', /1,284,530\.22/.test(accountTable) && /341,880\.50/.test(accountTable));
ok('account numbers are masked in the table', /•••• 2233/.test(accountTable), accountTable.slice(0, 200));
ok('and the full number is not printed beside them', !/1441002233/.test(accountTable));
ok('an account not in the cash position says so', /not in cash position/.test(accountTable));
ok('a closed account is shown as inactive', /Inactive/.test(accountTable));
ok('a settlement account shows its clearing delay and fee',
  /T\+2 · 1\.75%/.test(accountTable), accountTable.slice(0, 400));

/* ── the settings card: the bands ────────────────────────────────────────── */
ok('the treasury settings form is on the screen', Boolean(accounts.$('#treasurySettings')));
const setting = (name) => accounts.$(`input[name=${name}]`);
const labelOf = (name) => accounts.text(accounts.doc.querySelector(`label[for=${accounts.$(`input[name=${name}]`)?.id}]`));

ok('the approval threshold is money', setting('approval_threshold')?.getAttribute('type') === 'number'
  && setting('approval_threshold')?.getAttribute('step') === '0.01');
ok('bounded below at zero', setting('approval_threshold')?.getAttribute('min') === '0');
ok('and above at ten million', setting('approval_threshold')?.getAttribute('max') === '10000000',
  setting('approval_threshold')?.getAttribute('max'));
ok('with that band printed in words', /0 to 10,000,000/.test(labelOf('approval_threshold')), labelOf('approval_threshold'));
ok('the banking float is bounded too', setting('cash_float')?.getAttribute('max') === '1000000'
  && /0 to 1,000,000/.test(labelOf('cash_float')), labelOf('cash_float'));
ok('the stored values are what the boxes hold',
  setting('approval_threshold')?.getAttribute('value') === '50000' && setting('cash_float')?.getAttribute('value') === '5000');

for (const [name, min, max, expected] of [
  ['payday', '1', '28', '28'], ['clear_days', '0', '10', '2'],
  ['collect_pct', '0', '100', '85'], ['arrival_pct', '0', '100', '90'],
]) {
  const input = setting(name);
  ok(`${name} keeps its printed band`, input?.getAttribute('min') === min && input?.getAttribute('max') === max && input?.getAttribute('value') === expected,
    `${input?.getAttribute('min')}–${input?.getAttribute('max')} = ${input?.getAttribute('value')}`);
}

/* ── IAS 7 classification ────────────────────────────────────────────────── */
const cfSelects = accounts.$$('select[name^="cf["]');
ok('every balance-sheet account is classified', cfSelects.length === 8, `${cfSelects.length}`);
ok('each offers the four classes', cfSelects.every(select => [...select.options].length === 4));
ok('and marks which class is the automatic default',
  cfSelects.filter(select => [...select.options].some(option => /\(default\)/.test(option.textContent))).length === 8);
ok('the stored override is the one selected',
  cfSelects.some(select => select.options[select.selectedIndex]?.textContent.startsWith('Investing')));

/* ── the transfers screen ────────────────────────────────────────────────── */
ok('the transfers page is titled for banking', transfers.doc.title.startsWith('Banking'), transfers.doc.title);
const transferRows = transfers.$$('#transferTable tbody tr');
ok('the transfers already made are listed', transferRows.length === 5, `${transferRows.length}`);
ok('each carries a status', transferRows.every(row => row.querySelector('.badge')));
ok('a completion with a variance is flagged',
  transfers.$$('#transferTable tbody tr').filter(row => row.className.includes('row-amber')).length === 1);
ok('and the variance is shown in money', /-50\.00/.test(transfers.text(transfers.$('#transferTable'))));
ok('the approval threshold is stated on the screen',
  /Transfers of GH₵ 50,000\.00 or more need a second person's approval/.test(transfers.text(transfers.doc.body)));

const sources = [...transfers.$('#tfFrom').options].map(option => option.textContent);
ok('cash, clearing, MoMo and banks can start a transfer', sources.length === 5, sources.join(' | '));
ok('cash in transit cannot', !sources.some(name => /Cash in transit/.test(name)));
ok('and neither can a gateway clearing account', !sources.some(name => /gateway/i.test(name)));

/* ── the suggested deposit has to add up ─────────────────────────────────── */
const deposit = [...transfers.$('#tfFrom').options].find(option => /Front office cash box/.test(option.textContent));
const note = deposit?.getAttribute('data-note') ?? '';
const amount = Number(deposit?.getAttribute('data-amount'));
const cashInHand = Number((note.match(/Cash in hand GH₵ ([\d,.]+)/) ?? [])[1]?.replace(/,/g, ''));
const float = Number((note.match(/float of GH₵ ([\d,.]+)/) ?? [])[1]?.replace(/,/g, ''));
const committed = Number((note.match(/less GH₵ ([\d,.]+) already requested/) ?? [])[1]?.replace(/,/g, '') ?? 0);
ok('the suggestion names the cash in hand and the float', cashInHand > 0 && float > 0, note);
ok('it also names a deposit already requested, which the amount has taken off', committed > 0, note);
ok('the amount is the sum the note describes, rounded down to the nearest ten',
  amount === Math.floor(Math.max(0, cashInHand - float - committed) / 10) * 10,
  `${cashInHand} − ${float} − ${committed} → ${amount}`);
ok('and the note says when it has rounded', /rounded down to the nearest/.test(note) === (cashInHand - float - committed >= 10),
  note);

const sweep = [...transfers.$('#tfFrom').options].find(option => /card settlement/.test(option.textContent));
ok('an acquirer sweep names the settlement day and the fee',
  /T\+2/.test(sweep?.getAttribute('data-note') ?? '') && /1\.75%/.test(sweep?.getAttribute('data-note') ?? ''),
  sweep?.getAttribute('data-note'));
ok('and its amount is the receipts less the fee it states',
  Number(sweep?.getAttribute('data-fee')) === Math.round(Number(sweep?.getAttribute('data-amount')) * 1.75) / 100,
  `${sweep?.getAttribute('data-amount')} · ${sweep?.getAttribute('data-fee')}`);
ok('cheques in hand are offered for banking', /004512/.test(transfers.text(transfers.$('#tfChequeBox')))
  && /GH₵ 4,200\.00/.test(transfers.text(transfers.$('#tfChequeBox'))));

/* ── nothing left unrendered ─────────────────────────────────────────────── */
for (const [label, page] of [['accounts', accounts], ['transfers', transfers]]) {
  const bodyCopy = page.doc.body.cloneNode(true);
  bodyCopy.querySelectorAll('script, style').forEach(node => node.remove());
  const text = page.text(bodyCopy);
  ok(`no Blade directive survived on the ${label} screen`, !/@(if|foreach|endif|endforeach|php|else|continue)\b/.test(text));
  ok(`no undefined, NaN or [object Object] reached the ${label} screen`,
    !/undefined|NaN|\[object Object\]/.test(text));
  ok(`no setting key is printed on the ${label} screen`, !/treasury_(approval_threshold|cash_float|payday)/.test(text));
}

if (failures.length) {
  console.error(`treasury check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`treasury check: ${passed}/${passed} passed`);
