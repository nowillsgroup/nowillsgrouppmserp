#!/usr/bin/env node
/*
 * One count sheet, read before anything is posted.
 *
 * The sheet was a grid of inputs with a variance column, and the one distinction that decides what posting does was
 * invisible: a line left blank is posted with the book quantity untouched, a line counted at zero writes the whole
 * quantity off, and in a grid they look the same. v9.24 reads the sheet three ways and this check holds each to its own
 * line list:
 *
 *   1. the progress — 13 of 18 lines counted (72.2 %), kept by category because a count is done aisle by aisle;
 *   2. what it has found — 2,078.00 short against 25.00 over, a net 2,053.00 on the counted lines, the largest the
 *      tilapia counted at zero: 18 kg against 18 on the book, 810.00;
 *   3. what posting will do — ten movements for 2,053.00, five lines left on the book, and the 16,154.00 that
 *      "treat blank counts as zero" would write off in one click, the bed sheets 8,550.00 of it;
 *   4. the sheet itself — eighteen rows, an input each, blank lines visibly blank;
 *   5. the drift build — the linen run finished, so the box costs nothing and the postable value rises.
 *
 * Renders deploy/dev/previews/stocktake-sheet.html and stocktake-sheet-drift.html in jsdom against
 * deploy/dev/preview/stocktake-show-sample.mjs.
 *
 * Run: node deploy/dev/audit/stocktake_sheet_preview_check.js
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
const num = (node) => Number((((text(node).match(/[−-]?[\d,]+\.?\d*/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);
const pctOf = (node) => Number((text(node).match(/([\d.]+)\s*%/) ?? [0, 0])[1]);
const round2 = (v) => Math.round(v * 100) / 100;

const doc = load('deploy/dev/previews/stocktake-sheet.html', 'https://preview.local/inventory/stock-takes/121');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the header ─────────────────────────────────────────────────────────── */
const head = [...$$('.stats.wide')[0].children].map(s => text(s));
ok('the header carries five figures', head.length === 5, `${head.length}`);
ok('the sheet is counted with who counted it and where',
  /^18/.test(head[0]) && /13 counted · 5 blank on Kitchen Store · counted by Ama Boateng/.test(head[0]), head[0]);
ok('progress is a fraction, not a percentage that hides the blanks', /13 \/ 18/.test(head[1]) && /72\.2 %/.test(head[1]), head[1]);
ok('the variance found so far is on the header', /GH₵ -2,053\.00/.test(head[2]) && /10 lines have moved/.test(head[2]), head[2]);
ok('and the blanks are stated in money, not just counted',
  /^5/.test(head[3]) && /16,154\.00 stays on the book unless the box below is ticked/.test(head[3]), head[3]);
ok('the largest line is named with both quantities',
  /GH₵ 810\.00/.test(head[4]) && /Tilapia — counted 0 kg against 18 on the book/.test(head[4]), head[4]);

/* ── the progress ───────────────────────────────────────────────────────── */
const progress = $('#stProgress');
ok('the page asks how far through the count is', /How far through is the count\?/.test(text(progress)));
ok('the head states the lines, the percentage and the blanks',
  /13 of 18 lines · 72\.2 % · 5 blank/.test(text(progress.querySelector('.card-head'))), text(progress.querySelector('.card-head')));
const pStats = [...progress.querySelectorAll('.stat')].map(s => text(s));
ok('counted is the first figure', /Counted/.test(pStats[0]) && /^13/.test(pStats[0]) && /72\.2 %/.test(pStats[0]), pStats[0]);
ok('still blank is the second, and says what a blank means',
  /Still blank/.test(pStats[1]) && /^5/.test(pStats[1]) && /the book quantity stands/.test(pStats[1]), pStats[1]);
ok('lines that agreed with the book are counted apart, because they post nothing',
  /Counted and equal/.test(pStats[2]) && /^3/.test(pStats[2]), pStats[2]);
ok('and a line counted at zero is flagged as a write-off, not a match',
  /Counted at zero/.test(pStats[3]) && /^1/.test(pStats[3]) && /writes the whole book quantity off/.test(pStats[3]), pStats[3]);
ok('the verdict names the categories that are still open rather than the sheet as a whole',
  /5 of the 18 lines still have no figure — Housekeeping and Linen are the ones left\./.test(text($('#stProgressState'))), text($('#stProgressState')));
