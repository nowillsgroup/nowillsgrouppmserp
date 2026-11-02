#!/usr/bin/env node
/** Exercise open-item billing quantity independently of the informational order covers/pax field. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '../../..');
const view = fs.readFileSync(path.join(root, 'resources/views/pos/terminal.blade.php'), 'utf8');
const handler = view.match(/document\.getElementById\('openAdd'\)\?\.addEventListener\('click', \(\) => \{[\s\S]*?(?=\nlet modifierButton)/)?.[0];
assert.ok(handler, 'Expected open-item add handler');

let onAdd;
const elements = {
  openAdd: {addEventListener(_event, callback) { onAdd = callback; }},
  openName: {value: 'Chef special'},
  openPrice: {value: '20'},
  openQty: {value: '1'},
  openCovers: {value: '3', readOnly: true},
  openStation: {value: 'Kitchen'},
  openType: {value: 'non_veg'},
  openNotes: {value: ''},
};
const context = {
  cart: {}, openSeq: 1, guestAllergies: [], allergenNames: {}, stationPrinters: {},
  render() {}, alert(message) { throw new Error(message); }, confirm() { return true; },
  document: {
    getElementById(id) { return elements[id]; },
    querySelector() { return {click() {}}; },
  },
};
vm.createContext(context);
vm.runInContext(handler, context);
onAdd();
assert.equal(context.cart.o1.qty, 1, 'Billing quantity remains independent of order covers/pax');
assert.equal(elements.openQty.value, '1', 'Quantity resets to its original default');
assert.equal(elements.openCovers.value, '3', 'The informational covers/pax value is not changed by adding an item');
assert.equal(context.cart.o1.price, 20, 'Existing unit-rate amount handling is preserved');

console.log('POS open-item behavior passed: informational covers/pax is separate from the editable billing quantity.');
