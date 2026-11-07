#!/usr/bin/env node
/*
 * Stock takes, read as the three things a count sheet has to prove.
 *
 * The page listed count sheets and showed a variance column once a sheet had been posted. A count is worth nothing
 * until somebody posts it — and then only for the lines somebody counted — so v9.21 puts the three readings on the page
 * and this check holds each to its own record:
 *
 *   1. the variance — 102 lines posted, 13 of them adjusted, 3,660.00 short against 870.00 over, a net 2,790.00 the
 *      ledger has been told about, with the largest single line named (cooking gas, 4,560.00);
 *   2. the coverage — 96 of 102 lines counted, so six were posted with the book quantity untouched, and the thinnest
 *      sheet (ST-2026-0118, 91.7 %) is named rather than averaged away;
 *   3. the posting — two drafts, one of them four days old, holding 1,240.00 the book has not been told about;
 *   4. the count sheet list — the columns and the per-take summary behind them;
 *   5. the drift build — the engineering sheet posted, everything else on the shelf untouched.
 *
 * Renders deploy/dev/previews/stocktakes.html and stocktakes-drift.html in jsdom against
 * deploy/dev/preview/stocktakes-sample.mjs.
 *
 * Run: node deploy/dev/audit/stocktakes_preview_check.js
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

const doc = load('deploy/dev/previews/stocktakes.html', 'https://preview.local/inventory/stock-takes');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the header ─────────────────────────────────────────────────────────── */
const head = [...$$('.stats.wide')[0].children].map(s => text(s));
ok('the header carries five figures, one per reading plus the sheets themselves', head.length === 5, `${head.length}`);
ok('the sheets are counted with what became of them', /^6/.test(head[0]) && /3 posted · 2 draft · 1 cancelled/.test(head[0]), head[0]);
ok('the variance the ledger has been told about is on the header', /GH₵ -2,790\.00/.test(head[1]) && /13 lines adjusted/.test(head[1]), head[1]);
ok('and the share of lines actually counted sits beside it', /94\.1 %/.test(head[2]) && /96 of 102 lines/.test(head[2]), head[2]);
ok('the lines nobody counted are not buried in a percentage', /^6/.test(head[3]) && /Never counted/.test(head[3])
  && $$('.stats.wide')[0].children[3].classList.contains('tone-amber'), head[3]);
ok('and what is still out of the books is stated in money and age',
  /GH₵ 1,240\.00/.test(head[4]) && /2 drafts · oldest 4 days/.test(head[4]), head[4]);

/* ── the variance ───────────────────────────────────────────────────────── */
const variance = $('#stVariance');
ok('the page asks what the count found', /What did the count find\?/.test(text(variance)));
ok('the head separates the shortage from the surplus before it nets them',
  /3 posted takes · GH₵ 3,660\.00 short · GH₵ 870\.00 over · GH₵ -2,790\.00 net/.test(text(variance.querySelector('.card-head'))),
  text(variance.querySelector('.card-head')));
const vStats = [...variance.querySelectorAll('.stat')].map(s => text(s));
ok('the net variance is a figure of its own', /Net variance/.test(vStats[0]) && /2,790\.00/.test(vStats[0])
  && variance.querySelectorAll('.stat')[0].classList.contains('tone-red'), vStats[0]);
ok('the shortage is stated as money the shelf did not have', /Short/.test(vStats[1]) && /3,660\.00/.test(vStats[1])
  && /the book had and the shelf did not/.test(vStats[1]), vStats[1]);
ok('and the surplus as stock the book had already written off', /Over/.test(vStats[2]) && /870\.00/.test(vStats[2]), vStats[2]);
ok('every adjustment written by the posting is counted', /Lines adjusted/.test(vStats[3]) && /^13/.test(vStats[3])
  && /wrote a movement into the stock ledger/.test(vStats[3]), vStats[3]);
ok('the verdict names the largest single line with its quantity, its cost and its value',
  /The largest single one is Cooking gas on ST-2026-0118 — counted 2 cylinders against 14 on the book, 12 cylinders short at GH₵ 380\.00 = GH₵ 4,560\.00\./.test(text($('#stVarianceState'))),
  text($('#stVarianceState')));
ok('and says what a shortage actually is, rather than leaving it as a number',
  /a shortage is either a theft, a wastage nobody wrote down, or a receipt that never arrived/.test(text($('#stVarianceState'))));
