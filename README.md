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
- **Cheat check**: issue a one-off link a player runs in PowerShell
  (`irm '<panel>/checkcheat.ps1/<token>' | iex`). A 20-layer scanner checks
  running processes, injected modules, kernel drivers, DMA/KMBox hardware,
  execution history (Prefetch/Amcache/BAM/UserAssist), archives, download
  provenance and browser history, then reports its findings back to the
  panel. Heuristic hits are reported as *suspicious* rather than *cheat*, so
  nobody is auto-flagged on a hunch.
- **Modules tab**: turn built-in features (VIP/Skins/Ranks) on or off at
  runtime, no redeploy needed.
- **Plugins tab**: install third-party plugins as a `.zip`, right from the
  panel — see [docs/plugin-development.md](docs/plugin-development.md) for
  how to build one.
- **Self-service install wizard**: language, database connections,
  Steam/owner setup, and module selection — no manual SQL required. A
  previously downloaded `backup.zip` can be uploaded on the very first
  screen to skip the wizard entirely and restore straight into a working
  panel (see **Backup & restore** below).
- **Backup & restore**: Settings > Backup downloads a `backup.zip` —
  database connections, Steam credentials, the owner's SteamID, module
  toggles, every table the panel owns, and the logo/favicon — for disaster
  recovery or moving to a new server.
- 6-language UI (English, Turkish, German, French, Italian, Russian),
  dark/light theme, and an owner-customizable accent color.

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

### 2. Create the panel database

```sql
CREATE DATABASE s2_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Only the panel's own database has to be created by hand. The plugin
databases (Swiftly admin, CS2_Ranks, weapon skins, VIPCore) already exist —
the panel only reads and writes their tables.

### 3. Create the environment file

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set two things:

```
APP_URL=https://panel.example.com
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=s2_panel
DB_USERNAME=s2_panel
DB_PASSWORD=…
```

`APP_URL` must match the address the panel is actually served from — Steam
OpenID redirects back to it, so a wrong value breaks login.

The `DB_*` values are needed **before** the wizard, not after: sessions,
cache and queue all use the `database` driver, so the panel cannot render a
single page — including `/install` — until it can reach its own database.
The wizard asks for these credentials again on its Database step; that is
where they get written permanently, along with the four plugin connections.

Leave `STEAM_*` and `MODULE_*` alone — the wizard writes those for you.

### 4. Create the tables

```bash
php artisan migrate --force
```

This creates the panel's own tables (users, sessions, cache, jobs, settings,
audit log, reports, appeals, cheat scans, …). It does not touch the plugin
databases.

### 5. Point the web server at `public/`

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

### 6. Run the install wizard

Open the panel in a browser. Any URL redirects to `/install` until setup
finishes. The wizard walks through:

1. **Language** — the panel's default locale.
2. **Database** — the panel's own database, plus a connection per plugin
   database (Swiftly admin, CS2_Ranks, weapon skins, VIPCore). Each one is
   connection-tested before it is accepted.
3. **Steam & owner** — Steam Web API key, OpenID credentials, and the
   owner's SteamID64. The owner always has full access, independent of the
   plugin's flags.
4. **Modules** — which built-in features to enable. All of them can be
   changed later from the Modules tab.

The wizard writes to `.env` only — it does not run migrations, which is why
step 4 exists. When it finishes, `INSTALLED=true` is written and `/install`
starts returning 404.

> Already have a `backup.zip` from another install? Upload it on the very
> first screen to skip the wizard entirely — see **Backup & restore**.

### 7. Production hardening (recommended)

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Set `APP_DEBUG=false` in `.env`. Re-run the three cache commands after any
`.env` or config change — a cached config ignores later edits.

If you enabled the **Stats** or **Health** modules, add Laravel's scheduler
to cron so they actually collect data:

```bash
* * * * * cd /var/www/S2_Panel && php artisan schedule:run >> /dev/null 2>&1
```

The **Webhook** module dispatches Discord deliveries onto the queue, so it
also needs a worker (or set `QUEUE_CONNECTION=sync` to send them inline):

```bash
php artisan queue:work --queue=default
```

### Upgrading

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

### Troubleshooting

| Symptom | Cause |
|---|---|
| `/install` itself throws a database error | Step 4 was skipped, or `DB_*` is wrong. Sessions, cache and queue all use the `database` driver, so the wizard cannot render before its own tables exist. |
| Every URL redirects to `/install` | Setup never completed — `INSTALLED` is not `true` in `.env`. |
| `/install` returns 404 | Setup already completed. This is deliberate: it stops anyone re-running the wizard and overwriting your credentials. |
| Steam login returns to a wrong or broken URL | `APP_URL` does not match the address you are browsing, or `STEAM_CALLBACK_URL` is not `<APP_URL>/api/auth/callback`. |
| Blank page / 500 after deploying | Stale caches. Run `php artisan optimize:clear`, fix the issue, then re-cache. |
| Styles missing | `npm run build` was never run, or `public/build` was not uploaded. |
| Config edits have no effect | A cached config is in use — re-run `php artisan config:cache`. |

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
