/* KIVU Tracker service worker: OS notifications (top-of-screen banners) that
   open the app when tapped, plus re-alerting via tag/renotify for the 30s
   repeat series sent from the server. */
self.addEventListener('install', (e) => { self.skipWaiting(); });
self.addEventListener('activate', (e) => { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) {
    d = { title: 'KIVU Tracker', body: e.data ? e.data.text() : '' };
  }
  const opts = {
    body: d.body || '',
    icon: '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    vibrate: [200, 100, 200],
    tag: d.tag || 'kivu-alert',
    renotify: true,
    data: { url: d.url || '/app' },
    requireInteraction: !!d.critical
  };
  e.waitUntil(self.registration.showNotification(d.title || 'KIVU Tracker', opts));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/app';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((cs) => {
    for (const c of cs) {
      if ('focus' in c) { c.navigate(url); return c.focus(); }
    }
    if (self.clients.openWindow) return self.clients.openWindow(url);
    return undefined;
  }));
});
