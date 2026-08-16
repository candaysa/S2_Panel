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
     * Buy-menu-style grouping for the weapons() list, matching CS2's own
     * category split (snipers live under Rifles there too, not a category
     * of their own). Anything not listed falls back to 'rifle' rather than
     * disappearing from every filter.
     */
    private const WEAPON_CATEGORIES = [
        'weapon_glock' => 'pistol', 'weapon_usp_silencer' => 'pistol', 'weapon_hkp2000' => 'pistol',
        'weapon_elite' => 'pistol', 'weapon_p250' => 'pistol', 'weapon_fiveseven' => 'pistol',
        'weapon_tec9' => 'pistol', 'weapon_cz75a' => 'pistol', 'weapon_deagle' => 'pistol',
        'weapon_revolver' => 'pistol', 'weapon_taser' => 'pistol',

        'weapon_mac10' => 'smg', 'weapon_mp9' => 'smg', 'weapon_mp7' => 'smg',
        'weapon_mp5sd' => 'smg', 'weapon_ump45' => 'smg', 'weapon_p90' => 'smg', 'weapon_bizon' => 'smg',

        'weapon_famas' => 'rifle', 'weapon_galilar' => 'rifle', 'weapon_ak47' => 'rifle',
        'weapon_m4a1' => 'rifle', 'weapon_m4a1_silencer' => 'rifle', 'weapon_sg556' => 'rifle',
        'weapon_aug' => 'rifle', 'weapon_ssg08' => 'rifle', 'weapon_awp' => 'rifle',
        'weapon_scar20' => 'rifle', 'weapon_g3sg1' => 'rifle',

        'weapon_nova' => 'heavy', 'weapon_xm1014' => 'heavy', 'weapon_mag7' => 'heavy',
        'weapon_sawedoff' => 'heavy', 'weapon_m249' => 'heavy', 'weapon_negev' => 'heavy',
    ];

    /**
     * Skinnable guns: present in items.json (for the Index/defindex) AND
     * weapon_to_paintkits.json (proof a paintkit list exists for it).
     *
     * Knives (weapon_knife_*, weapon_bayonet) are deliberately excluded even
     * though they also appear in weapon_to_paintkits.json (knife finishes
     * are paintkits too) - they have their own knives() catalog and their
     * own panel tab; leaving them in here is what made rifle/pistol/etc
     * filters show knives mixed in with guns.
     *
     * @return array<int, array{name: string, index: int, label: string, category: string}>
     */
    public function weapons(): array
    {
        $paintable = $this->rawPaintkits();

        return collect($this->rawItems())
            ->filter(function (array $item): bool {
                $name = (string) ($item['Name'] ?? '');

                return str_starts_with($name, 'weapon_')
                    && ! str_starts_with($name, 'weapon_knife_')
                    && $name !== 'weapon_bayonet';
            })
            ->filter(fn (array $item): bool => array_key_exists($item['Name'], $paintable))
            ->map(function (array $item): array {
                $slim = $this->slim($item);
                $slim['category'] = self::WEAPON_CATEGORIES[$item['Name']] ?? 'rifle';

                return $slim;
            })
            ->values()->all();
    }

    /**
     * Paint id -> {label, rarity_color}, merged across every weapon's
     * paintkit list. Paint ids are a single global numbering (Valve's
     * paintkits.txt) reused across every compatible weapon, not a
     * per-weapon sequence - verified against the live catalog with zero
     * id/name collisions across 1400+ ids - so one flat map is enough to
     * label any equipped paint without fetching that weapon's own paint
     * list first (which the weapon list view has no reason to have loaded
     * yet).
     *
     * @return array<int, array{label: string, rarity_color: ?string}>
     */
    public function paintNames(): array
    {
        return Cache::remember("catalog:paint-names:".app()->getLocale(), self::TTL, function (): array {
            $map = [];

            foreach ($this->rawPaintkits() as $weapon => $paints) {
                if (str_starts_with($weapon, 'weapon_knife_') || $weapon === 'weapon_bayonet') {
                    continue;
                }

                foreach ($paints as $entry) {
                    $index = $entry['Index'] ?? null;

                    if ($index === null) {
                        continue;
                    }

                    $slim = $this->slim($entry, withRarity: true);
                    $map[(int) $index] = [
                        'label' => $slim['label'],
                        'rarity_color' => $slim['rarity_color'],
                        'legacy' => $slim['legacy'] ?? false,
                    ];
                }
            }

            return $map;
        });
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
     * @return array<int, array{name: string, index: int, label: string, rarity_color: ?string}>
     */
    public function keychains(): array
    {
        return collect($this->rawKeychains())
            ->map(fn (array $k): array => $this->slim($k, withRarity: true))
            ->values()->all();
    }

    /**
     * Every sticker across every capsule/collection, flattened into one
     * list. sticker_collections.json groups them by collection (each with
     * its own "Stickers" sub-array) because that's how Valve's own item
     * schema groups them, not because the panel cares which capsule a
     * sticker came from - a player picking a sticker for their weapon just
     * wants to find it by name.
     *
     * ~8,800 stickers total, so this is fetched on demand (only once the
     * sticker picker is actually opened) rather than eagerly with the rest
     * of the weapon catalog, and the frontend search-filters it rather than
     * rendering all of them at once.
     *
     * @return array<int, array{name: string, index: int, label: string, rarity_color: ?string}>
     */
    public function stickers(): array
    {
        return Cache::remember("catalog:stickers:".app()->getLocale(), self::TTL, function (): array {
            $out = [];

            foreach ($this->rawStickerCollections() as $collection) {
                foreach ($collection['Stickers'] ?? [] as $sticker) {
                    $out[] = $this->slim($sticker, withRarity: true);
                }
            }

            return $out;
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{name: string, index: int|null, label: string, rarity_color?: ?string, legacy?: bool}
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

        // Valve authored each finish against exactly one of the two weapon
        // models the .glb files bundle (CS:GO-era "legacy" vs CS2 "hd"),
        // and this schema flag records which. The 3D viewer needs it to
        // show the right mesh - rendering both at once was what made
        // skinned weapons look half-textured. Keyed off the field actually
        // being present rather than a caller flag, so paintkits get it and
        // the ~8,800 sticker entries (which share slim() but have no such
        // concept) don't each carry a pointless "legacy":false.
        if (array_key_exists('UseLegacyModel', $entry)) {
            $out['legacy'] = (bool) $entry['UseLegacyModel'];
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
     * @return array<string, mixed>
     */
    private function rawKeychains(): array
    {
        return $this->raw('keychains.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawStickerCollections(): array
    {
        return $this->raw('sticker_collections.json');
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
