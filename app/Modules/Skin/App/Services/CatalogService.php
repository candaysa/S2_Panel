<?php

namespace App\Modules\Skin\App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Static skin catalog loader (C6, Decision 3).
 *
 * Reads the JSON dumps the CS2_Skin plugin can export; file names match
 * EconService.cs:97 one-to-one. The panel never generates these files –
 * missing files simply yield an empty catalog so the module stays usable.
 */
class CatalogService
{
    /**
     * Catalog file names (EconService.cs:97).
     *
     * @var array<string, string>
     */
    private const FILES = [
        'items' => 'items.json',
        'agents' => 'agents.json',
        'paintkits' => 'weapon_to_paintkits.json',
        'stickers' => 'sticker_collections.json',
        'keychains' => 'keychains.json',
        'musickits' => 'musickits.json',
    ];

    public function types(): array
    {
        return array_keys(self::FILES);
    }

    /**
     * @return array<int, mixed>
     */
    public function get(string $type): array
    {
        $file = self::FILES[$type] ?? null;

        if ($file === null) {
            return [];
        }

        return Cache::remember(
            "catalog:{$type}",
            (int) config('catalog.ttl', 300),
            fn (): array => $this->read($file),
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function read(string $file): array
    {
        $path = rtrim((string) config('catalog.path'), '/\\').DIRECTORY_SEPARATOR.$file;

        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}