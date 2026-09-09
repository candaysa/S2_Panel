<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Steam avatars and display names, fetched in bulk.
 *
 * The plugin tables store only a SteamID and whatever nickname the player
 * last used in game, so anything richer has to come from Steam's Web API.
 * GetPlayerSummaries accepts 100 ids per call, which is the only reason
 * showing an avatar on a 50-row leaderboard is affordable at all - one
 * request for the page, not one per row.
 *
 * Everything is cached and every failure degrades to "no avatar": a
 * leaderboard must still render when Steam is slow, rate-limiting, or the
 * owner never entered an API key.
 */
final class SteamProfiles
{
    private const CACHE_HOURS = 12;

    private const CHUNK = 100;

    private const ENDPOINT = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/';

    /**
     * Cached in place of a real profile for an id Steam's API had nothing
     * for (a deleted account, or a malformed id someone typed straight into
     * an RCON console command - see BanService::looksLikeSteamId(), which
     * exists precisely because that happens). Without this, such an id was
     * never written to cache at all, so it repeated the full ~300-800ms
     * live API round trip on every single page load that happened to
     * include it, forever - not just once.
     */
    private const EMPTY_PROFILE = ['avatar' => null, 'name' => null, 'profile_url' => null];

    /**
     * @param  array<int, string>  $steamIds  any SteamID format
     * @return array<string, array{avatar: ?string, name: ?string, profile_url: ?string}>
     *                                        keyed by the id that was passed in. An id
     *                                        Steam has no profile for still gets an entry
     *                                        (all-null), not a missing key - see EMPTY_PROFILE.
     */
    public static function many(array $steamIds): array
    {
        $out = [];
        $missing = [];

        foreach ($steamIds as $raw) {
            $raw = (string) $raw;

            if ($raw === '' || ! SteamId::isValid($raw)) {
                continue;
            }

            try {
                $id64 = SteamId::parse($raw)->steamId64();
            } catch (Throwable) {
                continue;
            }

            $cached = Cache::get(self::key($id64));

            if (is_array($cached)) {
                $out[$raw] = $cached;

                continue;
            }

            $missing[$id64] = $raw;
        }

        if ($missing === [] || ! self::configured()) {
            return $out;
        }

        foreach (array_chunk(array_keys($missing), self::CHUNK) as $chunk) {
            $found = self::fetch($chunk);

            foreach ($chunk as $id64) {
                $profile = $found[$id64] ?? self::EMPTY_PROFILE;

                Cache::put(self::key($id64), $profile, now()->addHours(self::CACHE_HOURS));

                if (isset($missing[$id64])) {
                    $out[$missing[$id64]] = $profile;
                }
            }
        }

        return $out;
    }

    private static function configured(): bool
    {
        return trim((string) config('services.steam.api_key', '')) !== '';
    }

    /**
     * @param  array<int, string>  $ids64
     * @return array<string, array{avatar: ?string, name: ?string, profile_url: ?string}>
     */
    private static function fetch(array $ids64): array
    {
        try {
            $response = Http::timeout(5)->retry(1, 200)->get(self::ENDPOINT, [
                'key' => config('services.steam.api_key'),
                'steamids' => implode(',', $ids64),
            ]);

            if (! $response->successful()) {
                return [];
            }

            $players = $response->json('response.players') ?? [];
        } catch (Throwable) {
            // Steam being unreachable must never take a page down - the
            // caller renders without avatars.
            return [];
        }

        $out = [];

        foreach ($players as $player) {
            $id = (string) ($player['steamid'] ?? '');

            if ($id === '') {
                continue;
            }

            $out[$id] = [
                'avatar' => $player['avatarmedium'] ?? $player['avatar'] ?? null,
                'name' => $player['personaname'] ?? null,
                'profile_url' => $player['profileurl'] ?? null,
            ];
        }

        return $out;
    }

    private const BANS_ENDPOINT = 'https://api.steampowered.com/ISteamUser/GetPlayerBans/v1/';

    private const EMPTY_ENRICHMENT = [
        'account_created_at' => null,
        'vac_banned' => false,
        'game_bans' => 0,
        'community_banned' => false,
        'days_since_last_ban' => null,
    ];

    /**
     * Richer, single-player detail for a profile page - not part of many()
     * above, which stays a pure avatar/name bulk-fetch (a leaderboard row
     * has no use for this, and GetPlayerBans is a second API call per
     * lookup that a list of 50 players should never pay for). VAC/game-ban
     * status and account age are public Steam data - the same thing
     * Steam's own profile page shows anyone - not moderation data, so this
     * is meant to sit on the public player profile response, not a
     * staff-gated one.
     *
     * @return array{account_created_at: ?int, vac_banned: bool, game_bans: int, community_banned: bool, days_since_last_ban: ?int}
     */
    public static function enrichmentFor(string $steamId): array
    {
        if (! SteamId::isValid($steamId) || ! self::configured()) {
            return self::EMPTY_ENRICHMENT;
        }

        $id64 = SteamId::parse($steamId)->steamId64();
        $cacheKey = 'steam.enrichment.'.$id64;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $enrichment = self::fetchEnrichment($id64);
        Cache::put($cacheKey, $enrichment, now()->addHours(self::CACHE_HOURS));

        return $enrichment;
    }

    /**
     * @return array{account_created_at: ?int, vac_banned: bool, game_bans: int, community_banned: bool, days_since_last_ban: ?int}
     */
    private static function fetchEnrichment(string $id64): array
    {
        $created = null;

        try {
            $summary = Http::timeout(5)->retry(1, 200)->get(self::ENDPOINT, [
                'key' => config('services.steam.api_key'),
                'steamids' => $id64,
            ]);

            if ($summary->successful()) {
                $created = $summary->json('response.players.0.timecreated');
            }
        } catch (Throwable) {
            // Falls through with $created = null - the ban lookup below is
            // independent and still worth attempting.
        }

        try {
            $bans = Http::timeout(5)->retry(1, 200)->get(self::BANS_ENDPOINT, [
                'key' => config('services.steam.api_key'),
                'steamids' => $id64,
            ]);

            if (! $bans->successful()) {
                return [...self::EMPTY_ENRICHMENT, 'account_created_at' => $created];
            }

            $row = $bans->json('players.0') ?? [];
        } catch (Throwable) {
            return [...self::EMPTY_ENRICHMENT, 'account_created_at' => $created];
        }

        $daysSinceLastBan = (int) ($row['DaysSinceLastBan'] ?? 0);
        $everBanned = ($row['VACBanned'] ?? false) || (int) ($row['NumberOfGameBans'] ?? 0) > 0;

        return [
            'account_created_at' => $created,
            'vac_banned' => (bool) ($row['VACBanned'] ?? false),
            'game_bans' => (int) ($row['NumberOfGameBans'] ?? 0),
            'community_banned' => (bool) ($row['CommunityBanned'] ?? false),
            'days_since_last_ban' => $everBanned ? $daysSinceLastBan : null,
        ];
    }

    private static function key(string $id64): string
    {
        return 'steam.profile.'.$id64;
    }
}
