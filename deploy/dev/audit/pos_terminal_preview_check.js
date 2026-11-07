#!/usr/bin/env node
/*
 * The POS terminal, worked the way a waiter works it.
 *
 * v9.29 is the side-dish picker, and a picker is only real if it can be tapped: this check runs the shipped inline script
 * in jsdom and walks the order the guest would place.
 *
 *   1. the menu — eight dishes, four of them "with sides", each tile carrying its sides and its option groups;
 *   2. the order already open on table 12 — a served beer and a grilled chicken that reached the kitchen with its chips;
 *   3. the chain on a dish with both: Jollof Rice asks for the side dish first, the sides ticked there are carried into the
 *      option picker already ticked, the required portion is enforced, and the line lands in the cart at the price the
 *      sides and the option make — 65.00 + 25.00 + 5.00 + 15.00 = 110.00;
 *   4. a dish with sides and no options goes straight from the side picker into the cart (85.00 + 12.00 = 97.00);
 *   5. a dish with neither goes into the cart without a dialog, and the total, the per-item counts and the quantity
 *      buttons all keep up.
 *
 * The JSON written into data-variants / data-modifiers is escaped exactly once — `{{ json_encode(…) }}`, not
 * `{{ e(json_encode(…)) }}`. Double-escaped, the attribute reads `&quot;` after the browser decodes it, JSON.parse throws,
 * and the picker silently never opens. That regression is checked on every tile.
 *
 * Renders deploy/dev/previews/pos-terminal.html in jsdom.
 *
 * Run: node deploy/dev/audit/pos_terminal_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const html = readFileSync('deploy/dev/previews/pos-terminal.html', 'utf8');
const dom = new JSDOM(html, { url: 'https://preview.local/pos-terminal', runScripts: 'dangerously', pretendToBeVisual: true });
const { window } = dom;
const errors = [];
dom.virtualConsole.on('jsdomError', error => errors.push(error.message));
const alerts = [];
window.alert = message => alerts.push(String(message));
window.confirm = () => true;
await new Promise(resolve => setTimeout(resolve, 200));

const doc = window.document;
const $ = selector => doc.querySelector(selector);
const $$ = selector => [...doc.querySelectorAll(selector)];
const text = node => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const tile = name => $$('.pos-item').find(button => button.dataset.name === name);
const money = value => `GH₵ ${Number(value).toFixed(2)}`;
const cartLines = () => $$('#cartLines .cart-line').map(text);
const checkLines = () => text($('.pos-order-card .cart-lines'));
const total = () => Number(text($('#cartTotal')));

/* 1 ─ the menu ─────────────────────────────────────────────────────────────── */
const tiles = $$('.pos-item');
ok('eight dishes are on the menu', tiles.length === 8, `${tiles.length} tiles`);
const withSides = tiles.filter(button => JSON.parse(button.dataset.variants || '[]').length);
const withOptions = tiles.filter(button => JSON.parse(button.dataset.modifiers || '[]').length);
ok('four dishes come with side dishes', withSides.length === 4, `${withSides.length}`);
ok('two of them also carry option groups', withOptions.length === 2, `${withOptions.length}`);
ok('the tiles are the four sides dishes', withSides.map(b => b.dataset.name).join(' · ')
  === 'Grilled Tilapia & Banku · Jollof Rice (Special) · Banku & Okro Stew · Grilled Chicken & Chips');
ok('a tile says how many sides come with it', /3 sides/.test(text(tile('Grilled Tilapia & Banku'))));
ok('Jollof lists three sides with their rates',
  JSON.parse(tile('Jollof Rice (Special)').dataset.variants).map(v => `${v.name} ${v.rate}`).join(' · ')
  === 'Grilled chicken 25 · Fried fish 30 · Extra shito 5');
ok('the portion group is required, one of one',
  JSON.parse(tile('Jollof Rice (Special)').dataset.modifiers)[0].required === true
  && JSON.parse(tile('Jollof Rice (Special)').dataset.modifiers)[0].min === 1);
const plate = { 'Grilled Tilapia & Banku': 85, 'Jollof Rice (Special)': 65, 'Banku & Okro Stew': 58,
  'Grilled Chicken & Chips': 78, Kelewele: 20, 'Club Beer 330ml': 30, 'Malta Guinness': 25, 'Voltic Water 1.5L': 12 };
