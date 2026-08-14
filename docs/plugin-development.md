# Building a plugin

A plugin is a `.zip` file the panel owner uploads from **Plugins** in the
sidebar (owner-only). Structurally it's identical to one of the panel's own
built-in modules under `app/Modules/*` — same base class, same conventions
— the only difference is *how* it gets registered: a built-in module is
compiled into `bootstrap/providers.php`, a plugin is discovered from the
`plugin_installs` table and registered dynamically on every request.

## Minimum required layout

```
your-plugin.zip
├── plugin.json                          (required, at the zip root)
├── ExampleServiceProvider.php
├── Routes/
│   └── api.php                          (optional)
└── Database/
    └── Migrations/                      (optional)
```

If your zip contains a single top-level folder (e.g. because you zipped a
folder, or downloaded a "Source code (zip)" archive from GitHub), the
installer flattens it automatically — `plugin.json` still just needs to be
findable at the top level either way.

### `plugin.json`

```json
{
    "key": "example",
    "name": "Example Plugin",
    "version": "1.0.0",
    "author": "Your name",
    "description": "One sentence describing what this does."
}
```

- `key` and `name` are required. `version`/`author`/`description` are
  optional but shown in the Plugins list.
- `key` must match `^[a-z][a-z0-9_]{1,39}$` and must not collide with a
  built-in module (`auth`, `admin`, `vip`, `rank`, `skin`, `ban`, `report`,
  `appeal`, `server`, `rcon`, `stats`, `settings`, `i18n`, `health`,
  `webhook`, `modules`, `plugins`, `install`) or an already-installed
  plugin's key.
- **The service provider class is derived from `key`, not read from the
  manifest**: for `"key": "example"` the installer requires exactly
  `ExampleServiceProvider` (StudlyCase of the key) to exist after
  extraction. This keeps every plugin's layout predictable and removes a
  class of "manifest claims a class in a surprising namespace" risk.

## The service provider

Every plugin's entry point extends the same base class every built-in
module extends — you get the enable/disable gate, panel-only migration
loading, and route loading for free:

```php
<?php

namespace App\Modules\Example;

use App\Support\ModuleServiceProvider;

class ExampleServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'example'; // must match plugin.json's "key"
    }

    protected function registerModule(): void
    {
        // Bind services into the container here, if you have any.
    }

    protected function bootModule(): void
    {
        // Runs once the plugin is confirmed enabled. Routes are already
        // loaded for you (see below) - most plugins need nothing else here.
    }
}
```

Once installed, the class lives at `app/Modules/Example/ExampleServiceProvider.php`
with namespace `App\Modules\Example` — Composer's existing `App\` → `app/`
autoload mapping picks it up automatically (the installer also registers it
on the live autoloader immediately, so it works without a server restart).

## Routes

Drop a `Routes/api.php` next to your service provider (top level of the
zip, i.e. `your-plugin.zip/Routes/api.php`) and it's loaded automatically,
wrapped in the same `api` middleware group every module's routes get
(security headers, CSRF, session, rate limiting):

```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('api/example/hello', function () {
    return response()->json(['message' => 'Hello from a plugin!']);
})->middleware('steam.auth')->name('example.hello');
```

## Migrations (optional)

`Database/Migrations/*.php` files (standard Laravel migration format) are
loaded unconditionally, the same way a built-in module's are — a disabled
plugin's tables still exist so re-enabling it never loses data. Only ever
create tables owned by your plugin; never touch a Swiftly/VIPCore/CS2_Ranks
plugin table from here.

## Packaging and installing

1. Zip the folder so `plugin.json` sits at (or one level below) the zip
   root.
2. **Plugins** (sidebar, owner-only) → **Upload plugin (.zip)**.
3. The panel validates the zip (safe paths only, no `../` traversal),
   extracts it, checks the manifest and key, verifies your service
   provider class exists and extends `ModuleServiceProvider`, then
   registers it — live, immediately, no restart needed.
4. Toggle it on/off or uninstall it any time from the same page. Uninstall
   deletes `app/Modules/{Key}/` entirely — back up anything you want to
   keep first.

## Trust model — read this before installing anyone else's plugin

A plugin is PHP code that runs inside the panel with the same privileges
as the panel itself, exactly like a WordPress plugin or a WHMCS module.
The installer validates *structure* (safe extraction, a real manifest, a
service provider that actually extends the expected base class) — it does
not and cannot sandbox what a plugin's code does once it's registered.
Only install `.zip` files from sources you trust.

## Current limitations

- No Composer dependency support: a plugin can't `require` third-party
  packages that aren't already part of the panel's own `vendor/`.
- Uninstalling a plugin removes it for every request *after* the one that
  ran the uninstall — a provider already registered earlier in the request
  that deleted it keeps running until that request finishes (routes and
  bindings from a deleted plugin never survive to the next request).
- One plugin per `key` — installing a new zip with an already-used key is
  rejected outright, on purpose, rather than silently overwriting one.
