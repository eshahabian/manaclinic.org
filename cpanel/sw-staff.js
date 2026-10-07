/* Staff app service worker. Network-only so Chrome can install the app.
   Authenticated HTML is not cached. */
self.addEventListener('install', function (event) {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') {
    return;
  }
  if (request.cache === 'only-if-cached' && request.mode !== 'same-origin') {
    return;
  }
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request.url, { cache: 'no-store', credentials: 'include', redirect: 'follow' }));
    return;
  }
  event.respondWith(fetch(request, { cache: 'no-store' }).catch(function () {
    return fetch(request);
  }));
});
