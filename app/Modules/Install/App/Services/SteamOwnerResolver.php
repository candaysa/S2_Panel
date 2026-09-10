<?php

namespace App\Modules\Install\App\Services;

use App\Support\SteamId;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Turns whatever the owner pasted into the wizard's Steam step into a
 * SteamID64: a Steam profile link, or any SteamID format SteamId reads.
 *
 * A profile link is what an owner can actually get hold of - it is in the
 * address bar of their own profile - whereas a SteamID64 means finding a
 * converter site first. A /profiles/<id> link carries the ID; a custom
 * /id/<name> link carries none, so that one is resolved through the Steam
 * Web API with the key entered on the same screen - which also proves the
 * key works before every avatar and name in the panel depends on it.
 *
 * Failures are InvalidArgumentException with a stable code the wizard maps
 * to a message: invalid_steam_id (not a link or ID at all - the code this
 * step has always used), owner_vanity_not_found, steam_api_key_rejected,
 * steam_unreachable.
 */
class SteamOwnerResolver
{
    private const RESOLVE_URL = 'https://api.steampowered.com/ISteamUser/ResolveVanityURL/v1/';

    public function resolve(string $input, string $apiKey): string
    {
        $input = trim($input);

        if ($input !== '' && SteamId::isValid($input)) {
            return SteamId::parse($input)->steamId64();
        }

        if (preg_match('#steamcommunity\.com/profiles/(\d{17})(?:[/?\#]|$)#i', $input, $m) === 1 && SteamId::isValid($m[1])) {
            return SteamId::parse($m[1])->steamId64();
        }

        if (preg_match('#steamcommunity\.com/id/([A-Za-z0-9_-]{2,64})(?:[/?\#]|$)#i', $input, $m) === 1) {
            return $this->resolveVanity($m[1], $apiKey);
        }

        throw new InvalidArgumentException('invalid_steam_id');
    }

    private function resolveVanity(string $vanity, string $apiKey): string
    {
        try {
            $response = Http::timeout(8)->acceptJson()->get(self::RESOLVE_URL, [
                'key' => $apiKey,
                'vanityurl' => $vanity,
            ]);
        } catch (Throwable) {
            throw new InvalidArgumentException('steam_unreachable');
        }

        // Steam answers a bad or revoked key with a bare 403, not a JSON
        // error - worth telling apart from "that name does not exist",
        // since the fix is on a different field.
        if (in_array($response->status(), [401, 403], true)) {
            throw new InvalidArgumentException('steam_api_key_rejected');
        }

        if (! $response->successful()) {
            throw new InvalidArgumentException('steam_unreachable');
        }

        $steamId = (string) $response->json('response.steamid', '');

        if ((int) $response->json('response.success') !== 1 || ! SteamId::isValid($steamId)) {
            throw new InvalidArgumentException('owner_vanity_not_found');
        }

        return SteamId::parse($steamId)->steamId64();
    }
}
