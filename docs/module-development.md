# Building a module

Every feature in this panel — Admins, Bans, VIP, Skins, Tickets, RCON — is
a module: a self-contained folder under `app/Modules/` with its own routes,
migrations, controllers and services. There is no "core" the features hang
off; the core *is* the module loader.

A **plugin** is not a different kind of thing. It is a module packaged as a
`.zip` so it can be installed from the Plugins page without touching the
server's files. Same base class, same layout, same lifecycle. The only
difference is how it gets registered:

|                  | Built-in module            | Plugin                        |
| ---------------- | -------------------------- | ----------------------------- |
| Lives in         | `app/Modules/Foo/`         | `app/Modules/Foo/` (extracted)|
| Declared in      | `config/modules.php`       | `plugin.json` at the zip root |
| Registered by    | `bootstrap/providers.php`  | the `plugin_installs` table   |
| Installed by     | shipping with the panel    | uploading a zip, no restart   |

Read this page for either. The plugin-only bits are marked.

---

## Quick start

```bash
php artisan make:module Trophy
```

That creates `app/Modules/Trophy/` with a service provider, an API route
file and a controller, registers the module in `config/modules.php`, and
adds `MODULE_TROPHY=true` to `.env.example`. Then:

```bash
# .env
MODULE_TROPHY=true
```

```bash
php artisan config:clear
```

`GET /api/trophy` is live. Everything below explains what the generator
made and what it deliberately left to you.

---

## Layout

```
app/Modules/Trophy/
├── TrophyServiceProvider.php     required
├── Routes/
│   └── api.php                   loaded while enabled
├── Database/
│   └── Migrations/               loaded always (see below)
├── App/
│   ├── Http/Controllers/
│   ├── Models/
│   └── Services/
└── Resources/                    optional: data files the module ships
```

Namespaces follow the path: `app/Modules/Trophy/App/Services/Foo.php` is
`App\Modules\Trophy\App\Services\Foo`. Composer's existing `App\` → `app/`
mapping picks it up with no autoload changes.

The folder name is the StudlyCase of the module key — `cheat_check` lives
in `app/Modules/CheatCheck/`. `ModuleServiceProvider::modulePath()` derives
one from the other, so a mismatch means routes and migrations silently do
not load.

---

## The service provider

Every module has exactly one, extending `App\Support\ModuleServiceProvider`:

```php
<?php

namespace App\Modules\Trophy;

use App\Support\ModuleServiceProvider;

class TrophyServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'trophy';
    }

    protected function registerModule(): void
    {
        // Container bindings. Runs only while the module is enabled.
    }

    protected function bootModule(): void
    {
        // Usually empty - routes and migrations are already wired.
    }
}
```

The base class gives you, for free:

- **the enable/disable gate** — `registerModule()`/`bootModule()` never run
  while the module is off;
- **route loading** — `Routes/api.php`, inside the `api` middleware group;
- **migration loading** — `Database/Migrations`, *always*, enabled or not;
- **dependency checks** — anything listed in `depends` that is disabled is
  reported.

Migrations load even while disabled on purpose: switching a module off must
never leave orphaned tables behind, and re-enabling it must never lose data.

---

## Registering it (built-in modules)

One entry in `config/modules.php`:

```php
'trophy' => [
    'enabled' => env('MODULE_TROPHY', false),
    'provider' => App\Modules\Trophy\TrophyServiceProvider::class,
    'depends' => ['auth'],
],
```

That is the only place a module is declared. `bootstrap/providers.php`
derives its provider list from this file, so there is no second list to
keep in sync — which used to be the one way to add a module that silently
never loaded.

`depends` is advisory: it produces a warning and shows up in the Modules
tab so an owner is told what else goes with a switch, but it does not
prevent loading.

### Letting the owner switch it off

Add the key to `ModuleRegistry::toggleable()` and it appears in the Modules
tab with a switch. Then add a `modules.items.trophy` entry (`name`,
`description`) to each `app/Modules/I18n/lang/*/messages.php`, since the
tab renders those from i18n rather than from config.

Only list features a server can genuinely run without. Plumbing (auth,
install, settings) and the data other pages read through (admin, ban,
server) stay env-only, so no UI toggle can take the panel down.

---

## API routes

`Routes/api.php` is loaded automatically while the module is enabled, and
stops existing entirely when it is not — the routes 404, they are not just
hidden.

```php
Route::prefix('api/trophy')->middleware(['steam.auth'])->group(function (): void {
    Route::get('/', [TrophyController::class, 'index'])->name('trophy.index');
});
```

Pick the narrowest gate that fits:

| Middleware             | Who gets through                          |
| ---------------------- | ----------------------------------------- |
| *(none)*               | anyone, logged in or not                  |
| `steam.auth`           | any logged-in player                      |
| `flag:admin.generic`   | any admin                                 |
| `flag:admin.root`      | root admins — the owner always passes     |
| `owner.only`           | the panel owner alone                     |
| `module:trophy`        | only while that module is enabled         |

Answer in the panel's standard envelope so the frontend's `fetchJson()`
helpers can read it:

