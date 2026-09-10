{{--
    Update notice, owner only.

    Rendered by the app layout, so it can appear on any page. The check runs
    once per browser session and is dismissible per version - an owner who
    says "later" should not be asked again on every navigation, but a NEW
    release must still get through, which is why the dismissal key carries
    the version.

    A notice only: installing happens on Settings > Updates, the one place
    that shows what the server can and cannot do, drives install + finalise,
    and can roll back. This used to install from here as well, and reported
    "Updated" even when the finalise step (the migrations) had failed.
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

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-surface-raised/40 p-4">
            <button type="button" @click="dismiss()"
                    class="rounded-lg px-3 py-2 text-sm text-ink-muted transition-colors hover:text-ink">
                {{ __('i18n::messages.update.later') }}
            </button>
            <a href="{{ route('settings.updates.page') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90">
                <x-icon name="upload" class="size-4" />
                {{ __('i18n::messages.update.view_update') }}
            </a>
        </div>
    </div>
</div>

@push('scripts')
    <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
        window.updatePrompt = () => ({
            open: false,
            release: { current: '', latest: '', notes: '', html_url: '', reason: null },

            async init() {
                // No point announcing an update on the page that installs it.
                if (window.location.pathname === '/settings/updates') return;

                try {
                    const res = await fetch('/api/update/status', { headers: { Accept: 'application/json' } });
                    // 401/403 simply means this viewer is not the owner.
                    if (!res.ok) return;
                    const body = await res.json();
                    this.release = body.data.release;

                    if (!this.release.available) return;
                    if (localStorage.getItem('update.dismissed') === this.release.latest) return;

                    this.open = true;
                } catch (e) {
                    // An update check must never interfere with the page.
                }
            },

            shortNotes() {
                const n = this.release.notes ?? '';
                return n.length > 600 ? n.slice(0, 600) + '…' : n;
            },

            dismiss() {
                // Per version, so the next release still gets through.
                if (this.release.latest) localStorage.setItem('update.dismissed', this.release.latest);
                this.open = false;
            },
        });
    </script>
@endpush
