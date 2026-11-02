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

const pickerCode = view.match(/function openModifierPicker\(button, groups, variants = \[\]\) \{[\s\S]*?(?=\ndocument\.querySelectorAll\('\.pos-item'\))/)?.[0];
assert.ok(pickerCode, 'Expected POS side-dish picker and selection handler');
let onAdd;
const modal = {hidden: false, classList: {add() {}, remove() {modal.hidden = true;}}};
const pickerElements = {
  modifierTitle: {textContent: ''},
  modifierChoices: {innerHTML: ''},
  modifierModal: modal,
  modifierAdd: {addEventListener(_event, handler) {onAdd = handler;}},
};
const selectedInputs = sides.map(v => ({dataset: {sideVariantId: String(v.id), sideVariantName: v.name, sideVariantRate: String(v.rate)}}));
const pickerContext = {
  cart: {}, guestAllergies: [], allergenNames: {}, confirm: () => true,
  render() {}, window: {setTimeout() {}},
  posCurrencySymbol: '₵',
  esc: value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'),
  alert(message) { throw new Error(message); },
  document: {
    getElementById: id => pickerElements[id],
    querySelectorAll: selector => selector.includes('data-side-variant-id') ? selectedInputs : [],
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

console.log('POS side-dish behavior passed: multi-select picker, additive rates, identical-combination quantity grouping, and separate lines for different combinations.');
