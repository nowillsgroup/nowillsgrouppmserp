#!/usr/bin/env node
/*
 * Functional check of in-room dining on the guest portal.
 *
 * The page's own markup and its own script are lifted out of the Blade file, a few dishes are placed on the menu
 * (one that must come with a side dish, one that may, one that also asks for options), and the sheet is driven the way
 * a guest drives it: tap ADD, tick a side dish, count it twice, send. What we assert is what the room-service order
 * would carry — the same `variants[]` list the restaurant terminal posts.
 *
 * Run: node deploy/dev/audit/portal_dining_check.js
 * Needs jsdom: npm install --no-save jsdom   (it skips itself when that is absent)
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
  console.log('guest portal dining: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

let failures = 0;
const ok = (label, condition, detail = '') => {
  if (condition) {
    console.log(`  ✓ ${label}`);
  } else {
    failures += 1;
    console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ''}`);
  }
};

const BLADE = read('resources/views/portal/dining.blade.php');

// ── the menu the check puts on the page ──────────────────────────────────────
// Shaped exactly as the Blade card is: the side dishes ride in data-variants, and the dish says whether one is owed.
const card = ({ id, name, price, variants = [], modifiers = [], required = false }) =>
  `<div class="qm-item" data-id="${id}" data-name="${name}" data-price="${price}" data-modifiers='${JSON.stringify(modifiers)}' data-variants='${JSON.stringify(variants)}' data-side-required="${required ? '1' : '0'}">
     <div class="info"><div class="nm">${name}</div><div class="pr">${price}</div></div>
     <div class="ctl"><button type="button" class="qm-add">ADD</button></div>
   </div>`;

const CARDS = [
  card({
    id: 11, name: 'Banku & Okro Stew', price: 35, required: true,
    variants: [{ id: 101, name: 'Garden salad', price_delta: 0 }, { id: 102, name: 'French fries', price_delta: 25 }],
  }),
  card({
    id: 12, name: 'Grilled chicken', price: 60, required: false,
    variants: [{ id: 101, name: 'Garden salad', price_delta: 0 }, { id: 102, name: 'French fries', price_delta: 25 }],
  }),
  card({
    id: 13, name: 'Waakye special', price: 40, required: false,
    variants: [{ id: 103, name: 'Extra shito', price_delta: 5 }],
    modifiers: [{ id: 7, name: 'Spice level', min: 1, max: 1, required: true, options: [{ id: 71, name: 'Mild', price_delta: 0 }] }],
  }),
  card({ id: 14, name: 'Bottled water', price: 6 }),
].join('\n');

function buildPage() {
  const start = BLADE.indexOf("@section('content')") + "@section('content')".length;
  const end = BLADE.indexOf("@push('scripts')");
  let html = BLADE.slice(start, end);

  // the guest's past orders and the category loop are data, not markup: this check brings its own dishes
  html = html.replace(/@if\(\$orders[\s\S]*?@endif/, '');
  html = html.replace(/@if\(\$cats[\s\S]*?@endforelse/, `<div id="menu">${CARDS}</div>`);
  html = html.replace(/@forelse[\s\S]*?@endforelse/, '');
  html = html.replace(/\{\{[\s\S]*?\}\}/g, '');
  html = html.replace(/@php\([\s\S]*?\)\s*$/gm, '');
  html = html.replace(/@(csrf|if|endif|isset|unless|endunless)\b[^\n]*/g, '');
  html = html.replace(/<x-icon[^>]*\/>/g, '');
  return `<!doctype html><html><head><meta charset="utf-8"></head><body>${html}</body></html>`;
}

function bladeScript() {
  const script = BLADE.slice(BLADE.indexOf('<script>') + 8, BLADE.indexOf('</script>'));
  return script
    .replace(/@json\((?:[^()]|\([^()]*\))*\)/g, "''")
    .split('\n')
    .filter(line => !line.trim().startsWith('@'))
    .join('\n')
    // the script keeps its state in module scope; hand it to the check so the cart can be inspected
    + '\nwindow.__cart = cart;';
}

const dom = new JSDOM(buildPage(), { url: 'https://pms.test/portal/stay/abc/dining', runScripts: 'dangerously', pretendToBeVisual: true });
const { window } = dom;
const { document } = window;

