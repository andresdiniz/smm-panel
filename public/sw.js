const CACHE_NAME = 'smm-panel-public-v1';
const URLS_TO_CACHE = ['/', '/login', '/robots.txt', '/sitemap.xml', '/manifest.webmanifest'];
self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(URLS_TO_CACHE)));
});
self.addEventListener('fetch', event => {
  event.respondWith(caches.match(event.request).then(response => response || fetch(event.request).catch(() => caches.match('/'))));
});
