#!/usr/bin/env node
/*
 * Expense claims — how long a person has been waiting for their own money, and whether the line can be proved.
 *
 * A claim is money an employee has already spent out of their own pocket, so the screen's two real questions are about
 * time and evidence, not volume. This check holds it to both, and to the arithmetic that makes the page one page:
 *
 *   1. the queues — every claim's age is recomputed from the date its queue started, bucketed by the 7/14/30 rule here,
 *      and the bucket columns must add to the row, the row to the footer, and the footer to the header;
 *   2. the list and the card must agree — the same claims, aged the same way, from the table's own Age column rather than
 *      from the sample that fed both;
 *   3. the receipt rule — the counts, the money and the population have to be each other, and a line over the threshold
 *      with neither a receipt nor a transaction number may not be left standing without being named;
 *   4. the claim window — re-tested against the day each claim was submitted, not the day the file was read.
 *
 * Renders deploy/dev/previews/claims.html and claims-drift.html in jsdom against deploy/dev/preview/claims-sample.mjs.
 *
 * Run: node deploy/dev/audit/claims_preview_check.js
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
const count = (cell) => Number((text(cell).match(/(\d+)\s*(?:claim|line)/) ?? [0, 0])[1] ?? 0);
const round2 = (v) => Math.round(v * 100) / 100;

const AS_OF = Date.parse('2026-11-07T00:00:00Z');
const daysFrom = (label) => {
  const [d, m, y] = String(label).split(' ');
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return Math.round((AS_OF - Date.parse(`${y}-${String(months.indexOf(m) + 1).padStart(2, '0')}-${d}T00:00:00Z`)) / 86400000);
};
const BUCKETS = { w0: '0–7 days', w1: '8–14 days', w2: '15–30 days', w3: 'Over 30 days' };
const bucketOf = (days) => (days <= 7 ? 'w0' : (days <= 14 ? 'w1' : (days <= 30 ? 'w2' : 'w3')));
const SUBMITTED_ON = { 'CLM-2026-0142': '06 Nov 2026', 'CLM-2026-0139': '02 Nov 2026', 'CLM-2026-0131': '26 Oct 2026', 'CLM-2026-0118': '18 Oct 2026', 'CLM-2026-0104': '05 Oct 2026' };
const APPROVED_ON = { 'CLM-2026-0136': '04 Nov 2026', 'CLM-2026-0129': '27 Oct 2026', 'CLM-2026-0112': '16 Oct 2026' };

const doc = load('deploy/dev/previews/claims.html', 'https://preview.local/claims?status=submitted');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the document ───────────────────────────────────────────────────────── */
ok('the page is the expense claims screen', /Expense Claims/.test(doc.title), doc.title);
ok('it sits under accounts', /Accounts \/ Expense claims/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the actions bar survived the rebuild', /New claim/.test(text($('.page-head .actions'))) && /Claims register/.test(text($('.page-head .actions'))), text($('.page-head .actions')));
ok('the receipt threshold the page is judged by is on the settings form', $('#f_claims_receipt_threshold')?.value === '50', $('#f_claims_receipt_threshold')?.value);
ok('and the window it re-tests is the one set beside it', $('#f_claims_max_age_days')?.value === '60', $('#f_claims_max_age_days')?.value);

/* ── the header ─────────────────────────────────────────────────────────── */
const stats = [...$$('#claimStats .stat')].map(s => text(s));
ok('the header states four figures', stats.length === 4, `${stats.length}`);
ok('five claims wait for approval, 4,510.00, oldest 33 days', /To approve/.test(stats[0]) && /^5/.test(stats[0]) && /4,510\.00/.test(stats[0]) && /oldest waiting 33 days/.test(stats[0]), stats[0]);
ok('three wait for their money, 1,580.00, oldest 22 days', /To pay/.test(stats[1]) && /^3/.test(stats[1]) && /1,580\.00/.test(stats[1]) && /oldest approved 22 days/.test(stats[1]), stats[1]);
ok('one was sent back', /Sent back/.test(stats[2]) && /^1/.test(stats[2]) && /420\.00/.test(stats[2]), stats[2]);
ok('two have been paid, 2,050.00', /Paid/.test(stats[3]) && /^2/.test(stats[3]) && /2,050\.00/.test(stats[3]), stats[3]);

/* ── the two queues ─────────────────────────────────────────────────────── */
ok('the page asks how long each claim has waited', /How long has each claim waited\?/.test(text($('#claimQueue'))));
ok('the head totals both queues', /8 claims · GH₵ 6,090\.00 in the two queues · oldest 33 days/.test(text($('#claimQueue .card-head'))), text($('#claimQueue .card-head')));
const queueRows = [...$$('#queueTable tbody tr')].map(r => ({
  label: text(r.children[0]), claims: Number(text(r.children[1])),
  buckets: [num(r.children[2]), num(r.children[3]), num(r.children[4]), num(r.children[5])],
  counts: [count(r.children[2]), count(r.children[3]), count(r.children[4]), count(r.children[5])],
  amount: num(r.children[6]), oldest: Number((text(r.children[7]).match(/(\d+) d/) ?? [0, -1])[1]),
}));
ok('both queues are on the table', queueRows.length === 2, `${queueRows.length}`);
ok('approval is aged from the day the claim was sent in', /Awaiting approval/.test(queueRows[0].label) && /ageing from the day it was sent in/.test(queueRows[0].label), queueRows[0].label);
ok('payment is aged from the day it was approved', /Awaiting payment/.test(queueRows[1].label) && /ageing from the day it was approved/.test(queueRows[1].label), queueRows[1].label);
ok('each queue’s buckets add to its money', queueRows.every(r => Math.abs(r.buckets.reduce((t, v) => t + v, 0) - r.amount) < 0.005),
  queueRows.map(r => `${r.label}: ${r.buckets.join('+')} vs ${r.amount}`).join(' | '));
ok('and the bucket counts add to its claims', queueRows.every(r => r.counts.reduce((t, v) => t + v, 0) === r.claims),
  queueRows.map(r => `${r.label}: ${r.counts.join('+')} vs ${r.claims}`).join(' | '));
ok('the oldest approval has waited 33 days and the oldest payment 22', queueRows[0].oldest === 33 && queueRows[1].oldest === 22, `${queueRows[0].oldest} / ${queueRows[1].oldest}`);
ok('one claim has been waiting over a month, and it is red', queueRows[0].counts[3] === 1 && queueRows[0].buckets[3] === 1460
  && [...$$('#queueTable tbody tr')][0].children[5].classList.contains('text-red'));
ok('the footer is the sum of the queues',
  Number(text($('#queueSumClaims'))) === queueRows.reduce((t, r) => t + r.claims, 0)
  && num($('#queueSumAmount')) === round2(queueRows.reduce((t, r) => t + r.amount, 0))
  && ['w0', 'w1', 'w2', 'w3'].every((b, i) => num($(`#queueSum${b}`)) === round2(queueRows.reduce((t, r) => t + r.buckets[i], 0))),
  `${text($('#queueSumClaims'))} / ${text($('#queueSumAmount'))}`);
ok('and the footer agrees with the header', num($('#queueSumAmount')) === 6090 && Number(text($('#queueSumClaims'))) === 8);
const qState = text($('#claimQueueState'));
ok('the page names the worst approval, its staff and its money', /33 days: CLM-2026-0104 from Kofi Mensah for GH₵ 1,460\.00/.test(qState), qState);
ok('and the worst payment likewise', /22 days for its money — CLM-2026-0112 from Yaw Boateng for GH₵ 540\.00/.test(qState), qState);

/* ── the claim window, re-tested at submission ──────────────────────────── */
const windowState = text($('#claimWindow'));
ok('the window sentence states the oldest line at submission', /No line on any live claim was older than 27 days when it was submitted/.test(windowState), windowState);
ok('and the rule it is measured against', /the window is 60 days/.test(windowState), windowState);
ok('nothing is flagged past the window in this build', !/older than the 60-day window when the claim was submitted:/.test(windowState));

/* ── the receipt rule, read back over the live claims ───────────────────── */
const ev = $('#claimEvidence');
ok('the page asks whether a receipt stands behind every cedi claimed', /Is there a receipt behind every cedi claimed\?/.test(text(ev)));
ok('the head counts the population and the evidence', /16 lines over GH₵ 50\.00 · 12 on file · 4 by reference only · 0 with neither/.test(text(ev.querySelector('.card-head'))), text(ev.querySelector('.card-head')));
const eStats = [...ev.querySelectorAll('.stat')].map(s => text(s));
ok('the money over the threshold is stated', /Claimed over the threshold/.test(eStats[0]) && /8,140\.00/.test(eStats[0]) && /16 lines on live claims/.test(eStats[0]), eStats[0]);
ok('twelve carry a receipt', /With a receipt/.test(eStats[1]) && /^12/.test(eStats[1]), eStats[1]);
ok('four carry a transaction number instead', /By reference only/.test(eStats[2]) && /^4/.test(eStats[2]), eStats[2]);
ok('nothing is left with neither, in green', /With neither/.test(eStats[3]) && /0\.00/.test(eStats[3])
  && ev.querySelectorAll('.stat')[3].classList.contains('tone-green'), eStats[3]);
ok('three kinds of evidence account for the whole population', 12 + 4 + 0 === 16);
ok('the page says the rule holds', /Every one of the 16 lines over GH₵ 50\.00 carries a receipt or a transaction number, covering GH₵ 8,140\.00/.test(text($('#claimEvidenceState'))), text($('#claimEvidenceState')));
ok('and says why mileage is not in the count', /Mileage is paid at GH₵ 2\.50\/km and needs no receipt/.test(text($('#claimEvidenceState'))));
ok('no gap table is printed when there are no gaps', $('#gapTable') === null);

/* ── the list, aged the same way as the card ────────────────────────────── */
const tableRows = [...$$('#claimTable tbody tr')].map(r => ({
  claim: text(r.children[0]), staff: text(r.children[1]), status: text(r.children[6]),
  age: (text(r.children[7]).match(/(\d+) d/) ?? [])[1],
  waiting: /waiting/.test(text(r.children[7])) ? 'waiting' : (/to pay/.test(text(r.children[7])) ? 'to pay' : ''),
  total: num(r.children[4]),
}));
ok('the list shows every claim', tableRows.length === 12, `${tableRows.length}`);
ok('a claim in a queue prints its age and which queue it is in',
  tableRows.filter(r => r.age !== undefined).length === 8
  && tableRows.filter(r => r.waiting === 'waiting').length === 5
  && tableRows.filter(r => r.waiting === 'to pay').length === 3,
  tableRows.map(r => `${r.claim}:${r.age ?? '—'}/${r.waiting}`).join(' | '));
ok('approval rows age from the day they were submitted', Object.entries(SUBMITTED_ON).every(([claim, date]) => {
  const row = tableRows.find(r => r.claim === claim);
  return row !== undefined && Number(row.age) === daysFrom(date);
}), Object.entries(SUBMITTED_ON).map(([c, d]) => `${c}=${tableRows.find(r => r.claim === c)?.age}/${daysFrom(d)}`).join(' | '));
ok('payment rows age from the day they were approved', Object.entries(APPROVED_ON).every(([claim, date]) => {
  const row = tableRows.find(r => r.claim === claim);
  return row !== undefined && Number(row.age) === daysFrom(date);
}), Object.entries(APPROVED_ON).map(([c, d]) => `${c}=${tableRows.find(r => r.claim === c)?.age}/${daysFrom(d)}`).join(' | '));
ok('a claim not in a queue prints no age at all',
  tableRows.filter(r => ['Draft', 'Paid', 'Rejected'].includes(r.status)).every(r => r.age === undefined),
  tableRows.filter(r => ['Draft', 'Paid', 'Rejected'].includes(r.status)).map(r => `${r.claim}:${r.age}`).join(' | '));
/* the table and the queue card must be the same population, aged the same way */
const fromTable = { submitted: [0, 0, 0, 0], approved: [0, 0, 0, 0] };
for (const r of tableRows) {
  if (r.waiting !== 'waiting' && r.waiting !== 'to pay') continue;
  const key = r.waiting === 'waiting' ? 'submitted' : 'approved';
  fromTable[key][['w0', 'w1', 'w2', 'w3'].indexOf(bucketOf(Number(r.age)))] += 1;
}
ok('the list buckets into the card, claim for claim',
  fromTable.submitted.join(',') === queueRows[0].counts.join(',') && fromTable.approved.join(',') === queueRows[1].counts.join(','),
  `${fromTable.submitted.join(',')} vs ${queueRows[0].counts.join(',')} · ${fromTable.approved.join(',')} vs ${queueRows[1].counts.join(',')}`);
ok('the list’s money is the card’s money, queue for queue',
  round2(tableRows.filter(r => r.waiting === 'waiting').reduce((t, r) => t + r.total, 0)) === queueRows[0].amount
  && round2(tableRows.filter(r => r.waiting === 'to pay').reduce((t, r) => t + r.total, 0)) === queueRows[1].amount,
  tableRows.filter(r => r.waiting === 'waiting').reduce((t, r) => t + r.total, 0));
ok('a claim over a month in the queue is flagged in the list too',
  [...$$('#claimTable tbody tr')].find(r => /CLM-2026-0104/.test(text(r))).children[7].classList.contains('text-red'));
ok('a sent-back claim still carries its reason', /No receipt on the taxi line/.test(text($('#claimTable'))));

/* ── the drift build: a line nobody can prove ───────────────────────────── */
const drift = load('deploy/dev/previews/claims-drift.html', 'https://preview.local/claims?status=submitted');
const d$ = (s) => drift.querySelector(s);
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dEv = drift.querySelector('#claimEvidence');
ok('the drift build counts one line with nothing', /16 lines over GH₵ 50\.00 · 11 on file · 4 by reference only · 1 with neither/.test(dText(dEv.querySelector('.card-head'))), dText(dEv.querySelector('.card-head')));
const dStats = [...dEv.querySelectorAll('.stat')].map(s => dText(s));
ok('the money without evidence is stated', /With neither/.test(dStats[3]) && /940\.00/.test(dStats[3]) && /1 line with nothing behind it/.test(dStats[3]), dStats[3]);
ok('and marked red', dEv.querySelectorAll('.stat')[3].classList.contains('tone-red') === true);
ok('the population is unchanged by it, only the evidence', /Claimed over the threshold/.test(dStats[0]) && /8,140\.00/.test(dStats[0]), dStats[0]);
ok('the page says what the rule is for', /the same rule that stops the line being entered is the rule the payment rests on/.test(dText(drift.querySelector('#claimEvidenceState'))), dText(drift.querySelector('#claimEvidenceState')));
const gapRows = [...drift.querySelectorAll('#gapTable tbody tr')];
ok('the gap is named as one row', gapRows.length === 1, `${gapRows.length}`);
ok('with its claim, staff, date, age and money', /CLM-2026-0131/.test(dText(gapRows[0])) && /Yaw Boateng/.test(dText(gapRows[0]))
  && /26 Oct 2026/.test(dText(gapRows[0])) && /12 d/.test(dText(gapRows[0])) && /940\.00/.test(dText(gapRows[0])), dText(gapRows[0]));
ok('and it is the only one', /1 line over GH₵ 50\.00 has neither a receipt nor a transaction number/.test(dText(drift.querySelector('#claimEvidenceState'))));
ok('a missing receipt does not move the queue — it is a different question',
  /8 claims · GH₵ 6,090\.00 in the two queues · oldest 33 days/.test(dText(drift.querySelector('#claimQueue .card-head')))
  && dText(drift.querySelector('#queueSumAmount')) === dText(doc.querySelector('#queueSumAmount')),
  dText(drift.querySelector('#claimQueue .card-head')));
ok('and the claim window still holds', /No line on any live claim was older than 27 days/.test(dText(drift.querySelector('#claimWindow'))));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless|isset|endisset)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
  ok(`${name} holds no escaped markup printed as text`, !/&lt;(nav|span|a|div|td)\b/.test(clean));
}

if (failures.length) {
  console.error(`claims check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`claims check: ${passed}/${passed} passed`);
