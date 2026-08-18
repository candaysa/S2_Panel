<?php

namespace Tests\Feature;

use App\Support\SteamProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SteamProfiles::many() is on the request path of Bans, Admins and Ranks -
 * a live install measured 300-2500ms of Steam's own response time for any
 * id not already cached, which is why those three pages were reported slow
 * to open. The fix here is caching an id Steam has nothing for, same as one
 * it does - previously that id was never written to cache at all, so it
 * repeated the full live round trip on every single call, forever.
 */
class SteamProfilesTest extends TestCase
{
    use RefreshDatabase;

    private const KNOWN64 = '76561198000000001';

    private const UNKNOWN64 = '76561198000000002';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.steam.api_key' => 'test-key']);
    }

    public function test_an_id_steam_has_nothing_for_is_still_cached(): void
    {
        Http::fake([
            'api.steampowered.com/*' => Http::response([
                'response' => [
                    'players' => [
                        // Only the known id comes back - Steam simply omits
                        // one it has no account for, it does not error.
                        ['steamid' => self::KNOWN64, 'personaname' => 'Known Player', 'avatarmedium' => 'https://example.test/a.jpg'],
                    ],
                ],
            ], 200),
        ]);

        $profiles = SteamProfiles::many([self::KNOWN64, self::UNKNOWN64]);

        $this->assertSame('Known Player', $profiles[self::KNOWN64]['name']);
        $this->assertArrayHasKey(self::UNKNOWN64, $profiles);
        $this->assertNull($profiles[self::UNKNOWN64]['name']);

        // The part that was actually broken: the unresolved id must be a
        // real cache entry, not silently absent.
        $this->assertNotNull(Cache::get('steam.profile.'.self::UNKNOWN64));
    }

    public function test_a_cached_unresolved_id_never_calls_steam_again(): void
    {
        Cache::put('steam.profile.'.self::UNKNOWN64, ['avatar' => null, 'name' => null, 'profile_url' => null], now()->addHour());

        Http::fake(); // any request at all fails the assertion below

        SteamProfiles::many([self::UNKNOWN64]);

        Http::assertNothingSent();
    }

    public function test_a_second_call_for_the_same_ids_is_served_from_cache(): void
    {
        Http::fake([
            'api.steampowered.com/*' => Http::response([
                'response' => ['players' => [
                    ['steamid' => self::KNOWN64, 'personaname' => 'Known Player'],
                ]],
            ], 200),
        ]);

        SteamProfiles::many([self::KNOWN64]);
        SteamProfiles::many([self::KNOWN64]);

        Http::assertSentCount(1);
    }
}
