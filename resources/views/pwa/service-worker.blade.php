const CACHE_NAME = @json($cacheName);
const CACHE_PREFIX = 'furimadeck-pwa-';
const OFFLINE_TITLE = @json($offlineTitle);
const OFFLINE_MESSAGE = @json($offlineMessage);
const OFFLINE_RETRY_LABEL = @json($offlineRetryLabel);
const OFFLINE_CSP_NONCE = @json(Vite::cspNonce());
const CORE_ASSETS = [
    '/favicon.ico?v=3',
    '/images/logo.png',
    '/images/icons/icon-192.png?v=3',
    '/images/icons/icon-512.png?v=3'
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
                    .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
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
                `<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${OFFLINE_TITLE}</title></head><body style="font-family:sans-serif;padding:32px;background:#0f172a;color:#f8fafc"><main><h1>${OFFLINE_TITLE}</h1><p>${OFFLINE_MESSAGE}</p><button type="button" id="offline-retry">${OFFLINE_RETRY_LABEL}</button></main><script nonce="${OFFLINE_CSP_NONCE}">const retry=document.getElementById('offline-retry');retry.addEventListener('click',()=>window.location.reload());window.addEventListener('online',()=>window.location.reload());</script></body></html>`,
                { headers: {
                    'Content-Type': 'text/html; charset=UTF-8',
                    'Content-Security-Policy': `default-src 'none'; script-src 'nonce-${OFFLINE_CSP_NONCE}'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'`
                } }
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

    let cacheWritePromise = Promise.resolve();
    const responsePromise = caches.match(request).then((cachedResponse) => {
        if (cachedResponse) {
            return cachedResponse;
        }

        return fetch(request).then((networkResponse) => {
            if (! networkResponse || networkResponse.status !== 200) {
                return networkResponse;
            }

            const responseToCache = networkResponse.clone();
            cacheWritePromise = caches.open(CACHE_NAME).then((cache) => cache.put(request, responseToCache));

            return networkResponse;
        });
    });

    event.respondWith(responsePromise);
    event.waitUntil(responsePromise.then(() => cacheWritePromise).catch(() => undefined));
});
