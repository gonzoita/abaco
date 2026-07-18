const CACHE_NAME = 'antigravity-finanzas-v1';
const ASSETS = [
  './',
  './index.html',
  './manifest.json',
  // Los archivos compilados de JS y CSS se cachearán dinámicamente en tiempo de ejecución
];

// Instalar Service Worker y guardar en caché archivos estáticos básicos
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS);
    }).then(() => self.skipWaiting())
  );
});

// Activar SW y limpiar cachés antiguas
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Estrategia de Fetch: Cache-First para estáticos, Network-Only para la API
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Evitar cachear llamadas a la API o recursos externos (como la API de Gemini)
  if (url.pathname.includes('/backend/api/') || url.hostname.includes('googleapis.com')) {
    event.respondWith(fetch(event.request));
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cachedResponse) => {
      if (cachedResponse) {
        return cachedResponse;
      }
      
      return fetch(event.request).then((networkResponse) => {
        // Guardar dinámicamente los activos estáticos del frontend (CSS, JS) en caché
        if (event.request.method === 'GET' && networkResponse.status === 200) {
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseToCache);
          });
        }
        return networkResponse;
      });
    }).catch(() => {
      // Offline fallback para páginas HTML
      if (event.request.mode === 'navigate') {
        return caches.match('./index.html');
      }
    })
  );
});
