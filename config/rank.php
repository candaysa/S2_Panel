<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rank tier definitions (ranks.json)
    |--------------------------------------------------------------------------
    |
    | K4-LevelRanks-SwiftlyS2 keeps tier names/colors/point-thresholds in its
    | own config file, ranks.json, on the game server - not in the database.
    | Same idea as catalog.php's "path" below: the operator exports/copies
    | that file here once, the panel just reads it (see RankCatalogService).
    | Missing or unreadable simply falls back to the plugin's own shipped
    | defaults (RankCatalogService::DEFAULT_RANKS) rather than an empty
    | leaderboard.
    |
    */

    'ranks_path' => env('RANK_RANKS_PATH', storage_path('app/rank/ranks.json')),

    'ttl' => 300,

];
