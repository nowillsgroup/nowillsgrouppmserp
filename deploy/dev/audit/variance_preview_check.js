#!/usr/bin/env node
/*
 * Variance review.
 *
 * Three things must hold. The worklist must be exactly what the flag rule selects — a row whose variance is under
 * either threshold has no business asking for an explanation, and a flagged row that is missing would let a month be
 * signed off with a variance nobody looked at. Every row must say who owes the explanation and by when. And the
 * sign-off must be refused while one is open, naming the accounts — not silently allowed.
 *
 * Renders deploy/dev/previews/budget-variance.html (two explained, three open, sign-off blocked) and
 * budget-variance-signed.html (all explained, signed off) in jsdom against
 * deploy/dev/preview/variance-sample.mjs.
 *
 * Run: node deploy/dev/audit/variance_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const load = (file) => {
  const raw = readFileSync(file, 'utf8');
  const dom = new JSDOM(raw, { url: 'https://preview.local/budgets/2/variance' });
  const doc = dom.window.document;
  const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
  return { raw, dom, doc, text, $: (s) => doc.querySelector(s), $$: (s) => [...doc.querySelectorAll(s)] };
};

const open = load('deploy/dev/previews/budget-variance.html');
const signed = load('deploy/dev/previews/budget-variance-signed.html');

/* ── the screen ──────────────────────────────────────────────────────────── */
ok('the page is the variance review', open.doc.title.startsWith('Variance review'), open.doc.title);
ok('the breadcrumb names the version and the month', /BUD-2026-27-01 · Variance review · October 2026/.test(open.text(open.$('.crumbs'))), open.text(open.$('.crumbs')));
ok('the header links back to the version and the comparison', Boolean(open.$('#bvaLink')) && open.$$('.actions a').length >= 2);

const rows = open.$$('#varianceTable tbody tr');
ok('every flagged account is listed', rows.length === 5, `${rows.length} rows`);
const body = (r) => open.text(r);

/* ── the worklist is exactly the flag rule ───────────────────────────────── */
const parsed = rows.map((r) => {
  const cells = [...r.children].map((c) => open.text(c));
  return { account: cells[0], budget: Number(cells[1].replace(/[^\d.]/g, '')), actual: Number(cells[2].replace(/[^\d.]/g, '')), variance: cells[3] };
});
ok('the first row is the one the ledger overran most in percentage',
  /Housekeeping supplies/.test(parsed[0].account) && /\+27\.6 %/.test(parsed[0].variance), parsed[0].variance);
ok('an overspend is marked unfavourable (red)', /text-red/.test(rows[0].children[3].className), rows[0].children[3].className);
const welfare = rows.find((r) => /Staff welfare/.test(body(r)));
ok('an underspend is marked favourable (green)', /text-green/.test(welfare.children[3].className) && /−?13,100\.00|\-13,100\.00/.test(open.text(welfare.children[3])), open.text(welfare.children[3]));
ok('every listed row clears BOTH thresholds',
  parsed.every((p) => {
    const pct = Math.abs((p.actual - p.budget) * 100 / p.budget);
    return Math.abs(p.actual - p.budget) >= 10000 && pct >= 10;
  }), JSON.stringify(parsed.map((p) => Math.abs((p.actual - p.budget) * 100 / p.budget).toFixed(1))));
ok('the rule itself is printed, both for percentage and amount',
  /≥ 10 %/.test(open.text(open.$('#reviewFilter'))) && /≥ GH₵ 10,000\.00/.test(open.text(open.$('#reviewFilter'))));
ok('the deadline rule is printed with its unit', /10 day\(s\)/.test(open.text(open.$('#reviewFilter'))) && /0–90/.test(open.text(open.doc.body)));

/* ── each row says who owes it and when ──────────────────────────────────── */
ok('an explained row shows the words and who wrote them',
  /agency housekeepers/.test(body(rows[0])) && /Ama Serwaa/.test(body(rows[0])));
