<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rank ladder
    |--------------------------------------------------------------------------
    |
    | CS2_Ranks stores a numeric rank on rank_base.rank, but plenty of
    | installs never populate it - every row stays 0, which would render the
    | whole leaderboard as "Unranked". So the panel derives the tier from the
    | points value instead, using the ladder below.
    |
    | Each entry is [minimum points, key]. Sorted ascending; a player gets the
    | last tier whose minimum they meet. Tune these to your own server's point
    | economy - the defaults spread across a 0-20000 range, which suits a
    | long-running public server.
    |
    | Set "use_plugin_rank" to true if your CS2_Ranks build does maintain the
    | rank column and you would rather trust it than the point thresholds.
    |
    */

    'use_plugin_rank' => env('RANK_USE_PLUGIN_COLUMN', false),

    'ladder' => [
        [0, 'silver_1'],
        [250, 'silver_2'],
        [500, 'silver_3'],
        [800, 'silver_4'],
        [1200, 'silver_elite'],
        [1700, 'silver_elite_master'],
        [2300, 'gold_nova_1'],
        [3000, 'gold_nova_2'],
        [3800, 'gold_nova_3'],
        [4700, 'gold_nova_master'],
        [5700, 'master_guardian_1'],
        [6900, 'master_guardian_2'],
        [8200, 'master_guardian_elite'],
        [9700, 'distinguished_master_guardian'],
        [11500, 'legendary_eagle'],
        [13500, 'legendary_eagle_master'],
        [16000, 'supreme_master_first_class'],
        [19000, 'global_elite'],
    ],

];
