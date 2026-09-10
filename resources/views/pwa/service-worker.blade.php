const CACHE_NAME = @json($cacheName);
const OFFLINE_TITLE = @json($offlineTitle);
const OFFLINE_MESSAGE = @json($offlineMessage);
const OFFLINE_RETRY_LABEL = @json($offlineRetryLabel);
const CORE_ASSETS = [
    '/favicon.ico',
    '/images/logo.png',
    '/images/icons/icon-192.png',
    '/images/icons/icon-512.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(CORE_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => new Response(
                `<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${OFFLINE_TITLE}</title></head><body style="font-family:sans-serif;padding:32px;background:#0f172a;color:#f8fafc"><h1>${OFFLINE_TITLE}</h1><p>${OFFLINE_MESSAGE}</p><button type="button" id="offline-retry">${OFFLINE_RETRY_LABEL}</button><script>const retry=document.getElementById('offline-retry');retry.addEventListener('click',()=>window.location.reload());window.addEventListener('online',()=>window.location.reload());</script></body></html>`,
                { headers: { 'Content-Type': 'text/html; charset=UTF-8' } }
            ))
        );
        return;
    }

    const isCacheableAsset = [
        '/build/',
        '/images/',
        '/favicon.ico'
    ].some((path) => url.pathname.startsWith(path));

    if (! isCacheableAsset) {
        return;
    }

    event.respondWith(
        caches.match(request).then((cachedResponse) => {
            if (cachedResponse) {
                return cachedResponse;
            }

            return fetch(request).then((networkResponse) => {
                if (! networkResponse || networkResponse.status !== 200) {
                    return networkResponse;
                }

                const responseToCache = networkResponse.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(request, responseToCache));

                return networkResponse;
            });
        })
    );
});
