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

self.addEventListener('push', function (event) {
  var payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (err) {
    payload = {};
  }
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    var visible = false;
    list.forEach(function (client) {
      client.postMessage({ type: 'staff-push', payload: payload });
      if (client.visibilityState === 'visible') visible = true;
    });
    if (visible) return null;
    return self.registration.showNotification(payload.title || 'مانا کارکنان', {
      body: payload.body || 'پیام تازه در چت دارید.',
      tag: payload.tag || 'sapp-chat',
      lang: 'fa',
      data: { url: payload.url || '/app/chat' }
    });
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var target = (event.notification.data && event.notification.data.url) || '/app/chat';
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    var i;
    for (i = 0; i < list.length; i++) {
      if (list[i].focus) {
        list[i].postMessage({ type: 'staff-push-open', url: target });
        return list[i].focus();
      }
    }
    if (self.clients.openWindow) return self.clients.openWindow(target);
    return null;
  }));
});
