<x-layout.app :title="__('i18n::messages.nav.skins')">
    <div x-data="skinsPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.skins') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.skins.subtitle') }}</p>

        <div class="mt-5 flex flex-wrap gap-1 border-b border-line">
            <template x-for="s in sections" :key="s.key">
                <button
                    type="button"
                    @click="switchSection(s.key)"
                    :class="section === s.key ? 'border-brand-strong text-brand-strong' : 'border-transparent text-ink-muted hover:text-ink'"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                    x-text="s.label"
                ></button>
            </template>
        </div>

        {{-- Filter row. Whatever the tab's own filter is (weapon category,
             agent team, or nothing at all) sits left and the search box sits
             right, so search lands in the same place on every tab rather than
             sliding around with the number of filter chips. --}}
        <div x-show="!selected" x-cloak class="mt-4 flex flex-wrap items-center justify-between gap-3">
            {{-- Weapon category sub-filter - rifle/pistol/smg/heavy, matching
                 CS2's own buy-menu split (see
                 CatalogService::WEAPON_CATEGORIES). Knives are excluded from
                 the weapons catalog entirely, not just hidden here - they
                 have their own tab. --}}
            <div class="flex flex-wrap gap-1.5" x-show="section === 'weapons'">
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

            {{-- Agents are the one thing genuinely locked to a side (a T model
                 cannot be worn as CT), so this toggle only exists here - every
                 other tab applies its pick to both teams at once. --}}
            <div class="flex flex-wrap gap-1.5" x-show="section === 'agent'">
                <template x-for="t in [2, 3]" :key="t">
                    <button
                        type="button"
                        @click="team = t; loadSection()"
                        :class="team === t ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                        class="rounded-lg px-4 py-1.5 text-sm font-medium transition-colors"
                        x-text="t === 2 ? @js(__('i18n::messages.skins.team_t')) : @js(__('i18n::messages.skins.team_ct'))"
                    ></button>
                </template>
            </div>

            <label class="relative ml-auto w-full sm:w-64">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-faint">
                    <x-icon name="search" class="size-4" />
                </span>
                <input
                    type="search"
                    x-model="search"
                    placeholder="{{ __('i18n::messages.common.search') }}"
                    class="w-full rounded-lg border border-line bg-surface py-1.5 pl-9 pr-3 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none"
                >
            </label>
        </div>

        <p x-show="loading" x-cloak class="mt-6 text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
        <p x-show="error" x-cloak class="mt-6 text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>

        <div x-show="!loading" x-cloak class="mt-5">

            {{-- ============================= LIST VIEW ============================= --}}
            <template x-if="!selected">
                <div>
                    {{-- "What am I wearing right now" strip. These grids are
                         long enough that the equipped item scrolls out of
                         sight, which is why the equipped agent was invisible
                         in practice even though its card carried a highlight.
                         Weapons have no single equipped item, so no strip. --}}
                    <template x-if="section !== 'weapons' && equippedItem()">
                        <div class="mb-4 flex items-center gap-3 rounded-xl border border-line bg-surface p-3">
                            <div
                                class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-canvas"
                                :style="glow(rarityOf(equippedItem()))"
                            >
                                <img
                                    :src="cardImageUrl(equippedItem())"
                                    alt=""
                                    class="max-h-full max-w-full object-contain p-1"
                                    @@error="$el.style.visibility = 'hidden'"
                                >
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs uppercase tracking-wide text-ink-faint">{{ __('i18n::messages.skins.currently_equipped') }}</p>
                                <p class="truncate font-medium text-ink" x-text="equippedItem().label"></p>
                                <p
                                    class="truncate text-xs text-ink-muted"
                                    x-show="equippedPaintLabel(equippedItem())"
                                    x-text="equippedPaintLabel(equippedItem())"
                                ></p>
                            </div>
                        </div>
                    </template>

                    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                        <template x-for="item in visibleItems()" :key="item.name">
                            <button
                                type="button"
                                @click="cardClick(item)"
                                class="group overflow-hidden rounded-xl border text-left text-sm transition-colors"
                                :class="isEquipped(item) ? 'border-brand-strong bg-brand-soft' : 'border-line bg-surface hover:bg-surface-raised'"
                            >
                                {{-- Art well. The radial tint behind it is the
                                     item's rarity colour - the same signal the
                                     in-game inventory uses, and the fastest way
                                     to read a grid of forty skins. Not every
                                     weapon+paint pair has artwork, so a failed
                                     load hides the <img> and leaves the tinted
                                     well rather than a broken-image icon. --}}
                                <div
                                    class="relative flex h-24 items-center justify-center bg-canvas"
                                    :style="glow(rarityOf(item))"
                                >
                                    <img
                                        :src="cardImageUrl(item)"
                                        alt=""
                                        loading="lazy"
                                        class="max-h-full max-w-full object-contain p-2 transition-transform duration-200 group-hover:scale-105"
                                        @@error="$el.style.visibility = 'hidden'"
                                    >
                                </div>
                                <div class="border-t-2 p-3" :style="accent(rarityOf(item))" :class="rarityOf(item) ? '' : 'border-transparent'">
                                    <span class="block truncate font-medium" :class="isEquipped(item) ? 'text-brand-strong' : 'text-ink'" x-text="item.label"></span>
                                    <span
                                        class="mt-0.5 block truncate text-xs"
                                        :class="subtitleFor(item).equipped ? 'text-brand-strong' : 'text-ink-faint'"
                                        x-text="subtitleFor(item).text"
                                    ></span>
                                </div>
                            </button>
                        </template>
                    </div>

                    <p
                        x-show="visibleItems().length === 0"
                        class="mt-3 text-sm text-ink-faint"
                        x-text="search ? @js(__('i18n::messages.common.no_results')) : @js(__('i18n::messages.skins.no_data'))"
                    ></p>
                    <p class="mt-3 text-xs text-ink-faint" x-show="section !== 'agent'">{{ __('i18n::messages.skins.both_teams_hint') }}</p>
                </div>
            </template>

            {{-- ============================ DETAIL VIEW ============================
                 Weapons, knives and gloves all land here: each is an item with
                 a defindex whose finish is a paintkit row in wp_player_skins,
                 so one screen and one form covers all three. What differs is
                 only which extra table the save touches (wp_player_knife /
                 wp_player_gloves) and which fields the type supports - gloves
                 take no StatTrak, nametag, stickers or keychain; knives take
                 no stickers or keychain.

                 Two columns: the item and everything about *this particular
                 copy* of it on the left, the finish catalogue (much the
                 longest list) scrolling on the right. --}}
            <template x-if="selected">
                <div>
                    <button type="button" @click="closeDetail()" class="text-sm text-ink-muted hover:text-ink">
                        &larr; {{ __('i18n::messages.skins.back') }}
                    </button>

                    <div class="mt-3 grid gap-5 lg:grid-cols-[22rem_minmax(0,1fr)] xl:grid-cols-[26rem_minmax(0,1fr)]">

                        {{-- ------------------ left: the item itself ------------------ --}}
                        <div class="space-y-4 lg:sticky lg:top-6 lg:self-start">
                            <div class="overflow-hidden rounded-xl border border-line bg-surface">
                                <div class="flex h-56 items-center justify-center bg-canvas" :style="glow(detailRarity())">
                                    <img
                                        :src="detailImageUrl()"
                                        alt=""
                                        class="max-h-full max-w-full object-contain p-4"
                                        @@error="$el.style.visibility = 'hidden'"
                                        @load="$el.style.visibility = ''"
                                    >
                                </div>
                                <div class="border-t-2 p-3" :style="accent(detailRarity())" :class="detailRarity() ? '' : 'border-transparent'">
                                    <p class="truncate font-semibold text-ink" x-text="selected.label"></p>
                                    <p
                                        class="mt-0.5 truncate text-xs"
                                        :class="detailRarity() ? '' : 'text-ink-faint'"
                                        :style="detailRarity() ? 'color:' + detailRarity() : ''"
                                        x-text="currentPaintLabel()"
                                    ></p>
                                </div>
                            </div>

                            <div class="rounded-xl border border-line bg-surface p-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-ink-muted">{{ __('i18n::messages.skins.wear') }}</label>
                                        <input type="range" min="0" max="1" step="0.001" x-model.number="form.weapon_wear" class="mt-2 w-full">
                                        <p class="mt-1 text-xs text-ink-faint" x-text="form.weapon_wear.toFixed(3)"></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-ink-muted">{{ __('i18n::messages.skins.seed') }}</label>
                                        <input type="number" min="0" max="99999" x-model.number="form.weapon_seed" class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                                    </div>
                                </div>

                                <div class="mt-4 flex items-center justify-between gap-3" x-show="supports('stattrak')">
                                    <span class="text-sm font-medium text-ink-muted">{{ __('i18n::messages.skins.stattrak') }}</span>
                                    <x-toggle state="form.weapon_stattrak" @click="form.weapon_stattrak = !form.weapon_stattrak" />
                                </div>

                                <template x-if="supports('nametag')">
                                    <div class="mt-4">
                                        <label class="block text-sm font-medium text-ink-muted">{{ __('i18n::messages.skins.nametag') }}</label>
                                        <input type="text" x-model="form.weapon_nametag" maxlength="128" placeholder="{{ __('i18n::messages.skins.nametag_placeholder') }}" class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                                    </div>
                                </template>

                                <template x-if="supports('stickers')">
                                    <div class="mt-4">
                                        <label class="block text-sm font-medium text-ink-muted">{{ __('i18n::messages.skins.stickers') }}</label>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <template x-for="i in [0, 1, 2, 3, 4, 5]" :key="i">
                                                <button
                                                    type="button"
                                                    @click="openStickerPicker(i)"
                                                    :title="stickerAt(i).id ? stickerLabel(stickerAt(i).id) : ''"
                                                    class="relative flex size-14 flex-col items-center justify-center overflow-hidden rounded-lg border p-1 text-center text-[10px] leading-tight transition-colors"
                                                    :class="stickerAt(i).id ? 'border-brand-strong bg-brand-soft text-brand-strong' : 'border-line bg-canvas text-ink-faint hover:bg-surface-raised'"
                                                >
                                                    <img
                                                        x-show="stickerImage(stickerAt(i).id)"
                                                        :src="stickerImage(stickerAt(i).id)"
                                                        alt=""
                                                        loading="lazy"
                                                        class="max-h-full max-w-full object-contain"
                                                        @@error="$el.style.visibility = 'hidden'"
                                                    >
                                                    <span x-show="stickerAt(i).id && !stickerImage(stickerAt(i).id)" class="line-clamp-3" x-text="stickerLabel(stickerAt(i).id)"></span>
                                                    <span x-show="!stickerAt(i).id" class="text-lg">+</span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="supports('keychain')">
                                    <div class="mt-4">
                                        <label class="block text-sm font-medium text-ink-muted">{{ __('i18n::messages.skins.keychain') }}</label>
                                        <button
                                            type="button"
                                            @click="openKeychainPicker()"
                                            class="mt-2 flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition-colors"
                                            :class="keychainCurrentId ? 'border-brand-strong bg-brand-soft text-brand-strong' : 'border-line bg-canvas text-ink-muted hover:bg-surface-raised'"
                                        >
                                            <img
                                                x-show="keychainImage(keychainCurrentId)"
                                                :src="keychainImage(keychainCurrentId)"
                                                alt=""
                                                loading="lazy"
                                                class="size-6 shrink-0 object-contain"
                                                @@error="$el.style.visibility = 'hidden'"
                                            >
                                            <span class="truncate" x-text="keychainCurrentId ? keychainLabel(keychainCurrentId) : @js(__('i18n::messages.skins.keychain_none'))"></span>
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button" :disabled="saving" @click="saveDetail()" class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50">
                                    {{ __('i18n::messages.skins.equip') }}
                                </button>
                                <button type="button" x-show="isEquipped(selected)" @click="removeDetail()" class="rounded-lg border border-line px-4 py-2 text-sm text-red-400 transition-colors hover:bg-red-500/10">
                                    {{ __('i18n::messages.skins.remove') }}
                                </button>
                            </div>
                            <p class="text-xs text-ink-faint">{{ __('i18n::messages.skins.both_teams_hint') }}</p>
                        </div>

                        {{-- ------------------ right: the finish catalogue ------------------ --}}
                        <div class="rounded-xl border border-line bg-surface p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <label class="text-sm font-medium text-ink-muted">{{ __('i18n::messages.skins.choose_paint') }}</label>
                                <label class="relative ml-auto w-full sm:w-56">
                                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-faint">
                                        <x-icon name="search" class="size-4" />
                                    </span>
                                    <input
                                        type="search"
                                        x-model="paintSearch"
                                        placeholder="{{ __('i18n::messages.common.search') }}"
                                        class="w-full rounded-lg border border-line bg-canvas py-1.5 pl-9 pr-3 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none"
                                    >
                                </label>
                            </div>

                            <div class="mt-3 grid max-h-[68vh] grid-cols-2 gap-2 overflow-y-auto rounded-lg bg-canvas p-2 sm:grid-cols-3 xl:grid-cols-4">
                                {{-- Gloves are the one item with no unpainted
                                     state to offer: the game has no bare-glove
                                     model and no artwork for one, so a "no
                                     paint" tile here would equip nothing
                                     visible. --}}
                                <button
                                    type="button"
                                    x-show="supports('bare')"
                                    @click="pickPaint(0)"
                                    class="overflow-hidden rounded-lg border text-center text-xs transition-colors"
                                    :class="form.weapon_paint_id === 0 ? 'border-brand-strong bg-brand-soft text-brand-strong' : 'border-line bg-surface text-ink-muted hover:bg-surface-raised'"
                                >
                                    <div class="flex h-16 items-center justify-center bg-canvas">
                                        <img
                                            :src="skinImageUrl(imageName(selected.name), 0)"
                                            alt=""
                                            loading="lazy"
                                            class="max-h-full max-w-full object-contain p-1"
                                            @@error="$el.style.visibility = 'hidden'"
                                        >
                                    </div>
                                    <div class="p-1.5">{{ __('i18n::messages.skins.default_paint') }}</div>
                                </button>

                                <template x-for="p in visiblePaints()" :key="p.index">
                                    <button
                                        type="button"
                                        @click="pickPaint(p.index)"
                                        class="overflow-hidden rounded-lg border text-left text-xs transition-colors"
                                        :class="form.weapon_paint_id === p.index ? 'border-brand-strong bg-brand-soft' : 'border-line bg-surface hover:bg-surface-raised'"
                                    >
                                        <div class="flex h-16 items-center justify-center bg-canvas" :style="glow(p.rarity_color)">
                                            <img
                                                :src="p.image || skinImageUrl(imageName(selected.name), p.index)"
                                                alt=""
                                                loading="lazy"
                                                class="max-h-full max-w-full object-contain p-1"
                                                @@error="$el.style.visibility = 'hidden'"
                                            >
                                        </div>
                                        <div class="border-t-2 p-1.5" :style="accent(p.rarity_color)" :class="p.rarity_color ? '' : 'border-transparent'">
                                            <span class="block truncate" :class="form.weapon_paint_id === p.index ? 'text-brand-strong' : 'text-ink-muted'" x-text="p.label"></span>
                                        </div>
                                    </button>
                                </template>
                            </div>

                            <p x-show="paints.length === 0" class="mt-3 text-sm text-ink-faint">{{ __('i18n::messages.skins.no_paints') }}</p>
                            <p x-show="paints.length > 0 && visiblePaints().length === 0" class="mt-3 text-sm text-ink-faint">{{ __('i18n::messages.common.no_results') }}</p>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Sticker/keychain picker - shared by all 6 sticker slots and
                 the keychain slot above. Search only applies to stickers
                 (~8,800 of them); keychains (143) just list in full. --}}
            <div
                x-show="picker.open"
                x-cloak
                @click.self="closePicker()"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            >
                <div class="flex max-h-[80vh] w-full max-w-lg flex-col rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-ink" x-text="picker.kind === 'sticker' ? @js(__('i18n::messages.skins.stickers')) : @js(__('i18n::messages.skins.keychain'))"></h3>
                        <button type="button" @click="closePicker()" class="text-ink-faint hover:text-ink">&times;</button>
                    </div>
                    <input
                        type="search"
                        x-show="picker.kind === 'sticker'"
                        x-model="picker.search"
                        placeholder="{{ __('i18n::messages.common.search') }}"
                        class="mt-3 w-full shrink-0 rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none"
                    >
                    <div class="mt-3 grid grid-cols-3 gap-2 overflow-y-auto sm:grid-cols-4">
                        <button
                            type="button"
                            @click="pickerSelect(0)"
                            class="rounded-lg border border-line bg-canvas p-2 text-center text-xs text-ink-muted transition-colors hover:bg-surface-raised"
                        >{{ __('i18n::messages.skins.clear_slot') }}</button>
                        <template x-for="opt in pickerResults()" :key="opt.index">
                            <button
                                type="button"
                                @click="pickerSelect(opt.index)"
                                class="flex items-center gap-2 rounded-lg border border-line bg-canvas p-2 text-left text-xs text-ink-muted transition-colors hover:bg-surface-raised"
                            >
                                <span x-show="opt.image" class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-surface">
                                    <img :src="opt.image" alt="" loading="lazy" class="max-h-full max-w-full object-contain" @@error="$el.parentElement.style.visibility = 'hidden'">
                                </span>
                                <span x-show="!opt.image" class="size-1.5 shrink-0 rounded-full" :style="opt.rarity_color ? 'background:' + opt.rarity_color : ''"></span>
                                <span class="truncate" x-text="opt.label"></span>
                            </button>
                        </template>
                    </div>
                    <p x-show="picker.kind === 'sticker' && stickerCatalog.length === 0" class="mt-3 text-center text-xs text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
                </div>
            </div>

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
                knives: [],
                gloves: [],
                agents: { 2: [], 3: [] },
                music: [],
                paints: [],
                paintNames: {},
                stickerCatalog: [],
                keychainCatalog: [],

                weaponCategory: 'all',
                search: '',
                paintSearch: '',

                // Weapons, knives and gloves share one detail screen and one
                // form (all three are "an item with a defindex that carries a
                // paintkit"); selectedKind remembers which tab opened it,
                // because that decides both the visible fields and which extra
                // table the save writes alongside wp_player_skins.
                selected: null,
                selectedKind: null,
                form: {},

                picker: { open: false, kind: null, slotIndex: null, search: '' },

                EMPTY_STICKER: '0;0;0;0;0;0;0',
                EMPTY_KEYCHAIN: '0;0;0;0;0',

                // The stock knife is the only entry in the knives catalog with
                // no artwork of its own upstream. It is the same model as the
                // CT-side stock knife, which does have one, so it borrows it -
                // otherwise the first card on the tab is permanently blank.
                KNIFE_IMAGE_ALIASES: { weapon_knife_t: 'weapon_knife' },

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

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
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
                        // every weapon combined) so a list card can label and
                        // tint its equipped paint without first opening that
                        // item's own paint picker.
                        this.paintNames = await this.fetchJson('/api/skins/catalog/paint-names').catch(() => ({}));
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                switchSection(key) {
                    this.section = key;
                    this.selected = null;
                    this.selectedKind = null;
                    this.search = '';
                    this.loadSection();
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

                // ------------------------------------------------------ artwork

                // Weapon defindex/paint IDs are Valve's own numbering (the
                // catalog comes straight from the game's item schema - see
                // CatalogService), not anything the skin plugin invented, so
                // preview art keyed the same way from a public asset set lines
                // up with our data too. Not every combination has an image;
                // the <img> tags this feeds hide themselves on a failed load
                // rather than showing a broken-image icon.
                skinImageUrl(weaponName, paintId) {
                    if (!weaponName) return '';
                    const suffix = paintId ? `-${paintId}` : '';
                    return `https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/${weaponName}${suffix}.png`;
                },

                // The same flat-image set also publishes one icon per music kit
                // id - no paint/suffix concept here, just the kit's own id.
                musicImageUrl(id) {
                    if (!id) return '';
                    return `https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/music_kit-${id}.png`;
                },

                imageName(name) {
                    return this.KNIFE_IMAGE_ALIASES[name] ?? name;
                },

                // What a list card should draw. Weapons/knives show whatever
                // finish is actually equipped (falling back to the unpainted
                // model); gloves have no unpainted artwork at all, so they fall
                // back to the family's first finish purely so the card is not
                // blank (see CatalogService::gloves()).
                cardImageUrl(item) {
                    if (this.section === 'agent') return item.image || '';
                    if (this.section === 'music') return this.musicImageUrl(item.index);

                    const equipped = this.equippedPaintId(item.index);

                    if (this.section === 'gloves') {
                        return this.gloveImageUrl(item, equipped || item.preview_paint || 0);
                    }

                    return this.skinImageUrl(this.imageName(item.name), equipped);
                },

                // Gloves carry a real per-paint image from CatalogService
                // (see gloveImages() there) rather than a guessed
                // {name}-{id}.png URL - that pattern has genuine gaps for
                // every finish older than late 2019 (Wave Chaser, Lime
                // Polycam, ...), which is why those rendered as an empty
                // glow well with no image at all before this. The pattern
                // guess only remains as a fallback for a finish the game
                // added after this snapshot was taken.
                gloveImageUrl(item, paintId) {
                    return item.images?.[paintId] || this.skinImageUrl(item.name, paintId);
                },

                detailImageUrl() {
                    if (!this.selected) return '';
                    const paint = this.form.weapon_paint_id;

                    if (this.selectedKind === 'gloves') {
                        return this.gloveImageUrl(this.selected, paint || this.selected.preview_paint || 0);
                    }

                    return this.skinImageUrl(this.imageName(this.selected.name), paint);
                },

                // Rarity tint. CS2 grades every finish by colour and players
                // read a grid by that colour long before they read the names,
                // so art wells carry it as a soft radial wash and card bodies
                // as a hairline. Alpha is appended to the schema's own 6-digit
                // hex, which is why this refuses anything else.
                glow(color) {
                    const c = this.normalizeColor(color);
                    if (!c) return '';
                    return `background-image:radial-gradient(ellipse 76% 64% at 50% 50%, ${c}45, ${c}12 48%, transparent 72%)`;
                },

                accent(color) {
                    const c = this.normalizeColor(color);
                    return c ? `border-top-color:${c}` : '';
                },

                normalizeColor(color) {
                    if (!color) return '';
                    const c = String(color).startsWith('#') ? String(color) : `#${color}`;
                    return /^#[0-9a-f]{6}$/i.test(c) ? c : '';
                },

                rarityOf(item) {
                    if (!item) return null;
                    if (this.section === 'agent' || this.section === 'music') return item.rarity_color ?? null;

                    const equipped = this.paintNames[this.equippedPaintId(item.index)]?.rarity_color;

                    // A weapon with nothing equipped is stock, which has no
                    // grade; a knife or a pair of gloves is a graded item in
                    // its own right, so it keeps that grade as a floor.
                    return equipped ?? (this.section === 'weapons' ? null : (item.rarity_color ?? null));
                },

                detailRarity() {
                    const paint = this.form.weapon_paint_id;
                    return this.paintNames[paint]?.rarity_color
                        ?? this.paints.find((p) => p.index === paint)?.rarity_color
                        ?? (this.selectedKind === 'weapons' ? null : (this.selected?.rarity_color ?? null));
                },

                // ------------------------------------------------- profile reads

                // Knife/gloves/music apply to both teams at once, so team 2 is
                // read as the canonical "is this equipped" state - an equip
                // always writes both, so the two only ever disagree on data set
                // before that behaviour existed. Agent is genuinely per-team.
                rowsFor(slot) {
                    const key = { knife: 'knife', gloves: 'gloves', agent: 'agents', music: 'music' }[slot];
                    const rows = this.profile?.[key] ?? [];
                    const team = slot === 'agent' ? this.team : 2;
                    return rows.filter((r) => r.weapon_team === team);
                },

                weaponRow(defindex) {
                    return (this.profile?.skins ?? []).find((r) => r.weapon_team === 2 && r.weapon_defindex === defindex) ?? null;
                },

                equippedPaintId(defindex) {
                    return this.weaponRow(defindex)?.weapon_paint_id ?? 0;
                },

                paintLabel(id) {
                    if (!id) return '';
                    return this.paintNames[id]?.label ?? `#${id}`;
                },

                isEquipped(item) {
                    if (!item) return false;
                    const kind = this.selected ? this.selectedKind : this.section;
                    if (kind === 'weapons') return !!this.weaponRow(item.index);
                    if (kind === 'knife') return this.rowsFor('knife').some((r) => r.knife === item.name);
                    if (kind === 'gloves') return this.rowsFor('gloves').some((r) => r.weapon_defindex === item.index);
                    if (kind === 'agent') return this.rowsFor('agent').some((r) => r.agent_index === item.index);
                    if (kind === 'music') return this.rowsFor('music').some((r) => r.music_id === item.index);
                    return false;
                },

                // The one item currently worn on this tab, for the summary
                // strip above the grid. Weapons have no single answer, so they
                // get none.
                equippedItem() {
                    const list = this.currentCatalog();
                    if (this.section === 'knife') {
                        const name = this.rowsFor('knife')[0]?.knife;
                        return list.find((i) => i.name === name) ?? null;
                    }
                    if (this.section === 'gloves') {
                        const idx = this.rowsFor('gloves')[0]?.weapon_defindex;
                        return list.find((i) => i.index === idx) ?? null;
                    }
                    if (this.section === 'agent') {
                        const idx = this.rowsFor('agent')[0]?.agent_index;
                        return list.find((i) => i.index === idx) ?? null;
                    }
                    if (this.section === 'music') {
                        const idx = this.rowsFor('music')[0]?.music_id;
                        return list.find((i) => i.index === idx) ?? null;
                    }
                    return null;
                },

                equippedPaintLabel(item) {
                    if (!item || this.section === 'agent' || this.section === 'music') return '';
                    return this.paintLabel(this.equippedPaintId(item.index));
                },

                currentPaintLabel() {
                    const id = this.form.weapon_paint_id;
                    if (!id) return @js(__('i18n::messages.skins.default_paint'));
                    return this.paints.find((p) => p.index === id)?.label ?? this.paintLabel(id);
                },

                subtitleFor(item) {
                    if (this.section === 'agent' || this.section === 'music') {
                        return this.isEquipped(item)
                            ? { text: @js(__('i18n::messages.skins.equipped')), equipped: true }
                            : { text: '', equipped: false };
                    }

                    const paint = this.equippedPaintId(item.index);
                    if (paint) return { text: this.paintLabel(paint), equipped: true };

                    return this.isEquipped(item)
                        ? { text: @js(__('i18n::messages.skins.equipped')), equipped: true }
                        : { text: @js(__('i18n::messages.skins.no_data')), equipped: false };
                },

                // -------------------------------------------------- list + search

                currentCatalog() {
                    if (this.section === 'weapons') return this.weapons;
                    if (this.section === 'knife') return this.knives;
                    if (this.section === 'gloves') return this.gloves;
                    if (this.section === 'agent') return this.agents[this.team];
                    if (this.section === 'music') return this.music;
                    return [];
                },

                matches(item) {
                    const q = this.search.trim().toLowerCase();
                    if (!q) return true;
                    return `${item.label ?? ''} ${item.name ?? ''}`.toLowerCase().includes(q);
                },

                visibleItems() {
                    return this.currentCatalog().filter((item) => {
                        if (this.section === 'weapons' && this.weaponCategory !== 'all' && item.category !== this.weaponCategory) {
                            return false;
                        }
                        return this.matches(item);
                    });
                },

                visiblePaints() {
                    const q = this.paintSearch.trim().toLowerCase();
                    if (!q) return this.paints;
                    return this.paints.filter((p) => (p.label ?? '').toLowerCase().includes(q));
                },

                // -------------------------------------------------- detail screen

                // Agents and music kits are a single choice with nothing to
                // configure, so they equip straight from the card. Everything
                // else opens the detail screen.
                cardClick(item) {
                    if (this.section === 'agent' || this.section === 'music') return this.pick(item);
                    return this.openDetail(item);
                },

                supports(feature) {
                    const kind = this.selectedKind;
                    if (feature === 'stickers' || feature === 'keychain') return kind === 'weapons';
                    if (feature === 'stattrak' || feature === 'nametag') return kind !== 'gloves';
                    // Gloves have no unpainted state to fall back to.
                    if (feature === 'bare') return kind !== 'gloves';
                    return false;
                },

                async openDetail(item) {
                    this.selectedKind = this.section;
                    this.selected = item;
                    this.paintSearch = '';
                    this.paints = await this.fetchJson(`/api/skins/catalog/weapons/${encodeURIComponent(item.name)}/paints`).catch(() => []);

                    const row = this.weaponRow(item.index);
                    const empty = this.EMPTY_STICKER;
                    this.form = row
                        ? {
                            weapon_paint_id: row.weapon_paint_id ?? 0,
                            weapon_wear: row.weapon_wear ?? 0.01,
                            weapon_seed: row.weapon_seed ?? 0,
                            weapon_stattrak: !!row.weapon_stattrak,
                            weapon_nametag: row.weapon_nametag ?? '',
                            weapon_sticker_0: row.weapon_sticker_0 || empty,
                            weapon_sticker_1: row.weapon_sticker_1 || empty,
                            weapon_sticker_2: row.weapon_sticker_2 || empty,
                            weapon_sticker_3: row.weapon_sticker_3 || empty,
                            weapon_sticker_4: row.weapon_sticker_4 || empty,
                            weapon_sticker_5: row.weapon_sticker_5 || empty,
                            weapon_keychain: row.weapon_keychain || this.EMPTY_KEYCHAIN,
                        }
                        : {
                            // Gloves open on a real finish rather than "none",
                            // which for gloves is not a wearable state.
                            weapon_paint_id: this.section === 'gloves' ? (item.preview_paint ?? 0) : 0,
                            weapon_wear: 0.01,
                            weapon_seed: 0,
                            weapon_stattrak: false,
                            weapon_nametag: '',
                            weapon_sticker_0: empty, weapon_sticker_1: empty, weapon_sticker_2: empty,
                            weapon_sticker_3: empty, weapon_sticker_4: empty, weapon_sticker_5: empty,
                            weapon_keychain: this.EMPTY_KEYCHAIN,
                        };

                    // Fire-and-forget: if this item already carries a
                    // sticker/keychain, load the catalogs in the background so
                    // the slot labels show a real name rather than a bare id,
                    // without making the page wait on an 8,800-item fetch.
                    if ([0, 1, 2, 3, 4, 5].some((i) => this.stickerAt(i).id)) this.ensureStickerCatalog();
                    if (this.keychainCurrentId) this.ensureKeychainCatalog();
                },

                closeDetail() {
                    this.selected = null;
                    this.selectedKind = null;
                    this.paints = [];
                    this.paintSearch = '';
                },

                pickPaint(id) {
                    this.form.weapon_paint_id = id;
                },

                // --------------------------------------------- sticker/keychain

                // Sticker/keychain slots are stored as semicolon-delimited
                // strings (id;schema;x;y;wear;scale;rotation for a sticker,
                // id;x;y;z;seed for the keychain) - exactly what the skin
                // plugin itself reads, so nothing here needs its own column.
                // Picking one writes a sensible default placement (centered,
                // full scale, no rotation) rather than exposing manual x/y/
                // rotation controls - repositioning by hand is a real feature
                // but a separate one from "can a player put a sticker on their
                // gun at all", which is the gap this closes.
                stickerAt(slot) {
                    const raw = this.form[`weapon_sticker_${slot}`] || this.EMPTY_STICKER;
                    return { id: Number(raw.split(';')[0]) || 0 };
                },

                get keychainCurrentId() {
                    return Number((this.form.weapon_keychain || this.EMPTY_KEYCHAIN).split(';')[0]) || 0;
                },

                stickerLabel(id) {
                    return this.stickerCatalog.find((s) => s.index === id)?.label ?? `#${id}`;
                },

                keychainLabel(id) {
                    return this.keychainCatalog.find((k) => k.index === id)?.label ?? `#${id}`;
                },

                // Real artwork when the catalog has it (see
                // CatalogService::stickerImages()/keychainImages()) - '' once
                // the slot is empty or before the (lazily-loaded) catalog has
                // arrived, which the caller already falls back to text for.
                stickerImage(id) {
                    if (!id) return '';
                    return this.stickerCatalog.find((s) => s.index === id)?.image ?? '';
                },

                keychainImage(id) {
                    if (!id) return '';
                    return this.keychainCatalog.find((k) => k.index === id)?.image ?? '';
                },

                async ensureStickerCatalog() {
                    if (this.stickerCatalog.length) return;
                    this.stickerCatalog = await this.fetchJson('/api/skins/catalog/stickers').catch(() => []);
                },

                async ensureKeychainCatalog() {
                    if (this.keychainCatalog.length) return;
                    this.keychainCatalog = await this.fetchJson('/api/skins/catalog/keychains').catch(() => []);
                },

                async openStickerPicker(slot) {
                    this.picker = { open: true, kind: 'sticker', slotIndex: slot, search: '' };
                    await this.ensureStickerCatalog();
                },

                async openKeychainPicker() {
                    this.picker = { open: true, kind: 'keychain', slotIndex: null, search: '' };
                    await this.ensureKeychainCatalog();
                },

                closePicker() {
                    this.picker.open = false;
                },

                // Capped rather than rendering all ~8,800 stickers at once -
                // search narrows it down; keychains (143 total) just show in
                // full since that is small enough to browse directly.
                pickerResults() {
                    if (this.picker.kind === 'keychain') return this.keychainCatalog;
                    const q = this.picker.search.trim().toLowerCase();
                    const source = q ? this.stickerCatalog.filter((s) => s.label.toLowerCase().includes(q)) : this.stickerCatalog;
                    return source.slice(0, 60);
                },

                pickerSelect(id) {
                    if (this.picker.kind === 'sticker') {
                        this.form[`weapon_sticker_${this.picker.slotIndex}`] = id ? `${id};0;0;0;0;1;0` : this.EMPTY_STICKER;
                    } else {
                        this.form.weapon_keychain = id ? `${id};0;0;0;0` : this.EMPTY_KEYCHAIN;
                    }
                    this.closePicker();
                },

                // ----------------------------------------------------- writes

                async putSkin(slot, team, body) {
                    const res = await fetch(`/api/skins/${this.ownSteamId}/${slot}`, {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify({ ...body, team }),
                    });
                    if (!res.ok) throw new Error('request_failed');
                },

                async deleteSkin(slot, query) {
                    const res = await fetch(`/api/skins/${this.ownSteamId}/${slot}?${query}`, {
                        method: 'DELETE',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                    if (!res.ok) throw new Error('request_failed');
                },

                // Only the columns this item type actually supports, so a pair
                // of gloves never writes a nametag or sticker string it has no
                // way to display.
                weaponPayload() {
                    const payload = {
                        defindex: this.selected.index,
                        weapon_paint_id: this.form.weapon_paint_id,
                        weapon_wear: this.form.weapon_wear,
                        weapon_seed: this.form.weapon_seed,
                    };

                    if (this.supports('stattrak')) payload.weapon_stattrak = this.form.weapon_stattrak;
                    if (this.supports('nametag')) payload.weapon_nametag = this.form.weapon_nametag;

                    if (this.supports('stickers')) {
                        for (const i of [0, 1, 2, 3, 4, 5]) {
                            payload[`weapon_sticker_${i}`] = this.form[`weapon_sticker_${i}`];
                        }
                    }

                    if (this.supports('keychain')) payload.weapon_keychain = this.form.weapon_keychain;

                    return payload;
                },

                // Every non-agent slot equips identically on both teams in one
                // action - most skins here are cosmetically the same choice
                // either side, and re-picking the same thing twice through a
                // team tab was the exact friction this replaced.
                //
                // A knife or a pair of gloves is two writes, not one: which
                // model you wear lives in wp_player_knife / wp_player_gloves,
                // while the finish on it is an ordinary wp_player_skins row
                // keyed by that same defindex - the plugin reads both.
                async saveDetail() {
                    this.saving = true;
                    this.error = false;
                    try {
                        for (const team of [2, 3]) {
                            if (this.selectedKind === 'knife') {
                                await this.putSkin('knife', team, { knife: this.selected.name });
                            }
                            if (this.selectedKind === 'gloves') {
                                await this.putSkin('gloves', team, { weapon_defindex: this.selected.index });
                            }
                            await this.putSkin('weapon', team, this.weaponPayload());
                        }
                        this.profile = await this.fetchJson(`/api/skins/${this.ownSteamId}`);
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.saving = false;
                    }
                },

                async removeDetail() {
                    this.error = false;
                    try {
                        for (const team of [2, 3]) {
                            if (this.selectedKind === 'knife') await this.deleteSkin('knife', `team=${team}`);
                            if (this.selectedKind === 'gloves') await this.deleteSkin('gloves', `team=${team}`);
                            await this.deleteSkin('weapon', `team=${team}&defindex=${this.selected.index}`);
                        }
                        this.profile = await this.fetchJson(`/api/skins/${this.ownSteamId}`);
                        this.closeDetail();
                    } catch (e) {
                        this.error = true;
                    }
                },

                // Agents and music kits: one click, nothing to configure.
                async pick(item) {
                    this.error = false;
                    const body = {};
                    if (this.section === 'agent') body.agent_index = item.index;
                    if (this.section === 'music') body.music_id = item.index;

                    try {
                        // Agent is team-locked - only the currently toggled
                        // side. Music writes both teams so one click is the
                        // whole action.
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
