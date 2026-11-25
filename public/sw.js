const STATIC_CACHE = 'syncviet-static-v1';
const OFFLINE_URL = '/offline.html';

const PRECACHE_ASSETS = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/favicon.svg',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

// Sensitive routes and prefixes that MUST NEVER be cached by the Service Worker
const FORBIDDEN_PREFIXES = [
    '/admin',
    '/messages',
    '/wallet',
    '/ads',
    '/payments',
    '/webhooks',
    '/settings',
    '/notifications',
    '/login',
    '/register',
    '/logout',
    '/two-factor',
    '/forgot-password',
    '/reset-password',
    '/email',
    '/api',
];

function isCacheableStaticAsset(url) {
    if (url.origin !== self.location.origin) {
        return false;
    }

    const path = url.pathname;
    if (FORBIDDEN_PREFIXES.some((prefix) => path.startsWith(prefix))) {
        return false;
    }

    return (
        path.startsWith('/build/') ||
        path.startsWith('/icons/') ||
        path === '/manifest.webmanifest' ||
        path === '/favicon.ico' ||
        path === '/favicon.svg' ||
        path === '/apple-touch-icon.png' ||
        path === OFFLINE_URL
    );
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE_ASSETS))
            .catch(() => undefined),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key !== STATIC_CACHE)
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Never intercept mutations, payments, or Inertia/XHR data calls
    if (
        request.method !== 'GET' ||
        request.headers.has('X-Inertia') ||
        request.headers.get('X-Requested-With') === 'XMLHttpRequest'
    ) {
        return;
    }

    const url = new URL(request.url);

    // Navigation requests: network-first with offline fallback, NEVER cache HTML
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(async () => {
                const cachedOffline = await caches.match(OFFLINE_URL);
                return (
                    cachedOffline ||
                    new Response('Offline', {
                        status: 503,
                        headers: {
                            'Content-Type': 'text/plain; charset=utf-8',
                        },
                    })
                );
            }),
        );
        return;
    }

    // Strict allowlist for immutable static assets only
    if (!isCacheableStaticAsset(url)) {
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(request).then((response) => {
                if (response && response.ok && response.type === 'basic') {
                    const copy = response.clone();
                    caches
                        .open(STATIC_CACHE)
                        .then((cache) => cache.put(request, copy))
                        .catch(() => undefined);
                }
                return response;
            });
        }),
    );
});
