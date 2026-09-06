{{--
    Update notice, owner only.

    Rendered by the app layout, so it can appear on any page. The check runs
    once per browser session and is dismissible per version - an owner who
    says "later" should not be asked again on every navigation, but a NEW
    release must still get through, which is why the dismissal key carries
    the version.
--}}
<div
    x-data="updatePrompt()"
    x-init="init()"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    :aria-label="@js(__('i18n::messages.update.title'))"
>
    <div class="absolute inset-0 bg-black/70" @click="dismiss()"></div>

    <div
        x-transition
        class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl"
        @keydown.escape.window="dismiss()"
    >
        <div class="flex items-start gap-4 border-b border-line p-5">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-soft">
                <x-icon name="upload" class="size-5 text-brand-strong" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-semibold text-ink">{{ __('i18n::messages.update.title') }}</h2>
                <p class="mt-0.5 text-sm text-ink-muted">
                    <span class="font-mono text-ink-faint" x-text="release.current"></span>
                    <span class="mx-1">&rarr;</span>
                    <span class="font-mono font-medium text-brand-strong" x-text="release.latest"></span>
                </p>
            </div>
            <button type="button" @click="dismiss()" class="shrink-0 rounded-lg p-1.5 text-ink-faint transition-colors hover:bg-surface-raised hover:text-ink">
                <x-icon name="close" class="size-4" />
            </button>
        </div>

        {{-- Release notes, truncated: this is a prompt, not a changelog
             viewer, and the full notes are one link away. --}}
        <div class="max-h-52 overflow-y-auto px-5 py-4" x-show="release.notes">
            <p class="whitespace-pre-line text-sm leading-relaxed text-ink-muted" x-text="shortNotes()"></p>
            <a x-show="release.html_url" :href="release.html_url" target="_blank" rel="noopener noreferrer"
               class="mt-2 inline-block text-xs text-brand-strong hover:underline">
                {{ __('i18n::messages.update.full_notes') }}
            </a>
        </div>

        {{-- Why the install button is unavailable, when it is. Naming the
             failing check is the difference between "it's broken" and a
             problem the owner can actually fix. --}}
        <div x-show="!canInstall" x-cloak class="mx-5 mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3">
            <p class="text-xs font-medium text-amber-400">{{ __('i18n::messages.update.cannot_install') }}</p>
            <ul class="mt-1.5 space-y-1">
                <template x-for="c in failedChecks" :key="c.key">
                    <li class="text-xs text-amber-400/90">
                        <span x-text="checkLabel(c.key)"></span>
                        <span x-show="c.detail" class="font-mono opacity-70" x-text="' — ' + c.detail"></span>
                    </li>
                </template>
                <li x-show="release.reason === 'no_installable_asset'" class="text-xs text-amber-400/90">
                    {{ __('i18n::messages.update.no_asset') }}
                </li>
            </ul>
        </div>

        <p x-show="error" x-cloak class="mx-5 mb-4 rounded-lg bg-red-500/10 px-3 py-2 text-xs text-red-400" x-text="error"></p>

        <div x-show="stage === 'done'" x-cloak class="mx-5 mb-4 rounded-lg bg-emerald-500/10 px-3 py-2 text-xs text-emerald-400">
            {{ __('i18n::messages.update.done') }}
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-surface-raised/40 p-4">
            <button type="button" @click="dismiss()" :disabled="busy"
                    class="rounded-lg px-3 py-2 text-sm text-ink-muted transition-colors hover:text-ink disabled:opacity-50">
                <span x-text="stage === 'done' ? @js(__('i18n::messages.common.close')) : @js(__('i18n::messages.update.later'))"></span>
            </button>
            <button
                type="button"
                x-show="canInstall && stage !== 'done'"
                @click="install()"
                :disabled="busy"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-60"
            >
                <x-icon name="refresh" class="size-4" ::class="busy && 'animate-spin'" />
                <span x-text="busyLabel()"></span>
            </button>
        </div>
    </div>
</div>

@push('scripts')
    <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
        window.updatePrompt = () => ({
            open: false,
            busy: false,
            stage: 'idle',
            error: '',
            canInstall: false,
            release: { current: '', latest: '', notes: '', html_url: '', reason: null },
            preflight: { ready: false, checks: [] },
            labels: @js(__('i18n::messages.update')),

            async init() {
                try {
                    const res = await fetch('/api/update/status', { headers: { Accept: 'application/json' } });
                    // 401/403 simply means this viewer is not the owner.
                    if (!res.ok) return;
                    const body = await res.json();
                    this.release = body.data.release;
                    this.preflight = body.data.preflight;
                    this.canInstall = body.data.can_install;

                    if (!this.release.available) return;
                    if (localStorage.getItem('update.dismissed') === this.release.latest) return;

                    this.open = true;
                } catch (e) {
                    // An update check must never interfere with the page.
                }
            },

            get failedChecks() {
                return (this.preflight.checks ?? []).filter((c) => !c.ok);
            },

            checkLabel(key) {
                return this.labels['check_' + key] ?? key;
            },

            shortNotes() {
                const n = this.release.notes ?? '';
                return n.length > 600 ? n.slice(0, 600) + '…' : n;
            },

            busyLabel() {
                if (this.stage === 'installing') return this.labels.installing;
                if (this.stage === 'finalising') return this.labels.finalising;
                return this.labels.install_now;
            },

            csrf() {
                return document.querySelector('meta[name=csrf-token]').content;
            },

            async install() {
                this.busy = true;
                this.error = '';
                this.stage = 'installing';
                try {
                    const res = await fetch('/api/update/install', {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                    const body = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        this.error = (body.errors?.reason?.[0]) ?? body.message ?? this.labels.failed;
                        this.stage = 'idle';
                        return;
                    }

                    // The swap is done; migrations have to run against the new
                    // code, which only exists from the next request onward.
                    this.stage = 'finalising';
                    await fetch('/api/update/finalise', {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    }).catch(() => {});

                    this.stage = 'done';
                    setTimeout(() => window.location.reload(), 1800);
                } catch (e) {
                    this.error = this.labels.failed;
                    this.stage = 'idle';
                } finally {
                    this.busy = false;
                }
            },

            dismiss() {
                // Per version, so the next release still gets through.
                if (this.release.latest) localStorage.setItem('update.dismissed', this.release.latest);
                this.open = false;
            },
        });
    </script>
@endpush
