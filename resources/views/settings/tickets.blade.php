<x-layout.app :title="__('i18n::messages.nav.settings')">
    <div x-data="ticketSettingsPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.settings') }}</h1>

        <x-settings-tabs current="tickets" />

        <div x-show="loading" x-cloak class="mt-6 text-sm text-ink-faint">
            {{ __('i18n::messages.common.loading') }}
        </div>

        <div x-show="!loading" x-cloak class="mt-6 max-w-xl">
            <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.settings.ticket_staff_title') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.settings.ticket_staff_subtitle') }}</p>

            <div class="mt-4 space-y-4">
                <template x-for="cat in categories" :key="cat.key">
                    <div>
                        <label class="block text-sm font-medium text-ink-muted" x-text="cat.label"></label>
                        <div class="relative mt-1">
                            <select
                                x-model="assignments[cat.key]"
                                class="w-full appearance-none rounded-lg border border-line bg-surface py-2 pl-3 pr-9 text-sm text-ink focus:border-brand-strong focus:outline-none"
                            >
                                <option value="">{{ __('i18n::messages.settings.ticket_staff_owner_only') }}</option>
                                <template x-for="group in groups" :key="group.name">
                                    <option :value="group.name" x-text="group.name"></option>
                                </template>
                            </select>
                            <x-icon name="chevron-left" class="pointer-events-none absolute right-3 top-1/2 size-3.5 -translate-y-1/2 -rotate-90 text-ink-faint" />
                        </div>
                    </div>
                </template>

                <p x-show="groups.length === 0" x-cloak class="text-xs text-ink-faint">
                    {{ __('i18n::messages.settings.ticket_staff_no_groups') }}
                </p>
            </div>

            <div class="mt-5 flex items-center gap-3">
                <button
                    type="button"
                    :disabled="saving"
                    @click="save()"
                    class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    <span x-show="!saving">{{ __('i18n::messages.common.save') }}</span>
                    <span x-show="saving" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                </button>
                <span x-show="saved" x-cloak class="text-sm text-brand-strong">✓</span>
            </div>

            <p x-show="error" x-cloak class="mt-3 text-sm text-red-400">
                {{ __('i18n::messages.common.error') }}
            </p>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.ticketSettingsPage = () => ({
                loading: true,
                saving: false,
                saved: false,
                error: false,
                groups: [],
                assignments: { report: '', admin_application: '', ban_appeal: '' },

                categories: [
                    { key: 'report', label: @js(__('i18n::messages.tickets.category_reports')) },
                    { key: 'admin_application', label: @js(__('i18n::messages.tickets.category_applications')) },
                    { key: 'ban_appeal', label: @js(__('i18n::messages.tickets.category_appeals')) },
                ],

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                async init() {
                    try {
                        const [settingsRes, groupsRes] = await Promise.all([
                            fetch('/api/settings', { headers: { Accept: 'application/json' } }),
                            fetch('/api/admin/groups', { headers: { Accept: 'application/json' } }),
                        ]);
                        if (!settingsRes.ok) throw new Error('request_failed');
                        const settingsBody = await settingsRes.json();
                        this.assignments = {
                            report: settingsBody.data.ticket_staff_group_report ?? '',
                            admin_application: settingsBody.data.ticket_staff_group_admin_application ?? '',
                            ban_appeal: settingsBody.data.ticket_staff_group_ban_appeal ?? '',
                        };
                        // Groups come from whichever admin plugin is active
                        // (App\Support\AdminPlugin) - a 403 here just means
                        // the panel has no groups configured yet, not a
                        // real failure.
                        if (groupsRes.ok) this.groups = (await groupsRes.json()).data;
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                async save() {
                    this.saving = true;
                    this.saved = false;
                    this.error = false;
                    try {
                        const res = await fetch('/api/settings', {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify({
                                ticket_staff_group_report: this.assignments.report,
                                ticket_staff_group_admin_application: this.assignments.admin_application,
                                ticket_staff_group_ban_appeal: this.assignments.ban_appeal,
                            }),
                        });
                        if (!res.ok) throw new Error('request_failed');
                        this.saved = true;
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.saving = false;
                    }
                },
            });
        </script>
    @endpush
</x-layout.app>
