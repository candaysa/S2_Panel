@props(['siteName', 'siteLogo'])

@php
    // Grouped as [section label, items]; each item is [route name, label,
    // icon, gate, modules]. Wire a new module's page in here once it has a
    // real Blade page.
    //
    // `gate` mirrors the access rule the item's own module already enforces
    // server-side (see each Routes/api.php) rather than inventing a separate
    // one here, so the nav can never promise more than the API actually
    // grants:
    //   null            - public, shown to everyone (see routes/web.php)
    //   'auth'          - any logged-in session, no flag required
    //   [flags...]      - logged-in AND at least one of these flags (or owner)
    //
    // `modules` mirrors the module: gate on the same route in routes/web.php
    // (any-of, null = always). Owners can switch these features off, and a
    // nav link to a page that now 404s is worse than no link at all.
    $menuGroups = [
        [__('i18n::messages.nav.section_menu'), [
            ['dashboard', __('i18n::messages.nav.dashboard'), 'home', null, null],
            ['ranks.page', __('i18n::messages.nav.ranks'), 'trophy', null, ['rank']],
        ]],
        [__('i18n::messages.nav.section_community'), [
            ['tickets.page', __('i18n::messages.nav.tickets'), 'flag', 'auth', ['report', 'appeal']],
            ['vip.page', __('i18n::messages.nav.vip'), 'star', 'auth', ['vip']],
            ['skins.page', __('i18n::messages.nav.skins'), 'palette', 'auth', ['skin']],
            ['bans.page', __('i18n::messages.nav.bans'), 'ban', 'auth', null],
        ]],
        [__('i18n::messages.nav.section_moderation'), [
            ['admins.page', __('i18n::messages.nav.admin'), 'users', ['admin.root'], null],
            ['groups.page', __('i18n::messages.nav.groups'), 'group', ['admin.root'], null],
            ['cheatcheck.page', __('i18n::messages.nav.cheat_check'), 'shield', ['admin.generic'], ['cheat_check']],
            ['rcon.page', __('i18n::messages.nav.rcon'), 'terminal', ['admin.rcon'], ['rcon']],
            ['audit.page', __('i18n::messages.nav.audit'), 'list', ['admin.root'], ['audit']],
        ]],
    ];

    $user = \App\Support\Access::user();
    $isOwner = \App\Support\Access::isOwner();
    $userFlags = $isOwner ? [] : \App\Support\Access::flags();
    $registry = app(\App\Support\ModuleRegistry::class);
    $notificationsEnabled = $registry->isEnabled('health');

    $moduleOn = fn (?array $modules): bool => $modules === null
        || collect($modules)->contains(fn (string $key): bool => $registry->isEnabled($key));

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
        ->map(function (array $group) use ($canSee, $moduleOn): array {
            [$label, $items] = $group;
            $visible = collect($items)
                ->filter(fn (array $item): bool => $moduleOn($item[4]))
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
    <div class="flex items-center gap-3 px-5 py-5 border-b border-line">
        <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="size-10 shrink-0 object-contain">
        <span class="text-lg font-semibold tracking-tight text-ink truncate">{{ $siteName }}</span>
        <x-theme-toggle class="ml-auto" />
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-4">
        @foreach ($visibleGroups as [$sectionLabel, $items])
            <div>
                <p class="px-3 mb-1.5 text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    {{ $sectionLabel }}
                </p>
                <ul class="space-y-0.5">
                    @foreach ($items as [$routeName, $label, $icon, $gate, $modules])
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
            @if ($notificationsEnabled)
                <div x-data="notificationBell()" x-init="init()" class="relative">
                    <button
                        type="button"
                        @click="open = !open"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-[15px] text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink"
                    >
                        <span class="relative shrink-0">
                            <x-icon name="bell" class="size-5" />
                            <span x-show="unread > 0" x-cloak class="absolute -right-1 -top-1 flex size-3.5 items-center justify-center rounded-full bg-red-500 text-[9px] font-bold text-white" x-text="unread > 9 ? '9+' : unread"></span>
                        </span>
                        {{ __('i18n::messages.health.notifications') }}
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        @click.outside="open = false"
                        x-transition
                        class="absolute bottom-full left-0 z-50 mb-2 w-80 max-w-[calc(100vw-2rem)] rounded-xl border border-line bg-surface shadow-lg"
                    >
                        <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-2.5">
                            <span class="text-xs font-semibold uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.health.notifications') }}</span>
                            <button type="button" @click="markAllRead()" class="text-xs text-ink-muted hover:text-ink">
                                {{ __('i18n::messages.health.mark_all_read') }}
                            </button>
                        </div>
                        <div class="max-h-80 divide-y divide-line-soft overflow-y-auto">
                            <template x-for="notification in notifications" :key="notification.id">
                                <div @click="markRead(notification)" class="cursor-pointer px-4 py-2.5 hover:bg-surface-raised" :class="notification.read ? '' : 'font-medium'">
                                    <div class="flex items-center gap-2">
                                        <span x-show="!notification.read" class="size-1.5 shrink-0 rounded-full bg-brand-strong"></span>
                                        <p class="truncate text-sm text-ink" x-text="notification.title"></p>
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-ink-faint" x-text="notification.body"></p>
                                    <p class="mt-0.5 text-xs text-ink-faint" x-text="formatDate(notification.created_at)"></p>
                                </div>
                            </template>
                            <p x-show="!loading && notifications.length === 0" class="px-4 py-6 text-center text-sm text-ink-faint">
                                {{ __('i18n::messages.health.no_notifications') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

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

@if ($isOwner && $notificationsEnabled)
    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.notificationBell = () => ({
                open: false,
                loading: true,
                notifications: [],

                get unread() {
                    return this.notifications.filter((n) => !n.read).length;
                },

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                async init() {
                    try {
                        const res = await fetch('/api/notifications', { headers: { Accept: 'application/json' } });
                        if (res.ok) this.notifications = (await res.json()).data;
                    } catch (e) {
                        // The bell just stays quiet on failure - nothing else
                        // on the page depends on this call succeeding.
                    } finally {
                        this.loading = false;
                    }
                },

                async markAllRead() {
                    try {
                        await fetch('/api/notifications/read-all', {
                            method: 'POST',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        this.notifications = this.notifications.map((n) => ({ ...n, read: true }));
                    } catch (e) {}
                },

                async markRead(notification) {
                    if (notification.read) return;
                    try {
                        await fetch(`/api/notifications/${notification.id}/read`, {
                            method: 'POST',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        notification.read = true;
                    } catch (e) {}
                },

                formatDate(value) {
                    return value ? new Date(value).toLocaleString() : '—';
                },
            });
        </script>
    @endpush
@endif