ok('and says why progress is kept by category', /A count is done aisle by aisle/.test(text($('#stProgressState'))));
const pCols = [...$$('#progressTable thead th')].map(th => text(th));
ok('the progress list is five columns', pCols.join('|') === 'Category|Lines|Counted|Blank|Done', pCols.join('|'));
const pRows = [...$$('#progressTable tbody tr')].map(r => ({ cat: text(r.children[0]), lines: num(r.children[1]),
  counted: num(r.children[2]), blank: text(r.children[3]), pct: pctOf(r.children[4]), attr: Number(r.getAttribute('data-pct')) }));
ok('every category on the sheet is listed', pRows.length === 5 && pRows.map(r => r.cat).join(',')
  === 'Food,Fuel,Guest Supplies,Housekeeping,Linen', pRows.map(r => r.cat).join(','));
ok('the finished categories read a hundred per cent', pRows[0].pct === 100 && pRows[1].pct === 100 && pRows[2].pct === 100);
ok('the open ones read what is left', pRows[3].pct === 33.3 && pRows[4].pct === 40, `${pRows[3].pct}/${pRows[4].pct}`);
ok('the rows add to the header’s lines and counted figures',
  pRows.reduce((t, r) => t + r.lines, 0) === 18 && pRows.reduce((t, r) => t + r.counted, 0) === 13);
ok('the blank count on each row is the lines less the counted ones',
  pRows.every(r => (r.blank === '—' ? 0 : Number(r.blank)) === r.lines - r.counted));
ok('only the open rows are marked', $$('#progressTable tbody tr').filter(r => r.children[4].classList.contains('text-red')).length === 2);

/* ── what the count has found ───────────────────────────────────────────── */
const found = $('#stFound');
ok('the page asks what the count has found so far', /What has the count found so far\?/.test(text(found)));
ok('the head separates the two sides before it nets them',
  /GH₵ 2,078\.00 short · GH₵ 25\.00 over · GH₵ -2,053\.00 net, on 13 counted lines/.test(text(found.querySelector('.card-head'))),
  text(found.querySelector('.card-head')));
const fStats = [...found.querySelectorAll('.stat')].map(s => text(s));
ok('the net variance is a figure of its own', /Net variance/.test(fStats[0]) && /2,053\.00/.test(fStats[0]), fStats[0]);
ok('the short side is red and stated', /Short/.test(fStats[1]) && /2,078\.00/.test(fStats[1]), fStats[1]);
ok('the over side is stated too', /Over/.test(fStats[2]) && /25\.00/.test(fStats[2]), fStats[2]);
ok('the lines that moved are counted with the ones that agreed',
  /Lines moved/.test(fStats[3]) && /^10/.test(fStats[3]) && /3 counted lines agreed with the book exactly/.test(fStats[3]), fStats[3]);
ok('the verdict names the largest line with its quantity, its cost and its value',
  /The largest single line is Tilapia — 0 kg counted against 18 on the book, 18 kg short at GH₵ 45\.00 = GH₵ 810\.00\./.test(text($('#stFoundState'))),
  text($('#stFoundState')));
ok('and says the finding is not in the ledger yet', /On a draft sheet none of this is in the ledger yet/.test(text($('#stFoundState'))));
ok('the verdict is red while the count is short', $('#stFoundState').classList.contains('text-red'));
const fCols = [...$$('#foundTable thead th')].map(th => text(th));
ok('the finding is broken down by category', fCols.join('|') === 'Category|Counted|Short|Over|Net', fCols.join('|'));
const fRows = [...$$('#foundTable tbody tr')].map(r => ({ cat: text(r.children[0]), counted: num(r.children[1]),
  short: r.children[2].textContent.trim() === '—' ? 0 : num(r.children[2]),
  over: r.children[3].textContent.trim() === '—' ? 0 : num(r.children[3]), net: num(r.children[4]) }));
ok('every category is on the analysis', fRows.length === 5);
ok('short plus over is the net on every row', fRows.every(r => round2(r.short + r.over) === r.net),
  fRows.map(r => `${r.short}+${r.over}=${r.net}`).join(' | '));
ok('the rows add to the card’s net and to the header',
  round2(fRows.reduce((t, r) => t + r.net, 0)) === -2053);
