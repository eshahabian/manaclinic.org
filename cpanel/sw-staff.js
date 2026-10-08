/* Staff app service worker. Push and the home-screen badge only.
   Do not handle fetch. Intercepting navigations made link and button taps
   look dead, and fetch(request, {cache}) blanks every page this worker controls. */
self.addEventListener('install', function (event) {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

/* Do not intercept fetches. A navigation click is a document request.
   respondWith() was swallowing those clicks: the browser stays on the page
   and every link, tile, and logout looks dead. Live chat already cache-busts
   its own requests. Push does not need a fetch handler. */

self.addEventListener('push', function (event) {
  var payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (err) {
    payload = {};
  }
  var jobs = [];
  if (self.navigator && self.navigator.setAppBadge) {
    var badge = Number(payload.badge) || 0;
    jobs.push(badge > 0 ? self.navigator.setAppBadge(badge) : (self.navigator.clearAppBadge ? self.navigator.clearAppBadge() : Promise.resolve()));
  }
  jobs.push(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
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
  event.waitUntil(Promise.all(jobs));
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
