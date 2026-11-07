#!/usr/bin/env node
/*
 * The kitchen menu sheet is a document, so this checks it the way the kitchen reads it:
 * one function per page, the dishes in the order they are to be produced, the covers to
 * cook for — and no money anywhere on the paper, because a chef has no business knowing
 * what the client paid.
 *
 * Three things this check is deliberately honest about:
 *
 *   1. dietary counts are not held anywhere in the system. The sheet prints the client's
 *      requirements verbatim and a fill-in grid, and the assertion below says so; the day
 *      those counts are captured on the booking, this assertion is what has to change.
 *   2. a function with no package and no rate must say so plainly rather than print an
 *      empty menu — the ceremony below is that case.
 *   3. the sheet is one function per page, so the print rule is asserted in the stylesheet
 *      and the marker above the fold.
 *
 * Run: node deploy/dev/audit/banquet_menu_preview_check.js
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const SHEET = resolve(ROOT, 'deploy/dev/previews/banquet-menu.html');
const SINGLE = resolve(ROOT, 'deploy/dev/previews/banquet-menu-single.html');
const ROUTES = resolve(ROOT, 'routes/web.php');
const CONTROLLER = resolve(ROOT, 'app/Http/Controllers/BanquetController.php');
const SHOW = resolve(ROOT, 'resources/views/banquet/show.blade.php');
const DAYSHEET = resolve(ROOT, 'resources/views/banquet/sheet.blade.php');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('banquet menu preview check: SKIP (jsdom is not installed)');
  process.exit(0);
}

let passed = 0;
const failures = [];
const ok = (label, condition, detail = '') => {
  if (condition) { passed += 1; return; }
  failures.push(detail ? `${label} — ${detail}` : label);
};

ok('both menu previews exist', existsSync(SHEET) && existsSync(SINGLE));
if (!existsSync(SHEET) || !existsSync(SINGLE)) {
  console.log(`banquet menu preview check: 1 FAILED\n  ✗ ${failures[0]}`);
  process.exit(1);
}

const html = readFileSync(SHEET, 'utf8');
const doc = new JSDOM(html).window.document;
const singleDoc = new JSDOM(readFileSync(SINGLE, 'utf8')).window.document;
const $$ = (sel, root = doc) => [...root.querySelectorAll(sel)];
const text = (sel, root = doc) => (root.querySelector(sel)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const cards = $$('.menu-fn');
const bodyText = doc.body.textContent.replace(/\s+/g, ' ');

/* ── the paper ───────────────────────────────────────────────────────────── */
ok('the sheet names itself a kitchen menu sheet', /KITCHEN MENU SHEET/.test(text('.doc .m')), text('.doc .m'));
ok('it carries the event number', text('.doc h2') === 'BQT-26041', text('.doc h2'));
ok('it carries the hotel and its address', /The Grove Essipong Beach Resort/.test(text('h1')) && /Takoradi/.test(bodyText));
ok('the status is stamped on the paper', /TENTATIVE/.test(text('.stamp')), text('.stamp'));
ok('a tentative hold is warned about before the menu',
  /Tentative hold/.test(text('.menu-banner')) && /Cook nothing from this sheet/.test(text('.menu-banner')),
  text('.menu-banner'));
ok('the warning carries the option date', /21 Nov 2026/.test(text('.menu-banner')), text('.menu-banner'));

/* ── no money, and the sheet says so ─────────────────────────────────────── */
ok('nothing on the sheet is priced', !/GH₵/.test(bodyText));
ok('no deposit, balance or contract language leaks in',
  !/\bdeposit\b|\bbalance\b|\bcontract\b|\bquotation\b|\bproposal\b/i.test(bodyText));
ok('the footer states that prices live on the event order',
  /No prices on this sheet/.test(text('.menu-foot')), text('.menu-foot'));
ok('the footer never calls itself a preview', !/preview/i.test(bodyText));

/* ── the cover summary ───────────────────────────────────────────────────── */
ok('two functions are on this sheet', cards.length === 2, String(cards.length));
ok('the summary totals the covers to cook', /347 covers to cook/.test(text('.kv')), text('.kv'));
ok('and the covers expected', /Coversexpected330/.test(text('.kv').replace(/\s+/g, '')), text('.kv'));
ok('the host and number are on the paper for the dietary call',
  /Ama Serwaa Mensah/.test(text('.kv')) && /\+233 24 118 6620/.test(text('.kv')));

/* ── one function per page ───────────────────────────────────────────────── */
ok('each function is its own block', cards.every(card => card.tagName === 'SECTION'));
ok('the print rule breaks the page after each function',
  /\.menu-fn\{break-after:page;page-break-after:always\}/.test(html));
