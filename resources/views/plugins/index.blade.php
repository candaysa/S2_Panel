<x-layout.app :title="__('i18n::messages.nav.plugins')">
    <div
        x-data="{
            loading: true,
            error: '',
            plugins: [],
            uploading: false,

            csrf() {
                return document.querySelector('meta[name=csrf-token]').content;
            },

            async load() {
                this.loading = true;
                try {
                    const res = await fetch('/api/plugins', { headers: { Accept: 'application/json' } });
                    if (!res.ok) throw new Error('request_failed');
                    this.plugins = (await res.json()).data;
                } catch (e) {
                    this.error = @js(__('i18n::messages.common.error'));
                } finally {
                    this.loading = false;
                }
            },

            async upload(event) {
                const file = event.target.files[0];
                if (!file) return;
                this.uploading = true;
                this.error = '';
                try {
                    const data = new FormData();
                    data.append('file', file);
                    const res = await fetch('/api/plugins', {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: data,
                    });
                    const body = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(body.message || 'request_failed');
                    await this.load();
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.uploading = false;
                    event.target.value = '';
                }
            },

            async toggle(plugin) {
                this.error = '';
                try {
                    const res = await fetch(`/api/plugins/${plugin.key}`, {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify({ enabled: !plugin.enabled }),
                    });
                    if (!res.ok) throw new Error('request_failed');
                    plugin.enabled = !plugin.enabled;
                } catch (e) {
                    this.error = @js(__('i18n::messages.common.error'));
                }
            },

            async uninstall(plugin) {
                if (!confirm(@js(__('i18n::messages.plugins.uninstall_confirm')))) return;
                this.error = '';
                try {
                    const res = await fetch(`/api/plugins/${plugin.key}`, {
                        method: 'DELETE',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    });
                    if (!res.ok) throw new Error('request_failed');
                    await this.load();
                } catch (e) {
                    this.error = @js(__('i18n::messages.common.error'));
                }
            },

            init() { this.load(); },
        }"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.plugins') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.plugins.subtitle') }}</p>
            </div>

            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90">
                <x-icon name="upload" class="size-4" />
                <span x-show="!uploading">{{ __('i18n::messages.plugins.upload_zip') }}</span>
                <span x-show="uploading" x-cloak>{{ __('i18n::messages.common.loading') }}</span>
                <input type="file" accept=".zip" class="hidden" :disabled="uploading" @change="upload($event)">
            </label>
        </div>

        <p class="mt-4 rounded-lg border border-line-soft bg-surface px-4 py-3 text-xs text-ink-faint">
            {{ __('i18n::messages.plugins.trust_note') }}
        </p>

        <p x-show="error" x-cloak class="mt-4 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400" x-text="error"></p>

        <div class="mt-4 space-y-3">
            <template x-for="plugin in plugins" :key="plugin.key">
                <div class="flex items-center justify-between gap-4 rounded-xl border border-line bg-surface p-5">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="font-medium text-ink" x-text="plugin.name"></p>
                            <span class="text-xs text-ink-faint" x-text="plugin.version ? 'v' + plugin.version : ''"></span>
                        </div>
                        <p class="mt-0.5 text-sm text-ink-muted" x-text="plugin.description || plugin.key"></p>
                        <p class="mt-0.5 text-xs text-ink-faint" x-text="plugin.author"></p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="plugin.enabled.toString()"
                            @click="toggle(plugin)"
                            :class="plugin.enabled ? 'bg-brand-strong' : 'bg-line'"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors"
                        >
                            <span :class="plugin.enabled ? 'translate-x-5' : 'translate-x-0.5'" class="inline-block size-5 transform rounded-full bg-white transition-transform"></span>
                        </button>
                        <button type="button" @click="uninstall(plugin)" class="text-sm text-red-400 hover:underline">
                            {{ __('i18n::messages.plugins.uninstall') }}
                        </button>
                    </div>
                </div>
            </template>

            <p x-show="loading" x-cloak class="rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
            <p x-show="!loading && plugins.length === 0" x-cloak class="rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">{{ __('i18n::messages.common.empty') }}</p>
        </div>
    </div>
</x-layout.app>
