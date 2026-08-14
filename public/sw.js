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

const VERSION = 'v1';
const ASSET_CACHE = `s2panel-assets-${VERSION}`;
const SHELL_CACHE = `s2panel-shell-${VERSION}`;
const OFFLINE_URL = '/offline.html';

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
                    .filter((key) => key !== ASSET_CACHE && key !== SHELL_CACHE)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Never touch anything cross-origin, and never cache the API - a cached
    // /api/dashboard would show yesterday's numbers as if they were current.
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
