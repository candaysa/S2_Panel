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
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? "{$title} · {$siteName}" : $siteName }}</title>

    <link rel="icon" type="image/png" sizes="32x32" href="{{ $siteFavicon }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.head-theme')
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
                    aria-label="Menüyü aç/kapat"
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
</body>
</html>
