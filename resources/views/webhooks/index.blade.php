<x-layout.app :title="__('i18n::messages.nav.webhooks')">
    <div
        x-data="{
            loading: true,
            error: false,
            webhooks: [],
            eventOptions: [],
            showForm: false,
            saving: false,
            form: { name: '', url: '', events: [], enabled: true },

            csrf() {
                return document.querySelector('meta[name=csrf-token]').content;
            },

            async load() {
                this.loading = true;
                this.error = false;
                try {
                    const res = await fetch('/api/webhooks', { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.webhooks = body.data;
                    this.eventOptions = body.meta.events ?? [];
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },

            toggleEvent(event) {
                const i = this.form.events.indexOf(event);
                if (i === -1) this.form.events.push(event); else this.form.events.splice(i, 1);
            },

            async submit() {
                this.saving = true;
                this.error = false;
                try {
                    const res = await fetch('/api/webhooks', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify(this.form),
                    });
                    if (!res.ok) throw new Error('request_failed');
                    this.form = { name: '', url: '', events: [], enabled: true };
                    this.showForm = false;
                    await this.load();
                } catch (e) {
                    this.error = true;
                } finally {
                    this.saving = false;
                }
            },

            async test(webhook) {
                try {
                    await fetch(`/api/webhooks/${webhook.id}/test`, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                } catch (e) {}
            },

            async destroy(webhook) {
                if (!confirm(@js(__('i18n::messages.webhooks.delete_confirm')))) return;
                try {
                    const res = await fetch(`/api/webhooks/${webhook.id}`, {
                        method: 'DELETE',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                    if (!res.ok) throw new Error('request_failed');
                    await this.load();
                } catch (e) {
                    this.error = true;
                }
            },

            init() { this.load(); },
        }"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.webhooks') }}</h1>
            <button type="button" @click="showForm = !showForm" class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90">
                {{ __('i18n::messages.webhooks.add_webhook') }}
            </button>
        </div>

        <div x-show="showForm" x-cloak x-transition class="mt-4 rounded-xl border border-line bg-surface p-5">
            <div class="grid gap-3 sm:grid-cols-2">
                <input type="text" x-model="form.name" placeholder="{{ __('i18n::messages.webhooks.name') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                <input type="text" x-model="form.url" placeholder="{{ __('i18n::messages.webhooks.url') }}" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
            </div>
            <p class="mt-3 text-xs font-medium text-ink-faint">{{ __('i18n::messages.webhooks.events') }}</p>
            <div class="mt-2 flex flex-wrap gap-1.5">
                <template x-for="event in eventOptions" :key="event">
                    <button
                        type="button"
                        @click="toggleEvent(event)"
                        :class="form.events.includes(event) ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-muted'"
                        class="rounded-full px-2.5 py-1 text-xs font-medium transition-colors"
                        x-text="event"
                    ></button>
                </template>
            </div>
            <p class="mt-3 text-xs text-ink-faint">{{ __('i18n::messages.webhooks.url_note') }}</p>
            <button type="button" :disabled="saving" @click="submit()" class="mt-3 inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50">
                {{ __('i18n::messages.webhooks.save') }}
            </button>
        </div>

        <div class="mt-4 space-y-3">
            <template x-for="webhook in webhooks" :key="webhook.id">
                <div class="flex items-center justify-between gap-3 rounded-xl border border-line bg-surface p-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="font-medium text-ink" x-text="webhook.name"></p>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="webhook.enabled ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-faint'"
                                x-text="webhook.enabled ? @js(__('i18n::messages.webhooks.enabled')) : ''"
                            ></span>
                        </div>
                        <p class="mt-0.5 font-mono text-xs text-ink-faint" x-text="webhook.url_hint"></p>
                        <p class="mt-0.5 text-xs text-ink-faint" x-text="(webhook.events ?? []).join(', ')"></p>
                    </div>
                    <div class="flex shrink-0 gap-2 text-sm">
                        <button type="button" @click="test(webhook)" class="text-ink-muted hover:text-ink">{{ __('i18n::messages.webhooks.test') }}</button>
                        <button type="button" @click="destroy(webhook)" class="text-red-400 hover:underline">{{ __('i18n::messages.webhooks.delete') }}</button>
                    </div>
                </div>
            </template>

            <p x-show="loading" x-cloak class="rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
            <p x-show="!loading && !error && webhooks.length === 0" x-cloak class="rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>
            <p x-show="error" x-cloak class="text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
        </div>
    </div>
</x-layout.app>
