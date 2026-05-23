const CACHE_NAME = 'citadel-v4';

// Only cache these static assets
const STATIC_ASSETS = [
  '/assets/chart.min.js',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  // Always network-first for PHP pages and APIs
  if (e.request.url.includes('.php') || e.request.url.includes('/api/')) {
    e.respondWith(fetch(e.request).catch(() => new Response('Offline', {status: 503})));
    return;
  }
  // Cache-first only for chart.min.js
  if (e.request.url.includes('chart.min.js')) {
    e.respondWith(
      caches.match(e.request).then(cached => cached || fetch(e.request))
    );
    return;
  }
  // Everything else: network first
  e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
});

self.addEventListener('push', e => {
  const data = e.data?.json() || {};
  e.waitUntil(
    self.registration.showNotification(data.title || 'Citadel', {
      body: data.body || 'New notification',
      icon: '/assets/icon-192.png',
      data: data.url || '/',
      vibrate: [200, 100, 200],
    })
  );
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  e.waitUntil(clients.openWindow(e.notification.data || '/'));
});
