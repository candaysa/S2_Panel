<?php

namespace Tests\Feature;

use App\Modules\Install\App\Services\SteamOwnerResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The wizard's owner field: whatever an owner pastes - a profile link, a
 * custom /id/ link, or a raw SteamID - has to come out as a SteamID64, and
 * each way it can fail has to say which field to fix.
 */
class SteamOwnerResolverTest extends TestCase
{
    private function resolve(string $input, string $key = 'KEY'): string
    {
        return app(SteamOwnerResolver::class)->resolve($input, $key);
    }

    private function assertFailsWith(string $code, string $input): void
    {
        try {
            $this->resolve($input);
            $this->fail("expected {$code}");
        } catch (InvalidArgumentException $e) {
            $this->assertSame($code, $e->getMessage());
        }
    }

    public function test_a_raw_steamid_in_any_format_needs_no_lookup(): void
    {
        Http::fake();

        $this->assertSame('76561198000000042', $this->resolve('76561198000000042'));
        $this->assertSame('76561197962734863', $this->resolve('STEAM_0:1:1234567'));

        Http::assertNothingSent();
    }

    public function test_a_profiles_link_carries_the_id_itself(): void
    {
        Http::fake();

        foreach ([
            'https://steamcommunity.com/profiles/76561198000000042',
            'https://steamcommunity.com/profiles/76561198000000042/',
            'steamcommunity.com/profiles/76561198000000042/?xml=1',
        ] as $link) {
            $this->assertSame('76561198000000042', $this->resolve($link), $link);
        }

        Http::assertNothingSent();
    }

    public function test_a_custom_id_link_is_resolved_with_the_given_key(): void
    {
        Http::fake([
            'api.steampowered.com/ISteamUser/ResolveVanityURL/*' => Http::response([
                'response' => ['success' => 1, 'steamid' => '76561198000000077'],
            ]),
        ]);

        $this->assertSame('76561198000000077', $this->resolve('https://steamcommunity.com/id/panel_owner/', 'THE_KEY'));

        Http::assertSent(fn ($request) => $request['vanityurl'] === 'panel_owner' && $request['key'] === 'THE_KEY');
    }

    public function test_an_unknown_custom_link_is_reported_as_such(): void
    {
        // Steam's own "no match" answer: success 42, no steamid.
        Http::fake(['api.steampowered.com/*' => Http::response(['response' => ['success' => 42, 'message' => 'No match']])]);

        $this->assertFailsWith('owner_vanity_not_found', 'https://steamcommunity.com/id/nobody_has_this_name');
    }

    public function test_a_rejected_key_is_blamed_on_the_key_not_the_link(): void
    {
        Http::fake(['api.steampowered.com/*' => Http::response('Forbidden', 403)]);

        $this->assertFailsWith('steam_api_key_rejected', 'https://steamcommunity.com/id/panel_owner');
    }

    public function test_steam_being_unreachable_is_reported_separately(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->assertFailsWith('steam_unreachable', 'https://steamcommunity.com/id/panel_owner');
    }

    public function test_anything_else_is_not_a_profile(): void
    {
        Http::fake();

        foreach (['', 'nope', 'https://example.com/profiles/76561198000000042', 'https://steamcommunity.com/groups/somegroup'] as $input) {
            $this->assertFailsWith('invalid_steam_id', $input);
        }

        Http::assertNothingSent();
    }
}
