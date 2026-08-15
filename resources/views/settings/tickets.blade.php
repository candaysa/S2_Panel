<x-layout.app :title="__('i18n::messages.nav.settings')">
    <div x-data="ticketSettingsPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.settings') }}</h1>

        <x-settings-tabs current="tickets" />

        <div x-show="loading" x-cloak class="mt-6 text-sm text-ink-faint">
            {{ __('i18n::messages.common.loading') }}
        </div>

        <div x-show="!loading" x-cloak class="mt-6 max-w-xl">
            <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.settings.ticket_staff_title') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.settings.ticket_staff_subtitle') }}</p>

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($flags as $flag)
                    <label
                        class="flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm transition-colors"
                        :class="selected.includes('{{ $flag }}') ? flagColorClass('{{ $flag }}') + ' border-transparent ring-1' : 'text-ink-muted hover:bg-surface-raised'"
                    >
                        <input type="checkbox" value="{{ $flag }}" x-model="selected" class="sr-only">
                        {{ $flag }}
                    </label>
                @endforeach
            </div>

            <p x-show="selected.length === 0" x-cloak class="mt-3 text-xs text-ink-faint">
                {{ __('i18n::messages.settings.ticket_staff_empty') }}
            </p>

            <div class="mt-4 flex items-center gap-3">
                <button
                    type="button"
                    :disabled="saving"
                    @click="save()"
                    class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    <span x-show="!saving">{{ __('i18n::messages.common.save') }}</span>
                    <span x-show="saving" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                </button>
                <span x-show="saved" x-cloak class="text-sm text-brand-strong">✓</span>
            </div>

            <p x-show="error" x-cloak class="mt-3 text-sm text-red-400">
                {{ __('i18n::messages.common.error') }}
            </p>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.ticketSettingsPage = () => ({
                loading: true,
                saving: false,
                saved: false,
                error: false,
                selected: [],

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                async init() {
                    try {
                        const res = await fetch('/api/settings', { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        const raw = body.data.ticket_staff_flags ?? '';
                        this.selected = raw.split(',').map((f) => f.trim()).filter(Boolean);
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                async save() {
                    this.saving = true;
                    this.saved = false;
                    this.error = false;
                    try {
                        const res = await fetch('/api/settings', {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify({ ticket_staff_flags: this.selected.join(',') }),
                        });
                        if (!res.ok) throw new Error('request_failed');
                        this.saved = true;
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.saving = false;
                    }
                },
            });
        </script>
    @endpush
</x-layout.app>
