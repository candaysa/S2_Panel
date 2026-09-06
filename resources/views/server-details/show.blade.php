<x-layout.app :title="__('i18n::messages.server_details.title')">
    <div x-data="serverDetailsPage({{ (int) $serverId }})" x-init="init()">
        <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1 text-sm text-ink-muted transition-colors hover:text-ink">
            <x-icon name="chevron-left" class="size-3.5" />
            {{ __('i18n::messages.nav.dashboard') }}
        </a>

        <p x-show="notFound" x-cloak class="mt-4 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400">
            {{ __('i18n::messages.api_errors.server_not_found') }}
        </p>
        <p x-show="error" x-cloak class="mt-4 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400">
            {{ __('i18n::messages.common.error') }}
        </p>

        <div x-show="!notFound" x-cloak>
            {{-- Header --}}
            <div class="mt-3 flex flex-wrap items-center justify-between gap-4">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="size-2.5 shrink-0 rounded-full" :class="server?.online ? 'bg-emerald-400' : 'bg-ink-faint/40'"></span>
                    <div class="min-w-0">
                        <h1 class="truncate text-2xl font-semibold text-ink" x-text="loading ? '·' : (server?.live?.name || (server?.server_ip + ':' + server?.server_port))"></h1>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm text-ink-muted">
                            <span class="font-mono" x-text="server ? (server.server_ip + ':' + server.server_port) : ''"></span>
                            <span x-show="server?.live?.map">{{ __('i18n::messages.servers.map') }}: <span class="text-ink" x-text="server?.live?.map"></span></span>
                            <span x-text="server?.live ? (server.live.players + ' / ' + server.live.max_players + ' ' + t.players) : t.offline"></span>
                        </p>
                    </div>
                </div>
                <a
                    x-show="server?.online"
                    :href="'steam://connect/' + server?.server_ip + ':' + server?.server_port"
                    class="inline-flex shrink-0 items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90"
                >
                    {{ __('i18n::messages.servers.connect') }}
                </a>
            </div>

            {{-- Activity chart --}}
            <div class="mt-6 rounded-xl border border-line bg-surface p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.server_details.activity') }}</h2>
                    <div class="flex gap-1">
                        <template x-for="r in ranges" :key="r.key">
                            <button
                                type="button"
                                @click="range = r.key; loadStats()"
                                class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                                :class="range === r.key ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                                x-text="r.label"
                            ></button>
                        </template>
                    </div>
                </div>

                <div class="relative mt-4 h-64">
                    <p x-show="statsLoading" x-cloak class="absolute inset-0 flex items-center justify-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
                    <p x-show="!statsLoading && stats.length === 0" x-cloak class="absolute inset-0 flex items-center justify-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>
                    <canvas x-ref="chart" x-show="!statsLoading && stats.length > 0" x-cloak></canvas>
                </div>
            </div>

            {{-- Live players --}}
            <div class="mt-6 rounded-xl border border-line bg-surface">
                <div class="flex items-center justify-between border-b border-line px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.server_details.online_players') }}</h2>
                    <span class="text-xs text-ink-faint" x-text="players.length + ' ' + t.players"></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs font-semibold uppercase tracking-wider text-ink-faint">
                            <tr>
                                <th class="px-5 py-2.5">{{ __('i18n::messages.server_details.player') }}</th>
                                <th class="px-5 py-2.5 text-right">{{ __('i18n::messages.server_details.score') }}</th>
                                <th class="px-5 py-2.5 text-right">{{ __('i18n::messages.server_details.time_connected') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <template x-for="player in players" :key="player.name + player.duration_seconds">
                                <tr class="text-ink-muted">
                                    <td class="px-5 py-2.5 font-medium">
                                        {{-- steam is a best-effort name match against the Rank
                                             module's own player table (see
                                             ServerDetailsService::steamByName()) - the A2S query
                                             this list comes from carries no SteamID at all, only a
                                             display name. Only names that matched exactly one
                                             player link; anything ambiguous or unmatched (Rank
                                             module off, a shared/default name, ...) just isn't a
                                             link, same as the rest of the row. --}}
                                        <a
                                            x-show="player.steam"
                                            :href="'/players/' + encodeURIComponent(player.steam)"
                                            class="text-ink transition-colors hover:text-brand-strong"
                                            x-text="player.name || t.unnamed"
                                        ></a>
                                        <span x-show="!player.steam" class="text-ink" x-text="player.name || t.unnamed"></span>
                                    </td>
                                    <td class="px-5 py-2.5 text-right tabular-nums" x-text="player.score"></td>
                                    <td class="px-5 py-2.5 text-right tabular-nums" x-text="duration(player.duration_seconds)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <p x-show="!playersLoading && playersOnline && players.length === 0" x-cloak class="px-5 py-8 text-center text-sm text-ink-faint">
                    {{ __('i18n::messages.common.empty') }}
                </p>
                <p x-show="!playersLoading && !playersOnline" x-cloak class="px-5 py-8 text-center text-sm text-ink-faint">
                    {{ __('i18n::messages.servers.offline') }}
                </p>
            </div>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.serverDetailsPage = (serverId) => {
                // Chart.js instances hold circular internal references
                // (chart -> controller -> chart -> ...) that blow the call
                // stack if Alpine tries to deep-wrap them in its reactivity
                // proxy. Kept as a plain closure variable instead of an
                // x-data property so it's never made reactive.
                let chartInstance = null;

                return {
                serverId,
                loading: true,
                notFound: false,
                error: false,
                server: null,

                range: '1h',
                ranges: [
                    { key: '1h', label: @js(__('i18n::messages.server_details.range_1h')) },
                    { key: '12h', label: @js(__('i18n::messages.server_details.range_12h')) },
                    { key: '1w', label: @js(__('i18n::messages.server_details.range_1w')) },
                    { key: '12m', label: @js(__('i18n::messages.server_details.range_12m')) },
                ],
                stats: [],
                statsLoading: true,

                players: [],
                playersOnline: false,
                playersLoading: true,
                playersTimer: null,

                t: {
                    players: @js(__('i18n::messages.servers.players')),
                    offline: @js(__('i18n::messages.servers.offline')),
                    unnamed: @js(__('i18n::messages.server_details.unnamed_player')),
                },

                async init() {
                    await this.loadServer();

                    if (this.notFound) return;

                    await this.loadStats();
                    await this.loadPlayers();

                    // Live state moves fast enough that a static snapshot goes
                    // stale within a minute of opening the page.
                    this.playersTimer = setInterval(() => this.loadPlayers(), 15000);
                },

                async loadServer() {
                    try {
                        const res = await fetch(`/api/servers/${this.serverId}`, { headers: { Accept: 'application/json' } });
                        if (res.status === 404) { this.notFound = true; return; }
                        if (!res.ok) throw new Error('request_failed');
                        this.server = (await res.json()).data;
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                async loadStats() {
                    this.statsLoading = true;
                    try {
                        const res = await fetch(`/api/server-details/${this.serverId}/stats?range=${this.range}`, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        this.stats = (await res.json()).data;
                        this.renderChart();
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.statsLoading = false;
                    }
                },

                async loadPlayers() {
                    try {
                        const res = await fetch(`/api/server-details/${this.serverId}/players`, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.players = body.data;
                        this.playersOnline = body.meta?.online ?? false;
                    } catch (e) {
                        // A missed poll should not blank out an otherwise-fine
                        // player list - just try again on the next tick.
                    } finally {
                        this.playersLoading = false;
                    }
                },

                // Custom properties hold whatever the theme authored them as
                // (color-mix(), oklab, hex, ...) verbatim - reading one back
                // with getPropertyValue() returns that raw, unresolved text,
                // not a usable color. Applying it through a real `color`
                // property and reading getComputedStyle() back is what
                // forces the browser to resolve and normalize it to
                // "rgb(r, g, b)", which withAlpha() below can then parse
                // regardless of how the variable itself was written.
                resolveColor(varName, fallback) {
                    const el = document.createElement('span');
                    el.style.color = `var(${varName})`;
                    document.body.appendChild(el);
                    const rgb = getComputedStyle(el).color;
                    document.body.removeChild(el);
                    return rgb || fallback;
                },

                withAlpha(rgb, alpha) {
                    const m = rgb.match(/[\d.]+/g);
                    return m ? `rgba(${m[0]}, ${m[1]}, ${m[2]}, ${alpha})` : rgb;
                },

                renderChart() {
                    if (!window.Chart || !this.$refs.chart || this.stats.length === 0) return;

                    const points = this.stats.map((s) => ({ x: new Date(s.at).getTime(), y: s.players }));
                    const brand = this.resolveColor('--color-brand-strong', 'rgb(0, 255, 227)');
                    const grid = this.resolveColor('--color-line', 'rgb(43, 43, 48)');
                    const muted = this.resolveColor('--color-ink-faint', 'rgb(113, 113, 122)');

                    if (chartInstance) {
                        chartInstance.data.datasets[0].data = points;
                        chartInstance.update();
                        return;
                    }

                    chartInstance = new window.Chart(this.$refs.chart, {
                        type: 'line',
                        data: {
                            datasets: [{
                                data: points,
                                borderColor: brand,
                                backgroundColor: (ctx) => {
                                    const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, ctx.chart.height);
                                    g.addColorStop(0, this.withAlpha(brand, 0.35));
                                    g.addColorStop(1, this.withAlpha(brand, 0));
                                    return g;
                                },
                                fill: true,
                                tension: 0.35,
                                pointRadius: 0,
                                pointHoverRadius: 3,
                                borderWidth: 2,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { intersect: false, mode: 'index' },
                            scales: {
                                x: {
                                    type: 'linear',
                                    grid: { display: false },
                                    ticks: {
                                        color: muted,
                                        maxRotation: 0,
                                        autoSkipPadding: 20,
                                        callback: (value) => this.formatAxisTime(value),
                                    },
                                },
                                y: {
                                    beginAtZero: true,
                                    grid: { color: grid },
                                    ticks: { color: muted, precision: 0 },
                                },
                            },
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        title: (items) => items.length ? this.formatAxisTime(items[0].parsed.x, true) : '',
                                    },
                                },
                            },
                        },
                    });
                },

                // Plain numeric x values with a hand-formatted tick label,
                // no date-adapter dependency needed.
                formatAxisTime(ms, full = false) {
                    const d = new Date(ms);
                    if (full) return d.toLocaleString();
                    if (this.range === '12m') return d.toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
                    if (this.range === '1w') return d.toLocaleDateString(undefined, { weekday: 'short', hour: '2-digit' });
                    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
                },

                duration(seconds) {
                    const s = Math.max(0, seconds ?? 0);
                    const h = Math.floor(s / 3600);
                    const m = Math.floor((s % 3600) / 60);
                    return h > 0 ? `${h}h ${m}m` : `${m}m`;
                },
                };
            };
        </script>
    @endpush
</x-layout.app>