ok('the verdict is red while the net is a shortage', $('#stVarianceState').classList.contains('text-red'));
const vRows = [...$$('#varianceTable tbody tr')].map(r => ({ take: text(r.children[0]), store: text(r.children[1]),
  counted: text(r.children[2]), adjusted: Number(text(r.children[3])), short: num(r.children[4]), over: num(r.children[5]),
  net: num(r.children[6]), netAttr: Number(r.getAttribute('data-net')) }));
ok('every posted take is on the list, the worst first', vRows.length === 3
  && vRows.map(r => r.take).join(',') === 'ST-2026-0118,ST-2026-0117,ST-2026-0116', vRows.map(r => r.take).join(','));
ok('short plus over is the net on every row', vRows.every(r => round2(r.over - Math.abs(r.short)) === r.net),
  vRows.map(r => `${r.short}+${r.over}=${r.net}`).join(' | '));
ok('the rows add to the card’s net and to the header’s figure',
  round2(vRows.reduce((t, r) => t + r.net, 0)) === -2790);
ok('the row data carries the same net the column shows', vRows.every(r => r.netAttr === r.net));
ok('a take that found more than it lost is not painted red', vRows[2].net === 410
  && !$$('#varianceTable tbody tr')[2].children[6].classList.contains('text-red'));
ok('each row shows how much of the sheet was counted beside the variance',
  vRows[0].counted === '44/48' && vRows[1].counted === '30/32', vRows.map(r => r.counted).join(' '));

/* ── the coverage ───────────────────────────────────────────────────────── */
const coverage = $('#stCoverage');
ok('the page asks how much of the shelf was counted', /How much of the shelf was actually counted\?/.test(text(coverage)));
ok('the head states the lines and the ones left alone',
  /96 of 102 lines on the posted sheets · 6 left alone/.test(text(coverage.querySelector('.card-head'))), text(coverage.querySelector('.card-head')));
const cStats = [...coverage.querySelectorAll('.stat')].map(s => text(s));
ok('the lines on the sheets are counted with the sheets', /Lines on the sheets/.test(cStats[0]) && /^102/.test(cStats[0]), cStats[0]);
ok('the counted ones are stated as a share as well as a number', /Counted/.test(cStats[1]) && /^96/.test(cStats[1])
  && /94\.1 % of them have a figure somebody wrote down/.test(cStats[1]), cStats[1]);
ok('the ones nobody counted are red, not merely mentioned', /Never counted/.test(cStats[2]) && /^6/.test(cStats[2])
  && coverage.querySelectorAll('.stat')[2].classList.contains('tone-red'), cStats[2]);
ok('and the thinnest sheet is named with its line count', /Lowest coverage/.test(cStats[3]) && /91\.7 %/.test(cStats[3])
  && /ST-2026-0118 — 44 of 48 lines/.test(cStats[3]), cStats[3]);
ok('the verdict names the sheet that was posted on a partial count',
  /ST-2026-0118 was posted with 44 of its 48 lines counted, 91\.7 %/.test(text($('#stCoverageState'))), text($('#stCoverageState')));
ok('and says the rest kept whatever the book said', /the rest kept whatever the book said/.test(text($('#stCoverageState'))));
ok('the verdict adds up what the posted sheets cover together', /Together the posted sheets reach 94\.1 % of the lines they carried/.test(text($('#stCoverageState'))));
const cRows = [...$$('#coverageTable tbody tr')].map(r => ({ take: text(r.children[0]), lines: Number(text(r.children[2])),
  counted: Number(text(r.children[3])), pct: Number((text(r.children[4]).match(/[\d.]+/) ?? [0])[0]), attr: Number(r.getAttribute('data-coverage')) }));
ok('the sheets are listed thinnest first', cRows.length === 3 && cRows.map(r => r.pct).join(',') === '91.7,93.8,100',
  cRows.map(r => r.pct).join(','));
ok('the percentage on each row is the counted lines over the lines on the sheet',
  cRows.every(r => Math.round(r.counted / r.lines * 1000) / 10 === r.pct), cRows.map(r => `${r.counted}/${r.lines}`).join(' '));
ok('the rows agree with the card’s totals', cRows.reduce((t, r) => t + r.lines, 0) === 102
  && cRows.reduce((t, r) => t + r.counted, 0) === 96);
