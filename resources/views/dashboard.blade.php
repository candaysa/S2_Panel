<x-layout.app :title="__('i18n::messages.nav.dashboard')">
    <div
        x-data="{
            loading: true,
            error: false,
            servers: [],
            reports: [],
            health: null,
            isOwner: {{ auth()->user()?->is_owner ? 'true' : 'false' }},
            async init() {
                const requests = [
                    fetch('/api/servers?per_page=100', { headers: { Accept: 'application/json' } })
                        .then((res) => res.ok ? res.json() : Promise.reject())
                        .then((body) => { this.servers = body.data; }),
                    fetch('/api/reports?per_page=5', { headers: { Accept: 'application/json' } })
                        .then((res) => res.ok ? res.json() : Promise.reject())
                        .then((body) => { this.reports = body.data; }),
                ];

                if (this.isOwner) {
                    requests.push(
                        fetch('/api/health', { headers: { Accept: 'application/json' } })
                            .then((res) => res.ok ? res.json() : Promise.reject())
                            .then((body) => { this.health = body.data; })
                            .catch(() => {}), // health module may be disabled - degrade silently
                    );
                }

                try {
                    await Promise.all(requests);
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },
        }"
    >
        <h1 class="text-2xl font-semibold text-ink">
            {{ __('i18n::messages.dashboard.welcome') }}, {{ auth()->user()->name }}
        </h1>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    {{ __('i18n::messages.dashboard.servers_online') }}
                </p>
                <p class="mt-2 text-3xl font-semibold text-ink" x-show="!loading" x-cloak>
                    <span x-text="servers.filter(s => s.online).length"></span>
                    <span class="text-lg font-normal text-ink-faint">/ <span x-text="servers.length"></span></span>
                </p>
                <p class="mt-2 text-3xl font-semibold text-ink-faint" x-show="loading" x-cloak>—</p>
            </div>

            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    {{ __('i18n::messages.dashboard.total_players') }}
                </p>
                <p class="mt-2 text-3xl font-semibold text-ink" x-show="!loading" x-cloak>
                    <span x-text="servers.reduce((sum, s) => sum + (s.live?.players ?? 0), 0)"></span>
                </p>
                <p class="mt-2 text-3xl font-semibold text-ink-faint" x-show="loading" x-cloak>—</p>
            </div>

            <div x-show="isOwner" x-cloak class="rounded-xl border border-line bg-surface p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    {{ __('i18n::messages.dashboard.health_status') }}
                </p>
                <p class="mt-2 flex items-center gap-2" x-show="!loading" x-cloak>
                    <template x-if="health">
                        <span class="inline-flex items-center gap-1.5 text-lg font-semibold" :class="health.healthy ? 'text-brand-strong' : 'text-red-400'">
                            <x-icon name="pulse" class="size-5" />
                            <span x-text="health.healthy ? '{{ __('i18n::messages.dashboard.healthy') }}' : '{{ __('i18n::messages.dashboard.degraded') }}'"></span>
                        </span>
                    </template>
                    <span x-show="!health" class="text-lg font-normal text-ink-faint">—</span>
                </p>
                <p class="mt-2 text-3xl font-semibold text-ink-faint" x-show="loading" x-cloak>—</p>
            </div>
        </div>

        <div class="mt-8 grid gap-6 lg:grid-cols-3">
            <div class="rounded-xl border border-line bg-surface p-5 lg:col-span-2">
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.dashboard.recent_reports') }}</h2>

                <div class="mt-3 divide-y divide-line-soft">
                    <template x-for="report in reports" :key="report.id">
                        <div class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm text-ink" x-text="report.report_reason"></p>
                                <p class="mt-0.5 text-xs text-ink-faint" x-text="report.reporter_name + (report.target_name ? ' → ' + report.target_name : '')"></p>
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="report.status === 'open' ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-faint'"
                                x-text="report.status"
                            ></span>
                        </div>
                    </template>

                    <p x-show="!loading && !error && reports.length === 0" x-cloak class="py-6 text-center text-sm text-ink-faint">
                        {{ __('i18n::messages.common.empty') }}
                    </p>
                    <p x-show="loading" x-cloak class="py-6 text-center text-sm text-ink-faint">
                        {{ __('i18n::messages.common.loading') }}
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.dashboard.quick_links') }}</h2>

                <ul class="mt-3 space-y-0.5">
                    <li>
                        <a href="{{ route('admins.page') }}" class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                            <x-icon name="users" class="size-4.5 shrink-0" />
                            {{ __('i18n::messages.nav.admin') }}
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('groups.page') }}" class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                            <x-icon name="group" class="size-4.5 shrink-0" />
                            {{ __('i18n::messages.nav.groups') }}
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('servers.page') }}" class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                            <x-icon name="server" class="size-4.5 shrink-0" />
                            {{ __('i18n::messages.nav.servers') }}
                        </a>
                    </li>
                    @if (auth()->user()?->is_owner)
                        <li>
                            <a href="{{ route('modules.page') }}" class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                                <x-icon name="puzzle" class="size-4.5 shrink-0" />
                                {{ __('i18n::messages.nav.modules') }}
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('settings.page') }}" class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                                <x-icon name="cog" class="size-4.5 shrink-0" />
                                {{ __('i18n::messages.nav.settings') }}
                            </a>
                        </li>
                    @endif
                </ul>
            </div>
        </div>

        <p x-show="error" x-cloak class="mt-6 text-sm text-red-400">
            {{ __('i18n::messages.common.error') }}
        </p>
    </div>
</x-layout.app>