ok('the last function does not leave a blank page', /\.menu-fn:last-of-type\{break-after:auto/.test(html));

/* ── the ceremony: no F&B, stated plainly ────────────────────────────────── */
const ceremony = cards[0];
ok('the first function is the ceremony in the Garden Pavilion',
  /Traditional ceremony — Garden Pavilion/.test(text('h3', ceremony)), text('h3', ceremony));
ok('the ceremony prints its date and hours', /Sat 12 Dec 2026 · 14:00–17:00/.test(text('h3', ceremony)));
ok('the ceremony says there is no food and beverage',
  /No food and beverage on this function/.test(text('.menu-empty', ceremony)), text('.menu-empty', ceremony));
ok('the ceremony prints no dish list', $$('.menu-dishes li', ceremony).length === 0);
ok('the ceremony still shows the covers to prepare for',
  /Expected150/.test(text('.menu-covers', ceremony).replace(/\s+/g, '')),
  text('.menu-covers', ceremony));

/* ── the reception: the menu itself ──────────────────────────────────────── */
const reception = cards[1];
ok('the second function is the reception in the Grand Ballroom',
  /Reception & dinner — The Grand Ballroom/.test(text('h3', reception)), text('h3', reception));
ok('the reception names the package and its type',
  /Gold Buffet/.test(text('.menu-package', reception)) && /Buffet/.test(text('.menu-type', reception)),
  text('.menu-package', reception));
const dishes = $$('.menu-dishes li', reception).map(li => li.textContent.trim());
ok('every dish on the package is printed', dishes.length === 8, String(dishes.length));
ok('the dishes are printed in order, first to last',
  dishes[0] === 'Jollof rice with grilled chicken' && dishes[7] === 'Chocolate mousse and petits fours',
  `${dishes[0]} … ${dishes[7]}`);
ok('the covers to cook are the overset, not the booking',
  /Cookfor189\+5%overset/.test(text('.menu-covers', reception).replace(/\s+/g, '')),
  text('.menu-covers', reception));
ok('the ceremony cooks for its own overset too',
  /Cookfor158\+5%overset/.test(text('.menu-covers', ceremony).replace(/\s+/g, '')),
  text('.menu-covers', ceremony));
ok('the reception prints the kitchen notes',
  /Buffet open at 19:45/.test(text('.menu-card.menu-wide', reception)), text('.menu-card.menu-wide', reception));
ok('the ceremony prints its own notes',
  /The family provides the drinks/.test(text('.menu-card.menu-wide', ceremony)));

/* ── dietary: the gap, told honestly ─────────────────────────────────────── */
ok('every function carries a dietary block', $$('.menu-diet').length === 2);
ok('the dietary grid has five rows per function', $$('.menu-diet tbody tr', reception).length === 5,
  String($$('.menu-diet tbody tr', reception).length));
ok('the grid is there to be written in, not filled in for the cook',
  $$('.menu-fill', reception).length === 10, String($$('.menu-fill', reception).length));
ok('the client requirements are printed verbatim',
  /Two guests are coeliac and one is wheelchair-dependent/.test(text('.menu-card .pre', reception)));
ok('the sheet admits the counts are not held on the booking',
  /Dietary counts are not held on the booking/.test(bodyText));
ok('and tells the cook who to ask', /confirm with the banquet manager/.test(bodyText));

/* ── nothing comes off the paper unsigned ────────────────────────────────── */
ok('each function has a receipt line for the kitchen', $$('.menu-received').length === 2);
ok('the receipt line has somewhere to sign', cards.every(card => /Received in kitchen by _+/.test(text('.menu-received', card))));
ok('the receipt line repeats the covers to cook',
  /Reception & dinner · 189 covers/.test(text('.menu-received', reception)), text('.menu-received', reception));

/* ── the single-function sheet ───────────────────────────────────────────── */
const singleCards = [...singleDoc.querySelectorAll('.menu-fn')];
ok('the single-function sheet carries one function', singleCards.length === 1, String(singleCards.length));
ok('it is the function the day sheet linked to',
  /Reception & dinner — The Grand Ballroom/.test(singleCards[0]?.querySelector('h3')?.textContent ?? '')
  && !/Traditional ceremony — Garden Pavilion/.test(singleDoc.body.textContent));
ok('it still totals what that one function cooks',
  /189 covers to cook/.test(singleDoc.body.textContent), 'no cover total');
ok('and it is still unpriced', !/GH₵/.test(singleDoc.body.textContent));

/* ── the entry points exist in the application ───────────────────────────── */
const routes = readFileSync(ROUTES, 'utf8');
ok('the menu route is registered under the banquet permissions',
  /Route::get\('\/\{event\}\/menu', 'menu'\)->middleware\('perm:banquet\.view'\)->name\('menu'\);/.test(routes));
const controller = readFileSync(CONTROLLER, 'utf8');
ok('the controller hands the view the event, the functions and the cover totals',
  /public function menu\(Request \$request, BanquetEvent \$event\)/.test(controller)
  && /'cookTotal' =>/.test(controller) && /'expectedTotal' =>/.test(controller));
ok('the controller can narrow to one function', /\$focus = \$request->integer\('function'\)/.test(controller));
const show = readFileSync(SHOW, 'utf8');
ok('the event page links to the kitchen menu',
  /route\('banquet\.menu', \$e\)/.test(show) && /Kitchen menu/.test(show));
const daySheet = readFileSync(DAYSHEET, 'utf8');
ok('the day sheet links each function to its own menu',
  /route\('banquet\.menu', \['event' => \$f->event, 'function' => \$f->id\]\)/.test(daySheet));

if (failures.length) {
  console.log(`banquet menu preview check: ${failures.length} FAILED, ${passed} passed`);
  failures.forEach(failure => console.log(`  ✗ ${failure}`));
  process.exit(1);
}
console.log(`banquet menu preview check: ${passed} assertions passed`);
