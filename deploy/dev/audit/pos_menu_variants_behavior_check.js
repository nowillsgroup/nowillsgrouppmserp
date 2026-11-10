#!/usr/bin/env node
/** Exercise POS draft-line grouping and additive rates for selected side dishes. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '../../..');
const view = fs.readFileSync(path.join(root, 'resources/views/pos/terminal.blade.php'), 'utf8');
const script = view.match(/function addMenuItem\(button, modifiers = \[\], variants = \[\]\) \{[\s\S]*?(?=\nfunction openModifierPicker)/)?.[0];
assert.ok(script, 'Expected POS addMenuItem implementation');

const context = {
  cart: {},
  guestAllergies: [],
  allergenNames: {},
  confirm: () => true,
  render() {},
  window: {setTimeout() {}},
  // The slice also wires the side-dish Continue button, so a DOM stub is needed here.
  document: {getElementById: () => null, querySelectorAll: () => []},
};
vm.createContext(context);
vm.runInContext(script, context);

const button = {
  dataset: {id: '77', name: 'Grilled chicken', price: '60.00', allergens: '', may: '', printer: 'Kitchen', img: ''},
  classList: {remove() {}, add() {}},
  offsetWidth: 1,
};
const sides = [
  {id: 12, name: 'Fries', rate: 12.5},
  {id: 18, name: 'Garden salad', rate: 8},
];
context.addMenuItem(button, [], sides);
let lines = Object.values(context.cart);
assert.equal(lines.length, 1);
assert.equal(lines[0].price, 80.5, 'Both selected side-dish rates add to the base item price');
assert.deepEqual(lines[0].variants.map(v => v.id), [12, 18]);
context.addMenuItem(button, [], sides);
lines = Object.values(context.cart);
assert.equal(lines.length, 1, 'The same item + side combination shares a draft line');
assert.equal(lines[0].qty, 2);

context.addMenuItem(button, [], [sides[0]]);
lines = Object.values(context.cart);
assert.equal(lines.length, 2, 'Different side selections remain separate order lines');
assert.equal(lines.find(line => line.variants.length === 1).price, 72.5);

// A side dish saved with no rate (rate and cost are optional in the master form) must never invent a charge.
const zeroContext = {
  cart: {}, guestAllergies: [], allergenNames: {}, confirm: () => true,
  render() {}, window: {setTimeout() {}},
  document: {getElementById: () => null, querySelectorAll: () => []},
};
vm.createContext(zeroContext);
vm.runInContext(script, zeroContext);
zeroContext.addMenuItem(button, [], [{id: 20, name: 'Garden salad', rate: 0}]);
const zeroLine = Object.values(zeroContext.cart)[0];
assert.equal(zeroLine.price, 60, 'A side dish with a blank rate adds nothing to the item price');
assert.deepEqual([...zeroLine.variants.map(v => v.id)], [20], 'The side dish is still attached to the line');

const pickerCode = view.match(/function openModifierPicker\(button, groups, variants = \[\][^)]*\) \{[\s\S]*?(?=\ndocument\.querySelectorAll\('\.pos-item'\))/)?.[0];
assert.ok(pickerCode, 'Expected POS side-dish picker and selection handler');
let onAdd;
let onSideContinue;
const sideModal = {hidden: false, classList: {add() {sideModal.hidden = false;}, remove() {sideModal.hidden = true;}}};
const modal = {hidden: false, classList: {add() {modal.hidden = false;}, remove() {modal.hidden = true;}}};
const pickerElements = {
  modifierTitle: {textContent: ''},
  modifierChoices: {innerHTML: ''},
  modifierModal: modal,
  modifierAdd: {addEventListener(_event, handler) {onAdd = handler;}},
  sideDishTitle: {textContent: ''},
  sideDishChoices: {innerHTML: ''},
  sideDishModal: sideModal,
  sideDishContinue: {addEventListener(_event, handler) {onSideContinue = handler;}},
};
const rowFor = () => ({querySelector: () => ({textContent: '1'})});
const selectedInputs = sides.map(v => ({dataset: {sideVariantId: String(v.id), sideVariantName: v.name, sideVariantRate: String(v.rate)}, closest: rowFor}));
const pickerContext = {
  cart: {}, guestAllergies: [], allergenNames: {}, confirm: () => true,
  render() {}, window: {setTimeout() {}},
  posCurrencySymbol: '₵',
  esc: value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'),
  alert(message) { throw new Error(message); },
  document: {
    getElementById: id => pickerElements[id],
    querySelectorAll: selector => selector.includes('data-side-variant-id') ? selectedInputs : [],
    // The extracted region wires a delegated click handler through document.addEventListener.
    addEventListener: () => {},
  },
};
vm.createContext(pickerContext);
vm.runInContext(script + String.fromCharCode(10) + pickerCode, pickerContext);
pickerContext.openModifierPicker(button, [], sides);
assert.equal((pickerElements.modifierChoices.innerHTML.match(/type="checkbox" data-side-variant-id=/g) || []).length, 2, 'Picker renders a multi-select checkbox for every side dish');
assert.equal(modal.hidden, false);
onAdd();
const pickedLine = Object.values(pickerContext.cart)[0];
assert.equal(pickedLine.price, 80.5, 'Selecting both checkboxes adds both rates');
assert.deepEqual([...pickedLine.variants.map(v => v.id)], [12, 18]);
assert.equal(modal.hidden, true, 'Adding selections closes the picker');

// A dish with sides asks for them first, in its own popup.
pickerContext.cart = {};
pickerContext.openSideDishPicker(button, [], sides);
assert.equal((pickerElements.sideDishChoices.innerHTML.match(/type="checkbox" data-side-variant-id=/g) || []).length, 2,
  'The side-dish popup offers one checkbox per side dish');
assert.match(pickerElements.sideDishTitle.textContent, /Side dish for Grilled chicken/);
assert.equal(sideModal.hidden, false, 'Tapping a dish with sides opens the side-dish popup');
onSideContinue();
const sideFlowLine = Object.values(pickerContext.cart)[0];
assert.equal(sideModal.hidden, true, 'Continuing closes the side-dish popup');
assert.equal(sideFlowLine.price, 80.5, 'Continuing with no modifiers puts the chosen sides straight into the cart');
assert.deepEqual([...sideFlowLine.variants.map(v => v.id)], [12, 18]);

// With modifier groups too, the side step hands over to the options step with the sides pre-ticked.
pickerContext.cart = {};
pickerContext.openSideDishPicker(button, [{id: 5, name: 'Spice', min: 0, max: 2, required: false, options: []}], sides);
onSideContinue();
assert.equal(sideModal.hidden, true, 'The side step closes before the options step opens');
assert.equal((pickerElements.modifierChoices.innerHTML.match(/checked/g) || []).length, 2,
  'Sides chosen in the side step arrive pre-ticked in the options step');
assert.equal(modal.hidden, false, 'The options step opens after the side step');

console.log('POS side-dish behavior passed: dedicated side-dish popup on tap, optional (blank) rates adding nothing, multi-select picker, additive rates, identical-combination quantity grouping, separate lines for different combinations, and pre-ticked sides carried into the options step.');