```php
return Api::success($rows, ['pagination' => [...]]);
return Api::error(Api::MSG_VALIDATION_FAILED, $errors, 422);
```

---

## Pages

A module's API disappears when it is switched off, but Blade pages live in
`routes/web.php` and are registered by the app, not by the module. Gate
them explicitly or the page renders while every fetch behind it 404s:

```php
Route::view('/trophies', 'trophies.index')
    ->middleware(['module:trophy', 'flag:admin.generic'])
    ->name('trophies.page');
```

Then add it to `resources/views/components/layout/sidebar.blade.php`, whose
entries carry the *same* module keys and flag gates — the nav must never
link somewhere that is gone, or promise access the API will refuse.

Pages are Blade + Alpine and fetch their own data from the module's API.
Shared pieces worth reusing rather than rebuilding: `<x-pagination>`,
`<x-sort-th>`, `<x-icon>`, `<x-toggle>`.

---

## Database

Two rules, and they matter more than anything else on this page:

1. **Own your tables.** Migrations may only create tables belonging to your
   module. Never write a migration that touches a game plugin's schema.
2. **Never write to a game plugin's tables** unless that plugin's own
   contract says a third party may. Bans, ranks and admin logs are read-only
   here; the panel is a consumer, and the plugin is the owner.

Connections: `mysql` is the panel's own database. `swiftly`, `weaponskins`,
`ranks` and friends are the game plugins' — declare them on the model, not
per query:

```php
class TrophyAward extends Model
{
    protected $connection = 'swiftly';
    protected $table = 'trophy_awards';
    public $timestamps = false;
}
```

A game plugin's table may not exist on a given install. Check rather than
assume, and return an empty result instead of a 500 — see
`AdminLogService::available()` for the pattern.

**SteamID64s must be cast to `string`.** They exceed JavaScript's safe
integer range, so as a JSON number the browser silently drops the last
digit and every profile link built from it points at a different account.

---

## Translations

User-facing strings go in `app/Modules/I18n/lang/{locale}/messages.php` for
every locale `I18nController::locales()` lists (`en`, `tr`, `de`, `fr`,
`it`, `ru`, `hu`, `pl`), read as
`__('i18n::messages.trophy.title')`. English is the fallback for anything
missing, so a partial translation degrades rather than breaking.

---

## Auditing

Anything a module changes should be recorded:

```php
$this->audit->log('trophy.awarded', 'player', $steamId, ['trophy' => $name]);
```

Then add a sentence for the new action key in the `audit.*` i18n block, so
the log reads as a sentence rather than a dotted machine string.

---

## Packaging as a plugin

To ship a module as an installable zip instead of building it into the
panel, add a `plugin.json` at the root:

```json
{
    "key": "trophy",
    "name": "Trophies",
    "version": "1.0.0",
    "author": "Your name",
    "description": "One sentence describing what this does."
}
```

- `key` and `name` are required; the rest are optional and shown in the
  Plugins list.
- `key` must match `^[a-z][a-z0-9_]{1,39}$` and must not collide with a
  built-in module key or an installed plugin.
- **The provider class is derived from `key`, not read from the manifest**:
  `"key": "trophy"` requires exactly `TrophyServiceProvider` at the root.
  That keeps every plugin's layout predictable and removes a class of
  "manifest claims a class in a surprising namespace" risk.
- No `config/modules.php` entry — the manifest replaces it.

Zip the folder so `plugin.json` sits at (or one level below) the zip root;
a single wrapping folder is flattened automatically. Upload it from
**Plugins**. The panel validates the zip (safe paths only, no `../`),
checks the manifest and key, verifies the provider class exists and extends
`ModuleServiceProvider`, then registers it live — no restart. Anything that
fails a check is rejected and leaves nothing behind.

Uninstalling deletes `app/Modules/{Key}/` entirely. Back up first.

### Trust model — read before installing anyone else's plugin

A plugin is PHP running inside the panel with the panel's own privileges:
full database access, full filesystem access. There is no sandbox, and none
is planned — this is the same trust model as any other third-party plugin
system. The installer validates *structure*, not behaviour. Only install
zips from sources you trust.

### Known limitations

- No Composer dependencies: a plugin cannot `require` packages that are not
  already in the panel's `vendor/`.
- Uninstalling takes effect from the *next* request; a provider already
  registered earlier in the current one keeps running until it finishes.
- One plugin per `key`. A zip reusing an existing key is rejected outright
  rather than silently overwriting.

---

## Checklist

- [ ] `php artisan make:module Trophy`
- [ ] `MODULE_TROPHY=true` in `.env`, then `php artisan config:clear`
- [ ] Routes gated with the narrowest middleware that fits
- [ ] Page route carries `module:trophy` and the matching flag gate
- [ ] Sidebar entry carries the same two gates
- [ ] Migrations create only your own tables
- [ ] SteamID64 columns cast to `string`
- [ ] Strings in every locale `I18nController::locales()` lists
- [ ] Mutations audit-logged, with an `audit.*` sentence for each action
- [ ] `ModuleRegistry::toggleable()` + `modules.items.*` if owners may switch it off
