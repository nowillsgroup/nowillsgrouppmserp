#!/usr/bin/env node
/** Verify that the preview hashes credential state, not a duplicated or missing value. */
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { fingerprintOf, configurationHistoryContext, configurationIndexContext } from '../preview/configuration-sample.mjs';
const digest = records => crypto.createHash('sha256').update(JSON.stringify(records)).digest('hex');
const key = set => [{ keys: [{ raw: 'unit_secret', secret: true, set }] }];
assert.equal(fingerprintOf(key(true)).full, digest([['unit_secret', 'credential', true]]));
assert.equal(fingerprintOf(key(false)).full, digest([['unit_secret', 'credential', false]]));
assert.notEqual(fingerprintOf(key(true)).full, fingerprintOf(key(false)).full);
// An actual sample credential that falls under a family must be masked in both records.
const family = [{ keys: [{ raw: 'inbox_', family: true, secret: false }, { raw: 'inbox_webhook_secret', secret: true, set: true }] }];
assert.equal(fingerprintOf(family).full, digest([
  ['inbox_', 'family', [['inbox_webhook_secret', ['credential', true]]]],
  ['inbox_webhook_secret', 'credential', true],
]));
const history = configurationHistoryContext().scope.changes;
const holiday = history.find(row => row.key === 'hr_holidays_2026');
assert.equal(holiday?.module, 'hr');
assert.match(holiday.label, /hr_holidays_2026/);
const groups = configurationIndexContext().scope.groups;
const modules = Object.values(groups).flat();
const calendar = modules.find(module => module.key === 'hr').keys.find(row => row.raw === 'hr_holidays_');
assert.equal(calendar.count, 1);
assert.match(calendar.value, /2026 =/);
console.log('configuration preview fingerprint and families: 8 assertions passed');
