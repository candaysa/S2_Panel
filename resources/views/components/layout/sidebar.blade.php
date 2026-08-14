@props(['siteName', 'siteLogo'])

@php
    // Grouped as [section label, items]; each item is [route name, label,
    // icon, gate]. Wire a new module's page in here once it has a real Blade
    // page. `gate` mirrors the access rule the item's own module already
    // enforces server-side (see each Routes/api.php) rather than inventing a
    // separate one here, so the nav can never promise more than the API
    // actually grants:
    //   null            - public, shown to everyone (see routes/web.php)
    //   'auth'          - any logged-in session, no flag required
    //   [flags...]      - logged-in AND at least one of these flags (or owner)
    $menuGroups = [
        [__('i18n::messages.nav.section_menu'), [
            ['dashboard', __('i18n::messages.nav.dashboard'), 'home', null],
            ['servers.page', __('i18n::messages.nav.servers'), 'server', null],
            ['stats.page', __('i18n::messages.nav.stats'), 'chart', null],
            ['ranks.page', __('i18n::messages.nav.ranks'), 'trophy', null],
        ]],
        [__('i18n::messages.nav.section_community'), [
            ['reports.page', __('i18n::messages.nav.reports'), 'flag', 'auth'],
            ['appeals.page', __('i18n::messages.nav.appeals'), 'scale', 'auth'],
            ['vip.page', __('i18n::messages.nav.vip'), 'star', 'auth'],
            ['skins.page', __('i18n::messages.nav.skins'), 'palette', 'auth'],
        ]],
        [__('i18n::messages.nav.section_moderation'), [
            ['admins.page', __('i18n::messages.nav.admin'), 'users', ['admin.root']],
            ['groups.page', __('i18n::messages.nav.groups'), 'group', ['admin.root']],
            ['bans.page', __('i18n::messages.nav.bans'), 'ban', ['admin.ban', 'admin.mute', 'admin.kick', 'admin.generic']],
            ['cheatcheck.page', __('i18n::messages.nav.cheat_check'), 'shield', ['admin.generic']],
            ['rcon.page', __('i18n::messages.nav.rcon'), 'terminal', ['admin.rcon']],
            ['audit.page', __('i18n::messages.nav.audit'), 'list', ['admin.root']],
        ]],
    ];

    $user = \App\Support\Access::user();
    $isOwner = \App\Support\Access::isOwner();
    $userFlags = $isOwner ? [] : \App\Support\Access::flags();

    $canSee = function (?array $gate) use ($user, $isOwner, $userFlags): bool {
        if ($gate === null) {
            return true;
        }
        if ($user === null) {
            return false;
        }
        if ($isOwner) {
            return true;
        }
        if ($gate === ['auth']) {
            return true;
        }

        return array_intersect($gate, $userFlags) !== [];
    };

    // 'auth' is a single-item marker, not a flag list - normalize both shapes
    // to an array so $canSee only has one thing to branch on.
    $visibleGroups = collect($menuGroups)
        ->map(function (array $group) use ($canSee): array {
            [$label, $items] = $group;
            $visible = collect($items)
                ->filter(fn (array $item): bool => $canSee($item[3] === 'auth' ? ['auth'] : $item[3]))
                ->all();

            return [$label, $visible];
        })
        ->filter(fn (array $group): bool => $group[1] !== [])
        ->all();
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
        <span class="text-base font-semibold text-ink truncate">{{ $siteName }}</span>
        <x-theme-toggle class="ml-auto" />
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-4">
        @foreach ($visibleGroups as [$sectionLabel, $items])
            <div>
                <p class="px-3 mb-1.5 text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    {{ $sectionLabel }}
                </p>
                <ul class="space-y-0.5">
                    @foreach ($items as [$routeName, $label, $icon, $gate])
                        <li>
                            <a
                                href="{{ route($routeName) }}"
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2.5 text-[15px] transition-colors',
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
        @if ($isOwner)
            <a
                href="{{ route('health.page') }}"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2.5 text-[15px] transition-colors',
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
                    'flex items-center gap-3 rounded-lg px-3 py-2.5 text-[15px] transition-colors',
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
                    'flex items-center gap-3 rounded-lg px-3 py-2.5 text-[15px] transition-colors',
                    'bg-brand-soft text-brand-strong font-medium' => request()->routeIs('modules.page'),
                    'text-ink-muted hover:bg-surface-raised hover:text-ink' => ! request()->routeIs('modules.page'),
                ])
            >
                <x-icon name="puzzle" class="size-5 shrink-0" />
                {{ __('i18n::messages.nav.modules') }}
            </a>


            <a
                href="{{ route('settings.page') }}"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2.5 text-[15px] transition-colors',
                    'bg-brand-soft text-brand-strong font-medium' => request()->routeIs('settings.page'),
                    'text-ink-muted hover:bg-surface-raised hover:text-ink' => ! request()->routeIs('settings.page'),
                ])
            >
                <x-icon name="cog" class="size-5 shrink-0" />
                {{ __('i18n::messages.nav.settings') }}
            </a>
        @endif

        @if ($user)
            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button
                    type="submit"
                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-[15px] text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink"
                >
                    @if ($user->avatar)
                        <img src="{{ $user->avatar }}" alt="" class="size-6 shrink-0 rounded-full">
                    @else
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-surface-raised text-[11px] font-semibold text-ink-muted">
                            {{ mb_strtoupper(mb_substr($user->name ?? '?', 0, 1)) }}
                        </span>
                    @endif
                    {{ __('i18n::messages.nav.logout') }}
                </button>
            </form>
        @else
            {{-- Straight to Steam, not via /login: a visitor already on a
                 public page should not be bounced through an interstitial
                 just to click one more button. "return" carries where they
                 were so the callback lands them back here, not on the
                 dashboard. --}}
            <a
                href="{{ route('auth.redirect', ['return' => request()->fullUrl()]) }}"
                class="flex w-full items-center gap-3 rounded-lg bg-brand-soft px-3 py-2.5 text-[15px] font-medium text-brand-strong transition-opacity hover:opacity-80"
            >
                <x-icon name="steam" class="size-5 shrink-0" />
                {{ __('i18n::messages.auth.login_with_steam') }}
            </a>
        @endif
    </div>
</aside>

<div
    x-cloak
    x-show="sidebarOpen && !isDesktop"
    x-transition.opacity
    @click="sidebarOpen = false"
    class="fixed inset-0 z-30 bg-black/60 lg:hidden"
></div>
