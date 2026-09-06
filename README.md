# S2 Panel

A modular admin panel for Counter-Strike 2 servers running the **Swiftly**
plugin ecosystem — CS2_Admin, K4-LevelRanks-SwiftlyS2, weapon skins, and
VIPCore. It reads and writes those plugins' own database tables directly;
no separate agent or bridge is required on the game server.

## Features

- **Steam OpenID login** with an owner role (assigned during setup) plus
  live authorization against the Swiftly admin plugin's flags/groups.
- **Moderation**: admins & groups, bans/mutes/gags/warns, player reports
  and admin applications (ticket system), ban appeals, an RCON console
  with kick/ban/slay shortcuts, and a full audit log.
- **Community**: VIP group management (VIPCore), player ranks
  (K4-LevelRanks-SwiftlyS2), and weapon skin loadouts.
- **Operations**: server list with live A2S queries, health monitoring
  with owner notifications, Discord webhook notifications, and panel-wide
  stats.
- **Cheat check**: issue a one-off link a player runs in PowerShell
  (`irm '<panel>/checkcheat.ps1/<token>' | iex`). A 20-layer scanner checks
  running processes, injected modules, kernel drivers, DMA/KMBox hardware,
  execution history (Prefetch/Amcache/BAM/UserAssist), archives, download
  provenance and browser history, then reports its findings back to the
  panel. Heuristic hits are reported as *suspicious* rather than *cheat*, so
  nobody is auto-flagged on a hunch.
- **Modules tab**: turn optional features (VIP, Skins, Ranks, Tickets,
  RCON, Cheat check) on or off at runtime, no redeploy needed. Switching one
  off takes its pages and its API with it, not just the nav entry.
- **Plugins tab**: install third-party plugins as a `.zip`, right from the
  panel. A plugin is just a module in a zip — same base class, same layout;
  see [docs/module-development.md](docs/module-development.md) for how to
  build either, or run `php artisan make:module Trophy` to scaffold one.
- **Self-service install wizard**: language, database connections,
  Steam/owner setup, and module selection — no manual SQL required.
- **Restore from backup**: a previously downloaded `backup.zip` can be
  uploaded on the wizard's very first screen to skip it entirely and
  restore straight into a working panel — database connections, Steam
  credentials, the owner's SteamID, module toggles, every table the panel
  owns, and the logo/favicon.
- 8-language UI (English, Turkish, German, French, Italian, Russian,
  Hungarian, Polish), dark/light theme, and an owner-customizable accent
  color.

## Stack

Laravel 13 (PHP 8.3+) · Blade + Alpine.js + Tailwind CSS v4 · MySQL (one
connection per plugin database) · Vite.

## Requirements

| | Minimum | Notes |
|---|---|---|
| PHP | 8.3 | with `pdo_mysql`, `mbstring`, `openssl`, `zip`, `fileinfo`, `curl` |
| Composer | 2.x | |
| Node.js | 20+ | build-time only — not needed on the production server if you upload `public/build` |
| MySQL / MariaDB | 8.0 / 10.6 | the panel's own database, plus read/write access to each Swiftly plugin database |
| Web server | Apache or nginx | document root must point at `public/` |

You also need **Steam Web API credentials** (a key from
[steamcommunity.com/dev/apikey](https://steamcommunity.com/dev/apikey)) and
the **SteamID64 of the panel owner** — the wizard asks for both.

## Installation

On a bare Ubuntu server, one command gets you to a running panel:

```bash
sudo bash <(curl -fsSL https://raw.githubusercontent.com/candaysa/S2_Panel/main/install.sh)
```

It'll ask for a domain and an email (for HTTPS), then take care of the rest:
PHP 8.3 + extensions, Composer, Node 20+, MySQL and nginx (installing
whichever are missing), the repo itself, `composer`/`npm`, the panel's own
database, a minimal `.env`, migrations, the nginx vhost, and a Let's Encrypt
certificate. Safe to re-run — every step checks what's already there first.

It ends by printing a URL to **`/install`**. That's the actual setup: the
script above only gets the app *running* — every credential the panel
itself needs (each Swiftly plugin's database connection, your Steam API
key, the owner's SteamID64, which modules to enable) is entered there, in
the wizard, never on the command line. Until that finishes, every other URL
redirects to `/install`.

> Already have a `backup.zip` from another install? Upload it on the
> wizard's very first screen — database connections, Steam credentials,
> module toggles and all — to skip straight to a working panel instead of
> stepping through it (see **Restore from backup** under Features above).

Scripting this (CI, many servers) or need it to run non-interactively?
Pass the flags up front:

```bash
sudo bash <(curl -fsSL https://raw.githubusercontent.com/candaysa/S2_Panel/main/install.sh) \
  --domain panel.example.com --email you@example.com --yes
```

`--help` lists the rest (install directory, branch, DB name, `--skip-ssl`, …).

Not on Ubuntu, want every step by hand, or need to update/troubleshoot an
existing install? See **[docs/installation.md](docs/installation.md)**.

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

## Contributing

Issues and pull requests are welcome. There's no formal process yet — open
an issue to discuss a change before sending a large PR.

## License

[MIT](LICENSE) — do whatever you like with it, just keep the copyright
notice.
