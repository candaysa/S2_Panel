/*
 * S2 Panel service worker.
 *
 * Deliberately conservative: the panel shows live moderation data, so
 * serving a stale ban list or player count from cache would be worse than
 * showing nothing. Only the build assets (content-hashed by Vite, therefore
 * safe to keep forever) are cached, plus an offline fallback page.
 *
 * Every navigation and every API call goes to the network first.
 */

const VERSION = 'v3';
const ASSET_CACHE = `s2panel-assets-${VERSION}`;
const SHELL_CACHE = `s2panel-shell-${VERSION}`;
const MODEL_CACHE = `s2panel-models-${VERSION}`;
const OFFLINE_URL = '/offline.html';

// 3D weapon models and their paint textures. Immutable per URL (a given
// weapon+paint is always the same file), but the host serves them with
// Cache-Control: max-age=300 - so without this, reopening the same
// weapon's 3D view six minutes later re-downloads several megabytes.
// Cached here instead, capped so browsing a lot of weapons cannot grow
// unbounded on disk.
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

        // cache.keys() returns insertion order, so trimming from the front
        // evicts the least recently added.
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

// The page calls update() on every load; when that finds a new worker we
// want it live now, not after every tab using the old one has closed.
self.addEventListener('message', (event) => {
    if (event.data === 'skipWaiting') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // The one cross-origin exception: 3D model/texture assets, which are
    // immutable per URL but served with a 5-minute max-age.
    if (url.hostname === MODEL_HOST && url.pathname.includes(MODEL_PATH_HINT)) {
        event.respondWith(cacheFirstCapped(request));
        return;
    }

    // Otherwise never touch anything cross-origin, and never cache the API -
    // a cached /api/dashboard would show yesterday's numbers as if they were
    // current.
    if (url.origin !== self.location.origin) return;
    if (url.pathname.startsWith('/api/')) return;

    // Build assets carry a content hash in the filename, so a cache hit can
    // never be stale: a changed file is a different URL.
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

    // Pages: network first, offline page only when the network genuinely
    // fails. No stale HTML is ever served while online.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );
    }
});
