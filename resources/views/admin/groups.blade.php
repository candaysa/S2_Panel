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
        }"
        x-init="init()"
    >
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.groups') }}</h1>

        <div class="mt-6 overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    <tr>
                        <th class="px-4 py-3">{{ __('i18n::messages.nav.groups') }}</th>
                        <th class="px-4 py-3">Flags</th>
                        <th class="px-4 py-3">Immunity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="group in groups" :key="group.name">
                        <tr class="text-ink-muted">
                            <td class="px-4 py-3 font-medium text-ink" x-text="group.name"></td>
                            <td class="px-4 py-3" x-text="group.flags || '—'"></td>
                            <td class="px-4 py-3" x-text="group.immunity"></td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.loading') }}
            </p>
            <p x-show="!loading && !error && groups.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.empty') }}
            </p>
            <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">
                {{ __('i18n::messages.common.error') }}
            </p>
        </div>
    </div>
</x-layout.app>
