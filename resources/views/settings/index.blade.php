<x-layout.app :title="__('i18n::messages.nav.settings')">
    {{-- The component lives in a pushed <script> rather than inline in
         x-data. An inline attribute is delimited by double quotes, so a
         single " anywhere inside it - even in a code comment - closes the
         attribute early and dumps the rest of the component onto the page as
         text. That is exactly what happened here. --}}
    <div x-data="settingsPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.settings') }}</h1>

        <x-settings-tabs current="general" />

        {{-- Site totals live here rather than as their own page: they are a
             glance at how much the panel is holding, which is a settings-time
             question, not something to navigate to. --}}
        <div x-show="!loading" x-cloak class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <template x-for="stat in siteStats" :key="stat.label">
                <div class="rounded-xl border border-line bg-surface p-4">
                    <p class="text-[11px] uppercase tracking-wider text-ink-faint" x-text="stat.label"></p>
                    <p class="mt-1 text-xl font-semibold text-ink" x-text="stat.value"></p>
                </div>
            </template>
        </div>

<div x-show="loading" x-cloak class="mt-6 text-sm text-ink-faint">
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
                    <label class="block text-sm font-medium text-ink-muted" for="timezone">{{ __('i18n::messages.settings.timezone') }}</label>
                    {{-- Built from PHP's own tz database and grouped by
                         region, so it cannot drift out of date and the list
                         stays navigable at ~400 entries. UTC is pulled to the
                         top because it is the sensible default for a panel
                         serving players in several countries. --}}
                    <select
                        id="timezone"
                        x-model="form.timezone"
                        class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                    >
                        @php
                            $zones = collect(timezone_identifiers_list())
                                ->reject(fn (string $tz): bool => $tz === 'UTC')
                                ->groupBy(fn (string $tz): string => str_contains($tz, '/') ? explode('/', $tz)[0] : 'Other')
                                ->sortKeys();
                        @endphp
                        <option value="UTC">UTC</option>
                        @foreach ($zones as $region => $list)
                            <optgroup label="{{ $region }}">
                                @foreach ($list as $tz)
                                    <option value="{{ $tz }}">{{ str_replace(['_', '/'], [' ', ' / '], $tz) }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
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

            <p class="text-sm text-ink-faint">
                {{ __('i18n::messages.theme.moved_to_design') }}
                <a href="{{ route('settings.design.page') }}" class="text-brand-strong hover:underline">{{ __('i18n::messages.settings.tab_design') }}</a>
            </p>

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

        <div x-show="!loading" x-cloak class="mt-6 max-w-xl rounded-xl border border-line bg-surface p-5">
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

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.settingsPage = () => ({
                loading: true,
                saving: false,
                error: false,
                saved: false,
                form: { site_name: '', site_description: '', default_locale: 'en', timezone: 'UTC' },
                logoUploading: false,
                faviconUploading: false,

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                counts: { servers: null, bans: null, mutes: null, admins: null },

                get siteStats() {
                    const t = @js(__('i18n::messages.dashboard'));
                    const n = (v) => v === null || v === undefined ? '—' : v.toLocaleString();
                    return [
                        { label: t.card_servers, value: n(this.counts.servers) },
                        { label: t.card_admins, value: n(this.counts.admins) },
                        { label: t.card_bans, value: n(this.counts.bans) },
                        { label: t.card_mutes, value: n(this.counts.mutes) },
                    ];
                },

                async init() {
                    try {
                        const res = await fetch('/api/settings', { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        Object.assign(this.form, body.data);

                        // Reuse the dashboard aggregate rather than counting
                        // the same rows a second time.
                        const d = await fetch('/api/dashboard', { headers: { Accept: 'application/json' } });
                        if (d.ok) this.counts = (await d.json()).data.counts;
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
                            body: JSON.stringify(this.form),
                        });
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.saved = true;

                        // Every label was rendered server-side in the previous
                        // locale, so a language change only lands on a fresh
                        // render - reload once rather than leaving the owner to
                        // press F5 themselves.
                        if (body.meta?.locale_changed) {
                            setTimeout(() => window.location.reload(), 500);
                        }
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
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
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
            });
        </script>
    @endpush
</x-layout.app>
