#!/usr/bin/env node
/*
 * The consolidated report, held to what it claims.
 *
 * This is the first report in the engine that reads more than one property, so the check reads it
 * the way a group administrator would: is every property they may open in it, is each row on its own
 * business date, do the columns total to the summary above them, are the ratios kept out of the
 * totals, and is the property that cannot post named beneath the table rather than quietly added to
 * it — with the way out of the screen intact (CSV, print, save, schedule).
 *
 * Run: node deploy/dev/audit/reports_consolidated_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const read = path => readFileSync(resolve(ROOT, path), 'utf8');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('Consolidated report preview: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) {
    passed += 1;
    return;
  }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const dom = new JSDOM(read('deploy/dev/previews/reports-consolidated.html'), { url: 'https://preview.local/reports/group_operating_summary' });
const doc = dom.window.document;
const clean = node => (node?.textContent || '').replace(/\s+/g, ' ').trim();
const text = clean(doc.body);

ok('the report names itself', doc.title.includes('Consolidated Operating Summary'));
ok('it is filed under hotel group and owner reporting', text.includes('Hotel Group & Owner Reporting'));
ok('the context line counts the properties it read', /4 rows/.test(clean(doc.querySelector('[data-testid="report-context"]'))));

const table = doc.querySelector('table');
const head = [...table.querySelectorAll('thead th')].map(th => clean(th));
ok('every property column is there', ['Property', 'Code', 'Status', 'Business date', 'Sellable', 'Occupied', 'Occupancy', 'In-house', 'Revenue today', 'Revenue MTD', 'Licence'].every(h => head.includes(h)), head.join(' | '));

// the engine draws a "nothing matches" row inside the body when a filter empties it
const rows = [...table.querySelectorAll('tbody tr')]
  .map(tr => [...tr.querySelectorAll('td')].map(td => clean(td)))
  .filter(cells => /^[A-Z]{3,5}$/.test(cells[head.indexOf('Code')] || ''));
const cell = (row, header) => row[head.indexOf(header)];
const byCode = code => rows.find(r => cell(r, 'Code') === code);

ok('it lists the properties the reader may open', rows.length === 4, `${rows.length} rows`);
ok('a shut property is not a row', !byCode('KSI'));
ok('and it is named beneath the table', text.includes('Nowills Kumasi City (KSI)'));
ok('with the reason it is out', text.includes('because nobody can post there'));
ok('and that the exclusion is reversible', text.includes('licensing a module again puts it straight back in this report'));
ok('it says the properties are on different dates', text.includes('Each property is read on its own business date'));
ok('and the rows show those dates apart', cell(byCode('GROVE'), 'Business date') === '07-11-2026' && cell(byCode('ACC'), 'Business date') === '06-11-2026');
ok('a narrowed property says how narrowed', cell(byCode('GROVE'), 'Licence') === '6 of 7 modules');
ok('a property on the group licence says so', cell(byCode('ACC'), 'Licence') === 'Group licence');

const stats = [...doc.querySelectorAll('.stat')].map(s => ({ label: clean(s.querySelector('.l')), value: clean(s.querySelector('.v')) }));
const stat = label => stats.find(s => s.label === label);
ok('the strip counts the properties that traded', stat('Properties trading')?.value === '4', stat('Properties trading')?.value);
ok('it counts the shut ones beside them', stat('Properties shut')?.value === '1', stat('Properties shut')?.value);
ok('occupancy is the portfolio figure, not an average of four', stat('Group occupancy')?.value === '64.6%', stat('Group occupancy')?.value);
ok('revenue today is the sum of the rows', stat('Revenue today')?.value === 'GH₵ 85,880.00', stat('Revenue today')?.value);
ok('month to date is the sum of the rows', stat('Revenue MTD')?.value === 'GH₵ 1,855,600.00', stat('Revenue MTD')?.value);

const foot = [...table.querySelectorAll('tfoot tr')].map(tr => [...tr.querySelectorAll('td')].map(td => clean(td)))[0] || [];
const total = header => foot[head.indexOf(header)];
ok('the total line adds the rooms', total('Sellable') === '280', total('Sellable'));
ok('and the occupied rooms', total('Occupied') === '195', total('Occupied'));
ok('and revenue today, in money', /GH₵ 85,880\.00/.test(total('Revenue today')), total('Revenue today'));
ok('it does not add occupancies together', total('Occupancy') === '', `"${total('Occupancy')}"`);
ok('nor ADRs', total('ADR') === '', `"${total('ADR')}"`);

ok('the file can be exported', [...doc.querySelectorAll('a')].some(a => /csv/i.test(a.textContent) || /csv/i.test(a.getAttribute('href') || '')));
ok('it can be printed', [...doc.querySelectorAll('a')].some(a => /print/i.test(a.textContent) || /print/i.test(a.getAttribute('href') || '')));
ok('it can be saved as a view', [...doc.querySelectorAll('a,button')].some(el => /save view/i.test(clean(el))));
ok('and e-mailed on a schedule', [...doc.querySelectorAll('a,button')].some(el => /schedule/i.test(clean(el))));
ok('the other group reports are offered beside it', text.includes('Hotel Group Register') && text.includes('Hotel KPIs by Month'));

if (failures.length) {
  console.error(`Consolidated report preview check: ${failures.length} failed, ${passed} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`Consolidated report preview check: ${passed} assertions passed`);
