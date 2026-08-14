<x-layout.app :title="__('i18n::messages.nav.skins')">
    <div
        x-data="{
            steamid: '',
            loading: false,
            error: false,
            profile: null,

            teamLabel(team) {
                return team === 2 ? @js(__('i18n::messages.skins.team_t')) : @js(__('i18n::messages.skins.team_ct'));
            },

            rowFields(row) {
                return Object.entries(row).filter(([key]) => !['steamid', 'weapon_team'].includes(key));
            },

            async lookup() {
                if (!this.steamid.trim()) return;
                this.loading = true;
                this.error = false;
                this.profile = null;
                try {
                    const res = await fetch(`/api/skins/${encodeURIComponent(this.steamid.trim())}`, { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.profile = body.data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },
        }"
    >
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.skins') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.skins.search_hint') }}</p>

        <div class="mt-4 flex max-w-md gap-2">
            <input
                type="text"
                x-model="steamid"
                @keydown.enter="lookup()"
                placeholder="SteamID"
                class="flex-1 rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none"
            >
            <button
                type="button"
                :disabled="loading"
                @click="lookup()"
                class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
            >
                {{ __('i18n::messages.skins.lookup') }}
            </button>
        </div>

        <p x-show="loading" x-cloak class="mt-6 text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
        <p x-show="error" x-cloak class="mt-6 text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>

        <div x-show="profile" x-cloak class="mt-6 space-y-6">
            <template x-for="[sectionKey, sectionLabel] in [
                ['skins', @js(__('i18n::messages.skins.weapons'))],
                ['knife', @js(__('i18n::messages.skins.knife'))],
                ['gloves', @js(__('i18n::messages.skins.gloves'))],
                ['agents', @js(__('i18n::messages.skins.agent'))],
                ['music', @js(__('i18n::messages.skins.music'))],
            ]" :key="sectionKey">
                <div class="rounded-xl border border-line bg-surface p-5">
                    <h2 class="text-sm font-semibold text-ink" x-text="sectionLabel"></h2>

                    <div class="mt-3 space-y-2">
                        <template x-for="(row, i) in (profile?.[sectionKey] ?? [])" :key="i">
                            <div class="rounded-lg bg-surface-raised p-3 text-xs">
                                <span class="mb-1.5 inline-flex items-center rounded-full bg-brand-soft px-2 py-0.5 font-medium text-brand-strong" x-text="teamLabel(row.weapon_team)"></span>
                                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-ink-muted">
                                    <template x-for="[key, value] in rowFields(row)" :key="key">
                                        <span><span class="text-ink-faint" x-text="key + ':'"></span> <span x-text="value"></span></span>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <p x-show="(profile?.[sectionKey] ?? []).length === 0" class="text-xs text-ink-faint">
                            {{ __('i18n::messages.skins.no_data') }}
                        </p>
                    </div>
                </div>
            </template>
        </div>
    </div>
</x-layout.app>
