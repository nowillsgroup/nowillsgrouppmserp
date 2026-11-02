#!/usr/bin/env node
// Run with: node deploy/dev/audit/payment_momo_behavior_check.js
// Execute the Blade component's actual script against a tiny DOM harness and
// verify MoMo fields hide/disable for other methods and reveal/require for MoMo.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const blade = fs.readFileSync('resources/views/components/payment-momo-fields.blade.php', 'utf8');
const script = blade.match(/<script>([\s\S]*?)<\/script>/)?.[1];
assert.ok(script, 'payment-momo-fields must contain its visibility script');

const controls = [{disabled: true, required: false}, {disabled: true, required: false}];
const listeners = {};
const mode = {
  name: 'advance_mode',
  selectedOptions: [{textContent: 'Cash'}],
  addEventListener: (name, callback) => { listeners[name] = callback; },
};
const form = {querySelectorAll: selector => selector === 'select' ? [mode] : []};
const fields = {
  dataset: {momoModeName: 'advance_mode'},
  hidden: true,
  closest: selector => selector === 'form' ? form : null,
  querySelectorAll: selector => selector === 'select, input' ? controls : [],
};
const document = {querySelectorAll: selector => selector === '[data-momo-fields]' ? [fields] : []};
vm.runInNewContext(script, {document});

assert.equal(fields.hidden, true, 'MoMo fields start hidden for Cash');
assert.ok(controls.every(control => control.disabled && !control.required), 'hidden MoMo controls start disabled and optional');
assert.equal(typeof listeners.change, 'function', 'payment selector listens for changes');

mode.selectedOptions = [{textContent: 'Mobile Money (MoMo)'}];
listeners.change();
assert.equal(fields.hidden, false, 'MoMo fields show when Mobile Money is selected');
assert.ok(controls.every(control => !control.disabled && control.required), 'MoMo controls become enabled and required');

mode.selectedOptions = [{textContent: 'Credit / Debit Card'}];
listeners.change();
assert.equal(fields.hidden, true, 'MoMo fields hide again when a different method is selected');
assert.ok(controls.every(control => control.disabled && !control.required), 'hidden MoMo controls are disabled and optional again');

console.log('PASS: single-payment MoMo fields follow the selected method in both directions');

// Also exercise the shared split-tender component's automatic single-tender
// enhancement, used on Finance and operational payment forms.
const splitBlade = fs.readFileSync('resources/views/components/payment-split-tender.blade.php', 'utf8');
const start = splitBlade.indexOf('    const singleMode =');
const end = splitBlade.indexOf('    let oldLines = [];', start);
assert.ok(start >= 0 && end > start, 'shared split-tender single-MoMo enhancement must exist');
const controlsForSplit = [
  {name: 'momo_network', disabled: true, required: false, closest: () => ({querySelector: () => ({setAttribute() {}})})},
  {name: 'momo_phone', disabled: true, required: false, closest: () => ({querySelector: () => ({setAttribute() {}})})},
];
const splitModeListeners = {};
const splitMode = {
  name: 'payment_mode_id',
  selectedOptions: [{textContent: 'Cash'}],
  addEventListener: (name, callback) => { splitModeListeners[name] = callback; },
};
let insertedFields = null;
const referenceAnchor = {before: fields => { insertedFields = fields; }};
const splitSingle = {
  querySelector(selector) {
    if (selector.startsWith('select[')) return splitMode;
    if (selector === '[name="reference"],[name="advance_ref"]') return {closest: () => referenceAnchor};
    return null;
  },
  append(fields) { insertedFields = fields; },
};
const singleMomoGroup = {
  hidden: true,
  querySelector(selector) { return controlsForSplit.find(control => selector.includes(control.name)); },
  querySelectorAll() { return controlsForSplit; },
};
const splitWidget = {
  id: 'receiptSplit',
  querySelector: selector => selector === '[data-single-momo-template]' ? {
    content: {firstElementChild: {cloneNode: () => singleMomoGroup}},
  } : null,
};
const splitContext = {
  form: {}, single: splitSingle, widget: splitWidget, singleMomoTemplate: splitWidget.querySelector('[data-single-momo-template]'),
};
const splitResult = vm.runInNewContext(`(() => { ${splitBlade.slice(start, end)} return {singleMomo, singleMode}; })()`, splitContext);
assert.equal(insertedFields, splitResult.singleMomo, 'shared component inserts MoMo fields immediately before the reference');
assert.equal(splitResult.singleMomo.hidden, true, 'shared single-tender fields start hidden for Cash');
assert.ok(controlsForSplit.every(control => control.disabled && !control.required), 'shared hidden controls are disabled and optional');
splitMode.selectedOptions = [{textContent: 'Mobile Money (MoMo)'}];
splitModeListeners.change();
assert.equal(splitResult.singleMomo.hidden, false, 'shared single-tender fields show for Mobile Money');
assert.ok(controlsForSplit.every(control => !control.disabled && control.required), 'shared MoMo controls become enabled and required');
splitMode.selectedOptions = [{textContent: 'Cash'}];
splitModeListeners.change();
assert.equal(splitResult.singleMomo.hidden, true, 'shared single-tender fields hide again for Cash');
assert.ok(controlsForSplit.every(control => control.disabled && !control.required), 'shared controls disable again for non-MoMo');
console.log('PASS: shared split-tender single-payment enhancement hides, reveals, and places MoMo fields correctly');
