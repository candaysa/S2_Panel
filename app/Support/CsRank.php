<?php

namespace App\Support;

/**
 * Maps a CS2_Ranks points value onto a competitive tier.
 *
 * The plugin has a rank column, but many builds never write to it - on this
 * panel's own database all 3002 rows carry rank=0, which would label every
 * single player "Unranked". Points are what actually separate players, so
 * that is what the ladder in config/rank.php keys off. An install whose
 * plugin does maintain the column can flip rank.use_plugin_rank.
 *
 * Tiers are returned as keys, never as display strings: the label is
 * translated in the UI layer, and the tier group drives the badge colour.
 */
final class CsRank
{
    public const UNRANKED = 'unranked';

    /**
     * Ordered so index() can double as "how far up the ladder", which is what
     * the badge fill uses.
     *
     * @var list<string>
     */
    private const ORDER = [
        self::UNRANKED,
        'silver_1', 'silver_2', 'silver_3', 'silver_4',
        'silver_elite', 'silver_elite_master',
        'gold_nova_1', 'gold_nova_2', 'gold_nova_3', 'gold_nova_master',
        'master_guardian_1', 'master_guardian_2', 'master_guardian_elite',
        'distinguished_master_guardian',
        'legendary_eagle', 'legendary_eagle_master',
        'supreme_master_first_class', 'global_elite',
    ];

    /**
     * Coarse family, used for the badge colour so the ladder reads at a
     * glance without needing 19 separate palettes.
     */
    private const GROUPS = [
        'silver' => ['silver_1', 'silver_2', 'silver_3', 'silver_4', 'silver_elite', 'silver_elite_master'],
        'gold' => ['gold_nova_1', 'gold_nova_2', 'gold_nova_3', 'gold_nova_master'],
        'guardian' => ['master_guardian_1', 'master_guardian_2', 'master_guardian_elite', 'distinguished_master_guardian'],
        'eagle' => ['legendary_eagle', 'legendary_eagle_master'],
        'elite' => ['supreme_master_first_class', 'global_elite'],
    ];

    /**
     * @return array{key: string, index: int, group: string, tiers: int}
     */
    public static function for(int $points, ?int $pluginRank = null): array
    {
        $key = self::UNRANKED;

        if (config('rank.use_plugin_rank', false) && $pluginRank !== null && $pluginRank > 0) {
            $key = self::ORDER[$pluginRank] ?? self::UNRANKED;
        } else {
            foreach ((array) config('rank.ladder', []) as [$minimum, $candidate]) {
                if ($points >= (int) $minimum) {
                    $key = (string) $candidate;
                }
            }
        }

        return [
            'key' => $key,
            'index' => self::index($key),
            'group' => self::group($key),
            'tiers' => count(self::ORDER) - 1,
        ];
    }

    private static function index(string $key): int
    {
        $index = array_search($key, self::ORDER, true);

        return $index === false ? 0 : $index;
    }

    private static function group(string $key): string
    {
        foreach (self::GROUPS as $group => $keys) {
            if (in_array($key, $keys, true)) {
                return $group;
            }
        }

        return 'unranked';
    }
}
