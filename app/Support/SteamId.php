<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * SteamID value object supporting the three formats used across the stack:
 *
 *   - SteamID64   "76561198000000000"  (users table, admin_admins as integer)
 *   - SteamID2    "STEAM_0:1:123456"   (rank plugin)
 *   - SteamID3    "[U:1:1234567]"      (common in modern tooling)
 *
 * Immutable; always constructed via SteamId::parse().
 */
final class SteamId
{
    private const BASE64 = 76561197960265728;

    private int $accountId;

    private function __construct(int $accountId)
    {
        $this->accountId = $accountId;
    }

    /**
     * Parse any supported format.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string|int $value): self
    {
        $value = trim((string) $value);

        // [U:1:Z]
        if (preg_match('/^\[U:(\d+):(\d+)\]$/i', $value, $m)) {
            return new self((int) $m[2]);
        }

        // STEAM_X:Y:Z (X is universe, ignored)
        if (preg_match('/^STEAM_(\d+):(\d+):(\d+)$/i', $value, $m)) {
            $universe = (int) $m[2];

            if ($universe !== 0 && $universe !== 1) {
                throw new InvalidArgumentException("Invalid SteamID2 universe: [{$value}]");
            }

            return new self(((int) $m[3] << 1) | $universe);
        }

        // SteamID64 decimal
        if (preg_match('/^\d{15,17}$/', $value)) {
            $raw = (int) $value;

            if ($raw < self::BASE64 || $raw > self::BASE64 + 0xFFFFFFFF) {
                throw new InvalidArgumentException("Invalid SteamID64: [{$value}]");
            }

            return new self($raw - self::BASE64);
        }

        throw new InvalidArgumentException("Unrecognized SteamID format: [{$value}]");
    }

    /**
     * Build directly from a raw AccountID/SteamID32 (VIPCore's account_id
     * column, for example) - parse() only accepts already-formatted strings.
     */
    public static function fromAccountId(int $accountId): self
    {
        return new self($accountId);
    }

    public static function isValid(string|int $value): bool
    {
        try {
            self::parse($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function accountId(): int
    {
        return $this->accountId;
    }

    public function steamId64(): string
    {
        return (string) (self::BASE64 + $this->accountId);
    }

    public function steamId2(): string
    {
        return sprintf('STEAM_0:%d:%d', $this->accountId & 1, $this->accountId >> 1);
    }

    public function steamId3(): string
    {
        return sprintf('[U:1:%d]', $this->accountId);
    }

    public function equals(self $other): bool
    {
        return $this->accountId === $other->accountId;
    }
}