ok('…and carries its state as a badge', /badge-blue/.test(rows[0].innerHTML) && /Explained/.test(body(rows[0])));
const repairs = rows.find((r) => /Repairs & maintenance/.test(body(r)));
ok('an open row says so plainly rather than showing a blank cell', /No explanation yet\./.test(body(repairs)));
ok('an open row names nobody yet rather than leaving the owner blank', /nobody yet/.test(body(repairs)));
ok('a deadline already passed is marked as passed, in red', /was due 05 Nov 2026/.test(body(repairs)) && /text-red/.test(repairs.innerHTML));
ok('a deadline in the future is stated as due', /due 10 Nov 2026/.test(body(rows[0])));
ok('the stats count what is explained and what is not',
  /2 \/ 5/.test(open.text(open.$('.stats'))) && /Still to explain/.test(open.text(open.$('.stats'))));

/* ── the sign-off is refused while one is open ───────────────────────────── */
const blockers = open.$$('#signoffBlockers li');
ok('the blockers are listed, one per unexplained variance', blockers.length === 3, `${blockers.length} blockers`);
ok('a blocker names the account and the money', /6100|Housekeeping|Food cost|Repairs|Staff welfare|Utilities/.test(open.text(blockers[0])) && /GH₵/.test(open.text(blockers[0])), open.text(blockers[0]));
ok('…the amount is unsigned and the word carries the direction', /GH₵ 13,100\.00 under/.test(open.text(blockers[2])) && !/GH₵ -/.test(open.text(open.$('#signoffBlockers'))), open.text(blockers[2]));
ok('the sign-off button is disabled on the screen', open.$('#signoffBtn')?.hasAttribute('disabled') === true);
ok('the screen says the month is not signed off', /not signed off/.test(open.text(open.$('.stats'))));
ok('…and offers the note field for when it can be', Boolean(open.$('#signoffForm [name=note]')));

/* ── signed off completes the picture ────────────────────────────────────── */
ok('the signed state has no blockers', signed.$$('#signoffBlockers li').length === 0);
ok('…and states who signed it, when, and the count at the time',
  /Kwesi Boateng/.test(signed.text(signed.$('#signoffInfo'))) && /06 Nov 2026/.test(signed.text(signed.$('#signoffInfo'))) && /5 of 5 explained/.test(signed.text(signed.$('#signoffInfo'))));
ok('…with the note kept', /Reviewed with the heads of department on 5 November/.test(signed.text(signed.$('#signoffInfo'))));
ok('every row is explained in the signed state', /5 \/ 5/.test(signed.text(signed.$('.stats'))));
ok('…and none claims to be open', !/No explanation yet/.test(signed.text(signed.doc.body)));

/* ── explanation is written against this version and month ───────────────── */
const modal = open.$('.modal form[action*="explain"]');
ok('each row has its own explanation form', open.$$('.modal form[action*="explain"]').length === 5, `${open.$$('.modal form[action*="explain"]').length} forms`);
ok('the form carries the month and the account, not free text',
  Boolean(modal?.querySelector('[name=month]')) && Boolean(modal?.querySelector('[name=account_head_id]')));
ok('and asks what happened, who owns it and by when',
  Boolean(modal?.querySelector('textarea[name=body]')) && Boolean(modal?.querySelector('select[name=assigned_to]')) && Boolean(modal?.querySelector('input[name=due_on]')));
ok('the owner list is people, not a free-text field', open.$$('.modal select[name=assigned_to] option').length >= 5);
ok('an approver can accept an explained variance', open.$$('form[action*="comment"] button').length >= 1);

/* ── nothing leaked ──────────────────────────────────────────────────────── */
for (const [label, page] of [['open', open], ['signed', signed]]) {
  const text = page.text(page.doc.body);
  ok(`no Blade directive survived in the ${label} state`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php)\b/.test(text));
  ok(`no undefined, NaN or [object Object] reached the ${label} state`, !/undefined|NaN|\[object Object\]/.test(text));
  ok(`no raw placeholder was printed in the ${label} state`, !/\{\{|\}\}/.test(text));
}

if (failures.length) {
  console.error(`variance review check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`variance review check: ${passed}/${passed} passed`);
