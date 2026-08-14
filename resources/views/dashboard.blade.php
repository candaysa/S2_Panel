<x-layout.app :title="__('i18n::messages.nav.dashboard')">
    <div
        x-data="{
            loading: true,
            error: false,
            counts: { servers: null, bans: null, mutes: null, admins: null },
            servers: [],
            ranks: [],
            recentBans: [],
            recentMutes: [],
            // The page is public; this defaults to false so nothing ban/mute
            // related renders before the response comes back. The API applies
            // the same moderation-flag gate the Ban module's own endpoint
            // does - see DashboardController::canViewBanDetail().
            canViewBanDetail: false,

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

            // null means the figure could not be read, which is not the same
            // as zero - show a dash rather than claim there are none.
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
        }"
        x-init="init()"
    >
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.dashboard') }}</h1>

        <p x-show="error" x-cloak class="mt-4 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400">
            {{ __('i18n::messages.common.error') }}
        </p>

        {{-- Counters --}}
        <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                ['servers', 'server', __('i18n::messages.dashboard.card_servers')],
                ['bans', 'ban', __('i18n::messages.dashboard.card_bans')],
                ['mutes', 'mute', __('i18n::messages.dashboard.card_mutes')],
                ['admins', 'users', __('i18n::messages.dashboard.card_admins')],
            ] as [$key, $icon, $label])
                <div class="rounded-xl border border-line bg-surface p-5">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase tracking-wider text-ink-faint">{{ $label }}</span>
                        <x-icon name="{{ $icon }}" class="size-4 text-ink-faint" />
                    </div>
                    <p class="mt-2 text-3xl font-semibold text-ink" x-text="loading ? '·' : stat(counts.{{ $key }})"></p>
                </div>
            @endforeach
        </div>

        {{-- Rank leaderboard --}}
        <div class="mt-6 rounded-xl border border-line bg-surface">
            <div class="border-b border-line px-5 py-3.5">
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.dashboard.top_players') }}</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-line-soft text-xs uppercase tracking-wider text-ink-faint">
                            <th class="px-5 py-2.5 font-medium">#</th>
                            <th class="px-5 py-2.5 font-medium">{{ __('i18n::messages.dashboard.player') }}</th>
                            <th class="px-5 py-2.5 font-medium">{{ __('i18n::messages.dashboard.points') }}</th>
                            <th class="px-5 py-2.5 font-medium">{{ __('i18n::messages.dashboard.kills') }}</th>
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
                                <td class="px-5 py-2.5 font-medium text-ink" x-text="player.name ?? player.steam"></td>
                                <td class="px-5 py-2.5" x-text="stat(player.value)"></td>
                                <td class="px-5 py-2.5" x-text="stat(player.kills)"></td>
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

        {{-- Server list --}}
        <div class="mt-6 rounded-xl border border-line bg-surface">
            <div class="border-b border-line px-5 py-3.5">
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.nav.servers') }}</h2>
            </div>

            <ul class="divide-y divide-line-soft">
                <template x-for="server in servers" :key="server.id">
                    <li class="flex items-center justify-between gap-4 px-5 py-3">
                        <span class="min-w-0 truncate text-sm font-medium text-ink">{{ __('i18n::messages.nav.servers') }} #<span x-text="server.id"></span></span>
                        <span class="shrink-0 font-mono text-xs text-ink-faint" x-text="server.server_ip + ':' + server.server_port"></span>
                    </li>
                </template>
            </ul>

            <p x-show="!loading && servers.length === 0" x-cloak class="px-5 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.empty') }}
            </p>
        </div>

        {{-- Recent bans / recent mutes, side by side - hidden entirely (not
             shown empty) for a viewer without a moderation flag, so it never
             reads as "no bans" when the real answer is "you can't see them". --}}
        <div x-show="!loading && canViewBanDetail" x-cloak class="mt-6 grid gap-6 lg:grid-cols-2">
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
</x-layout.app>