ok('every tile shows its plate price',
  Object.entries(plate).every(([name, price]) => text(tile(name)).includes(money(price))),
  Object.entries(plate).filter(([name, price]) => !text(tile(name)).includes(money(price))).map(([name]) => name).join(' · '));

/* the escaping regression: once-escaped JSON survives the browser's own decode */
const rawAttributes = [...html.matchAll(/data-(variants|modifiers)="([^"]*)"/g)].map(match => match[2]);
const carrying = rawAttributes.filter(raw => raw !== '[]');
ok('no attribute is escaped twice', carrying.every(raw => !raw.includes('&amp;quot;')),
  carrying.filter(raw => raw.includes('&amp;quot;')).length + ' double-escaped');
ok('every attribute is escaped once', carrying.every(raw => raw.includes('&quot;')));
for (const button of withSides) {
  const parsed = JSON.parse(button.dataset.variants);
  ok(`data-variants on ${button.dataset.name} parses`, Array.isArray(parsed) && parsed.length > 0);
}
ok('data-modifiers parses on the portion group', JSON.parse(tile('Jollof Rice (Special)').dataset.modifiers)[0]?.options?.length === 2);
ok('data-modifiers parses on the spice group', JSON.parse(tile('Grilled Chicken & Chips').dataset.modifiers)[0]?.options?.length === 3);

/* 2 ─ the check that is already open ───────────────────────────────────────── */
ok('the order is table 12 on the terrace', /Table 12/.test(text($('.pos-order-context'))));
ok('two pax, served by Ama Boateng', /Pax\s*2/.test(text($('.pos-order-context')))
  && /Waiter\s*Ama Boateng/.test(text($('.pos-order-context'))));
ok('the check is two lines for two covers', /2 cover\(s\) · Mr\. K\. Adjei/.test(text($('.pos-order-card')))
  && /2 lines/.test(text($('.pos-order-card'))));
ok('the kitchen summary counts what is preparing and what is served',
  /1 Preparing/.test(text($('.pos-kitchen-summary'))) && /1 Served/.test(text($('.pos-kitchen-summary'))));
