const CACHE_VERSION = 'citizen-pwa-v3';
const STATIC_CACHE = `${CACHE_VERSION}-static`;

const STATIC_PATHS = [
    '/citizen/offline',
    '/citizen.webmanifest',
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
        Promise.all([
            bestEffort(async () => (await caches.open(STATIC_CACHE)).addAll(STATIC_PATHS)),
            self.skipWaiting(),
        ]),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        Promise.all([bestEffort(async () => {
            const keys = await caches.keys();
            await Promise.all(
            keys
                .filter((key) => (key.startsWith('caller-pwa-') || key.startsWith('citizen-pwa-')) && key !== STATIC_CACHE)
                .map((key) => bestEffort(() => caches.delete(key))),
            );
        }), self.clients.claim()]),
    );
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

    if (request.mode === 'navigate' && url.pathname.startsWith('/citizen')) {
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
        const cached = await bestEffort(() => caches.match('/citizen/offline'));

        if (cached) {
            return cached;
        }

        throw error;
    }
}

async function networkFirst(request) {
    let response;
    try {
        response = await fetch(request);
    } catch (error) {
        const cached = await bestEffort(() => caches.match(request));

        if (cached) {
            return cached;
        }

        throw error;
    }

    if (response.ok) {
        await bestEffort(async () => {
            const cache = await caches.open(STATIC_CACHE);
            await cache.put(request, response.clone());
        });
    }
    return response;
}

async function bestEffort(operation) {
    try {
        return await operation();
    } catch {
        // Offline storage is optional; never replace a network response/error with a cache error.
        return undefined;
    }
}
