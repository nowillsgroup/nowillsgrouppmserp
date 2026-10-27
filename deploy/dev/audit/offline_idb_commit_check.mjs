import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

function extractDbRequest(source) {
  const start = source.indexOf('async function dbRequest(');
  assert.notEqual(start, -1, 'dbRequest helper exists');
  const open = source.indexOf('{', start);
  let depth = 0;
  for (let i = open; i < source.length; i++) {
    if (source[i] === '{') depth++;
    if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
  }
  throw new Error('Could not extract dbRequest helper.');
}

async function exercise(file, abortAfterSuccess) {
  const helper = extractDbRequest(readFileSync(file, 'utf8'));
  let tx;
  let closed = false;
  const request = { result: 'saved-result' };
  const db = {
    transaction: () => (tx = { objectStore: () => ({}) }),
    close: () => { closed = true; },
  };
  const dbRequest = new Function('openDb', `${helper}; return dbRequest;`)(async () => db);
  const result = dbRequest('outbox', 'readwrite', () => request);
  await Promise.resolve();
  assert.equal(typeof request.onsuccess, 'function', `${file}: success handler attached`);
  assert.equal(typeof tx.oncomplete, 'function', `${file}: completion handler attached`);
  request.onsuccess();

  if (abortAfterSuccess) {
    tx.error = new Error('simulated quota/commit failure');
    tx.onabort();
    await assert.rejects(result, /simulated quota\/commit failure/, `${file}: abort rejects after request success`);
  } else {
    tx.oncomplete();
    assert.equal(await result, 'saved-result', `${file}: result resolves only on commit`);
  }
  assert.equal(closed, true, `${file}: database connection closes`);
}

for (const file of ['public/js/offline-sync.js', 'public/js/offline-guest-requests.js']) {
  await exercise(file, false);
  await exercise(file, true);
  console.log(`PASS IndexedDB requests resolve only after transaction commit (${file})`);
}
