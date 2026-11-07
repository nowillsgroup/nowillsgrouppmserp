#!/usr/bin/env node
/*
 * Supplier payments, aged and evidenced — money that left the bank and has not reached a bill.
 *
 * The existing ap_payments check holds the credit rule (a foreign remainder and a cancelled payment are not credits) and
 * the header figures. This one holds v9.15's three additions, which are all about the same thing: whether the row can be
 * believed.
 *
 *   1. the ageing — the unapplied money aged from the day it left, on the receivables' 30/60/90 rule, with the buckets
 *      adding to the footer, the footer to the header, and the header to the KPI the account manager already reads;
 *   2. the certificate — every payment that withheld tax must name one, and the money on the missing documents must be
 *      the difference between what was withheld and what is certified;
 *   3. the allocation tie — the allocated column, which the ageing and the supplier statement rest on, must be the
 *      allocations on file, payment by payment;
 *   4. the row — the list itself prints each payment's age and its certificate, and the drift build shows what a stale
 *      row looks like.
 *
 * Renders deploy/dev/previews/ap-payments.html and ap-payments-drift.html in jsdom against
 * deploy/dev/preview/ap-payments-sample.mjs.
 *
 * Run: node deploy/dev/audit/ap_payments_ageing_preview_check.js
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
const round2 = (v) => Math.round(v * 100) / 100;
const BUCKETS = { b0: '0–30 days', b30: '31–60 days', b60: '61–90 days', b90: 'Over 90 days' };

const doc = load('deploy/dev/previews/ap-payments.html', 'https://preview.local/ap/payments');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the ageing ─────────────────────────────────────────────────────────── */
const ageCard = $('#creditAge');
ok('the page ages the money it is holding', ageCard !== null && /How long has the supplier's money been on our books\?/.test(text(ageCard)));
ok('the head states the population, the money and the age',
  /1 payment · GH₵ 8,000\.00 unapplied · oldest 8 days/.test(text(ageCard.querySelector('.card-head'))), text(ageCard.querySelector('.card-head')));
const ageRows = [...$$('#creditAgeTable tbody tr')].map(r => ({
  label: text(r.children[0]), bucket: r.getAttribute('data-bucket'),
  claims: Number(text(r.children[1])), money: num(r.children[2]), share: text(r.children[3]),
}));
ok('all four bands are on the table, in order', ageRows.length === 4 && ageRows.map(r => r.bucket).join(',') === 'b0,b30,b60,b90',
  ageRows.map(r => r.bucket).join(','));
ok('the bands are the receivables’ 30/60/90 rule, labelled as such',
  ageRows.map(r => r.label).join(' | ') === Object.values(BUCKETS).join(' | '), ageRows.map(r => r.label).join(' | '));
ok('the eight-day-old payment is in the first band', ageRows[0].claims === 1 && ageRows[0].money === 8000, JSON.stringify(ageRows[0]));
ok('an empty band prints a dash, not a zero', ageRows.slice(1).every(r => r.money === 0 && r.share === '—'), ageRows.slice(1).map(r => r.share).join(' | '));
ok('the footer is the sum of the bands',
  Number(text($('#ageSumCount'))) === ageRows.reduce((t, r) => t + r.claims, 0)
  && num($('#ageSumTotal')) === round2(ageRows.reduce((t, r) => t + r.money, 0)),
  `${text($('#ageSumCount'))} / ${text($('#ageSumTotal'))}`);
ok('and the ageing is the header’s own figure, not a second opinion',
  num($('#ageSumTotal')) === 8000 && /8,000\.00/.test(text($$('.stats')[0].querySelectorAll('.stat')[0])),
  `${text($('#ageSumTotal'))} vs ${text($$('.stats')[0].querySelectorAll('.stat')[0])}`);
ok('the header now carries the age as well as the total', /oldest 8 days/.test(text($$('.stats')[0].querySelectorAll('.stat')[0])), text($$('.stats')[0].querySelectorAll('.stat')[0]));
ok('the verdict names the oldest payment, its supplier, its money and its age',
  /The oldest is PAY-2026-0102 from Coastal Beverages Ltd — GH₵ 8,000\.00 unapplied for 8 days\./.test(text($('#creditAgeState'))), text($('#creditAgeState')));
ok('and says why ninety days is the line that matters',
  /Nothing has been unapplied for more than ninety days, so no bill has sat unfound long enough to be written off\./.test(text($('#creditAgeState'))), text($('#creditAgeState')));
ok('nothing is flagged red that is not late', !$('#creditAgeState').classList.contains('text-red'));
ok('the shares are the bands against the total',
  ageRows[0].share === '100 %' && ageRows.slice(1).every(r => r.share === '—'), ageRows.map(r => r.share).join(' | '));

/* ── the certificate ────────────────────────────────────────────────────── */
const cert = $('#whtCert');
ok('the page asks whether every withheld cedi is certified', /Is every withheld cedi certified\?/.test(text(cert)));
ok('the head counts the paid, the certified and the gaps',
  /3 payments withheld tax · 3 certified · 0 without a certificate/.test(text(cert.querySelector('.card-head'))), text(cert.querySelector('.card-head')));
const cStats = [...cert.querySelectorAll('.stat')].map(s => text(s));
ok('the tax withheld is stated as a liability, not a discount',
  /Tax withheld/.test(cStats[0]) && /4,320\.00/.test(cStats[0]) && /a GRA liability, not a discount/.test(cStats[0]), cStats[0]);
ok('the certified money is the same money', /Certified/.test(cStats[1]) && /4,320\.00/.test(cStats[1]) && /Certificates issued to the supplier: 3/.test(cStats[1]), cStats[1]);
ok('and a nil gap is green and says so', /Without a certificate/.test(cStats[2]) && /0\.00/.test(cStats[2])
  && cert.querySelectorAll('.stat')[2].classList.contains('tone-green'), cStats[2]);
ok('the page says the paper exists for the tax', /so the supplier can claim the GH₵ 4,320\.00 against their own assessment/.test(text($('#whtCertState'))), text($('#whtCertState')));
ok('no gap table is printed when there are no gaps', $('#certGap') === null);
/* the row itself carries the certificate number, so the list can be audited without the card */
const rowOf = (d, no) => [...d.querySelectorAll('#payTable tbody tr')].find(r => text(r).includes(no));
const certifiedRow = rowOf(doc, 'PAY-2026-0071');
ok('a payment that withheld tax prints its certificate in the row', /WHT-2026-0022/.test(text(certifiedRow)) && /1,500\.00/.test(text(certifiedRow)), text(certifiedRow));
ok('and a payment that withheld nothing says so quietly',
  text(rowOf(doc, 'PAY-2026-0102').children[9]) === '—' && !/WHT-/.test(text(rowOf(doc, 'PAY-2026-0102'))),
  text(rowOf(doc, 'PAY-2026-0102').children[9]));

/* ── the allocation tie ─────────────────────────────────────────────────── */
const alloc = $('#allocTie');
ok('the page checks the allocated column against the allocations', /Does the allocated column match the allocations\?/.test(text(alloc)));
ok('the head counts the population and the disagreements',
  /3 payments applied · 0 where the two disagree/.test(text(alloc.querySelector('.card-head'))), text(alloc.querySelector('.card-head')));
const aStats = [...alloc.querySelectorAll('.stat')].map(s => text(s));
ok('the payments say 67,200.00 was allocated', /Allocated, per the payments/.test(aStats[0]) && /67,200\.00/.test(aStats[0]), aStats[0]);
ok('and the allocations on file say the same', /Allocations on file/.test(aStats[1]) && /67,200\.00/.test(aStats[1]), aStats[1]);
ok('the difference is nil and green', /Difference/.test(aStats[2]) && /0\.00/.test(aStats[2])
  && alloc.querySelectorAll('.stat')[2].classList.contains('tone-green'), aStats[2]);
ok('the page says the column can be trusted where the ageing reads it',
  /The column can be trusted where the ageing reads it\./.test(text($('#allocState'))), text($('#allocState')));
ok('no allocation gap table is printed when there is none', $('#allocGap') === null);

/* ── the rows ───────────────────────────────────────────────────────────── */
const rows = [...$$('#payTable tbody tr')];
ok('every payment is on the list', rows.length === 5, `${rows.length}`);
ok('each row prints how long ago the payment was made', rows.every(r => /\d+ d/.test(text(r.children[8]))),
  rows.map(r => text(r.children[8])).join(' | '));
ok('the unapplied payment carries the band its age falls in', rows.find(r => /PAY-2026-0102/.test(text(r))).getAttribute('data-bucket') === 'b0',
  rows.find(r => /PAY-2026-0102/.test(text(r))).getAttribute('data-bucket'));
ok('a fully applied payment carries no band at all', rows.filter(r => /PAY-2026-0071|PAY-2026-0098/.test(text(r))).every(r => r.getAttribute('data-bucket') === ''),
  rows.map(r => r.getAttribute('data-bucket')).join(' | '));
ok('the list’s unapplied money is the ageing’s money',
  round2(rows.reduce((t, r) => t + num(r.children[7]), 0)) === num($('#ageSumTotal')),
  `${rows.reduce((t, r) => t + num(r.children[7]), 0)} vs ${num($('#ageSumTotal'))}`);
ok('the credit rule still holds beside all this: one credit row only', rows.filter(r => r.querySelector('.badge-amber')).length === 1);

/* ── the drift build: two records that no longer agree ──────────────────── */
const drift = load('deploy/dev/previews/ap-payments-drift.html', 'https://preview.local/ap/payments');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dCert = drift.querySelector('#whtCert');
ok('the drift build counts the payment nobody certified', /3 payments withheld tax · 2 certified · 1 without a certificate/.test(dText(dCert.querySelector('.card-head'))), dText(dCert.querySelector('.card-head')));
const dStats = [...dCert.querySelectorAll('.stat')].map(s => dText(s));
ok('the withheld money is unchanged, only the paper is missing', /4,320\.00/.test(dStats[0]) && /3,840\.00/.test(dStats[1]), `${dStats[0]} / ${dStats[1]}`);
ok('the gap is stated in money and marked red', /Without a certificate/.test(dStats[2]) && /480\.00/.test(dStats[2])
  && dCert.querySelectorAll('.stat')[2].classList.contains('tone-red'), dStats[2]);
ok('what was withheld and what is certified differ by exactly the gap', 4320 - 3840 === 480);
const certGap = [...drift.querySelectorAll('#certGap tbody tr')];
ok('the uncertified payment is named, with its age and its money', certGap.length === 1
  && /PAY-2026-0098/.test(dText(certGap[0])) && /Sahel Textiles/.test(dText(certGap[0]))
  && /110 d/.test(dText(certGap[0])) && /480\.00/.test(dText(certGap[0])), dText(certGap[0]));
ok('and the page says what to do about it', /issue the certificate or correct the withholding/.test(dText(drift.querySelector('#whtCertState'))), dText(drift.querySelector('#whtCertState')));
const dAlloc = drift.querySelector('#allocTie');
ok('the allocation tie breaks in the drift build', /3 payments applied · 1 where the two disagree/.test(dText(dAlloc.querySelector('.card-head'))), dText(dAlloc.querySelector('.card-head')));
const dAStats = [...dAlloc.querySelectorAll('.stat')].map(s => dText(s));
ok('the two records are 500.00 apart', /66,700\.00/.test(dAStats[0]) && /66,200\.00/.test(dAStats[1]) && /500\.00/.test(dAStats[2]), `${dAStats[0]} / ${dAStats[1]} / ${dAStats[2]}`);
ok('and the break is red', dAlloc.querySelectorAll('.stat')[2].classList.contains('tone-red') === true);
const allocGap = [...drift.querySelectorAll('#allocGap tbody tr')];
ok('the payment is named with both figures', allocGap.length === 1 && /PAY-2026-0105/.test(dText(allocGap[0]))
  && /30,700\.00/.test(dText(allocGap[0])) && /30,200\.00/.test(dText(allocGap[0])), dText(allocGap[0]));
ok('the page says which record the ageing rests on', /both rest on the column/.test(dText(drift.querySelector('#allocState'))), dText(drift.querySelector('#allocState')));
ok('the drift does not touch the ageing — the column is still what it is',
  dText(drift.querySelector('#ageSumTotal')) === dText(doc.querySelector('#ageSumTotal'))
  && dText(drift.querySelector('#creditAgeState')) === dText(doc.querySelector('#creditAgeState')));
ok('nor the credit the ageing and the bills list share',
  /8,000\.00/.test(dText(drift.querySelectorAll('.stats')[0].querySelectorAll('.stat')[0])));
ok('the row itself shows the missing certificate, not only the card',
  new RegExp('PAY-2026-0098').test(dText(drift.querySelector('#payTable'))));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
}

if (failures.length) {
  console.error(`ap payments ageing check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`ap payments ageing check: ${passed}/${passed} passed`);
