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
    | The panel checks GitHub Releases for a newer version and can install it
    | in place. Two things make that safe to automate:
    |
    | 1. It only ever installs a release ASSET whose name matches
    |    "asset_pattern" - never GitHub's auto-generated source tarball. The
    |    source archive has no vendor/ and no compiled public/build, so
    |    installing it would leave the panel unbootable on any server without
    |    Composer and Node (which is most of them). Build the bundle in CI and
    |    attach it to the release.
    |
    | 2. The current install is kept as a sibling directory and restored
    |    automatically if the new one fails its post-install health check.
    |
    | Updates never drop or rewrite data: migrations run forward only, and
    | .env plus storage/ are carried across untouched.
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
    ],

];
