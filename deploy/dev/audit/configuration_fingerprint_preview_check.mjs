#!/usr/bin/env node
/** Verify that the preview hashes credential state, not a duplicated or missing value. */
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { fingerprintOf } from '../preview/configuration-sample.mjs';
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
console.log('configuration preview fingerprint: 4 assertions passed');
