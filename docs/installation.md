# Installation

See the [README](../README.md#installation) for the one-line Ubuntu install.
This page covers everything past that: doing it by hand, updating, and
troubleshooting.

## Manual installation

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
databases (Swiftly admin, K4-LevelRanks-SwiftlyS2, weapon skins, VIPCore)
already exist — the panel only reads and writes their tables.

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
   database (Swiftly admin, K4-LevelRanks-SwiftlyS2, weapon skins, VIPCore).
   Each one is connection-tested before it is accepted.
3. **Steam & owner** — Steam Web API key, OpenID credentials, and the
   owner's SteamID64. The owner always has full access, independent of the
   plugin's flags.
4. **Modules** — which built-in features to enable. All of them can be
   changed later from the Modules tab.

The wizard writes to `.env` only — it does not run migrations, which is why
step 4 exists. When it finishes, `INSTALLED=true` is written and `/install`
starts returning 404.

> Already have a `backup.zip` from another install? Upload it on the very
> first screen to skip the wizard entirely — see **Restore from backup**
> under Features in the [README](../README.md#features).

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

## Updating

### From the panel (one click)

The panel checks GitHub Releases and offers the owner a one-click update.
Two rules make that safe, and both are on the release side:

**1. Attach a built bundle, not the source.** GitHub's auto-generated
source archive has no `vendor/` and no compiled `public/build`, so
installing it would leave the panel unbootable on any server without
Composer and Node. The updater therefore ignores the source tarball and
only installs an asset matching `s2panel-*.tar.gz`. Build it the same way
you would deploy:

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
tar -czf s2panel-1.2.3.tar.gz \
    --exclude='./node_modules' --exclude='./.git' --exclude='./.env' \
    --exclude='./storage/logs/*' --exclude='./storage/framework/cache/data/*' \
    --exclude='./storage/framework/sessions/*' --exclude='./storage/framework/views/*' .
```

Attach that file to the release. The updater refuses any bundle missing
`vendor/`, `public/build/manifest.json`, or whose `composer.json` name does
not match the running panel.

**2. Bump `version` in `config/panel.php`** in the same commit you tag.
The panel compares that value against the release tag, so a release tagged
`v1.2.3` against a config still saying `1.2.2` is what triggers the prompt.

What an update does and does not touch:

| | |
|---|---|
| Replaced | application code, `vendor/`, `public/build` |
| Preserved | `.env`, `storage/` (logs, sessions, uploads) |
| Database | `migrate --force` only — forward, additive, never a rollback |
| Rollback | the previous install is kept as `<dir>_pre-update_<timestamp>` |

The web server user needs write access to the install directory **and its
parent** (the swap creates a sibling directory). On a deployment where
those are root-owned, the panel reports exactly which check failed instead
of offering a button that cannot work — update manually with the steps
below in that case.

Set `PANEL_UPDATE_ENABLED=false` to turn the whole thing off.

### Manually

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Troubleshooting

| Symptom | Cause |
|---|---|
| `/install` itself throws a database error | Step 4 was skipped, or `DB_*` is wrong. Sessions, cache and queue all use the `database` driver, so the wizard cannot render before its own tables exist. |
| Every URL redirects to `/install` | Setup never completed — `INSTALLED` is not `true` in `.env`. |
| `/install` returns 404 | Setup already completed. This is deliberate: it stops anyone re-running the wizard and overwriting your credentials. |
| Steam login returns to a wrong or broken URL | `APP_URL` does not match the address you are browsing, or `STEAM_CALLBACK_URL` is not `<APP_URL>/api/auth/callback`. |
| Blank page / 500 after deploying | Stale caches. Run `php artisan optimize:clear`, fix the issue, then re-cache. |
| Styles missing | `npm run build` was never run, or `public/build` was not uploaded. |
| Config edits have no effect | A cached config is in use — re-run `php artisan config:cache`. |
