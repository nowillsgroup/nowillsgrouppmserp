#!/usr/bin/env node
/*
 * The withholding-tax desk is the screen a property files its GRA return from. Three things there
 * turn into a wrong return rather than a wrong screen: the threshold band the box accepts versus
 * the band the controller enforces, the totals that go on the schedule, and whether the screen
 * warns about suppliers whose TIN GRA will reject.
 *
 * This renders deploy/dev/previews/wht-desk.html in jsdom against
 * deploy/dev/preview/wht-sample.mjs: four figures, the September return still overdue, the
 * schedule grouped by nature of payment with a total that is the sum of its rows, the TIN warning,
 * the ledger-versus-schedule note, and the filing form with the threshold's printed ceiling.
 *
 * Run: node deploy/dev/audit/wht_desk_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const FILE = 'deploy/dev/previews/wht-desk.html';
const dom = new JSDOM(readFileSync(FILE, 'utf8'), { url: 'https://preview.local/ap/wht' });
const doc = dom.window.document;

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};
const $ = (selector) => doc.querySelector(selector);
const $$ = (selector) => [...doc.querySelectorAll(selector)];
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const money = (value) => Number(String(value).replace(/[^0-9.-]/g, ''));

/* ── the screen ──────────────────────────────────────────────────────────── */
ok('the page is titled for withholding tax', doc.title.startsWith('Withholding Tax'), doc.title);
ok('the period can be chosen', $$('#whtPeriod option').length === 5, `${$$('#whtPeriod option').length} periods`);
ok('and the one on screen is selected',
  $('#whtPeriod option[selected]')?.value === '2026-10', $('#whtPeriod option[selected]')?.value);
ok('the schedule can be exported', /Schedule \(CSV\)/.test(text(doc.body)));

/* ── the four figures ────────────────────────────────────────────────────── */
const stats = $$('.stat').map(stat => ({
  label: text(stat.querySelector('.l')),
  value: text(stat.querySelector('.v')),
  note: text(stat.querySelector('.tiny')),
}));
ok('four figures head the desk', stats.length === 4, `${stats.length} cards`);
ok('the tax withheld is named with its month', /Tax withheld · October 2026/.test(stats[0]?.label), stats[0]?.label);
ok('and says how many payments it came from', /4 payment\(s\) · base/.test(stats[0]?.note), stats[0]?.note);
ok('withholding VAT shows the agent rate in force',
  stats[1]?.value.includes('1,180.40') && /appointed agent · 7%/.test(stats[1]?.note), JSON.stringify(stats[1]));
ok('what is payable to GRA is the two added together',
  Math.abs(money(stats[2]?.value) - (money(stats[0]?.value) + money(stats[1]?.value))) < 0.01,
  `${stats[0]?.value} + ${stats[1]?.value} vs ${stats[2]?.value}`);
ok('the filing status says the month is closed but not filed',
  /Not filed/.test(stats[3]?.value), stats[3]?.value);

/* ── what the desk warns about ───────────────────────────────────────────── */
ok('an overdue earlier return is flagged',
  Boolean($('#whtAlert')) && /overdue/.test(text($('#whtAlert'))), text($('#whtAlert')));
ok('and names the month it belongs to', /September 2026/.test(text($('#whtAlert'))));
ok('suppliers with no TIN are named, because GRA rejects the return without them',
  Boolean($('#whtMissingTin')) && /Adom Facilities Ltd/.test(text($('#whtMissingTin'))), text($('#whtMissingTin')));
ok('the ledger-versus-schedule difference is explained rather than left as a mystery',
  Boolean($('#whtGlDiff')) && /payments post to the ledger when the night audit closes their day/.test(text($('#whtGlDiff'))));

/* ── the schedule ────────────────────────────────────────────────────────── */
const rows = $$('#whtCats tbody tr');
ok('every nature of payment that paid tax has a row', rows.length === 4, `${rows.length} rows`);
ok('each row carries the statutory rate',
  text(rows[0]).includes('7.5%') && text(rows[1]).includes('3%'), text(rows[0]));
ok('an unknown nature of payment is not invented', !/undefined/.test(text($('#whtCats'))));

const footCells = $$('#whtCats tfoot td').map(td => text(td));
const taxableCells = $$('#whtCats tbody tr td:nth-child(4)');
const whtCells = $$('#whtCats tbody tr td:nth-child(5)');
ok('the taxable total is the sum of the rows',
  Math.abs(taxableCells.reduce((total, cell) => total + money(text(cell)), 0) - money(footCells[1])) < 0.01,
  `${taxableCells.map(cell => text(cell)).join(' + ')} vs ${footCells[1]}`);
ok('the tax total is the sum of the rows',
  Math.abs(whtCells.reduce((total, cell) => total + money(text(cell)), 0) - money(footCells[2])) < 0.01,
  `${whtCells.map(cell => text(cell)).join(' + ')} vs ${footCells[2]}`);
ok('and the tax total is the figure in the headline card',
  Math.abs(money(footCells[2]) - money(stats[0]?.value)) < 0.01, `${footCells[2]} vs ${stats[0]?.value}`);

/* ── filing ──────────────────────────────────────────────────────────────── */
const fileForm = $('#whtFileForm');
ok('the return can be marked filed', Boolean(fileForm));
ok('which needs the GRA reference', Boolean(fileForm?.querySelector('input[name=reference][required]')));
const filedOn = fileForm?.querySelector('input[name=filed_on]');
ok('and the date it was filed, never in the future',
  filedOn?.getAttribute('max') === '2026-11-05' && filedOn?.value === '2026-11-05',
  `${filedOn?.value} (max ${filedOn?.getAttribute('max')})`);
ok('the form warns that filing locks the month',
  /Withheld payments in the month will be locked/.test(fileForm?.getAttribute('data-confirm') ?? ''));
ok('the amount to pay and its due date are printed',
  /Pay GH₵ [\d,]+\.\d\d to GRA by 15 Nov 2026/.test(text(doc.body)), text(doc.body).match(/Pay GH₵[^.]*\./)?.[0]);

/* ── the settings on the same desk ───────────────────────────────────────── */
const threshold = $('input[name=wht_threshold]');
ok('the annual threshold prints the ceiling WhtController enforces',
  threshold?.getAttribute('min') === '0' && threshold?.getAttribute('max') === '10000000',
  `${threshold?.getAttribute('min')}–${threshold?.getAttribute('max')}`);
ok('and the label states the same band in words', /0 to 10,000,000/.test(text(doc.body)));
const wvat = $('input[name=wvat_rate]');
ok('the withholding VAT rate prints its 0–20 % band',
  wvat?.getAttribute('min') === '0' && wvat?.getAttribute('max') === '20',
  `${wvat?.getAttribute('min')}–${wvat?.getAttribute('max')}`);
ok('the agent switch says what it will withhold', /withhold 7% of the taxable value/.test(text(doc.body)));

/* ── nothing left unrendered ─────────────────────────────────────────────── */
const bodyCopy = doc.body.cloneNode(true);
bodyCopy.querySelectorAll('script, style').forEach(node => node.remove());
const bodyText = text(bodyCopy);
ok('no Blade directive survived the render', !/@(if|foreach|endif|endforeach|php|else)\b/.test(bodyText));
ok('no undefined or NaN reached the screen', !/undefined|NaN/.test(bodyText));

if (failures.length) {
  console.error(`wht desk check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`wht desk check: ${passed}/${passed} passed`);
