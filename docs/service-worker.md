# Service worker (`public/sw.js`)

`public/sw.js` and `public/offline.html` are served to browsers as-is, so
they carry no comments — the reasoning behind them lives here instead.

The worker is deliberately conservative: the panel shows live moderation
data, and serving a stale ban list or player count from cache would be worse
than showing nothing. Every navigation and every API call goes to the
network first.

What it does cache:

- **Build assets** (`/build/…`) — content-hashed by Vite, so a cache hit can
  never be stale: a changed file is a different URL.
- **The offline page** (`/offline.html`) — shown only when a navigation
  genuinely fails. It is self-contained (inline styles) because it has to
  render when the network is gone and cannot pull the app stylesheet. No
  stale HTML is ever served while online.
- **3D weapon models and paint textures** from `raw.githubusercontent.com`
  — immutable per URL, but served with `Cache-Control: max-age=300`, so
  reopening a weapon's 3D view a few minutes later would re-download several
  megabytes. Cached first, capped at 24 entries; `cache.keys()` returns
  insertion order, so trimming from the front evicts the oldest.

What it never touches: anything else cross-origin, and `/api/*` — a cached
`/api/dashboard` would show yesterday's numbers as if they were current.

On `activate` it deletes every cache that is not one of the three current
ones, so bumping `VERSION` retires old caches. The page calls `update()` on
every load and posts `skipWaiting` when a new worker is found, so an update
goes live immediately rather than after every tab using the old worker has
closed.
