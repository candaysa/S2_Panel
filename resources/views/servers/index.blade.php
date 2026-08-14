@php $isOwner = \App\Support\Access::isOwner(); @endphp

<x-layout.app :title="__('i18n::messages.nav.servers')">
    <div x-data="serverList(@js($isOwner))" x-init="init()">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.servers') }}</h1>
                <p class="mt-1 text-sm text-ink-faint">
                    <span x-text="onlineCount"></span> / <span x-text="servers.length"></span>
                    {{ __('i18n::messages.servers.online_of_total') }}
                    <span x-show="totalPlayers > 0"> &middot; <span x-text="totalPlayers"></span> {{ __('i18n::messages.servers.players_in_game') }}</span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if ($isOwner)
                    <button type="button" @click="manage = !manage; load()" class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm transition-colors hover:bg-surface-raised" :class="manage ? 'text-brand-strong border-brand-strong/40' : 'text-ink-muted hover:text-ink'">
                        <x-icon name="cog" class="size-4" />
                        {{ __('i18n::messages.servers.manage') }}
                    </button>
                @endif
                <button type="button" @click="load(true)" :disabled="loading" class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink disabled:opacity-50">
                    <x-icon name="refresh" class="size-4" ::class="loading && 'animate-spin'" />
                    {{ __('i18n::messages.servers.refresh') }}
                </button>
            </div>
        </div>

        @if ($isOwner)
            {{-- Management: owner-only, and the API enforces that too
                 (owner.only on the mutating routes) - this is just the UI. --}}
            <div x-show="manage" x-cloak x-transition class="mt-4 rounded-xl border border-line bg-surface">
                <div class="flex flex-wrap items-end gap-2 border-b border-line p-4">
                    <div class="min-w-0 flex-1">
                        <label class="mb-1 block text-[11px] uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.servers.ip_placeholder') }}</label>
                        <input type="text" x-model="newIp" placeholder="127.0.0.1" class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    </div>
                    <div class="w-28">
                        <label class="mb-1 block text-[11px] uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.servers.port_placeholder') }}</label>
                        <input type="number" x-model="newPort" placeholder="27015" class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    </div>
                    <button type="button" :disabled="adding" @click="addServer()" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50">
                        <x-icon name="plus" class="size-4" />
                        {{ __('i18n::messages.servers.add') }}
                    </button>
                </div>

                <p x-show="manageError" x-cloak class="border-b border-line px-4 py-2 text-sm text-red-400" x-text="manageError"></p>

                <ul class="divide-y divide-line-soft">
                    <template x-for="server in servers" :key="'m' + server.id">
                        <li class="flex flex-wrap items-center gap-3 px-4 py-3">
                            <span class="size-2 shrink-0 rounded-full" :class="server.online ? 'bg-emerald-400' : 'bg-ink-faint/40'"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm text-ink" x-text="server.live?.name || address(server)"></p>
                                <p class="truncate font-mono text-xs text-ink-faint" x-text="address(server)"></p>
                            </div>

                            {{-- Visible/hidden switch --}}
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="(!server.hidden).toString()"
                                @click="toggleHidden(server)"
                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                :class="server.hidden ? 'bg-surface-raised' : 'bg-brand-strong'"
                                :title="server.hidden ? @js(__('i18n::messages.servers.show')) : @js(__('i18n::messages.servers.hide'))"
                            >
                                <span class="inline-block size-3.5 rounded-full bg-canvas transition-transform" :class="server.hidden ? 'translate-x-1' : 'translate-x-4.5'"></span>
                            </button>
                            <span class="w-14 shrink-0 text-xs" :class="server.hidden ? 'text-ink-faint' : 'text-ink-muted'" x-text="server.hidden ? @js(__('i18n::messages.servers.hidden')) : @js(__('i18n::messages.servers.visible'))"></span>

                            <button type="button" @click="removeServer(server)" class="shrink-0 rounded-lg p-2 text-ink-faint transition-colors hover:bg-red-500/10 hover:text-red-400" :title="@js(__('i18n::messages.servers.remove'))">
                                <x-icon name="trash" class="size-4" />
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        @endif

        <p x-show="loading && servers.length === 0" x-cloak class="mt-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
        <p x-show="error" x-cloak class="mt-8 text-center text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
        <p x-show="!loading && !error && servers.length === 0" x-cloak class="mt-8 rounded-xl border border-line bg-surface px-4 py-10 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>

        {{-- name / map / ip / players / connect as aligned columns, no header
             row. Same shape as the dashboard list so the two never look like
             different features. --}}
        <div class="mt-5 overflow-hidden rounded-xl border border-line bg-surface">
            <ul class="divide-y divide-line-soft">
                <template x-for="server in sorted" :key="server.id">
                    <li
                        class="grid grid-cols-[auto_1fr_auto_auto] items-center gap-x-4 px-4 py-3 transition-colors hover:bg-surface-raised sm:grid-cols-[auto_minmax(0,1fr)_9rem_auto_auto] lg:grid-cols-[auto_minmax(0,1fr)_10rem_11rem_auto_auto_auto]"
                        :class="!server.online && 'opacity-60'"
                    >
                        <span class="size-2 shrink-0 rounded-full" :class="server.online ? 'bg-emerald-400' : 'bg-ink-faint/40'"></span>

                        {{-- Real hostname when the server answered A2S; the
                             address is the honest fallback, since a name we
                             never received should not be invented. --}}
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-ink" x-text="server.live?.name || address(server)"></p>
                            {{-- Map and ip collapse into the name cell on the
                                 narrow layouts where their columns are gone. --}}
                            <p class="truncate text-xs text-ink-faint sm:hidden">
                                <span x-text="server.live?.map || '—'"></span>
                                <span class="font-mono opacity-70"> &middot; <span x-text="address(server)"></span></span>
                            </p>
                            <p class="hidden truncate font-mono text-xs text-ink-faint sm:block lg:hidden" x-text="address(server)"></p>
                        </div>

                        <p class="hidden truncate text-sm text-ink-muted sm:block" x-text="server.live?.map || '—'"></p>

                        <p class="hidden truncate font-mono text-xs text-ink-faint lg:block" x-text="address(server)"></p>

                        <div class="whitespace-nowrap text-right">
                            <p class="text-sm tabular-nums" :class="server.online ? 'text-ink-muted' : 'text-ink-faint'"
                               x-text="server.live ? server.live.players + ' / ' + server.live.max_players : '—'"></p>
                            <div class="mt-1 h-1 w-16 overflow-hidden rounded-full bg-surface-raised">
                                <div class="h-full rounded-full bg-brand-strong transition-all" :style="'width:' + fill(server) + '%'"></div>
                            </div>
                        </div>

                        {{-- steam://connect hands off to the game client. Only
                             offered when the server actually answered - a
                             connect button for a dead host is a dead end. --}}
                        <a
                            x-show="server.online"
                            :href="'steam://connect/' + address(server)"
                            class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-strong px-3 py-1.5 text-sm font-medium text-canvas transition-opacity hover:opacity-90"
                        >
                            <x-icon name="play" class="size-4" />
                            <span class="hidden sm:inline">{{ __('i18n::messages.servers.connect') }}</span>
                        </a>
                        <span x-show="!server.online" class="justify-self-end text-xs text-ink-faint">
                            {{ __('i18n::messages.servers.offline') }}
                        </span>

                        <button
                            type="button"
                            @click="copy(server)"
                            class="hidden items-center justify-center rounded-lg border border-line p-1.5 text-ink-faint transition-colors hover:bg-surface-raised hover:text-ink lg:inline-flex"
                            :title="copied === server.id ? @js(__('i18n::messages.servers.copied')) : @js(__('i18n::messages.servers.copy_ip'))"
                        >
                            <x-icon name="copy" class="size-4" />
                        </button>
                    </li>
                </template>
            </ul>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.serverList = (isOwner) => ({
                loading: true,
                error: false,
                servers: [],
                copied: null,
                isOwner,
                manage: false,
                manageError: '',
                newIp: '',
                newPort: '',
                adding: false,

                async load(force = false) {
                    this.loading = true;
                    this.error = false;
                    try {
                        const url = new URL('/api/servers', window.location.origin);
                        url.searchParams.set('per_page', '100');
                        // Hidden servers are only listed while managing, so a
                        // hidden entry stays invisible on the normal page even
                        // for the owner - otherwise "hidden" would not mean
                        // anything to the person who set it.
                        if (this.manage && this.isOwner) url.searchParams.set('include_hidden', '1');
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        this.servers = (await res.json()).data;
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                async addServer() {
                    this.manageError = '';
                    const port = Number(this.newPort);
                    if (!this.newIp.trim() || !port || port < 1 || port > 65535) {
                        this.manageError = @js(__('i18n::messages.servers.invalid'));
                        return;
                    }
                    this.adding = true;
                    try {
                        const res = await fetch('/api/servers', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify({ server_ip: this.newIp.trim(), server_port: port }),
                        });
                        if (!res.ok) {
                            const body = await res.json().catch(() => ({}));
                            this.manageError = Object.values(body.errors ?? {}).flat()[0] ?? @js(__('i18n::messages.common.error'));
                            return;
                        }
                        this.newIp = '';
                        this.newPort = '';
                        await this.load();
                    } catch (e) {
                        this.manageError = @js(__('i18n::messages.common.error'));
                    } finally {
                        this.adding = false;
                    }
                },

                async toggleHidden(server) {
                    this.manageError = '';
                    const next = !server.hidden;
                    // Optimistic: the switch should feel instant, and a
                    // failure re-syncs from the server below.
                    server.hidden = next;
                    try {
                        const res = await fetch(`/api/servers/${server.id}/visibility`, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify({ hidden: next }),
                        });
                        if (!res.ok) throw new Error('request_failed');
                    } catch (e) {
                        server.hidden = !next;
                        this.manageError = @js(__('i18n::messages.common.error'));
                    }
                },

                async removeServer(server) {
                    if (!confirm(@js(__('i18n::messages.servers.remove_confirm')))) return;
                    this.manageError = '';
                    try {
                        const res = await fetch(`/api/servers/${server.id}`, {
                            method: 'DELETE',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        if (!res.ok) throw new Error('request_failed');
                        await this.load();
                    } catch (e) {
                        this.manageError = @js(__('i18n::messages.common.error'));
                    }
                },

                address(server) {
                    return server.server_ip + ':' + server.server_port;
                },

                // Online first, then busiest - an empty server is the least
                // interesting thing on the page.
                get sorted() {
                    return [...this.servers].sort((a, b) =>
                        (b.online - a.online) || ((b.live?.players ?? 0) - (a.live?.players ?? 0))
                    );
                },

                get onlineCount() {
                    return this.servers.filter((s) => s.online).length;
                },

                get totalPlayers() {
                    return this.servers.reduce((sum, s) => sum + (s.live?.players ?? 0), 0);
                },

                fill(server) {
                    if (!server.live?.max_players) return 0;
                    return Math.min(100, (server.live.players / server.live.max_players) * 100);
                },

                async copy(server) {
                    const text = this.address(server);
                    try {
                        await navigator.clipboard.writeText(text);
                    } catch (e) {
                        // clipboard API needs a secure context and permission;
                        // fall back rather than leaving the button silent.
                        const el = document.createElement('textarea');
                        el.value = text;
                        document.body.appendChild(el);
                        el.select();
                        document.execCommand('copy');
                        el.remove();
                    }
                    this.copied = server.id;
                    setTimeout(() => { if (this.copied === server.id) this.copied = null; }, 1500);
                },

                init() {
                    this.load();
                    // Live state is cached server-side for 15s; matching that
                    // keeps the page current without hammering the endpoint.
                    setInterval(() => this.load(), 30000);
                },
            });
        </script>
    @endpush
</x-layout.app>
