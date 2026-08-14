<x-layout.app :title="__('i18n::messages.nav.stats')">
    <div
        x-data="{
            loading: true,
            error: false,
            stats: null,
            tiles: [
                { key: 'total_players', label: @js(__('i18n::messages.stats.total_players')) },
                { key: 'active_bans', label: @js(__('i18n::messages.stats.active_bans')) },
                { key: 'total_servers', label: @js(__('i18n::messages.stats.total_servers')) },
                { key: 'open_tickets', label: @js(__('i18n::messages.stats.open_tickets')) },
                { key: 'total_users', label: @js(__('i18n::messages.stats.total_users')) },
            ],
            async init() {
                try {
                    const res = await fetch('/api/stats/dashboard', { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    this.stats = (await res.json()).data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },
        }"
        x-init="init()"
    >
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.stats') }}</h1>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <template x-for="tile in tiles" :key="tile.key">
                <div class="rounded-xl border border-line bg-surface p-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint" x-text="tile.label"></p>
                    <p class="mt-2 text-3xl font-semibold text-ink" x-show="!loading" x-cloak x-text="stats?.[tile.key] ?? 0"></p>
                    <p class="mt-2 text-3xl font-semibold text-ink-faint" x-show="loading" x-cloak>—</p>
                </div>
            </template>
        </div>

        <p x-show="error" x-cloak class="mt-6 text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
    </div>
</x-layout.app>
