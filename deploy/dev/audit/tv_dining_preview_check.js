#!/usr/bin/env node
/*
 * The room TV's in-room dining screen, as it is left in the preview.
 *
 * The preview is frozen markup — the client's script does not travel with it — so this check reads the two frames the
 * builder captured: the side-dish step with the included salad and French fries counted twice, and the order it made.
 *
 * Run: node deploy/dev/audit/tv_dining_preview_check.js
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
  console.log('room TV preview: SKIP (jsdom is not installed — npm install --no-save jsdom)');
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

const html = read('deploy/dev/previews/tv-dining.html');
const dom = new JSDOM(html, { url: 'https://preview.local/tv-dining', pretendToBeVisual: true });
const doc = dom.window.document;
const frames = [...doc.querySelectorAll('.tv-frame')];
const [step, order] = frames.map(frame => frame.querySelector('.screen'));

ok('the preview carries two television frames', frames.length === 2, `${frames.length} frames`);
ok('the captions say what is being shown', doc.body.textContent.includes('French fries counted twice')
  && doc.body.textContent.includes('French fries ×2'));
ok('the icon sprite travels with the page', doc.querySelectorAll('symbol[id^="tv-glyph-"]').length > 5);

// ── the dining screen behind the step ──────────────────────────────────────
const tiles = [...step.querySelectorAll('.tv-dish')];
ok('the menu is drawn on the television', tiles.length === 4, `${tiles.length} dishes`);
const banku = tiles.find(t => t.textContent.includes('Banku'));
ok('the dish that comes with a side dish says so', banku.textContent.includes('Side dish · required'));
ok('a dish with no side dishes says nothing', tiles.find(t => t.textContent.includes('water')).textContent.trim().endsWith('GH₵ 8.00'));

// ── the side-dish step ─────────────────────────────────────────────────────
ok('the side-dish step is open', !!step.querySelector('.tv-modal'));
ok('it is titled for the dish', step.querySelector('.tv-modal h2').textContent.includes('Banku & Okro Stew · Side dish'));
const rows = [...step.querySelectorAll('.tv-modal .tv-side-row')];
const rowFor = name => rows.find(row => row.textContent.includes(name));
ok('both side dishes are offered', rows.length === 2, `${rows.length} rows`);
ok('the included side dish reads as included', rowFor('Garden salad').textContent.includes('included'));
ok('the charged side dish shows its rate', rowFor('French fries').textContent.includes('+25.00'));
ok('French fries are counted twice', rowFor('French fries').querySelector('.tv-side-q').textContent === '×2');
ok('the salad is counted once', rowFor('Garden salad').querySelector('.tv-side-q').textContent === '×1');
ok('each side dish has its own − / +', rowFor('French fries').querySelectorAll('.tv-q').length === 2);
ok('the step can be completed', [...step.querySelectorAll('.tv-modal .tv-row button')][0].textContent === 'Add');

// ── the order it made ──────────────────────────────────────────────────────
const line = order.querySelector('.tv-cart-l');
ok('the order carries the dish', !!line && line.textContent.includes('1 × Banku & Okro Stew'));
ok('the line reads “French fries ×2”', line.textContent.includes('French fries ×2'), line && line.textContent);
ok('the included side dish is named without a price', line.textContent.includes('Garden salad') && !line.textContent.includes('Garden salad +'));
ok('the order total counts both portions', order.querySelector('.tv-total').textContent.includes('108.00'), order.querySelector('.tv-total').textContent);
ok('the order can be placed', order.textContent.includes('Place order'));
ok('the side step is closed in the order frame', !order.querySelector('.tv-modal'));

console.log(`room TV preview: ${passed} assertion(s) passed${failures.length ? `, ${failures.length} failed` : ''}`);
if (failures.length) {
  failures.forEach(failure => console.log(`  ✗ ${failure}`));
  process.exit(1);
}
