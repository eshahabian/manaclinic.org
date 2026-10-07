/* Staff app service worker. Network-only so the installed app can open.
   Never call fetch(request, {cache:...}): a Request plus an init object throws
   and blanks every page this worker controls.
   Never fetch(request.url) for navigations: a redirected response cannot
   satisfy a navigation whose redirect mode is manual.
   iOS still uses the HTTP cache for fetch(request), so other GETs are loaded
   by URL with cache no-store. no-cors requests (fonts) stay no-cors. */
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
    event.respondWith(fetch(request));
    return;
  }
  var init = {
    cache: 'no-store',
    credentials: 'same-origin',
    redirect: 'follow'
  };
  if (request.mode === 'no-cors') {
    init.mode = 'no-cors';
  }
  event.respondWith(fetch(request.url, init));
});
