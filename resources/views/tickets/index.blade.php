<x-layout.app :title="__('i18n::messages.nav.tickets')">
    <div x-data="ticketsPage()" x-init="init()">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.tickets') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.tickets.subtitle') }}</p>
            </div>
            <div class="flex items-center gap-2">
                {{-- The category dropdown - reports, admin applications and ban
                     appeals are three different backends (Report vs Appeal
                     models) presented as one queue, the way a ticket system
                     usually groups unrelated request types under one roof. --}}
                <div class="relative">
                    <select
                        x-model="category"
                        @change="selected = null; load()"
                        class="w-full appearance-none rounded-lg border border-line bg-surface py-2 pl-3 pr-9 text-sm font-medium text-ink focus:border-brand-strong focus:outline-none"
                    >
                        <option value="report">{{ __('i18n::messages.tickets.category_reports') }}</option>
                        <option value="admin_application">{{ __('i18n::messages.tickets.category_applications') }}</option>
                        <option value="ban_appeal">{{ __('i18n::messages.tickets.category_appeals') }}</option>
                    </select>
                    <x-icon name="chevron-left" class="pointer-events-none absolute right-3 top-1/2 size-3.5 -translate-y-1/2 -rotate-90 text-ink-faint" />
                </div>
                <button
                    type="button"
                    x-show="category !== 'ban_appeal'"
                    @click="showNewForm = !showNewForm"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-strong px-3 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90"
                >
                    <x-icon name="plus" class="size-4" />
                    {{ __('i18n::messages.reports.new_ticket') }}
                </button>
            </div>
        </div>

        {{-- New report / application --}}
        <div x-show="composing" x-cloak x-transition class="mt-4 rounded-xl border border-line bg-surface p-5">
            {{-- The category picker also lives here, not just in the header -
                 it is the one thing that decides which queue this ticket
                 lands in, and burying that choice behind an unrelated
                 dropdown up top made it easy to submit into the wrong one
                 without noticing. --}}
            <div>
                <label class="block text-xs font-medium uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.tickets.category_label') }}</label>
                <div class="relative mt-1 max-w-xs">
                    <select
                        x-model="category"
                        @change="selected = null; load()"
                        class="w-full appearance-none rounded-lg border border-line bg-canvas py-2 pl-3 pr-9 text-sm font-medium text-ink focus:border-brand-strong focus:outline-none"
                    >
                        <option value="report">{{ __('i18n::messages.tickets.category_reports') }}</option>
                        <option value="admin_application">{{ __('i18n::messages.tickets.category_applications') }}</option>
                    </select>
                    <x-icon name="chevron-left" class="pointer-events-none absolute right-3 top-1/2 size-3.5 -translate-y-1/2 -rotate-90 text-ink-faint" />
                </div>
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <input type="text" x-model="newTicket.target_steamid" placeholder="{{ __('i18n::messages.reports.target_steamid') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                <input type="text" x-model="newTicket.target_name" placeholder="{{ __('i18n::messages.reports.target_name') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
            </div>
            <textarea
                x-model="newTicket.report_reason"
                rows="3"
                placeholder="{{ __('i18n::messages.reports.reason_placeholder') }}"
                class="mt-3 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
            ></textarea>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    :disabled="creating"
                    @click="submitNewTicket()"
                    class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    {{ __('i18n::messages.reports.submit') }}
                </button>
                <button
                    type="button"
                    @click="showNewForm = false"
                    class="rounded-lg border border-line px-4 py-2 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink"
                >
                    {{ __('i18n::messages.common.cancel') }}
                </button>
            </div>

            <p x-show="actionError" x-cloak class="mt-3 text-sm text-red-400" x-text="actionError"></p>
        </div>

        {{-- Status filter, vocabulary switches with the category. Hidden
             while composing: filtering a queue you are not looking at does
             nothing, and its empty state ("Nothing here yet.") sitting under
             a half-filled form read as if the submission had already
             failed. --}}
        <div x-show="!composing" x-cloak class="mt-4 flex flex-wrap gap-1.5">
            <template x-for="s in statusOptions" :key="s.key">
                <button
                    type="button"
                    @click="status = s.key; load()"
                    :class="status === s.key ? 'bg-brand-soft text-brand-strong' : 'text-ink-muted hover:bg-surface-raised hover:text-ink'"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                    x-text="s.label"
                ></button>
            </template>
        </div>

        <div x-show="!composing" x-cloak class="mt-4 grid gap-6 lg:grid-cols-5">
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
                                        <span class="truncate font-medium text-ink" x-text="ticketTitle(ticket)"></span>
                                        <span
                                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="isOpenState(ticket) ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-faint'"
                                            x-text="ticketStatus(ticket)"
                                        ></span>
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-ink-faint" x-text="ticket.report_reason ?? ticket.reason"></p>
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
                        <button type="button" @click="selected = null" class="text-sm text-ink-muted hover:text-ink lg:hidden">
                            ← {{ __('i18n::messages.reports.back_to_list') }}
                        </button>

                        <div class="mt-2 flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint" x-text="ticketTitle(selected)"></p>
                                <p class="mt-1 text-sm text-ink" x-text="selected.report_reason ?? selected.reason"></p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="isOpenState(selected) ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-faint'"
                                x-text="ticketStatus(selected)"
                            ></span>
                        </div>

                        {{-- Report / admin application detail --}}
                        <template x-if="category !== 'ban_appeal'">
                            <div>
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
                                    <button type="button" x-show="canDecide && selected.status === 'open'" @click="resolveReport('APPROVED')" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                                        {{ __('i18n::messages.reports.approve') }}
                                    </button>
                                    <button type="button" x-show="canDecide && selected.status === 'open'" @click="resolveReport('REJECTED')" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                                        {{ __('i18n::messages.reports.reject') }}
                                    </button>
                                    <button type="button" @click="destroyReport()" class="ml-auto rounded-lg border border-line px-3 py-1.5 text-sm text-red-400 transition-colors hover:bg-red-500/10">
                                        {{ __('i18n::messages.reports.delete_ticket') }}
                                    </button>
                                </div>
                            </div>
                        </template>

                        {{-- Ban appeal detail --}}
                        <template x-if="category === 'ban_appeal'">
                            <div>
                                <p x-show="selected.ban_id" class="mt-2 text-xs text-ink-faint">
                                    {{ __('i18n::messages.appeals.ban_id') }}: <span x-text="selected.ban_id"></span>
                                </p>

                                <template x-if="selected.status !== 'PENDING'">
                                    <p class="mt-3 text-xs text-ink-faint" x-text="selected.decision_note"></p>
                                </template>

                                <template x-if="canDecide && selected.status === 'PENDING'">
                                    <div class="mt-4 space-y-3 border-t border-line-soft pt-4">
                                        <textarea
                                            x-model="decisionNote"
                                            rows="2"
                                            placeholder="{{ __('i18n::messages.appeals.decision_note') }}"
                                            class="w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none"
                                        ></textarea>
                                        <div class="flex gap-2">
                                            <button type="button" @click="decideAppeal('APPROVED')" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                                                {{ __('i18n::messages.appeals.approve') }}
                                            </button>
                                            <button type="button" @click="decideAppeal('REJECTED')" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                                                {{ __('i18n::messages.appeals.reject') }}
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <p x-show="actionError" x-cloak class="mt-3 text-sm text-red-400" x-text="actionError"></p>
                    </div>
                </template>
            </div>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.ticketsPage = () => ({
                loading: true,
                forbidden: false,
                error: false,
                category: 'report',
                status: 'open',
                tickets: [],
                selected: null,
                replyMessage: '',
                replySending: false,
                actionError: '',
                showNewForm: false,
                newTicket: { target_steamid: '', target_name: '', report_reason: '' },
                creating: false,
                decisionNote: '',
                canDecide: @js($canDecide ?? false),

                labels: @js(__('i18n::messages.reports')),
                appealLabels: @js(__('i18n::messages.appeals')),

                // Composing takes over the page rather than sitting on top
                // of the queue. Ban appeals are raised from a ban, not from
                // here, which is why the form never opens for that category.
                get composing() {
                    return this.showNewForm && this.category !== 'ban_appeal';
                },

                get statusOptions() {
                    if (this.category === 'ban_appeal') {
                        return [
                            { key: '', label: this.appealLabels.status_all },
                            { key: 'PENDING', label: this.appealLabels.status_pending },
                            { key: 'APPROVED', label: this.appealLabels.status_approved },
                            { key: 'REJECTED', label: this.appealLabels.status_rejected },
                        ];
                    }
                    return [
                        { key: 'open', label: this.labels.status_open },
                        { key: 'closed', label: this.labels.status_closed },
                        { key: 'all', label: this.labels.status_all },
                    ];
                },

                ticketTitle(t) {
                    if (!t) return '';
                    return this.category === 'ban_appeal' ? (t.name || t.steamid) : (t.target_name || t.reporter_name);
                },

                ticketStatus(t) {
                    return t?.status ?? '';
                },

                isOpenState(t) {
                    if (this.category === 'ban_appeal') return (t?.status ?? '') === 'PENDING';
                    return (t?.status ?? '') === 'open';
                },

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                async load() {
                    // A category switch has no matching status in the other
                    // vocabulary (open/closed vs PENDING/APPROVED/REJECTED),
                    // so the filter resets rather than silently applying a
                    // status the new category doesn't recognise.
                    if (this.category === 'ban_appeal' && !['', 'PENDING', 'APPROVED', 'REJECTED'].includes(this.status)) this.status = '';
                    if (this.category !== 'ban_appeal' && !['open', 'closed', 'all'].includes(this.status)) this.status = 'open';

                    this.loading = true;
                    this.error = false;
                    this.forbidden = false;
                    try {
                        if (this.category === 'ban_appeal') {
                            const res = await fetch('/api/appeals', { headers: { Accept: 'application/json' } });
                            if (res.status === 403) { this.forbidden = true; return; }
                            if (!res.ok) throw new Error('request_failed');
                            const body = await res.json();
                            this.tickets = this.status ? body.data.filter((a) => a.status === this.status) : body.data;
                        } else {
                            const url = new URL('/api/reports', window.location.origin);
                            url.searchParams.set('ticket_type', this.category);
                            if (this.status !== 'all') url.searchParams.set('status', this.status);
                            const res = await fetch(url, { headers: { Accept: 'application/json' } });
                            if (res.status === 403) { this.forbidden = true; return; }
                            if (!res.ok) throw new Error('request_failed');
                            const body = await res.json();
                            this.tickets = body.data;
                        }
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                async open(ticket) {
                    this.selected = ticket;
                    this.actionError = '';
                    this.decisionNote = '';
                    if (this.category === 'ban_appeal') return;
                    try {
                        const res = await fetch(`/api/reports/${ticket.id}`, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        this.selected = (await res.json()).data;
                    } catch (e) {
                        this.actionError = @js(__('i18n::messages.common.error'));
                    }
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

                async resolveReport(resolution) {
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

                async destroyReport() {
                    if (!confirm(@js(__('i18n::messages.reports.delete_confirm')))) return;
                    this.actionError = '';
                    try {
                        const res = await fetch(`/api/reports/${this.selected.id}`, {
                            method: 'DELETE',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        if (res.status === 403) { this.actionError = @js(__('i18n::messages.common.forbidden')); return; }
                        if (!res.ok) throw new Error('request_failed');
                        this.selected = null;
                        await this.load();
                    } catch (e) {
                        this.actionError = @js(__('i18n::messages.common.error'));
                    }
                },

                async decideAppeal(status) {
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

                async submitNewTicket() {
                    if (!this.newTicket.report_reason.trim()) return;
                    this.creating = true;
                    this.actionError = '';
                    try {
                        const res = await fetch('/api/reports', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify({ ...this.newTicket, ticket_type: this.category }),
                        });
                        if (!res.ok) throw new Error('request_failed');
                        this.newTicket = { target_steamid: '', target_name: '', report_reason: '' };
                        this.showNewForm = false;
                        await this.load();
                    } catch (e) {
                        this.actionError = @js(__('i18n::messages.common.error'));
                    } finally {
                        this.creating = false;
                    }
                },

                init() { this.load(); },
            });
        </script>
    @endpush
</x-layout.app>
