#!/usr/bin/env node
/*
 * The in-room dining page, as the guest leaves it.
 *
 * The preview is frozen markup — the page's script does not travel with it — so this check reads the two frames the
 * builder captured and asserts that they show what the guest would have seen: the side-dish step with the included
 * salad ticked and French fries counted twice, and the order it produced with the same taste written once, priced at
 * two portions, and posted unit by unit.
 *
 * Run: node deploy/dev/audit/guest_dining_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const read = path => readFileSync(resolve(ROOT, path), 'utf8');

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) {
    passed += 1;
    return;
  }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const html = read('deploy/dev/previews/guest-dining.html');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('guest dining preview: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

const dom = new JSDOM(html, { url: 'https://preview.local/guest-dining', pretendToBeVisual: true });
const doc = dom.window.document;
const frames = [...doc.querySelectorAll('.phone')];
const [step, order] = frames;

// ── the page ────────────────────────────────────────────────────────────────
ok('the preview carries two states', frames.length === 2, `${frames.length} frames`);
ok('the captions say what is being shown', doc.body.textContent.includes('French fries counted twice')
  && doc.body.textContent.includes('French fries ×2'));
ok('the tab title names the screen', doc.title.includes('in-room dining'));

// ── the menu behind the sheet ───────────────────────────────────────────────
const banku = step.querySelector('.qm-item[data-id="11"]');
ok('the dish that comes with a side dish is on the menu', !!banku);
ok('its card says a side dish is owed', banku.textContent.includes('please choose one'));
ok('the dish with no side dishes carries none', step.querySelector('.qm-item[data-id="12"]').dataset.variants === '[]');
ok('the side dishes are published once, undoubled', banku.dataset.variants.includes('"name":"French fries"')
  && !html.includes('&amp;quot;'), 'double-escaped JSON would never parse');

// ── the side-dish step ──────────────────────────────────────────────────────
ok('the side-dish sheet is open', step.querySelector('#sideSheet').classList.contains('show'));
const rows = [...step.querySelectorAll('#sideChoices .gp-side-row')];
const rowFor = name => rows.find(row => row.textContent.includes(name));
ok('both side dishes are offered', rows.length === 2, `${rows.length} rows`);
ok('the sheet is titled for the dish', step.querySelector('#sideTitle').textContent.includes('Banku & Okro Stew'));
ok('the included side dish is ticked and counted once', rowFor('Garden salad').textContent.includes('included')
  && rowFor('Garden salad').querySelector('input[type=checkbox]').checked
  && rowFor('Garden salad').querySelector('.gp-side-qty .n').textContent === '1');
ok('the charged side dish is counted twice', rowFor('French fries').querySelector('.gp-side-qty .n').textContent === '2');
ok('it is ticked once, not twice', rowFor('French fries').querySelectorAll('input[type=checkbox]').length === 1);
ok('the charged side dish shows its rate', rowFor('French fries').textContent.includes('+25.00'));
ok('a dish that owes a side dish offers no way past it', step.querySelector('#sideSkip').style.display === 'none');
ok('the step can be completed', step.querySelector('#sideAdd').textContent === 'Add to order'
  && step.querySelector('#sideAdd').disabled === false);

// ── the order it produced ───────────────────────────────────────────────────
ok('the order sheet is open', order.querySelector('#sheet').classList.contains('show'));
const lines = [...order.querySelectorAll('#sheetLines .cart-line')];
ok('one line was added', lines.length === 1, `${lines.length} lines`);
ok('the line reads “French fries ×2”', lines[0].textContent.includes('French fries ×2'));
ok('the included side dish names no price', lines[0].textContent.includes('Garden salad') && !lines[0].textContent.includes('Garden salad +'));
ok('the double portion is charged twice', lines[0].textContent.includes('+50.00'));
ok('the order total counts both portions', order.querySelector('#sheetTotal').textContent.trim() === 'GH₵ 108.00',
  order.querySelector('#sheetTotal').textContent.trim());
const units = [...order.querySelectorAll('#sheetLines input[name="items[0][variants][]"]')].map(input => Number(input.value));
ok('both units of the double portion are posted', JSON.stringify(units) === '[501,502,502]', JSON.stringify(units));
ok('one portion of the dish is ordered', order.querySelector('#sheetLines input[name="items[0][qty]"]').value === '1');
ok('the note field travels with the line', order.querySelector('#sheetLines input[name="items[0][notes]"]') !== null);

console.log(`guest dining preview: ${passed} assertion(s) passed${failures.length ? `, ${failures.length} failed` : ''}`);
if (failures.length) {
  failures.forEach(failure => console.log(`  ✗ ${failure}`));
  process.exit(1);
}
