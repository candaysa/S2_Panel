@php
    $settingService = app(\App\Modules\Settings\App\Services\SettingService::class);
    $siteName = $settingService->get('site_name') ?: 'S2 Panel';
    $siteLogo = $settingService->get('logo')
        ? asset($settingService->get('logo'))
        : asset('images/logo.png');
    $siteFavicon = $settingService->get('favicon')
        ? asset($settingService->get('favicon'))
        : asset('favicon-32x32.png');

    // Every module except the always-on core ones (auth/install/modules) is
    // installer-selectable - see InstallController::modules() which applies
    // the exact same exclusion list when persisting the choice.
    $installableModules = collect(config('modules.modules', []))
        ->except(['auth', 'install', 'modules'])
        ->keys()
        ->values();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ __('i18n::messages.install.title') }} · {{ $siteName }}</title>

    <link rel="icon" type="image/png" sizes="32x32" href="{{ $siteFavicon }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.head-theme')
</head>
<body class="relative flex min-h-screen bg-canvas px-4 py-10 text-ink antialiased sm:px-6">
    <x-theme-toggle class="absolute right-4 top-4" />

    {{-- m-auto rather than justify-center on the body: with flexbox, centering
         a child that is taller than the viewport (the database step has five
         connection blocks) makes its top overflow out of reach. Auto margins
         centre it while still letting it scroll from the top. --}}
    <div
        class="m-auto w-full max-w-2xl"
        x-data="{
            step: 1,
            steps: ['locale', 'database', 'steam', 'modules', 'complete'],
            loading: false,
            error: null,

            restoring: false,
            restoreError: null,
            restoreDone: null,

            locale: '{{ app()->getLocale() }}',

            db: {
                panel: { host: '127.0.0.1', port: '3306', database: '', username: '', password: '' },
                plugins: { host: '127.0.0.1', port: '3306', database: '', username: '', password: '' },
            },

            steam: { api_key: '', client_id: '', client_secret: '', callback_url: '', owner_steam_id: '' },

            modules: {
                @foreach ($installableModules as $key)
                    {{ $key }}: true,
                @endforeach
            },

            csrf() {
                return document.querySelector('meta[name=csrf-token]').content;
            },

            async post(url, body) {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrf(),
                    },
                    body: JSON.stringify(body),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    const err = new Error(data.message || 'request_failed');
                    err.data = data;
                    throw err;
                }
                return data;
            },

            // Picking a language applies it straight away. Every label on this
            // page is rendered by Blade, so the only way to show the new one is
            // to ask the server for the page again - hence the reload.
            async applyLocale() {
                this.loading = true;
                this.error = null;
                try {
                    await this.post('/api/install/locale', { locale: this.locale });
                    window.location.reload();
                } catch (e) {
                    this.error = '{{ __('i18n::messages.install.generic_error') }}';
                    this.loading = false;
                }
            },

            // Continue must NOT reload. `step` is Alpine state and starts at 1
            // on every page load, so reloading here made step 1 a dead end -
            // the locale saved, the page came back, and you were on step 1
            // again with no way forward. It still posts, so the default locale
            // is persisted even when the dropdown was never touched.
            async submitLocale() {
                this.loading = true;
                this.error = null;
                try {
                    await this.post('/api/install/locale', { locale: this.locale });
                    this.step = 2;
                } catch (e) {
                    this.error = '{{ __('i18n::messages.install.generic_error') }}';
                } finally {
                    this.loading = false;
                }
            },

            async submitDatabase() {
                this.loading = true;
                this.error = null;
                try {
                    await this.post('/api/install/database', this.db);
                    this.step = 3;
                } catch (e) {
                    this.error = e.data?.message === 'database_connection_failed'
                        ? '{{ __('i18n::messages.install.db_connection_failed') }}: ' + (e.data.errors?.connections ?? []).join(', ')
                        : '{{ __('i18n::messages.install.generic_error') }}';
                } finally {
                    this.loading = false;
                }
            },

            async submitSteam() {
                this.loading = true;
                this.error = null;
                try {
                    await this.post('/api/install/steam', this.steam);
                    this.step = 4;
                } catch (e) {
                    this.error = '{{ __('i18n::messages.install.generic_error') }}';
                } finally {
                    this.loading = false;
                }
            },

            async submitModules() {
                this.loading = true;
                this.error = null;
                try {
                    await this.post('/api/install/modules', this.modules);
                    this.step = 5;
                } catch (e) {
                    this.error = '{{ __('i18n::messages.install.generic_error') }}';
                } finally {
                    this.loading = false;
                }
            },

            async complete() {
                this.loading = true;
                this.error = null;
                try {
                    await this.post('/api/install/complete', {});
                    window.location.href = '/login';
                } catch (e) {
                    this.error = '{{ __('i18n::messages.install.generic_error') }}';
                    this.loading = false;
                }
            },

            async restoreBackup(event) {
                const file = event.target.files[0];
                if (!file) return;

                this.restoring = true;
                this.restoreError = null;
                this.restoreDone = null;

                try {
                    const data = new FormData();
                    data.append('backup', file);
                    const res = await fetch('/api/install/restore-backup', {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrf(),
                        },
                        body: data,
                    });
                    const body = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        const key = {
                            invalid_zip_file: 'restore_invalid_zip',
                            backup_manifest_missing: 'restore_manifest_invalid',
                            backup_manifest_invalid: 'restore_manifest_invalid',
                            database_connection_failed: 'restore_db_connection_failed',
                        }[body.message] ?? 'restore_failed';
                        throw new Error(key);
                    }
                    this.restoreDone = body.data;
                    window.location.href = '/login';
                } catch (e) {
                    const messages = @js(collect(['restore_invalid_zip', 'restore_manifest_invalid', 'restore_db_connection_failed', 'restore_failed'])->mapWithKeys(fn ($key) => [$key => __('i18n::messages.install.'.$key)]));
                    this.restoreError = messages[e.message] ?? messages.restore_failed;
                } finally {
                    this.restoring = false;
                    event.target.value = '';
                }
            },
        }"
    >
        <div class="flex flex-col items-center text-center">
            <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="size-12 object-contain">
            <h1 class="mt-3 text-xl font-semibold text-ink">{{ __('i18n::messages.install.title') }}</h1>
            <p class="mt-1 text-sm text-ink-muted">{{ $siteName }}</p>
        </div>

        {{-- Restore from backup.zip — replaces every step below in one shot --}}
        <div x-show="step === 1" x-cloak class="mt-8 rounded-xl border border-dashed border-line bg-surface-raised/50 p-4">
            <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.install.restore_choice_title') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.install.restore_choice_body') }}</p>

            <label
                class="mt-3 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line bg-surface px-4 py-2.5 text-sm font-medium text-ink transition-colors hover:bg-line"
                :class="restoring ? 'pointer-events-none opacity-50' : ''"
            >
                <x-icon name="upload" class="size-4" />
                <span x-show="!restoring">{{ __('i18n::messages.install.restore_button') }}</span>
                <span x-show="restoring" x-cloak>{{ __('i18n::messages.install.restore_uploading') }}</span>
                <input type="file" accept=".zip" class="hidden" :disabled="restoring" @change="restoreBackup($event)">
            </label>

            <p x-show="restoreError" x-cloak class="mt-3 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400" x-text="restoreError"></p>
        </div>

        <div class="mt-6 flex items-center gap-3 text-xs uppercase tracking-wide text-ink-faint" x-show="step === 1" x-cloak>
            <span class="h-px flex-1 bg-line"></span>
            {{ __('i18n::messages.install.restore_or') }}
            <span class="h-px flex-1 bg-line"></span>
        </div>

        <div class="mt-8 rounded-xl border border-line bg-surface p-6">
            {{-- Step 1: Locale --}}
            <div x-show="step === 1" x-cloak>
                <h2 class="text-base font-semibold text-ink">{{ __('i18n::messages.install.step_locale') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.install.locale_prompt') }}</p>

                {{-- Native <select>: one control instead of a six-button grid,
                     and it stays usable on a phone where the grid wrapped. The
                     chevron is drawn by the wrapper because appearance-none
                     removes the platform one. --}}
                <div class="relative mt-4">
                    <select
                        x-model="locale"
                        @change="applyLocale()"
                        :disabled="loading"
                        class="w-full appearance-none rounded-lg border border-line bg-canvas px-3 py-2.5 pr-10 text-sm font-medium text-ink transition-colors hover:bg-surface-raised focus:border-brand-strong focus:outline-none disabled:opacity-50"
                    >
                        @foreach (['en' => 'English', 'tr' => 'Türkçe', 'de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'ru' => 'Русский'] as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <svg
                        class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-faint"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </div>

                <button
                    type="button"
                    :disabled="loading"
                    @click="submitLocale()"
                    class="mt-6 inline-flex w-full items-center justify-center rounded-lg bg-brand-strong px-4 py-2.5 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    <span x-show="!loading">{{ __('i18n::messages.install.next') }}</span>
                    <span x-show="loading" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                </button>
            </div>

            {{-- Step 2: Database --}}
            <div x-show="step === 2" x-cloak>
                <h2 class="text-base font-semibold text-ink">{{ __('i18n::messages.install.step_database') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.install.db_hint') }}</p>

                {{-- Two blocks, not five. Swiftly, CS2_Admin, CS2_Ranks, the
                     weapon skins plugin and VIPCore all share one database, so
                     they are asked for once and fanned out to the four
                     connections server-side (InstallController::database).
                     The panel stays separate because its own migrations create
                     tables - users, sessions, reports - that already exist in a
                     live Swiftly database. --}}
                <div class="mt-4 space-y-5">
                    @foreach ([
                        'panel' => [__('i18n::messages.install.db_panel_label'), __('i18n::messages.install.db_panel_hint')],
                        'plugins' => [__('i18n::messages.install.db_plugins_label'), __('i18n::messages.install.db_plugins_hint')],
                    ] as $connKey => [$connLabel, $connHint])
                        <fieldset class="rounded-lg border border-line-soft p-4">
                            <legend class="px-1 text-sm font-medium text-ink">{{ $connLabel }}</legend>
                            <p class="mb-3 text-xs text-ink-faint">{{ $connHint }}</p>

                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <input type="text" x-model="db.{{ $connKey }}.host" placeholder="{{ __('i18n::messages.install.db_host') }}" class="col-span-2 rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none sm:col-span-1">
                                <input type="text" x-model="db.{{ $connKey }}.port" placeholder="{{ __('i18n::messages.install.db_port') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                                <input type="text" x-model="db.{{ $connKey }}.database" placeholder="{{ __('i18n::messages.install.db_database') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                                <input type="text" x-model="db.{{ $connKey }}.username" placeholder="{{ __('i18n::messages.install.db_username') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                                <input type="password" x-model="db.{{ $connKey }}.password" placeholder="{{ __('i18n::messages.install.db_password') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                            </div>
                        </fieldset>
                    @endforeach
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="button" @click="step = 1" class="inline-flex items-center justify-center rounded-lg border border-line px-4 py-2.5 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                        {{ __('i18n::messages.install.back') }}
                    </button>
                    <button
                        type="button"
                        :disabled="loading"
                        @click="submitDatabase()"
                        class="inline-flex flex-1 items-center justify-center rounded-lg bg-brand-strong px-4 py-2.5 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        <span x-show="!loading">{{ __('i18n::messages.install.next') }}</span>
                        <span x-show="loading" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                    </button>
                </div>
            </div>

            {{-- Step 3: Steam & Owner --}}
            <div x-show="step === 3" x-cloak>
                <h2 class="text-base font-semibold text-ink">{{ __('i18n::messages.install.step_steam') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.install.steam_hint') }}</p>

                <div class="mt-4 space-y-3">
                    <input type="text" x-model="steam.api_key" placeholder="{{ __('i18n::messages.install.steam_api_key') }}" class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <input type="text" x-model="steam.client_id" placeholder="{{ __('i18n::messages.install.steam_client_id') }}" class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <input type="text" x-model="steam.client_secret" placeholder="{{ __('i18n::messages.install.steam_client_secret') }}" class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <input type="text" x-model="steam.callback_url" placeholder="{{ __('i18n::messages.install.steam_callback_url') }}" class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <input type="text" x-model="steam.owner_steam_id" placeholder="{{ __('i18n::messages.install.owner_steam_id') }}" class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="button" @click="step = 2" class="inline-flex items-center justify-center rounded-lg border border-line px-4 py-2.5 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                        {{ __('i18n::messages.install.back') }}
                    </button>
                    <button
                        type="button"
                        :disabled="loading"
                        @click="submitSteam()"
                        class="inline-flex flex-1 items-center justify-center rounded-lg bg-brand-strong px-4 py-2.5 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        <span x-show="!loading">{{ __('i18n::messages.install.next') }}</span>
                        <span x-show="loading" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                    </button>
                </div>
            </div>

            {{-- Step 4: Modules --}}
            <div x-show="step === 4" x-cloak>
                <h2 class="text-base font-semibold text-ink">{{ __('i18n::messages.install.step_modules') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.install.modules_prompt') }}</p>

                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    @foreach ($installableModules as $key)
                        <label class="flex items-center gap-2.5 rounded-lg border border-line-soft px-3 py-2.5 text-sm text-ink-muted transition-colors has-[:checked]:border-brand-strong has-[:checked]:text-ink">
                            <input type="checkbox" x-model="modules.{{ $key }}" class="size-4 rounded border-line text-brand-strong focus:ring-brand-strong">
                            {{ \Illuminate\Support\Str::headline($key) }}
                        </label>
                    @endforeach
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="button" @click="step = 3" class="inline-flex items-center justify-center rounded-lg border border-line px-4 py-2.5 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                        {{ __('i18n::messages.install.back') }}
                    </button>
                    <button
                        type="button"
                        :disabled="loading"
                        @click="submitModules()"
                        class="inline-flex flex-1 items-center justify-center rounded-lg bg-brand-strong px-4 py-2.5 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        <span x-show="!loading">{{ __('i18n::messages.install.next') }}</span>
                        <span x-show="loading" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                    </button>
                </div>
            </div>

            {{-- Step 5: Complete --}}
            <div x-show="step === 5" x-cloak>
                <h2 class="text-base font-semibold text-ink">{{ __('i18n::messages.install.complete_title') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.install.complete_body') }}</p>

                <div class="mt-6 flex gap-3">
                    <button type="button" @click="step = 4" class="inline-flex items-center justify-center rounded-lg border border-line px-4 py-2.5 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                        {{ __('i18n::messages.install.back') }}
                    </button>
                    <button
                        type="button"
                        :disabled="loading"
                        @click="complete()"
                        class="inline-flex flex-1 items-center justify-center rounded-lg bg-brand-strong px-4 py-2.5 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        <span x-show="!loading">{{ __('i18n::messages.install.finish') }}</span>
                        <span x-show="loading" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                    </button>
                </div>
            </div>

            <p x-show="error" x-cloak class="mt-4 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400" x-text="error"></p>

            {{-- Progress dots. One per step, the current one filled in the
                 highest-contrast ink colour (white on the dark theme, near
                 black on the light one) and widened into a pill so the
                 position reads at a glance without any numbers. Steps are not
                 clickable on purpose - each one persists to .env before the
                 next unlocks, so jumping ahead would skip that write. --}}
            <ol
                class="mt-6 flex items-center justify-center gap-1.5 border-t border-line-soft pt-5"
                :aria-label="`Step ${step} of ${steps.length}`"
            >
                <template x-for="(s, i) in steps" :key="s">
                    <li
                        class="h-1.5 rounded-full transition-all duration-300"
                        :class="step === i + 1 ? 'w-6 bg-ink' : (step > i + 1 ? 'w-1.5 bg-ink-faint' : 'w-1.5 bg-line')"
                        :aria-current="step === i + 1 ? 'step' : false"
                    ></li>
                </template>
            </ol>
        </div>
    </div>
</body>
</html>
