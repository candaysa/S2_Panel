<x-layout.app :title="__('i18n::messages.ranks.profile')">
    <div x-data="playerProfile(@js($steam))" x-init="init()">
        <a href="{{ route('ranks.page') }}" class="inline-flex items-center gap-1.5 text-sm text-ink-muted transition-colors hover:text-ink">
            <x-icon name="chevron-left" class="size-4" />
            {{ __('i18n::messages.ranks.back_to_ranks') }}
        </a>

        <p x-show="loading" x-cloak class="mt-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
        <p x-show="notFound" x-cloak class="mt-8 rounded-xl border border-line bg-surface px-4 py-10 text-center text-sm text-ink-faint">
            {{ __('i18n::messages.ranks.not_found') }}
        </p>
        <p x-show="error" x-cloak class="mt-8 text-center text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>

        <template x-if="player">
            <div class="mt-4">
                {{-- Identity --}}
                <div class="rounded-xl border border-line bg-surface p-5 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-3">
                                <h1 class="truncate text-2xl font-semibold text-ink" x-text="player.name || '—'"></h1>
                                <x-rank-badge rank="player.rank_tier" label="rankLabel()" size="lg" show-label />
                            </div>
                            <p class="mt-1 font-mono text-xs text-ink-faint" x-text="player.steam"></p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.ranks.position') }}</p>
                            <p class="text-2xl font-semibold text-brand-strong" x-text="'#' + player.position"></p>
                        </div>
                    </div>

                    {{-- Progress toward the next tier: the ladder is derived
                         from points, so this is the one number that explains
                         the badge above it. --}}
                    <div class="mt-5">
                        <div class="flex items-baseline justify-between text-xs">
                            <span class="text-ink-muted" x-text="num(player.value) + ' {{ __('i18n::messages.ranks.points') }}'"></span>
                            <span class="text-ink-faint" x-text="tierProgressLabel()"></span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-raised">
                            <div class="h-full rounded-full bg-brand-strong transition-all" :style="'width:' + tierProgress() + '%'"></div>
                        </div>
                    </div>
                </div>

                {{-- Core stats --}}
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <template x-for="stat in headline" :key="stat.label">
                        <div class="rounded-xl border border-line bg-surface p-4">
                            <p class="text-xs uppercase tracking-wider text-ink-faint" x-text="stat.label"></p>
                            <p class="mt-1 text-xl font-semibold text-ink" x-text="stat.value"></p>
                        </div>
                    </template>
                </div>

                {{-- Detail grid --}}
                <div class="mt-4 rounded-xl border border-line bg-surface p-5">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3 lg:grid-cols-4">
                        <template x-for="row in detail" :key="row.label">
                            <div>
                                <dt class="text-xs uppercase tracking-wider text-ink-faint" x-text="row.label"></dt>
                                <dd class="mt-0.5 text-sm font-medium text-ink" x-text="row.value"></dd>
                            </div>
                        </template>
                    </dl>
                </div>
            </div>
        </template>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.playerProfile = (steam) => ({
                loading: true,
                error: false,
                notFound: false,
                player: null,
                hits: null,
                labels: @js(__('i18n::messages.rank_tiers')),
                t: @js(__('i18n::messages.ranks')),

                async init() {
                    try {
                        const res = await fetch(`/api/ranks/${encodeURIComponent(steam)}`, { headers: { Accept: 'application/json' } });
                        if (res.status === 404) { this.notFound = true; return; }
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.player = body.data.player ?? body.data;
                        this.hits = body.data.hits ?? null;
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                rankLabel() {
                    return this.labels[this.player?.rank_tier?.key] ?? this.labels.unranked;
                },

                num(v) { return (v ?? 0).toLocaleString(); },

                kd() {
                    const p = this.player;
                    if (!p?.deaths) return (p?.kills ?? 0).toFixed(2);
                    return (p.kills / p.deaths).toFixed(2);
                },

                hours(seconds) {
                    const h = Math.floor((seconds ?? 0) / 3600);
                    return h >= 1 ? h.toLocaleString() + this.t.hours_short : Math.floor((seconds ?? 0) / 60) + this.t.minutes_short;
                },

                accuracy() {
                    const p = this.player;
                    if (!p?.shoots) return '—';
                    return ((p.hits / p.shoots) * 100).toFixed(1) + '%';
                },

                // Where this player sits between their current tier's floor
                // and the next one's. Top tier has no next, so it reads full.
                tierProgress() {
                    const ladder = @js(collect(config('rank.ladder'))->pluck(0)->all());
                    const pts = this.player?.value ?? 0;
                    let floor = 0, ceil = null;
                    for (const min of ladder) {
                        if (pts >= min) floor = min;
                        else { ceil = min; break; }
                    }
                    if (ceil === null) return 100;
                    if (pts < 0) return 0;
                    return Math.max(0, Math.min(100, ((pts - floor) / (ceil - floor)) * 100));
                },

                tierProgressLabel() {
                    const ladder = @js(collect(config('rank.ladder'))->pluck(0)->all());
                    const pts = this.player?.value ?? 0;
                    for (const min of ladder) {
                        if (pts < min) return '+' + (min - pts).toLocaleString();
                    }
                    return '';
                },

                get headline() {
                    const p = this.player;
                    return [
                        { label: this.t.points, value: this.num(p.value) },
                        { label: this.t.kills, value: this.num(p.kills) },
                        { label: this.t.kd, value: this.kd() },
                        { label: this.t.playtime, value: this.hours(p.playtime) },
                    ];
                },

                get detail() {
                    const p = this.player;
                    return [
                        { label: this.t.deaths, value: this.num(p.deaths) },
                        { label: this.t.assists, value: this.num(p.assists) },
                        { label: this.t.headshots, value: this.num(p.headshots) },
                        { label: this.t.accuracy, value: this.accuracy() },
                        { label: this.t.damage, value: this.num(p.damage) },
                        { label: this.t.rounds, value: this.num(p.rounds_played) },
                        { label: this.t.wins, value: this.num(p.game_wins) },
                        { label: this.t.losses, value: this.num(p.game_losses) },
                    ];
                },
            });
        </script>
    @endpush
</x-layout.app>
