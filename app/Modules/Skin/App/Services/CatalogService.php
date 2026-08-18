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
     * paintkit list - knives and gloves included, since a finish on either
     * is an ordinary paintkit stored in wp_player_skins against that item's
     * defindex, and the picker needs to label it exactly like a gun's. Paint ids are a single global numbering (Valve's
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

            foreach ($this->rawPaintkits() as $paints) {
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

        // A real per-paint image when one is known (see gloveImages()/
        // knifeImages()/weaponImages()) - checked in that order since a
        // name is never in more than one. Guns and knives additionally
        // still have the frontend's name+id guess as a fallback for
        // whatever a snapshot does not cover (knives matched 556/556 at
        // export time - full coverage; guns matched 1,456/1,478, 98.5%, a
        // genuinely new finish being the only realistic gap); gloves have
        // no such fallback, because that guess has real, systematic holes
        // for them specifically.
        $images = $this->gloveImages()[$weapon]
            ?? $this->knifeImages()[$weapon]
            ?? $this->weaponImages()[$weapon]
            ?? null;

        return collect($all[$weapon])
            ->map(function (array $entry) use ($images): array {
                $slim = $this->slim($entry, withRarity: true);

                if ($images !== null && $slim['index'] !== null) {
                    $image = $images[(string) $slim['index']] ?? null;

                    if ($image !== null) {
                        $slim['image'] = $image;
                    }
                }

                return $slim;
            })
            ->values()->all();
    }

    /**
     * Per-paint gun images, sourced from ByMykel/CSGO-API (MIT) the same
     * way gloveImages() is - a real picture per (weapon, paint) pair rather
     * than a guessed {name}-{id}.png URL. Guns don't have the systemic gap
     * gloves do (most finishes the guess pattern predicts really do exist),
     * but they do have real, individual holes - "AK-47 | Crane Flight" was
     * one, confirmed 404 against the pattern the frontend otherwise
     * builds - so this is the same fix at gun scale: 1,456/1,478 (98.5%) of
     * the live catalog matched at export time; the frontend's own guess
     * remains the fallback for whatever this snapshot does not cover.
     *
     * @return array<string, array<string, string>> weapon name -> paint id -> image URL
     */
    private function weaponImages(): array
    {
        return Cache::remember('catalog:weapon-images', self::TTL, function (): array {
            $path = __DIR__.'/../../Resources/weapon_images.json';

            if (! File::exists($path)) {
                return [];
            }

            $decoded = json_decode((string) File::get($path), true);

            return is_array($decoded) ? $decoded : [];
        });
    }

    /**
     * Per-paint knife images, sourced from ByMykel/CSGO-API (MIT) the same
     * way weaponImages() is. Full coverage here, unlike guns/gloves: 556/556
     * (100%) of the live catalog's knife finishes matched at export time -
     * a knife's own finish list is small enough (one game-wide set of
     * skins applied to every blade shape, not a per-weapon roster like guns
     * have) that there was nothing left uncovered to fall back on the
     * frontend's guess for.
     *
     * @return array<string, array<string, string>> knife classname -> paint id -> image URL
     */
    private function knifeImages(): array
    {
        return Cache::remember('catalog:knife-images', self::TTL, function (): array {
            $path = __DIR__.'/../../Resources/knife_images.json';

            if (! File::exists($path)) {
                return [];
            }

            $decoded = json_decode((string) File::get($path), true);

            return is_array($decoded) ? $decoded : [];
        });
    }

    /**
     * Knife types.
     *
     * wp_player_knife stores only the classname (which knife model), with no
     * paint column - but a knife finish is an ordinary paintkit on the
     * knife's own defindex, so the paint half of a knife loadout lives in
     * wp_player_skins exactly like a gun's does. That's why every entry
     * carries its `index` here: the panel writes both tables when a knife is
     * equipped (see the skins page's saveKnife()).
     *
     * @return array<int, array{name: string, index: int, label: string, rarity_color: ?string}>
     */
    public function knives(): array
    {
        return collect($this->rawItems())
            ->filter(fn (array $item): bool => str_starts_with((string) ($item['Name'] ?? ''), 'weapon_knife_')
                || ($item['Name'] ?? '') === 'weapon_bayonet')
            ->map(fn (array $item): array => $this->slim($item, withRarity: true))
            ->values()->all();
    }

    /**
     * Glove types. SkinGloves.weapon_defindex is items.json's Index for
     * these entries directly - and, as with knives, the *finish* on those
     * gloves is a normal paintkit stored against the same defindex in
     * wp_player_skins, so both tables are written when gloves are equipped.
     *
     * Only families that actually have finishes. items.json also lists
     * t_gloves/ct_gloves - the default bare hands, which carry no paintkits
     * and are not an item anyone owns. Same reasoning that keeps knives out
     * of weapons(): listing them produces a card that can never show or do
     * anything. Taking gloves off is what the detail screen's Remove button
     * is for.
     *
     * Unlike every other slot, gloves have no unpainted artwork at all -
     * there is no "{glove}.png", only "{glove}-{paint}.png" - because a bare
     * glove model is not an item a player can own. `preview_paint` is
     * therefore attached here (this family's own highest-id finish) so a
     * card has something to draw before a finish has been chosen; without
     * it the gloves tab renders as a wall of unlabelled boxes.
     *
     * `images` is every finish this family actually has, each pointing at
     * its own real picture from gloveImages() rather than a guessed name+id
     * URL - that pattern has a genuine gap, not just occasional missing art:
     * every finish older than late 2019 (Wave Chaser, Lime Polycam, ...) has
     * a catalog entry with no corresponding image anywhere in the set every
     * other slot uses, which is exactly why they rendered blank before this.
     *
     * @return array<int, array{name: string, index: int, label: string, rarity_color: ?string, images: array<string, string>, preview_paint: ?int}>
     */
    public function gloves(): array
    {
        $paintable = $this->rawPaintkits();
        $images = $this->gloveImages();

        return collect($this->rawItems())
            ->filter(fn (array $item): bool => str_contains((string) ($item['Name'] ?? ''), 'glove')
                || str_contains((string) ($item['Name'] ?? ''), 'handwrap'))
            ->filter(fn (array $item): bool => ! empty($paintable[(string) ($item['Name'] ?? '')]))
            ->map(function (array $item) use ($paintable, $images): array {
                $slim = $this->slim($item, withRarity: true);
                $name = (string) ($item['Name'] ?? '');

                $ids = collect($paintable[$name])
                    ->pluck('Index')
                    ->filter()
                    ->map(fn ($index): int => (int) $index)
                    ->sortDesc()
                    ->values();

                // Every finish this family actually has, each pointing at its
                // own real image (see gloveImages()) - not a Nereziel-style
                // {name}-{id}.png pattern, which has no entry at all for the
                // pre-2019 finishes (id < 10000: Wave Chaser, Lime Polycam,
                // ...) and left those permanently blank. A ->filter() below
                // drops any id this snapshot doesn't cover (a paint the game
                // added after it was taken) rather than emitting a null the
                // frontend would have to guard against.
                $slim['images'] = $ids
                    ->mapWithKeys(fn (int $id): array => [(string) $id => $images[$name][(string) $id] ?? null])
                    ->filter()
                    ->all();

                // Cards need something to show before any paint is chosen or
                // equipped - gloves have no unpainted look, so this is simply
                // the family's own highest-id finish, which is guaranteed to
                // have a real image above.
                $slim['preview_paint'] = $ids->first();

                return $slim;
            })
            ->values()->all();
    }

    /**
     * Per-paint glove images, sourced from ByMykel/CSGO-API (MIT) rather
     * than built from a name+id URL pattern like every other slot: gloves
     * are the one item where that pattern has real gaps, not just
     * occasional missing art - every finish predating late 2019 (Wave
     * Chaser, Lime Polycam, Superconductor, ...) has a catalog entry with
     * no corresponding image anywhere in Nereziel's set. glove_images.json
     * is a one-time export (see Resources/, keyed identically to
     * weapon_to_paintkits.json's own glove families) rather than a live API
     * call, for the same reason agent_images.json is: this data changes only
     * when Valve ships new finishes, not per request.
     *
     * @return array<string, array<string, string>> weapon name -> paint id -> image URL
     */
    private function gloveImages(): array
    {
        return Cache::remember('catalog:glove-images', self::TTL, function (): array {
            $path = __DIR__.'/../../Resources/glove_images.json';

            if (! File::exists($path)) {
                return [];
            }

            $decoded = json_decode((string) File::get($path), true);

            return is_array($decoded) ? $decoded : [];
        });
    }

    /**
     * Agents. The catalog carries no explicit team field; CS2's own naming
     * convention (agents/models/tm_* = Terrorist, ctm_* = Counter-Terrorist)
     * is the only signal available, so it's used as a best-effort filter -
     * team null returns every agent unfiltered.
     *
     * A preview picture is a join against a static id lookup, not the
     * plugin's own export: agent_images.json (shipped in this module,
     * unlike the storage/app/catalog/*.json dumps) maps each agent's own
     * "tm_foo/tm_foo_variantx" model path to the numeric id a third-party
     * skins CDN happens to use, built once by matching this catalog's own
     * agent list against that CDN's - 62 of 63 agents matched, the rest
     * simply have no `image` key and the picker falls back to a silhouette.
     *
     * @return array<int, array{name: string, index: int, label: string, rarity_color: ?string, image?: string}>
     */
    public function agents(?int $team = null): array
    {
        $images = $this->agentImages();

        return collect($this->rawAgents())
            ->filter(function (array $agent) use ($team): bool {
                if ($team === null) {
                    return true;
                }

                $path = (string) ($agent['ModelPath'] ?? '');

                return $team === 2 ? str_contains($path, '/tm_') : str_contains($path, '/ctm_');
            })
            ->map(function (array $agent) use ($images): array {
                $slim = $this->slim($agent, withRarity: true);
                $modelKey = preg_replace('#^agents/models/#', '', (string) ($agent['Name'] ?? ''));

                if ($modelKey !== null && isset($images[$modelKey])) {
                    $slim['image'] = "https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/agent-{$images[$modelKey]}.png";
                }

                return $slim;
            })
            ->values()->all();
    }

    /**
     * @return array<string, int>
     */
    private function agentImages(): array
    {
        return Cache::remember('catalog:agent-images', self::TTL, function (): array {
            $path = __DIR__.'/../../Resources/agent_images.json';

            if (! File::exists($path)) {
                return [];
            }

            $decoded = json_decode((string) File::get($path), true);

            return is_array($decoded) ? $decoded : [];
        });
    }

    /**
     * @return array<int, array{name: string, index: int, label: string, rarity_color: ?string}>
     */
    public function music(): array
    {
        return collect($this->rawMusic())
            ->map(fn (array $kit): array => $this->slim($kit, withRarity: true))
            ->values()->all();
    }

    /**
     * @return array<int, array{name: string, index: int, label: string, rarity_color: ?string}>
     */
    public function keychains(): array
    {
        $images = $this->keychainImages();

        return collect($this->rawKeychains())
            ->map(function (array $k) use ($images): array {
                $slim = $this->slim($k, withRarity: true);
                $image = $images[(string) ($k['Name'] ?? '')] ?? null;

                if ($image !== null) {
                    $slim['image'] = $image;
                }

                return $slim;
            })
            ->values()->all();
    }

    /**
     * @return array<string, string> keychain id -> image URL
     */
    private function keychainImages(): array
    {
        return Cache::remember('catalog:keychain-images', self::TTL, function (): array {
            $path = __DIR__.'/../../Resources/keychain_images.json';

            if (! File::exists($path)) {
                return [];
            }

            $decoded = json_decode((string) File::get($path), true);

            return is_array($decoded) ? $decoded : [];
        });
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
        $images = $this->stickerImages();

        return Cache::remember("catalog:stickers:".app()->getLocale(), self::TTL, function () use ($images): array {
            $out = [];

            foreach ($this->rawStickerCollections() as $collection) {
                foreach ($collection['Stickers'] ?? [] as $sticker) {
                    $slim = $this->slim($sticker, withRarity: true);
                    $image = $images[(string) ($sticker['Name'] ?? '')] ?? null;

                    if ($image !== null) {
                        $slim['image'] = $image;
                    }

                    $out[] = $slim;
                }
            }

            return $out;
        });
    }

    /**
     * Per-sticker images, sourced from ByMykel/CSGO-API (MIT) and keyed by
     * the same material name (e.g. "std_thirteen") the plugin's own dump
     * uses as Name - sticker_collections.json carries no image field at all,
     * only Name/Index/LocalizedNames/Rarity. sticker_images.json is a
     * one-time export rather than a live call, for the same reason
     * gloveImages() is: matched 8,828/8,828 (100%) of the live catalog at
     * export time; a sticker the game adds afterward simply has no
     * thumbnail until the export is refreshed, and the picker already
     * degrades gracefully to a text-only row for that case.
     *
     * @return array<string, string> sticker material name -> image URL
     */
    private function stickerImages(): array
    {
        return Cache::remember('catalog:sticker-images', self::TTL, function (): array {
            $path = __DIR__.'/../../Resources/sticker_images.json';

            if (! File::exists($path)) {
                return [];
            }

            $decoded = json_decode((string) File::get($path), true);

            return is_array($decoded) ? $decoded : [];
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
