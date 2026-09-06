@props(['title' => null])

@php
    $settingService = app(\App\Modules\Settings\App\Services\SettingService::class);
    $siteName = $settingService->get('site_name') ?: 'S2 Panel';
    $siteLogo = $settingService->get('logo')
        ? asset($settingService->get('logo'))
        : asset('images/logo.png');
    $siteFavicon = $settingService->get('favicon')
        ? asset($settingService->get('favicon'))
        : asset('favicon-32x32.png');

    // Settings > Design overrides app.css's factory --color-* tokens. Same
    // token map as SettingsController::THEME_TOKENS; kept as literal pairs
    // here rather than importing that constant, since a Blade view has no
    // clean way to reach into a controller class.
    $themeTokenVars = [
        'surface' => '--color-surface', 'surface_raised' => '--color-surface-raised',
        'canvas' => '--color-canvas', 'line' => '--color-line', 'line_soft' => '--color-line-soft',
        'ink' => '--color-ink', 'ink_muted' => '--color-ink-muted', 'ink_faint' => '--color-ink-faint',
    ];
    $themeColors = (array) $settingService->get('theme_colors', []);
    $themeDark = (array) ($themeColors['dark'] ?? []);
    $themeLight = (array) ($themeColors['light'] ?? []);

    // brand_color has been readable/settable since Settings > Appearance
    // existed, but nothing ever actually applied it anywhere except the PWA
    // theme-color meta tag below and a client-side preview that only ran
    // while the Settings page itself was open - every other page silently
    // fell back to the factory teal. Included in the same override block as
    // the palette above now that one exists.
    $brandColor = (string) $settingService->get('brand_color', '#00ffe3');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? "{$title} · {$siteName}" : $siteName }}</title>

    {{-- A custom upload replaces every icon reference with the one file the
         owner provided - the previous version only ever swapped this first
         tag, so the 16x16 and apple-touch variants silently kept showing
         the factory icon no matter what was uploaded. --}}
    @if ($settingService->get('favicon'))
        <link rel="icon" href="{{ $siteFavicon }}">
        <link rel="apple-touch-icon" href="{{ $siteFavicon }}">
    @else
        <link rel="icon" type="image/png" sizes="32x32" href="{{ $siteFavicon }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @endif

    {{-- Installable web app. theme-color has to be a literal here (not a
         CSS variable) because the browser reads it before any stylesheet. --}}
    <link rel="manifest" href="{{ route('pwa.manifest') }}">
    <meta name="theme-color" content="{{ app(\App\Modules\Settings\App\Services\SettingService::class)->get('brand_color') ?: '#00ffe3' }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ $siteName }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.head-theme')

    {{-- Per-request color override (Settings > Design). !important because
         it must beat app.css's own @theme block regardless of cascade
         order; the palette portion is empty when nothing has been
         customized, so the factory look is exactly what a fresh install
         already looks like. brand_color always renders here since it has
         no "is this the factory value" guard of its own upstream. --}}
    <style>
        :root {
            --color-brand: {{ preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor) ? $brandColor : '#00ffe3' }} !important;
            @foreach ($themeDark as $token => $hex)
                @if (isset($themeTokenVars[$token]) && preg_match('/^#[0-9a-fA-F]{6}$/', $hex))
                    {{ $themeTokenVars[$token] }}: {{ $hex }} !important;
                @endif
            @endforeach
        }
        @if ($themeLight !== [])
            :root[data-theme='light'] {
                @foreach ($themeLight as $token => $hex)
                    @if (isset($themeTokenVars[$token]) && preg_match('/^#[0-9a-fA-F]{6}$/', $hex))
                        {{ $themeTokenVars[$token] }}: {{ $hex }} !important;
                    @endif
                @endforeach
            }
        @endif
    </style>
</head>
<body
    x-data="{
        sidebarOpen: false,
        isDesktop: window.innerWidth >= 1024,
        init() {
            window.addEventListener('resize', () => { this.isDesktop = window.innerWidth >= 1024; });
        },
    }"
    class="h-full bg-canvas text-ink antialiased"
>
    <div class="flex h-full">
        <x-layout.sidebar :site-name="$siteName" :site-logo="$siteLogo" />

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex items-center gap-3 border-b border-line px-4 py-3 lg:hidden">
                <button
                    type="button"
                    @click="sidebarOpen = !sidebarOpen"
                    class="flex size-9 items-center justify-center rounded-lg text-ink-muted hover:bg-surface-raised hover:text-ink"
                    :aria-label="sidebarOpen ? @js(__('i18n::messages.nav.close_menu')) : @js(__('i18n::messages.nav.open_menu'))"
                >
                    <x-icon name="menu" class="size-5" />
                </button>
                <span class="text-sm font-semibold text-ink">{{ $title ?? $siteName }}</span>
            </header>

            <main class="flex-1 overflow-y-auto">
                <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    {{-- Owner-only; the component itself checks and stays hidden otherwise,
         and /api/update/status is gated behind owner.only regardless. --}}
    @if (\App\Support\Access::isOwner())
        <x-update-prompt />
    @endif

    {{-- Pages with more Alpine state than fits legibly in an x-data attribute
         push a component factory here instead. Anything pushed must carry the
         CSP nonce (see partials/head-theme) or it will not execute. --}}
    @stack('scripts')

    <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
        // Reason codes the API returns in `errors`, translated once per page
        // so window.apiError() can render them (see resources/js/app.js).
        window.apiMessages = @js(__('i18n::messages.api_errors'));

        // Registered after load so it never competes with the first paint.
        // The worker caches only hashed build assets and an offline page.
        //
        // updateViaCache:'none' stops the browser serving sw.js itself from
        // HTTP cache, and an explicit update() on every load means a new
        // deploy is picked up on the next navigation rather than whenever
        // the browser feels like revalidating. Without these two, a stale
        // worker kept handing out the previous build's CSS after a deploy.
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' })
                    .then((reg) => {
                        reg.update();

                        // A worker that has taken over mid-session is serving
                        // the old build; reload once, guarded so two tabs
                        // cannot bounce each other forever.
                        let reloaded = false;
                        navigator.serviceWorker.addEventListener('controllerchange', () => {
                            if (reloaded) return;
                            reloaded = true;
                            window.location.reload();
                        });
                    })
                    .catch(() => {});
            });
        }
    </script>
</body>
</html>
