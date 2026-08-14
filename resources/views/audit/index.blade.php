<x-layout.app :title="__('i18n::messages.nav.audit')">
    <div
        x-data="{
            loading: true,
            forbidden: false,
            error: false,
            action: '',
            logs: [],

            async load() {
                this.loading = true;
                this.error = false;
                this.forbidden = false;
                try {
                    const url = new URL('/api/audit', window.location.origin);
                    if (this.action) url.searchParams.set('action', this.action);
                    const res = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (res.status === 403) { this.forbidden = true; return; }
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.logs = body.data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },

            formatDate(value) {
                return value ? new Date(value).toLocaleString() : '—';
            },

            init() { this.load(); },
        }"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.audit') }}</h1>
            <input
                type="search"
                x-model.debounce.400ms="action"
                @input="load()"
                placeholder="{{ __('i18n::messages.audit.filter_action') }}"
                class="w-full max-w-xs rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none sm:w-64"
            >
        </div>

        <div class="mt-4 overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    <tr>
                        <th class="px-4 py-3">{{ __('i18n::messages.audit.actor') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.audit.action') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.audit.target') }}</th>
                        <th class="px-4 py-3">{{ __('i18n::messages.audit.when') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="log in logs" :key="log.id">
                        <tr class="text-ink-muted">
                            <td class="px-4 py-3 text-ink" x-text="log.actor_name || '—'"></td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full bg-surface-raised px-2.5 py-0.5 font-mono text-xs" x-text="log.action"></span>
                            </td>
                            <td class="px-4 py-3" x-text="(log.target_type ?? '') + (log.target_id ? ' #' + log.target_id : '')"></td>
                            <td class="px-4 py-3" x-text="formatDate(log.created_at)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
            <p x-show="!loading && !forbidden && !error && logs.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>
            <p x-show="forbidden" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.forbidden') }}</p>
            <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
        </div>
    </div>
</x-layout.app>