ok('KOT #41 and KOT #42 are on the check', /KOT #41/.test(checkLines()) && /KOT #42/.test(checkLines()));
ok('the beer is served', /Club Beer 330ml/.test(checkLines()) && /served/.test(checkLines()));
ok('the grilled chicken reached the kitchen with its chips',
  /Grilled Chicken & Chips/.test(checkLines()) && /Side dish: Extra chips \+10\.00/.test(checkLines()));
ok('the chicken line shows the spice option it was sent with', /Medium/.test(checkLines()));
ok('the KOT groups name the printers they went to', /Bar printer/.test(checkLines()) && /Grill printer/.test(checkLines()));
ok('subtotal 148.00 and tax 22.20', text($('.list')).includes('148.00') && text($('.list')).includes('22.20'));
ok('total due 170.20', text($('.list')).includes('170.20'));
ok('no cart lines are drawn from the draft yet', cartLines().length === 0);
ok('the total starts empty', text($('#cartTotal')) === '0.00');

/* 3 ─ the side-dish chain on Jollof Rice ───────────────────────────────────── */
tile('Jollof Rice (Special)').click();
const sideModal = $('#sideDishModal');
ok('tapping a dish with sides opens the side-dish picker', sideModal.classList.contains('show'));
ok('the picker is titled for the dish', text($('#sideDishTitle')) === 'Side dish for Jollof Rice (Special)');
ok('it explains that a side is optional', /Continue with none/.test(text(sideModal)));
const sideBoxes = $$('#sideDishChoices input[type=checkbox]');
ok('the sides are checkboxes, one per side', sideBoxes.length === 3, `${sideBoxes.length}`);
ok('a charged side shows its rate', /\+GH₵ 25\.00/.test(text($('#sideDishChoices')))
  && /\+GH₵ 30\.00/.test(text($('#sideDishChoices'))) && /\+GH₵ 5\.00/.test(text($('#sideDishChoices'))));
ok('nothing is ticked to begin with', sideBoxes.every(box => !box.checked));
ok('the options picker is still closed', !$('#modifierModal').classList.contains('show'));

sideBoxes.find(box => box.dataset.sideVariantName === 'Grilled chicken').checked = true;
sideBoxes.find(box => box.dataset.sideVariantName === 'Extra shito').checked = true;
$('#sideDishContinue').click();
ok('Continue closes the side picker', !sideModal.classList.contains('show'));
const optionModal = $('#modifierModal');
ok('and carries the dish into the options picker', optionModal.classList.contains('show'));
ok('the options picker is titled for the dish', text($('#modifierTitle')) === 'Options for Jollof Rice (Special)');
const carried = $$('#modifierChoices input[data-side-variant-id]');
ok('the same sides are offered there', carried.length === 3);
ok('the two ticked sides are already ticked',
  carried.filter(box => box.checked).map(box => box.dataset.sideVariantName).join(' · ') === 'Grilled chicken · Extra shito');
ok('it says the sides came from above', /Chosen above/.test(text($('#modifierChoices'))));
ok('the portion group is shown as required', /Portion 1–1 · required/.test(text($('#modifierChoices'))));
ok('and offers Regular and Large', /Regular/.test(text($('#modifierChoices'))) && /Large \+15\.00/.test(text($('#modifierChoices'))));
ok('the cart is still empty while the picker is open', cartLines().length === 0);

/* the required portion is enforced before anything is added */
$('#modifierAdd').click();
ok('adding without the required portion is refused', cartLines().length === 0 && optionModal.classList.contains('show'));
ok('and says which group to choose', alerts.length === 1 && /Portion/.test(alerts[0]), alerts.join(' | '));

$$('#modifierChoices input[data-modifier-name]').find(box => box.dataset.modifierName === 'Large').checked = true;
$('#modifierAdd').click();
ok('Add closes the options picker', !optionModal.classList.contains('show'));
const jollofLine = cartLines().join(' | ');
ok('one line is in the cart', cartLines().length === 1, jollofLine);
ok('the line is the dish', /^Jollof Rice \(Special\)/.test(cartLines()[0]));
ok('the sides ride on the line', /Side dishes: Grilled chicken \+25\.00 · Extra shito \+5\.00/.test(jollofLine));
ok('the option rides on the line', /Large \+15\.00/.test(jollofLine));
ok('the unit price is 65.00 + 25.00 + 5.00 + 15.00', /GH₵ 110\.00 each/.test(jollofLine), jollofLine);
ok('the line amount is 110.00', /GH₵ 110\.00$/.test(jollofLine));
ok('the order total follows', text($('#cartTotal')) === '110.00');
ok('the cart count reads one item', /1 item · 1 line/.test(text($('#cartItemCount'))));
const variantInputs = $$('#cartLines input[name*="[variants][]"]').map(input => `${input.name}=${input.value}`);
ok('both sides are posted as items[0][variants][]', variantInputs.join(',') === 'items[0][variants][]=4,items[0][variants][]=6', variantInputs.join(','));
const modifierInputs = $$('#cartLines input[name*="[modifiers][]"]').map(input => `${input.name}=${input.value}`);
ok('the portion is posted as items[0][modifiers][]', modifierInputs.join(',') === 'items[0][modifiers][]=12', modifierInputs.join(','));
ok('the dish badge counts one on the tile', text($('[data-item-count="502"]')) === '1');

/* 4 ─ a dish whose side dish is required cannot be added without one ────────── */
const tilapia = tile('Grilled Tilapia & Banku');
ok('the dish that insists says so on its tile', /3 sides · required/.test(text(tilapia)), text(tilapia));
ok('a dish that does not insist says nothing', !/required/.test(text(tile('Jollof Rice (Special)'))));
ok('the rule is on the tile, not just in the words', tilapia.dataset.sidesRequired === '1'
  && tile('Jollof Rice (Special)').dataset.sidesRequired === '');

tilapia.click();
const sideSheet = $('#sideDishModal');
ok('the side picker opens for it too', sideSheet.classList.contains('show'));
ok('titled for that dish', text($('#sideDishTitle')) === 'Side dish for Grilled Tilapia & Banku');
ok('the sheet says the side is not optional', /must be served with a side dish/i.test(text($('#sideDishNote'))));
ok('no warning until it is needed', $('#sideDishWarn').hidden);
ok('an included side reads so', /included/.test(text($('#sideDishChoices'))));

$('#sideDishContinue').click();
ok('Continue with none is refused', sideSheet.classList.contains('show'), 'the sheet closed anyway');
ok('and the sheet says why', !$('#sideDishWarn').hidden);
ok('nothing was added while it insisted', cartLines().length === 1 && text($('#cartTotal')) === '110.00');

const tilapiaBoxes = $$('#sideDishChoices input[type=checkbox]');
tilapiaBoxes.find(box => box.dataset.sideVariantName === 'Garden salad').checked = true;
tilapiaBoxes.find(box => box.dataset.sideVariantName === 'Extra banku').checked = true;
$('#sideDishContinue').click();
ok('with a side ticked the sheet closes', !sideSheet.classList.contains('show'));
ok('Continue adds it without an options step', !$('#modifierModal').classList.contains('show'));
ok('two lines now', cartLines().length === 2);
ok('85.00 + 0.00 + 12.00 = 97.00', /GH₵ 97\.00 each/.test(cartLines()[1]), cartLines()[1]);
ok('both sides are named, the free one as included',
  /Side dishes: Garden salad included · Extra banku \+12\.00/.test(cartLines()[1]), cartLines()[1]);
ok('both side ids are posted', $$('#cartLines input[name*="items[1][variants]"]').map(input => input.value).join(',') === '1,2');
ok('total 207.00', text($('#cartTotal')) === '207.00');

tile('Grilled Tilapia & Banku').click();
ok('reopening the picker clears the warning', $('#sideDishWarn').hidden && sideSheet.classList.contains('show'));
$$('#sideDishModal [data-close]')[0].click();
ok('closing the sheet adds nothing', !sideSheet.classList.contains('show') && cartLines().length === 2);

/* 5 ─ a dish with neither ──────────────────────────────────────────────────── */
tile('Club Beer 330ml').click();
ok('a plain dish opens no picker', !$('#sideDishModal').classList.contains('show') && !$('#modifierModal').classList.contains('show'));
ok('it lands in the cart at 30.00', text($('#cartTotal')) === '237.00');
ok('three lines', cartLines().length === 3);
ok('three tiles show a count', $$('[data-item-count]').filter(badge => !badge.classList.contains('hidden')).length === 3);

/* the quantity buttons keep the sides attached */
const jollofRow = $$('#cartLines .cart-line').find(row => /Jollof Rice/.test(text(row)));
jollofRow.querySelector('.qty button[title="Increase quantity"]').click();
ok('a second Jollof is 220.00 on the line', /GH₵ 220\.00/.test(text($$('#cartLines .cart-line').find(row => /Jollof/.test(text(row))))));
ok('and the sides are still on it', /Side dishes: Grilled chicken \+25\.00 · Extra shito \+5\.00/.test(text($$('#cartLines .cart-line').find(row => /Jollof/.test(text(row))))));
ok('the total is now 347.00', text($('#cartTotal')) === '347.00');
ok('the cart count reads two of it', /4 items · 3 lines/.test(text($('#cartItemCount'))), text($('#cartItemCount')));

/* 7 ─ the options step cannot undo the rule ─────────────────────────────────── */
tile('Grilled Chicken & Chips').click();
ok('a dish with both a required side and a spice level asks for the side first',
  $('#sideDishModal').classList.contains('show') && /must be served with a side dish/i.test(text($('#sideDishNote'))));
$$('#sideDishChoices input[type=checkbox]').find(box => box.dataset.sideVariantName === 'Extra chips').checked = true;
$('#sideDishContinue').click();
ok('then it asks for the spice level', $('#modifierModal').classList.contains('show'));
const spiceBoxes = $$('#modifierChoices input[data-modifier-name]');
const sideBoxesInOptions = $$('#modifierChoices input[data-side-variant-id]');
ok('the side arrives ticked there', sideBoxesInOptions.filter(box => box.checked).length === 1);
spiceBoxes.find(box => box.dataset.modifierName === 'Hot').checked = true;
const beforeRefusal = cartLines().length;
sideBoxesInOptions.forEach(box => { box.checked = false; });
$('#modifierAdd').click();
ok('unticking the side in the options step is refused', cartLines().length === beforeRefusal
  && $('#modifierModal').classList.contains('show'));
ok('and says so', /side dish/.test(alerts[alerts.length - 1] || ''), alerts.join(' | '));
sideBoxesInOptions.find(box => box.dataset.sideVariantName === 'Extra chips').checked = true;
$('#modifierAdd').click();
ok('ticking it again adds the dish', cartLines().length === beforeRefusal + 1 && !$('#modifierModal').classList.contains('show'));
ok('78.00 + 10.00 = 88.00 on the line', /GH₵ 88\.00 each/.test(cartLines()[beforeRefusal]), cartLines()[beforeRefusal]);
ok('with its side and its spice', /Side dishes: Extra chips \+10\.00/.test(cartLines()[beforeRefusal])
  && /Hot/.test(cartLines()[beforeRefusal]));
ok('total 435.00', text($('#cartTotal')) === '435.00');

/* 8 ─ a side dish can be ordered twice ─────────────────────────────────────── */
tile('Banku & Okro Stew').click();
ok('every side carries a count', $$('#sideDishChoices .pos-side-row').length === 2
  && $$('#sideDishChoices [data-side-step]').length === 4);
const fishBox = $$('#sideDishChoices input[type=checkbox]').find(box => box.dataset.sideVariantName === 'Extra fish');
const okroBox = $$('#sideDishChoices input[type=checkbox]').find(box => box.dataset.sideVariantName === 'Extra okro');
const fishRow = fishBox.closest('.pos-side-row');
ok('a side starts at one', fishRow.querySelector('[data-side-count]').textContent === '1');
okroBox.checked = true;
okroBox.closest('.pos-side-row').querySelector('[data-side-step="-1"]').click();
ok('a count never falls below one', okroBox.closest('.pos-side-row').querySelector('[data-side-count]').textContent === '1');
fishRow.querySelector('[data-side-step="1"]').click();
ok('+ raises the count', fishRow.querySelector('[data-side-count]').textContent === '2');
ok('and does not close the sheet', $('#sideDishModal').classList.contains('show'));
fishRow.querySelector('[data-side-step="1"]').click();
fishRow.querySelector('[data-side-step="-1"]').click();
ok('the count comes back down', fishRow.querySelector('[data-side-count]').textContent === '2');
$('#sideDishContinue').click();
const bankuLine = cartLines()[4];
ok('the line is added', cartLines().length === 5, `${cartLines().length} lines`);
ok('58.00 + (28.00 × 2) + 8.00 = 122.00', /GH₵ 122\.00 each/.test(bankuLine), bankuLine);
ok('the doubled side shows its count and what the two make',
  /Extra fish ×2 \+56\.00/.test(bankuLine) && /Extra okro \+8\.00/.test(bankuLine), bankuLine);
ok('the request repeats the id once per portion',
  $$('#cartLines input[name*="items[4][variants]"]').map(input => input.value).join(',') === '7,7,8');
ok('total 557.00', text($('#cartTotal')) === '557.00', text($('#cartTotal')));

/* the same dish with a different count is a different line, not a second unit of the first */
tile('Banku & Okro Stew').click();
$$('#sideDishChoices input[type=checkbox]').find(box => box.dataset.sideVariantName === 'Extra fish').checked = true;
$('#sideDishContinue').click();
ok('one portion of the same dish is a line of its own', cartLines().length === 6, `${cartLines().length} lines`);
ok('58.00 + 28.00 = 86.00', /GH₵ 86\.00 each/.test(cartLines()[5]), cartLines()[5]);
ok('and it is posted as one unit', $$('#cartLines input[name*="items[5][variants]"]').map(input => input.value).join(',') === '7');
ok('total 643.00', text($('#cartTotal')) === '643.00', text($('#cartTotal')));

ok('the page ran without a script error', errors.length === 0, errors.join(' | '));

console.log(`pos-terminal preview: ${passed} passed, ${failures.length} failed`);
for (const failure of failures) console.log(`  ✗ ${failure}`);
process.exit(failures.length ? 1 : 0);
