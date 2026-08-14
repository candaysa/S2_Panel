<x-layout.app :title="__('i18n::messages.nav.ranks')">
    <div
        x-data="{
            loading: true,
            error: false,
            search: '',
            players: [],
            editing: null,
            editValue: '',
            saving: false,

            async load() {
                this.loading = true;
                this.error = false;
                try {
                    const url = new URL('/api/ranks', window.location.origin);
                    if (this.search) url.searchParams.set('search', this.search);
                    const res = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.players = body.data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },

            startEdit(player) {
                this.editing = player.steam;
                this.editValue = player.value;
            },

            csrf() {
                return document.querySelector('meta[name=csrf-token]').content;
            },

            async savePoints(player) {
                this.saving = true;
                try {
                    const res = await fetch(`/api/ranks/${player.steam}/points`, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify({ value: Number(this.editValue) }),
                    });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    player.value = body.data.value;
                    this.editing = null;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.saving = false;
                }
            },

            init() { this.load(); },
        }"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.ranks') }}</h1>
            <input
                type="search"
                x-model.debounce.400ms="search"
                @input="load()"
                placeholder="{{ __('i18n::messages.common.search') }}"
                class="w-full max-w-xs rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none sm:w-64"
            >
        </div>

        <div class="mt-4 overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    <tr>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.position') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.player') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.points') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.kills') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.deaths') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.headshots') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="(player, index) in players" :key="player.steam">
                        <tr class="text-ink-muted">
                            <td class="px-4 py-3 text-ink-faint" x-text="index + 1"></td>
                            <td class="px-4 py-3">
                                <span class="block font-medium text-ink" x-text="player.name || '—'"></span>
                                <span class="block font-mono text-xs text-ink-faint" x-text="player.steam"></span>
                            </td>
                            <td class="px-4 py-3">
                                <template x-if="editing !== player.steam">
                                    <span x-text="player.value"></span>
                                </template>
                                <template x-if="editing === player.steam">
                                    <div class="flex items-center gap-1.5">
                                        <input type="number" x-model="editValue" class="w-24 rounded-lg border border-line bg-canvas px-2 py-1 text-sm text-ink focus:border-brand-strong focus:outline-none">
                                        <button type="button" :disabled="saving" @click="savePoints(player)" class="text-xs font-medium text-brand-strong hover:underline">
                                            {{ __('i18n::messages.ranks.save') }}
                                        </button>
                                    </div>
                                </template>
                            </td>
                            <td class="px-4 py-3" x-text="player.kills"></td>
                            <td class="px-4 py-3" x-text="player.deaths"></td>
                            <td class="px-4 py-3" x-text="player.headshots"></td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" x-show="editing !== player.steam" @click="startEdit(player)" class="text-xs text-ink-faint hover:text-ink">
                                    {{ __('i18n::messages.ranks.edit_points') }}
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
            <p x-show="!loading && !error && players.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>
            <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
        </div>
    </div>
</x-layout.app>
