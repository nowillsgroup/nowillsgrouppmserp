#!/usr/bin/env node
'use strict';

import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(__dirname, '../../tizen-tv/www/js/launcher.js'), 'utf8');

function makeLauncher(initialStore) {
  const ids = ['server', 'hotel-code', 'connection-form', 'notice', 'configure', 'startup', 'change-settings'];
  const elements = {};
  ids.forEach((id) => {
    elements[id] = {
      id,
      hidden: false,
      value: '',
      textContent: '',
      handlers: {},
      focused: false,
      focus() { this.focused = true; },
      addEventListener(type, fn) { this.handlers[type] = fn; },
    };
  });
  const store = Object.assign({}, initialStore || {});
  const timers = [];
  let redirected = '';
  const context = {
    document: { getElementById: (id) => elements[id] },
    localStorage: {
      getItem: (key) => store[key] || null,
      setItem: (key, value) => { store[key] = String(value); },
    },
    location: { replace: (url) => { redirected = url; } },
    setTimeout: (fn) => { timers.push({ fn, cancelled: false }); return timers.length - 1; },
    clearTimeout: (id) => { if (timers[id]) timers[id].cancelled = true; },
    encodeURIComponent,
  };
  vm.runInNewContext(source, context, { filename: 'launcher.js' });
  return {
    elements,
    store,
    timers,
    runTimer(index) { if (timers[index] && !timers[index].cancelled) timers[index].fn(); },
    get redirected() { return redirected; },
  };
}

const fresh = makeLauncher();
assert.strictEqual(fresh.elements.server.value, 'https://pms.nowills.com');
assert.strictEqual(fresh.elements.configure.hidden, false);
assert.strictEqual(fresh.redirected, '');
fresh.elements.server.value = 'https://pms.nowills.com/';
fresh.elements['hotel-code'].value = 'ACC';
fresh.elements['connection-form'].handlers.submit({ preventDefault() {} });
assert.strictEqual(fresh.store['nowills-tv-server-v1'], 'https://pms.nowills.com');
assert.strictEqual(fresh.store['nowills-tv-hotel-v1'], 'ACC');
fresh.runTimer(fresh.timers.length - 1);
assert.strictEqual(fresh.redirected, 'https://pms.nowills.com/tv/setup/ACC?launch=welcome');

const invalid = makeLauncher();
invalid.elements.server.value = 'http://pms.nowills.com';
invalid.elements['hotel-code'].value = 'ACC';
invalid.elements['connection-form'].handlers.submit({ preventDefault() {} });
assert.match(invalid.elements.notice.textContent, /valid HTTPS/);
assert.strictEqual(invalid.redirected, '');

const returning = makeLauncher({
  'nowills-tv-server-v1': 'https://pms.nowills.com',
  'nowills-tv-hotel-v1': 'ACC',
});
assert.strictEqual(returning.elements.startup.hidden, false);
assert.strictEqual(returning.elements.configure.hidden, true);
assert.strictEqual(returning.elements['change-settings'].focused, true);
returning.elements['change-settings'].handlers.click();
assert.strictEqual(returning.elements.configure.hidden, false);
returning.runTimer(0);
assert.strictEqual(returning.redirected, '');

const automatic = makeLauncher({
  'nowills-tv-server-v1': 'https://pms.nowills.com',
  'nowills-tv-hotel-v1': 'ACC',
});
automatic.runTimer(0);
assert.strictEqual(automatic.redirected, 'https://pms.nowills.com/tv/setup/ACC?launch=welcome');

console.log('Tizen launcher setup, HTTPS validation, persistence, and welcome startup checks passed.');
