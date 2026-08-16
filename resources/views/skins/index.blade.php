<x-layout.app :title="__('i18n::messages.nav.skins')">
    <div x-data="skinsPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.skins') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.skins.subtitle') }}</p>

        <div class="mt-5 flex flex-wrap gap-1 border-b border-line">
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

        {{-- Agents are the one thing genuinely locked to a side (a T model
             cannot be worn as CT), so this toggle only exists here - every
             other tab applies its pick to both teams at once. --}}
        <div class="mt-4 flex gap-1.5" x-show="section === 'agent'" x-cloak>
            <template x-for="t in [2, 3]" :key="t">
                <button
                    type="button"
                    @click="team = t; loadSection()"
                    :class="team === t ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                    x-text="t === 2 ? @js(__('i18n::messages.skins.team_t')) : @js(__('i18n::messages.skins.team_ct'))"
                ></button>
            </template>
        </div>

        {{-- Weapon category sub-filter - rifle/pistol/smg/heavy, matching
             CS2's own buy-menu split (see CatalogService::WEAPON_CATEGORIES).
             Knives are excluded from the weapons catalog entirely now, not
             just hidden here - they have their own tab. --}}
        <div class="mt-4 flex flex-wrap gap-1.5" x-show="section === 'weapons' && !selectedWeapon" x-cloak>
            <template x-for="c in weaponCategories" :key="c.key">
                <button
                    type="button"
                    @click="weaponCategory = c.key"
                    :class="weaponCategory === c.key ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                    x-text="c.label"
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
                            <template x-for="w in filteredWeapons()" :key="w.name">
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
                            <p x-show="filteredWeapons().length === 0" class="col-span-full text-sm text-ink-faint">{{ __('i18n::messages.skins.no_data') }}</p>
                        </div>
                    </template>

                    <template x-if="selectedWeapon">
                        <div class="max-w-2xl">
                            <button type="button" @click="closeWeapon()" class="text-sm text-ink-muted hover:text-ink">
                                ← {{ __('i18n::messages.skins.back') }}
                            </button>

                            <h2 class="mt-2 text-lg font-semibold text-ink" x-text="selectedWeapon.label"></h2>

                            {{-- Live preview of whatever paint is currently picked below,
                                 not necessarily saved yet. Two modes: the flat
                                 reference image (fast, always accurate to the
                                 pattern), or a real rotatable 3D model of the
                                 weapon itself (see skin-viewer.js - shows the
                                 model's own shape, not this image projected
                                 onto it). --}}
                            <div class="mt-3 flex items-center gap-1.5" x-show="weaponForm.weapon_paint_id">
                                <button
                                    type="button"
                                    @click="previewMode = '2d'"
                                    :class="previewMode === '2d' ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                                    class="rounded-lg px-3 py-1 text-xs font-medium transition-colors"
                                >{{ __('i18n::messages.skins.preview_2d') }}</button>
                                <button
                                    type="button"
                                    @click="previewMode = '3d'; mount3d()"
                                    :class="previewMode === '3d' ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                                    class="rounded-lg px-3 py-1 text-xs font-medium transition-colors"
                                >{{ __('i18n::messages.skins.preview_3d') }}</button>
                            </div>

                            <div class="mt-2 h-56 overflow-hidden rounded-lg border border-line bg-canvas" x-show="weaponForm.weapon_paint_id">
                                <div class="flex h-full items-center justify-center" x-show="previewMode === '2d'">
                                    <img
                                        :src="skinImageUrl(selectedWeapon.name, weaponForm.weapon_paint_id)"
                                        alt=""
                                        class="max-h-full max-w-full object-contain p-3"
                                        @@error="$el.style.display = 'none'"
                                        @load="$el.style.display = ''"
                                    >
                                </div>
                                <div class="relative h-full" x-show="previewMode === '3d'" x-cloak>
                                    <div x-ref="viewer3d" class="h-full w-full"></div>
                                    <p x-show="loading3d" class="pointer-events-none absolute inset-0 flex items-center justify-center text-xs text-ink-faint">
                                        {{ __('i18n::messages.common.loading') }}
                                    </p>
                                    <p x-show="error3d" x-cloak class="pointer-events-none absolute inset-0 flex items-center justify-center px-4 text-center text-xs text-ink-faint">
                                        {{ __('i18n::messages.skins.preview_3d_unavailable') }}
                                    </p>
                                </div>
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

                            <div class="mt-5 flex items-center gap-3">
                                <button type="button" :disabled="saving" @click="saveWeapon()" class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50">
                                    {{ __('i18n::messages.skins.equip') }}
                                </button>
                                <button type="button" x-show="equippedWeapon(selectedWeapon)" @click="removeWeapon()" class="rounded-lg border border-line px-4 py-2 text-sm text-red-400 transition-colors hover:bg-red-500/10">
                                    {{ __('i18n::messages.skins.remove') }}
                                </button>
                                <span class="text-xs text-ink-faint">{{ __('i18n::messages.skins.both_teams_hint') }}</span>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Knife / Gloves / Music: pick-a-card, applies to both teams at
                 once. Agent is the exception - it stays scoped to whichever
                 side the toggle above has selected. --}}
            <template x-if="section !== 'weapons'">
                <div>
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
                    <p class="mt-3 text-xs text-ink-faint" x-show="section !== 'agent'">{{ __('i18n::messages.skins.both_teams_hint') }}</p>
                </div>
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
                weaponCategory: 'all',
                paints: [],
                paintNames: {},
                selectedWeapon: null,
                weaponForm: { weapon_paint_id: 0, weapon_wear: 0.01, weapon_seed: 0, weapon_stattrak: false, weapon_nametag: '' },
                previewMode: '2d',
                loading3d: false,
                error3d: false,
                dispose3d: null,
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

                weaponCategories: [
                    { key: 'all', label: @js(__('i18n::messages.skins.category_all')) },
                    { key: 'rifle', label: @js(__('i18n::messages.skins.category_rifle')) },
                    { key: 'pistol', label: @js(__('i18n::messages.skins.category_pistol')) },
                    { key: 'smg', label: @js(__('i18n::messages.skins.category_smg')) },
                    { key: 'heavy', label: @js(__('i18n::messages.skins.category_heavy')) },
                ],

                filteredWeapons() {
                    if (this.weaponCategory === 'all') return this.weapons;
                    return this.weapons.filter((w) => w.category === this.weaponCategory);
                },

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
                        // Global paint-id -> name/rarity map (one request for
                        // every weapon combined) so the list view can label an
                        // equipped paint without first opening that weapon's
                        // own paint picker - previously this showed a bare
                        // "#1147" until the weapon had been opened once.
                        this.paintNames = await this.fetchJson('/api/skins/catalog/paint-names').catch(() => ({}));
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

                // Knife/gloves/music apply to both teams at once, so team 2
                // (T) is read as the canonical "is this equipped" state -
                // an equip action always writes both, so the two only ever
                // disagree on data set before this behaviour existed. Agent
                // stays genuinely per-team.
                rowsFor(slot) {
                    const key = { knife: 'knife', gloves: 'gloves', agent: 'agents', music: 'music' }[slot];
                    const rows = this.profile?.[key] ?? [];
                    const team = slot === 'agent' ? this.team : 2;
                    return rows.filter((r) => r.weapon_team === team);
                },

                isEquipped(item) {
                    if (this.section === 'knife') return this.rowsFor('knife').some((r) => r.knife === item.name);
                    if (this.section === 'gloves') return this.rowsFor('gloves').some((r) => r.weapon_defindex === item.index);
                    if (this.section === 'agent') return this.rowsFor('agent').some((r) => r.agent_index === item.index);
                    if (this.section === 'music') return this.rowsFor('music').some((r) => r.music_id === item.index);
                    return false;
                },

                equippedWeapon(weapon) {
                    const row = (this.profile?.skins ?? []).find((r) => r.weapon_team === 2 && r.weapon_defindex === weapon.index);
                    if (!row || !row.weapon_paint_id) return null;
                    const paint = this.paintNames[row.weapon_paint_id];
                    return { ...row, paint_label: paint?.label ?? `#${row.weapon_paint_id}` };
                },

                async openWeapon(weapon) {
                    this.selectedWeapon = weapon;
                    this.previewMode = '2d';
                    this.error3d = false;
                    this.paints = await this.fetchJson(`/api/skins/catalog/weapons/${weapon.name}/paints`).catch(() => []);
                    const row = (this.profile?.skins ?? []).find((r) => r.weapon_team === 2 && r.weapon_defindex === weapon.index);
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

                closeWeapon() {
                    if (this.dispose3d) { this.dispose3d(); this.dispose3d = null; }
                    this.selectedWeapon = null;
                },

                // The 3D model is per-weapon, not per-paint (see skin-viewer.js -
                // it shows the real model shape, not this paint projected onto
                // it), so it only needs to (re)mount when the weapon changes or
                // the 3D tab is opened for the first time - not on every paint
                // click.
                async mount3d() {
                    if (this.dispose3d) return;

                    this.loading3d = true;
                    this.error3d = false;
                    try {
                        // Loaded via app.js's window.loadSkinViewer() rather than
                        // a bare import() here, so Vite can code-split it as a
                        // proper build chunk with a hashed filename - this
                        // inline pushed script isn't part of the Vite module
                        // graph, so a relative import() here would have nothing
                        // correct to resolve against in production.
                        const { webglSupported, modelUrl, mount } = await window.loadSkinViewer();
                        if (!webglSupported()) throw new Error('no_webgl');
                        this.dispose3d = await mount(this.$refs.viewer3d, modelUrl(this.selectedWeapon.name));
                    } catch (e) {
                        this.error3d = true;
                    } finally {
                        this.loading3d = false;
                    }
                },

                async putSkin(slot, team, body) {
                    const res = await fetch(`/api/skins/${this.ownSteamId}/${slot}`, {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify({ ...body, team }),
                    });
                    if (!res.ok) throw new Error('request_failed');
                },

                // Every non-agent slot equips identically on both teams in
                // one action - most skins here are cosmetically the same
                // choice either side, and re-picking the same thing twice
                // through a team tab was the exact friction this replaced.
                async saveWeapon() {
                    this.saving = true;
                    this.error = false;
                    try {
                        for (const team of [2, 3]) {
                            // The API upserts the whole row - carry over sticker/keychain
                            // fields from each team's own existing row untouched so
                            // equipping a paint here never silently wipes stickers
                            // applied elsewhere, and one team's stickers never leak
                            // onto the other's row.
                            const existing = (this.profile?.skins ?? []).find(
                                (r) => r.weapon_team === team && r.weapon_defindex === this.selectedWeapon.index
                            ) ?? {};

                            await this.putSkin('weapon', team, {
                                defindex: this.selectedWeapon.index,
                                ...this.weaponForm,
                                weapon_sticker_0: existing.weapon_sticker_0,
                                weapon_sticker_1: existing.weapon_sticker_1,
                                weapon_sticker_2: existing.weapon_sticker_2,
                                weapon_sticker_3: existing.weapon_sticker_3,
                                weapon_sticker_4: existing.weapon_sticker_4,
                                weapon_sticker_5: existing.weapon_sticker_5,
                                weapon_keychain: existing.weapon_keychain,
                            });
                        }
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
                        for (const team of [2, 3]) {
                            const url = `/api/skins/${this.ownSteamId}/weapon?team=${team}&defindex=${this.selectedWeapon.index}`;
                            const res = await fetch(url, { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() } });
                            if (!res.ok) throw new Error('request_failed');
                        }
                        this.profile = await this.fetchJson(`/api/skins/${this.ownSteamId}`);
                        this.closeWeapon();
                    } catch (e) {
                        this.error = true;
                    }
                },

                async pick(item) {
                    this.error = false;
                    const body = {};
                    if (this.section === 'knife') body.knife = item.name;
                    if (this.section === 'gloves') body.weapon_defindex = item.index;
                    if (this.section === 'agent') body.agent_index = item.index;
                    if (this.section === 'music') body.music_id = item.index;

                    try {
                        // Agent is team-locked - only the currently toggled
                        // side. Everything else writes both teams so one
                        // click is the whole action.
                        const teams = this.section === 'agent' ? [this.team] : [2, 3];
                        for (const team of teams) {
                            await this.putSkin(this.section, team, body);
                        }
                        this.profile = await this.fetchJson(`/api/skins/${this.ownSteamId}`);
                    } catch (e) {
                        this.error = true;
                    }
                },
            });
        </script>
    @endpush
</x-layout.app>
