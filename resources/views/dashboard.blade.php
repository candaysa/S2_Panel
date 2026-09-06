<x-layout.app :title="__('i18n::messages.nav.dashboard')">
    <div x-data="dashboard()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.dashboard') }}</h1>

        <p x-show="error" x-cloak class="mt-4 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400">
            {{ __('i18n::messages.common.error') }}
        </p>

        {{-- Counters. Each card carries its own border/accent so the four
             read as distinct figures at a glance instead of one grey block;
             the tint is tied to meaning (bans red, mutes amber) rather than
             decoration.

             Bans and mutes lead with a full-width "N active" band across the
             top of the card. The headline number is the all-time total, and
             on its own it cannot tell a moderator whether 605 bans means 605
             people are banned right now - which is the only figure they are
             ever actually looking for. Giving it the widest, first-read line
             on the card puts the two in the right order. Servers and admins
             have no such distinction, so they simply have no band. --}}
        <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            @foreach ([
                ['servers', 'server', __('i18n::messages.dashboard.card_servers'), 'text-emerald-400', 'bg-emerald-500/10', 'border-emerald-500/30', 'from-emerald-500/[0.07]', false],
                ['bans', 'ban', __('i18n::messages.dashboard.card_bans'), 'text-red-400', 'bg-red-500/10', 'border-red-500/30', 'from-red-500/[0.07]', true],
                ['mutes', 'mute', __('i18n::messages.dashboard.card_mutes'), 'text-amber-400', 'bg-amber-500/10', 'border-amber-500/30', 'from-amber-500/[0.07]', true],
                ['admins', 'users', __('i18n::messages.dashboard.card_admins'), 'text-sky-400', 'bg-sky-500/10', 'border-sky-500/30', 'from-sky-500/[0.07]', false],
            ] as [$key, $icon, $label, $fg, $chip, $ring, $wash, $hasActive])
                <div class="flex flex-col overflow-hidden rounded-xl border bg-surface bg-gradient-to-b {{ $ring }} {{ $wash }} to-transparent">
                    @if ($hasActive)
                        <div
                            x-show="!loading && counts.{{ $key }}"
                            x-cloak
                            class="border-b px-3 py-1.5 text-center text-xs font-semibold {{ $ring }} {{ $chip }} {{ $fg }}"
                            x-text="stat(counts.{{ $key }}?.active) + ' ' + activeLabel"
                        ></div>
                    @endif

                    <div class="flex flex-1 items-center justify-between gap-3 p-4 sm:p-5">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $chip }} sm:size-12">
                            <x-icon name="{{ $icon }}" class="size-5 {{ $fg }} sm:size-6" />
                        </span>
                        <div class="min-w-0 text-right">
                            <p class="text-2xl font-semibold leading-none text-ink sm:text-3xl" x-text="loading ? '·' : stat({{ $hasActive ? "counts.{$key}?.total" : "counts.{$key}" }})"></p>
                            <span class="mt-1.5 block text-[11px] font-medium uppercase tracking-wider text-ink-faint sm:text-xs">{{ $label }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Servers: live cards, not a bare id list --}}
        <div class="mt-6 rounded-xl border border-emerald-500/30 bg-surface">
            <div class="flex items-center justify-between border-b border-line px-5 py-3.5">
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.nav.servers') }}</h2>
                @if (\App\Support\Access::isOwner())
                    <a href="{{ route('settings.servers.page') }}" class="text-xs text-ink-faint transition-colors hover:text-brand-strong">{{ __('i18n::messages.servers.manage') }}</a>
                @endif
            </div>

            {{-- name / map / ip / players / connect, aligned as columns but
                 with no header row. Fixed track widths rather than a table so
                 the narrow columns stay put and only the name flexes; map and
                 ip drop away on small screens instead of squashing the rest. --}}
            <ul class="divide-y divide-line-soft">
                <template x-for="server in sortedServers" :key="server.id">
                    <li class="grid grid-cols-[auto_1fr_auto_auto] items-center gap-x-4 px-5 py-3 sm:grid-cols-[auto_minmax(0,1fr)_9rem_auto_auto] lg:grid-cols-[auto_minmax(0,1fr)_10rem_11rem_auto_auto]">
                        <span class="size-2 shrink-0 rounded-full" :class="server.online ? 'bg-emerald-400' : 'bg-ink-faint/40'"></span>

                        <a
                            :href="'/servers/' + server.id"
                            class="min-w-0 truncate text-sm font-medium text-ink transition-colors hover:text-brand-strong"
                            x-text="server.live?.name || (server.server_ip + ':' + server.server_port)"
                        ></a>

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
                            <th class="w-12 py-2.5 pl-5 pr-2 font-medium">#</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('i18n::messages.dashboard.player') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('i18n::messages.ranks.rank') }}</th>
                            <th class="px-3 py-2.5 text-right font-medium">{{ __('i18n::messages.dashboard.points') }}</th>
                            <th class="hidden px-3 py-2.5 text-right font-medium md:table-cell">{{ __('i18n::messages.dashboard.kills') }}</th>
                            <th class="hidden px-3 py-2.5 text-right font-medium md:table-cell">{{ __('i18n::messages.ranks.time_on_server') }}</th>
                            <th class="py-2.5 pl-3 pr-5 text-right font-medium">{{ __('i18n::messages.dashboard.kd') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-soft">
                        <template x-for="(player, index) in ranks" :key="player.steam">
                            <tr class="group text-ink-muted transition-colors hover:bg-surface-raised">
                                {{-- Top three get a medal tint; the rest stay
                                     quiet so the podium actually stands out. --}}
                                <td class="py-2.5 pl-5 pr-2">
                                    <span
                                        class="inline-flex size-7 items-center justify-center rounded-lg text-xs font-bold ring-1 ring-inset"
                                        :class="medal(index)"
                                        x-text="index + 1"
                                    ></span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <a :href="'/players/' + encodeURIComponent(player.steam)" class="flex items-center gap-2.5">
                                        <img
                                            x-show="player.avatar"
                                            :src="player.avatar"
                                            alt=""
                                            loading="lazy"
                                            class="size-8 shrink-0 rounded-full ring-1 ring-line object-cover"
                                        >
                                        <span x-show="!player.avatar" class="flex size-8 shrink-0 items-center justify-center rounded-full bg-surface-raised text-xs font-semibold text-ink-faint" x-text="(player.name ?? '?').charAt(0).toUpperCase()"></span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-ink transition-colors group-hover:text-brand-strong" x-text="player.name ?? player.steam"></span>
                                            <span class="block truncate text-xs text-ink-faint" x-text="lastSeen(player.lastconnect)"></span>
                                        </span>
                                    </a>
                                </td>
                                <td class="hidden px-3 py-2.5 sm:table-cell">
                                    <x-rank-badge rank="player.rank_tier" label="rankLabel(player)" />
                                </td>
                                <td class="px-3 py-2.5 text-right"><x-premier-badge points="player.value" size="sm" /></td>
                                <td class="hidden px-3 py-2.5 text-right tabular-nums md:table-cell" x-text="stat(player.kills)"></td>
                                <td class="hidden px-3 py-2.5 text-right tabular-nums md:table-cell" x-text="playtime(player.playtime)"></td>
                                <td class="py-2.5 pl-3 pr-5 text-right">
                                    <span class="font-medium tabular-nums" :class="kdColor(player)" x-text="ratio(player.kills, player.deaths)"></span>
                                </td>
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
             reads as "no bans" when the real answer is "you can't see them".
             Both come straight from BanService::list() now (see
             DashboardController), so every field here - avatar, the live
             name fallback for a row whose stored name is just its own
             SteamID, the three-state status - is exactly what /bans itself
             would show for the same row; nothing here recomputes anything
             that page didn't already work out once. --}}
        <div x-show="!loading && canViewBanDetail" x-cloak class="mt-6 grid gap-4 lg:grid-cols-2">
            @foreach ([
                ['recentBans', __('i18n::messages.dashboard.recent_bans')],
                ['recentMutes', __('i18n::messages.dashboard.recent_mutes')],
            ] as [$collection, $heading])
                <div class="overflow-hidden rounded-xl border border-line bg-surface">
                    <div class="border-b border-line px-5 py-3.5">
                        <h2 class="text-sm font-semibold text-ink">{{ $heading }}</h2>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs font-semibold uppercase tracking-wider text-ink-faint">
                                <tr>
                                    <th class="px-5 py-2.5">{{ __('i18n::messages.bans.target') }}</th>
                                    <th class="px-5 py-2.5">{{ __('i18n::messages.bans.admin') }}</th>
                                    <th class="px-5 py-2.5">{{ __('i18n::messages.bans.created') }}</th>
                                    <th class="px-5 py-2.5">{{ __('i18n::messages.bans.status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line-soft">
                                <template x-for="row in {{ $collection }}" :key="row.id">
                                    <tr>
                                        <td class="max-w-[9rem] px-5 py-3">
                                            <a
                                                :href="profileUrl(row.steamid)"
                                                :target="profileUrl(row.steamid) ? '_blank' : null"
                                                rel="noopener noreferrer"
                                                class="flex min-w-0 items-center gap-2.5"
                                            >
                                                <img x-show="row.avatar" :src="row.avatar" alt="" loading="lazy" class="size-7 shrink-0 rounded-full object-cover ring-1 ring-line">
                                                <span x-show="!row.avatar" class="flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-raised text-xs font-semibold text-ink-faint" x-text="(row.target_name || '?').charAt(0).toUpperCase()"></span>
                                                <span class="min-w-0 truncate font-medium" :class="profileUrl(row.steamid) ? 'text-ink transition-colors hover:text-brand-strong' : 'text-ink'" x-text="row.target_name || '—'"></span>
                                            </a>
                                        </td>
                                        <td class="max-w-[7rem] px-5 py-3">
                                            <a
                                                :href="profileUrl(row.admin_steamid)"
                                                :target="profileUrl(row.admin_steamid) ? '_blank' : null"
                                                rel="noopener noreferrer"
                                                class="block truncate text-ink-muted"
                                                :class="profileUrl(row.admin_steamid) ? 'transition-colors hover:text-brand-strong' : ''"
                                                x-text="row.admin_name || '—'"
                                            ></a>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-3 text-xs text-ink-faint" x-text="date(row.created_at)"></td>
                                        <td class="px-5 py-3">
                                            <span
                                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                                :class="statusBadge(row.status)"
                                                x-text="row.status === 'active' && !row.expires_at ? @js(__('i18n::messages.bans.permanent')) : statusLabel(row.status)"
                                            ></span>
                                            {{-- Share of the ban/mute's own total duration still remaining -
                                                 full for permanent or anything not currently active, tapering
                                                 down toward expiry for a temporary one still in force. --}}
                                            <span class="mt-1.5 block h-1 w-16 overflow-hidden rounded-full bg-surface-raised">
                                                <span class="block h-full rounded-full transition-all" :class="durationColor(row.status)" :style="'width:' + durationPercent(row) + '%'"></span>
                                            </span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

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
                t: @js(__('i18n::messages.ranks')),
                activeLabel: @js(__('i18n::messages.bans.status_active')),

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
                    return player.rank_tier?.label || this.t.unranked;
                },

                // Gold / silver / bronze for the podium, nothing for the rest.
                medal(index) {
                    return [
                        'bg-amber-400/15 text-amber-300 ring-amber-400/30',
                        'bg-slate-300/15 text-slate-200 ring-slate-300/30',
                        'bg-orange-600/15 text-orange-400 ring-orange-600/30',
                    ][index] ?? 'text-ink-faint ring-transparent';
                },

                kdColor(player) {
                    const kd = player.deaths ? player.kills / player.deaths : player.kills;
                    if (kd >= 1.5) return 'text-emerald-400';
                    if (kd >= 1) return 'text-ink';
                    return 'text-ink-faint';
                },

                lastSeen(unix) {
                    if (!unix) return '';
                    const days = Math.floor((Date.now() / 1000 - unix) / 86400);
                    if (days <= 0) return this.t.seen_today;
                    if (days === 1) return this.t.seen_yesterday;
                    return this.t.seen_days_ago.replace(':days', days);
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

                // A Steam profile URL, or '' when there is no real account
                // behind the id - a console-issued punishment records no
                // admin, and a link to nobody is worse than none because it
                // still looks like one.
                profileUrl(steamid) {
                    const id = String(steamid ?? '');
                    return id && id !== '0' ? `https://steamcommunity.com/profiles/${id}` : '';
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

                // Fraction of the row's OWN total duration still remaining -
                // not a countdown against "now" alone, since a 30-day mute
                // one day old and one three days from expiring should not
                // read as the same "almost full" bar. Anything not
                // currently active (lifted early, or already ran out) reads
                // as fully spent regardless of what expires_at says.
                durationPercent(row) {
                    if (row.status !== 'active' || !row.expires_at) return 100;
                    const created = new Date(row.created_at).getTime();
                    const expires = new Date(row.expires_at).getTime();
                    const total = expires - created;
                    if (!(total > 0)) return 100;
                    const remaining = expires - Date.now();
                    return Math.max(0, Math.min(100, Math.round((remaining / total) * 100)));
                },

                durationColor(status) {
                    if (status === 'removed') return 'bg-sky-400';
                    if (status === 'expired') return 'bg-ink-faint/50';
                    return 'bg-brand-strong';
                },

                ratio(kills, deaths) {
                    if (!deaths) return (kills ?? 0).toFixed(2);
                    return (kills / deaths).toFixed(2);
                },
            });
        </script>
    @endpush
</x-layout.app>