const fFoot = [...$$('#foundTable tfoot th')].map(th => text(th));
ok('the footings are the totals the card states',
  fFoot.join('|') === 'All categories|13|-2,078.00|25.00|-2,053.00', fFoot.join('|'));
ok('a category that found nothing wrong reads zero, not blank', fRows[2].net === 0);

/* ── what posting will do ───────────────────────────────────────────────── */
const writes = $('#stWrites');
ok('the page asks what posting will do', /What will posting do\?/.test(text(writes)));
ok('the head says how many lines will write and how many will not',
  /10 lines will write a movement · GH₵ -2,053\.00 · 5 lines will not/.test(text(writes.querySelector('.card-head'))), text(writes.querySelector('.card-head')));
const wStats = [...writes.querySelectorAll('.stat')].map(s => text(s));
ok('the value to be written is the first figure', /Will be written/.test(wStats[0]) && /2,053\.00/.test(wStats[0]), wStats[0]);
ok('a line counted at zero is called what it is', /Counted at zero/.test(wStats[1]) && /^1/.test(wStats[1])
  && /the whole quantity is written off/.test(wStats[1]), wStats[1]);
ok('the lines posting leaves alone are counted with what that means',
  /Left alone/.test(wStats[2]) && /^5/.test(wStats[2]) && /posting leaves them on the book/.test(wStats[2]), wStats[2]);
ok('and the cost of the blank-as-zero box is stated as money',
  /Cost of the box/.test(wStats[3]) && /16,154\.00/.test(wStats[3]) && /sight unseen/.test(wStats[3]), wStats[3]);
ok('the verdict puts the biggest blank line in front of the person about to tick the box',
  /The biggest is Bed sheet: 190 pcs, GH₵ 8,550\.00\./.test(text($('#stWritesState'))), text($('#stWritesState')));
ok('and says plainly what ticking it means',
  /it tells the system every uncounted line is at zero, and writes GH₵ 16,154\.00 off the shelf in one posting that nobody actually counted/.test(text($('#stWritesState'))));
const wCols = [...$$('#writeTable thead th')].map(th => text(th));
ok('the write list is five columns', wCols.join('|') === 'Item|Book|Counted|Variance|Writes', wCols.join('|'));
const wRows = [...$$('#writeTable tbody tr')].map(r => ({ item: text(r.children[0]), book: text(r.children[1]),
  counted: num(r.children[2]), variance: num(r.children[3]), value: num(r.children[4]), attr: Number(r.getAttribute('data-value')) }));
ok('only the lines that will write a movement are listed', wRows.length === 10, `${wRows.length}`);
ok('they are ordered by what they are worth, the write-off first', wRows[0].item === 'Tilapia' && wRows[0].value === -810,
  `${wRows[0].item}:${wRows[0].value}`);
ok('the variance on every row is counted less the book quantity',
  wRows.every(r => round2(r.counted - Number(r.book.split(' ')[0])) === r.variance),
  wRows.map(r => `${r.book}/${r.counted}=${r.variance}`).join(' '));
ok('the row data carries the same value the column shows', wRows.every(r => r.attr === r.value));
ok('the footings are the value the card states', num($$('#writeTable tfoot th')[4]) === -2053);
ok('a line that found more than it lost is green, not red',
  wRows.find(r => r.item === 'Napkin').value === 16
  && $$('#writeTable tbody tr').find(r => text(r.children[0]) === 'Napkin').children[4].classList.contains('text-green'));
ok('the blank lines are not among the ones that will write anything',
  !wRows.some(r => ['Laundry powder', 'Bed sheet', 'Table cloth', 'Pillow case', 'Bin liners'].includes(r.item)),
  wRows.map(r => r.item).join(','));

/* ── the sheet itself ───────────────────────────────────────────────────── */
const cols = [...$$('#stTable thead th')].map(th => text(th));
ok('the sheet keeps nine columns', cols.join('|') === 'Item|Category|Unit|Book qty|Counted|Variance|Unit cost|Value|Remarks', cols.join('|'));
const rows = [...$$('#stTable tbody tr')].map(r => ({ item: text(r.children[0]).split(' ')[0], state: r.getAttribute('data-counted'),
  sys: Number(r.getAttribute('data-sys')), input: r.querySelector('.st-count')?.value ?? null,
  variance: text(r.children[5]), value: text(r.children[7]) }));
