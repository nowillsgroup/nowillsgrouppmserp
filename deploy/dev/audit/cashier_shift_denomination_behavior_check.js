#!/usr/bin/env node
/** Keep counted cash synchronized after denomination counting starts, including when reset to zero. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '../../..');
const view = fs.readFileSync(path.join(root, 'resources/views/shifts/index.blade.php'), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)?.[1];
assert.ok(script, 'Expected cashier shift denomination script');

const listeners = new Map();
const denominations = [200, 100, 50].map((denom, index) => ({
  dataset: {denom: String(denom)}, value: '0',
  addEventListener(_event, callback) { listeners.set(index, callback); },
}));
const counted = {value: '125.50'};
const form = {querySelectorAll() { return denominations; }};
const context = {
  document: {getElementById(id) { return id === 'closeShiftForm' ? form : counted; }},
};
vm.createContext(context);
vm.runInContext(script, context);

assert.equal(counted.value, '125.50', 'Manual count remains usable before denomination counting starts');
denominations[0].value = '2';
listeners.get(0)();
assert.equal(counted.value, '400.00', 'Denomination counts fill counted cash');
denominations[0].value = '0';
listeners.get(0)();
assert.equal(counted.value, '0.00', 'Clearing every denomination clears stale counted cash');
denominations[2].value = '3';
listeners.get(2)();
assert.equal(counted.value, '150.00', 'Subsequent denomination edits keep the total synchronized');

console.log('Cashier denomination behavior passed: manual count is preserved until denomination entry, then the total tracks zero and subsequent edits.');
