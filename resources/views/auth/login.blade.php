@php
    $settingService = app(\App\Modules\Settings\App\Services\SettingService::class);
    $siteName = $settingService->get('site_name') ?: 'S2 Panel';
    $siteDescription = $settingService->get('site_description') ?: '';
    $siteLogo = $settingService->get('logo')
        ? asset($settingService->get('logo'))
        : asset('images/logo.png');
    $siteFavicon = $settingService->get('favicon')
        ? asset($settingService->get('favicon'))
        : asset('favicon-32x32.png');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ __('i18n::messages.auth.login_title') }} · {{ $siteName }}</title>

    <link rel="icon" type="image/png" sizes="32x32" href="{{ $siteFavicon }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.head-theme')
</head>
<body class="relative flex h-full min-h-screen items-center justify-center bg-canvas px-4 text-ink antialiased">
    <x-theme-toggle class="absolute right-4 top-4" />

    <div class="w-full max-w-sm">
        <div class="flex flex-col items-center text-center">
            <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="size-14 object-contain">
            <h1 class="mt-4 text-xl font-semibold text-ink">{{ $siteName }}</h1>
            @if ($siteDescription)
                <p class="mt-1.5 text-sm text-ink-muted">{{ $siteDescription }}</p>
            @endif
        </div>

        <div class="mt-8 rounded-xl border border-line bg-surface p-6">
            @if (session('error'))
                <p class="mb-4 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400">
                    {{ session('error') }}
                </p>
            @endif

            <a
                href="{{ route('auth.redirect') }}"
                class="flex w-full items-center justify-center gap-2.5 rounded-lg bg-[#171a21] px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#1f2532]"
            >
                <x-icon name="steam" class="size-5" />
                {{ __('i18n::messages.auth.login_with_steam') }}
            </a>
        </div>
    </div>
</body>
</html>
