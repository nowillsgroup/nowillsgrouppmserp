#!/usr/bin/env node
/** Exercise the guest QR menu: a dish with side dishes asks for the side before it joins the order. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '../../..');
const view = fs.readFileSync(path.join(root, 'resources/views/public/qrmenu.blade.php'), 'utf8');

const start = view.indexOf("const fmt = v => cur + ' ' + v.toFixed(2);");
assert.ok(start > 0, 'Expected the QR menu cart helpers');
const end = view.indexOf('\nfunction draw(){', start);
assert.ok(end > start, 'Expected the QR menu draw() boundary');
const script = view.slice(start, end);

// Top-level wiring the slice performs on load: the sheet buttons.
let onSideContinue;
let onModifierAdd;
const sideSheet = {hidden: false, classList: {add() {sideSheet.hidden = false;}, remove() {sideSheet.hidden = true;}}};
const modifierSheet = {hidden: false, classList: {add() {modifierSheet.hidden = false;}, remove() {modifierSheet.hidden = true;}}};
const elements = {
  sideTitle: {textContent: ''},
  sideChoices: {innerHTML: ''},
  sideSheet,
  sideContinue: {addEventListener(_event, handler) {onSideContinue = handler;}},
  modifierTitle: {textContent: ''},
  modifierChoices: {innerHTML: ''},
  modifierSheet,
  modifierAdd: {addEventListener(_event, handler) {onModifierAdd = handler;}},
};
const sides = [
  {id: 12, name: 'Fries', rate: 12.5},
  {id: 18, name: 'Garden salad', rate: 0},
];
let checkedSides = [];
const context = {
  cart: {},
  cur: 'GH₵',
  draw() {},
  alert(message) { throw new Error(message); },
  document: {
    getElementById: id => elements[id],
    querySelectorAll: selector => (selector.includes('data-side-id') ? checkedSides : []),
  },
};
vm.createContext(context);
vm.runInContext(script, context);

const tile = (sidesJson, modifiersJson = '[]') => ({dataset: {id: '77', name: 'Grilled chicken', price: '60', variants: sidesJson, modifiers: modifiersJson}});

// 1. A dish with sides opens the side sheet, and a blank rate reads "included", never a charge.
context.openSides(tile(JSON.stringify(sides)));
assert.equal(sideSheet.hidden, false, 'Tapping a dish with sides opens the side-dish sheet');
assert.match(elements.sideTitle.textContent, /Side dish for Grilled chicken/);
assert.equal((elements.sideChoices.innerHTML.match(/type="checkbox" data-side-id=/g) || []).length, 2,
  'The guest sheet offers one checkbox per side dish');
assert.match(elements.sideChoices.innerHTML, /\+GH₵ 12\.50/, 'A priced side dish shows its rate');
assert.match(elements.sideChoices.innerHTML, /Garden salad<\/span> <span class="tiny muted">included<\/span>/,
  'A side dish with no rate is shown as included');
assert.match(elements.sideChoices.innerHTML, /Tick one or more, or continue with none\./);

// 2. Continuing with none is allowed and adds the dish at its own price.
onSideContinue();
assert.equal(sideSheet.hidden, true, 'Continuing closes the side sheet');
let lines = Object.values(context.cart);
assert.equal(lines.length, 1);
assert.equal(lines[0].price, 60, 'Continuing with no side dish charges only the dish');
assert.deepEqual([...lines[0].sides], [], 'No side dish means an empty side list');

// 3. Ticking sides adds their rates, and a 0-rate side still rides with the line.
context.cart = {};
context.openSides(tile(JSON.stringify(sides)));
checkedSides = sides.map(v => ({dataset: {sideId: String(v.id), sideName: v.name, sideRate: String(v.rate)}, closest: () => ({querySelector: () => ({textContent: '1'})})}));
onSideContinue();
lines = Object.values(context.cart);
assert.equal(lines[0].price, 72.5, 'Only the priced side dish adds to the dish price');
assert.deepEqual([...lines[0].sides.map(v => v.id)], [12, 18], 'The included side dish is still sent with the line');

// 4. The same dish with different sides stays a separate line.
checkedSides = [{dataset: {sideId: '12', sideName: 'Fries', sideRate: '12.5'}, closest: () => ({querySelector: () => ({textContent: '1'})})}];
context.openSides(tile(JSON.stringify(sides)));
onSideContinue();
assert.equal(Object.values(context.cart).length, 2, 'A different side selection is its own line');

// 5. A dish with options too: the sides come pre-ticked into the options sheet and are kept.
context.cart = {};
const groups = JSON.stringify([{id: 5, name: 'Spice', min: 0, max: 2, required: false, options: []}]);
checkedSides = [{dataset: {sideId: '18', sideName: 'Garden salad', sideRate: '0'}, closest: () => ({querySelector: () => ({textContent: '1'})})}];
context.openSides(tile(JSON.stringify(sides), groups));
onSideContinue();
assert.equal(sideSheet.hidden, true, 'The side sheet closes before the options sheet opens');
assert.equal(modifierSheet.hidden, false, 'The options sheet opens after the side step');
assert.equal((elements.modifierChoices.innerHTML.match(/checked/g) || []).length, 1,
  'The side chosen above arrives pre-ticked in the options sheet');
assert.match(elements.modifierChoices.innerHTML, /Chosen above — change if needed\./);
checkedSides = [{dataset: {sideId: '18', sideName: 'Garden salad', sideRate: '0'}, closest: () => ({querySelector: () => ({textContent: '1'})})}];
onModifierAdd();
lines = Object.values(context.cart);
assert.equal(lines.length, 1, 'Adding from the options sheet keeps one line');
assert.deepEqual([...lines[0].sides.map(v => v.id)], [18], 'The pre-ticked side dish survives the options step');
assert.equal(lines[0].price, 60, 'An included side dish adds nothing');

// 6. The server contract the sheet posts to.
const controller = fs.readFileSync(path.join(root, 'app/Http/Controllers/QrMenuController.php'), 'utf8');
assert.match(controller, /'items\.\*\.variants' => 'nullable\|array\|max:30', 'items\.\*\.variants\.\*' => 'integer\|owned:menu_item_variants'/,
  'Guest side-dish ids are validated against the tenant');
assert.match(controller, /\[\$sideDishSnapshots, \$sideDishAmount\] = PosMenuVariantService::snapshot\(\$m, \$line\['variants'\] \?\? \[\], \$index\);/,
  'Guest side dishes go through the same snapshot service as the terminal');
assert.match(controller, /\$modifierSnapshots = array_merge\(\$modifierSnapshots, \$sideDishSnapshots\);/,
  'Side dishes are merged into the stored add-on list so the KOT and bill show them');
assert.match(controller, /MenuItem::with\(\['variants', 'modifierGroups'/,
  'The guest menu loads each dish’s side dishes');

assert.match(view, /name="items\[\$\{i\}\]\[variants\]\[\]"/, 'The order form posts the chosen side-dish ids');
assert.equal((view.match(/id="sideSheet"/g) || []).length, 1, 'The guest menu has exactly one side-dish sheet');
assert.match(view, /data-d="1">\+<\/button><\/div>` : '<button type="button" class="qm-add">ADD<\/button>'; \}/, 'Plain dishes keep their stepper');
assert.match(view, /const key = id \+ '::s:';/, 'The plain-dish stepper uses the side-aware cart key');

console.log('Guest QR menu side-dish behavior passed: side sheet before the order, none allowed, included (0-rate) sides attached at no charge, options step pre-ticked, and side ids validated and stored with the line.');