// the page's own script, run the way the browser runs it: as a script, so its sheets can call themselves
const tag = document.createElement('script');
tag.textContent = bladeScript();
document.body.appendChild(tag);

// ── helpers ─────────────────────────────────────────────────────────────────
const $ = sel => document.querySelector(sel);
const click = el => el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
const cardEl = id => document.querySelector(`.qm-item[data-id="${id}"]`);
const sheetOpen = () => $('#sideSheet').classList.contains('show');
const sideRows = () => [...document.querySelectorAll('#sideChoices .gp-side-row')];
const sideRow = name => sideRows().find(row => row.textContent.includes(name));
const stepValue = row => Number(row.querySelector('.gp-side-qty .n').textContent);
const step = (row, dir) => click(row.querySelector(`[data-side-step][data-d="${dir}"]`));
const sheetLines = () => [...document.querySelectorAll('#sheetLines .cart-line')];
const variantsOf = index => [...document.querySelectorAll(`#sheetLines input[name="items[${index}][variants][]"]`)].map(input => Number(input.value));
const cartKeyed = () => Object.keys(window.__cart);
const total = () => $('#sheetTotal').textContent.trim();

// ── the page itself ─────────────────────────────────────────────────────────
console.log('guest portal dining — side dishes');
ok('the menu card publishes its side dishes', BLADE.includes('data-variants="{{ json_encode($i->variants->map('));
ok('the menu card publishes the dish\'s own rule', BLADE.includes("data-side-required=\"{{ $i->side_dish_required ? '1' : '0' }}\""));
ok('the card offers the menu rather than a plain ADD when a side dish exists', BLADE.includes("Served with a side dish{{ $i->side_dish_required ? ' – please choose one' : '' }}"));
ok('a side-dish sheet exists beside the options sheet', BLADE.includes('id="sideSheet"') && BLADE.includes('id="sideChoices"'));
ok('the guest\'s past orders read the side dishes back', BLADE.includes('PosMenuVariantService::describe('));
ok('every portion is posted, not every dish', (BLADE.match(/name="items\[\$\{i\}\]\[variants\]\[\]"/g) || []).length === 1);

// ── a dish that must come with a side dish ──────────────────────────────────
click(cardEl(11).querySelector('.qm-add'));
ok('ADD opens the side-dish sheet', sheetOpen());
ok('both side dishes are offered', sideRows().length === 2, `${sideRows().length} rows`);
ok('a required side dish cannot be skipped', $('#sideAdd').disabled === true && $('#sideAdd').textContent === 'Choose a side dish');
ok('the sheet says why', $('#sideHint').textContent.includes('choose at least one'));
ok('the free side dish reads as included', sideRow('Garden salad').textContent.includes('included'));
ok('the paid side dish shows its price', sideRow('French fries').textContent.includes('+25.00'));

step(sideRow('French fries'), 1);
ok('+ ticks the side dish and counts one', stepValue(sideRow('French fries')) === 1 && sideRow('French fries').querySelector('input[type=checkbox]').checked);
ok('− is available once counted', sideRow('French fries').querySelector('[data-d="-1"]').disabled === false);
step(sideRow('French fries'), 1);
ok('the same side dish can be counted twice', stepValue(sideRow('French fries')) === 2);
ok('the sheet stays open while the count is set', sheetOpen());
ok('a chosen side dish unlocks the order', $('#sideAdd').disabled === false && $('#sideAdd').textContent === 'Add to order');

click($('#sideAdd'));
ok('the sheet closes on add', !sheetOpen());
ok('the dish lands in the order', sheetLines().length === 1);
ok('the order names the side dish twice, unit by unit', JSON.stringify(variantsOf(0)) === '[102,102]', JSON.stringify(variantsOf(0)));
ok('the line reads “French fries ×2”', sheetLines()[0].textContent.includes('French fries ×2'));
ok('the double portion is charged twice', total() === '85.00', total());

// ── a second portion of the same combination joins the first line ───────────
click(cardEl(11).querySelector('.qm-add'));
step(sideRow('French fries'), 1);
step(sideRow('French fries'), 1);
click($('#sideAdd'));
ok('the same dish and the same side dishes stay one line', sheetLines().length === 1 && cartKeyed().length === 1);
ok('two portions still post two units a portion', JSON.stringify(variantsOf(0)) === '[102,102]' && document.querySelector('#sheetLines input[name="items[0][qty]"]').value === '2');
ok('two portions are charged twice over', total() === '170.00', total());