ok('the rows under ninety-five per cent are the ones marked', cRows.filter(r => r.pct < 95).length === 2
  && $$('#coverageTable tbody tr').filter(r => r.children[4].classList.contains('text-red')).length === 2);

/* ── the posting ────────────────────────────────────────────────────────── */
const posting = $('#stPosting');
ok('the page asks what is still out of the ledger', /What is still out of the ledger\?/.test(text(posting)));
ok('the head counts the drafts, the money and the age',
  /2 drafts · GH₵ 1,240\.00 counted and not posted · oldest 4 days/.test(text(posting.querySelector('.card-head'))), text(posting.querySelector('.card-head')));
const pStats = [...posting.querySelectorAll('.stat')].map(s => text(s));
ok('the drafts are counted with the lines already filled in', /Draft sheets/.test(pStats[0]) && /^2/.test(pStats[0])
  && /18 of 74 lines/.test(pStats[0]), pStats[0]);
ok('the variance held out of the books is money, not a count', /Held out of the books/.test(pStats[1]) && /1,240\.00/.test(pStats[1]), pStats[1]);
ok('the oldest draft is aged and named', /Oldest draft/.test(pStats[2]) && /^4 d/.test(pStats[2])
  && /ST-2026-0119 counted 03 Nov 2026/.test(pStats[2]), pStats[2]);
ok('and the adjustments the posting did write are counted', /Adjustments posted/.test(pStats[3]) && /^13/.test(pStats[3]), pStats[3]);
ok('the verdict names the draft, its date and what the book is missing',
  /ST-2026-0119 was counted on 03 Nov 2026 and has been waiting 4 days to be posted, with 18 of its 26 lines filled in\./.test(text($('#stPostingState'))),
  text($('#stPostingState')));
ok('and says the shelf and the book disagree until it is posted',
  /Until it is posted the shelf and the book disagree by GH₵ 1,240\.00/.test(text($('#stPostingState'))));
ok('the verdict points at the consequence, which is the next order being wrong',
  /the re-order suggestions are still being made on the book/.test(text($('#stPostingState'))));
const postRows = [...$$('#postingTable tbody tr')].map(r => ({ take: text(r.children[0]), store: text(r.children[1]),
  date: text(r.children[2]), days: Number(text(r.children[3])), counted: text(r.children[4]), value: num(r.children[5]),
  age: Number(r.getAttribute('data-age')) }));
ok('every draft is listed, oldest first', postRows.length === 2
  && postRows.map(r => r.take).join(',') === 'ST-2026-0119,ST-2026-0120', postRows.map(r => r.take).join(','));
ok('the ages are the days since the count date', postRows[0].age === 4 && postRows[1].age === 0, postRows.map(r => r.age).join(','));
ok('a sheet with nothing counted yet shows zero rather than a blank', postRows[1].counted === '0/48' && postRows[1].value === 0);

/* ── the count sheets themselves ────────────────────────────────────────── */
const cols = [...$$('#takeTable thead th')].map(th => text(th));
ok('the sheet list has ten columns, coverage and adjustments included',
  cols.join('|') === 'No.|Store|Count date|Items|Counted|Coverage|Adjusted|Variance value|Status|By', cols.join('|'));
const tRows = [...$$('#takeTable tbody tr')].map(r => ({ take: text(r.children[0]), store: text(r.children[1]),
  items: Number(text(r.children[3])), counted: Number(text(r.children[4])), pct: text(r.children[5]),
  adjusted: text(r.children[6]), value: text(r.children[7]), status: r.getAttribute('data-status'),
  coverage: r.getAttribute('data-coverage') }));
ok('every sheet is on the list, newest first', tRows.length === 6 && tRows[0].take === 'ST-2026-0120' && tRows[5].take === 'ST-2026-0115',
  tRows.map(r => r.take).join(','));
ok('the items column is the lines on the sheet, not the lines counted',
  tRows[0].items === 48 && tRows[0].counted === 0, `${tRows[0].items}/${tRows[0].counted}`);
ok('a variance value only appears once a sheet has been posted',
  tRows.filter(r => r.status === 'posted').every(r => r.value !== '—')
  && tRows.filter(r => r.status !== 'posted').every(r => r.value === '—'),
  tRows.map(r => `${r.status}:${r.value}`).join(' '));
