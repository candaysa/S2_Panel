<x-layout.app :title="__('i18n::messages.nav.cheat_check')">
    <div
        x-data="{
            loading: true,
            forbidden: false,
            error: false,
            status: 'all',
            search: '',
            scans: [],
            selected: null,
            findings: [],
            selectedLoading: false,
            actionError: '',
            showNewForm: false,
            newScan: { player_name: '', steam_link: '', discord_id: '' },
            creating: false,
            issued: null,
            copied: false,

            async load() {
                this.loading = true;
                this.error = false;
                this.forbidden = false;
                try {
                    const url = new URL('/api/cheat-check', window.location.origin);
                    if (this.status !== 'all') url.searchParams.set('status', this.status);
                    if (this.search.trim()) url.searchParams.set('search', this.search.trim());
                    const res = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (res.status === 403) { this.forbidden = true; return; }
                    if (!res.ok) throw new Error('request_failed');
                    this.scans = (await res.json()).data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },

            csrf() {
                return document.querySelector('meta[name=csrf-token]').content;
            },

            async open(scan) {
                this.selected = scan;
                this.findings = [];
                this.selectedLoading = true;
                this.actionError = '';
                try {
                    const res = await fetch(`/api/cheat-check/${scan.id}`, { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.selected = body.data.scan;
                    this.findings = body.data.findings;
                } catch (e) {
                    this.actionError = @js(__('i18n::messages.common.error'));
                } finally {
                    this.selectedLoading = false;
                }
            },

            close() { this.selected = null; this.findings = []; },

            async submitNewScan() {
                if (!this.newScan.player_name.trim() || !this.newScan.steam_link.trim()) return;
                this.creating = true;
                this.actionError = '';
                try {
                    const res = await fetch('/api/cheat-check', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify(this.newScan),
                    });
                    const body = await res.json();
                    if (res.status === 429) { this.actionError = @js(__('i18n::messages.cheat_check.rate_limited')); return; }
                    if (!res.ok) throw new Error('request_failed');
                    this.issued = body.meta;
                    this.copied = false;
                    this.newScan = { player_name: '', steam_link: '', discord_id: '' };
                    this.showNewForm = false;
                    await this.load();
                } catch (e) {
                    this.actionError = @js(__('i18n::messages.common.error'));
                } finally {
                    this.creating = false;
                }
            },

            async copyCommand() {
                try {
                    await navigator.clipboard.writeText(this.issued.command);
                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 2000);
                } catch (e) {
                    this.$refs.command.select();
                }
            },

            async destroyScan() {
                if (!confirm(@js(__('i18n::messages.cheat_check.delete_confirm')))) return;
                this.actionError = '';
                try {
                    const res = await fetch(`/api/cheat-check/${this.selected.id}`, {
                        method: 'DELETE',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                    if (res.status === 403) { this.actionError = @js(__('i18n::messages.common.forbidden')); return; }
                    if (!res.ok) throw new Error('request_failed');
                    this.close();
                    await this.load();
                } catch (e) {
                    this.actionError = @js(__('i18n::messages.common.error'));
                }
            },

            statusClass(status) {
                return {
                    cheat: 'bg-red-500/15 text-red-400',
                    suspicious: 'bg-amber-500/15 text-amber-400',
                    clean: 'bg-emerald-500/15 text-emerald-400',
                    pending: 'bg-brand-soft text-brand-strong',
                }[status] ?? 'bg-surface-raised text-ink-faint';
            },

            riskClass(risk) {
                return {
                    HIGH: 'bg-red-500/15 text-red-400',
                    MEDIUM: 'bg-amber-500/15 text-amber-400',
                    LOW: 'bg-surface-raised text-ink-muted',
                }[risk] ?? 'bg-surface-raised text-ink-faint';
            },

            statusLabel(status) {
                return {
                    pending: @js(__('i18n::messages.cheat_check.status_pending')),
                    clean: @js(__('i18n::messages.cheat_check.status_clean')),
                    suspicious: @js(__('i18n::messages.cheat_check.status_suspicious')),
                    cheat: @js(__('i18n::messages.cheat_check.status_cheat')),
                    error: @js(__('i18n::messages.cheat_check.status_error')),
                }[status] ?? status;
            },

            formatDate(value) {
                return value ? new Date(value).toLocaleString() : '—';
            },

            init() { this.load(); },
        }"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.cheat_check') }}</h1>
                <p class="mt-1 text-sm text-ink-faint">{{ __('i18n::messages.cheat_check.subtitle') }}</p>
            </div>
            <button
                type="button"
                @click="showNewForm = !showNewForm; issued = null"
                class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90"
            >
                {{ __('i18n::messages.cheat_check.new_check') }}
            </button>
        </div>

        {{-- New check form --}}
        <div x-show="showNewForm" x-cloak x-transition class="mt-4 rounded-xl border border-line bg-surface p-5">
            <div class="grid gap-3 sm:grid-cols-3">
                <input type="text" x-model="newScan.player_name" placeholder="{{ __('i18n::messages.cheat_check.player_name') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                <input type="url" x-model="newScan.steam_link" placeholder="{{ __('i18n::messages.cheat_check.steam_link') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                <input type="text" x-model="newScan.discord_id" placeholder="{{ __('i18n::messages.cheat_check.discord_id') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
            </div>
            <button
                type="button"
                :disabled="creating"
                @click="submitNewScan()"
                class="mt-3 inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
            >
                {{ __('i18n::messages.cheat_check.generate_link') }}
            </button>
            <p x-show="actionError" x-cloak class="mt-3 text-sm text-red-400" x-text="actionError"></p>
        </div>

        {{-- Issued command --}}
        <div x-show="issued" x-cloak x-transition class="mt-4 rounded-xl border border-brand-strong/40 bg-brand-soft/40 p-5">
            <p class="text-sm font-medium text-ink">{{ __('i18n::messages.cheat_check.command_ready') }}</p>
            <p class="mt-1 text-xs text-ink-muted">{{ __('i18n::messages.cheat_check.command_hint') }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <input
                    type="text"
                    readonly
                    x-ref="command"
                    :value="issued?.command"
                    @click="$refs.command.select()"
                    class="min-w-0 flex-1 rounded-lg border border-line bg-canvas px-3 py-2 font-mono text-xs text-ink focus:border-brand-strong focus:outline-none"
                >
                <button
                    type="button"
                    @click="copyCommand()"
                    class="inline-flex shrink-0 items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90"
                    x-text="copied ? @js(__('i18n::messages.cheat_check.copied')) : @js(__('i18n::messages.cheat_check.copy'))"
                ></button>
                <button
                    type="button"
                    @click="issued = null"
                    class="inline-flex shrink-0 items-center rounded-lg border border-line px-3 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink"
                >
                    {{ __('i18n::messages.common.close') }}
                </button>
            </div>
            <p class="mt-2 text-xs text-ink-faint">{{ __('i18n::messages.cheat_check.command_expiry') }}</p>
        </div>

        {{-- Filters --}}
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <template x-for="s in ['all', 'pending', 'clean', 'suspicious', 'cheat']" :key="s">
                <button
                    type="button"
                    @click="status = s; load()"
                    :class="status === s ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                    x-text="s === 'all' ? @js(__('i18n::messages.cheat_check.status_all')) : statusLabel(s)"
                ></button>
            </template>
            <input
                type="search"
                x-model="search"
                @keydown.enter="load()"
                placeholder="{{ __('i18n::messages.common.search') }}"
                class="ml-auto w-48 rounded-lg border border-line bg-canvas px-3 py-1.5 text-sm text-ink focus:border-brand-strong focus:outline-none"
            >
        </div>

        <div class="mt-4 grid gap-6 lg:grid-cols-5">
            {{-- List --}}
            <div class="overflow-x-auto rounded-xl border border-line bg-surface lg:col-span-2" :class="selected ? 'hidden lg:block' : ''">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-line-soft">
                        <template x-for="scan in scans" :key="scan.id">
                            <tr
                                @click="open(scan)"
                                :class="selected?.id === scan.id ? 'bg-surface-raised' : 'hover:bg-surface-raised'"
                                class="cursor-pointer text-ink-muted transition-colors"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="truncate font-medium text-ink" x-text="scan.player_name"></span>
                                        <span
                                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="statusClass(scan.status)"
                                            x-text="statusLabel(scan.status)"
                                        ></span>
                                    </div>
                                    <p class="mt-0.5 flex items-center gap-2 text-xs text-ink-faint">
                                        <span x-text="formatDate(scan.created_at)"></span>
                                        <span x-show="scan.high_count > 0 || scan.medium_count > 0" x-cloak>
                                            &middot;
                                            <span class="text-red-400" x-text="scan.high_count + 'H'"></span>
                                            /
                                            <span class="text-amber-400" x-text="scan.medium_count + 'M'"></span>
                                        </span>
                                        <span x-show="scan.is_partial" x-cloak class="rounded bg-surface-raised px-1.5 py-0.5">{{ __('i18n::messages.cheat_check.partial') }}</span>
                                    </p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
                <p x-show="!loading && !forbidden && !error && scans.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>
                <p x-show="forbidden" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.forbidden') }}</p>
                <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
            </div>

            {{-- Detail --}}
            <div x-show="selected" x-cloak class="rounded-xl border border-line bg-surface p-5 lg:col-span-3">
                <template x-if="selected">
                    <div>
                        <button type="button" @click="close()" class="text-sm text-ink-muted hover:text-ink lg:hidden">
                            &larr; {{ __('i18n::messages.cheat_check.back_to_list') }}
                        </button>

                        <div class="mt-2 flex items-start justify-between gap-3">
                            <div>
                                <a :href="selected.steam_link" target="_blank" rel="noopener noreferrer" class="text-base font-semibold text-ink hover:text-brand-strong" x-text="selected.player_name"></a>
                                <p class="mt-1 text-xs text-ink-faint" x-text="selected.discord_id || ''"></p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="statusClass(selected.status)"
                                x-text="statusLabel(selected.status)"
                            ></span>
                        </div>

                        <dl class="mt-4 grid grid-cols-2 gap-2 border-t border-line-soft pt-4 text-xs text-ink-faint sm:grid-cols-3">
                            <div><dt>{{ __('i18n::messages.cheat_check.risk_score') }}</dt><dd class="mt-0.5 text-sm font-medium text-ink" x-text="selected.risk_score"></dd></div>
                            <div><dt>{{ __('i18n::messages.cheat_check.findings') }}</dt><dd class="mt-0.5 text-sm font-medium text-ink" x-text="selected.finding_count"></dd></div>
                            <div><dt>{{ __('i18n::messages.cheat_check.duration') }}</dt><dd class="mt-0.5 text-sm font-medium text-ink" x-text="selected.scan_duration ? selected.scan_duration + 's' : '—'"></dd></div>
                            <div><dt>{{ __('i18n::messages.cheat_check.computer') }}</dt><dd class="mt-0.5 text-sm text-ink-muted" x-text="selected.computer_name || '—'"></dd></div>
                            <div><dt>{{ __('i18n::messages.cheat_check.coverage') }}</dt><dd class="mt-0.5 text-sm text-ink-muted" x-text="selected.scan_coverage || '—'"></dd></div>
                            <div><dt>{{ __('i18n::messages.cheat_check.elevated') }}</dt><dd class="mt-0.5 text-sm text-ink-muted" x-text="selected.was_elevated ? @js(__('i18n::messages.common.yes')) : @js(__('i18n::messages.common.no'))"></dd></div>
                            <div><dt>{{ __('i18n::messages.cheat_check.requested_by') }}</dt><dd class="mt-0.5 text-sm text-ink-muted" x-text="selected.admin_name || '—'"></dd></div>
                            <div><dt>{{ __('i18n::messages.cheat_check.created') }}</dt><dd class="mt-0.5 text-sm text-ink-muted" x-text="formatDate(selected.created_at)"></dd></div>
                        </dl>

                        <p x-show="selected.status === 'suspicious'" x-cloak class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-400">
                            {{ __('i18n::messages.cheat_check.suspicious_note') }}
                        </p>
                        <p x-show="selected.is_partial" x-cloak class="mt-2 rounded-lg border border-line bg-surface-raised px-3 py-2 text-xs text-ink-muted">
                            {{ __('i18n::messages.cheat_check.partial_note') }}
                        </p>

                        <div class="mt-4 border-t border-line-soft pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.cheat_check.findings') }}</p>

                            <p x-show="selectedLoading" x-cloak class="py-6 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
                            <p x-show="!selectedLoading && findings.length === 0" x-cloak class="py-6 text-center text-sm text-ink-faint">{{ __('i18n::messages.cheat_check.no_findings') }}</p>

                            <div class="mt-2 space-y-2">
                                <template x-for="(finding, i) in findings" :key="i">
                                    <div class="rounded-lg bg-surface-raised p-3">
                                        <div class="flex items-center gap-2">
                                            <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold" :class="riskClass(finding.risk)" x-text="finding.risk"></span>
                                            <span class="text-xs font-medium text-ink" x-text="finding.category"></span>
                                        </div>
                                        <p class="mt-1 break-all text-xs text-ink-muted" x-text="finding.detail"></p>
                                        <p x-show="finding.hash" x-cloak class="mt-1 break-all font-mono text-[11px] text-ink-faint" x-text="'SHA256: ' + finding.hash"></p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="mt-4 flex border-t border-line-soft pt-4">
                            <button type="button" @click="destroyScan()" class="ml-auto rounded-lg border border-line px-3 py-1.5 text-sm text-red-400 transition-colors hover:bg-red-500/10">
                                {{ __('i18n::messages.cheat_check.delete_scan') }}
                            </button>
                        </div>

                        <p x-show="actionError" x-cloak class="mt-3 text-sm text-red-400" x-text="actionError"></p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</x-layout.app>
