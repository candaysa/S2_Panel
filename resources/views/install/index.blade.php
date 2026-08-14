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
<body class="relative min-h-screen bg-canvas px-4 py-10 text-ink antialiased sm:px-6">
    <x-theme-toggle class="absolute right-4 top-4" />

    <div
        class="mx-auto w-full max-w-2xl"
        x-data="{
            step: 1,
            steps: ['locale', 'database', 'steam', 'modules', 'complete'],
            loading: false,
            error: null,

            locale: '{{ app()->getLocale() }}',

            db: {
                panel: { host: '127.0.0.1', port: '3306', database: '', username: '', password: '' },
                swiftly: { host: '127.0.0.1', port: '3306', database: '', username: '', password: '' },
                ranks: { host: '127.0.0.1', port: '3306', database: '', username: '', password: '' },
                weaponskins: { host: '127.0.0.1', port: '3306', database: '', username: '', password: '' },
                vip: { host: '127.0.0.1', port: '3306', database: '', username: '', password: '' },
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

            async submitLocale() {
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
        }"
    >
        <div class="flex flex-col items-center text-center">
            <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="size-12 object-contain">
            <h1 class="mt-3 text-xl font-semibold text-ink">{{ __('i18n::messages.install.title') }}</h1>
            <p class="mt-1 text-sm text-ink-muted">{{ $siteName }}</p>
        </div>

        {{-- Step indicator --}}
        <ol class="mt-8 flex items-center justify-center gap-2">
            <template x-for="(s, i) in steps" :key="s">
                <li class="flex items-center gap-2">
                    <span
                        class="flex size-7 items-center justify-center rounded-full text-xs font-semibold transition-colors"
                        :class="step > i + 1 ? 'bg-brand-strong text-canvas' : (step === i + 1 ? 'bg-brand-soft text-brand-strong ring-1 ring-brand-strong' : 'bg-surface-raised text-ink-faint')"
                        x-text="i + 1"
                    ></span>
                    <span x-show="i < steps.length - 1" class="h-px w-4 bg-line sm:w-8"></span>
                </li>
            </template>
        </ol>

        <div class="mt-6 rounded-xl border border-line bg-surface p-6">
            {{-- Step 1: Locale --}}
            <div x-show="step === 1" x-cloak>
                <h2 class="text-base font-semibold text-ink">{{ __('i18n::messages.install.step_locale') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.install.locale_prompt') }}</p>

                <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach (['en' => 'English', 'tr' => 'Türkçe', 'de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'ru' => 'Русский'] as $code => $label)
                        <button
                            type="button"
                            @click="locale = '{{ $code }}'"
                            :class="locale === '{{ $code }}' ? 'border-brand-strong bg-brand-soft text-brand-strong' : 'border-line text-ink-muted hover:bg-surface-raised hover:text-ink'"
                            class="rounded-lg border px-3 py-2.5 text-sm font-medium transition-colors"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <button
                    type="button"
                    :disabled="loading"
                    @click="submitLocale()"
                    class="mt-6 inline-flex w-full items-center justify-center rounded-lg bg-brand-strong px-4 py-2.5 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    {{ __('i18n::messages.install.next') }}
                </button>
            </div>

            {{-- Step 2: Database --}}
            <div x-show="step === 2" x-cloak>
                <h2 class="text-base font-semibold text-ink">{{ __('i18n::messages.install.step_database') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.install.db_hint') }}</p>

                <div class="mt-4 space-y-5">
                    @foreach ([
                        'panel' => 'Panel',
                        'swiftly' => 'Swiftly (CS2_Admin)',
                        'ranks' => 'CS2_Ranks',
                        'weaponskins' => 'Weapon Skins',
                        'vip' => 'VIPCore',
                    ] as $connKey => $connLabel)
                        <fieldset class="rounded-lg border border-line-soft p-4">
                            <legend class="px-1 text-sm font-medium text-ink">{{ $connLabel }}</legend>

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
        </div>
    </div>
</body>
</html>
