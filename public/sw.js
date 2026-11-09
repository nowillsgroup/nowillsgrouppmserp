/*
 * Nowills PMS service worker.
 *
 * Three jobs:
 *   1. keep the application shell (CSS, JS, brand images) on the device, so the
 *      screens that can work offline still open when the server is unreachable;
 *   2. serve the offline-capable workspaces from the device, and send every other
 *      navigation to the offline hub instead of the browser's dinosaur page;
 *   3. keep moving the outbox written by /js/offline-core.js — Background Sync
 *      runs this even when no tab is open.
 *
 * Caching model (v5), deliberately strict:
 *   • the application shell and static assets are cache-first — they are public,
 *     non-personal files;
 *   • ONLY the offline surfaces listed in WORKSPACES / the snapshot JSON pattern
 *     may be stored, because those screens are designed to work from a saved copy
 *     and only after a staff member prepares the device;
 *   • every other navigation in the app is network-only and is NEVER written to
 *     the cache — this covers the whole authenticated back office, which sends
 *     guest PII and must not persist it on shared desk PCs;
 *   • a response with Cache-Control: no-store/private is never stored, even on an
 *     offline surface, unless that surface is designed for offline use.
 *   • a POST is never cached, and a redirect to the sign-in page is never cached.
 */
importScripts('/js/offline-core.js');

const CACHE = 'nowills-pms-v5';
const STATIC = [
  '/css/app.css',
  '/css/offline.css',
  '/css/dashboard.css',
  '/js/app.js',
  '/js/offline-core.js',
  '/js/offline-pos.js',
  '/js/offline-frontdesk.js',
  '/js/offline-queue.js',
  '/js/offline-sync.js',
  '/js/offline-guest-requests.js',
  '/sw.js'
];
// Screens built to work from a saved snapshot — the only navigations we may store.
const WORKSPACES = ['/offline', '/offline/pos', '/offline/housekeeping', '/offline/guest-requests', '/offline/frontdesk', '/offline/queue', '/offline/preparation', '/offline/reconciliation', '/offline/desktop'];
const FALLBACK = '/offline';
const NAV_TIMEOUT_MS = 4000;

// JSON payloads the offline workspaces render from: /offline/<area>/snapshot,
// /offline/desktop/pack and /offline/desktop/delta. Same design exception as WORKSPACES.
function isOfflineJson(pathname) {
  return pathname.startsWith('/offline/') && /\/(snapshot|delta|pack)$/.test(pathname);
}
// The /offline surface is the designed exception to no-store on navigations; nothing else is.
function isOfflineSurface(pathname) {
  return WORKSPACES.includes(pathname) || isOfflineJson(pathname);
}

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE)
      // One failed asset must not abort the whole install: cache what we can.
      .then(cache => Promise.all(STATIC.map(url =>
        cache.add(new Request(url, { cache: 'reload' })).catch(() => null)
      )))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key !== CACHE).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

function cacheability(request, response) {
  if (!response || !response.ok || response.redirected) return false;
  const pathname = new URL(request.url).pathname;
  const cc = (response.headers.get('Cache-Control') || '').toLowerCase();
  const noStore = cc.includes('no-store') || cc.includes('private');
  if (isOfflineSurface(pathname)) return true;               // designed offline surfaces
  return !noStore && (isStatic(pathname));                    // everything else: static only
}
function isStatic(pathname) {
  return STATIC.includes(pathname) || pathname.startsWith('/images/') || pathname.startsWith('/css/') || pathname.startsWith('/js/');
}

async function cacheIfUsable(request, response) {
  if (!cacheability(request, response)) return response;
  const cache = await caches.open(CACHE);
  await cache.put(request, response.clone());
  return response;
}

async function networkFirst(request, fallbackUrl) {
  const cache = await caches.open(CACHE);
  try {
    const network = fetch(request);
    network.catch(() => null); // the timeout may win; keep that rejection quiet
    const response = await Promise.race([
      network,
      new Promise((_, reject) => setTimeout(() => reject(new Error('slow')), NAV_TIMEOUT_MS))
    ]);
    return await cacheIfUsable(request, response);
  } catch (_) {
    const cached = await cache.match(request, { ignoreSearch: true });
    if (cached) return cached;
    const fallback = fallbackUrl ? await cache.match(fallbackUrl) : null;
    if (fallback) return fallback;
    return new Response(
      '<!doctype html><meta charset="utf-8"><title>Offline</title>' +
      '<body style="font-family:system-ui;padding:2rem;max-width:32rem;margin:0 auto">' +
      '<h1>This screen needs a connection</h1>' +
      '<p>Sign in once while online so this device can keep the screens that support offline work.</p></body>',
      { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
  }
}

// Network-only for the rest of the authenticated app: never writes to the cache,
// falls back to the offline hub (which is not sensitive) only when the network is down.
async function networkOnly(request, fallbackUrl) {
  try {
    const response = await fetch(request);
    return response;
  } catch (_) {
    const cache = await caches.open(CACHE);
    const fallback = fallbackUrl ? await cache.match(fallbackUrl) : null;
    return fallback || new Response(
      '<!doctype html><meta charset="utf-8"><title>Offline</title>' +
      '<body style="font-family:system-ui;padding:2rem;max-width:32rem;margin:0 auto">' +
      '<h1>You are offline</h1>' +
      '<p>This screen needs a connection. The offline workspaces under /offline keep working.</p></body>',
      { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
  }
}

async function cacheFirst(request) {
  const cache = await caches.open(CACHE);
  const cached = await cache.match(request, { ignoreSearch: true });
  const network = fetch(request).then(response => cacheIfUsable(request, response)).catch(() => null);
  return cached || (await network) || Response.error();
}

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    // The offline workspaces are network-first, so staff online always see fresh
    // permissions, and anything they cannot load lands on the offline hub.
    if (WORKSPACES.includes(url.pathname)) {
      event.respondWith(networkFirst(request, FALLBACK));
      return;
    }
    // Everything else in the (authenticated) app: never cached.
    event.respondWith(networkOnly(request, FALLBACK));
    return;
  }

  // Snapshots and other JSON the offline workspaces render: fresh when possible, last saved copy otherwise.
  if (isOfflineJson(url.pathname)) {
    event.respondWith(networkFirst(request));
    return;
  }

  if (isStatic(url.pathname)) {
    event.respondWith(cacheFirst(request));
  }
});
