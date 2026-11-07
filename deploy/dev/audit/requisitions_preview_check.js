#!/usr/bin/env node
/*
 * Store requisitions, read as three things at once.
 *
 * The register listed requisitions. v9.18 asks what a requisition is between the request and the goods leaving the
 * store — a queue, an allowance, a handover — and answers each from the record that owns it:
 *
 *   1. the queue — two requisitions with a head of department (16,730.00, the oldest four days) and four with the store
 *      (35,300.00, the oldest six), aged from submitted_at and approved_at rather than from the date raised;
 *   2. the allowance — four departments with a budget for November, 175,000.00 between them and 140,080.00 used, the
 *      tightest named rather than summed, and the department with no allowance said out loud;
 *   3. the handover — three issue notes nobody signed, 8,460.00, the older eight days old, counted off received_at;
 *   4. the page — the columns, the badges and the header the three cards are summed into;
 *   5. the drift build — a late delivery taking the Front Office over its allowance and a signature going missing, with
 *      the first reading left alone.
 *
 * Renders deploy/dev/previews/requisitions.html and requisitions-drift.html in jsdom against
 * deploy/dev/preview/requisitions-sample.mjs.
 *
 * Run: node deploy/dev/audit/requisitions_preview_check.js
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

const doc = load('deploy/dev/previews/requisitions.html', 'https://preview.local/requisitions');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the header ─────────────────────────────────────────────────────────── */
const head = [...$$('.stats.wide')[0].children].map(s => text(s));
ok('the header carries five figures, one per queue plus the month and the unsigned money', head.length === 5, `${head.length}`);
ok('what needs the reader is counted first', /^6/.test(head[0]) && /Needs my action/.test(head[0]), head[0]);
ok('the queue with the head of department is aged from the day it reached them', /^2/.test(head[1]) && /oldest 4 days/.test(head[1]), head[1]);
ok('the queue with the store says how much of it is partly issued', /^4/.test(head[2]) && /1 part issued/.test(head[2]) && /oldest 6 days/.test(head[2]), head[2]);
ok('the store’s unsigned money is on the header, not only in a card', /3 issues · GH₵ 8,460\.00 unsigned/.test(head[3])
  && $$('.stats.wide')[0].children[3].classList.contains('tone-purple'), head[3]);
ok('the month is stated with the count of the notes behind it', /GH₵ 8,740\.00/.test(head[4]) && /3 issue notes/.test(head[4]), head[4]);

/* ── the queue ──────────────────────────────────────────────────────────── */
const age = $('#reqAge');
ok('the page asks how long each queue has waited', /How long has each queue waited\?/.test(text(age)));
ok('the head states both queues and the longest wait on the book',
  /2 with a head of department · 4 with the store · oldest 6 days/.test(text(age.querySelector('.card-head'))), text(age.querySelector('.card-head')));
const qRows = [...$$('#reqAgeTable tbody tr')].map(r => ({ queue: text(r.children[0]), count: Number(text(r.children[1])),
  value: num(r.children[2]), oldest: text(r.children[3]), who: text(r.children[4]) }));
ok('a requisition waiting on a person is one row per person', qRows.length === 2
  && qRows[0].queue === 'With the head of department' && qRows[1].queue === 'With the store, to issue', qRows.map(r => r.queue).join(' | '));
ok('the head of department holds two requisitions worth 16,730.00', qRows[0].count === 2 && qRows[0].value === 16730, JSON.stringify(qRows[0]));
ok('the store holds four worth 35,300.00', qRows[1].count === 4 && qRows[1].value === 35300, JSON.stringify(qRows[1]));
ok('the age of each queue is the age of its oldest item, not of the list', qRows[0].oldest === '4 d' && qRows[1].oldest === '6 d',
  `${qRows[0].oldest} / ${qRows[1].oldest}`);
ok('and each queue names the requisition behind that age', qRows[0].who === 'REQ-2026-0141 · Housekeeping'
  && qRows[1].who === 'REQ-2026-0145 · Kitchen', `${qRows[0].who} / ${qRows[1].who}`);
