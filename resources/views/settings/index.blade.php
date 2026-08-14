<x-layout.app :title="__('i18n::messages.nav.settings')">
    <div
        x-data="{
            loading: true,
            saving: false,
            error: false,
            saved: false,
            form: { site_name: '', site_description: '', default_locale: 'en', timezone: '', brand_color: '#00ffe3' },
            logoUploading: false,
            faviconUploading: false,
            resettingColor: false,
            applyBrandColor() {
                document.documentElement.style.setProperty('--color-brand', this.form.brand_color);
            },
            async resetBrandColor() {
                this.resettingColor = true;
                this.error = false;
                try {
                    const res = await fetch('/api/settings/brand-color/reset', {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                    });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.form.brand_color = body.data.brand_color;
                    this.applyBrandColor();
                } catch (e) {
                    this.error = true;
                } finally {
                    this.resettingColor = false;
                }
            },
            async init() {
                try {
                    const res = await fetch('/api/settings', { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    Object.assign(this.form, body.data);
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
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify(this.form),
                    });
                    if (!res.ok) throw new Error('request_failed');
                    this.saved = true;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.saving = false;
                }
            },
            async upload(kind, event) {
                const file = event.target.files[0];
                if (!file) return;
                const flag = kind === 'logo' ? 'logoUploading' : 'faviconUploading';
                this[flag] = true;
                this.error = false;
                try {
                    const data = new FormData();
                    data.append('file', file);
                    const res = await fetch(`/api/settings/${kind}`, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: data,
                    });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.form[kind] = body.data.path;
                } catch (e) {
                    this.error = true;
                } finally {
                    this[flag] = false;
                    event.target.value = '';
                }
            },
        }"
        x-init="init()"
    >
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.settings') }}</h1>

        <div x-show="loading" x-cloak class="mt-6 text-sm text-ink-faint">
            {{ __('i18n::messages.common.loading') }}
        </div>

        <form x-show="!loading" x-cloak @submit.prevent="save()" class="mt-6 max-w-xl space-y-5">
            <div>
                <label class="block text-sm font-medium text-ink-muted" for="site_name">{{ __('i18n::messages.nav.dashboard') }} — Site name</label>
                <input
                    id="site_name"
                    type="text"
                    x-model="form.site_name"
                    class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                >
            </div>

            <div>
                <label class="block text-sm font-medium text-ink-muted" for="site_description">Site description</label>
                <textarea
                    id="site_description"
                    x-model="form.site_description"
                    rows="3"
                    class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                ></textarea>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-ink-muted" for="default_locale">Default locale</label>
                    <select
                        id="default_locale"
                        x-model="form.default_locale"
                        class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                    >
                        <option value="en">English</option>
                        <option value="tr">Türkçe</option>
                        <option value="de">Deutsch</option>
                        <option value="fr">Français</option>
                        <option value="it">Italiano</option>
                        <option value="ru">Русский</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-ink-muted" for="timezone">Timezone</label>
                    <input
                        id="timezone"
                        type="text"
                        x-model="form.timezone"
                        class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                    >
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-ink-muted">Logo</label>
                    <input
                        type="file"
                        accept="image/*"
                        @change="upload('logo', $event)"
                        class="mt-1 block w-full text-sm text-ink-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-raised file:px-3 file:py-2 file:text-sm file:text-ink hover:file:bg-line"
                    >
                    <p x-show="logoUploading" x-cloak class="mt-1 text-xs text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-ink-muted">Favicon</label>
                    <input
                        type="file"
                        accept="image/*"
                        @change="upload('favicon', $event)"
                        class="mt-1 block w-full text-sm text-ink-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-raised file:px-3 file:py-2 file:text-sm file:text-ink hover:file:bg-line"
                    >
                    <p x-show="faviconUploading" x-cloak class="mt-1 text-xs text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
                </div>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.theme.appearance') }}</h2>
                <p class="mt-0.5 text-sm text-ink-muted">{{ __('i18n::messages.theme.appearance_subtitle') }}</p>

                <div class="mt-3 flex items-center gap-3">
                    <input
                        type="color"
                        x-model="form.brand_color"
                        @input="applyBrandColor()"
                        class="size-10 shrink-0 cursor-pointer rounded-lg border border-line bg-surface p-1"
                        aria-label="{{ __('i18n::messages.theme.accent_color') }}"
                    >
                    <input
                        type="text"
                        x-model="form.brand_color"
                        @input="applyBrandColor()"
                        maxlength="7"
                        class="w-28 rounded-lg border border-line bg-surface px-3 py-2 font-mono text-sm text-ink focus:border-brand-strong focus:outline-none"
                    >
                    <button
                        type="button"
                        :disabled="resettingColor"
                        @click="resetBrandColor()"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-line px-3 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink disabled:opacity-50"
                    >
                        <x-icon name="refresh" class="size-4" />
                        {{ __('i18n::messages.theme.reset_default') }}
                    </button>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="saving"
                    class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    <span x-show="!saving">{{ __('i18n::messages.common.save') }}</span>
                    <span x-show="saving" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                </button>
                <span x-show="saved" x-cloak class="text-sm text-brand-strong">✓</span>
            </div>
        </form>

        <p x-show="error" x-cloak class="mt-6 text-sm text-red-400">
            {{ __('i18n::messages.common.error') }}
        </p>

        <div x-show="!loading" x-cloak class="mt-8 max-w-xl rounded-xl border border-line bg-surface p-5">
            <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.backup.title') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.backup.subtitle') }}</p>
            <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.backup.plugins_note') }}</p>

            <a
                href="{{ route('settings.backup') }}"
                class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-line px-4 py-2.5 text-sm font-medium text-ink transition-colors hover:bg-surface-raised"
            >
                <x-icon name="upload" class="size-4 rotate-180" />
                {{ __('i18n::messages.backup.download') }}
            </a>

            <p class="mt-3 text-xs text-ink-faint">{{ __('i18n::messages.backup.warning') }}</p>
        </div>
    </div>
</x-layout.app>
