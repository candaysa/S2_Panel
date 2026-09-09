<x-layout.app :title="__('i18n::messages.nav.bans')">
    <div
        x-data="{
            loading: true,
            forbidden: false,
            error: false,
            search: '',
            // Server-supplied now (each type has its own URL - see
            // routes/web.php), not Alpine-only state, so a mute list can be
            // linked and bookmarked.
            type: @js($type ?? 'ban'),
            status: 'active',
            // Whether this viewer may issue a punishment from here. The
            // list itself is open to any logged-in player; issuing goes
            // through RCON, so admin.rcon is the real gate - probed by
            // asking the RCON API a harmless question rather than trusting
            // anything the page could set for itself.
            canModerate: false,
            form: {
                open: false,
                mode: 'online',
                target: '',
                manualTarget: '',
                duration: '1440',
                customDuration: false,
                reason: '',
                serverId: '',
                sending: false,
                error: '',
                done: '',
            },
            servers: [],
            onlinePlayers: [],
            playersLoading: false,
            // -1 is the permanent convention both plugins use (see
            // RconService - the duration is passed through verbatim). 0 is
            // a real, near-instant duration, not permanent.
            // NOTE: no double quotes anywhere inside this x-data attribute,
            // not even in a comment - the attribute itself is delimited by
            // one, so a stray quote ends it early and the rest of this
            // object leaks into the DOM as attributes. That is exactly how
            // this page broke once.
            durations: [
                { minutes: -1, label: @js(__('i18n::messages.bans.duration_permanent')) },
                { minutes: 60, label: @js(__('i18n::messages.bans.duration_hour')) },
                { minutes: 1440, label: @js(__('i18n::messages.bans.duration_day')) },
                { minutes: 10080, label: @js(__('i18n::messages.bans.duration_week')) },
                { minutes: 43200, label: @js(__('i18n::messages.bans.duration_month')) },
            ],
            sort: 'id',
            dir: 'desc',
            rows: [],
            page: 1,
            perPage: 50,
            lastPage: 1,
            total: 0,
            types: [
                { key: 'ban', label: @js(__('i18n::messages.bans.type_ban')), url: @js(route('bans.page')), add: @js(__('i18n::messages.bans.add_ban')) },
                { key: 'mute', label: @js(__('i18n::messages.bans.type_mute')), url: @js(route('bans.mute')), add: @js(__('i18n::messages.bans.add_mute')) },
                { key: 'gag', label: @js(__('i18n::messages.bans.type_gag')), url: @js(route('bans.gag')), add: @js(__('i18n::messages.bans.add_gag')) },
                { key: 'warn', label: @js(__('i18n::messages.bans.type_warn')), url: @js(route('bans.warn')), add: @js(__('i18n::messages.bans.add_warn')) },
            ],
            get addLabel() {
                return this.types.find((t) => t.key === this.type)?.add ?? '';
            },
            lifts: {
                ban: { action: 'unban', label: @js(__('i18n::messages.bans.lift_ban')) },
                mute: { action: 'unmute', label: @js(__('i18n::messages.bans.lift_mute')) },
                gag: { action: 'ungag', label: @js(__('i18n::messages.bans.lift_gag')) },
                warn: { action: 'unwarn', label: @js(__('i18n::messages.bans.lift_warn')) },
            },
            get liftAction() {
                return this.lifts[this.type]?.action ?? '';
            },
            get liftLabel() {
                return this.lifts[this.type]?.label ?? '';
            },

            // Lifting goes out as the plugin's own console command, same as
            // issuing does - the plugin updates its record, the panel just
            // re-reads. Targets by SteamID: the player is usually not
            // connected, so a name would not match anything.
            async lift(row) {
                if (!this.liftAction || !this.form.serverId) return;
                this.form.sending = true;
                this.form.error = '';
                this.form.done = '';

                try {
                    const res = await fetch(`/api/rcon/${this.form.serverId}/${this.liftAction}`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({ target: String(row.steamid ?? '') }),
                    });
                    const data = await res.json().catch(() => ({}));

                    if (!res.ok) {
                        this.form.error = window.apiError(res, data, @js(__('i18n::messages.common.error')));
                        return;
                    }

                    this.form.done = @js(__('i18n::messages.bans.add_sent'));
                    this.load();
                } catch (e) {
                    this.form.error = @js(__('i18n::messages.common.error'));
                } finally {
                    this.form.sending = false;
                }
            },
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
            init() {
                this.load();
                this.probeModeration();
            },

            // 200 => this viewer holds admin.rcon (or is the owner);
            // 401/403 => they do not, and the Add button never renders.
            // The server still enforces it on every action - this only
            // decides whether to show a control that would fail anyway.
            async probeModeration() {
                try {
                    const res = await fetch('/api/rcon/settings', { headers: { Accept: 'application/json' } });
                    if (!res.ok) return;
                    this.canModerate = true;
                    const servers = await fetch('/api/servers?per_page=100', { headers: { Accept: 'application/json' } });
                    if (!servers.ok) return;
                    const body = await servers.json();
                    this.servers = body.data ?? [];
                    if (this.servers.length) this.form.serverId = this.servers[0].id;
                } catch (e) {}
            },

            openForm() {
                this.form.open = true;
                this.form.error = '';
                this.form.done = '';
                this.loadOnlinePlayers();
            },

            // A2S reports names, not SteamIDs - which is fine, the plugins'
            // commands take a name as target. Public endpoint, same one the
            // server details page uses.
            async loadOnlinePlayers() {
                this.onlinePlayers = [];
                if (!this.form.serverId) return;

                this.playersLoading = true;

                try {
                    const res = await fetch(`/api/server-details/${this.form.serverId}/players`, { headers: { Accept: 'application/json' } });
                    if (!res.ok) return;
                    const body = await res.json();
                    this.onlinePlayers = (body.data ?? []).filter((p) => (p.name ?? '').trim() !== '');
                } catch (e) {
                } finally {
                    this.playersLoading = false;
                }
            },

            // Accepts a SteamID64, a STEAM_0:x:y, a [U:1:x], or a pasted
            // profile URL (…/profiles/7656… or …/id/vanityname). A vanity
            // URL cannot be resolved without a Steam API round trip, so its
            // name is passed through as typed and the plugin matches it.
            steamIdFrom(value) {
                const raw = (value ?? '').trim();
                if (!raw) return '';

                const profile = raw.match(/steamcommunity\.com\/profiles\/(\d{17})/i);
                if (profile) return profile[1];

                const vanity = raw.match(/steamcommunity\.com\/id\/([^/?#]+)/i);
                if (vanity) return vanity[1];

                return raw;
            },

            // warn takes no duration (the plugin owns how long one counts
            // for) - see RconService::warn().
            get formNeedsDuration() {
                return this.type !== 'warn';
            },

            async submitPunishment() {
                if (!this.form.target.trim() || !this.form.serverId) return;
                this.form.sending = true;
                this.form.error = '';
                this.form.done = '';

                const payload = { target: this.form.target.trim() };
                if (this.formNeedsDuration) payload.duration = String(this.form.duration || '-1');
                if (this.form.reason.trim()) payload.reason = this.form.reason.trim();

                try {
                    const res = await fetch(`/api/rcon/${this.form.serverId}/${this.type}`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify(payload),
                    });
                    const data = await res.json().catch(() => ({}));

                    if (!res.ok) {
                        this.form.error = window.apiError(res, data, @js(__('i18n::messages.common.error')));
                        return;
                    }

                    this.form.done = @js(__('i18n::messages.bans.add_sent'));
                    this.form.target = '';
                    this.form.reason = '';
                    // The plugin writes the row itself, so the list only
                    // catches up once it has - reload rather than pretend.
                    this.load(true);
                } catch (e) {
                    this.form.error = @js(__('i18n::messages.common.error'));
                } finally {
                    this.form.sending = false;
                }
            },
            formatDate(value) {
                return value ? new Date(value).toLocaleString() : '—';
            },
            // A Steam profile URL, or '' when there is no real account behind
            // the id - a console-issued punishment records admin_steamid 0.
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
        }"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.bans') }}</h1>

            <div class="flex w-full items-center gap-2 sm:w-auto">
                {{-- Staff only, and immediately left of the search bar. --}}
                <button
                    type="button"
                    x-show="canModerate"
                    x-cloak
                    @click="openForm()"
                    class="shrink-0 rounded-lg bg-brand-strong px-3 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90"
                    x-text="addLabel"
                ></button>

                <input
                    type="search"
                    x-model="search"
                    @input.debounce.350ms="load(true)"
                    placeholder="{{ __('i18n::messages.bans.search_placeholder') }}"
                    class="w-full max-w-xs rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none sm:w-64"
                >
            </div>
        </div>

        {{-- Feedback from a row-level lift, which happens with the form
             below closed. --}}
        <p x-show="!form.open && form.error" x-cloak class="mt-3 text-sm text-red-400" x-text="form.error"></p>
        <p x-show="!form.open && form.done" x-cloak class="mt-3 text-sm text-emerald-400" x-text="form.done"></p>

        {{-- Issue a punishment. Goes out as an RCON console command, so the
             game server applies it and its own plugin writes the record -
             the panel never writes to the plugin's tables itself. --}}
        <div
            x-show="form.open"
            x-cloak
            @keydown.escape.window="form.open = false"
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 sm:p-8"
        >
            <div @click.outside="form.open = false" class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl">
                <div class="flex items-center justify-between border-b border-line px-5 py-3.5">
                    <p class="text-sm font-semibold text-ink" x-text="addLabel"></p>
                    <button type="button" @click="form.open = false" class="text-sm text-ink-faint hover:text-ink">
                        {{ __('i18n::messages.common.close') }}
                    </button>
                </div>

                <div class="space-y-4 px-5 py-4">
                    <div>
                        <label class="text-xs font-medium text-ink-muted">{{ __('i18n::messages.rcon.select_server') }}</label>
                        <select x-model="form.serverId" @change="loadOnlinePlayers()" class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                            <template x-for="s in servers" :key="s.id">
                                <option :value="s.id" x-text="s.server_ip + ':' + s.server_port"></option>
                            </template>
                        </select>
                    </div>

                    {{-- Two ways to name the target: pick someone who is on
                         the server right now (A2S only reports names, which
                         is what the plugin's commands take anyway), or type
                         a SteamID / paste a profile link for someone who is
                         not connected. --}}
                    <div class="flex gap-1.5">
                        <button type="button" @click="form.mode = 'online'; loadOnlinePlayers()"
                            :class="form.mode === 'online' ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium transition-colors">
                            {{ __('i18n::messages.bans.target_online') }}
                        </button>
                        <button type="button" @click="form.mode = 'manual'"
                            :class="form.mode === 'manual' ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium transition-colors">
                            {{ __('i18n::messages.bans.target_manual') }}
                        </button>
                    </div>

                    <div x-show="form.mode === 'online'">
                        <select x-model="form.target" class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                            <option value="">{{ __('i18n::messages.bans.target_pick') }}</option>
                            <template x-for="p in onlinePlayers" :key="p.name">
                                <option :value="p.name" x-text="p.name"></option>
                            </template>
                        </select>
                        <p x-show="playersLoading" class="mt-1 text-xs text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
                        <p x-show="!playersLoading && onlinePlayers.length === 0" class="mt-1 text-xs text-ink-faint">{{ __('i18n::messages.bans.target_none_online') }}</p>
                    </div>

                    <div x-show="form.mode === 'manual'">
                        <input
                            type="text"
                            x-model="form.manualTarget"
                            @input="form.target = steamIdFrom(form.manualTarget)"
                            placeholder="{{ __('i18n::messages.bans.target_manual_placeholder') }}"
                            class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                        >
                        {{-- A pasted profile URL is reduced to the id it
                             contains, so the command gets an id and not a
                             URL the plugin would treat as a name. --}}
                        <p x-show="form.target && form.target !== form.manualTarget" x-cloak class="mt-1 text-xs text-ink-faint">
                            <span x-text="form.target"></span>
                        </p>
                    </div>

                    <div x-show="formNeedsDuration">
                        <label class="text-xs font-medium text-ink-muted">{{ __('i18n::messages.rcon.duration_minutes') }}</label>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            <template x-for="d in durations" :key="d.minutes">
                                <button type="button" @click="form.duration = d.minutes; form.customDuration = false"
                                    :class="!form.customDuration && String(form.duration) === String(d.minutes) ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                                    class="rounded-lg border border-line px-2.5 py-1 text-xs font-medium transition-colors"
                                    x-text="d.label"></button>
                            </template>
                            <button type="button" @click="form.customDuration = true"
                                :class="form.customDuration ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                                class="rounded-lg border border-line px-2.5 py-1 text-xs font-medium transition-colors">
                                {{ __('i18n::messages.bans.duration_custom') }}
                            </button>
                        </div>
                        <input
                            x-show="form.customDuration"
                            x-cloak
                            type="number"
                            min="0"
                            x-model="form.duration"
                            placeholder="{{ __('i18n::messages.rcon.duration_minutes') }}"
                            class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                        >
                    </div>

                    <div>
                        <label class="text-xs font-medium text-ink-muted">{{ __('i18n::messages.rcon.reason') }}</label>
                        <input type="text" x-model="form.reason" class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    </div>

                    <p x-show="form.error" x-cloak class="text-sm text-red-400" x-text="form.error"></p>
                    <p x-show="form.done" x-cloak class="text-sm text-emerald-400" x-text="form.done"></p>
                    <p class="text-xs text-ink-faint">{{ __('i18n::messages.bans.add_hint') }}</p>
                </div>

                <div class="flex justify-end gap-2 border-t border-line px-5 py-3.5">
                    <button type="button" @click="form.open = false" class="rounded-lg border border-line px-3 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                        {{ __('i18n::messages.common.cancel') }}
                    </button>
                    <button type="button" :disabled="form.sending || !form.target.trim()" @click="submitPunishment()" class="rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50">
                        <span x-text="addLabel"></span>
                    </button>
                </div>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            {{-- Real links, one URL per type - so a mute list can be shared
                 or bookmarked. --}}
            <div class="flex flex-wrap gap-1.5">
                <template x-for="t in types" :key="t.key">
                    <a
                        :href="t.url"
                        :class="type === t.key ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                        x-text="t.label"
                    ></a>
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
                        <th class="px-4 py-3 text-right" x-show="canModerate" x-cloak>{{ __('i18n::messages.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="row in rows" :key="row.id">
                        <tr class="text-ink-muted">
                            {{-- Both sides link to Steam. The name and the
                                 SteamID were already on screen and reading as
                                 clickable, but nothing was: looking a player
                                 up meant copying 17 digits out by hand. No
                                 link when there is no real account behind the
                                 id - a console-issued punishment records no
                                 admin, and a link to nobody is worse than
                                 none because it still looks like one. --}}
                            <td class="px-4 py-3">
                                <a
                                    :href="profileUrl(row.steamid)"
                                    :target="profileUrl(row.steamid) ? '_blank' : null"
                                    rel="noopener noreferrer"
                                    class="flex items-center gap-2.5"
                                >
                                    <template x-if="row.avatar">
                                        <img :src="row.avatar" alt="" class="size-7 shrink-0 rounded-full">
                                    </template>
                                    <template x-if="!row.avatar">
                                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-raised text-xs font-semibold text-ink-muted" x-text="(row.target_name || '?').charAt(0).toUpperCase()"></span>
                                    </template>
                                    <div class="min-w-0">
                                        <span class="block truncate font-medium text-ink" :class="profileUrl(row.steamid) ? 'transition-colors hover:text-brand-strong' : ''" x-text="row.target_name || '—'"></span>
                                        <span class="block font-mono text-xs text-ink-faint" x-text="row.steamid || '—'"></span>
                                    </div>
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                <a
                                    :href="profileUrl(row.admin_steamid)"
                                    :target="profileUrl(row.admin_steamid) ? '_blank' : null"
                                    rel="noopener noreferrer"
                                    class="block min-w-0"
                                >
                                    <span class="block truncate" :class="profileUrl(row.admin_steamid) ? 'text-ink transition-colors hover:text-brand-strong' : ''" x-text="row.admin_name || '—'"></span>
                                    <span x-show="row.admin_steamid && row.admin_steamid !== '0'" class="block truncate font-mono text-[11px] text-ink-faint" x-text="row.admin_steamid"></span>
                                </a>
                            </td>
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
                            {{-- Lift, staff only. Only offered on rows that
                                 are actually in force - lifting an expired
                                 punishment is a command the server would
                                 just refuse. --}}
                            <td class="px-4 py-3 text-right" x-show="canModerate" x-cloak>
                                <button
                                    type="button"
                                    x-show="row.status === 'active' && liftAction"
                                    :disabled="form.sending"
                                    @click="lift(row)"
                                    class="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink disabled:opacity-50"
                                    x-text="liftLabel"
                                ></button>
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
