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
 * It never caches a POST, never caches a redirect to the sign-in page, and never
 * invents data: the workspaces render from the snapshot the server last sent.
 */
importScripts('/js/offline-core.js');

const CACHE = 'nowills-pms-v3';
const STATIC = [
  '/css/app.css',
  '/css/offline.css',
  '/css/dashboard.css',
  '/js/app.js',
  '/js/offline-core.js',
  '/js/offline-pos.js',
  '/js/offline-sync.js',
  '/js/offline-guest-requests.js',
  '/sw.js'
];
// Screens that are built to work from a saved snapshot.
const WORKSPACES = ['/offline', '/offline/pos', '/offline/housekeeping', '/offline/guest-requests'];
const FALLBACK = '/offline';
const NAV_TIMEOUT_MS = 4000;

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

async function cacheIfUsable(request, response) {
  // A redirect to the sign-in page, an error page or a no-store response must
  // never become the offline copy of a screen.
  if (!response || !response.ok || response.redirected) return response;
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
    event.respondWith(networkFirst(request, FALLBACK));
    return;
  }

  if (WORKSPACES.includes(url.pathname)) {
    event.respondWith(networkFirst(request, FALLBACK));
    return;
  }

  // Snapshots and other JSON: fresh when possible, last saved copy otherwise.
  if (url.pathname.startsWith('/offline/') && (url.pathname.endsWith('/snapshot') || url.pathname.endsWith('/delta') || url.pathname.endsWith('/pack'))) {
    event.respondWith(networkFirst(request));
    return;
  }

  if (STATIC.includes(url.pathname) || url.pathname.startsWith('/images/') || url.pathname.startsWith('/css/')) {
    event.respondWith(cacheFirst(request));
  }
});
