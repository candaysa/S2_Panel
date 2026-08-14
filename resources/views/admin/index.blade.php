<x-layout.app :title="__('i18n::messages.nav.admin')">
    <div
        x-data="{
            loading: true,
            forbidden: false,
            error: false,
            search: '',
            admins: [],
            async load() {
                this.loading = true;
                this.error = false;
                this.forbidden = false;
                try {
                    const url = new URL('/api/admin', window.location.origin);
                    if (this.search) url.searchParams.set('search', this.search);
                    const res = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (res.status === 403) { this.forbidden = true; return; }
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.admins = body.data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },
            init() { this.load(); },
            isActive(admin) {
                return !admin.expires_at || new Date(admin.expires_at) > new Date();
            },
        }"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.admin') }}</h1>
            <input
                type="search"
                x-model.debounce.400ms="search"
                @input="load()"
                placeholder="{{ __('i18n::messages.common.search') }}"
                class="w-full max-w-xs rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none sm:w-64"
            >
        </div>

        <div class="mt-6 overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    <tr>
                        <th class="px-4 py-3">{{ __('i18n::messages.nav.admin') }}</th>
                        <th class="px-4 py-3">SteamID64</th>
                        <th class="px-4 py-3">Flags</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.nav.groups') }}</th>
                        <th class="px-4 py-3">Immunity</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="admin in admins" :key="admin.id">
                        <tr class="text-ink-muted">
                            <td class="px-4 py-3 font-medium text-ink" x-text="admin.name"></td>
                            <td class="px-4 py-3 font-mono text-xs" x-text="admin.steamid"></td>
                            <td class="px-4 py-3" x-text="admin.flags || '—'"></td>
                            <td class="px-4 py-3" x-text="admin.groups || '—'"></td>
                            <td class="px-4 py-3" x-text="admin.immunity"></td>
                            <td class="px-4 py-3">
                                <span
                                    :class="isActive(admin) ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-faint'"
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    x-text="isActive(admin) ? 'Active' : 'Disabled'"
                                ></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.loading') }}
            </p>
            <p x-show="!loading && !forbidden && !error && admins.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.empty') }}
            </p>
            <p x-show="forbidden" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.forbidden') }}
            </p>
            <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">
                {{ __('i18n::messages.common.error') }}
            </p>
        </div>
    </div>
</x-layout.app>
