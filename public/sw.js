/* Nowills PMS offline shell. Only explicitly offline-ready workspaces are cached. */
const CACHE = 'nowills-pms-offline-v2';
const SHELL = ['/css/app.css', '/css/offline.css', '/js/app.js', '/js/offline-sync.js', '/js/offline-guest-requests.js', '/sw.js'];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) return;
  const url = new URL(request.url);

  // Navigation is network-first so staff see fresh permissions and data online.
  // Successful offline-workspace responses are cached for outage use.
  if (request.mode === 'navigate' && ['/offline/housekeeping', '/offline/guest-requests'].includes(url.pathname)) {
    event.respondWith(fetch(request).then(response => {
      if (response.ok) {
        const copy = response.clone();
        caches.open(CACHE).then(cache => cache.put(request, copy));
      }
      return response;
    }).catch(() => caches.match(request)));
    return;
  }

  // Static offline assets use a cache-first strategy with a network refresh.
  if (SHELL.includes(url.pathname)) {
    event.respondWith(caches.match(request).then(cached => {
      const fresh = fetch(request).then(response => {
        if (response.ok) caches.open(CACHE).then(cache => cache.put(request, response.clone()));
        return response;
      }).catch(() => cached);
      return cached || fresh;
    }));
  }
});
