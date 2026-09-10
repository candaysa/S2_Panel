{{--
    Settings > Updates (owner only): check GitHub for a newer release and
    install it from here, no SSH. The work is UpdateInstaller's - this page
    shows what the server can and cannot do, drives install -> finalise, and
    offers the roll-back when an update was left half done.

    Stays reachable while the panel is in maintenance mode (bootstrap/app.php),
    since that is exactly when it is needed.
--}}
<x-layout.app :title="__('i18n::messages.nav.settings')">
    <div x-data="updatesPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.settings') }}</h1>

        <x-settings-tabs current="updates" />

        <div class="mt-6 max-w-2xl space-y-4">
            <div>
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.update.page_title') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.update.page_subtitle') }}</p>
            </div>

            {{-- An update in flight that this page is not driving: still
                 running in another request (wait for it), files in but not
                 finalised (finish it), or stopped half way (roll back). --}}
            <div x-show="pending && stage === 'idle'" x-cloak class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-4">
                <p class="text-sm text-amber-400" x-text="pending?.running ? labels.in_progress : (pending?.stage === 'applied' ? labels.not_finalised : labels.interrupted)"></p>
                <p class="mt-1 font-mono text-xs text-amber-400/80" x-text="pending ? (pending.from + ' → ' + pending.version + ' · ' + pending.stage) : ''"></p>
                <div x-show="!pending?.running" class="mt-3 flex flex-wrap gap-2">
                    <button type="button" x-show="pending?.stage === 'applied'" @click="finishPending()" :disabled="busy"
                            class="inline-flex items-center rounded-lg bg-brand-strong px-3 py-1.5 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50">
                        {{ __('i18n::messages.update.finish') }}
                    </button>
                    <button type="button" @click="rollback()" :disabled="busy"
                            class="inline-flex items-center rounded-lg bg-amber-500/20 px-3 py-1.5 text-sm font-medium text-amber-300 transition-colors hover:bg-amber-500/30 disabled:opacity-50">
                        {{ __('i18n::messages.update.roll_back') }}
                    </button>
                </div>
            </div>

            <div class="rounded-lg border border-line bg-surface p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs text-ink-faint">{{ __('i18n::messages.update.installed_version') }}</p>
                        <p class="font-mono text-lg text-ink">{{ config('panel.version') }}</p>
                    </div>
                    <button type="button" @click="load(true)" :disabled="checking || busy"
                            class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink disabled:opacity-50">
                        <x-icon name="refresh" class="size-4" ::class="checking && 'animate-spin'" />
                        <span x-text="checking ? labels.checking : labels.check_now"></span>
                    </button>
                </div>

                <p x-show="!loading && !release.available && stage !== 'done'" x-cloak class="mt-4 text-sm text-ink-muted" x-text="statusText()"></p>
            </div>

            {{-- A newer release exists. --}}
            <div x-show="release.available && stage !== 'done'" x-cloak class="rounded-lg border border-line bg-surface">
                <div class="border-b border-line p-4">
                    <p class="text-xs text-ink-faint">{{ __('i18n::messages.update.available_title') }}</p>
                    <p class="mt-0.5 font-mono text-lg text-brand-strong" x-text="release.latest"></p>
                    <p x-show="release.published_at" class="mt-0.5 text-xs text-ink-faint">
                        {{ __('i18n::messages.update.published') }} <span x-text="formatDate(release.published_at)"></span>
                    </p>
                </div>

                {{-- Release notes as plain text, never HTML: they come from
                     outside the panel. --}}
                <div x-show="release.notes" class="max-h-72 overflow-y-auto border-b border-line p-4">
                    <p class="whitespace-pre-line text-sm leading-relaxed text-ink-muted" x-text="release.notes"></p>
                    <a x-show="release.html_url" :href="release.html_url" target="_blank" rel="noopener noreferrer"
                       class="mt-2 inline-block text-xs text-brand-strong hover:underline">{{ __('i18n::messages.update.full_notes') }}</a>
                </div>

                <div class="p-4">
                    <p class="text-xs font-medium text-ink-muted">{{ __('i18n::messages.update.requirements') }}</p>
                    <ul class="mt-2 space-y-1.5">
                        <template x-for="c in preflight.checks" :key="c.key">
                            <li class="flex items-start gap-2 text-sm">
                                <span class="mt-0.5 shrink-0" :class="c.ok ? 'text-emerald-400' : 'text-red-400'" x-text="c.ok ? '✓' : '✗'"></span>
                                <span :class="c.ok ? 'text-ink-muted' : 'text-ink'">
                                    <span x-text="checkLabel(c.key)"></span>
                                    <span x-show="!c.ok && c.detail" class="font-mono text-xs text-ink-faint" x-text="' — ' + c.detail"></span>
                                </span>
                            </li>
                        </template>
                        <li x-show="release.reason === 'no_installable_asset'" class="flex items-start gap-2 text-sm">
                            <span class="mt-0.5 shrink-0 text-red-400">✗</span>
                            <span class="text-ink">{{ __('i18n::messages.update.no_asset') }}</span>
                        </li>
                    </ul>

                    <p class="mt-4 text-xs text-ink-faint">{{ __('i18n::messages.update.maintenance_note') }}</p>

                    <button type="button" @click="update()" :disabled="!canInstall || busy"
                            class="mt-4 inline-flex items-center gap-2 rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50">
                        <x-icon name="upload" class="size-4" ::class="busy && 'animate-pulse'" />
                        <span x-text="busyLabel()"></span>
                    </button>
                </div>
            </div>

            <div x-show="stage === 'done'" x-cloak class="rounded-lg bg-emerald-500/10 px-4 py-3 text-sm text-emerald-400">
                <span x-text="doneText"></span>
            </div>

            <p x-show="warning" x-cloak class="rounded-lg bg-amber-500/10 px-4 py-3 text-sm text-amber-400" x-text="warning"></p>
            <p x-show="error" x-cloak class="rounded-lg bg-red-500/10 px-4 py-3 text-sm text-red-400" x-text="error"></p>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.updatesPage = () => ({
                loading: true,
                checking: false,
                busy: false,
                stage: 'idle',
                error: '',
                warning: '',
                doneText: '',
                canInstall: false,
                pending: null,
                watcher: null,
                release: { available: false, current: '', latest: '', notes: '', html_url: '', published_at: null, reason: null },
                preflight: { ready: false, checks: [] },
                labels: @js(__('i18n::messages.update')),

                async init() {
                    await this.load(false);
                },

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                async load(force) {
                    this.checking = force;
                    this.error = '';

                    try {
                        const res = await fetch('/api/update/status' + (force ? '?force=1' : ''), { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('status_failed');
                        const body = await res.json();
                        this.release = body.data.release;
                        this.preflight = body.data.preflight;
                        this.canInstall = body.data.can_install;
                        this.pending = body.data.pending;

                        if (this.pending?.running) {
                            this.watch();
                        }
                    } catch (e) {
                        this.error = this.labels.lookup_failed;
                    } finally {
                        this.loading = false;
                        this.checking = false;
                    }
                },

                statusText() {
                    const reasons = {
                        no_release: this.labels.no_release,
                        lookup_failed: this.labels.lookup_failed,
                        updates_disabled: this.labels.updates_disabled,
                    };

                    return reasons[this.release.reason] ?? this.labels.up_to_date;
                },

                checkLabel(key) {
                    return this.labels['check_' + key] ?? key;
                },

                busyLabel() {
                    if (this.stage === 'installing') return this.labels.installing;
                    if (this.stage === 'finalising') return this.labels.finalising;

                    return this.labels.install_now;
                },

                formatDate(value) {
                    const d = new Date(value);

                    return isNaN(d) ? value : d.toLocaleString();
                },

                // Network errors and non-JSON answers (a proxy's 504 page)
                // come back as { ok: false, body: {} } rather than throwing.
                async post(url) {
                    try {
                        const res = await fetch(url, {
                            method: 'POST',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        const body = await res.json().catch(() => ({}));

                        return { ok: res.ok, body };
                    } catch (e) {
                        return { ok: false, body: {} };
                    }
                },

                reasonOf(result) {
                    return result.body.errors?.reason?.[0] ?? '';
                },

                // Re-reads status every few seconds while another request is
                // still working on an update, then shows how it ended.
                watch() {
                    clearTimeout(this.watcher);
                    this.watcher = setTimeout(() => this.load(false), 3000);
                },

                // Two requests on purpose: install() copies the new files in,
                // and the migrations have to run on that new code, which only
                // exists from the next request onward.
                async update() {
                    this.busy = true;
                    this.error = '';
                    this.warning = '';
                    this.stage = 'installing';

                    try {
                        const install = await this.post('/api/update/install');

                        if (!install.ok) {
                            const reason = this.reasonOf(install);
                            await this.load(false);

                            // No reason means the answer never arrived (a
                            // gateway timeout) - the install itself keeps
                            // going server-side. If it already got the files
                            // in, finish it; otherwise the banner takes over.
                            if (reason === '' && this.pending?.stage === 'applied' && !this.pending.running) {
                                await this.finish();

                                return;
                            }

                            this.stage = 'idle';

                            if (reason !== '' || !this.pending) {
                                this.error = this.labels.failed + ' ' + reason;
                            }

                            return;
                        }

                        await this.finish();
                    } finally {
                        this.busy = false;
                    }
                },

                async finishPending() {
                    this.busy = true;
                    this.error = '';

                    try {
                        await this.finish();
                    } finally {
                        this.busy = false;
                    }
                },

                async finish() {
                    this.stage = 'finalising';
                    const finalise = await this.post('/api/update/finalise');

                    if (!finalise.ok) {
                        const reason = this.reasonOf(finalise);
                        await this.load(false);
                        this.stage = 'idle';

                        // A failed migration has already put the previous
                        // release back by the time this answers.
                        this.error = (reason.startsWith('migration_failed') ? this.labels.failed_restored : this.labels.failed) + ' ' + reason;

                        return;
                    }

                    const warnings = finalise.body.data?.warnings ?? [];
                    if (warnings.length) {
                        this.warning = warnings.map((w) => this.labels['warning_' + w] ?? w).join(' ');
                    }

                    this.doneText = this.labels.done;
                    this.stage = 'done';
                    setTimeout(() => window.location.reload(), warnings.length ? 8000 : 2000);
                },

                async rollback() {
                    this.busy = true;
                    this.error = '';

                    try {
                        const result = await this.post('/api/update/rollback');

                        if (result.ok) {
                            this.doneText = this.labels.rolled_back;
                            this.stage = 'done';
                            setTimeout(() => window.location.reload(), 2000);
                        } else {
                            this.error = this.reasonOf(result);
                            await this.load(false);
                        }
                    } finally {
                        this.busy = false;
                    }
                },
            });
        </script>
    @endpush
</x-layout.app>
