<x-layout.app :title="__('i18n::messages.nav.vip')">
    <div
        x-data="{
            loading: true,
            forbidden: false,
            error: false,
            actionError: '',
            search: '',
            serverId: '',
            servers: [],
            users: [],
            showGrantForm: false,
            granting: false,
            grant: { steamid: '', name: '', group: '', server_id: '', expires_at: '' },

            async loadServers() {
                try {
                    const res = await fetch('/api/vip/servers', { headers: { Accept: 'application/json' } });
                    if (!res.ok) return;
                    const body = await res.json();
                    this.servers = body.data;
                } catch (e) {}
            },

            serverLabel(id) {
                const server = this.servers.find((s) => s.serverId === id);
                return server ? `${server.serverIp}:${server.port}` : `#${id}`;
            },

            async load() {
                this.loading = true;
                this.error = false;
                this.forbidden = false;
                try {
                    const url = new URL('/api/vip', window.location.origin);
                    if (this.search) url.searchParams.set('search', this.search);
                    if (this.serverId) url.searchParams.set('server_id', this.serverId);
                    const res = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (res.status === 403) { this.forbidden = true; return; }
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.users = body.data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },

            csrf() {
                return document.querySelector('meta[name=csrf-token]').content;
            },

            async submitGrant() {
                this.granting = true;
                this.actionError = '';
                try {
                    const payload = {
                        steamid: this.grant.steamid,
                        name: this.grant.name,
                        group: this.grant.group,
                        server_id: Number(this.grant.server_id),
                        expires_at: this.grant.expires_at ? Math.floor(new Date(this.grant.expires_at).getTime() / 1000) : null,
                    };
                    const res = await fetch('/api/vip', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify(payload),
                    });
                    if (res.status === 403) { this.actionError = @js(__('i18n::messages.common.forbidden')); return; }
                    if (!res.ok) throw new Error('request_failed');
                    this.grant = { steamid: '', name: '', group: '', server_id: '', expires_at: '' };
                    this.showGrantForm = false;
                    await this.load();
                } catch (e) {
                    this.actionError = @js(__('i18n::messages.common.error'));
                } finally {
                    this.granting = false;
                }
            },

            async revoke(user) {
                if (!confirm(@js(__('i18n::messages.vip.revoke_confirm')))) return;
                this.actionError = '';
                try {
                    // account_id alone is not a recognized SteamID format (it's
                    // SteamID32, missing the universe/instance the parser
                    // needs) - SteamID3 is the shortest format that round-trips.
                    const steamId3 = `[U:1:${user.account_id}]`;
                    const url = new URL(`/api/vip/${encodeURIComponent(steamId3)}/${encodeURIComponent(user.group)}`, window.location.origin);
                    url.searchParams.set('server_id', user.sid);
                    const res = await fetch(url, {
                        method: 'DELETE',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                    if (res.status === 403) { this.actionError = @js(__('i18n::messages.common.forbidden')); return; }
                    if (!res.ok) throw new Error('request_failed');
                    await this.load();
                } catch (e) {
                    this.actionError = @js(__('i18n::messages.common.error'));
                }
            },

            formatExpires(value) {
                return !value ? @js(__('i18n::messages.bans.never')) : new Date(value * 1000).toLocaleString();
            },

            formatPlaytime(minutes) {
                if (!minutes) return '—';
                const h = Math.floor(minutes / 60);
                const m = minutes % 60;
                return h > 0 ? `${h}${@js(__('i18n::messages.ranks.hours_short'))} ${m}${@js(__('i18n::messages.ranks.minutes_short'))}` : `${m}${@js(__('i18n::messages.ranks.minutes_short'))}`;
            },

            init() {
                this.loadServers();
                this.load();
            },
        }"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.vip') }}</h1>
            <button
                type="button"
                @click="showGrantForm = !showGrantForm"
                class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90"
            >
                {{ __('i18n::messages.vip.grant_vip') }}
            </button>
        </div>

        {{-- Grant form --}}
        <div x-show="showGrantForm" x-cloak x-transition class="mt-4 rounded-xl border border-line bg-surface p-5">
            <div class="grid gap-3 sm:grid-cols-2">
                <input type="text" x-model="grant.steamid" placeholder="{{ __('i18n::messages.vip.steamid') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                <input type="text" x-model="grant.name" placeholder="{{ __('i18n::messages.vip.player_name') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                <input type="text" x-model="grant.group" placeholder="{{ __('i18n::messages.vip.group') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                <select x-model="grant.server_id" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <option value="">{{ __('i18n::messages.vip.select_server') }}</option>
                    <template x-for="server in servers" :key="server.serverId">
                        <option :value="server.serverId" x-text="server.serverIp + ':' + server.port"></option>
                    </template>
                </select>
                <input type="datetime-local" x-model="grant.expires_at" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                <p class="self-center text-xs text-ink-faint">{{ __('i18n::messages.vip.expires_hint') }}</p>
            </div>
            <button
                type="button"
                :disabled="granting"
                @click="submitGrant()"
                class="mt-3 inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
            >
                {{ __('i18n::messages.vip.grant_vip') }}
            </button>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <input
                type="search"
                x-model="search"
                @input.debounce.350ms="load()"
                placeholder="{{ __('i18n::messages.common.search') }}"
                class="w-full max-w-xs rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none sm:w-64"
            >
            <select x-model="serverId" @change="load()" class="rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                <option value="">{{ __('i18n::messages.vip.all_servers') }}</option>
                <template x-for="server in servers" :key="server.serverId">
                    <option :value="server.serverId" x-text="server.serverIp + ':' + server.port"></option>
                </template>
            </select>
        </div>

        <div class="mt-4 overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    <tr>
                        <th class="px-4 py-3">{{ __('i18n::messages.vip.player_name') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.vip.group') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.nav.servers') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.ranks.playtime') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.vip.status') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.bans.expires') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="user in users" :key="user.account_id + '-' + user.sid + '-' + user.group">
                        <tr class="text-ink-muted">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <template x-if="user.avatar">
                                        <img :src="user.avatar" alt="" class="size-7 shrink-0 rounded-full">
                                    </template>
                                    <template x-if="!user.avatar">
                                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-raised text-xs font-semibold text-ink-muted" x-text="(user.name || '?').charAt(0).toUpperCase()"></span>
                                    </template>
                                    <div class="min-w-0">
                                        <span class="block truncate font-medium text-ink" x-text="user.name || '—'"></span>
                                        <span class="block font-mono text-xs text-ink-faint" x-text="user.account_id"></span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full bg-brand-soft px-2.5 py-0.5 text-xs font-medium text-brand-strong" x-text="user.group"></span>
                            </td>
                            <td class="px-4 py-3" x-text="serverLabel(user.sid)"></td>
                            <td class="px-4 py-3 tabular-nums" x-text="formatPlaytime(user.playtime_minutes)"></td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="user.active ? 'bg-emerald-500/10 text-emerald-400' : 'bg-surface-raised text-ink-faint'"
                                    x-text="user.active ? @js(__('i18n::messages.vip.active')) : @js(__('i18n::messages.vip.expired'))"
                                ></span>
                            </td>
                            <td class="px-4 py-3" x-text="formatExpires(user.expires)"></td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" @click="revoke(user)" class="text-sm text-red-400 hover:underline">
                                    {{ __('i18n::messages.vip.revoke') }}
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
            <p x-show="!loading && !forbidden && !error && users.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>
            <p x-show="forbidden" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.forbidden') }}</p>
            <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
        </div>

        <p x-show="actionError" x-cloak class="mt-4 text-sm text-red-400" x-text="actionError"></p>
    </div>
</x-layout.app>