// ── one portion and two portions are different lines ────────────────────────
click(cardEl(11).querySelector('.qm-add'));
step(sideRow('Garden salad'), 1);
click($('#sideAdd'));
ok('a different side dish is its own line', sheetLines().length === 2);
ok('the free side dish costs nothing', variantsOf(1).length === 1 && total() === '205.00', total());

// ── a side dish that is not owed may be skipped ─────────────────────────────
click(cardEl(12).querySelector('.qm-add'));
ok('the sheet opens for an optional side dish too', sheetOpen());
ok('an optional side dish can be skipped', $('#sideAdd').disabled === false && $('#sideAdd').textContent === 'Add without a side dish');
ok('“No side dish” is offered only when it may be declined', $('#sideSkip').style.display !== 'none');
click($('#sideAdd'));
ok('a dish added without a side dish posts none', JSON.stringify(variantsOf(2)) === '[]', JSON.stringify(variantsOf(2)));
ok('the plain dish is its own line', sheetLines().length === 3);

// ── side dishes and options on one dish ─────────────────────────────────────
click(cardEl(13).querySelector('.qm-add'));
step(sideRow('Extra shito'), 1);
ok('the extra is charged for what it is', sideRow('Extra shito').textContent.includes('+5.00'));
click($('#sideAdd'));
ok('options follow the side dish', $('#modifierSheet').classList.contains('show') && !sheetOpen());
click(document.querySelector('#modifierChoices input[data-modifier-id]'));
click($('#modifierAdd'));
const line = sheetLines().length - 1;
ok('options and side dishes arrive together', JSON.stringify(variantsOf(line)) === '[103]' && document.querySelectorAll(`#sheetLines input[name="items[${line}][modifiers][]"]`).length === 1);
ok('both are charged', total() === '310.00', total());

// ── a dish with neither keeps its stepper ───────────────────────────────────
click(cardEl(14).querySelector('.qm-add'));
ok('a plain dish is added without a sheet', !sheetOpen() && sheetLines().length === 5);
ok('it carries no side dishes', JSON.stringify(variantsOf(4)) === '[]');
ok('and keeps the − / + control', document.querySelectorAll('.qm-item[data-id="14"] .qty [data-d]').length === 2);

// ── the count is bounded ────────────────────────────────────────────────────
click(cardEl(12).querySelector('.qm-add'));
for (let i = 0; i < 12; i += 1) step(sideRow('Garden salad'), 1);
ok('a side dish is capped at nine portions', stepValue(sideRow('Garden salad')) === 9, String(stepValue(sideRow('Garden salad'))));
ok('+ is spent at the cap', sideRow('Garden salad').querySelector('[data-d="1"]').disabled === true);
click(sideRow('Garden salad').querySelector('input[type=checkbox]'));
ok('unticking the box clears the count', stepValue(sideRow('Garden salad')) === 0);
click($('#sideSkip'));
ok('and the sheet can be left alone', !sheetOpen());

// ── the server side ────────────────────────────────────────────────────────
const service = read('app/Services/GuestPortalService.php');
const controller = read('app/Http/Controllers/GuestPortalController.php');
ok('the room-service order loads the side dishes', service.includes("MenuItem::with(['variants', 'modifierGroups'"));
ok('the snapshot prices them beside the add-ons', service.includes('PosMenuVariantService::snapshot($m, $l[\'variants\'] ?? [], $index)'));
ok('a dish that owes a side dish cannot be sent without one', service.includes('if ($requireSides && $m->side_dish_required && empty($l[\'variants\']))'));
ok('the rule is asked for by the caller that can offer the choice', service.includes('bool $requireSides = false'));
ok('the portal asks for it', controller.includes("GuestPortalService::orderDining($stay, $data['items'], null, 'portal', true)"));
ok('the room TV, which cannot offer a side dish, does not', read('app/Services/IptvService.php').includes("GuestPortalService::orderDining($stay, $clean, null, 'tv')"));
ok('the portal validates the side dishes like the till does', controller.includes("'items.*.variants.*' => 'integer|owned:menu_item_variants'"));

console.log(failures ? `\n${failures} assertion(s) failed` : '\nguest portal dining: OK');
process.exit(failures ? 1 : 0);