ok('a posted sheet with thin coverage is marked on the row',
  tRows[2].status === 'posted' && tRows[2].take === 'ST-2026-0118'
  && $$('#takeTable tbody tr')[2].children[5].classList.contains('text-red'));
ok('the coverage on the row is the same figure the coverage card used',
  tRows[2].pct.startsWith('91.7') && tRows[5].coverage === '0');
ok('a cancelled sheet keeps its lines and shows no coverage work',
  tRows[5].status === 'cancelled' && tRows[5].counted === 0 && tRows[5].adjusted === '—');

/* ── the loop behind the count ──────────────────────────────────────────── */
ok('the page says what a count closes and what it is worth',
  /A count sheet closes the stock loop the way a goods receipt opens it/.test(text($('#stChain')))
  && /It is worth nothing until it is posted/.test(text($('#stChain'))), text($('#stChain')));
ok('and links the receipt, the issue and the relation between them',
  /receipt/.test(text($('#stChain'))) && /issue/.test(text($('#stChain'))));

/* ── the drift build: the draft posted, the shelf untouched ─────────────── */
const drift = load('deploy/dev/previews/stocktakes-drift.html', 'https://preview.local/inventory/stock-takes');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats.wide')[0].children].map(s => dText(s));
ok('the drift shows the same six sheets with one more posted', /3 posted · 2 draft · 1 cancelled/.test(head[0])
  && /4 posted · 1 draft · 1 cancelled/.test(dHead[0]), dHead[0]);
ok('the variance moves into the books', /GH₵ -4,030\.00/.test(dHead[1]) && /17 lines adjusted/.test(dHead[1]), dHead[1]);
ok('and the coverage falls, because the posted sheet was thin',
  /89\.1 %/.test(dHead[2]) && /114 of 128 lines/.test(dHead[2]), dHead[2]);
ok('fourteen lines were never counted now, not six', /^14/.test(dHead[3]), dHead[3]);
ok('and there is nothing held out of the books any more', /GH₵ 0\.00/.test(dHead[4]) && /1 draft · oldest 0 days/.test(dHead[4]), dHead[4]);
const dVariance = [...drift.querySelectorAll('#varianceTable tbody tr')].map(r => dText(r.children[0]));
ok('the posted engineering sheet joins the variance list in its place',
  dVariance.length === 4 && dVariance.join(',') === 'ST-2026-0118,ST-2026-0119,ST-2026-0117,ST-2026-0116', dVariance.join(','));
ok('the short side grows by what the sheet found',
  /GH₵ 5,060\.00 short · GH₵ 1,030\.00 over · GH₵ -4,030\.00 net/.test(dText(drift.querySelector('#stVariance .card-head'))),
  dText(drift.querySelector('#stVariance .card-head')));
ok('the thinnest sheet is now the one that was just posted',
  /ST-2026-0119 was posted with 18 of its 26 lines counted, 69\.2 %/.test(dText(drift.querySelector('#stCoverageState'))),
  dText(drift.querySelector('#stCoverageState')));
ok('the posting card is left with the empty sheet only',
  dText(drift.querySelectorAll('#stPosting .stat')[0]).includes('1') && /0 of 48 lines/.test(dText(drift.querySelectorAll('#stPosting .stat')[0])),
  dText(drift.querySelectorAll('#stPosting .stat')[0]));
ok('the engineering sheet now carries its variance on the list',
  /-1,240\.00/.test(dText([...drift.querySelectorAll('#takeTable tbody tr')][1]))
  && [...drift.querySelectorAll('#takeTable tbody tr')][1].getAttribute('data-status') === 'posted',
  dText([...drift.querySelectorAll('#takeTable tbody tr')][1]));
ok('the items and counted columns do not move — posting does not change what was counted',
  [...drift.querySelectorAll('#takeTable tbody tr')].every((r, i) => dText(r.children[3]) === text($$('#takeTable tbody tr')[i].children[3])
    && dText(r.children[4]) === text($$('#takeTable tbody tr')[i].children[4])));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|endforelse|php|endphp|unless|endunless|error|enderror)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder or leaked attribute`, !/\{\{|\}\}|:sub=|:tone=/.test(clean));
  ok(`${name} holds no unpluralised shorthand`, !/day\(s\)|line\(s\)|sheet\(s\)|take\(s\)/.test(clean));
}

if (failures.length) {
  console.error(`stocktakes check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`stocktakes check: ${passed}/${passed} passed`);
