<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Panel version
    |--------------------------------------------------------------------------
    |
    | Compared against the latest GitHub release to decide whether an update
    | is offered. Bump this in the same commit you tag a release, or the
    | panel will keep offering an update it already has.
    |
    */

    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Updates
    |--------------------------------------------------------------------------
    |
    | The panel checks GitHub Releases for a newer version and the owner can
    | install it from Settings > Updates. What makes that safe to automate:
    |
    | 1. It only ever installs a release ASSET whose name matches
    |    "asset_pattern" - never GitHub's auto-generated source tarball. The
    |    source archive has no vendor/ and no compiled public/build, so
    |    installing it would leave the panel unbootable on any server without
    |    Composer and Node (which is most of them). .github/workflows/
    |    release.yml builds that asset whenever a vX.Y.Z tag is pushed.
    |
    | 2. Files are replaced in place, but only after every file that will be
    |    overwritten or deleted has been copied aside - a failed install or a
    |    failed migration puts all of it back (see UpdateInstaller).
    |
    | Updates never drop or rewrite data: migrations run forward only, and
    | .env, storage/, uploads and installed plugins are never touched.
    |
    */

    'update' => [
        'enabled' => env('PANEL_UPDATE_ENABLED', true),

        // owner/repo on github.com
        'repository' => env('PANEL_UPDATE_REPO', 'candaysa/S2_Panel'),

        // Release asset to install. {version} is substituted; the check is a
        // simple prefix/suffix match, so "s2panel-1.2.3.tar.gz" matches.
        'asset_pattern' => env('PANEL_UPDATE_ASSET', 's2panel-*.tar.gz'),

        // How long a release lookup is cached. GitHub's unauthenticated rate
        // limit is 60/hour per IP, and an update check is not urgent.
        'check_ttl_minutes' => 180,

        // Optional token, only needed for a private repository or to raise
        // the rate limit.
        'token' => env('PANEL_UPDATE_TOKEN'),

        // Refuse anything larger than this, so a mistagged asset cannot fill
        // the disk. Megabytes.
        'max_asset_mb' => 150,

        // What a bundle may expand to, and how many entries it may hold -
        // checked while inflating, before anything is extracted.
        'max_expanded_mb' => 600,
        'max_entries' => 20000,

        // Show the maintenance page while files are replaced. Only ever off
        // in tests, which must not put the app they run in into maintenance.
        'maintenance' => true,

        // The install being updated. Empty means this checkout - it exists
        // so tests can point the updater at a throwaway directory.
        'root' => env('PANEL_UPDATE_ROOT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin flag cache
    |--------------------------------------------------------------------------
    |
    | How long App\Support\Flags caches one SteamID's flag/group/immunity
    | profile before re-reading the plugin's admin tables. Revoking access
    | from the panel itself (Admin module) busts this immediately; a change
    | made directly against the plugin database (or by the plugin itself)
    | is not seen until the cached entry expires - that admin keeps their
    | old permissions for up to this long.
    |
    */

    'flags_cache_ttl_seconds' => env('PANEL_FLAGS_CACHE_TTL', 60),

];
