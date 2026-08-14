<x-layout.app :title="__('i18n::messages.modules.title')">
    <div
        x-data="{
            loading: true,
            error: false,
            modules: [],
            pending: {},
            async init() {
                try {
                    const res = await fetch('/api/modules', { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    const body = await res.json();
                    this.modules = body.data;
                } catch (e) {
                    this.error = true;
                } finally {
                    this.loading = false;
                }
            },
            async toggle(module) {
                const next = !module.enabled;
                this.pending[module.key] = true;
                this.error = false;
                try {
                    const res = await fetch(`/api/modules/${module.key}`, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({ enabled: next }),
                    });
                    if (!res.ok) throw new Error('request_failed');
                    module.enabled = next;
                } catch (e) {
                    this.error = true;
                } finally {
                    delete this.pending[module.key];
                }
            },
        }"
        x-init="init()"
    >
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.modules.title') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.modules.subtitle') }}</p>

        <p x-show="loading" x-cloak class="mt-6 text-sm text-ink-faint">
            {{ __('i18n::messages.common.loading') }}
        </p>

        <div x-show="!loading" x-cloak class="mt-6 space-y-3">
            <template x-for="module in modules" :key="module.key">
                <div class="flex items-center justify-between gap-4 rounded-xl border border-line bg-surface p-5">
                    <div class="min-w-0">
                        <p class="font-medium text-ink" x-text="{
                            vip: @js(__('i18n::messages.modules.items.vip.name')),
                            skin: @js(__('i18n::messages.modules.items.skin.name')),
                            rank: @js(__('i18n::messages.modules.items.rank.name')),
                        }[module.key] ?? module.key"></p>
                        <p class="mt-0.5 text-sm text-ink-muted" x-text="{
                            vip: @js(__('i18n::messages.modules.items.vip.description')),
                            skin: @js(__('i18n::messages.modules.items.skin.description')),
                            rank: @js(__('i18n::messages.modules.items.rank.description')),
                        }[module.key] ?? ''"></p>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="module.enabled.toString()"
                        :disabled="pending[module.key]"
                        @click="toggle(module)"
                        :class="module.enabled ? 'bg-brand-strong' : 'bg-line'"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-50"
                    >
                        <span
                            :class="module.enabled ? 'translate-x-5' : 'translate-x-0.5'"
                            class="inline-block size-5 transform rounded-full bg-white transition-transform"
                        ></span>
                    </button>
                </div>
            </template>
        </div>

        <p x-show="error" x-cloak class="mt-6 text-sm text-red-400">
            {{ __('i18n::messages.common.error') }}
        </p>
    </div>
</x-layout.app>