ok('every line on the sheet is a row', rows.length === 18, `${rows.length}`);
ok('each row carries the state the readings above were taken from',
  rows.filter(r => r.state === 'blank').length === 5 && rows.filter(r => r.state === 'zero').length === 2
  && rows.filter(r => r.state === 'counted').length === 11,
  rows.map(r => r.state).join(','));
ok('a counted line has its figure in the input, ready to be changed',
  rows.find(r => r.item === 'Tilapia').input === '0' && rows.find(r => r.item === 'Chicken').input === '62');
ok('a blank line is an empty input, which is the whole distinction',
  rows.filter(r => r.state === 'blank').every(r => r.input === ''));
ok('a blank line shows no variance and no value, because there is nothing to compute',
  rows.filter(r => r.state === 'blank').every(r => r.variance === '' && r.value === ''));
ok('a line counted at zero shows the whole book quantity as its variance',
  rows.find(r => r.item === 'Tilapia').variance === '-18' && rows.find(r => r.item === 'Tilapia').value === '-810.00');
ok('the save, post and blank-as-zero controls are still on the sheet',
  $$('#stForm button[name="action"]').length === 2 && $$('#stForm input[name="blank_as_zero"]').length === 1);
ok('and the blind sheet can still be printed without the book quantities showing',
  $$('a[href*="stocktakes-print"]').length === 2
  && /Blind count sheet/.test(text($$('a[href*="stocktakes-print"]')[0])));

/* ── the loop the sheet sits in ─────────────────────────────────────────── */
ok('the page says where the posting goes and what a blank line is not',
  /Posting writes one movement per counted line into the stock ledger/.test(text($('#stSheetChain')))
  && /a line left blank is not a line counted at zero/.test(text($('#stSheetChain'))), text($('#stSheetChain')));

/* ── the drift build ────────────────────────────────────────────────────── */
const drift = load('deploy/dev/previews/stocktake-sheet-drift.html', 'https://preview.local/inventory/stock-takes/121');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats.wide')[0].children].map(s => dText(s));
const dRows = [...drift.querySelectorAll('#progressTable tbody tr')];
const dWrite = [...drift.querySelectorAll('#writeTable tbody tr')];
ok('the drift finishes the count', /18 \/ 18/.test(dHead[1]) && /18 counted · 0 blank/.test(dHead[0]), dHead[0]);
ok('so the box costs nothing any more', /^0/.test(dHead[3]) && /nothing is left unseen/.test(dHead[3]), dHead[3]);
ok('and posting covers the whole sheet', /0 lines will not/.test(dText(drift.querySelector('#stWrites .card-head'))),
  dText(drift.querySelector('#stWrites .card-head')));
ok('every category reads a hundred per cent', dRows.every(r => pctOf(r.children[4]) === 100), dRows.map(r => dText(r.children[4])).join(','));
ok('the verdict on the box changes with it', /there is nothing left to write off unseen/.test(dText(drift.querySelector('#stWritesState'))),
  dText(drift.querySelector('#stWritesState')));
ok('the count finds more, because the linen was never counted before',
  /GH₵ -2,125\.00/.test(dHead[2]) && dWrite.length === 10
  && /11 lines/.test(dText(drift.querySelector('#writeTable tfoot'))), `${dHead[2]} / ${dWrite.length}`);
ok('the pillow cases are the line that turns up short', (() => {
  const r = dWrite.find(x => dText(x.children[0]) === 'Pillow case');

  return r && dText(r.children[3]) === '-4' && dText(r.children[4]) === '-72.00';
})());
ok('the lines that agreed with the book write nothing and stay off the list',
  !dWrite.some(r => ['Table cloth', 'Bed sheet', 'Laundry powder', 'Bin liners'].includes(dText(r.children[0]))));
ok('nothing else about the sheet changes — the tilapia is still the largest line',
  /Tilapia — counted 0 kg against 18 on the book/.test(dHead[4]));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the sheet', doc], ['the drifted sheet', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|endforelse|php|endphp|unless|endunless|error|enderror)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder or leaked attribute`, !/\{\{|\}\}|:sub=|:tone=/.test(clean));
  ok(`${name} holds no unpluralised shorthand`, !/line\(s\)|item\(s\)|category\(s\)/.test(clean));
}

if (failures.length) {
  console.error(`count sheet check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`count sheet check: ${passed}/${passed} passed`);
