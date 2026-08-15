<x-layout.app :title="__('i18n::messages.nav.skins')">
    <div x-data="skinsPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.skins') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.skins.subtitle') }}</p>

        <div class="mt-5 flex gap-1.5">
            <template x-for="t in [2, 3]" :key="t">
                <button
                    type="button"
                    @click="team = t; selectedWeapon = null"
                    :class="team === t ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                    x-text="t === 2 ? @js(__('i18n::messages.skins.team_t')) : @js(__('i18n::messages.skins.team_ct'))"
                ></button>
            </template>
        </div>

        <div class="mt-4 flex flex-wrap gap-1 border-b border-line">
            <template x-for="s in sections" :key="s.key">
                <button
                    type="button"
                    @click="section = s.key; selectedWeapon = null; loadSection()"
                    :class="section === s.key ? 'border-brand-strong text-brand-strong' : 'border-transparent text-ink-muted hover:text-ink'"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                    x-text="s.label"
                ></button>
            </template>
        </div>

        <p x-show="loading" x-cloak class="mt-6 text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
        <p x-show="error" x-cloak class="mt-6 text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>

        <div x-show="!loading" x-cloak class="mt-6">
            {{-- Weapons: list + a sub-picker (paint/wear/seed/stattrak/nametag) for the selected one --}}
            <template x-if="section === 'weapons'">
                <div>
                    <template x-if="!selectedWeapon">
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                            <template x-for="w in weapons" :key="w.name">
                                <button
                                    type="button"
                                    @click="openWeapon(w)"
                                    class="overflow-hidden rounded-lg border border-line bg-surface text-left text-sm transition-colors hover:bg-surface-raised"
                                >
                                    {{-- Preview art, when the equipped paint has one - see
                                         skinImageUrl(). Not every weapon+paint pair has an
                                         image available, so a broken load just hides the
                                         image and falls back to the label alone. --}}
                                    <div class="flex h-20 items-center justify-center bg-canvas" x-show="equippedWeapon(w)">
                                        <img
                                            :src="skinImageUrl(w.name, equippedWeapon(w)?.weapon_paint_id)"
                                            alt=""
                                            loading="lazy"
                                            class="max-h-full max-w-full object-contain p-1.5"
                                            @@error="$el.parentElement.style.display = 'none'"
                                        >
                                    </div>
                                    <div class="p-3">
                                        <span class="block truncate font-medium text-ink" x-text="w.label"></span>
                                        <span
                                            class="mt-1 block truncate text-xs"
                                            :class="equippedWeapon(w) ? 'text-brand-strong' : 'text-ink-faint'"
                                            x-text="equippedWeapon(w) ? equippedWeapon(w).paint_label : @js(__('i18n::messages.skins.no_data'))"
                                        ></span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </template>

                    <template x-if="selectedWeapon">
                        <div class="max-w-2xl">
                            <button type="button" @click="selectedWeapon = null" class="text-sm text-ink-muted hover:text-ink">
                                ← {{ __('i18n::messages.skins.back') }}
                            </button>

                            <h2 class="mt-2 text-lg font-semibold text-ink" x-text="selectedWeapon.label"></h2>

                            {{-- Live preview of whatever paint is currently picked below,
                                 not necessarily saved yet. --}}
                            <div class="mt-3 flex h-40 items-center justify-center rounded-lg border border-line bg-canvas" x-show="weaponForm.weapon_paint_id">
                                <img
                                    :src="skinImageUrl(selectedWeapon.name, weaponForm.weapon_paint_id)"
                                    alt=""
                                    class="max-h-full max-w-full object-contain p-3"
                                    @@error="$el.style.display = 'none'"
                                    @load="$el.style.display = ''"
                                >
                            </div>

                            <label class="mt-4 block text-sm font-medium text-ink-muted">{{ __('i18n::messages.skins.choose_paint') }}</label>
                            <div class="mt-2 grid max-h-80 grid-cols-3 gap-2 overflow-y-auto rounded-lg border border-line bg-canvas p-2 sm:grid-cols-4">
                                <button
                                    type="button"
                                    @click="weaponForm.weapon_paint_id = 0"
                                    class="rounded-lg border p-2 text-center text-xs transition-colors"
                                    :class="weaponForm.weapon_paint_id === 0 ? 'border-brand-strong bg-brand-soft text-brand-strong' : 'border-line bg-surface text-ink-muted hover:bg-surface-raised'"
                                >
                                    {{ __('i18n::messages.skins.default_paint') }}
                                </button>
                                <template x-for="p in paints" :key="p.index">
                                    <button
                                        type="button"
                                        @click="weaponForm.weapon_paint_id = p.index"
                                        class="overflow-hidden rounded-lg border text-left text-xs transition-colors"
                                        :class="weaponForm.weapon_paint_id === p.index ? 'border-brand-strong bg-brand-soft' : 'border-line bg-surface hover:bg-surface-raised'"
                                    >
                                        <div class="flex h-14 items-center justify-center bg-canvas">
                                            <img
                                                :src="skinImageUrl(selectedWeapon.name, p.index)"
                                                alt=""
                                                loading="lazy"
                                                class="max-h-full max-w-full object-contain p-1"
                                                @@error="$el.style.visibility = 'hidden'"
                                            >
                                        </div>
                                        <div class="flex items-center gap-1 p-1.5">
                                            <span class="size-1.5 shrink-0 rounded-full" :style="p.rarity_color ? 'background:' + p.rarity_color : ''"></span>
                                            <span class="truncate" :class="weaponForm.weapon_paint_id === p.index ? 'text-brand-strong' : 'text-ink-muted'" x-text="p.label"></span>
                                        </div>
                                    </button>
                                </template>
                            </div>

                            <div class="mt-4 grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-ink-muted">{{ __('i18n::messages.skins.wear') }}</label>
                                    <input type="range" min="0" max="1" step="0.001" x-model.number="weaponForm.weapon_wear" class="mt-2 w-full">
                                    <p class="mt-1 text-xs text-ink-faint" x-text="weaponForm.weapon_wear.toFixed(3)"></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-ink-muted">{{ __('i18n::messages.skins.seed') }}</label>
                                    <input type="number" min="0" max="99999" x-model.number="weaponForm.weapon_seed" class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                                </div>
                            </div>

                            <label class="mt-4 flex items-center gap-2 text-sm text-ink-muted">
                                <input type="checkbox" x-model="weaponForm.weapon_stattrak" class="rounded border-line">
                                {{ __('i18n::messages.skins.stattrak') }}
                            </label>

                            <label class="mt-4 block text-sm font-medium text-ink-muted">{{ __('i18n::messages.skins.nametag') }}</label>
                            <input type="text" x-model="weaponForm.weapon_nametag" maxlength="128" placeholder="{{ __('i18n::messages.skins.nametag_placeholder') }}" class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">

                            <div class="mt-5 flex gap-2">
                                <button type="button" :disabled="saving" @click="saveWeapon()" class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50">
                                    {{ __('i18n::messages.skins.equip') }}
                                </button>
                                <button type="button" x-show="equippedWeapon(selectedWeapon)" @click="removeWeapon()" class="rounded-lg border border-line px-4 py-2 text-sm text-red-400 transition-colors hover:bg-red-500/10">
                                    {{ __('i18n::messages.skins.remove') }}
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Knife / Gloves / Agent / Music: pick-a-card, no sub-attributes --}}
            <template x-if="section !== 'weapons'">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    <template x-for="item in currentCatalog()" :key="item.name">
                        <button
                            type="button"
                            @click="pick(item)"
                            class="rounded-lg border p-3 text-left text-sm transition-colors"
                            :class="isEquipped(item) ? 'border-brand-strong bg-brand-soft' : 'border-line bg-surface hover:bg-surface-raised'"
                        >
                            <span class="block truncate font-medium" :class="isEquipped(item) ? 'text-brand-strong' : 'text-ink'" x-text="item.label"></span>
                            <span x-show="isEquipped(item)" class="mt-1 block text-xs text-brand-strong">{{ __('i18n::messages.skins.equipped') }}</span>
                        </button>
                    </template>
                </div>
                <p x-show="currentCatalog().length === 0" class="mt-2 text-sm text-ink-faint">{{ __('i18n::messages.skins.no_data') }}</p>
            </template>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.skinsPage = () => ({
                ownSteamId: @js($ownSteamId),
                team: 2,
                section: 'weapons',
                loading: true,
                saving: false,
                error: false,
                profile: null,
                weapons: [],
                paints: [],
                selectedWeapon: null,
                weaponForm: { weapon_paint_id: 0, weapon_wear: 0.01, weapon_seed: 0, weapon_stattrak: false, weapon_nametag: '' },
                knives: [],
                gloves: [],
                agents: { 2: [], 3: [] },
                music: [],

                sections: [
                    { key: 'weapons', label: @js(__('i18n::messages.skins.weapons')) },
                    { key: 'knife', label: @js(__('i18n::messages.skins.knife')) },
                    { key: 'gloves', label: @js(__('i18n::messages.skins.gloves')) },
                    { key: 'agent', label: @js(__('i18n::messages.skins.agent')) },
                    { key: 'music', label: @js(__('i18n::messages.skins.music')) },
                ],

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                // Weapon defindex/paint IDs are Valve's own numbering (the
                // catalog itself comes straight from the game's item schema
                // - see CatalogService), not anything CS2_Skin invented, so
                // preview art keyed the same way from a different weapon-skin
                // project's asset set lines up with our own data too. Not
                // every combination has an image; the <img> tags this feeds
                // hide themselves on a failed load rather than showing a
                // broken-image icon.
                skinImageUrl(weaponName, paintId) {
                    if (!weaponName || !paintId) return '';
                    return `https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/${weaponName}-${paintId}.png`;
                },

                async fetchJson(url) {
                    const res = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    return (await res.json()).data;
                },

                async init() {
                    try {
                        this.profile = await this.fetchJson(`/api/skins/${this.ownSteamId}`);
                        this.weapons = await this.fetchJson('/api/skins/catalog/weapons');
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                async loadSection() {
                    if (this.section === 'knife' && this.knives.length === 0) {
                        this.knives = await this.fetchJson('/api/skins/catalog/knives').catch(() => []);
                    }
                    if (this.section === 'gloves' && this.gloves.length === 0) {
                        this.gloves = await this.fetchJson('/api/skins/catalog/gloves').catch(() => []);
                    }
                    if (this.section === 'agent' && this.agents[this.team].length === 0) {
                        this.agents[this.team] = await this.fetchJson(`/api/skins/catalog/agents?team=${this.team}`).catch(() => []);
                    }
                    if (this.section === 'music' && this.music.length === 0) {
                        this.music = await this.fetchJson('/api/skins/catalog/music').catch(() => []);
                    }
                },

                currentCatalog() {
                    if (this.section === 'knife') return this.knives;
                    if (this.section === 'gloves') return this.gloves;
                    if (this.section === 'agent') return this.agents[this.team];
                    if (this.section === 'music') return this.music;
                    return [];
                },

                rowsFor(slot) {
                    const key = { knife: 'knife', gloves: 'gloves', agent: 'agents', music: 'music' }[slot];
                    return (this.profile?.[key] ?? []).filter((r) => r.weapon_team === this.team);
                },

                isEquipped(item) {
                    if (this.section === 'knife') return this.rowsFor('knife').some((r) => r.knife === item.name);
                    if (this.section === 'gloves') return this.rowsFor('gloves').some((r) => r.weapon_defindex === item.index);
                    if (this.section === 'agent') return this.rowsFor('agent').some((r) => r.agent_index === item.index);
                    if (this.section === 'music') return this.rowsFor('music').some((r) => r.music_id === item.index);
                    return false;
                },

                equippedWeapon(weapon) {
                    const row = (this.profile?.skins ?? []).find((r) => r.weapon_team === this.team && r.weapon_defindex === weapon.index);
                    if (!row || !row.weapon_paint_id) return null;
                    const paint = this.paints.find((p) => p.index === row.weapon_paint_id);
                    return { ...row, paint_label: paint?.label ?? `#${row.weapon_paint_id}` };
                },

                async openWeapon(weapon) {
                    this.selectedWeapon = weapon;
                    this.paints = await this.fetchJson(`/api/skins/catalog/weapons/${weapon.name}/paints`).catch(() => []);
                    const row = (this.profile?.skins ?? []).find((r) => r.weapon_team === this.team && r.weapon_defindex === weapon.index);
                    this.weaponForm = row
                        ? {
                            weapon_paint_id: row.weapon_paint_id ?? 0,
                            weapon_wear: row.weapon_wear ?? 0.01,
                            weapon_seed: row.weapon_seed ?? 0,
                            weapon_stattrak: !!row.weapon_stattrak,
                            weapon_nametag: row.weapon_nametag ?? '',
                        }
                        : { weapon_paint_id: 0, weapon_wear: 0.01, weapon_seed: 0, weapon_stattrak: false, weapon_nametag: '' };
                },

                async saveWeapon() {
                    this.saving = true;
                    this.error = false;
                    try {
                        // The API upserts the whole row - carry over sticker/keychain
                        // fields from the existing row untouched so equipping a paint
                        // here never silently wipes stickers applied elsewhere.
                        const existing = (this.profile?.skins ?? []).find(
                            (r) => r.weapon_team === this.team && r.weapon_defindex === this.selectedWeapon.index
                        ) ?? {};

                        const res = await fetch(`/api/skins/${this.ownSteamId}/weapon`, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify({
                                team: this.team,
                                defindex: this.selectedWeapon.index,
                                ...this.weaponForm,
                                weapon_sticker_0: existing.weapon_sticker_0,
                                weapon_sticker_1: existing.weapon_sticker_1,
                                weapon_sticker_2: existing.weapon_sticker_2,
                                weapon_sticker_3: existing.weapon_sticker_3,
                                weapon_sticker_4: existing.weapon_sticker_4,
                                weapon_sticker_5: existing.weapon_sticker_5,
                                weapon_keychain: existing.weapon_keychain,
                            }),
                        });
                        if (!res.ok) throw new Error('request_failed');
                        this.profile = await this.fetchJson(`/api/skins/${this.ownSteamId}`);
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.saving = false;
                    }
                },

                async removeWeapon() {
                    this.error = false;
                    try {
                        const url = `/api/skins/${this.ownSteamId}/weapon?team=${this.team}&defindex=${this.selectedWeapon.index}`;
                        const res = await fetch(url, { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() } });
                        if (!res.ok) throw new Error('request_failed');
                        this.profile = await this.fetchJson(`/api/skins/${this.ownSteamId}`);
                        this.selectedWeapon = null;
                    } catch (e) {
                        this.error = true;
                    }
                },

                async pick(item) {
                    this.error = false;
                    const body = { team: this.team };
                    if (this.section === 'knife') body.knife = item.name;
                    if (this.section === 'gloves') body.weapon_defindex = item.index;
                    if (this.section === 'agent') body.agent_index = item.index;
                    if (this.section === 'music') body.music_id = item.index;

                    try {
                        const res = await fetch(`/api/skins/${this.ownSteamId}/${this.section}`, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify(body),
                        });
                        if (!res.ok) throw new Error('request_failed');
                        this.profile = await this.fetchJson(`/api/skins/${this.ownSteamId}`);
                    } catch (e) {
                        this.error = true;
                    }
                },
            });
        </script>
    @endpush
</x-layout.app>
