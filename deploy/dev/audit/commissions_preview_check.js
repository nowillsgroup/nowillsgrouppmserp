#!/usr/bin/env node
/*
 * Agent commissions — how old the money is, which agent is carrying it, and whether every settlement has a document.
 *
 * A commission accrues at check-out and is settled when the agent asks, so the only two things that matter about it are
 * age and evidence. This check holds both:
 *
 *   1. the ageing is real — every row's days are the days from its check-out to the date being read, its bucket is the
 *      30/60/90 rule computed here independently, and the buckets across the screen add up to the total due;
 *   2. the agent summary is the same population — each agent's due is the sum of its own rows, and the footer is the sum of
 *      the agents, which is the defect the screen had when the summary was built from a different query than the table;
 *   3. the document control — four commissions are paid, four name their voucher, none is paid without one; the drift build
 *      is the 1,120.00 settlement with nothing behind it, and the page has to name it;
 *   4. nothing is payable until an agent is chosen, and the as-at control cannot be read into the future.
 *
 * Renders deploy/dev/previews/commissions.html and commissions-drift.html in jsdom against
 * deploy/dev/preview/commissions-sample.mjs.
 *
 * Run: node deploy/dev/audit/commissions_preview_check.js
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
const blank = (cell) => text(cell) === '' || text(cell) === '—';

/* the same rule the screen uses, computed here so the screen cannot mark its own homework */
const bucketOf = (days) => (days <= 30 ? 'b0' : (days <= 60 ? 'b30' : (days <= 90 ? 'b60' : 'b90')));
const LABEL = { b0: '0–30 days', b30: '31–60 days', b60: '61–90 days', b90: 'Over 90 days' };
const AS_OF = Date.parse('2026-11-07T00:00:00Z');
const daysTo = (iso) => Math.max(0, Math.round((AS_OF - Date.parse(`${iso}T00:00:00Z`)) / 86400000));

const doc = load('deploy/dev/previews/commissions.html', 'https://preview.local/accounts/commissions?status=due&as_of=2026-11-07');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the document ───────────────────────────────────────────────────────── */
ok('the page is the commissions screen', /Agent Commissions/.test(doc.title), doc.title);
ok('it sits under accounts', /Accounts \/ Agent Commissions/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the as-at date is a control that cannot be read into the future',
  $('input[name="as_of"]')?.value === '2026-11-07' && $('input[name="as_of"]')?.getAttribute('max') === '2026-11-07',
  `${$('input[name="as_of"]')?.value} / ${$('input[name="as_of"]')?.getAttribute('max')}`);
ok('the agent and status filters are on the page', $('select[name="company_id"]') !== null && $('select[name="status"]') !== null);

