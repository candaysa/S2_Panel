<x-layout.app :title="__('i18n::messages.nav.reports')">
    <div
        x-data="{
            loading: true,
            forbidden: false,
            error: false,
            status: 'open',
            tickets: [],
            selected: null,
            selectedLoading: false,
            replyMessage: '',
            replySending: false,
            actionError: '',
            showNewForm: false,
            newTicket: { ticket_type: 'report', report_reason: '', target_steamid: '', target_name: '' },
            creating: false,

            async load() {
                this.loading = true;
                this.error = false;
                this.forbidden = false;
                try {
                    const url = new URL('/api/reports', window.location.origin);
                    if (this.status !== 'all') url.searchParams.set('status', this.status);
                    const res = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (res.status === 403) { this.forbidden = true; return; }
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.tickets = body.data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },

            async open(ticket) {
                this.selected = ticket;
                this.selectedLoading = true;
                this.actionError = '';
                try {
                    const res = await fetch(`/api/reports/${ticket.id}`, { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.selected = body.data;
                } catch (e) {
                    this.actionError = @js(__('i18n::messages.common.error'));
                } finally {
                    this.selectedLoading = false;
                }
            },

            close() {
                this.selected = null;
                this.replyMessage = '';
            },

            csrf() {
                return document.querySelector('meta[name=csrf-token]').content;
            },

            async sendReply() {
                if (!this.replyMessage.trim()) return;
                this.replySending = true;
                this.actionError = '';
                try {
                    const res = await fetch(`/api/reports/${this.selected.id}/reply`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify({ message: this.replyMessage }),
                    });
                    if (!res.ok) throw new Error('request_failed');
                    this.replyMessage = '';
                    await this.open(this.selected);
                } catch (e) {
                    this.actionError = @js(__('i18n::messages.common.error'));
                } finally {
                    this.replySending = false;
                }
            },

            async resolve(resolution) {
                this.actionError = '';
                try {
                    const res = await fetch(`/api/reports/${this.selected.id}/close`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify({ resolution }),
                    });
                    if (res.status === 403) { this.actionError = @js(__('i18n::messages.common.forbidden')); return; }
                    if (!res.ok) throw new Error('request_failed');
                    await this.open(this.selected);
                    await this.load();
                } catch (e) {
                    this.actionError = @js(__('i18n::messages.common.error'));
                }
            },

            async destroyTicket() {
                if (!confirm(@js(__('i18n::messages.reports.delete_confirm')))) return;
                this.actionError = '';
                try {
                    const res = await fetch(`/api/reports/${this.selected.id}`, {
                        method: 'DELETE',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                    if (res.status === 403) { this.actionError = @js(__('i18n::messages.common.forbidden')); return; }
                    if (!res.ok) throw new Error('request_failed');
                    this.close();
                    await this.load();
                } catch (e) {
                    this.actionError = @js(__('i18n::messages.common.error'));
                }
            },

            async submitNewTicket() {
                if (!this.newTicket.report_reason.trim()) return;
                this.creating = true;
                this.actionError = '';
                try {
                    const res = await fetch('/api/reports', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify(this.newTicket),
                    });
                    if (!res.ok) throw new Error('request_failed');
                    this.newTicket = { ticket_type: 'report', report_reason: '', target_steamid: '', target_name: '' };
                    this.showNewForm = false;
                    await this.load();
                } catch (e) {
                    this.actionError = @js(__('i18n::messages.common.error'));
                } finally {
                    this.creating = false;
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
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.reports') }}</h1>
            <button
                type="button"
                @click="showNewForm = !showNewForm"
                class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90"
            >
                {{ __('i18n::messages.reports.new_ticket') }}
            </button>
        </div>

        {{-- New ticket form --}}
        <div x-show="showNewForm" x-cloak x-transition class="mt-4 rounded-xl border border-line bg-surface p-5">
            <div class="grid gap-3 sm:grid-cols-2">
                <select x-model="newTicket.ticket_type" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <option value="report">{{ __('i18n::messages.reports.type_report') }}</option>
                    <option value="admin_application">{{ __('i18n::messages.reports.type_admin_application') }}</option>
                </select>
                <div></div>
                <input type="text" x-model="newTicket.target_steamid" placeholder="{{ __('i18n::messages.reports.target_steamid') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                <input type="text" x-model="newTicket.target_name" placeholder="{{ __('i18n::messages.reports.target_name') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
            </div>
            <textarea
                x-model="newTicket.report_reason"
                rows="3"
                placeholder="{{ __('i18n::messages.reports.reason_placeholder') }}"
                class="mt-3 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
            ></textarea>
            <button
                type="button"
                :disabled="creating"
                @click="submitNewTicket()"
                class="mt-3 inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
            >
                {{ __('i18n::messages.reports.submit') }}
            </button>
        </div>

        <div class="mt-4 flex flex-wrap gap-1.5">
            <template x-for="s in ['open', 'closed', 'all']" :key="s">
                <button
                    type="button"
                    @click="status = s; load()"
                    :class="status === s ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium capitalize transition-colors"
                    x-text="{
                        open: @js(__('i18n::messages.reports.status_open')),
                        closed: @js(__('i18n::messages.reports.status_closed')),
                        all: @js(__('i18n::messages.reports.status_all')),
                    }[s]"
                ></button>
            </template>
        </div>

        <div class="mt-4 grid gap-6 lg:grid-cols-5">
            {{-- List --}}
            <div class="overflow-x-auto rounded-xl border border-line bg-surface lg:col-span-2" :class="selected ? 'hidden lg:block' : ''">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-line-soft">
                        <template x-for="ticket in tickets" :key="ticket.id">
                            <tr
                                @click="open(ticket)"
                                :class="selected?.id === ticket.id ? 'bg-surface-raised' : 'hover:bg-surface-raised'"
                                class="cursor-pointer text-ink-muted transition-colors"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="truncate font-medium text-ink" x-text="ticket.target_name || ticket.reporter_name"></span>
                                        <span
                                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="ticket.status === 'open' ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-faint'"
                                            x-text="ticket.status"
                                        ></span>
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-ink-faint" x-text="ticket.report_reason"></p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
                <p x-show="!loading && !forbidden && !error && tickets.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>
                <p x-show="forbidden" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.forbidden') }}</p>
                <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
            </div>

            {{-- Detail --}}
            <div x-show="selected" x-cloak class="rounded-xl border border-line bg-surface p-5 lg:col-span-3">
                <template x-if="selected">
                    <div>
                        <button type="button" @click="close()" class="text-sm text-ink-muted hover:text-ink lg:hidden">
                            ← {{ __('i18n::messages.reports.back_to_list') }}
                        </button>

                        <div class="mt-2 flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint" x-text="selected.ticket_type"></p>
                                <p class="mt-1 text-sm text-ink" x-text="selected.report_reason"></p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="selected.status === 'open' ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-faint'"
                                x-text="selected.status"
                            ></span>
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-2 text-xs text-ink-faint">
                            <div><dt class="inline">{{ __('i18n::messages.reports.reporter') }}: </dt><dd class="inline text-ink-muted" x-text="selected.reporter_name"></dd></div>
                            <div x-show="selected.target_name"><dt class="inline">{{ __('i18n::messages.reports.target') }}: </dt><dd class="inline text-ink-muted" x-text="selected.target_name"></dd></div>
                        </dl>

                        <div class="mt-4 space-y-3 border-t border-line-soft pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.reports.thread') }}</p>
                            <template x-for="reply in (selected.replies ?? [])" :key="reply.id">
                                <div class="rounded-lg bg-surface-raised p-3">
                                    <p class="text-xs font-medium text-ink" x-text="reply.author_name"></p>
                                    <p class="mt-1 text-sm text-ink-muted" x-text="reply.message"></p>
                                </div>
                            </template>
                        </div>

                        <div class="mt-4 flex gap-2">
                            <input
                                type="text"
                                x-model="replyMessage"
                                @keydown.enter="sendReply()"
                                placeholder="{{ __('i18n::messages.reports.reply_placeholder') }}"
                                class="flex-1 rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                            >
                            <button
                                type="button"
                                :disabled="replySending"
                                @click="sendReply()"
                                class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                            >
                                {{ __('i18n::messages.reports.send') }}
                            </button>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2 border-t border-line-soft pt-4">
                            <button type="button" x-show="selected.status === 'open'" @click="resolve('APPROVED')" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                                {{ __('i18n::messages.reports.approve') }}
                            </button>
                            <button type="button" x-show="selected.status === 'open'" @click="resolve('REJECTED')" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                                {{ __('i18n::messages.reports.reject') }}
                            </button>
                            <button type="button" @click="destroyTicket()" class="ml-auto rounded-lg border border-line px-3 py-1.5 text-sm text-red-400 transition-colors hover:bg-red-500/10">
                                {{ __('i18n::messages.reports.delete_ticket') }}
                            </button>
                        </div>

                        <p x-show="actionError" x-cloak class="mt-3 text-sm text-red-400" x-text="actionError"></p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</x-layout.app>
