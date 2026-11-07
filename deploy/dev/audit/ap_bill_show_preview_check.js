#!/usr/bin/env node
/*
 * One supplier bill, everything behind it.
 *
 * A clerk decides on this screen: what was entered, what has been paid, what is still owed, which credit to apply, what
 * posted. This check holds four things:
 *
 *   1. the arithmetic is the document's own — the lines add to the net, the net plus VAT is the total, and the total less
 *      what has been applied is the outstanding 18,500, which is the figure the ageing prints in its 1–30 bucket;
 *   2. the credits on offer are the credit rule's documents — the 8,000 payment on account and the 3,600 debit note, the
 *      same two the payments screen lists and the ageing nets off, both in cedis because a credit in another currency
 *      cannot be applied to this bill;
 *   3. what has been applied is a document, not a number — it links to the payment, and taking it back says what happens;
 *   4. the ledger side is shown, so a bill nobody can trace to a posting cannot look settled.
 *
 * Renders deploy/dev/previews/ap-bill-show.html in jsdom against deploy/dev/preview/ap-bill-show-sample.mjs.
 *
 * Run: node deploy/dev/audit/ap_bill_show_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const raw = readFileSync('deploy/dev/previews/ap-bill-show.html', 'utf8');
const dom = new JSDOM(raw, { url: 'https://preview.local/ap/bills/771' });
const doc = dom.window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];
const num = (cell) => Number((((text(cell).match(/[−-]?[\d,]+\.\d{2}/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);
const money = (s) => Number((s.replace(/[^\d.]/g, '')) || 0);

/* ── the document ───────────────────────────────────────────────────────── */
ok('the page is one supplier bill', /Supplier Bill CBL-2026-0771/.test(doc.title), doc.title);
ok('it is reached from the bills list', /Supplier Bills \/ CBL-2026-0771/.test(text($('.crumbs'))), text($('.crumbs')));
ok('its status is printed, not implied', /Open/.test(text($('.actions .badge'))), text($('.actions .badge')));
const actions = $$('.actions a').map(a => text(a));
ok('it offers the supplier statement', actions.includes('Statement'), actions.join(' | '));
ok('it can be paid, with this bill preselected',
  $$('.actions a').some(a => /Pay/.test(text(a)) && /bills\[\]=771/.test(a.getAttribute('href') ?? '')),
  $$('.actions a').map(a => a.getAttribute('href')).join(' | '));
ok('a debit note can be raised against it', $$('.actions a').some(a => /against=771/.test(a.getAttribute('href') ?? '')));
ok('it can be cancelled, but only through a reason', $('button[data-modal="cancelModal"]') !== null && $('#cancelModal input[name="reason"]') !== null);
ok('nothing offers to approve a variance that does not exist', !/Approve variance/.test(text($('.actions'))));

const kv = [...doc.querySelectorAll('dl.kv dt')].map(d => ({ term: text(d), value: text(d.nextElementSibling) }));
const field = (term) => kv.find(f => f.term === term)?.value;
ok('the supplier is named', field('Supplier') === 'Coastal Beverages Ltd', field('Supplier'));
ok('the supplier’s own invoice number is kept', field('Invoice no.') === 'BEV-OCT');
ok('the type says this is an expense, not a matched receipt', field('Type') === 'Expense / service', field('Type'));
ok('the document date is printed', field('Date') === '25 Sep 2026', field('Date'));
ok('the due date says how late it is, in red', /25 Oct 2026 · 13 days overdue/.test(field('Due')), field('Due'));
ok('the reader knows who entered it and when', /Ama Mensah · 25 Sep 14:20/.test(field('Entered by')), field('Entered by'));

/* ── the money ──────────────────────────────────────────────────────────── */
const summary = [...doc.querySelectorAll('.ap-summary > div')].map(d => ({ label: text(d.querySelector('.l')), value: money(text(d.querySelector('.v'))) }));
const sum = (label) => summary.find(s => s.label === label)?.value;
ok('the summary is the total, what was applied, and what is outstanding', summary.length === 3, JSON.stringify(summary));
ok('the total is what was entered', sum('Total') === 23000, JSON.stringify(summary));
ok('what was applied is green, not a warning', /green/.test(doc.querySelectorAll('.ap-summary > div')[1].querySelector('.v').className));
ok('the outstanding is red because it is owed', /red/.test($('#billOutstanding').className));
ok('and it is the total less what was applied', sum('Outstanding') === 23000 - 4500 && /18,500\.00/.test(text($('#billOutstanding'))), text($('#billOutstanding')));
ok('the ageing prints the same 18,500 for this supplier',
  /18,500\.00/.test(text(new JSDOM(readFileSync('deploy/dev/previews/ap-ageing.html', 'utf8')).window.document.body)));

