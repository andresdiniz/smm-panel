const CACHE_NAME = 'smm-panel-public-v3';
const CACHE_PREFIX = 'smm-panel-public-';
const URLS_TO_CACHE = ['/servicos', '/como-funciona', '/manifest.webmanifest'];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(URLS_TO_CACHE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys
        .filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
        .map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }

  if (request.mode === 'navigate' && URLS_TO_CACHE.includes(url.pathname)) {
    event.respondWith(
      fetch(request)
        .then(response => {
          const cacheControl = response.headers.get('Cache-Control') || '';
          if (response.ok && /\bpublic\b/i.test(cacheControl) && !/\bprivate\b|\bno-store\b/i.test(cacheControl)) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
          }

          return response;
        })
        .catch(async () => (await caches.match(request)) || new Response('Página indisponível offline.', {
          status: 503,
          headers: { 'Content-Type': 'text/plain; charset=utf-8' }
        }))
    );
  }
});

self.addEventListener('push', event => {
  if (!event.data) return;

  let payload;
  try {
    payload = event.data.json();
  } catch (error) {
    console.error('Notificação push ignorada: payload inválido.', error);
    return;
  }

  const title = typeof payload.title === 'string' ? payload.title : 'SMM Panel';
  const body = typeof payload.body === 'string' ? payload.body : '';
  const tag = typeof payload.category === 'string' ? 'smm-panel-' + payload.category : 'smm-panel';
  const notificationUrl = new URL(typeof payload.url === 'string' ? payload.url : '/', self.location.origin);
  const safeUrl = notificationUrl.origin === self.location.origin
    ? notificationUrl.pathname + notificationUrl.search + notificationUrl.hash
    : '/';

  event.waitUntil(self.registration.showNotification(title, {
    body,
    icon: '/favicon.svg',
    badge: '/favicon.svg',
    tag,
    data: { url: safeUrl },
    renotify: false
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const requestedUrl = new URL(event.notification.data && event.notification.data.url || '/', self.location.origin);
  const targetUrl = requestedUrl.origin === self.location.origin ? requestedUrl.href : self.location.origin + '/';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
      for (const client of windowClients) {
        if (new URL(client.url).origin === self.location.origin) {
          return client.navigate(targetUrl).then(() => client.focus());
        }
      }

      return clients.openWindow(targetUrl);
    })
  );
});
