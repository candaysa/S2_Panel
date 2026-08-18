<x-layout.app :title="__('i18n::messages.nav.audit')">
    <div x-data="auditPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.audit') }}</h1>

        <x-settings-tabs current="logs" />

        {{-- Two logs that answer different questions and must not be merged.
             The panel trail says what someone did *in the panel* - granted an
             admin, edited a group, changed a setting. The admin log says what
             an admin did *on a server* - a ban, a kick, a slay - and the panel
             is only ever a reader of it (see AdminLogService). Same audience,
             same gate, two tables that share no columns. --}}
        <div class="mt-5 flex flex-wrap gap-1 border-b border-line">
            <template x-for="t in tabs" :key="t.key">
                <button
                    type="button"
                    @click="switchTab(t.key)"
                    :class="tab === t.key ? 'border-brand-strong text-brand-strong' : 'border-transparent text-ink-muted hover:text-ink'"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                    x-text="t.label"
                ></button>
            </template>
        </div>

        {{-- ======================== PANEL ACTIVITY ======================== --}}
        <div x-show="tab === 'panel'" x-cloak>
            <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
                <input
                    type="search"
                    x-model="action"
                    @input.debounce.350ms="load(true)"
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

            <x-pagination :jump="true" />
        </div>

        {{-- ========================== ADMIN LOGS ========================== --}}
        <div x-show="tab === 'admin'" x-cloak>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {{-- Admin picker. Built from the log itself rather than from
                     the admin list, so it never offers a name with nothing
                     behind it and still includes admins who have since been
                     removed - whose history is what someone reviewing this
                     page is most likely looking for. --}}
                <select
                    x-model="adminFilter"
                    @change="loadAdminLog(true)"
                    class="rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                >
                    <option value="">{{ __('i18n::messages.audit.all_admins') }}</option>
                    <template x-for="a in adminOptions" :key="a.steamid">
                        <option :value="a.steamid" x-text="`${a.name} (${a.actions})`"></option>
                    </template>
                </select>

                <select
                    x-model="actionFilter"
                    @change="loadAdminLog(true)"
                    class="rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                >
                    <option value="">{{ __('i18n::messages.audit.all_actions') }}</option>
                    <template x-for="a in actionOptions" :key="a">
                        <option :value="a" x-text="a"></option>
                    </template>
                </select>

                <input
                    type="search"
                    x-model="adminSearch"
                    @input.debounce.350ms="loadAdminLog(true)"
                    placeholder="{{ __('i18n::messages.audit.admin_log_search') }}"
                    class="rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none lg:col-span-2"
                >
            </div>

            <div class="mt-4 overflow-x-auto rounded-xl border border-line bg-surface">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                        <tr>
                            <th class="px-4 py-3"><x-sort-th key="admin_name" :label="__('i18n::messages.audit.actor')" /></th>
                            <th class="px-4 py-3"><x-sort-th key="action" :label="__('i18n::messages.audit.action')" /></th>
                            <th class="px-4 py-3">{{ __('i18n::messages.audit.against') }}</th>
                            <th class="hidden px-4 py-3 xl:table-cell">{{ __('i18n::messages.audit.details') }}</th>
                            <th class="hidden px-4 py-3 lg:table-cell">{{ __('i18n::messages.audit.where') }}</th>
                            <th class="px-4 py-3"><x-sort-th key="created_at" :label="__('i18n::messages.audit.when')" /></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-soft">
                        <template x-for="row in adminLogs" :key="row.id">
                            <tr class="text-ink-muted">
                                <td class="px-4 py-3">
                                    {{-- Anything issued from the server console
                                         or over RCON is recorded against no
                                         SteamID at all, so it gets a label
                                         rather than a link to nobody. --}}
                                    <a
                                        :href="profileUrl(row.admin_steamid)"
                                        :target="profileUrl(row.admin_steamid) ? '_blank' : null"
                                        rel="noopener noreferrer"
                                        class="flex items-center gap-2.5"
                                    >
                                        <img x-show="row.admin_avatar" :src="row.admin_avatar" alt="" loading="lazy" class="size-7 shrink-0 rounded-full object-cover ring-1 ring-line">
                                        <span x-show="!row.admin_avatar" class="flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-raised text-xs font-semibold text-ink-faint" x-text="adminName(row).charAt(0).toUpperCase()"></span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-ink" :class="profileUrl(row.admin_steamid) ? 'transition-colors hover:text-brand-strong' : ''" x-text="adminName(row)"></span>
                                            <span
                                                class="block truncate font-mono text-[11px] text-ink-faint"
                                                x-text="row.admin_is_console ? @js(__('i18n::messages.audit.console_actor')) : (row.admin_steamid || '—')"
                                            ></span>
                                        </span>
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full bg-surface-raised px-2.5 py-0.5 text-xs font-medium text-ink" x-text="row.action"></span>
                                </td>
                                <td class="px-4 py-3">
                                    {{-- admin_log stores only a SteamID for the
                                         player acted upon; the name is overlaid
                                         from the live profile, because a bare
                                         17-digit number is the one thing nobody
                                         can identify at a glance. --}}
                                    <a
                                        x-show="profileUrl(row.target_steamid)"
                                        :href="profileUrl(row.target_steamid)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="flex items-center gap-2.5"
                                    >
                                        <img x-show="row.target_avatar" :src="row.target_avatar" alt="" loading="lazy" class="size-7 shrink-0 rounded-full object-cover ring-1 ring-line">
                                        <span class="min-w-0">
                                            <span class="block truncate text-ink transition-colors hover:text-brand-strong" x-text="row.target_name || '—'"></span>
                                            <span class="block truncate font-mono text-[11px] text-ink-faint" x-text="row.target_steamid"></span>
                                        </span>
                                    </a>
                                    <span x-show="!profileUrl(row.target_steamid)" class="text-ink-faint">—</span>
                                </td>
                                <td class="hidden max-w-xs px-4 py-3 xl:table-cell">
                                    <span class="line-clamp-2" :title="row.details" x-text="row.details || '—'"></span>
                                </td>
                                <td class="hidden px-4 py-3 lg:table-cell" x-text="serverLabel(row)"></td>
                                <td class="whitespace-nowrap px-4 py-3" x-text="formatDate(row.created_at)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>

                {{-- "This plugin keeps no such log" is a different answer from
                     "no admin has done anything yet", and only one of them is
                     worth investigating - see AdminLogService::available(). --}}
                <p x-show="!loading && !adminAvailable" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                    {{ __('i18n::messages.audit.admin_log_unavailable') }}
                </p>
                <p x-show="!loading && adminAvailable && !error && adminLogs.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                    {{ __('i18n::messages.common.empty') }}
                </p>
                <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
            </div>

            <x-pagination :jump="true" />
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.auditPage = () => ({
                tab: 'panel',

                // <x-sort-th> and <x-pagination> bind to sort/dir and
                // page/lastPage/total/perPage by name in this scope. Only
                // one tab is ever on screen, so rather than a second set of
                // fields the shared components know nothing about, both tabs
                // share these and switchTab() stashes/restores them.
                loading: true,
                forbidden: false,
                error: false,
                sort: 'created_at',
                dir: 'desc',
                page: 1,
                perPage: 50,
                lastPage: 1,
                total: 0,

                tabState: {
                    panel: { sort: 'created_at', dir: 'desc', page: 1 },
                    admin: { sort: 'id', dir: 'desc', page: 1 },
                },

                // --- panel activity ---
                action: '',
                logs: [],

                // --- in-game admin log ---
                adminAvailable: true,
                adminLoaded: false,
                adminLogs: [],
                adminFilter: '',
                actionFilter: '',
                adminSearch: '',
                adminOptions: [],
                actionOptions: [],

                t: @js(__('i18n::messages.audit')),

                tabs: [
                    { key: 'panel', label: @js(__('i18n::messages.audit.tab_panel')) },
                    { key: 'admin', label: @js(__('i18n::messages.audit.tab_admin')) },
                ],

                init() {
                    this.load();
                },

                // Each tab keeps its own sort column and page across a
                // switch - they sort by different columns entirely, and
                // coming back to a list you had paged into only to find it
                // reset to page 1 is its own small annoyance.
                switchTab(key) {
                    if (key === this.tab) return;

                    this.tabState[this.tab] = { sort: this.sort, dir: this.dir, page: this.page };
                    this.tab = key;
                    Object.assign(this, this.tabState[key]);
                    this.error = false;
                    this.forbidden = false;

                    // The admin log is a second round-trip against a table
                    // this install may not even have, so its first load waits
                    // until someone actually opens the tab.
                    if (key === 'admin' && !this.adminLoaded) {
                        this.adminLoaded = true;
                        this.loadAdminFilters();
                    }

                    key === 'admin' ? this.loadAdminLog() : this.load();
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

                go(page) {
                    if (page < 1 || page > this.lastPage || page === this.page) return;
                    this.page = page;
                    this.tab === 'admin' ? this.loadAdminLog() : this.load();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                },

                // resetPage on anything that changes what the result set IS -
                // narrowing a list from page 7 otherwise lands on an empty
                // page that reads as "no results".
                async load(resetPage = false) {
                    if (resetPage) this.page = 1;
                    this.loading = true;
                    this.error = false;
                    this.forbidden = false;
                    try {
                        const url = new URL('/api/audit', window.location.origin);
                        if (this.action) url.searchParams.set('action', this.action);
                        url.searchParams.set('sort', this.sort);
                        url.searchParams.set('dir', this.dir);
                        url.searchParams.set('per_page', String(this.perPage));
                        url.searchParams.set('page', String(this.page));
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (res.status === 403) { this.forbidden = true; return; }
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.logs = body.data;
                        const p = body.meta?.pagination ?? {};
                        this.lastPage = p.last_page ?? 1;
                        this.total = p.total ?? this.logs.length;
                        this.page = p.current_page ?? this.page;
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                async loadAdminFilters() {
                    try {
                        const res = await fetch('/api/audit/admin-log/filters', { headers: { Accept: 'application/json' } });
                        if (!res.ok) return;
                        const body = await res.json();
                        this.adminOptions = body.data.admins ?? [];
                        this.actionOptions = body.data.actions ?? [];
                    } catch (e) {
                        // Filters are a convenience; the table below still
                        // loads and is still searchable without them.
                    }
                },

                async loadAdminLog(resetPage = false) {
                    if (resetPage) this.page = 1;
                    this.loading = true;
                    this.error = false;
                    this.forbidden = false;
                    try {
                        const url = new URL('/api/audit/admin-log', window.location.origin);
                        if (this.adminFilter) url.searchParams.set('admin', this.adminFilter);
                        if (this.actionFilter) url.searchParams.set('action', this.actionFilter);
                        if (this.adminSearch) url.searchParams.set('search', this.adminSearch);
                        url.searchParams.set('sort', this.sort);
                        url.searchParams.set('dir', this.dir);
                        url.searchParams.set('per_page', String(this.perPage));
                        url.searchParams.set('page', String(this.page));
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (res.status === 403) { this.forbidden = true; return; }
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.adminAvailable = body.meta?.available !== false;
                        this.adminLogs = body.data;
                        const p = body.meta?.pagination ?? {};
                        this.lastPage = p.last_page ?? 1;
                        this.total = p.total ?? this.adminLogs.length;
                        this.page = p.current_page ?? this.page;
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                // <x-sort-th> calls this. The two tables share no column
                // names, so which list reloads depends on the tab; names sort
                // A-Z on first click, everything else newest-first.
                sortBy(key) {
                    if (this.sort === key) {
                        this.dir = this.dir === 'asc' ? 'desc' : 'asc';
                    } else {
                        this.sort = key;
                        this.dir = ['actor_name', 'admin_name', 'action'].includes(key) ? 'asc' : 'desc';
                    }

                    this.tab === 'admin' ? this.loadAdminLog(true) : this.load(true);
                },

                formatDate(value) {
                    return value ? new Date(value).toLocaleString() : '—';
                },

                // A Steam profile URL, or '' when there is no real account
                // behind the id. admin_log records console/RCON actions
                // against 0, and steamcommunity.com/profiles/0 is a link to
                // nobody - worse than no link, because it looks like one.
                profileUrl(steamid) {
                    const id = String(steamid ?? '');
                    return id && id !== '0' ? `https://steamcommunity.com/profiles/${id}` : '';
                },

                serverLabel(row) {
                    if (row.server_ip) return `${row.server_ip}:${row.server_port ?? ''}`.replace(/:$/, '');
                    return row.server_id ? `#${row.server_id}` : '—';
                },

                // Prefer the live Steam profile the API overlays onto every row
                // over the stored snapshot - a nickname can change after the
                // action, and the snapshot is whatever it was at the time.
                adminName(row) {
                    return row.admin_current_name || row.admin_name || '—';
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
            });
        </script>
    @endpush
</x-layout.app>
