<x-layout.app :title="__('i18n::messages.nav.bans')">
    <div
        x-data="{
            loading: true,
            forbidden: false,
            error: false,
            search: '',
            type: 'ban',
            status: 'active',
            rows: [],
            types: [
                { key: 'ban', label: @js(__('i18n::messages.bans.type_ban')) },
                { key: 'mute', label: @js(__('i18n::messages.bans.type_mute')) },
                { key: 'gag', label: @js(__('i18n::messages.bans.type_gag')) },
                { key: 'warn', label: @js(__('i18n::messages.bans.type_warn')) },
            ],
            async load() {
                this.loading = true;
                this.error = false;
                this.forbidden = false;
                try {
                    const url = new URL('/api/bans', window.location.origin);
                    url.searchParams.set('type', this.type);
                    url.searchParams.set('status', this.status);
                    if (this.search) url.searchParams.set('search', this.search);
                    const res = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (res.status === 403) { this.forbidden = true; return; }
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.rows = body.data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },
            init() { this.load(); },
            formatDate(value) {
                return value ? new Date(value).toLocaleString() : '—';
            },
        }"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.bans') }}</h1>
            <input
                type="search"
                x-model="search"
                @input.debounce.350ms="load()"
                placeholder="{{ __('i18n::messages.common.search') }}"
                class="w-full max-w-xs rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none sm:w-64"
            >
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1.5">
                <template x-for="t in types" :key="t.key">
                    <button
                        type="button"
                        @click="type = t.key; load()"
                        :class="type === t.key ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                        x-text="t.label"
                    ></button>
                </template>
            </div>

            <select
                x-model="status"
                @change="load()"
                class="rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
            >
                <option value="active">{{ __('i18n::messages.bans.status_active') }}</option>
                <option value="expired">{{ __('i18n::messages.bans.status_expired') }}</option>
                <option value="all">{{ __('i18n::messages.bans.status_all') }}</option>
            </select>
        </div>

        <div class="mt-4 overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    <tr>
                        <th class="px-4 py-3">{{ __('i18n::messages.bans.target') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.bans.admin') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.bans.reason') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.bans.scope') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.bans.created') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.bans.expires') }}</th>
                        <th class="px-4 py-3" x-show="type !== 'warn'">{{ __('i18n::messages.bans.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="row in rows" :key="row.id">
                        <tr class="text-ink-muted">
                            <td class="px-4 py-3">
                                <span class="block font-medium text-ink" x-text="row.target_name || '—'"></span>
                                <span class="block font-mono text-xs text-ink-faint" x-text="row.steamid"></span>
                            </td>
                            <td class="px-4 py-3" x-text="row.admin_name || '—'"></td>
                            <td class="max-w-xs px-4 py-3">
                                <span class="line-clamp-2" x-text="row.reason || '—'"></span>
                            </td>
                            <td class="px-4 py-3">
                                <span x-show="row.is_global" class="inline-flex items-center rounded-full bg-brand-soft px-2.5 py-0.5 text-xs font-medium text-brand-strong">
                                    {{ __('i18n::messages.bans.global') }}
                                </span>
                                <span x-show="!row.is_global" x-text="'{{ __('i18n::messages.bans.server') }} #' + (row.server_id ?? '—')"></span>
                            </td>
                            <td class="px-4 py-3" x-text="formatDate(row.created_at)"></td>
                            <td class="px-4 py-3" x-text="row.expires_at ? formatDate(row.expires_at) : '{{ __('i18n::messages.bans.never') }}'"></td>
                            <td class="px-4 py-3" x-show="type !== 'warn'">
                                <span
                                    :class="(row.status ?? 'active') === 'active' ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-faint'"
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    x-text="row.status ?? 'active'"
                                ></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.loading') }}
            </p>
            <p x-show="!loading && !forbidden && !error && rows.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
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
