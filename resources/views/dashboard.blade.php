<x-layout.app :title="__('i18n::messages.nav.dashboard')">
    <div x-data="dashboard()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.dashboard') }}</h1>

        <p x-show="error" x-cloak class="mt-4 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400">
            {{ __('i18n::messages.common.error') }}
        </p>

        {{-- Counters. Each card carries its own accent so the four read as
             distinct figures at a glance instead of one grey block; the tint
             is tied to meaning (bans red, mutes amber) rather than decoration. --}}
        <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            @foreach ([
                ['servers', 'server', __('i18n::messages.dashboard.card_servers'), 'text-emerald-400', 'bg-emerald-500/10', 'from-emerald-500/[0.07]'],
                ['bans', 'ban', __('i18n::messages.dashboard.card_bans'), 'text-red-400', 'bg-red-500/10', 'from-red-500/[0.07]'],
                ['mutes', 'mute', __('i18n::messages.dashboard.card_mutes'), 'text-amber-400', 'bg-amber-500/10', 'from-amber-500/[0.07]'],
                ['admins', 'users', __('i18n::messages.dashboard.card_admins'), 'text-sky-400', 'bg-sky-500/10', 'from-sky-500/[0.07]'],
            ] as [$key, $icon, $label, $fg, $chip, $wash])
                <div class="relative overflow-hidden rounded-xl border border-line bg-surface bg-gradient-to-br {{ $wash }} to-transparent p-4 sm:p-5">
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-ink-faint sm:text-xs">{{ $label }}</span>
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ $chip }}">
                            <x-icon name="{{ $icon }}" class="size-4 {{ $fg }}" />
                        </span>
                    </div>
                    <p class="mt-2 text-2xl font-semibold text-ink sm:text-3xl" x-text="loading ? '·' : stat(counts.{{ $key }})"></p>
                </div>
            @endforeach
        </div>

        {{-- Servers: live cards, not a bare id list --}}
        <div class="mt-6 rounded-xl border border-line bg-surface">
            <div class="flex items-center justify-between border-b border-line px-5 py-3.5">
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.nav.servers') }}</h2>
                <a href="{{ route('servers.page') }}" class="text-xs text-ink-faint transition-colors hover:text-brand-strong">{{ __('i18n::messages.dashboard.view_all') }}</a>
            </div>

            {{-- name / map / ip / players / connect, aligned as columns but
                 with no header row. Fixed track widths rather than a table so
                 the narrow columns stay put and only the name flexes; map and
                 ip drop away on small screens instead of squashing the rest. --}}
            <ul class="divide-y divide-line-soft">
                <template x-for="server in sortedServers" :key="server.id">
                    <li class="grid grid-cols-[auto_1fr_auto_auto] items-center gap-x-4 px-5 py-3 sm:grid-cols-[auto_minmax(0,1fr)_9rem_auto_auto] lg:grid-cols-[auto_minmax(0,1fr)_10rem_11rem_auto_auto]">
                        <span class="size-2 shrink-0 rounded-full" :class="server.online ? 'bg-emerald-400' : 'bg-ink-faint/40'"></span>

                        <p class="min-w-0 truncate text-sm font-medium text-ink" x-text="server.live?.name || (server.server_ip + ':' + server.server_port)"></p>

                        <p class="hidden truncate text-sm text-ink-muted sm:block" x-text="server.live?.map || '—'"></p>

                        <p class="hidden truncate font-mono text-xs text-ink-faint lg:block" x-text="server.server_ip + ':' + server.server_port"></p>

                        <span class="whitespace-nowrap text-right text-sm tabular-nums" :class="server.online ? 'text-ink-muted' : 'text-ink-faint'"
                              x-text="server.live ? server.live.players + ' / ' + server.live.max_players : '—'"></span>

                        <a x-show="server.online" :href="'steam://connect/' + server.server_ip + ':' + server.server_port"
                           class="justify-self-end rounded-lg bg-brand-soft px-2.5 py-1 text-xs font-medium text-brand-strong transition-opacity hover:opacity-80">
                            {{ __('i18n::messages.servers.connect') }}
                        </a>
                        {{-- Keeps the grid aligned when a row has no button --}}
                        <span x-show="!server.online" class="justify-self-end text-xs text-ink-faint">—</span>
                    </li>
                </template>
            </ul>

            <p x-show="!loading && servers.length === 0" x-cloak class="px-5 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.empty') }}
            </p>
        </div>

        {{-- Leaderboard --}}
        <div class="mt-6 rounded-xl border border-line bg-surface">
            <div class="flex items-center justify-between border-b border-line px-5 py-3.5">
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.dashboard.top_players') }}</h2>
                <a href="{{ route('ranks.page') }}" class="text-xs text-ink-faint transition-colors hover:text-brand-strong">{{ __('i18n::messages.dashboard.view_all') }}</a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-line-soft text-xs uppercase tracking-wider text-ink-faint">
                            <th class="px-5 py-2.5 font-medium">#</th>
                            <th class="px-5 py-2.5 font-medium">{{ __('i18n::messages.dashboard.player') }}</th>
                            <th class="hidden px-5 py-2.5 font-medium sm:table-cell">{{ __('i18n::messages.ranks.rank') }}</th>
                            <th class="px-5 py-2.5 font-medium">{{ __('i18n::messages.dashboard.points') }}</th>
                            <th class="hidden px-5 py-2.5 font-medium md:table-cell">{{ __('i18n::messages.dashboard.kills') }}</th>
                            <th class="hidden px-5 py-2.5 font-medium md:table-cell">{{ __('i18n::messages.ranks.playtime') }}</th>
                            <th class="px-5 py-2.5 font-medium">{{ __('i18n::messages.dashboard.kd') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-soft">
                        <template x-for="(player, index) in ranks" :key="player.steam">
                            <tr class="text-ink-muted transition-colors hover:bg-surface-raised">
                                <td class="px-5 py-2.5">
                                    <span
                                        class="inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold"
                                        :class="index < 3 ? 'bg-brand-soft text-brand-strong' : 'text-ink-faint'"
                                        x-text="index + 1"
                                    ></span>
                                </td>
                                <td class="px-5 py-2.5">
                                    <a :href="'/players/' + encodeURIComponent(player.steam)" class="font-medium text-ink transition-colors hover:text-brand-strong" x-text="player.name ?? player.steam"></a>
                                </td>
                                <td class="hidden px-5 py-2.5 sm:table-cell">
                                    <x-rank-badge rank="player.rank_tier" label="rankLabel(player)" />
                                </td>
                                <td class="px-5 py-2.5 font-medium text-ink" x-text="stat(player.value)"></td>
                                <td class="hidden px-5 py-2.5 md:table-cell" x-text="stat(player.kills)"></td>
                                <td class="hidden px-5 py-2.5 md:table-cell" x-text="playtime(player.playtime)"></td>
                                <td class="px-5 py-2.5" x-text="ratio(player.kills, player.deaths)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p x-show="!loading && ranks.length === 0" x-cloak class="px-5 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.empty') }}
            </p>
        </div>

        {{-- Recent bans / recent mutes, side by side - hidden entirely (not
             shown empty) for a viewer without a moderation flag, so it never
             reads as "no bans" when the real answer is "you can't see them". --}}
        <div x-show="!loading && canViewBanDetail" x-cloak class="mt-6 grid gap-4 lg:grid-cols-2">
            @foreach ([
                ['recentBans', __('i18n::messages.dashboard.recent_bans')],
                ['recentMutes', __('i18n::messages.dashboard.recent_mutes')],
            ] as [$collection, $heading])
                <div class="rounded-xl border border-line bg-surface">
                    <div class="border-b border-line px-5 py-3.5">
                        <h2 class="text-sm font-semibold text-ink">{{ $heading }}</h2>
                    </div>

                    <ul class="divide-y divide-line-soft">
                        <template x-for="row in {{ $collection }}" :key="row.id">
                            <li class="px-5 py-3">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="min-w-0 truncate text-sm font-medium text-ink" x-text="row.name ?? row.steamid"></span>
                                    <span class="shrink-0 text-xs text-ink-faint" x-text="date(row.created_at)"></span>
                                </div>
                                <p class="mt-0.5 truncate text-xs text-ink-muted" x-text="row.reason"></p>
                                <p class="mt-0.5 text-xs text-ink-faint">
                                    <span x-text="row.admin_name"></span>
                                    <span class="opacity-60">·</span>
                                    <span x-text="expiry(row.expires_at)"></span>
                                </p>
                            </li>
                        </template>
                    </ul>

                    <p x-show="!loading && {{ $collection }}.length === 0" x-cloak class="px-5 py-8 text-center text-sm text-ink-faint">
                        {{ __('i18n::messages.common.empty') }}
                    </p>
                </div>
            @endforeach
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.dashboard = () => ({
                loading: true,
                error: false,
                counts: { servers: null, bans: null, mutes: null, admins: null },
                servers: [],
                ranks: [],
                recentBans: [],
                recentMutes: [],
                // The page is public; this defaults to false so nothing
                // ban/mute related renders before the response comes back.
                // The API applies the same moderation-flag gate the Ban
                // module's own endpoint does.
                canViewBanDetail: false,
                tierLabels: @js(__('i18n::messages.rank_tiers')),
                t: @js(__('i18n::messages.ranks')),

                async init() {
                    try {
                        const res = await fetch('/api/dashboard', { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.counts = body.data.counts;
                        this.servers = body.data.servers;
                        this.ranks = body.data.ranks;
                        this.recentBans = body.data.recent_bans;
                        this.recentMutes = body.data.recent_mutes;
                        this.canViewBanDetail = body.meta?.can_view_ban_detail ?? false;
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                get sortedServers() {
                    return [...this.servers].sort((a, b) =>
                        (b.online - a.online) || ((b.live?.players ?? 0) - (a.live?.players ?? 0))
                    );
                },

                rankLabel(player) {
                    return this.tierLabels[player.rank_tier?.key] ?? this.tierLabels.unranked;
                },

                playtime(seconds) {
                    const h = Math.floor((seconds ?? 0) / 3600);
                    return h >= 1 ? h.toLocaleString() + this.t.hours_short : Math.floor((seconds ?? 0) / 60) + this.t.minutes_short;
                },

                // null means the figure could not be read, which is not the
                // same as zero - show a dash rather than claim there are none.
                stat(value) {
                    return value === null || value === undefined ? '—' : value.toLocaleString();
                },

                date(value) {
                    return value ? new Date(value).toLocaleDateString(undefined, { day: '2-digit', month: 'short' }) : '—';
                },

                expiry(value) {
                    return value ? new Date(value).toLocaleDateString(undefined, { day: '2-digit', month: 'short' }) : @js(__('i18n::messages.bans.never'));
                },

                ratio(kills, deaths) {
                    if (!deaths) return (kills ?? 0).toFixed(2);
                    return (kills / deaths).toFixed(2);
                },
            });
        </script>
    @endpush
</x-layout.app>
