<x-layout.app :title="__('i18n::messages.settings.tab_design')">
    <div x-data="designPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.settings') }}</h1>

        <x-settings-tabs current="design" />

        <div x-show="loading" x-cloak class="mt-6 text-sm text-ink-faint">
            {{ __('i18n::messages.common.loading') }}
        </div>

        <div x-show="!loading" x-cloak class="mt-6 max-w-2xl">
            <p class="text-sm text-ink-muted">{{ __('i18n::messages.settings.design_subtitle') }}</p>

            {{-- Accent --}}
            <div class="mt-5">
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.theme.appearance') }}</h2>
                <p class="mt-0.5 text-sm text-ink-muted">{{ __('i18n::messages.theme.appearance_subtitle') }}</p>

                <div class="mt-3 flex items-center gap-3">
                    <input
                        type="color"
                        x-model="brandColor"
                        @input="previewBrand()"
                        class="size-10 shrink-0 cursor-pointer rounded-lg border border-line bg-surface p-1"
                        aria-label="{{ __('i18n::messages.theme.accent_color') }}"
                    >
                    <input
                        type="text"
                        x-model="brandColor"
                        @input="previewBrand()"
                        maxlength="7"
                        class="w-28 rounded-lg border border-line bg-surface px-3 py-2 font-mono text-sm text-ink focus:border-brand-strong focus:outline-none"
                    >
                    <button
                        type="button"
                        :disabled="resettingBrand"
                        @click="resetBrand()"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-line px-3 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink disabled:opacity-50"
                    >
                        <x-icon name="refresh" class="size-4" />
                        {{ __('i18n::messages.theme.reset_default') }}
                    </button>
                </div>
            </div>

            {{-- Full palette --}}
            <div class="mt-6 border-t border-line-soft pt-6">
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.settings.design_palette_title') }}</h2>
                <p class="mt-0.5 text-sm text-ink-muted">{{ __('i18n::messages.settings.design_palette_subtitle') }}</p>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs font-semibold uppercase tracking-wider text-ink-faint">
                            <tr>
                                <th class="py-2 pr-3"></th>
                                <th class="px-3 py-2">{{ __('i18n::messages.settings.design_mode_dark') }}</th>
                                <th class="px-3 py-2">{{ __('i18n::messages.settings.design_mode_light') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            <template x-for="tok in tokens" :key="tok.key">
                                <tr>
                                    <td class="py-2.5 pr-3 text-ink-muted" x-text="tok.label"></td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <input
                                                type="color"
                                                x-model="dark[tok.key]"
                                                @input="preview()"
                                                class="size-8 shrink-0 cursor-pointer rounded-md border border-line bg-surface p-0.5"
                                            >
                                            <input
                                                type="text"
                                                x-model="dark[tok.key]"
                                                @input="preview()"
                                                maxlength="7"
                                                class="w-24 rounded-md border border-line bg-surface px-2 py-1 font-mono text-xs text-ink focus:border-brand-strong focus:outline-none"
                                            >
                                        </div>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <input
                                                type="color"
                                                x-model="light[tok.key]"
                                                @input="preview()"
                                                class="size-8 shrink-0 cursor-pointer rounded-md border border-line bg-surface p-0.5"
                                            >
                                            <input
                                                type="text"
                                                x-model="light[tok.key]"
                                                @input="preview()"
                                                maxlength="7"
                                                class="w-24 rounded-md border border-line bg-surface px-2 py-1 font-mono text-xs text-ink focus:border-brand-strong focus:outline-none"
                                            >
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-xs text-ink-faint">{{ __('i18n::messages.settings.design_preview_hint') }}</p>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <button
                    type="button"
                    :disabled="saving"
                    @click="save()"
                    class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    <span x-show="!saving">{{ __('i18n::messages.common.save') }}</span>
                    <span x-show="saving" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                </button>
                <button
                    type="button"
                    :disabled="resettingPalette"
                    @click="resetPalette()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-line px-3 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink disabled:opacity-50"
                >
                    <x-icon name="refresh" class="size-4" />
                    {{ __('i18n::messages.settings.design_reset_palette') }}
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
            window.designPage = () => ({
                loading: true,
                saving: false,
                saved: false,
                error: false,
                resettingBrand: false,
                resettingPalette: false,

                brandColor: '#00ffe3',
                dark: {},
                light: {},

                tokens: [
                    { key: 'surface', label: @js(__('i18n::messages.settings.design_token_surface')) },
                    { key: 'surface_raised', label: @js(__('i18n::messages.settings.design_token_surface_raised')) },
                    { key: 'canvas', label: @js(__('i18n::messages.settings.design_token_canvas')) },
                    { key: 'line', label: @js(__('i18n::messages.settings.design_token_line')) },
                    { key: 'line_soft', label: @js(__('i18n::messages.settings.design_token_line_soft')) },
                    { key: 'ink', label: @js(__('i18n::messages.settings.design_token_ink')) },
                    { key: 'ink_muted', label: @js(__('i18n::messages.settings.design_token_ink_muted')) },
                    { key: 'ink_faint', label: @js(__('i18n::messages.settings.design_token_ink_faint')) },
                ],

                tokenVar: {
                    surface: '--color-surface', surface_raised: '--color-surface-raised', canvas: '--color-canvas',
                    line: '--color-line', line_soft: '--color-line-soft',
                    ink: '--color-ink', ink_muted: '--color-ink-muted', ink_faint: '--color-ink-faint',
                },

                // app.css's own factory values - a color picker needs a
                // real hex to show, so an untouched token displays its true
                // current color (the default) rather than defaulting to
                // black the way an empty <input type="color"> would.
                factoryDark: {
                    surface: '#18181b', surface_raised: '#1f1f23', canvas: '#0c0c0e',
                    line: '#2b2b30', line_soft: '#232327',
                    ink: '#f4f4f5', ink_muted: '#a1a1aa', ink_faint: '#71717a',
                },
                factoryLight: {
                    surface: '#ffffff', surface_raised: '#f4f4f5', canvas: '#f9fafb',
                    line: '#e4e4e7', line_soft: '#ececef',
                    ink: '#18181b', ink_muted: '#52525b', ink_faint: '#a1a1aa',
                },

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                async init() {
                    try {
                        const res = await fetch('/api/settings', { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.brandColor = body.data.brand_color || '#00ffe3';
                        const theme = body.data.theme_colors || {};
                        this.dark = { ...this.factoryDark, ...theme.dark };
                        this.light = { ...this.factoryLight, ...theme.light };
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                previewBrand() {
                    document.documentElement.style.setProperty('--color-brand', this.brandColor);
                },

                // Live-applies whichever mode is actually on screen right
                // now, so editing the palette shows its effect immediately
                // instead of only after a save + reload.
                preview() {
                    const active = document.documentElement.getAttribute('data-theme') === 'light' ? this.light : this.dark;
                    for (const [key, cssVar] of Object.entries(this.tokenVar)) {
                        if (active[key]) document.documentElement.style.setProperty(cssVar, active[key]);
                    }
                },

                async resetBrand() {
                    this.resettingBrand = true;
                    this.error = false;
                    try {
                        const res = await fetch('/api/settings/brand-color/reset', {
                            method: 'POST',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.brandColor = body.data.brand_color;
                        this.previewBrand();
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.resettingBrand = false;
                    }
                },

                async resetPalette() {
                    if (!confirm(@js(__('i18n::messages.settings.design_reset_confirm')))) return;
                    this.resettingPalette = true;
                    this.error = false;
                    try {
                        const res = await fetch('/api/settings/theme/reset', {
                            method: 'POST',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        if (!res.ok) throw new Error('request_failed');
                        this.dark = {};
                        this.light = {};
                        window.location.reload();
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.resettingPalette = false;
                    }
                },

                async save() {
                    this.saving = true;
                    this.saved = false;
                    this.error = false;
                    try {
                        const [brandRes, themeRes] = await Promise.all([
                            fetch('/api/settings', {
                                method: 'PUT',
                                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                                body: JSON.stringify({ brand_color: this.brandColor }),
                            }),
                            fetch('/api/settings/theme', {
                                method: 'PUT',
                                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                                body: JSON.stringify({ dark: this.dark, light: this.light }),
                            }),
                        ]);
                        if (!brandRes.ok || !themeRes.ok) throw new Error('request_failed');
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