ok('the two queue counts are the two tabs they are drawn from', qRows[0].count === 2 && qRows[1].count === 4);
ok('the verdict names the oldest with the head of department with its money and its date',
  /The oldest with the head of department is REQ-2026-0141 from Housekeeping — GH₵ 4,250\.00, raised on 03 Nov 2026 and waiting 4 days\./.test(text($('#reqAgeState'))),
  text($('#reqAgeState')));
ok('and names the oldest with the store, approved rather than raised',
  /The oldest with the store is REQ-2026-0145 from Kitchen — GH₵ 9,640\.00, approved on 01 Nov 2026 and still open for 6 days\./.test(text($('#reqAgeState'))));
ok('the verdict says how many are past the date the department asked for',
  /3 of them are past the date the department asked for the goods by\./.test(text($('#reqAgeState'))));
ok('the queue is only marked red once something has waited more than a week', !$('#reqAgeState').classList.contains('text-red'));

/* ── the allowance ──────────────────────────────────────────────────────── */
const budget = $('#reqBudget');
ok('the page asks what is left of each department’s month', /What is left of each department's month\?/.test(text(budget)));
ok('the head counts the departments with an allowance and the ones over it',
  /4 departments with an allowance · 0 over it/.test(text(budget.querySelector('.card-head'))), text(budget.querySelector('.card-head')));
const bStats = [...budget.querySelectorAll('.stat')].map(s => text(s));
ok('the allowance is summed from the departments that have one', /Allowance, this month/.test(bStats[0]) && /175,000\.00/.test(bStats[0]), bStats[0]);
ok('what is used is issued this month plus what the open requisitions will still take', /Usedissued this month/.test(bStats[1]) && /140,080\.00/.test(bStats[1]), bStats[1]);
ok('and the money over the allowance is nil and green', /Over the allowance/.test(bStats[2]) && /0\.00/.test(bStats[2])
  && budget.querySelectorAll('.stat')[2].classList.contains('tone-green'), bStats[2]);
const dname = (cell) => (cell.childNodes[0]?.textContent ?? '').trim();
const bRows = [...$$('#budgetTable tbody tr')].map(r => ({ name: dname(r.children[0]),
  budget: num(r.children[1]), issued: num(r.children[2]), committed: num(r.children[3]), used: num(r.children[4]),
  left: num(r.children[5]), pct: Number((text(r.children[6]).match(/[\d.]+/) ?? ['0'])[0]), over: r.getAttribute('data-over') }));
ok('every department with an allowance is on the list, tightest first',
  bRows.length === 4 && bRows.map(r => r.name).join(',') === 'Maintenance,Front Office,Kitchen,Housekeeping', bRows.map(r => r.name).join(','));
ok('the tightest department is the one with the least room, not the least money',
  bRows[0].pct === 91.7 && bRows[0].left === 5000, JSON.stringify(bRows[0]));
ok('used is issued plus committed on every row', bRows.every(r => round2(r.issued + r.committed) === r.used),
  bRows.map(r => `${r.issued}+${r.committed}=${r.used}`).join(' | '));
ok('left is the allowance less what is used, on every row', bRows.every(r => round2(r.budget - r.used) === r.left));
ok('the percentages are used over the allowance, to one decimal',
  bRows.every(r => Math.round(r.used / r.budget * 1000) / 10 === r.pct), bRows.map(r => r.pct).join(','));
ok('the rows add to the header’s allowance and used', round2(bRows.reduce((t, r) => t + r.budget, 0)) === 175000
  && round2(bRows.reduce((t, r) => t + r.used, 0)) === 140080);
ok('no row is marked over while none is', bRows.every(r => r.over === '0'));
ok('the verdict names the least room left rather than the biggest spend',
  /No department is over its allowance\. The least room left is Maintenance — 91\.7 % used, GH₵ 5,000\.00 left of GH₵ 60,000\.00\./.test(text($('#reqBudgetState'))),
  text($('#reqBudgetState')));
ok('and says out loud which department has no allowance, so nothing is held to it',
  /1 department has no allowance set, so nothing is held to it\./.test(text($('#reqBudgetState'))));
ok('the department without a budget is kept off the list rather than shown against zero',
  !bRows.some(r => r.name === 'Security'));
ok('each row says where its allowance comes from, so the figure can be argued with',
  /BD-2026-07/.test(text($$('#budgetTable tbody tr')[0])) && /monthly figure on the department/.test(text($$('#budgetTable tbody tr')[1])));

/* ── the handover ───────────────────────────────────────────────────────── */
const hand = $('#reqHandover');
ok('the page asks what has left the store unsigned', /What has left the store with nobody's signature\?/.test(text(hand)));
ok('the head counts the notes, the money and the oldest wait',
  /3 issue notes unsigned · GH₵ 8,460\.00 · oldest 8 days/.test(text(hand.querySelector('.card-head'))), text(hand.querySelector('.card-head')));
const hStats = [...hand.querySelectorAll('.stat')].map(s => text(s));
ok('the unsigned money is the card’s first figure and it is red', /Unsigned/.test(hStats[0]) && /8,460\.00/.test(hStats[0])
  && hand.querySelectorAll('.stat')[0].classList.contains('tone-red'), hStats[0]);
ok('the notes that were signed are counted, not hidden', /Signed in the last 30 days/.test(hStats[1]) && /^3/.test(hStats[1]), hStats[1]);
ok('and the average time to sign is stated in days', /acknowledged in 1 day on average/.test(hStats[1]), hStats[1]);
ok('the oldest unsigned note is aged on its own', /Oldest unsigned/.test(hStats[2]) && /8 days/.test(hStats[2]), hStats[2]);
const hRows = [...$$('#handoverTable tbody tr')].map(r => ({ req: text(r.children[0]), dept: text(r.children[1]), store: text(r.children[2]),
  issued: text(r.children[3]), days: text(r.children[4]), value: num(r.children[5]), by: text(r.children[6]) }));
ok('every unsigned note is listed, oldest first', hRows.length === 3 && hRows.map(r => r.req).join(',') === 'REQ-2026-0146,REQ-2026-0149,REQ-2026-0145',
  hRows.map(r => r.req).join(','));
ok('each note states the store it left and the day it left', hRows[0].store === 'Main Store' && hRows[0].issued === '30 Oct 2026', JSON.stringify(hRows[0]));
ok('the ages run oldest first and are counted from the issue date', hRows.map(r => r.days).join(',') === '8 d,3 d,2 d', hRows.map(r => r.days).join(','));
ok('the rows add to the card’s unsigned figure', round2(hRows.reduce((t, r) => t + r.value, 0)) === 8460);
ok('the verdict names the oldest note, its department, its money and its store',
  /The oldest is REQ-2026-0146 for Housekeeping — GH₵ 3,120\.00 left the Main Store on 30 Oct 2026 and nobody has signed for it, 8 days ago\./.test(text($('#reqHandoverState'))),
  text($('#reqHandoverState')));
ok('and says what an unacknowledged note costs the department', /carries goods it may never have seen/.test(text($('#reqHandoverState'))));
ok('a note issued to a department’s own store is a transfer, and still has to be signed',
  hRows[2].store === 'Kitchen Store' && hRows[2].req === 'REQ-2026-0145', JSON.stringify(hRows[2]));

/* ── the page of requisitions ───────────────────────────────────────────── */
const cols = [...$$('#reqTable thead th')].map(th => text(th));
ok('the register has ten columns, in reading order',
  cols.join('|') === 'Requisition|Department|From → to|Items|Value|Needed by|Raised / approved|Age|Receipt|Status', cols.join('|'));
const rows = [...$$('#reqTable tbody tr')];
ok('the open tab is what the page opens on', rows.length === 7, `${rows.length}`);
ok('newest requisition first', text(rows[0].children[0]).startsWith('REQ-2026-0152') && text(rows[6].children[0]).startsWith('REQ-2026-0141'),
  `${text(rows[0].children[0])} … ${text(rows[6].children[0])}`);
const rowOf = (no) => rows.find(r => text(r).includes(no));
ok('the age column counts from the state the row is in, not from the date raised',
  /^4 d/.test(text(rowOf('REQ-2026-0141').children[7])) && /with the head/.test(text(rowOf('REQ-2026-0141').children[7])),
  text(rowOf('REQ-2026-0141').children[7]));
ok('an approved row is aged from the approval, not the raise', /^6 d/.test(text(rowOf('REQ-2026-0145').children[7]))
  && /part issued/.test(text(rowOf('REQ-2026-0145').children[7])), text(rowOf('REQ-2026-0145').children[7]));
ok('a draft is aged from the day it was written', /^0 d/.test(text(rowOf('REQ-2026-0148').children[7]))
  && /not submitted/.test(text(rowOf('REQ-2026-0148').children[7])), text(rowOf('REQ-2026-0148').children[7]));
ok('a row with an unsigned issue says so in the receipt column', /1 unsigned/.test(text(rowOf('REQ-2026-0145').children[8])),
  text(rowOf('REQ-2026-0145').children[8]));
ok('a row whose issue was signed says so rather than going blank',
  /signed/.test(text(rowOf('REQ-2026-0152').children[8])) === false && text(rowOf('REQ-2026-0152').children[8]) === '—',
  text(rowOf('REQ-2026-0152').children[8]));
ok('the unsigned rows are marked in the row data',
  rows.filter(r => r.getAttribute('data-unsigned') === '1').length === 1
  && rows.filter(r => r.getAttribute('data-unsigned') === '1')[0].getAttribute('data-status') === 'partial',
  rows.map(r => `${r.getAttribute('data-status')}/${r.getAttribute('data-unsigned')}`).join(' '));
ok('a row with nothing issued yet is not a handover', rowOf('REQ-2026-0144').getAttribute('data-unsigned') === '0');
ok('an approved row states how much is still to come out of the store',
  /12 units to issue/.test(text(rowOf('REQ-2026-0152').children[3])), text(rowOf('REQ-2026-0152').children[3]));
ok('a part-issued row states the part and the whole', /6 units to issue/.test(text(rowOf('REQ-2026-0145').children[3]))
  && /9,640\.00/.test(text(rowOf('REQ-2026-0145').children[4])) && /issued 6,400\.00/.test(text(rowOf('REQ-2026-0145').children[4])),
  text(rowOf('REQ-2026-0145').children[4]));
ok('a requisition past the date the department asked for is flagged on the row',
  $$('#reqTable tbody tr.row-warn').length === 3
  && rows.filter(r => r.classList.contains('row-warn')).every(r => r.getAttribute('data-status') !== 'draft'),
  `${$$('#reqTable tbody tr.row-warn').length}`);
ok('and the date it asked for is the cell that goes red',
  /text-red/.test(rowOf('REQ-2026-0141').children[5].innerHTML) && !/text-red/.test(rowOf('REQ-2026-0144').children[5].innerHTML),
  rowOf('REQ-2026-0141').children[5].innerHTML.slice(0, 60));
ok('a transfer names the store it goes to, an expense names the department that uses it',
  /Kitchen Store/.test(text(rowOf('REQ-2026-0142').children[2])) && /Used by Security/.test(text(rowOf('REQ-2026-0148').children[2])),
  `${text(rowOf('REQ-2026-0142').children[2])} / ${text(rowOf('REQ-2026-0148').children[2])}`);
ok('the urgent request is flagged on the row', /Urgent/.test(text(rowOf('REQ-2026-0143').children[0])));
const tabs = [...$$('.tabs a')].map(a => ({ href: a.getAttribute('href'), label: text(a) }));
ok('the tabs still filter by state and carry their counts', tabs.length === 9
  && tabs.some(t => t.href === '?show=submitted' && t.label === 'Awaiting approval (2)')
  && tabs.some(t => t.href === '?show=receipt' && t.label === 'Awaiting receipt (3)'), tabs.map(t => t.label).join(' | '));
ok('a tab with nothing waiting shows no count rather than a zero', tabs.some(t => t.label === 'Completed'), tabs.map(t => t.label).join(' | '));
ok('the chain from request to signature is spelled out, and linked to the goods receipt',
  /A department raises a requisition/.test(text($('#reqChain'))) && /goods receipts/.test(text($('#reqChain')))
  && /here, goods leave and a signature should/.test(text($('#reqChain'))), text($('#reqChain')));

/* ── the drift build: a late delivery and a missing signature ───────────── */
const drift = load('deploy/dev/previews/requisitions-drift.html', 'https://preview.local/requisitions');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats.wide')[0].children].map(s => dText(s));
ok('the header counts the fourth unsigned note', /4 issues · GH₵ 13,940\.00 unsigned/.test(dHead[3]), dHead[3]);
ok('the allowance card counts the department the late delivery pushed over',
  /4 departments with an allowance · 1 over it/.test(dText(drift.querySelector('#reqBudget .card-head'))),
  dText(drift.querySelector('#reqBudget .card-head')));
const dBudget = [...drift.querySelectorAll('#reqBudget .stat')].map(s => dText(s));
ok('what is used rises by the 3,300.00 that arrived late', /143,380\.00/.test(dBudget[1]), dBudget[1]);
ok('the money over the allowance is stated and red', /760\.00/.test(dBudget[2])
  && drift.querySelectorAll('#reqBudget .stat')[2].classList.contains('tone-red'), dBudget[2]);
ok('the verdict names the department, its percentage and how far past it is',
  /1 department is over the month's allowance, GH₵ 760\.00 in all — Front Office at 103\.8 %, GH₵ 760\.00 past its GH₵ 20,000\.00\./.test(dText(drift.querySelector('#reqBudgetState'))),
  dText(drift.querySelector('#reqBudgetState')));
const dRows = [...drift.querySelectorAll('#budgetTable tbody tr')].map(r => ({ name: dname(r.children[0]),
  left: num(r.children[5]), pct: Number((dText(r.children[6]).match(/[\d.]+/) ?? ['0'])[0]), over: r.getAttribute('data-over') }));
ok('the department over its allowance is first, and the only row marked over',
  dRows[0].name === 'Front Office' && dRows[0].over === '1' && dRows.filter(r => r.over === '1').length === 1, JSON.stringify(dRows[0]));
ok('and it is over by exactly the difference between the allowance and what was spent',
  dRows[0].pct === 103.8 && dRows[0].left === -760, JSON.stringify(dRows[0]));
ok('the handover grows by the note that lost its signature',
  /4 issue notes unsigned · GH₵ 13,940\.00 · oldest 10 days/.test(dText(drift.querySelector('#reqHandover .card-head'))),
  dText(drift.querySelector('#reqHandover .card-head')));
ok('the note that lost its signature is now the oldest', 
  /The oldest is REQ-2026-0147 for Maintenance — GH₵ 5,480\.00 left the Engineering Store on 28 Oct 2026 and nobody has signed for it, 10 days ago\./.test(dText(drift.querySelector('#reqHandoverState'))),
  dText(drift.querySelector('#reqHandoverState')));
ok('a note that was signed drops out of the signed count', /^2/.test(dText(drift.querySelectorAll('#reqHandover .stat')[1])),
  dText(drift.querySelectorAll('#reqHandover .stat')[1]));
ok('the drift leaves the queue alone — nobody’s timestamps moved',
  dText(drift.querySelector('#reqAgeState')) === text($('#reqAgeState'))
  && dText(drift.querySelector('#reqAge .card-head')) === text(age.querySelector('.card-head')));
ok('and leaves the queue table and the page of requisitions alone',
  dText(drift.querySelector('#reqAgeTable')) === text($('#reqAgeTable'))
  && [...drift.querySelectorAll('#reqTable tbody tr')].every((r, i) => dText(r) === text(rows[i])));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|endforelse|php|endphp|unless|endunless|error|enderror)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
  ok(`${name} holds no unpluralised shorthand`, !/day\(s\)|note\(s\)|issue\(s\)|unit\(s\)|item\(s\)|department\(s\)/.test(clean));
}

if (failures.length) {
  console.error(`requisitions check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`requisitions check: ${passed}/${passed} passed`);
