<?php

namespace App\Modules\Skin\App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Static skin catalog (C6). Reads the JSON dumps the CS2_Skin plugin
 * exports (file names match EconService.cs:97 one-to-one); the panel never
 * generates these files - missing ones simply yield an empty catalog.
 *
 * Every entry ships CS2's full LocalizedNames map (30+ languages) and, for
 * weapon_to_paintkits.json alone, is a multi-megabyte file - sending that
 * raw would mean shipping the whole game's item schema to the browser on
 * every page load. slim() strips each entry down to {name, index, label}
 * (label = the current app locale's name, English fallback) before it ever
 * leaves the server.
 */
class CatalogService
{
    private const TTL = 300;

    /**
     * CS2's LocalizedNames keys don't match the panel's ISO locale codes.
     */
    private const LOCALE_MAP = [
        'en' => 'english',
        'tr' => 'turkish',
        'de' => 'german',
        'fr' => 'french',
        'ru' => 'russian',
        'it' => 'italian',
    ];

    /**
     * Skinnable guns: present in items.json (for the Index/defindex) AND
     * weapon_to_paintkits.json (proof a paintkit list exists for it).
     *
     * @return array<int, array{name: string, index: int, label: string}>
     */
    public function weapons(): array
    {
        $paintable = $this->rawPaintkits();

        return collect($this->rawItems())
            ->filter(fn (array $item): bool => str_starts_with((string) ($item['Name'] ?? ''), 'weapon_')
                && array_key_exists($item['Name'], $paintable))
            ->map(fn (array $item): array => $this->slim($item))
            ->values()->all();
    }

    /**
     * Paint choices for one weapon (also covers glove families, though
     * SkinGloves has no paint_id column to store one - gloves only pick a
     * type, see gloves()).
     *
     * @return array<int, array{name: string, index: int, label: string, rarity_color: ?string}>
     */
    public function paintkits(string $weapon): array
    {
        $all = $this->rawPaintkits();

        if (! isset($all[$weapon])) {
            return [];
        }

        return collect($all[$weapon])
            ->map(fn (array $entry): array => $this->slim($entry, withRarity: true))
            ->values()->all();
    }

    /**
     * Knife types. SkinKnife stores the classname string directly - there is
     * no paint_id column, so (unlike guns) there is no per-knife paintkit
     * step here.
     *
     * @return array<int, array{name: string, index: int, label: string}>
     */
    public function knives(): array
    {
        return collect($this->rawItems())
            ->filter(fn (array $item): bool => str_starts_with((string) ($item['Name'] ?? ''), 'weapon_knife_')
                || ($item['Name'] ?? '') === 'weapon_bayonet')
            ->map(fn (array $item): array => $this->slim($item))
            ->values()->all();
    }

    /**
     * Glove types. SkinGloves.weapon_defindex is items.json's Index for
     * these entries directly - like knives, there is no paint sub-choice.
     *
     * @return array<int, array{name: string, index: int, label: string}>
     */
    public function gloves(): array
    {
        return collect($this->rawItems())
            ->filter(fn (array $item): bool => str_contains((string) ($item['Name'] ?? ''), 'glove')
                || str_contains((string) ($item['Name'] ?? ''), 'handwrap'))
            ->map(fn (array $item): array => $this->slim($item))
            ->values()->all();
    }

    /**
     * Agents. The catalog carries no explicit team field; CS2's own naming
     * convention (agents/models/tm_* = Terrorist, ctm_* = Counter-Terrorist)
     * is the only signal available, so it's used as a best-effort filter -
     * team null returns every agent unfiltered.
     *
     * @return array<int, array{name: string, index: int, label: string}>
     */
    public function agents(?int $team = null): array
    {
        return collect($this->rawAgents())
            ->filter(function (array $agent) use ($team): bool {
                if ($team === null) {
                    return true;
                }

                $path = (string) ($agent['ModelPath'] ?? '');

                return $team === 2 ? str_contains($path, '/tm_') : str_contains($path, '/ctm_');
            })
            ->map(fn (array $agent): array => $this->slim($agent))
            ->values()->all();
    }

    /**
     * @return array<int, array{name: string, index: int, label: string}>
     */
    public function music(): array
    {
        return collect($this->rawMusic())
            ->map(fn (array $kit): array => $this->slim($kit))
            ->values()->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{name: string, index: int|null, label: string, rarity_color?: ?string}
     */
    private function slim(array $entry, bool $withRarity = false): array
    {
        $names = $entry['LocalizedNames'] ?? [];
        $key = self::LOCALE_MAP[app()->getLocale()] ?? 'english';
        $label = $names[$key] ?? $names['english'] ?? (string) ($entry['Name'] ?? '');

        $out = [
            'name' => (string) ($entry['Name'] ?? ''),
            'index' => isset($entry['Index']) ? (int) $entry['Index'] : null,
            'label' => $label,
        ];

        if ($withRarity) {
            $out['rarity_color'] = $entry['Rarity']['Color']['HexColor'] ?? null;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawItems(): array
    {
        return $this->raw('items.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawAgents(): array
    {
        return $this->raw('agents.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawPaintkits(): array
    {
        return $this->raw('weapon_to_paintkits.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawMusic(): array
    {
        return $this->raw('musickits.json');
    }

    /**
     * Locale-independent: only decodes and slices the raw plugin dump, so
     * one cache entry serves every locale (slim() runs fresh per request).
     *
     * @return array<string, mixed>
     */
    private function raw(string $file): array
    {
        return Cache::remember("catalog:raw:{$file}", self::TTL, function () use ($file): array {
            $path = rtrim((string) config('catalog.path'), '/\\').DIRECTORY_SEPARATOR.$file;

            if (! File::exists($path)) {
                return [];
            }

            $decoded = json_decode((string) File::get($path), true);

            return is_array($decoded) ? $decoded : [];
        });
    }
}
