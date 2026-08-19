<x-layout.app :title="__('i18n::messages.nav.settings')">
    {{-- The component lives in a pushed <script> rather than inline in
         x-data. An inline attribute is delimited by double quotes, so a
         single " anywhere inside it - even in a code comment - closes the
         attribute early and dumps the rest of the component onto the page as
         text. That is exactly what happened here. --}}
    <div x-data="settingsPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.settings') }}</h1>

        <x-settings-tabs current="general" />

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

        {{-- Site images. Their own card rather than two file inputs in the
             middle of the general form: uploading here is immediate (there is
             no "save" step for these, unlike every other field around them),
             and each shows what is currently live - an owner who cannot see
             the favicon they set has no way to tell an upload from a
             no-op. --}}
        <div x-show="!loading" x-cloak class="mt-6 max-w-xl rounded-xl border border-line bg-surface p-5">
            <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.settings.images_title') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.settings.images_subtitle') }}</p>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                @foreach ([['logo', 'Logo', 'logoUploading', 'size-14'], ['favicon', 'Favicon', 'faviconUploading', 'size-9']] as [$kind, $label, $flag, $box])
                    <div>
                        <label class="block text-sm font-medium text-ink-muted">{{ $label }}</label>
                        <div class="mt-2 flex items-center gap-3">
                            <span class="flex {{ $box }} shrink-0 items-center justify-center overflow-hidden rounded-lg border border-line bg-canvas">
                                <img
                                    x-show="form.{{ $kind }}"
                                    :src="'/' + form.{{ $kind }}"
                                    alt=""
                                    class="max-h-full max-w-full object-contain"
                                >
                                <span x-show="!form.{{ $kind }}" class="text-xs text-ink-faint">&mdash;</span>
                            </span>
                            <input
                                type="file"
                                accept="image/*"
                                @change="upload('{{ $kind }}', $event)"
                                class="block w-full min-w-0 text-sm text-ink-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-raised file:px-3 file:py-2 file:text-sm file:text-ink hover:file:bg-line"
                            >
                        </div>
                        <p x-show="{{ $flag }}" x-cloak class="mt-1 text-xs text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- SMTP. The panel really does send mail (an approved ban appeal
             notifies the player), but config/mail.php reads .env, which a
             packaged install gives an owner no comfortable way to edit.
             Leaving the host blank hands outgoing mail back to .env - see
             App\Support\MailConfig. --}}
        <div x-show="!loading" x-cloak class="mt-6 max-w-xl rounded-xl border border-line bg-surface p-5">
            <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.smtp.title') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.smtp.subtitle') }}</p>

            <form @submit.prevent="saveSmtp()" class="mt-4 space-y-4">
                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_7rem]">
                    <div>
                        <label class="block text-sm font-medium text-ink-muted" for="mail_host">{{ __('i18n::messages.smtp.host') }}</label>
                        <input id="mail_host" type="text" x-model="smtp.mail_host" placeholder="smtp.example.com" class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink-muted" for="mail_port">{{ __('i18n::messages.smtp.port') }}</label>
                        <input id="mail_port" type="number" min="1" max="65535" x-model.number="smtp.mail_port" class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-ink-muted" for="mail_encryption">{{ __('i18n::messages.smtp.encryption') }}</label>
                    <select id="mail_encryption" x-model="smtp.mail_encryption" class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                        <option value="tls">STARTTLS</option>
                        <option value="ssl">SSL/TLS</option>
                        <option value="none">{{ __('i18n::messages.smtp.encryption_none') }}</option>
                    </select>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-ink-muted" for="mail_username">{{ __('i18n::messages.smtp.username') }}</label>
                        <input id="mail_username" type="text" autocomplete="off" x-model="smtp.mail_username" class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink-muted" for="mail_password">{{ __('i18n::messages.smtp.password') }}</label>
                        {{-- Never pre-filled: the API does not return the
                             stored password in any form, so an empty box
                             means "keep it" rather than "clear it". --}}
                        <input
                            id="mail_password"
                            type="password"
                            autocomplete="new-password"
                            x-model="smtp.mail_password"
                            :placeholder="smtpPasswordSet ? @js(__('i18n::messages.smtp.password_kept')) : ''"
                            class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none"
                        >
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-ink-muted" for="mail_from_address">{{ __('i18n::messages.smtp.from_address') }}</label>
                        <input id="mail_from_address" type="email" x-model="smtp.mail_from_address" placeholder="noreply@example.com" class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink-muted" for="mail_from_name">{{ __('i18n::messages.smtp.from_name') }}</label>
                        <input id="mail_from_name" type="text" x-model="smtp.mail_from_name" class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="smtpSaving" class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50">
                        <span x-show="!smtpSaving">{{ __('i18n::messages.common.save') }}</span>
                        <span x-show="smtpSaving" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                    </button>
                    <span x-show="smtpSaved" x-cloak class="text-sm text-brand-strong">&check;</span>
                    <span x-show="smtpError" x-cloak class="text-sm text-red-400">{{ __('i18n::messages.common.error') }}</span>
                </div>
            </form>

            {{-- Test send. Its own sub-section because it answers a different
                 question from "are these values stored", and it reports the
                 transport's own error verbatim: the difference between a
                 wrong password, a blocked port and an unresolvable host is
                 the entire answer when mail will not go through, and
                 "Something went wrong" contains none of it. --}}
            <div class="mt-5 border-t border-line pt-5">
                <label class="block text-sm font-medium text-ink-muted" for="smtp_test_to">{{ __('i18n::messages.smtp.test_title') }}</label>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.smtp.test_hint') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <input id="smtp_test_to" type="email" x-model="smtpTestTo" placeholder="you@example.com" class="min-w-0 flex-1 rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none">
                    <button
                        type="button"
                        :disabled="smtpTesting || !smtpTestTo"
                        @click="testSmtp()"
                        class="shrink-0 rounded-lg border border-line px-4 py-2 text-sm font-medium text-ink transition-colors hover:bg-surface-raised disabled:opacity-50"
                    >
                        <span x-show="!smtpTesting">{{ __('i18n::messages.smtp.test_send') }}</span>
                        <span x-show="smtpTesting" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                    </button>
                </div>

                <p x-show="smtpTestOk" x-cloak class="mt-2 rounded-lg bg-brand-soft px-3 py-2 text-sm text-brand-strong" x-text="smtpTestOk"></p>
                <p x-show="smtpTestError" x-cloak class="mt-2 break-words rounded-lg bg-red-500/10 px-3 py-2 font-mono text-xs text-red-400" x-text="smtpTestError"></p>
            </div>
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

                smtp: {
                    mail_host: '', mail_port: 587, mail_encryption: 'tls',
                    mail_username: '', mail_password: '',
                    mail_from_address: '', mail_from_name: '',
                },
                smtpPasswordSet: false,
                smtpSaving: false,
                smtpSaved: false,
                smtpError: false,
                smtpTestTo: '',
                smtpTesting: false,
                smtpTestOk: '',
                smtpTestError: '',

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                async init() {
                    try {
                        const res = await fetch('/api/settings', { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        Object.assign(this.form, body.data);

                        // Same payload, different form. mail_password is
                        // absent by design (see SettingsController::index),
                        // so it stays blank and only mail_password_set says
                        // whether one exists.
                        for (const key of Object.keys(this.smtp)) {
                            if (key !== 'mail_password' && body.data[key] !== undefined && body.data[key] !== null) {
                                this.smtp[key] = body.data[key];
                            }
                        }
                        this.smtpPasswordSet = !!body.data.mail_password_set;
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

                async saveSmtp() {
                    this.smtpSaving = true;
                    this.smtpSaved = false;
                    this.smtpError = false;
                    this.smtpTestOk = '';
                    this.smtpTestError = '';
                    try {
                        // An untouched password field means "keep the stored
                        // one", so it is omitted rather than sent empty -
                        // sending '' is how the API is told to clear it.
                        const payload = { ...this.smtp };
                        if (payload.mail_password === '') delete payload.mail_password;

                        const res = await fetch('/api/settings/smtp', {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify(payload),
                        });
                        if (!res.ok) throw new Error('request_failed');
                        if (this.smtp.mail_password !== '') this.smtpPasswordSet = true;
                        this.smtp.mail_password = '';
                        this.smtpSaved = true;
                    } catch (e) {
                        this.smtpError = true;
                    } finally {
                        this.smtpSaving = false;
                    }
                },

                async testSmtp() {
                    this.smtpTesting = true;
                    this.smtpTestOk = '';
                    this.smtpTestError = '';
                    try {
                        const res = await fetch('/api/settings/smtp/test', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify({ to: this.smtpTestTo }),
                        });
                        const body = await res.json().catch(() => ({}));
                        // The transport's own message, verbatim - see the
                        // markup comment above the test section.
                        if (!res.ok) throw new Error(body.message || 'request_failed');
                        this.smtpTestOk = @js(__('i18n::messages.smtp.test_ok'));
                    } catch (e) {
                        this.smtpTestError = e.message;
                    } finally {
                        this.smtpTesting = false;
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
