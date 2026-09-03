<?php

namespace App\Modules\Rank\App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Rank tier catalog (C5.1) - reads K4-LevelRanks-SwiftlyS2's ranks.json, the
 * same way CatalogService reads the Skin module's plugin dumps. Tiers live
 * entirely in that file (name/tag/color/point threshold), never in the
 * database - lvl_base only ever stores a points total.
 *
 * ranks.json's shape is read straight off the plugin's own C# model
 * (RanksConfig/Rank in K4-LevelRanks-SwiftlyS2's source): a root object with
 * one "Ranks" array, each entry {Name, Tag, Color, Hex, Points}, PascalCase.
 * That casing has not been verified against a live plugin export - same
 * caveat AdminPlugin\SwiftlyAdminsService documents for its own schema -
 * adjust decode() if a real export turns out to differ.
 */
class RankCatalogService
{
    private const TTL = 300;

    /**
     * K4-LevelRanks-SwiftlyS2's own shipped ranks.json defaults (Silver I -
     * Global Elite), used whenever the operator hasn't exported a real one
     * yet - a fresh install should show a real ladder, not an empty one.
     *
     * @var list<array{Name: string, Tag: string, Hex: string, Points: int}>
     */
    private const DEFAULT_RANKS = [
        ['Name' => 'Silver I', 'Tag' => 'S1', 'Hex' => '#808080', 'Points' => 0],
        ['Name' => 'Silver II', 'Tag' => 'S2', 'Hex' => '#808080', 'Points' => 100],
        ['Name' => 'Silver III', 'Tag' => 'S3', 'Hex' => '#808080', 'Points' => 200],
        ['Name' => 'Silver IV', 'Tag' => 'S4', 'Hex' => '#808080', 'Points' => 350],
        ['Name' => 'Silver Elite', 'Tag' => 'SE', 'Hex' => '#808080', 'Points' => 500],
        ['Name' => 'Silver Elite Master', 'Tag' => 'SEM', 'Hex' => '#808080', 'Points' => 750],
        ['Name' => 'Gold Nova I', 'Tag' => 'GN1', 'Hex' => '#FFD700', 'Points' => 1000],
        ['Name' => 'Gold Nova II', 'Tag' => 'GN2', 'Hex' => '#FFD700', 'Points' => 1250],
        ['Name' => 'Gold Nova III', 'Tag' => 'GN3', 'Hex' => '#FFD700', 'Points' => 1500],
        ['Name' => 'Gold Nova Master', 'Tag' => 'GNM', 'Hex' => '#FFD700', 'Points' => 1750],
        ['Name' => 'Master Guardian I', 'Tag' => 'MG1', 'Hex' => '#87CEEB', 'Points' => 2000],
        ['Name' => 'Master Guardian II', 'Tag' => 'MG2', 'Hex' => '#87CEEB', 'Points' => 2500],
        ['Name' => 'Master Guardian Elite', 'Tag' => 'MGE', 'Hex' => '#87CEEB', 'Points' => 3000],
        ['Name' => 'Distinguished Master Guardian', 'Tag' => 'DMG', 'Hex' => '#0000FF', 'Points' => 3500],
        ['Name' => 'Legendary Eagle', 'Tag' => 'LE', 'Hex' => '#800080', 'Points' => 4000],
        ['Name' => 'Legendary Eagle Master', 'Tag' => 'LEM', 'Hex' => '#800080', 'Points' => 5000],
        ['Name' => 'Supreme Master First Class', 'Tag' => 'SMFC', 'Hex' => '#FF6B6B', 'Points' => 6000],
        ['Name' => 'Global Elite', 'Tag' => 'GE', 'Hex' => '#FF0000', 'Points' => 7500],
    ];

    /**
     * Every configured tier, sorted ascending by points, each carrying its
     * own index into that order (0-based - the badge/progress-bar math
     * wants "how far up the ladder" as a plain number).
     *
     * @return list<array{key: string, label: string, tag: string, hex: string, points: int, index: int}>
     */
    public function ranks(): array
    {
        // Keyed by path (like CatalogService::raw() keys by filename), not a
        // fixed string - config('rank.ranks_path') pointing somewhere new
        // (a redeploy, a test overriding it) must not keep serving whatever
        // an earlier path's ranks.json cached under the same key.
        $path = (string) config('rank.ranks_path');

        return Cache::remember('rank:ranks-json:'.md5($path), self::TTL, function (): array {
            $raw = $this->decode();

            $sorted = collect($raw)
                ->map(fn (array $r): array => [
                    'label' => (string) ($r['Name'] ?? ''),
                    'tag' => (string) ($r['Tag'] ?? ''),
                    'hex' => $this->normalizeHex($r['Hex'] ?? null),
                    'points' => (int) ($r['Points'] ?? 0),
                ])
                ->filter(fn (array $r): bool => $r['label'] !== '')
                ->sortBy('points')
                ->values();

            return $sorted->map(fn (array $r, int $index): array => [
                'key' => Str::slug($r['label'], '_') ?: "tier_{$index}",
                'label' => $r['label'],
                'tag' => $r['tag'],
                'hex' => $r['hex'],
                'points' => $r['points'],
                'index' => $index,
            ])->all();
        });
    }

    /**
     * The tier a points total actually reaches - the last entry in ranks()
     * whose threshold the total clears. No ranks configured at all (an
     * empty ranks.json) reports "unranked" rather than crashing.
     *
     * @return array{key: string, label: string, tag: string, hex: ?string, index: int, tiers: int}
     */
    public function tierFor(int $points): array
    {
        $ranks = $this->ranks();

        if ($ranks === []) {
            return ['key' => 'unranked', 'label' => 'Unranked', 'tag' => '', 'hex' => null, 'index' => 0, 'tiers' => 0];
        }

        $current = $ranks[0];

        foreach ($ranks as $rank) {
            if ($points >= $rank['points']) {
                $current = $rank;
            }
        }

        return [
            'key' => $current['key'],
            'label' => $current['label'],
            'tag' => $current['tag'],
            'hex' => $current['hex'],
            // +1: index 0 is still a real, attained tier here (unlike the old
            // CsRank ladder, ranks.json has no separate "unranked" entry of
            // its own), so the badge's "is this plated" check stays index > 0.
            'index' => $current['index'] + 1,
            'tiers' => count($ranks),
        ];
    }

    /**
     * Just the point thresholds, ascending - players/show.blade.php's
     * progress-toward-next-tier bar only needs the numbers, not the full
     * ladder.
     *
     * @return list<int>
     */
    public function thresholds(): array
    {
        return array_column($this->ranks(), 'points');
    }

    /**
     * @return list<array{Name?: string, Tag?: string, Color?: string, Hex?: string, Points?: int}>
     */
    private function decode(): array
    {
        $path = (string) config('rank.ranks_path');

        if (! File::exists($path)) {
            return self::DEFAULT_RANKS;
        }

        $decoded = json_decode((string) File::get($path), true);
        $ranks = $decoded['Ranks'] ?? null;

        return is_array($ranks) ? $ranks : self::DEFAULT_RANKS;
    }

    private function normalizeHex(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $hex = str_starts_with($value, '#') ? $value : "#{$value}";

        return preg_match('/^#[0-9a-f]{6}$/i', $hex) ? $hex : null;
    }
}
