<x-layout.app :title="__('i18n::messages.nav.health')">
    <div
        x-data="{
            loading: true,
            error: false,
            health: null,
            notifications: [],

            csrf() {
                return document.querySelector('meta[name=csrf-token]').content;
            },

            async load() {
                this.loading = true;
                this.error = false;
                try {
                    const [healthRes, notifRes] = await Promise.all([
                        fetch('/api/health', { headers: { Accept: 'application/json' } }),
                        fetch('/api/notifications', { headers: { Accept: 'application/json' } }),
                    ]);
                    if (healthRes.ok) this.health = (await healthRes.json()).data;
                    if (notifRes.ok) this.notifications = (await notifRes.json()).data;
                } catch (e) {
                    this.error = true;
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

            init() { this.load(); },
        }"
        x-init="init()"
    >
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.health') }}</h1>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            {{-- Component status --}}
            <div class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.health.checks') }}</h2>
                <div class="mt-3 divide-y divide-line-soft">
                    <template x-for="check in (health?.checks ?? [])" :key="check.component">
                        <div class="flex items-center justify-between gap-3 py-2.5">
                            <div>
                                <p class="text-sm font-medium text-ink" x-text="check.component"></p>
                                <p class="text-xs text-ink-faint" x-text="check.message || ''"></p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="check.status === 'ok' ? 'bg-brand-soft text-brand-strong' : 'bg-red-500/10 text-red-400'"
                                x-text="check.status"
                            ></span>
                        </div>
                    </template>
                    <p x-show="!loading && (health?.checks ?? []).length === 0" class="py-4 text-center text-sm text-ink-faint">
                        {{ __('i18n::messages.common.empty') }}
                    </p>
                </div>
            </div>

            {{-- Notifications --}}
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.health.notifications') }}</h2>
                    <button type="button" @click="markAllRead()" class="text-xs text-ink-muted hover:text-ink">
                        {{ __('i18n::messages.health.mark_all_read') }}
                    </button>
                </div>
                <div class="mt-3 divide-y divide-line-soft">
                    <template x-for="notification in notifications" :key="notification.id">
                        <div @click="markRead(notification)" class="cursor-pointer py-2.5" :class="notification.read ? '' : 'font-medium'">
                            <div class="flex items-center gap-2">
                                <span x-show="!notification.read" class="size-1.5 shrink-0 rounded-full bg-brand-strong"></span>
                                <p class="text-sm text-ink" x-text="notification.title"></p>
                            </div>
                            <p class="mt-0.5 text-xs text-ink-faint" x-text="notification.body"></p>
                            <p class="mt-0.5 text-xs text-ink-faint" x-text="formatDate(notification.created_at)"></p>
                        </div>
                    </template>
                    <p x-show="!loading && notifications.length === 0" class="py-4 text-center text-sm text-ink-faint">
                        {{ __('i18n::messages.health.no_notifications') }}
                    </p>
                </div>
            </div>
        </div>

        <p x-show="error" x-cloak class="mt-6 text-sm text-red-400">{{ __('i18n::messages.common.error') }}</p>
    </div>
</x-layout.app>
