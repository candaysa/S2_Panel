@php
    // Mirrors what PATCH /api/ranks/{steam}/points actually enforces
    // (flag:admin.root, owner bypasses) so the edit affordance is not offered
    // to someone the API will refuse.
    $canEditPoints = \App\Support\Access::hasFlag('admin.root');
@endphp

<x-layout.app :title="__('i18n::messages.nav.ranks')">
    <div
        x-data="rankBoard()"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.ranks') }}</h1>
                <p class="mt-1 text-sm text-ink-faint" x-show="!loading" x-cloak>
                    <span x-text="rangeFrom"></span>&ndash;<span x-text="rangeTo"></span>
                    <span class="opacity-70">/</span>
                    <span x-text="total.toLocaleString()"></span>
                    {{ __('i18n::messages.ranks.players_total') }}
                </p>
            </div>
            <div class="relative w-full sm:w-72">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-faint">
                    <x-icon name="search" class="size-4" />
                </span>
                <input
                    type="search"
                    x-model="search"
                    @input.debounce.350ms="load(true)"
                    placeholder="{{ __('i18n::messages.ranks.search_placeholder') }}"
                    class="w-full rounded-lg border border-line bg-surface py-2 pl-9 pr-3 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none"
                >
            </div>
        </div>

        {{-- Desktop: table. Mobile: the same rows as cards, since eight
             columns cannot be read on a phone no matter how they scroll. --}}
        <div class="mt-4 hidden overflow-x-auto rounded-xl border border-line bg-surface md:block">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    <tr>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.position') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.player') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.rank') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.points') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.kills') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.time_on_server') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.kd') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="player in players" :key="player.steam">
                        <tr class="group text-ink-muted transition-colors hover:bg-surface-raised">
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex size-7 items-center justify-center rounded-lg text-xs font-bold ring-1 ring-inset"
                                    :class="medal(player.position)"
                                    x-text="player.position"
                                ></span>
                            </td>
                            <td class="px-4 py-3">
                                <a :href="'/players/' + encodeURIComponent(player.steam)" class="flex items-center gap-2.5">
                                    <img x-show="player.avatar" :src="player.avatar" alt="" loading="lazy" class="size-9 shrink-0 rounded-full object-cover ring-1 ring-line">
                                    <span x-show="!player.avatar" class="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-raised text-sm font-semibold text-ink-faint" x-text="(player.name ?? '?').charAt(0).toUpperCase()"></span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-ink transition-colors group-hover:text-brand-strong" x-text="player.name || '—'"></span>
                                        <span class="block truncate font-mono text-xs text-ink-faint" x-text="player.steam"></span>
                                    </span>
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                <x-rank-badge rank="player.rank_tier" label="rankLabel(player)" show-label />
                            </td>
                            <td class="px-4 py-3">
                                <template x-if="editing !== player.steam">
                                    <span class="font-medium text-ink" x-text="num(player.value)"></span>
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
                            <td class="px-4 py-3 tabular-nums" x-text="num(player.kills)"></td>
                            <td class="px-4 py-3 tabular-nums" x-text="playtime(player.playtime)"></td>
                            <td class="px-4 py-3">
                                <span class="font-medium tabular-nums" :class="kdColor(player)" x-text="kd(player)"></span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" x-show="canEdit && editing !== player.steam" @click="startEdit(player)" class="text-xs text-ink-faint hover:text-ink">
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

        {{-- Mobile cards --}}
        <div class="mt-4 space-y-2 md:hidden">
            <template x-for="player in players" :key="player.steam">
                <a :href="'/players/' + encodeURIComponent(player.steam)" class="block rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-center gap-3">
                        <span
                            class="inline-flex size-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold ring-1 ring-inset"
                            :class="medal(player.position)"
                            x-text="player.position"
                        ></span>
                        <img x-show="player.avatar" :src="player.avatar" alt="" loading="lazy" class="size-9 shrink-0 rounded-full object-cover ring-1 ring-line">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink" x-text="player.name || '—'"></p>
                            <p class="truncate font-mono text-xs text-ink-faint" x-text="player.steam"></p>
                        </div>
                        <x-rank-badge rank="player.rank_tier" label="rankLabel(player)" size="sm" class="shrink-0" />
                    </div>
                    <dl class="mt-3 grid grid-cols-4 gap-2 border-t border-line-soft pt-3 text-center">
                        <div><dt class="text-[11px] text-ink-faint">{{ __('i18n::messages.ranks.points') }}</dt><dd class="text-sm font-medium text-ink" x-text="num(player.value)"></dd></div>
                        <div><dt class="text-[11px] text-ink-faint">{{ __('i18n::messages.ranks.kills') }}</dt><dd class="text-sm text-ink-muted" x-text="num(player.kills)"></dd></div>
                        <div><dt class="text-[11px] text-ink-faint">{{ __('i18n::messages.ranks.time_on_server') }}</dt><dd class="text-sm text-ink-muted" x-text="playtime(player.playtime)"></dd></div>
                        <div><dt class="text-[11px] text-ink-faint">{{ __('i18n::messages.ranks.kd') }}</dt><dd class="text-sm text-ink-muted" x-text="kd(player)"></dd></div>
                    </dl>
                </a>
            </template>

            <p x-show="loading" x-cloak class="rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
            <p x-show="!loading && !error && players.length === 0" x-cloak class="rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>
        </div>

        {{-- Pagination. Hidden when everything fits on one page, so a small
             server never sees controls it has no use for. --}}
        <div x-show="!loading && lastPage > 1" x-cloak class="mt-4 flex flex-wrap items-center justify-center gap-1">
            <button
                type="button"
                @click="go(page - 1)"
                :disabled="page === 1"
                class="inline-flex size-9 items-center justify-center rounded-lg border border-line text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink disabled:pointer-events-none disabled:opacity-40"
                aria-label="{{ __('i18n::messages.ranks.previous_page') }}"
            >
                <x-icon name="chevron-left" class="size-4" />
            </button>

            <template x-for="(n, i) in pageNumbers" :key="'p' + i">
                <span>
                    <span x-show="n === '…'" class="inline-flex size-9 items-center justify-center text-ink-faint">…</span>
                    <button
                        type="button"
                        x-show="n !== '…'"
                        @click="go(n)"
                        class="inline-flex min-w-9 items-center justify-center rounded-lg border px-2.5 py-2 text-sm font-medium transition-colors"
                        :class="n === page
                            ? 'border-brand-strong bg-brand-soft text-brand-strong'
                            : 'border-line text-ink-muted hover:bg-surface-raised hover:text-ink'"
                        x-text="n"
                    ></button>
                </span>
            </template>

            <button
                type="button"
                @click="go(page + 1)"
                :disabled="page === lastPage"
                class="inline-flex size-9 items-center justify-center rounded-lg border border-line text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink disabled:pointer-events-none disabled:opacity-40"
                aria-label="{{ __('i18n::messages.ranks.next_page') }}"
            >
                <x-icon name="chevron-left" class="size-4 rotate-180" />
            </button>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.rankBoard = () => ({
                loading: true,
                error: false,
                search: '',
                players: [],
                editing: null,
                editValue: '',
                saving: false,
                canEdit: @js($canEditPoints ?? false),

                labels: @js(__('i18n::messages.rank_tiers')),

                page: 1,
                perPage: 50,
                total: 0,
                lastPage: 1,

                async load(resetPage = false) {
                    // Any change to the filter has to send the reader back to
                    // page 1, or a search from page 7 lands on an empty page.
                    if (resetPage) this.page = 1;

                    this.loading = true;
                    this.error = false;
                    try {
                        const url = new URL('/api/ranks', window.location.origin);
                        url.searchParams.set('per_page', String(this.perPage));
                        url.searchParams.set('page', String(this.page));
                        if (this.search) url.searchParams.set('search', this.search);
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.players = body.data;
                        const p = body.meta?.pagination ?? {};
                        this.total = p.total ?? body.data.length;
                        this.lastPage = p.last_page ?? 1;
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

                // A short window around the current page plus the two ends,
                // so 60 pages do not render 60 buttons.
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

                rankLabel(player) {
                    return this.labels[player.rank_tier?.key] ?? this.labels.unranked;
                },

                // Gold / silver / bronze for the podium, nothing for the rest.
                medal(position) {
                    return {
                        1: 'bg-amber-400/15 text-amber-300 ring-amber-400/30',
                        2: 'bg-slate-300/15 text-slate-200 ring-slate-300/30',
                        3: 'bg-orange-600/15 text-orange-400 ring-orange-600/30',
                    }[position] ?? 'text-ink-faint ring-transparent';
                },

                kdColor(player) {
                    const kd = player.deaths ? player.kills / player.deaths : player.kills;
                    if (kd >= 1.5) return 'text-emerald-400';
                    if (kd >= 1) return 'text-ink';
                    return 'text-ink-faint';
                },

                num(value) {
                    return (value ?? 0).toLocaleString();
                },

                kd(player) {
                    if (!player.deaths) return (player.kills ?? 0).toFixed(2);
                    return (player.kills / player.deaths).toFixed(2);
                },

                // rank_base.playtime counts seconds. Hours is the only unit
                // that stays readable across a brand new player and someone
                // with 300+ hours on the server.
                playtime(seconds) {
                    const hours = Math.floor((seconds ?? 0) / 3600);
                    if (hours >= 1) return hours.toLocaleString() + @js(__('i18n::messages.ranks.hours_short'));
                    return Math.floor((seconds ?? 0) / 60) + @js(__('i18n::messages.ranks.minutes_short'));
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
                        player.value = (await res.json()).data.value;
                        this.editing = null;
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.saving = false;
                    }
                },

                init() { this.load(); },
            });
        </script>
    @endpush
</x-layout.app>
