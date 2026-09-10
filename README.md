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
- **Self-service install wizard**: language, database, RCON and
  Steam/owner setup — no manual SQL, no hand-edited `.env`.
- **Updates from the panel**: Settings → Updates installs a new release in
  place, keeping your settings, uploads and plugins, and puts the previous
  version back by itself if anything fails.
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
| MySQL / MariaDB | 8.0 / 10.6 | the database your CS2 plugins already use - the panel creates its own tables in it; the user needs read/write plus `CREATE` on it |
| Web server | Apache or nginx | document root must point at `public/` |

You also need **Steam Web API credentials** (a key from
[steamcommunity.com/dev/apikey](https://steamcommunity.com/dev/apikey)) and
the **panel owner's Steam profile link** (the address of their Steam
profile page — `/id/<name>` or `/profiles/<id>` both work) — the wizard asks
for both, and links to where each one comes from.

## Installation

### Quick install (Ubuntu)

```bash
curl -fsSL https://raw.githubusercontent.com/candaysa/S2_Panel/main/install.sh | sudo bash
```

It asks for your domain and an email for Let's Encrypt, shows what it is
about to do, and waits for you to confirm. Then it installs whatever is
missing (PHP 8.3 and its extensions, Composer, Node 20+, nginx), pulls this
repo and builds it, points nginx at `public/`, requests an HTTPS
certificate, and schedules the panel's background tasks. Leave the email
blank to install without SSL. Safe to re-run — on an installed panel a
re-run updates it.

**It creates no database.** The panel keeps its tables in the database your
CS2 plugins already use; the setup wizard asks for it and creates the
panel's tables there.

When it finishes, open `https://your-domain/install`.

### Setup wizard

Any URL redirects to `/install` until setup finishes. The wizard walks
through:

1. **Language** — the panel's default locale, and its name.
2. **Database** — the database your CS2 plugins use. The connection is
   tested, then the panel's own tables are created in it; all five
   connections (the panel's and the four plugins') point there.
3. **RCON** — optional: one password for the servers the admin plugin has
   registered, or set them per server later.
4. **Steam & owner** — Steam Web API key and the owner's Steam profile
   link (a raw SteamID works too). A custom `/id/<name>` link is looked up
   through Steam with that key, which also confirms the key works. The owner
   always has full access, independent of the plugin's flags.
5. **Done** — `INSTALLED=true` is written, sessions and cache move to the
   database, and `/install` starts returning 404.

Which modules are on is not part of setup — that is an ongoing decision,
made from the Modules tab once you have logged in.

### After installing

The **Webhook** module dispatches Discord deliveries onto the queue, so it
also needs a worker (or set `QUEUE_CONNECTION=sync` to send them inline):

```bash
php artisan queue:work --queue=default
```

<details>
<summary><strong>Manual installation</strong> (not on Ubuntu, or doing it by hand)</summary>

### 1. Get the code and its dependencies

```bash
git clone https://github.com/candaysa/S2_Panel.git
cd S2_Panel
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

For a development checkout use `composer install` (keep dev dependencies)
and `npm run dev` instead of `npm run build`.

### 2. The database: nothing to create

The panel lives in the database your CS2 plugins already use (Swiftly
admin, K4-LevelRanks-SwiftlyS2, weapon skins, VIPCore) — there is no
separate panel database to create. The install wizard asks for it and
creates the panel's own tables in it (`users`, `sessions`, `cache`, `jobs`,
`settings`, `panel_logs`, `reports`, `appeals`, `cheat_scans`, …). None of
those names is used by a plugin (`admin_*`, `lvl_base*`, `vip_*`,
`wp_player_*`), so they sit next to the plugin tables without touching them.

Two things to check first:

- the MySQL user needs `CREATE` on that database, not just read/write — the
  wizard reports the database's own error if it cannot create a table;
- the database must not still hold **another web panel's** tables (a Laravel
  `migrations` table listing migrations that are not this panel's — an older
  panel, typically). The wizard refuses such a database rather than
  migrating over it, and names the migrations that are in the way.

### 3. Create the environment file

```bash
cp .env.example .env
php artisan key:generate
```

Set these in `.env`:

```
APP_URL=https://panel.example.com
APP_ENV=production
APP_DEBUG=false
```

`APP_URL` must match the address the panel is actually served from — the
Steam login returns to it, so a wrong value breaks login.

Leave everything else alone. `DB_*` is written by the wizard's database
step, and `STEAM_*` / `OWNER_STEAM_ID` by its Steam step. `.env.example`
ships with `SESSION_DRIVER=file` and `CACHE_STORE=file` on purpose: there is
no database for the panel until the wizard creates its tables, and the
wizard itself has to load first. Finishing the wizard switches both to
`database`.

### 4. Point the web server at `public/`

The document root must be `public/`, never the project root — everything
above it (including `.env`) would otherwise be downloadable.

<details>
<summary>nginx</summary>

```nginx
server {
    listen 80;
    server_name panel.example.com;
    root /var/www/S2_Panel/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```
</details>

<details>
<summary>Apache</summary>

Enable `mod_rewrite`, then point the vhost at `public/`. The bundled
`public/.htaccess` handles the rest.

```apache
<VirtualHost *:80>
    ServerName panel.example.com
    DocumentRoot /var/www/S2_Panel/public

    <Directory /var/www/S2_Panel/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
</details>

On Linux, give the web server user ownership of the two writable
directories:

```bash
chown -R www-data:www-data storage bootstrap/cache
```

### 5. Schedule background tasks

The scheduler drives the health checks, RCON verification, the server
activity chart and Steam profile warming. Add it **as the web server's user,
never root** — a root run creates `storage/` files (the log first) that
php-fpm then cannot write, and every request that needs to log an error
fails:

```bash
# /etc/cron.d/s2panel
* * * * * www-data cd /var/www/S2_Panel && php artisan schedule:run >> /dev/null 2>&1
```

Then open the panel in a browser and follow the [setup wizard](#setup-wizard).

</details>

### Updating from the panel

**Settings → Updates** (owner only) checks GitHub Releases for a newer
version and installs it in place — no SSH. Before a single file changes,
everything it would overwrite or delete is copied aside; if the install or
its migrations fail, that copy is put back automatically. Visitors see a
maintenance page for the minute or so it takes.

| | |
|---|---|
| Replaced | application code, `vendor/`, `public/build` |
| Never touched | `.env`, `storage/`, `public/uploads`, installed plugins — anything a release does not ship |
| Database | `migrate --force` only — forward, additive |
| Git checkout | moved to the release's tag, so a later `install.sh` re-run pulls cleanly |

The page lists what the server needs before it offers the button: the web
server's user must be able to write to the install directory (`install.sh`
sets that up) and there must be about 512 MB of free disk.

If an update is interrupted — the server restarted half way, say — the page
offers **Finish update** or **Roll back**. Over SSH, the same roll-back is:

```bash
sudo -u www-data php artisan panel:update-rollback
```

Set `PANEL_UPDATE_ENABLED=false` to turn the updater off.

#### Publishing a release

Bump `version` in `config/panel.php`, commit, then push a matching tag:

```bash
git tag v1.2.3 && git push origin v1.2.3
```

The [Release workflow](.github/workflows/release.yml) refuses a tag that
does not match `config/panel.php`, builds the bundle with `vendor/` and the
compiled assets, and attaches it as `s2panel-1.2.3.tar.gz`. The updater only
installs that asset — never GitHub's source archive, which has neither —
and checks it against the SHA-256 digest GitHub publishes for it.

### Upgrading manually

On Ubuntu, re-running the install command is the upgrade: on an installed
panel it pulls, rebuilds and migrates without touching `.env` or the
database's data. By hand:

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
```

### Troubleshooting

| Symptom | Cause |
|---|---|
| `/install` itself throws a database error | `SESSION_DRIVER` / `CACHE_STORE` are set to `database` in `.env` before install. They have to stay `file` until the wizard finishes — it is what creates the database tables they would need. |
| Database step: "already holds another web application's tables" | The database still has an older panel in it. Remove that panel's tables (the error names its migrations) or use a database only the CS2 plugins write to. |
| Database step: "could not create its tables" | Usually the MySQL user lacks `CREATE` on that database, or a table with one of the panel's names already exists — the database's own message is shown next to it. |
| Every URL redirects to `/install` | Setup never completed — `INSTALLED` is not `true` in `.env`. |
| `/install` returns 404 | Setup already completed. This is deliberate: it stops anyone re-running the wizard and overwriting your credentials. |
| Steam login returns to a wrong or broken URL | `APP_URL` does not match the address you are browsing, or `STEAM_CALLBACK_URL` is not `<APP_URL>/api/auth/callback`. |
| Blank page / 500 after deploying | Stale caches. Run `php artisan optimize:clear`, fix the issue, then re-cache. |
| Styles missing | `npm run build` was never run, or `public/build` was not uploaded. |
| `.env` edits have no effect | A cached config is in use — run `php artisan optimize:clear`. |

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
