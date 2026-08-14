<x-layout.app :title="__('i18n::messages.nav.groups')">
    <div
        x-data="{
            loading: true,
            error: false,
            groups: [],
            async init() {
                try {
                    const res = await fetch('/api/admin/groups', { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.groups = body.data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },
            // Flags arrive comma-joined from the plugin table; empty segments
            // are dropped so a trailing comma does not render a blank chip.
            chips(value) {
                return (value ?? '').split(',').map(v => v.trim()).filter(Boolean);
            },
        }"
        x-init="init()"
    >
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.groups') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.admins.groups_subtitle') }}</p>

        {{-- Cards rather than a three-column table: a group is really just a
             name and a set of flags, and the flag set is the whole point -
             it needs room to wrap, which a narrow table cell never gives it. --}}
        <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <template x-for="group in groups" :key="group.name">
                <div class="rounded-xl border border-line bg-surface p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-soft">
                                <x-icon name="group" class="size-4.5 text-brand-strong" />
                            </span>
                            <p class="truncate font-medium text-ink" x-text="group.name"></p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-[11px] uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.admins.immunity') }}</p>
                            <p class="text-lg font-semibold tabular-nums text-ink" x-text="group.immunity ?? 0"></p>
                        </div>
                    </div>

                    <div class="mt-3 h-1 overflow-hidden rounded-full bg-surface-raised">
                        <div class="h-full rounded-full bg-brand-strong" :style="'width:' + Math.min(100, group.immunity ?? 0) + '%'"></div>
                    </div>

                    <div class="mt-4 border-t border-line-soft pt-3">
                        <p class="text-[11px] uppercase tracking-wider text-ink-faint">
                            {{ __('i18n::messages.admins.flags') }}
                            <span class="ml-1 opacity-70" x-text="'(' + chips(group.flags).length + ')'"></span>
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <template x-for="flag in chips(group.flags)" :key="flag">
                                <span class="rounded bg-surface-raised px-1.5 py-0.5 font-mono text-[11px] text-ink-muted" x-text="flag"></span>
                            </template>
                            <span x-show="chips(group.flags).length === 0" class="text-sm text-ink-faint">—</span>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <p x-show="loading" x-cloak class="mt-6 rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">
            {{ __('i18n::messages.common.loading') }}
        </p>
        <p x-show="!loading && !error && groups.length === 0" x-cloak class="mt-6 rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">
            {{ __('i18n::messages.common.empty') }}
        </p>
        <p x-show="error" x-cloak class="mt-6 text-center text-sm text-red-400">
            {{ __('i18n::messages.common.error') }}
        </p>
    </div>
</x-layout.app>
