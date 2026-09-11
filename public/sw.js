const VERSION = 'v3';
const ASSET_CACHE = `s2panel-assets-${VERSION}`;
const SHELL_CACHE = `s2panel-shell-${VERSION}`;
const MODEL_CACHE = `s2panel-models-${VERSION}`;
const OFFLINE_URL = '/offline.html';

const MODEL_HOST = 'raw.githubusercontent.com';
const MODEL_PATH_HINT = '/LielXD/CS2-WeaponPaints-Website/';
const MODEL_CACHE_MAX_ENTRIES = 24;

async function cacheFirstCapped(request) {
    const cache = await caches.open(MODEL_CACHE);
    const hit = await cache.match(request);
    if (hit) return hit;

    const response = await fetch(request);

    if (response.ok) {
        await cache.put(request, response.clone());

        const keys = await cache.keys();
        if (keys.length > MODEL_CACHE_MAX_ENTRIES) {
            await Promise.all(
                keys.slice(0, keys.length - MODEL_CACHE_MAX_ENTRIES).map((key) => cache.delete(key)),
            );
        }
    }

    return response;
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            .then((cache) => cache.addAll([OFFLINE_URL]))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key !== ASSET_CACHE && key !== SHELL_CACHE && key !== MODEL_CACHE)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data === 'skipWaiting') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.hostname === MODEL_HOST && url.pathname.includes(MODEL_PATH_HINT)) {
        event.respondWith(cacheFirstCapped(request));
        return;
    }

    if (url.origin !== self.location.origin) return;
    if (url.pathname.startsWith('/api/')) return;

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then((hit) => hit ?? fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(ASSET_CACHE).then((cache) => cache.put(request, copy));
                }
                return response;
            })),
        );
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );
    }
});
