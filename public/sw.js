/**
 * Minimal service worker: only enables PWA installability and speeds up
 * repeat loads of static assets (CSS/JS/icons). Deliberately does NOT
 * cache HTML pages or /api/* responses - this app is server-rendered and
 * session/CSRF-dependent, so caching those would risk serving stale or
 * cross-session content.
 */
const CACHE_NAME = 'pm-static-v1';

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || !url.pathname.startsWith('/assets/')) {
    return; // let the browser/network handle pages and API calls normally
  }

  event.respondWith(
    caches.open(CACHE_NAME).then(async (cache) => {
      const cached = await cache.match(request);
      if (cached) {
        fetch(request).then((response) => {
          if (response.ok) cache.put(request, response.clone());
        }).catch(() => {});
        return cached;
      }
      const response = await fetch(request);
      if (response.ok) cache.put(request, response.clone());
      return response;
    })
  );
});

self.addEventListener('push', (event) => {
  let payload = { title: 'Notification', body: '' };
  try {
    payload = event.data.json();
  } catch (e) {
    // Non-JSON push payload: fall back to the default above.
  }

  event.waitUntil(
    self.registration.showNotification(payload.title || 'Notification', {
      body: payload.body || '',
      icon: '/assets/icons/icon.svg',
      data: { url: payload.url || '/' },
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url.endsWith(url) && 'focus' in client) {
          return client.focus();
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(url);
      }
    })
  );
});
