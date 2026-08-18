<x-layout.app :title="__('i18n::messages.nav.bans')">
    <div
        x-data="{
            loading: true,
            forbidden: false,
            error: false,
            search: '',
            type: 'ban',
            status: 'active',
            sort: 'id',
            dir: 'desc',
            rows: [],
            page: 1,
            perPage: 50,
            lastPage: 1,
            total: 0,
            types: [
                { key: 'ban', label: @js(__('i18n::messages.bans.type_ban')) },
                { key: 'mute', label: @js(__('i18n::messages.bans.type_mute')) },
                { key: 'gag', label: @js(__('i18n::messages.bans.type_gag')) },
                { key: 'warn', label: @js(__('i18n::messages.bans.type_warn')) },
            ],
            // resetPage on anything that changes what the result set IS
            // (search, type, status, sort) - otherwise narrowing a list while
            // on page 7 lands on an empty page and looks like no results.
            async load(resetPage = false) {
                if (resetPage) this.page = 1;
                this.loading = true;
                this.error = false;
                this.forbidden = false;
                try {
                    const url = new URL('/api/bans', window.location.origin);
                    url.searchParams.set('type', this.type);
                    url.searchParams.set('status', this.status);
                    url.searchParams.set('sort', this.sort);
                    url.searchParams.set('dir', this.dir);
                    url.searchParams.set('per_page', String(this.perPage));
                    url.searchParams.set('page', String(this.page));
                    if (this.search) url.searchParams.set('search', this.search);
                    const res = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (res.status === 403) { this.forbidden = true; return; }
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.rows = body.data;
                    const p = body.meta?.pagination ?? {};
                    this.lastPage = p.last_page ?? 1;
                    this.total = p.total ?? this.rows.length;
                    this.page = p.current_page ?? this.page;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },
            go(page) {
                if (page < 1 || page > this.lastPage || page === this.page) return;
                this.page = page;
                this.load();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            },
            // A short window around the current page plus the two ends, so
            // 60 pages do not render 60 buttons.
            get pageNumbers() {
                const span = 2;
                const pages = new Set([1, this.lastPage]);
                for (let i = this.page - span; i <= this.page + span; i++) {
                    if (i >= 1 && i <= this.lastPage) pages.add(i);
                }
                const sorted = [...pages].sort((a, b) => a - b);
                const out = [];
                let prev = 0;
                for (const n of sorted) {
                    if (prev && n - prev > 1) out.push('…');
                    out.push(n);
                    prev = n;
                }
                return out;
            },
            get rangeFrom() {
                return this.total === 0 ? 0 : (this.page - 1) * this.perPage + 1;
            },
            get rangeTo() {
                return Math.min(this.page * this.perPage, this.total);
            },
            sortBy(key) {
                if (this.sort === key) { this.dir = this.dir === 'asc' ? 'desc' : 'asc'; }
                else { this.sort = key; this.dir = key === 'target_name' || key === 'admin_name' ? 'asc' : 'desc'; }
                this.load(true);
            },
            init() { this.load(); },
            formatDate(value) {
                return value ? new Date(value).toLocaleString() : '—';
            },
            statusLabels: {
                active: @js(__('i18n::messages.bans.status_active')),
                removed: @js(__('i18n::messages.bans.status_removed')),
                expired: @js(__('i18n::messages.bans.status_expired')),
            },
            statusLabel(status) {
                return this.statusLabels[status] ?? this.statusLabels.active;
            },
            statusBadge(status) {
                if (status === 'removed') return 'bg-sky-500/10 text-sky-400';
                if (status === 'expired') return 'bg-surface-raised text-ink-faint';
                return 'bg-brand-soft text-brand-strong';
            },
        }"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.bans') }}</h1>
            <input
                type="search"
                x-model="search"
                @input.debounce.350ms="load(true)"
                placeholder="{{ __('i18n::messages.bans.search_placeholder') }}"
                class="w-full max-w-xs rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none sm:w-64"
            >
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-1.5">
                <template x-for="t in types" :key="t.key">
                    <button
                        type="button"
                        @click="type = t.key; load(true)"
                        :class="type === t.key ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                        x-text="t.label"
                    ></button>
                </template>
            </div>

            <div class="flex items-center gap-2">
                <select
                    x-model="status"
                    @change="load(true)"
                    class="rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                >
                    <option value="active">{{ __('i18n::messages.bans.status_active') }}</option>
                    <option value="expired">{{ __('i18n::messages.bans.status_expired') }}</option>
                    <option value="all">{{ __('i18n::messages.bans.status_all') }}</option>
                </select>

                <select
                    x-model.number="perPage"
                    @change="load(true)"
                    class="rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                    aria-label="{{ __('i18n::messages.pagination.per_page') }}"
                >
                    @foreach ([25, 50, 100] as $n)
                        <option value="{{ $n }}">{{ $n }} / {{ __('i18n::messages.pagination.page') }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    <tr>
                        <th class="px-4 py-3"><x-sort-th key="target_name" :label="__('i18n::messages.bans.target')" /></th>
                        <th class="px-4 py-3"><x-sort-th key="admin_name" :label="__('i18n::messages.bans.admin')" /></th>
                        <th class="px-4 py-3">{{ __('i18n::messages.bans.reason') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.bans.scope') }}</th>
                        <th class="px-4 py-3"><x-sort-th key="created_at" :label="__('i18n::messages.bans.created')" /></th>
                        <th class="px-4 py-3"><x-sort-th key="expires_at" :label="__('i18n::messages.bans.expires')" /></th>
                        <th class="px-4 py-3" x-show="type !== 'warn'">{{ __('i18n::messages.bans.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="row in rows" :key="row.id">
                        <tr class="text-ink-muted">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <template x-if="row.avatar">
                                        <img :src="row.avatar" alt="" class="size-7 shrink-0 rounded-full">
                                    </template>
                                    <template x-if="!row.avatar">
                                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-raised text-xs font-semibold text-ink-muted" x-text="(row.target_name || '?').charAt(0).toUpperCase()"></span>
                                    </template>
                                    <div class="min-w-0">
                                        <span class="block truncate font-medium text-ink" x-text="row.target_name || '—'"></span>
                                        <span class="block font-mono text-xs text-ink-faint" x-text="row.steamid"></span>
                                    </div>
                                </div>
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
                                    :class="statusBadge(row.status)"
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    x-text="statusLabel(row.status)"
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

        {{-- jump: a busy server has hundreds of pages of bans, and the
             windowed buttons only ever reach the two ends and the five
             around you. --}}
        <x-pagination :jump="true" />
    </div>
</x-layout.app>
