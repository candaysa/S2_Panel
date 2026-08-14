@props(['siteName', 'siteLogo'])

@php
    // Grouped as [section label, items]; each item is [route name, label,
    // icon]. Wire a new module's page in here once it has a real Blade page.
    $menuGroups = [
        [__('i18n::messages.nav.section_menu'), [
            ['dashboard', __('i18n::messages.nav.dashboard'), 'home'],
            ['servers.page', __('i18n::messages.nav.servers'), 'server'],
            ['stats.page', __('i18n::messages.nav.stats'), 'chart'],
        ]],
        [__('i18n::messages.nav.section_moderation'), [
            ['admins.page', __('i18n::messages.nav.admin'), 'users'],
            ['groups.page', __('i18n::messages.nav.groups'), 'group'],
            ['bans.page', __('i18n::messages.nav.bans'), 'ban'],
            ['reports.page', __('i18n::messages.nav.reports'), 'flag'],
            ['appeals.page', __('i18n::messages.nav.appeals'), 'scale'],
            ['cheatcheck.page', __('i18n::messages.nav.cheat_check'), 'shield'],
            ['rcon.page', __('i18n::messages.nav.rcon'), 'terminal'],
            ['audit.page', __('i18n::messages.nav.audit'), 'list'],
        ]],
        [__('i18n::messages.nav.section_community'), [
            ['vip.page', __('i18n::messages.nav.vip'), 'star'],
            ['ranks.page', __('i18n::messages.nav.ranks'), 'trophy'],
            ['skins.page', __('i18n::messages.nav.skins'), 'palette'],
        ]],
    ];
@endphp

<aside
    x-cloak
    x-show="sidebarOpen || isDesktop"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="-translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="-translate-x-full"
    class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-surface border-r border-line lg:static lg:flex lg:translate-x-0"
>
    <div class="flex items-center gap-3 px-5 py-4 border-b border-line">
        <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="size-8 shrink-0 object-contain">
        <span class="text-[15px] font-semibold text-ink truncate">{{ $siteName }}</span>
        <x-theme-toggle class="ml-auto" />
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-4">
        @foreach ($menuGroups as [$sectionLabel, $items])
            <div>
                <p class="px-3 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-ink-faint">
                    {{ $sectionLabel }}
                </p>
                <ul class="space-y-0.5">
                    @foreach ($items as [$routeName, $label, $icon])
                        <li>
                            <a
                                href="{{ route($routeName) }}"
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                                    'bg-brand-soft text-brand-strong font-medium' => request()->routeIs($routeName),
                                    'text-ink-muted hover:bg-surface-raised hover:text-ink' => ! request()->routeIs($routeName),
                                ])
                            >
                                <x-icon :name="$icon" class="size-5 shrink-0" />
                                {{ $label }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="border-t border-line px-3 py-3 space-y-0.5">
        @if (auth()->user()?->is_owner)
            <a
                href="{{ route('health.page') }}"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                    'bg-brand-soft text-brand-strong font-medium' => request()->routeIs('health.page'),
                    'text-ink-muted hover:bg-surface-raised hover:text-ink' => ! request()->routeIs('health.page'),
                ])
            >
                <x-icon name="bell" class="size-5 shrink-0" />
                {{ __('i18n::messages.nav.health') }}
            </a>

            <a
                href="{{ route('webhooks.page') }}"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                    'bg-brand-soft text-brand-strong font-medium' => request()->routeIs('webhooks.page'),
                    'text-ink-muted hover:bg-surface-raised hover:text-ink' => ! request()->routeIs('webhooks.page'),
                ])
            >
                <x-icon name="webhook" class="size-5 shrink-0" />
                {{ __('i18n::messages.nav.webhooks') }}
            </a>

            <a
                href="{{ route('modules.page') }}"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                    'bg-brand-soft text-brand-strong font-medium' => request()->routeIs('modules.page'),
                    'text-ink-muted hover:bg-surface-raised hover:text-ink' => ! request()->routeIs('modules.page'),
                ])
            >
                <x-icon name="puzzle" class="size-5 shrink-0" />
                {{ __('i18n::messages.nav.modules') }}
            </a>

            <a
                href="{{ route('plugins.page') }}"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                    'bg-brand-soft text-brand-strong font-medium' => request()->routeIs('plugins.page'),
                    'text-ink-muted hover:bg-surface-raised hover:text-ink' => ! request()->routeIs('plugins.page'),
                ])
            >
                <x-icon name="upload" class="size-5 shrink-0" />
                {{ __('i18n::messages.nav.plugins') }}
            </a>

            <a
                href="{{ route('settings.page') }}"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                    'bg-brand-soft text-brand-strong font-medium' => request()->routeIs('settings.page'),
                    'text-ink-muted hover:bg-surface-raised hover:text-ink' => ! request()->routeIs('settings.page'),
                ])
            >
                <x-icon name="cog" class="size-5 shrink-0" />
                {{ __('i18n::messages.nav.settings') }}
            </a>
        @endif

        <form method="POST" action="{{ route('auth.logout') }}">
            @csrf
            <button
                type="submit"
                class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink"
            >
                @if (auth()->user()?->avatar)
                    <img src="{{ auth()->user()->avatar }}" alt="" class="size-6 shrink-0 rounded-full">
                @else
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-surface-raised text-[11px] font-semibold text-ink-muted">
                        {{ mb_strtoupper(mb_substr(auth()->user()?->name ?? '?', 0, 1)) }}
                    </span>
                @endif
                {{ __('i18n::messages.nav.logout') }}
            </button>
        </form>
    </div>
</aside>

<div
    x-cloak
    x-show="sidebarOpen && !isDesktop"
    x-transition.opacity
    @click="sidebarOpen = false"
    class="fixed inset-0 z-30 bg-black/60 lg:hidden"
></div>
