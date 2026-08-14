<x-layout.app :title="__('i18n::messages.nav.servers')">
    <div
        x-data="{
            loading: true,
            error: false,
            servers: [],
            async init() {
                try {
                    const res = await fetch('/api/servers?per_page=100', { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.servers = body.data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },
        }"
        x-init="init()"
    >
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.servers') }}</h1>

        <div class="mt-6 overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    <tr>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Address</th>
                        <th class="px-4 py-3">Map</th>
                        <th class="px-4 py-3">Players</th>
                        <th class="px-4 py-3">Last seen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="server in servers" :key="server.id">
                        <tr class="text-ink-muted">
                            <td class="px-4 py-3">
                                <span
                                    :class="server.online ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-faint'"
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    x-text="server.online ? 'Online' : 'Offline'"
                                ></span>
                            </td>
                            <td class="px-4 py-3 font-medium text-ink">
                                <span x-text="server.live?.name || (server.server_ip + ':' + server.server_port)"></span>
                                <span class="block font-mono text-xs text-ink-faint" x-text="server.server_ip + ':' + server.server_port"></span>
                            </td>
                            <td class="px-4 py-3" x-text="server.live?.map || '—'"></td>
                            <td class="px-4 py-3" x-text="server.live ? (server.live.players + ' / ' + server.live.max_players) : '—'"></td>
                            <td class="px-4 py-3" x-text="server.last_seen_at ? new Date(server.last_seen_at).toLocaleString() : '—'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.loading') }}
            </p>
            <p x-show="!loading && !error && servers.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.empty') }}
            </p>
            <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">
                {{ __('i18n::messages.common.error') }}
            </p>
        </div>
    </div>
</x-layout.app>
