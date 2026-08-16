<x-layout.app :title="__('i18n::messages.nav.audit')">
    <div x-data="auditPage()" x-init="init()">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.audit') }}</h1>
            <input
                type="search"
                x-model="action"
                @input.debounce.350ms="load()"
                placeholder="{{ __('i18n::messages.audit.filter_action') }}"
                class="w-full max-w-xs rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none sm:w-64"
            >
        </div>

        <div class="mt-4 overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    <tr>
                        <th class="px-4 py-3"><x-sort-th key="actor_name" :label="__('i18n::messages.audit.actor')" /></th>
                        <th class="px-4 py-3"><x-sort-th key="action" :label="__('i18n::messages.audit.action')" /></th>
                        <th class="px-4 py-3">{{ __('i18n::messages.audit.target') }}</th>
                        <th class="hidden px-4 py-3 lg:table-cell">{{ __('i18n::messages.audit.source') }}</th>
                        <th class="px-4 py-3"><x-sort-th key="created_at" :label="__('i18n::messages.audit.when')" /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="log in logs" :key="log.id">
                        <tr class="text-ink-muted">
                            <td class="px-4 py-3">
                                <a
                                    :href="log.actor_steamid ? 'https://steamcommunity.com/profiles/' + log.actor_steamid : null"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="flex items-center gap-2.5"
                                >
                                    <img x-show="log.actor_avatar" :src="log.actor_avatar" alt="" loading="lazy" class="size-7 shrink-0 rounded-full object-cover ring-1 ring-line">
                                    <span x-show="!log.actor_avatar" class="flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-raised text-xs font-semibold text-ink-faint" x-text="actorName(log).charAt(0).toUpperCase()"></span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-ink transition-colors hover:text-brand-strong" x-text="actorName(log)"></span>
                                        <span class="block truncate font-mono text-[11px] text-ink-faint" x-text="log.actor_steamid"></span>
                                    </span>
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                <span :title="log.action" x-text="describe(log)"></span>
                            </td>
                            <td class="px-4 py-3" x-text="(log.target_type ?? '') + (log.target_id ? ' #' + log.target_id : '')"></td>
                            <td class="hidden px-4 py-3 font-mono text-xs lg:table-cell" x-text="log.ip_address || '—'"></td>
                            <td class="px-4 py-3" x-text="formatDate(log.created_at)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
            <p x-show="!loading && !forbidden && !error && logs.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>
            <p x-show="forbidden" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.forbidden') }}</p>
            <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.auditPage = () => ({
                loading: true,
                forbidden: false,
                error: false,
                action: '',
                sort: 'created_at',
                dir: 'desc',
                logs: [],
                t: @js(__('i18n::messages.audit')),

                async load() {
                    this.loading = true;
                    this.error = false;
                    this.forbidden = false;
                    try {
                        const url = new URL('/api/audit', window.location.origin);
                        if (this.action) url.searchParams.set('action', this.action);
                        url.searchParams.set('sort', this.sort);
                        url.searchParams.set('dir', this.dir);
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (res.status === 403) { this.forbidden = true; return; }
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.logs = body.data;
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                sortBy(key) {
                    if (this.sort === key) { this.dir = this.dir === 'asc' ? 'desc' : 'asc'; }
                    else { this.sort = key; this.dir = key === 'actor_name' || key === 'action' ? 'asc' : 'desc'; }
                    this.load();
                },

                formatDate(value) {
                    return value ? new Date(value).toLocaleString() : '—';
                },

                // Prefer the live Steam profile the API overlays onto every row
                // (App\Modules\Audit\App\Http\Controllers\AuditController::index)
                // over the historical snapshot: a nickname can change after the
                // action, and one real bug briefly stored the profile's real-name
                // bio instead of the handle in the snapshot itself.
                actorName(log) {
                    return log.actor_current_name || log.actor_name || '—';
                },

                // Turns a raw "module.verb_phrase" action key into a
                // readable sentence. Covers the actions every module
                // actually logs (see AuditService callers); anything new or
                // uncovered still reads fine via the generic fallback at the
                // bottom, which just humanizes the key itself rather than
                // showing the dotted machine string as-is.
                describe(log) {
                    const d = log.details || {};
                    const t = log.target_id ?? '';
                    const name = d.name || d.player_name || t;

                    const sentences = {
                        'admin.created': () => this.t.action_admin_created.replace(':name', name),
                        'admin.updated': () => this.t.action_admin_updated.replace(':name', name),
                        'admin.disabled': () => this.t.action_admin_disabled.replace(':name', name),
                        'admin_group.created': () => this.t.action_group_created.replace(':name', t),
                        'admin_group.updated': () => this.t.action_group_updated.replace(':name', t),
                        'admin_group.deleted': () => this.t.action_group_deleted.replace(':name', t),
                        'rank.points_updated': () => this.t.action_points_updated
                            .replace(':old', d.old_value ?? '?').replace(':new', d.new_value ?? '?'),
                        'appeal.created': () => this.t.action_appeal_created,
                        'appeal.decided': () => this.t.action_appeal_decided.replace(':status', d.status ?? '?'),
                        'report.created': () => this.t.action_report_created.replace(':type', d.ticket_type ?? '?'),
                        'report.replied': () => this.t.action_report_replied.replace(':id', t),
                        'report.closed': () => this.t.action_report_closed.replace(':id', t),
                        'report.deleted': () => this.t.action_report_deleted.replace(':id', t),
                        'rcon.settings.saved': () => this.t.action_rcon_saved.replace(':server', d.server_id ?? t),
                        'rcon.settings.removed': () => this.t.action_rcon_removed.replace(':server', d.server_id ?? t),
                        'rcon.command.executed': () => this.t.action_rcon_executed.replace(':server', d.server_id ?? t),
                        'server.created': () => this.t.action_server_created.replace(':address', d.address ?? t),
                        'server.deleted': () => this.t.action_server_deleted.replace(':address', d.address ?? t),
                        'server.hidden': () => this.t.action_server_hidden,
                        'server.shown': () => this.t.action_server_shown,
                        'vip.granted': () => this.t.action_vip_granted,
                        'vip.revoked': () => this.t.action_vip_revoked,
                        'plugin.installed': () => this.t.action_plugin_installed.replace(':name', d.name ?? t),
                        'plugin.enabled': () => this.t.action_plugin_enabled.replace(':name', t),
                        'plugin.disabled': () => this.t.action_plugin_disabled.replace(':name', t),
                        'plugin.uninstalled': () => this.t.action_plugin_uninstalled.replace(':name', d.name ?? t),
                        'module.enabled': () => this.t.action_module_enabled.replace(':name', t),
                        'module.disabled': () => this.t.action_module_disabled.replace(':name', t),
                        'module.admin_plugin_changed': () => this.t.action_admin_plugin_changed
                            .replace(':from', d.from ?? '?').replace(':to', d.to ?? '?'),
                        'panel.updated': () => this.t.action_panel_updated.replace(':version', t),
                        'cheat_check.opened': () => this.t.action_cheatcheck_opened.replace(':name', name),
                        'cheat_check.completed': () => this.t.action_cheatcheck_completed.replace(':status', d.status ?? '?'),
                        'cheat_check.deleted': () => this.t.action_cheatcheck_deleted.replace(':id', t),
                    };

                    if (sentences[log.action]) return sentences[log.action]();

                    // Generic fallback: "module.some_verb" -> "Some verb" -
                    // still far more readable than the raw dotted key.
                    const parts = String(log.action).split('.');
                    const words = (parts[parts.length - 1] || '').replace(/_/g, ' ');
                    return words.charAt(0).toUpperCase() + words.slice(1);
                },

                init() { this.load(); },
            });
        </script>
    @endpush
</x-layout.app>
