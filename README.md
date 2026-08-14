# S2 Panel

A modular admin panel for Counter-Strike 2 servers running the **Swiftly**
plugin ecosystem — CS2_Admin, CS2_Ranks, weapon skins, and VIPCore. It reads
and writes those plugins' own database tables directly; no separate agent
or bridge is required on the game server.

## Features

- **Steam OpenID login** with an owner role (assigned during setup) plus
  live authorization against the Swiftly admin plugin's flags/groups.
- **Moderation**: admins & groups, bans/mutes/gags/warns, player reports
  and admin applications (ticket system), ban appeals, an RCON console
  with kick/ban/slay shortcuts, and a full audit log.
- **Community**: VIP group management (VIPCore), player ranks (CS2_Ranks),
  and weapon skin loadouts.
- **Operations**: server list with live A2S queries, health monitoring
  with owner notifications, Discord webhook notifications, and panel-wide
  stats.
- **Modules tab**: turn built-in features (VIP/Skins/Ranks) on or off at
  runtime, no redeploy needed.
- **Plugins tab**: install third-party plugins as a `.zip`, right from the
  panel — see [docs/plugin-development.md](docs/plugin-development.md) for
  how to build one.
- **Self-service install wizard**: language, database connections,
  Steam/owner setup, and module selection — no manual SQL required.
- 6-language UI (English, Turkish, German, French, Italian, Russian),
  dark/light theme, and an owner-customizable accent color.

## Stack

Laravel 13 (PHP 8.3+) · Blade + Alpine.js + Tailwind CSS v4 · MySQL (one
connection per plugin database) · Vite.

## Getting started

```bash
composer install
npm install
npm run build       # or `npm run dev` while developing
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Then open the panel in a browser and follow the install wizard — it walks
through choosing a language, connecting the panel to its own database plus
the Swiftly/CS2_Ranks/weapon-skins/VIPCore databases, Steam OpenID
credentials, the panel owner's SteamID, and which built-in modules to
enable. Nothing here is entered by hand-editing `.env`.

## Architecture

Every feature is its own package under `app/Modules/*` (a `ServiceProvider`
extending `App\Support\ModuleServiceProvider`, its own routes, controllers,
models and — for panel-owned data — migrations). A module is gated on/off
via `config/modules.php`; a curated subset can additionally be toggled at
runtime from the **Modules** tab without touching `.env`. Third-party
plugins (see **Plugins** tab) follow the exact same shape, just discovered
from the database instead of being compiled into the app.

## Tests

```bash
php artisan test
```

## License

Not yet decided.
