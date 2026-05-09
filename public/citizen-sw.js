const CACHE_VERSION = 'citizen-pwa-v2';
const STATIC_CACHE = `${CACHE_VERSION}-static`;

const STATIC_PATHS = [
    '/citizen/offline',
    '/caller/offline',
    '/citizen.webmanifest',
    '/caller.webmanifest',
    '/images/logo.png',
    '/favicon-192.png',
    '/favicon-512.png',
    '/apple-touch-icon.png',
];

const NEVER_CACHE_PREFIXES = [
    '/api/',
    '/broadcasting/',
    '/login',
    '/logout',
    '/sanctum/',
    '/storage/',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll(STATIC_PATHS)),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys
                .filter((key) => (key.startsWith('caller-pwa-') || key.startsWith('citizen-pwa-')) && key !== STATIC_CACHE)
                .map((key) => caches.delete(key)),
        )),
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (NEVER_CACHE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
        return;
    }

    if (request.mode === 'navigate' && (url.pathname.startsWith('/citizen') || url.pathname.startsWith('/caller'))) {
        event.respondWith(networkFirstNavigation(request));
        return;
    }

    if (url.pathname.startsWith('/build/assets/') || STATIC_PATHS.includes(url.pathname)) {
        event.respondWith(networkFirst(request));
    }
});

async function networkFirstNavigation(request) {
    try {
        return await fetch(request);
    } catch (error) {
        const cached = await caches.match('/citizen/offline')
            ?? await caches.match('/caller/offline');

        if (cached) {
            return cached;
        }

        throw error;
    }
}

async function networkFirst(request) {
    try {
        const response = await fetch(request);

        if (response.ok) {
            const cache = await caches.open(STATIC_CACHE);
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        const cached = await caches.match(request);

        if (cached) {
            return cached;
        }

        throw error;
    }
}
