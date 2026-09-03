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
            <div class="mt-4 space-y-4">
                {{-- Identity --}}
                <div class="overflow-hidden rounded-xl border border-line bg-surface">
                    <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:p-6">
                        <img x-show="player.avatar" :src="player.avatar" alt="" class="size-20 shrink-0 rounded-xl object-cover ring-1 ring-line">
                        <span x-show="!player.avatar" class="flex size-20 shrink-0 items-center justify-center rounded-xl bg-surface-raised text-2xl font-semibold text-ink-faint" x-text="(player.name ?? '?').charAt(0).toUpperCase()"></span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-3">
                                <h1 class="truncate text-2xl font-semibold text-ink" x-text="player.name || '—'"></h1>
                                <x-rank-badge rank="player.rank_tier" label="rankLabel()" size="lg" show-label />
                            </div>
                            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-faint">
                                <span class="font-mono" x-text="player.steam"></span>
                                <a :href="'https://steamcommunity.com/profiles/' + steam64" target="_blank" rel="noopener noreferrer"
                                   class="inline-flex items-center gap-1 text-brand-strong hover:underline">
                                    <x-icon name="steam" class="size-3.5" />
                                    {{ __('i18n::messages.ranks.steam_profile') }}
                                </a>
                                <span x-show="player.lastconnect" x-text="lastSeen()"></span>
                            </p>
                        </div>

                        <div class="shrink-0 text-left sm:text-right">
                            <p class="text-xs uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.ranks.position') }}</p>
                            <p class="text-3xl font-semibold text-brand-strong" x-text="'#' + player.position"></p>
                            <p class="text-sm text-ink-muted"><span x-text="num(player.value)"></span> {{ __('i18n::messages.ranks.points') }}</p>
                        </div>
                    </div>

                    {{-- Progress toward the next tier: the ladder is derived
                         from points, so this is the one number that explains
                         the badge above it. --}}
                    <div class="border-t border-line-soft px-5 py-4 sm:px-6">
                        <div class="flex items-baseline justify-between text-xs">
                            <span class="text-ink-muted" x-text="tierNow()"></span>
                            <span class="text-ink-faint" x-text="tierProgressLabel()"></span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-raised">
                            <div class="h-full rounded-full bg-brand-strong transition-all" :style="'width:' + tierProgress() + '%'"></div>
                        </div>
                    </div>
                </div>

                {{-- Headline figures --}}
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <template x-for="stat in headline" :key="stat.label">
                        <div class="rounded-xl border border-line bg-surface bg-gradient-to-br p-4" :class="stat.wash">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-[11px] uppercase tracking-wider text-ink-faint" x-text="stat.label"></p>
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg" :class="stat.chip">
                                    <span :class="stat.fg" x-html="stat.icon"></span>
                                </span>
                            </div>
                            <p class="mt-1.5 text-2xl font-semibold text-ink" x-text="stat.value"></p>
                            <p class="text-xs text-ink-faint" x-text="stat.hint"></p>
                        </div>
                    </template>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    {{-- Combat detail --}}
                    <div class="rounded-xl border border-line bg-surface">
                        <div class="border-b border-line px-5 py-3.5">
                            <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.ranks.combat') }}</h2>
                        </div>
                        <dl class="divide-y divide-line-soft">
                            <template x-for="row in combat" :key="row.label">
                                <div class="flex items-center justify-between px-5 py-2.5">
                                    <dt class="text-sm text-ink-muted" x-text="row.label"></dt>
                                    <dd class="text-sm font-medium tabular-nums text-ink" x-text="row.value"></dd>
                                </div>
                            </template>
                        </dl>
                    </div>

                    {{-- Round record: win/lose as a single bar, which reads
                         faster than two numbers side by side. --}}
                    <div class="rounded-xl border border-line bg-surface">
                        <div class="border-b border-line px-5 py-3.5">
                            <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.ranks.round_record') }}</h2>
                        </div>
                        <div class="px-5 py-4">
                            <div class="flex items-baseline justify-between text-sm">
                                <span class="font-medium text-emerald-400"><span x-text="num(player.round_win)"></span> {{ __('i18n::messages.ranks.wins') }}</span>
                                <span class="font-medium text-ink-faint">{{ __('i18n::messages.ranks.losses') }} <span x-text="num(player.round_lose)"></span></span>
                            </div>
                            <div class="mt-2 flex h-2 overflow-hidden rounded-full bg-surface-raised">
                                <div class="bg-emerald-400 transition-all" :style="'width:' + winRate() + '%'"></div>
                            </div>
                            <p class="mt-2 text-xs text-ink-faint">
                                <span x-text="winRate().toFixed(1)"></span>% &middot;
                                <span x-text="num(player.rounds_played)"></span> {{ __('i18n::messages.ranks.rounds') }}
                            </p>

                            <dl class="mt-4 space-y-2 border-t border-line-soft pt-4">
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-ink-muted">{{ __('i18n::messages.ranks.time_on_server') }}</dt>
                                    <dd class="text-sm font-medium text-ink" x-text="hours(player.playtime)"></dd>
                                </div>
                                <div class="flex items-center justify-between" x-show="hits">
                                    <dt class="text-sm text-ink-muted">{{ __('i18n::messages.ranks.dmg_health') }}</dt>
                                    <dd class="text-sm font-medium tabular-nums text-ink" x-text="num(hits?.DmgHealth)"></dd>
                                </div>
                                <div class="flex items-center justify-between" x-show="hits">
                                    <dt class="text-sm text-ink-muted">{{ __('i18n::messages.ranks.dmg_armor') }}</dt>
                                    <dd class="text-sm font-medium tabular-nums text-ink" x-text="num(hits?.DmgArmor)"></dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>

                {{-- Hitbox breakdown. The plugin records this and nothing in
                     the panel used it - it is the most telling thing here,
                     since a head-heavy distribution is what separates an aim
                     player from a spray player. --}}
                <div class="rounded-xl border border-line bg-surface" x-show="hits && hitTotal > 0" x-cloak>
                    <div class="flex items-center justify-between border-b border-line px-5 py-3.5">
                        <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.ranks.hit_distribution') }}</h2>
                        <span class="text-xs text-ink-faint">{{ __('i18n::messages.ranks.of_total') }}</span>
                    </div>
                    <div class="space-y-3 px-5 py-4">
                        <template x-for="zone in hitZones" :key="zone.label">
                            <div>
                                <div class="flex items-baseline justify-between text-xs">
                                    <span class="text-ink-muted" x-text="zone.label"></span>
                                    <span class="tabular-nums text-ink-faint">
                                        <span class="font-medium text-ink" x-text="num(zone.value)"></span>
                                        <span x-text="' · ' + zone.pct.toFixed(1) + '%'"></span>
                                    </span>
                                </div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-raised">
                                    <div class="h-full rounded-full transition-all" :class="zone.color" :style="'width:' + zone.pct + '%'"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Per-weapon breakdown - new with K4-LevelRanks-SwiftlyS2's
                     optional WeaponStats module (lvl_base_weapons); the old
                     Swiftly CS2_Ranks schema had no equivalent, so this
                     simply doesn't render for a profile the module never
                     tracked. --}}
                <div class="rounded-xl border border-line bg-surface" x-show="weapons.length > 0" x-cloak>
                    <div class="border-b border-line px-5 py-3.5">
                        <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.ranks.top_weapons') }}</h2>
                    </div>
                    <dl class="divide-y divide-line-soft">
                        <template x-for="w in weapons.slice(0, 5)" :key="w.classname">
                            <div class="flex items-center justify-between px-5 py-2.5">
                                <dt class="text-sm text-ink-muted" x-text="weaponLabel(w.classname)"></dt>
                                <dd class="text-sm font-medium tabular-nums text-ink">
                                    <span x-text="num(w.kills)"></span> {{ __('i18n::messages.ranks.kills') }}
                                    <span class="text-ink-faint">&middot; <span x-text="num(w.headshots)"></span> {{ __('i18n::messages.ranks.headshots') }}</span>
                                </dd>
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
                weapons: [],
                steam64: '',
                t: @js(__('i18n::messages.ranks')),
                // Point thresholds ladder, now server-defined (ranks.json via
                // RankCatalogService) - comes back inside the profile
                // response itself rather than a static config() value, since
                // it can genuinely differ per install.
                ladder: [],

                async init() {
                    try {
                        const res = await fetch(`/api/ranks/${encodeURIComponent(steam)}`, { headers: { Accept: 'application/json' } });
                        if (res.status === 404) { this.notFound = true; return; }
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.player = body.data.player ?? body.data;
                        this.hits = body.data.hits ?? null;
                        this.weapons = body.data.weapons ?? [];
                        this.ladder = body.data.ranks_ladder ?? [];
                        this.steam64 = this.toSteam64(this.player.steam);
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                // STEAM_0:Y:Z -> 64-bit id, so the profile can link out.
                toSteam64(steam2) {
                    const m = /^STEAM_[0-5]:([01]):(\d+)$/.exec(steam2 ?? '');
                    if (!m) return '';
                    return (BigInt(m[2]) * 2n + BigInt(m[1]) + 76561197960265728n).toString();
                },

                rankLabel() {
                    return this.player?.rank_tier?.label || this.t.unranked;
                },

                // "weapon_ak47" -> "Ak47" - no catalog lookup, just enough
                // to be readable; the Skin module's own weapon labels live
                // behind a session-gated API this public page has no access
                // to anyway.
                weaponLabel(classname) {
                    return (classname ?? '').replace(/^weapon_/, '').replace(/_/g, ' ')
                        .replace(/\b\w/g, (c) => c.toUpperCase());
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

                hsPercent() {
                    const p = this.player;
                    if (!p?.kills) return '—';
                    return ((p.headshots / p.kills) * 100).toFixed(1) + '%';
                },

                winRate() {
                    const p = this.player;
                    const total = (p?.round_win ?? 0) + (p?.round_lose ?? 0);
                    return total ? (p.round_win / total) * 100 : 0;
                },

                lastSeen() {
                    const unix = this.player?.lastconnect;
                    if (!unix) return '';
                    const days = Math.floor((Date.now() / 1000 - unix) / 86400);
                    if (days <= 0) return this.t.seen_today;
                    if (days === 1) return this.t.seen_yesterday;
                    return this.t.seen_days_ago.replace(':days', days);
                },

                tierNow() {
                    return this.num(this.player?.value) + ' ' + this.t.points;
                },

                // Where this player sits between their current tier's floor
                // and the next one's. Top tier has no next, so it reads full.
                tierProgress() {
                    const pts = this.player?.value ?? 0;
                    let floor = 0, ceil = null;
                    for (const min of this.ladder) {
                        if (pts >= min) floor = min;
                        else { ceil = min; break; }
                    }
                    if (ceil === null) return 100;
                    if (pts < 0) return 0;
                    return Math.max(0, Math.min(100, ((pts - floor) / (ceil - floor)) * 100));
                },

                tierProgressLabel() {
                    const pts = this.player?.value ?? 0;
                    for (const min of this.ladder) {
                        if (pts < min) return '+' + (min - pts).toLocaleString();
                    }
                    return '';
                },

                icon(path) {
                    return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="size-4">${path}</svg>`;
                },

                get headline() {
                    const p = this.player;
                    return [
                        {
                            label: this.t.kills, value: this.num(p.kills), hint: this.num(p.deaths) + ' ' + this.t.deaths,
                            wash: 'from-emerald-500/[0.07] to-transparent', chip: 'bg-emerald-500/10', fg: 'text-emerald-400',
                            icon: this.icon('<circle cx="12" cy="12" r="7.5"/><path d="M12 2.5v4M12 17.5v4M2.5 12h4M17.5 12h4"/>'),
                        },
                        {
                            label: this.t.kd, value: this.kd(), hint: this.t.assists + ': ' + this.num(p.assists),
                            wash: 'from-sky-500/[0.07] to-transparent', chip: 'bg-sky-500/10', fg: 'text-sky-400',
                            icon: this.icon('<path d="M4 19.5V9M9.5 19.5V4.5M15 19.5v-7M20.5 19.5V7"/>'),
                        },
                        {
                            label: this.t.hs_percent, value: this.hsPercent(), hint: this.num(p.headshots) + ' ' + this.t.headshots,
                            wash: 'from-amber-500/[0.07] to-transparent', chip: 'bg-amber-500/10', fg: 'text-amber-400',
                            icon: this.icon('<circle cx="12" cy="8" r="4"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>'),
                        },
                        {
                            label: this.t.accuracy, value: this.accuracy(), hint: this.num(p.shoots) + ' → ' + this.num(p.hits),
                            wash: 'from-violet-500/[0.07] to-transparent', chip: 'bg-violet-500/10', fg: 'text-violet-400',
                            icon: this.icon('<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/>'),
                        },
                    ];
                },

                get combat() {
                    const p = this.player;
                    return [
                        { label: this.t.kills, value: this.num(p.kills) },
                        { label: this.t.deaths, value: this.num(p.deaths) },
                        { label: this.t.assists, value: this.num(p.assists) },
                        { label: this.t.headshots, value: this.num(p.headshots) },
                        { label: this.t.damage, value: this.num(p.damage) },
                        { label: this.t.accuracy, value: this.accuracy() },
                    ];
                },

                get hitTotal() {
                    const h = this.hits;
                    if (!h) return 0;
                    return ['Head', 'Chest', 'Belly', 'LeftArm', 'RightArm', 'LeftLeg', 'RightLeg', 'Neak']
                        .reduce((sum, k) => sum + (h[k] ?? 0), 0);
                },

                get hitZones() {
                    const h = this.hits;
                    if (!h) return [];
                    const total = this.hitTotal || 1;
                    // Left/right are summed: which arm was hit is noise, the
                    // limb-vs-head split is the signal.
                    const zones = [
                        { label: this.t.head, value: h.Head ?? 0, color: 'bg-red-400' },
                        { label: this.t.neck, value: h.Neak ?? 0, color: 'bg-orange-400' },
                        { label: this.t.chest, value: h.Chest ?? 0, color: 'bg-amber-400' },
                        { label: this.t.belly, value: h.Belly ?? 0, color: 'bg-yellow-400' },
                        { label: this.t.arms, value: (h.LeftArm ?? 0) + (h.RightArm ?? 0), color: 'bg-sky-400' },
                        { label: this.t.legs, value: (h.LeftLeg ?? 0) + (h.RightLeg ?? 0), color: 'bg-violet-400' },
                    ];
                    return zones.map((z) => ({ ...z, pct: (z.value / total) * 100 }));
                },
            });
        </script>
    @endpush
</x-layout.app>
