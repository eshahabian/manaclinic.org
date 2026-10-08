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

var clientUsers = Object.create(null);

function sameStaffUser(left, right) {
  left = String(left || '').trim().toLowerCase();
  right = String(right || '').trim().toLowerCase();
  return left !== '' && left === right;
}

self.addEventListener('message', function (event) {
  var data = event.data || {};
  if (data.type === 'staff-user' && event.source) {
    clientUsers[event.source.id] = String(data.userId || '');
  }
});

function askClientUser(client) {
  var known = clientUsers[client.id];
  if (known) return Promise.resolve(known);
  return new Promise(function (resolve) {
    var settled = false;
    var finish = function (id) {
      if (settled) return;
      settled = true;
      id = String(id || '');
      if (id) clientUsers[client.id] = id;
      resolve(id);
    };
    try {
      var channel = new MessageChannel();
      channel.port1.onmessage = function (msg) {
        finish(msg.data && msg.data.userId);
      };
      client.postMessage({ type: 'staff-who' }, [channel.port2]);
    } catch (err) {
      finish(clientUsers[client.id] || '');
      return;
    }
    setTimeout(function () { finish(clientUsers[client.id] || ''); }, 400);
  });
}

self.addEventListener('push', function (event) {
  var payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (err) {
    payload = {};
  }
  var senderId = String(payload.senderId || '');
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    return Promise.all(list.map(askClientUser)).then(function (ids) {
      var visible = false;
      var senderHere = false;
      var i;
      for (i = 0; i < list.length; i++) {
        list[i].postMessage({ type: 'staff-push', payload: payload });
        if (list[i].visibilityState === 'visible') visible = true;
        if (sameStaffUser(ids[i], senderId)) senderHere = true;
      }
      if (senderHere) return null;
      var jobs = [];
      if (self.navigator && self.navigator.setAppBadge) {
        var badge = Number(payload.badge) || 0;
        jobs.push(badge > 0 ? self.navigator.setAppBadge(badge) : (self.navigator.clearAppBadge ? self.navigator.clearAppBadge() : Promise.resolve()));
      }
      if (visible) return Promise.all(jobs);
      jobs.push(self.registration.showNotification(payload.title || 'مانا کارکنان', {
        body: payload.body || 'پیام تازه در چت دارید.',
        tag: payload.tag || 'sapp-chat',
        lang: 'fa',
        data: { url: payload.url || '/app/chat' }
      }));
      return Promise.all(jobs);
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
