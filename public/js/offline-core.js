/*
 * Nowills PMS offline core.
 *
 * One queue, one set of rules, shared by every screen that can work without a
 * connection — and by the service worker that keeps the queue moving when no
 * tab is open.
 *
 * How it works:
 *   · A screen writes an operation (an intent such as "open this order" or
 *     "send these dishes to the kitchen") into IndexedDB and returns at once.
 *     The member of staff never waits for the network.
 *   · The operation carries an operation id generated on the device. The server
 *     stores that id, so replaying an operation that already landed returns the
 *     stored answer instead of creating a second order or a second payment.
 *   · When a connection is available the queue is posted to /offline/sync in
 *     batches. Applied operations leave the queue, conflicts and rejections stay
 *     so a person can see them, retries back off on a timer.
 *   · Background Sync (where the browser has it) flushes the queue even if the
 *     tab was closed; otherwise the next visit does it.
 *
 * Loaded by pages (window.PmsOffline) and by /sw.js (self.PmsOffline).
 */
(function (global) {
  'use strict';

  var DB_NAME = 'nowills-pms-offline-v2';
  var DB_VERSION = 1;
  var OUTBOX = 'outbox';
  var SNAPSHOTS = 'snapshots';
  var META = 'meta';
  var SYNC_URL = '/offline/sync';
  var BATCH = 100;
  var MAX_ATTEMPTS = 20;
  var isWorker = typeof document === 'undefined';

  var state = {
    online: typeof navigator !== 'undefined' && typeof navigator.onLine === 'boolean' ? navigator.onLine : true,
    syncing: false,
    queued: 0,
    conflicts: 0,
    rejected: 0,
    lastSync: null,
    lastError: null,
    device: null
  };

  var listeners = [];
  var opening = null;

  function now() { return Date.now(); }

  function uuid() {
    if (global.crypto && typeof global.crypto.randomUUID === 'function') return global.crypto.randomUUID();
    return 'op-' + now().toString(36) + '-' + Math.random().toString(16).slice(2, 12);
  }

  function openDb() {
    if (opening) return opening;
    opening = new Promise(function (resolve, reject) {
      if (!global.indexedDB) { reject(new Error('This browser cannot store offline work.')); return; }
      var request = global.indexedDB.open(DB_NAME, DB_VERSION);
      request.onupgradeneeded = function () {
        var db = request.result;
        if (!db.objectStoreNames.contains(OUTBOX)) {
          var outbox = db.createObjectStore(OUTBOX, { keyPath: 'id' });
          outbox.createIndex('status', 'status');
          outbox.createIndex('created_at', 'created_at');
        }
        if (!db.objectStoreNames.contains(SNAPSHOTS)) db.createObjectStore(SNAPSHOTS, { keyPath: 'key' });
        if (!db.objectStoreNames.contains(META)) db.createObjectStore(META, { keyPath: 'key' });
      };
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { reject(request.error || new Error('Local storage could not be opened.')); };
      request.onblocked = function () { reject(new Error('Close this account in another tab to finish upgrading local storage.')); };
    });
    return opening;
  }

  function run(store, mode, action) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(store, mode);
        var request = action(tx.objectStore(store));
        tx.oncomplete = function () { resolve(request ? request.result : undefined); };
        tx.onerror = function () { reject(tx.error || new Error('Local storage write failed.')); };
        tx.onabort = function () { reject(tx.error || new Error('Local storage write was aborted.')); };
      });
    });
  }

  function readMeta(key) {
    return run(META, 'readonly', function (store) { return store.get(key); }).then(function (row) { return row ? row.value : null; });
  }

  function writeMeta(key, value) {
    return run(META, 'readwrite', function (store) { return store.put({ key: key, value: value }); });
  }

  function deviceId() {
    if (state.device) return Promise.resolve(state.device);
    var stored = null;
    try { stored = global.localStorage ? global.localStorage.getItem('nowills-offline-device') : null; } catch (_) { stored = null; }
    if (!stored) {
      return readMeta('device').then(function (value) {
        var id = value || 'dev-' + uuid();
        state.device = id;
        try { if (global.localStorage) global.localStorage.setItem('nowills-offline-device', id); } catch (_) { /* private mode */ }
        return writeMeta('device', id).then(function () { return id; });
      });
    }
    state.device = stored;
    return Promise.resolve(stored);
  }

  function csrfToken() {
    if (!isWorker) {
      var meta = document.querySelector('meta[name="csrf-token"]');
      if (meta && meta.getAttribute('content')) return Promise.resolve(meta.getAttribute('content'));
    }
    return readMeta('csrf').then(function (value) { return value || null; });
  }

  function rememberCsrf() {
    if (isWorker) return Promise.resolve();
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return Promise.resolve();
    var token = meta.getAttribute('content');
    return writeMeta('csrf', token).catch(function () { /* storage full or blocked */ });
  }

  function emit() {
    var snapshot = status();
    listeners.slice().forEach(function (fn) {
      try { fn(snapshot); } catch (_) { /* a broken listener must not stop the queue */ }
    });
    if (!isWorker) {
      try {
        global.dispatchEvent(new CustomEvent('pms-offline:status', { detail: snapshot }));
      } catch (_) { /* very old browsers */ }
    }
  }

  function status() {
    return {
      online: state.online, syncing: state.syncing, queued: state.queued,
      conflicts: state.conflicts, rejected: state.rejected,
      lastSync: state.lastSync, lastError: state.lastError
    };
  }

  /** Count what is waiting and tell anyone listening. */
  function refresh() {
    return allRows().then(function (rows) {
      state.queued = rows.filter(function (row) { return row.status === 'queued' || row.status === 'retry'; }).length;
      state.conflicts = rows.filter(function (row) { return row.status === 'conflict'; }).length;
      state.rejected = rows.filter(function (row) { return row.status === 'rejected' || row.status === 'stalled'; }).length;
      emit();
      return status();
    }).catch(function () { return status(); });
  }

  function allRows() {
    return run(OUTBOX, 'readonly', function (store) { return store.getAll(); }).then(function (rows) { return rows || []; });
  }

  /**
   * Put an operation in the queue.
   * @param {string} operation  server-side name, e.g. 'pos.order.kot'
   * @param {object} payload    what the server needs to apply it
   * @param {object} [options]  { baseUpdatedAt, label, id }
   * @returns {Promise<object>} the stored row (its id is the operation id)
   */
  function enqueue(operation, payload, options) {
    options = options || {};
    var row = {
      // `id` is only passed when work already carries one — a screen moving onto
      // this queue keeps the operation ids its rows were stored under, so the
      // server still recognises them and cannot apply the same work twice.
      id: options.id || uuid(),
      operation: operation,
      payload: payload || {},
      base_updated_at: options.baseUpdatedAt || new Date().toISOString(),
      label: options.label || operation,
      status: 'queued',
      attempts: 0,
      next_attempt_at: 0,
      error: null,
      created_at: now()
    };
    return run(OUTBOX, 'readwrite', function (store) { return store.put(row); })
      .then(function () { return requestBackgroundSync(); })
      .catch(function () { return null; })
      .then(function () { return refresh(); })
      .then(function () { return row; });
  }

  function patch(id, changes) {
    return run(OUTBOX, 'readonly', function (store) { return store.get(id); }).then(function (row) {
      if (!row) return null;
      var next = Object.assign({}, row, changes);
      return run(OUTBOX, 'readwrite', function (store) { return store.put(next); }).then(function () { return next; });
    });
  }

  function drop(id) {
    return run(OUTBOX, 'readwrite', function (store) { return store.delete(id); });
  }

  /**
   * Put a change back in the queue, now.
   *
   * A person uses this after fixing what the server complained about — a room that
   * was occupied has been freed, a category was wrong — instead of re-typing work
   * the guest already watched happen.
   */
  function retry(id) {
    return patch(id, { status: 'queued', attempts: 0, next_attempt_at: 0, error: null })
      .then(function (row) { return refresh().then(function () { return row; }); })
      .then(function (row) {
        if (!row) return null;
        return flush({ force: true }).then(function () { return row; });
      });
  }

  /**
   * Give up on one queued change. Only a person may drop work: the server never
   * removes an operation, and the screen asks before it calls this.
   */
  function discard(id) {
    return drop(id).then(function () { return refresh(); });
  }

  /**
   * Warm this device for offline work.
   *
   * `plan` is what the server says this login may work: one entry per screen, with
   * the page and the snapshot to keep. Each page is fetched so the service worker
   * has the screen itself, and each snapshot is kept — in this queue's own store
   * when the screen reads through it (`snapshot_key`), otherwise in the HTTP cache
   * the service worker fills, which is what the older screens read.
   *
   * Nothing here invents data: a slice the server refuses is reported as failed.
   *
   * @param {Array<{key: string, label: string, page?: string, url?: string, snapshot_key?: string}>} plan
   * @returns {Promise<Array<{key: string, label: string, page: boolean, snapshot: boolean, error: (string|null)}>>}
   */
  function prepare(plan) {
    var results = [];

    return (plan || []).reduce(function (chain, slice) {
      return chain.then(function () {
        var entry = { key: slice.key, label: slice.label, page: false, snapshot: false, error: null };
        results.push(entry);

        var page = slice.page
          ? fetch(slice.page, { credentials: 'same-origin' })
              .then(function (response) { entry.page = !!response.ok; })
              .catch(function (error) { entry.error = error.message; })
          : Promise.resolve();

        return page.then(function () {
          if (!slice.url) return null;

          var pull = slice.snapshot_key
            ? snapshot(slice.snapshot_key, slice.url).then(function () { entry.snapshot = true; })
            : fetch(slice.url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
                .then(function (response) {
                  if (!response.ok) throw new Error('The server could not send ' + slice.label + ' (' + response.status + ').');
                  return response.json();
                })
                .then(function () { entry.snapshot = true; });

          return pull.catch(function (error) { entry.error = entry.error || error.message; });
        });
      });
    }, Promise.resolve()).then(function () { return results; });
  }

  function backoff(attempts) {
    var seconds = Math.min(30 * Math.pow(2, Math.max(0, attempts - 1)), 900);
    return now() + seconds * 1000;
  }

  function requestBackgroundSync() {
    if (isWorker || !('serviceWorker' in navigator)) return Promise.resolve(null);
    if (!('SyncManager' in global)) return Promise.resolve(null);
    // A page can queue work before the worker is active: never let `ready` hold up
    // the caller, the work is already safely in IndexedDB.
    return Promise.race([
      navigator.serviceWorker.ready.then(function (registration) { return registration.sync.register('pms-outbox'); }),
      new Promise(function (resolve) { setTimeout(resolve, 1500); })
    ]).catch(function () { return null; });
  }

  function applyResults(sent, results) {
    var byId = {};
    (results || []).forEach(function (result) { if (result && result.operation_id) byId[result.operation_id] = result; });
    var applied = [];
    var conflicts = [];

    return sent.reduce(function (chain, row) {
      return chain.then(function () {
        var result = byId[row.id];
        if (!result) {
          return patch(row.id, { attempts: row.attempts + 1, status: 'retry', next_attempt_at: backoff(row.attempts + 1), error: 'The server did not report this change; it will be sent again.' });
        }
        if (result.status === 'applied') {
          applied.push({ operation: row.operation, payload: row.payload, result: result });
          return drop(row.id);
        }
        if (result.status === 'conflict') {
          conflicts.push({ operation: row.operation, payload: row.payload, error: result.error || 'Changed on the server while this device was offline.' });
          return patch(row.id, { status: 'conflict', error: result.error || 'Changed on the server while this device was offline.', server: result, attempts: row.attempts + 1 });
        }
        if (result.status === 'rejected') {
          return patch(row.id, { status: 'rejected', error: result.error || 'The server refused this change.', server: result, attempts: row.attempts + 1 });
        }
        var attempts = row.attempts + 1;
        return patch(row.id, {
          status: attempts >= MAX_ATTEMPTS ? 'stalled' : 'retry',
          attempts: attempts,
          next_attempt_at: backoff(attempts),
          error: result.error || 'The server could not apply this change yet.'
        });
      });
    }, Promise.resolve()).then(function () {
      return { applied: applied, conflicts: conflicts };
    });
  }

  /**
   * Send what is due. Safe to call from anywhere, any number of times: a second
   * call while one is running is ignored.
   */
  function flush(options) {
    options = options || {};
    if (state.syncing) return Promise.resolve({ skipped: 'busy', status: status() });
    if (!options.force && !state.online && !isWorker) return Promise.resolve({ skipped: 'offline', status: status() });
    state.syncing = true;
    emit();

    var batch = [];
    return allRows()
      .then(function (rows) {
        var due = now();
        // Oldest first: an order opened while offline must reach the server before the
        // tickets that belong to it.
        batch = rows.filter(function (row) {
          return (row.status === 'queued' || row.status === 'retry') && (!row.next_attempt_at || row.next_attempt_at <= due);
        }).sort(function (a, b) { return (a.created_at || 0) - (b.created_at || 0); }).slice(0, BATCH);
        if (!batch.length) return null;
        return Promise.all([deviceId(), csrfToken()]).then(function (values) {
          var headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Offline-Device': values[0]
          };
          if (values[1]) headers['X-CSRF-TOKEN'] = values[1];
          return fetch(SYNC_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify({
              operations: batch.map(function (row) {
                return {
                  operation_id: row.id,
                  operation: row.operation,
                  payload: row.payload,
                  base_updated_at: row.base_updated_at
                };
              })
            })
          });
        });
      })
      .then(function (response) {
        if (!response) return { sent: 0, applied: [], conflicts: [] };
        if (response.status === 419 || response.status === 401 || response.status === 302) {
          throw new Error('signed-out');
        }
        if (!response.ok) throw new Error('The server answered ' + response.status + '.');
        return response.json().then(function (payload) { return applyResults(batch, payload.results); })
          .then(function (summary) { summary.sent = batch.length; return summary; });
      })
      .then(function (summary) {
        state.lastError = null;
        if (summary.sent) state.lastSync = new Date().toISOString();
        state.syncing = false;
        return refresh().then(function () {
          if (!isWorker && summary.applied && summary.applied.length && global.dispatchEvent) {
            try { global.dispatchEvent(new CustomEvent('pms-offline:applied', { detail: summary })); } catch (_) {}
          }
          return summary;
        });
      })
      .catch(function (error) {
        state.syncing = false;
        state.lastError = error.message === 'signed-out'
          ? 'Sign in again to send the work saved on this device.'
          : 'Saved on this device. It will be sent when the connection returns.';
        if (error.message === 'signed-out') {
          // Nothing is lost; the queue waits for a fresh session.
          return refresh().then(function () { return { skipped: 'signed-out', status: status() }; });
        }
        return refresh().then(function () { return { skipped: 'error', error: error.message, status: status() }; });
      });
  }

  /** Read-through cache for the JSON a screen needs to work offline. */
  function snapshot(name, url, options) {
    options = options || {};
    var read = function () {
      return run(SNAPSHOTS, 'readonly', function (store) { return store.get(name); }).then(function (row) { return row || null; });
    };
    var pull = function () {
      return fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
        .then(function (response) {
          if (!response.ok) throw new Error('The server could not send ' + name + ' (' + response.status + ').');
          return response.json();
        })
        .then(function (data) {
          return run(SNAPSHOTS, 'readwrite', function (store) {
            return store.put({ key: name, data: data, saved_at: now() });
          }).then(function () { return data; });
        });
    };
    var cached = function (error) {
      return read().then(function (row) {
        if (!row) throw error;
        return Object.assign({}, row.data, { _saved_at: row.saved_at, _stale: true });
      });
    };

    if (state.online === false) return cached(new Error('This device is offline and no saved copy of ' + name + ' exists yet.'));
    return pull().catch(cached);
  }

  function cachedSnapshot(name) {
    return run(SNAPSHOTS, 'readonly', function (store) { return store.get(name); })
      .then(function (row) { return row ? Object.assign({}, row.data, { _saved_at: row.saved_at, _stale: true }) : null; });
  }

  function clear(name) {
    return run(SNAPSHOTS, 'readwrite', function (store) { return store.delete(name); });
  }

  /** Forget the queued work (only for a device being wiped by a manager). */
  function reset() {
    return run(OUTBOX, 'readwrite', function (store) { return store.clear(); })
      .then(function () { return run(SNAPSHOTS, 'readwrite', function (store) { return store.clear(); }); })
      .then(refresh);
  }

  function onChange(fn) {
    listeners.push(fn);
    fn(status());
    return function () { listeners = listeners.filter(function (other) { return other !== fn; }); };
  }

  /** Page-side wiring: connection changes, coming back to the tab, and a timer. */
  var watching = false;

  function watch() {
    if (isWorker || watching) return;
    watching = true;
    rememberCsrf();
    global.addEventListener('online', function () { state.online = true; emit(); flush(); });
    global.addEventListener('offline', function () { state.online = false; emit(); });
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') { state.online = navigator.onLine; refresh().then(function () { flush(); }); }
    });
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.addEventListener('message', function (event) {
        var data = event.data || {};
        if (data.type === 'pms-offline:flushed' || data.type === 'pms-outbox-flushed') refresh();
      });
    }
    refresh();
    flush();
    setInterval(function () {
      if (navigator.onLine) { state.online = true; flush(); } else { state.online = false; emit(); }
    }, 45000);
  }

  /** Small key/value corner for screen state that must survive a reload (drafts, claims). */
  var local = {
    get: function (key) {
      return readMeta('local:' + key).then(function (value) { return value; });
    },
    set: function (key, value) {
      return writeMeta('local:' + key, value);
    },
    remove: function (key) {
      return writeMeta('local:' + key, null);
    }
  };

  global.PmsOffline = {
    local: local,
    syncUrl: SYNC_URL,
    open: openDb,
    enqueue: enqueue,
    flush: flush,
    snapshot: snapshot,
    cachedSnapshot: cachedSnapshot,
    clearSnapshot: clear,
    pending: allRows,
    prepare: prepare,
    retry: retry,
    discard: discard,
    refresh: refresh,
    status: status,
    onChange: onChange,
    watch: watch,
    reset: reset,
    deviceId: deviceId,
    uuid: uuid
  };

  if (isWorker) {
    // The service worker owns the queue while the app is closed.
    global.addEventListener('sync', function (event) {
      if (event.tag === 'pms-outbox') {
        event.waitUntil(flush({ force: true }).then(function (summary) {
          return self.clients.matchAll({ includeUncontrolled: true }).then(function (clients) {
            clients.forEach(function (client) { client.postMessage({ type: 'pms-outbox-flushed', summary: summary }); });
          });
        }).catch(function () { /* retried on the next sync or page visit */ }));
      }
    });
    global.addEventListener('message', function (event) {
      var data = event.data || {};
      if (data.type === 'pms-outbox-flush') {
        event.waitUntil(flush({ force: true }));
      }
    });
  }
})(typeof self !== 'undefined' ? self : this);
