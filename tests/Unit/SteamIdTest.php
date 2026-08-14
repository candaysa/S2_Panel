<?php

namespace Tests\Unit;

use App\Support\SteamId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SteamIdTest extends TestCase
{
    private const KNOWN_64 = '76561197962734863';

    private const KNOWN_2 = 'STEAM_0:1:1234567';

    private const KNOWN_3 = '[U:1:2469135]';

    private const ACCOUNT_ID = 2469135;

    private const OTHER_64 = '76561197962734864';

    public function test_parses_steamid64(): void
    {
        $this->assertSame(self::ACCOUNT_ID, SteamId::parse(self::KNOWN_64)->accountId());
    }

    public function test_parses_steamid2(): void
    {
        $this->assertSame(self::ACCOUNT_ID, SteamId::parse(self::KNOWN_2)->accountId());
    }

    public function test_parses_steamid3(): void
    {
        $this->assertSame(self::ACCOUNT_ID, SteamId::parse(self::KNOWN_3)->accountId());
    }

    public function test_accepts_integer_input(): void
    {
        $this->assertSame(self::ACCOUNT_ID, SteamId::parse((int) self::KNOWN_64)->accountId());
    }

    public function test_converts_between_all_formats(): void
    {
        $id = SteamId::parse(self::KNOWN_64);

        $this->assertSame(self::KNOWN_64, $id->steamId64());
        $this->assertSame(self::KNOWN_2, $id->steamId2());
        $this->assertSame(self::KNOWN_3, $id->steamId3());
    }

    public function test_steamid2_universe_one_maps_to_same_account(): void
    {
        $this->assertSame(
            SteamId::parse('STEAM_0:1:1234567')->accountId(),
            SteamId::parse('STEAM_1:1:1234567')->accountId(),
        );
    }

    public function test_steamid3_universe_is_preserved(): void
    {
        $this->assertSame(
            SteamId::parse('[U:1:2469135]')->accountId(),
            SteamId::parse('[U:2:2469135]')->accountId(),
        );
    }

    public function test_rejects_invalid_formats(): void
    {
        foreach (['', 'foo', 'STEAM_0:2:1', '123', '9999999999999999999999'] as $bad) {
            $this->expectException(InvalidArgumentException::class);
            SteamId::parse($bad);
        }
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(SteamId::isValid(self::KNOWN_64));
        $this->assertFalse(SteamId::isValid('nope'));
    }

    public function test_equals_compares_accounts(): void
    {
        $this->assertTrue(SteamId::parse(self::KNOWN_2)->equals(SteamId::parse(self::KNOWN_3)));
        $this->assertFalse(SteamId::parse(self::KNOWN_2)->equals(SteamId::parse(self::OTHER_64)));
    }
}