/* ── the lines ──────────────────────────────────────────────────────────── */
const lines = $$('table.table:not(.table-compact) tbody tr');
ok('one row per line on the bill', lines.length === 3, `${lines.length}`);
const net = lines.reduce((t, r) => t + num(r.children[2]), 0);
const tax = lines.reduce((t, r) => t + num(r.children[4]), 0);
ok('the lines add to the net', net === 20000, `${net}`);
ok('each line’s own total is its amount plus its tax', lines.every(r => Math.abs(num(r.children[2]) + num(r.children[4]) - num(r.children[5])) < 0.005),
  lines.map(r => `${num(r.children[2])}+${num(r.children[4])}≠${num(r.children[5])}`).join(' | '));
const foot = doc.querySelector('table.table:not(.table-compact) tfoot tr');
// the foot merges the account and description cells: net, an empty tax-rate cell, tax, total
ok('the foot prints the same net and tax', num(foot.children[1]) === 20000 && num(foot.children[3]) === 3000, text(foot));
ok('and a total that is the two added together', num(foot.children[4]) === 23000 && num(foot.children[4]) === net + tax, text(foot));

/* ── what has been applied ──────────────────────────────────────────────── */
const applied = $$('#allocTable tbody tr');
ok('one allocation, shown as a document', applied.length === 1 && /PAY-2026-0058/.test(text(applied[0])), `${applied.length}`);
ok('the allocation links to the payment behind it', /#ap-payments-show-58/.test(applied[0]?.querySelector('a')?.getAttribute('href') ?? ''),
  applied[0]?.querySelector('a')?.getAttribute('href'));
ok('it says how the money arrived', /Bank transfer CHQ 004098/.test(text(applied[0])), text(applied[0]));
ok('and it is the 4,500 the summary counts', num(applied[0].children[2]) === 4500, text(applied[0]));
ok('taking it back says what happens to the money', /becomes unapplied supplier credit again/.test(applied[0].querySelector('form')?.getAttribute('onsubmit') ?? ''));
ok('the un-apply is a form, not a link that changes data on a GET', /post/i.test(applied[0].querySelector('form')?.getAttribute('method') ?? ''));

/* ── the credits on offer ───────────────────────────────────────────────── */
const form = $('#applyCredit');
ok('a credit can be applied to what is outstanding', form !== null);
const options = [...(form?.querySelectorAll('option') ?? [])];
ok('the list holds the two credits on account, and nothing else', options.length === 2, options.map(o => text(o)).join(' | '));
ok('the payment on account is offered with what is free', /PAY-2026-0102 · 30 Oct · unapplied GH₵ 8,000\.00/.test(text(options[0])) && options[0].getAttribute('data-free') === '8000',
  text(options[0]));
ok('the debit note is offered as a credit, not as a bill', /DN-2026-0044 · debit note · unapplied GH₵ 3,600\.00/.test(text(options[1])), text(options[1]));
ok('the source keys are the ones the controller parses', options.map(o => o.getAttribute('value')).join(',') === 'p102,d44',
  options.map(o => o.getAttribute('value')).join(','));
ok('the amount defaults to what the first credit can cover', form.querySelector('input[name="amount"]')?.value === '8000',
  form.querySelector('input[name="amount"]')?.value);
ok('it cannot be set below a cent', form.querySelector('input[name="amount"]')?.getAttribute('min') === '0.01');
ok('the form posts to this bill', /#ap-bills-apply-771/.test(form.getAttribute('action') ?? ''), form.getAttribute('action'));
ok('no empty card is left where a credit could not be applied', $('#noCredit') === null);

/* ── the ledger side ────────────────────────────────────────────────────── */
ok('the posting behind the bill is shown', /GL-2026-1188/.test(text(doc.body)));
ok('it links to the journal', $$('a').some(a => /#gl-journals-show-1188/.test(a.getAttribute('href') ?? '')));
ok('and it is for the bill’s total', /GL-2026-1188 · 26 Sep 2026 · GH₵ 23,000\.00/.test(text(doc.body)),
  text(doc.body).match(/GL-2026-1188[^|]{0,60}/)?.[0]);

/* ── nothing leaked ─────────────────────────────────────────────────────── */
const copy = doc.body.cloneNode(true);
copy.querySelectorAll('script, style').forEach(node => node.remove());
const clean = text(copy);
ok('no Blade directive survived', !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless|isset|endisset)\b/.test(clean));
ok('no undefined, NaN or [object Object] reached the screen', !/undefined|NaN|\[object Object\]/.test(clean));
ok('no raw placeholder was printed', !/\{\{|\}\}|\{od\}/.test(clean));
ok('no escaped markup was printed as text', !/&lt;(nav|span|a|div|td)\b/.test(clean));
ok('no ISO date was left unformatted', !/\b2026-\d\d-\d\d\b/.test(text($('.content'))));

if (failures.length) {
  console.error(`ap bill check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`ap bill check: ${passed}/${passed} passed`);