/* ── the ageing, from the header down ───────────────────────────────────── */
const stats = [...doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')].map(s => text(s));
ok('the header states four figures', stats.length === 4, `${stats.length}`);
ok('commission due is 23,820.00 as at 07 Nov 2026', /Commission due/.test(stats[0]) && /23,820\.00/.test(stats[0]) && /as at 07 Nov 2026/.test(stats[0]), stats[0]);
ok('and it says how many stays are unsettled', /6 unsettled stays/.test(stats[0]), stats[0]);
ok('the over-90 figure is 12,320.00 across two commissions', /Over 90 days/.test(stats[1]) && /12,320\.00/.test(stats[1]) && /2 commissions older than three months/.test(stats[1]), stats[1]);
ok('and it is red, because it is a position somebody should act on', doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')[1].classList.contains('tone-red') === true);
ok('three agents carry the dues', /Agents with dues/.test(stats[2]) && /3/.test(stats[2]), stats[2]);
ok('the oldest is 193 days old and flagged', /Oldest due/.test(stats[3]) && /193 days/.test(stats[3]), stats[3]);

/* ── every row is aged by its own check-out ─────────────────────────────── */
const rows = [...$$('#cmRows tbody tr')];
ok('the due population is listed', rows.length === 6, `${rows.length}`);
const aged = rows.map(r => ({
  date: text(r.children[0]),
  days: Number(text(r.children[1]).match(/(\d+) d/)?.[1] ?? -1),
  bucket: text(r.children[1]),
  agent: text(r.children[4]),
  amount: num(r.children[8]),
}));
ok('each row prints its age in days', aged.every(r => r.days >= 0), aged.map(r => r.days).join(' | '));
ok('the age is the days from the check-out to the date being read',
  aged.map(r => r.days).join(',') === [daysTo('2026-06-18'), daysTo('2026-10-02'), daysTo('2026-09-12'), daysTo('2026-10-20'), daysTo('2026-04-28'), daysTo('2026-11-05')].join(','),
  aged.map(r => `${r.date}=${r.days}`).join(' | '));
ok('each row prints the bucket its age falls in, by the 30/60/90 rule',
  aged.every(r => r.bucket.includes(LABEL[bucketOf(r.days)])), aged.map(r => `${r.days}→${r.bucket}`).join(' | '));
ok('a commission from yesterday is in the 0–30 bucket', aged.some(r => r.days === 2 && r.bucket.includes('0–30 days')));
ok('a commission from April is over 90 days and printed in red',
  aged.some(r => r.days === 193 && r.bucket.includes('Over 90 days'))
  && rows[rows.length - 2].children[1].classList.contains('text-red'), text(rows[rows.length - 2]?.children[1]));
ok('the rows total what the header says is due', Math.round(aged.reduce((t, r) => t + r.amount, 0) * 100) / 100 === 23820, `${aged.reduce((t, r) => t + r.amount, 0)}`);

/* ── the agent summary is the same population ───────────────────────────── */
const agents = [...$$('#cmSummary tbody tr')];
ok('the due is summarised by agent', agents.length === 3, `${agents.length}`);
ok('agents are ordered by what they are owed', agents.map(r => text(r.children[0])).join(' | ') === 'Zenith Oil Ghana Ltd | Meridian Travel & Tours | Ashanti Builders Co-op',
  agents.map(r => text(r.children[0])).join(' | '));
const summary = agents.map(r => ({
  agent: text(r.children[0]),
  due: num(r.children[8]),
  oldest: Number(text(r.children[9]).match(/(\d+) d/)?.[1] ?? -1),
  buckets: [num(r.children[4]), num(r.children[5]), num(r.children[6]), num(r.children[7])],
}));
ok('each agent’s due is its four buckets added', summary.every(a => Math.abs(a.buckets.reduce((t, v) => t + v, 0) - a.due) < 0.005),
  summary.map(a => `${a.agent}: ${a.buckets.join('+')} vs ${a.due}`).join(' | '));
const perAgentRows = new Map();
for (const r of aged) perAgentRows.set(r.agent, Math.round(((perAgentRows.get(r.agent) ?? 0) + r.amount) * 100) / 100);
ok('and it is the same money the rows below print, agent by agent',
  summary.every(a => Math.abs(a.due - (perAgentRows.get(a.agent) ?? 0)) < 0.005),
  summary.map(a => `${a.agent}: summary ${a.due} vs rows ${perAgentRows.get(a.agent)}`).join(' | '));
ok('the summary footer is the sum of the agents', num($('#cmSumDue')) === summary.reduce((t, a) => t + a.due, 0) && num($('#cmSumDue')) === 23820, text($('#cmSumDue')));
const footBuckets = ['#cmSumb0', '#cmSumb30', '#cmSumb60', '#cmSumb90'].map(s => num($(s)));
ok('and its buckets are the sums of the bucket columns', footBuckets.join(',') === summary.reduce((acc, a) => acc.map((v, i) => Math.round((v + a.buckets[i]) * 100) / 100), [0, 0, 0, 0]).join(','),
  `${footBuckets.join(',')}`);
ok('the buckets across the screen add to the total due', footBuckets.reduce((t, v) => t + v, 0) === 23820, `${footBuckets.reduce((t, v) => t + v, 0)}`);
ok('the summary footer also names the oldest', /193 d/.test(text($('#cmSummary tfoot tr'))), text($('#cmSummary tfoot tr')));
ok('every agent row can be settled in one click', agents.every(r => /#accounts-commissions\?company_id=\d+&status=due/.test(r.querySelector('a')?.getAttribute('href') ?? '')),
  agents.map(r => r.querySelector('a')?.getAttribute('href')).join(' | '));

/* ── the document control ───────────────────────────────────────────────── */
const tie = $('#commissionTie');
ok('the page checks its own documents', tie !== null && /Is every paid commission on a voucher\?/.test(text(tie)));
ok('it counts what is paid and what is documented', /4 paid · 4 on a voucher · 0 without one/.test(text(tie.querySelector('.card-head'))), text(tie.querySelector('.card-head')));
const tieStats = [...tie.querySelectorAll('.stat')].map(s => text(s));
ok('the money marked paid is stated', /Marked paid/.test(tieStats[0]) && /9,070\.00/.test(tieStats[0]) && /4 commissions settled/.test(tieStats[0]), tieStats[0]);
ok('the ones with a voucher are counted', /On a voucher/.test(tieStats[1]) && /^4/.test(tieStats[1]), tieStats[1]);
ok('and a nil shortfall is green and says so', /Without one/.test(tieStats[2]) && /^0/.test(tieStats[2]) && tie.querySelectorAll('.stat')[2].classList.contains('tone-green'));
const state = text($('#commissionTieState'));
ok('the page says the status column can be read as it stands', /names its voucher/.test(state) && /can be read as it stands/.test(state), state);
ok('it does not cry defect when there is none', !/no voucher/.test(state));

/* ── nothing is payable until an agent is chosen ────────────────────────── */
ok('no checkbox column is printed before an agent is chosen', rows.every(r => r.children.length === 11), `${rows[0]?.children.length}`);
ok('and the page says how to start a payment', /choose an agent above or click/.test(text(doc.body)));
ok('the settlement form is on the page, ready for the agent filter', $('#payForm')?.getAttribute('action')?.includes('accounts-commissions-pay'), $('#payForm')?.getAttribute('action'));

/* ── the drift build: a settlement with nothing behind it ───────────────── */
const drift = load('deploy/dev/previews/commissions-drift.html', 'https://preview.local/accounts/commissions?status=due&as_of=2026-11-07');
const dTie = drift.querySelector('#commissionTie');
ok('the shortfall is counted', /4 paid · 3 on a voucher · 1 without one/.test(text(dTie.querySelector('.card-head'))), text(dTie.querySelector('.card-head')));
const dStats = [...dTie.querySelectorAll('.stat')].map(s => text(s));
ok('and marked red, not merely stated', dTie.querySelectorAll('.stat')[2].classList.contains('tone-red') === true && /^1/.test(dStats[2]), dStats[2]);
const dState = text(drift.querySelector('#commissionTieState'));
ok('the page names the agent and the money', /Ashanti Builders Co-op 1,120\.00/.test(dState), dState);
ok('it says what that means', /nothing here records it/.test(dState), dState);
ok('and where to reconcile it', /against the voucher register/.test(dState), dState);
ok('the ageing is unchanged by the missing document',
  num(drift.querySelector('#cmSumDue')) === 23820 && /23,820\.00/.test(text(drift.querySelectorAll('.stats')[0])), text(drift.querySelector('#cmSumDue')));

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
  console.error(`commissions check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`commissions check: ${passed}/${passed} passed`);
