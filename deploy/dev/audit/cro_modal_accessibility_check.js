#!/usr/bin/env node
// Exercise the CRO dialog focus loop without requiring a browser runtime.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const listeners = {};
const controls = [];
const trigger = {
  dataset: { modal: 'croLogModal' },
  isConnected: true,
  focused: false,
  focus() { trigger.focused = true; document.activeElement = trigger; },
};
const dialog = {
  classList: { contains: name => name === 'modal' || (name === 'show' && dialog.visible) },
  visible: true,
  contains(node) { return controls.includes(node); },
  querySelector() { return { querySelectorAll() { return controls; } }; },
  querySelectorAll() { return controls; },
  focus() { document.activeElement = dialog; },
};
for (const name of ['first', 'last']) {
  controls.push({ name, readOnly: false, getClientRects: () => [1], focus() { document.activeElement = this; } });
}
const document = {
  activeElement: null,
  addEventListener(name, handler) { listeners[name] = handler; },
  getElementById(id) { return id === 'croLogModal' ? dialog : null; },
};
const context = {
  document,
  window: { setTimeout(callback) { callback(); } },
  requestAnimationFrame(callback) { callback(); },
};
vm.runInNewContext(fs.readFileSync('public/js/cro-modal-accessibility.js', 'utf8'), context);

const openerClick = {
  target: {
    closest(selector) { return selector === '[data-modal]' ? trigger : null; },
    classList: { contains: () => false },
  },
};
listeners.click(openerClick);
assert.equal(document.activeElement, controls[0], 'opening a dialog focuses its first editable control');

let prevented = false;
document.activeElement = controls[controls.length - 1];
listeners.keydown({ key: 'Tab', shiftKey: false, preventDefault() { prevented = true; } });
assert.equal(prevented, true, 'Tab at the final control is trapped');
assert.equal(document.activeElement, controls[0], 'Tab wraps to the first control');

prevented = false;
document.activeElement = controls[0];
listeners.keydown({ key: 'Tab', shiftKey: true, preventDefault() { prevented = true; } });
assert.equal(prevented, true, 'Shift+Tab at the first control is trapped');
assert.equal(document.activeElement, controls[controls.length - 1], 'Shift+Tab wraps to the final control');

listeners.keydown({ key: 'Escape' });
assert.equal(document.activeElement, trigger, 'Escape returns focus to the opener');

trigger.focused = false;
listeners.click(openerClick);
const closeClick = {
  target: {
    closest(selector) { return selector === '[data-close]' ? { closest: () => dialog } : null; },
    classList: { contains: () => false },
  },
};
listeners.click(closeClick);
assert.equal(document.activeElement, trigger, 'the close control returns focus to its opener');
console.log('CRO dialog accessibility: 5 interaction checks passed.');
