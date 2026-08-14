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
            // flags/groups arrive as a comma-joined string from the plugin
            // table; empty segments are dropped so a trailing comma does not
            // render a blank chip.
            chips(value) {
                return (value ?? '').split(',').map(v => v.trim()).filter(Boolean);
            },
            expiry(admin) {
                if (!admin.expires_at) return @js(__('i18n::messages.bans.never'));
                return new Date(admin.expires_at).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
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
                        <th class="px-4 py-3">{{ __('i18n::messages.admins.flags') }}</th>
                        <th class="hidden px-4 py-3 lg:table-cell">{{ __('i18n::messages.nav.groups') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('i18n::messages.admins.immunity') }}</th>
                        <th class="hidden px-4 py-3 md:table-cell">{{ __('i18n::messages.admins.expires') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.admins.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="admin in admins" :key="admin.id">
                        <tr class="group text-ink-muted transition-colors hover:bg-surface-raised">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <img x-show="admin.avatar" :src="admin.avatar" alt="" loading="lazy" class="size-9 shrink-0 rounded-full object-cover ring-1 ring-line">
                                    <span x-show="!admin.avatar" class="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-raised text-sm font-semibold text-ink-faint" x-text="(admin.name ?? '?').charAt(0).toUpperCase()"></span>
                                    <span class="min-w-0">
                                        <a
                                            :href="'https://steamcommunity.com/profiles/' + admin.steamid"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="block truncate font-medium text-ink transition-colors hover:text-brand-strong"
                                            x-text="admin.name"
                                        ></a>
                                        <span class="block truncate font-mono text-xs text-ink-faint" x-text="admin.steamid"></span>
                                    </span>
                                </div>
                            </td>

                            {{-- Flags are a comma-joined string in the plugin
                                 table; split into chips so a long list stays
                                 scannable instead of running off the row. --}}
                            <td class="px-4 py-3">
                                <div class="flex max-w-xs flex-wrap gap-1">
                                    <template x-for="flag in chips(admin.flags)" :key="flag">
                                        <span class="rounded bg-surface-raised px-1.5 py-0.5 font-mono text-[11px] text-ink-muted" x-text="flag"></span>
                                    </template>
                                    <span x-show="chips(admin.flags).length === 0" class="text-ink-faint">—</span>
                                </div>
                            </td>

                            <td class="hidden px-4 py-3 lg:table-cell">
                                <div class="flex max-w-xs flex-wrap gap-1">
                                    <template x-for="group in chips(admin.groups)" :key="group">
                                        <span class="rounded bg-brand-soft px-1.5 py-0.5 text-[11px] font-medium text-brand-strong" x-text="group"></span>
                                    </template>
                                    <span x-show="chips(admin.groups).length === 0" class="text-ink-faint">—</span>
                                </div>
                            </td>

                            {{-- Immunity is a 0-100 rank; the bar makes the
                                 relative standing readable at a glance. --}}
                            <td class="px-4 py-3">
                                <div class="mx-auto w-14">
                                    <p class="text-center text-sm font-medium tabular-nums text-ink" x-text="admin.immunity ?? 0"></p>
                                    <div class="mt-1 h-1 overflow-hidden rounded-full bg-surface-raised">
                                        <div class="h-full rounded-full bg-brand-strong" :style="'width:' + Math.min(100, admin.immunity ?? 0) + '%'"></div>
                                    </div>
                                </div>
                            </td>

                            <td class="hidden px-4 py-3 text-sm md:table-cell" x-text="expiry(admin)"></td>

                            <td class="px-4 py-3">
                                <span
                                    :class="isActive(admin) ? 'bg-emerald-500/10 text-emerald-400' : 'bg-surface-raised text-ink-faint'"
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                >
                                    <span class="size-1.5 rounded-full" :class="isActive(admin) ? 'bg-emerald-400' : 'bg-ink-faint/50'"></span>
                                    <span x-text="isActive(admin) ? @js(__('i18n::messages.admins.active')) : @js(__('i18n::messages.admins.disabled'))"></span>
                                </span>
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
