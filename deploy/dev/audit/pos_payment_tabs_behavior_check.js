#!/usr/bin/env node
/** Exercise the actual POS payment-tab script with live-style payment mode options. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const view = fs.readFileSync(path.join(root, 'resources/views/pos/terminal.blade.php'), 'utf8');
const start = view.indexOf("const posSingleMode = document.querySelector('#settleModal select[name=\"payment_mode_id\"]');");
const end = view.indexOf('let allocationSeq = 0;', start);
assert.ok(start >= 0 && end > start, 'Expected payment tab synchronization and click handlers');
const source = view.slice(start, end);

class StubEvent { constructor(type, options) { this.type = type; Object.assign(this, options); } }
const options = [
  {value: '1', textContent: 'Cash'},
  {value: '3', textContent: 'Bank Transfer'},
  {value: '2', textContent: 'Credit / Debit Card'},
  {value: '4', textContent: 'Mobile Money (MoMo)'},
  {value: '9', textContent: 'Cheque'},
];
const modeHandlers = {};
const mode = {
  value: '1', options,
  get selectedOptions() { return options.filter(option => option.value === this.value); },
  addEventListener(type, handler) { (modeHandlers[type] ||= []).push(handler); },
  dispatchEvent(event) { for (const handler of modeHandlers[event.type] || []) handler(event); },
};
const controls = [{disabled: true, required: false}, {disabled: true, required: false}];
const momoFields = {hidden: true, querySelectorAll: () => controls};
const tabs = ['cash', 'momo', 'card'].map(category => ({
  dataset: {posPaymentTab: category},
  disabled: false,
  pressed: 'false',
  handlers: {},
  setAttribute(name, value) { if (name === 'aria-pressed') this.pressed = value; },
  addEventListener(type, handler) { this.handlers[type] = handler; },
  click() { this.handlers.click(); },
}));
const document = {
  querySelector: () => mode,
  querySelectorAll: () => tabs,
  getElementById: id => id === 'stlMomoFields' ? momoFields : null,
};
const context = {document, Event: StubEvent};
vm.createContext(context);
vm.runInContext(source, context);

assert.equal(tabs.find(tab => tab.dataset.posPaymentTab === 'cash').pressed, 'true', 'Cash is selected from the default cash mode');
assert.equal(momoFields.hidden, true);
tabs.find(tab => tab.dataset.posPaymentTab === 'momo').click();
assert.equal(mode.value, '4', 'MoMo tab selects the live Mobile Money option');
assert.equal(tabs.find(tab => tab.dataset.posPaymentTab === 'momo').pressed, 'true');
assert.equal(momoFields.hidden, false);
assert.ok(controls.every(control => control.required && !control.disabled), 'MoMo network and number become required and enabled');
tabs.find(tab => tab.dataset.posPaymentTab === 'card').click();
assert.equal(mode.value, '2', 'Card tab selects the live card option');
assert.equal(momoFields.hidden, true);
assert.ok(controls.every(control => !control.required && control.disabled));
mode.value = '3';
mode.dispatchEvent(new StubEvent('change', {bubbles: true}));
assert.ok(tabs.every(tab => tab.pressed === 'false'), 'Other configured methods remain selectable without being mislabelled');
assert.ok(options.some(option => option.textContent === 'Bank Transfer'), 'Other live payment modes remain in the native select');

const splitOptions = options.map(option => ({...option}));
const splitHandlers = {};
const splitMode = {
  value: '1', options: splitOptions,
  get selectedOptions() { return splitOptions.filter(option => option.value === this.value); },
  addEventListener(type, handler) { (splitHandlers[type] ||= []).push(handler); },
  dispatchEvent(event) { for (const handler of splitHandlers[event.type] || []) handler(event); },
};
const splitTabs = ['cash', 'momo', 'card'].map(category => ({
  dataset: {posPaymentTab: category}, disabled: false, pressed: 'false', handlers: {},
  setAttribute(name, value) { if (name === 'aria-pressed') this.pressed = value; },
  addEventListener(type, handler) { this.handlers[type] = handler; },
  click() { this.handlers.click(); },
}));
context.bindPosAllocationPaymentTabs({
  querySelector: () => splitMode,
  querySelectorAll: () => splitTabs,
});
splitTabs.find(tab => tab.dataset.posPaymentTab === 'momo').click();
assert.equal(splitMode.value, '4', 'Split-allocation MoMo tab selects the live MoMo mode');
assert.equal(splitTabs.find(tab => tab.dataset.posPaymentTab === 'momo').pressed, 'true');
splitMode.value = '3';
splitMode.dispatchEvent(new StubEvent('change', {bubbles: true}));
assert.ok(splitTabs.every(tab => tab.pressed === 'false'), 'Other modes remain unclassified in split allocations too');

console.log('POS payment tabs passed: single and split tenders select Cash, MoMo and Card live modes, preserve other configured methods, and keep MoMo fields synchronized.');
