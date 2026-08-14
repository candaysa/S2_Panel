<x-layout.app :title="__('i18n::messages.nav.appeals')">
    <div
        x-data="{
            loading: true,
            error: false,
            status: '',
            appeals: [],
            selected: null,
            actionError: '',
            decisionNote: '',

            async load() {
                this.loading = true;
                this.error = false;
                try {
                    const url = new URL('/api/appeals', window.location.origin);
                    const res = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.appeals = this.status ? body.data.filter((a) => a.status === this.status) : body.data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },

            open(appeal) {
                this.selected = appeal;
                this.decisionNote = '';
                this.actionError = '';
            },

            csrf() {
                return document.querySelector('meta[name=csrf-token]').content;
            },

            async decide(status) {
                this.actionError = '';
                try {
                    const res = await fetch(`/api/appeals/${this.selected.id}/decide`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify({ status, decision_note: this.decisionNote || null }),
                    });
                    if (res.status === 403) { this.actionError = @js(__('i18n::messages.common.forbidden')); return; }
                    if (!res.ok) throw new Error('request_failed');
                    this.selected = null;
                    await this.load();
                } catch (e) {
                    this.actionError = @js(__('i18n::messages.common.error'));
                }
            },

            formatDate(value) {
                return value ? new Date(value).toLocaleString() : '—';
            },

            init() { this.load(); },
        }"
        x-init="init()"
    >
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.appeals') }}</h1>

        <div class="mt-4 flex flex-wrap gap-1.5">
            <template x-for="s in ['', 'PENDING', 'APPROVED', 'REJECTED']" :key="s">
                <button
                    type="button"
                    @click="status = s; load()"
                    :class="status === s ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                    x-text="{
                        '': @js(__('i18n::messages.appeals.status_all')),
                        PENDING: @js(__('i18n::messages.appeals.status_pending')),
                        APPROVED: @js(__('i18n::messages.appeals.status_approved')),
                        REJECTED: @js(__('i18n::messages.appeals.status_rejected')),
                    }[s]"
                ></button>
            </template>
        </div>

        <div class="mt-4 grid gap-6 lg:grid-cols-5">
            <div class="overflow-x-auto rounded-xl border border-line bg-surface lg:col-span-2" :class="selected ? 'hidden lg:block' : ''">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-line-soft">
                        <template x-for="appeal in appeals" :key="appeal.id">
                            <tr @click="open(appeal)" :class="selected?.id === appeal.id ? 'bg-surface-raised' : 'hover:bg-surface-raised'" class="cursor-pointer text-ink-muted transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="truncate font-medium text-ink" x-text="appeal.name || appeal.steamid"></span>
                                        <span
                                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="{
                                                PENDING: 'bg-brand-soft text-brand-strong',
                                                APPROVED: 'bg-brand-soft text-brand-strong',
                                                REJECTED: 'bg-surface-raised text-ink-faint',
                                            }[appeal.status]"
                                            x-text="appeal.status"
                                        ></span>
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-ink-faint" x-text="appeal.reason"></p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
                <p x-show="!loading && !error && appeals.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>
                <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
            </div>

            <div x-show="selected" x-cloak class="rounded-xl border border-line bg-surface p-5 lg:col-span-3">
                <template x-if="selected">
                    <div>
                        <button type="button" @click="selected = null" class="text-sm text-ink-muted hover:text-ink lg:hidden">
                            ← {{ __('i18n::messages.appeals.back_to_list') }}
                        </button>

                        <div class="mt-2 flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint" x-text="selected.name || selected.steamid"></p>
                                <p class="mt-1 text-sm text-ink" x-text="selected.reason"></p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="selected.status === 'PENDING' ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-faint'"
                                x-text="selected.status"
                            ></span>
                        </div>

                        <p x-show="selected.ban_id" class="mt-2 text-xs text-ink-faint">
                            {{ __('i18n::messages.appeals.ban_id') }}: <span x-text="selected.ban_id"></span>
                        </p>

                        <template x-if="selected.status !== 'PENDING'">
                            <p class="mt-3 text-xs text-ink-faint" x-text="selected.decision_note"></p>
                        </template>

                        <template x-if="selected.status === 'PENDING'">
                            <div class="mt-4 space-y-3 border-t border-line-soft pt-4">
                                <textarea
                                    x-model="decisionNote"
                                    rows="2"
                                    placeholder="{{ __('i18n::messages.appeals.decision_note') }}"
                                    class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                                ></textarea>
                                <div class="flex gap-2">
                                    <button type="button" @click="decide('APPROVED')" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                                        {{ __('i18n::messages.appeals.approve') }}
                                    </button>
                                    <button type="button" @click="decide('REJECTED')" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                                        {{ __('i18n::messages.appeals.reject') }}
                                    </button>
                                </div>
                            </div>
                        </template>

                        <p x-show="actionError" x-cloak class="mt-3 text-sm text-red-400" x-text="actionError"></p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</x-layout.app>
