#!/usr/bin/env node
/*
 * One supplier payment — what went out, what it settled, what is left of it.
 *
 * After a part payment the clerk's question is "where is the rest of this money, and does the ageing know about it?". The
 * screen answers with the credit rule rather than an opinion, so this check holds three things:
 *
 *   1. the money is stated three ways that must add up — 8,000 paid, 0 allocated, 8,000 unapplied;
 *   2. the credit state is the rule's answer — openCredits() returns this payment, so 8,000 of it is a credit and it is
 *      part of the 11,600 the ageing nets off for Coastal Beverages, which the payments list also prints;
 *   3. the document side is there — nothing was settled because it was paid on account, the ledger posting is pending on
 *      a date named, the remittance advice prints, and cancelling asks for a reason and says what it undoes.
 *
 * Renders deploy/dev/previews/ap-payment-show.html in jsdom against deploy/dev/preview/ap-payment-show-sample.mjs.
 *
 * Run: node deploy/dev/audit/ap_payment_show_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const raw = readFileSync('deploy/dev/previews/ap-payment-show.html', 'utf8');
const dom = new JSDOM(raw, { url: 'https://preview.local/ap/payments/102' });
const doc = dom.window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];
const money = (s) => Number(((String(s).match(/[−-]?[\d,]+\.\d{2}/) ?? ['0'])[0]).replace(/[−,]/g, '-').replace('-', '')) || 0;

/* ── the document ───────────────────────────────────────────────────────── */
ok('the page is one supplier payment', /Supplier Payment PAY-2026-0102/.test(doc.title), doc.title);
ok('it is reached from the payments list', /Supplier Payments \/ PAY-2026-0102/.test(text($('.crumbs'))), text($('.crumbs')));
ok('it is not marked cancelled', $('.actions .badge') === null, text($('.actions .badge')));
const actions = $$('.actions a').map(a => text(a));
ok('the remittance advice can be printed', actions.includes('Remittance advice'), actions.join(' | '));
ok('and it prints this payment', $$('.actions a').some(a => /print=1/.test(a.getAttribute('href') ?? '')));
ok('the supplier statement is one click away', $$('.actions a').some(a => /#ap-statement-7/.test(a.getAttribute('href') ?? '')));
ok('there is no withholding certificate to offer when nothing was withheld', actions.every(a => !/WHT certificate/.test(a)), actions.join(' | '));
ok('it can be cancelled, with a reason', $('button[data-modal="cancelModal"]') !== null && $('#cancelModal input[name="reason"]') !== null);

const kv = [...doc.querySelectorAll('dl.kv dt')].map(d => ({ term: text(d), value: text(d.nextElementSibling) }));
const field = (term) => kv.find(f => f.term === term)?.value;
ok('the supplier is named', field('Supplier') === 'Coastal Beverages Ltd', field('Supplier'));
ok('the payment date is printed', field('Date') === '30 Oct 2026', field('Date'));
ok('how the money went is printed', field('Paid by') === 'Bank transfer', field('Paid by'));
ok('the reference is kept', field('Reference') === 'ON ACCOUNT — OCT', field('Reference'));
ok('the reader knows who recorded it', /Ama Mensah · 30 Oct 11:05/.test(field('Recorded by')), field('Recorded by'));
ok('a payment with no journal says when it will post, and on which date',
  /posts after the night audit closes 30 Oct/.test(field('Ledger')), field('Ledger'));

/* ── the money ──────────────────────────────────────────────────────────── */
const summary = [...doc.querySelectorAll('.ap-summary > div')].map(d => ({ label: text(d.querySelector('.l')), value: money(text(d.querySelector('.v'))) }));
const sum = (label) => summary.find(s => s.label === label)?.value;
ok('the summary is a cedi payment, so it has three parts', summary.length === 3, JSON.stringify(summary));
ok('the amount that went out', sum('Amount') === 8000, JSON.stringify(summary));
ok('nothing was allocated, and that is green rather than alarming', sum('Allocated') === 0 && /green/.test(doc.querySelectorAll('.ap-summary > div')[1].querySelector('.v').className));
ok('so the whole of it is unapplied', sum('Unapplied credit') === 8000 && /8,000\.00/.test(text($('#payUnapplied'))), text($('#payUnapplied')));
ok('amount less allocated is the unapplied figure', sum('Amount') - sum('Allocated') === sum('Unapplied credit'));

/* ── the credit state ───────────────────────────────────────────────────── */
const credit = $('#payCredit');
ok('the screen answers where the money sits', credit !== null);
ok('it says the payment is still on account', /of this payment is still on account/.test(text(credit)), text(credit));
ok('it names the ageing date it was judged at', /the ageing at 07 Nov 2026/.test(text(credit)), text(credit));
ok('it gives the supplier’s credits as the ageing counts them', /counts it in the GH₵ 11,600\.00/.test(text(credit)), text(credit));
const paymentsBody = text(new JSDOM(readFileSync('deploy/dev/previews/ap-payments.html', 'utf8')).window.document.body);
ok('and that is the same 11,600 the payments list prints',
  /Credits on the ageing/.test(paymentsBody) && /11,600\.00/.test(paymentsBody));
ok('it says where the credit can be applied', /Apply it from any open bill for this supplier/.test(text(credit)), text(credit));

/* ── what was settled ───────────────────────────────────────────────────── */
const allocs = $('#payAllocs');
ok('the bills settled card is there', allocs !== null);
ok('nothing was settled, and the screen says why rather than showing an empty table',
  /Paid on account – apply the credit from a bill page\./.test(text(allocs)), text(allocs));
ok('the empty message spans the table', allocs.querySelector('tbody td')?.getAttribute('colspan') === '6');

/* ── cancelling ─────────────────────────────────────────────────────────── */
const modal = $('#cancelModal');
ok('the cancel modal names the payment', /Cancel PAY-2026-0102/.test(text(modal)), text(modal?.querySelector('h3')));
ok('it says what cancelling does', /bills it settled become open again and the ledger posting is reversed/.test(text(modal)));
ok('it posts to this payment, by id', /#ap-payments-cancel-102$/.test(modal?.querySelector('form')?.getAttribute('action') ?? ''),
  modal?.querySelector('form')?.getAttribute('action'));
ok('a reason is required', modal?.querySelector('input[name="reason"]')?.hasAttribute('required') === true);

/* ── nothing leaked ─────────────────────────────────────────────────────── */
const copy = doc.body.cloneNode(true);
copy.querySelectorAll('script, style').forEach(node => node.remove());
const clean = text(copy);
ok('no Blade directive survived', !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless|isset|endisset)\b/.test(clean));
ok('no undefined, NaN or [object Object] reached the screen', !/undefined|NaN|\[object Object\]/.test(clean));
ok('no raw placeholder was printed', !/\{\{|\}\}/.test(clean));
ok('no escaped markup was printed as text', !/&lt;(nav|span|a|div|td)\b/.test(clean));
ok('no ISO date was left unformatted', !/\b2026-\d\d-\d\d\b/.test(text($('.content'))));

if (failures.length) {
  console.error(`ap payment check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`ap payment check: ${passed}/${passed} passed`);
