<x-layout.app :title="__('i18n::messages.modules.title')">
    <div x-data="modulesPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.modules.title') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.modules.subtitle') }}</p>

        <p x-show="error" x-cloak class="mt-4 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400" x-text="error"></p>

        {{-- Two tabs rather than two pages: an installed plugin is
             structurally identical to a built-in module (same base provider,
             same enable gate), so splitting them across the sidebar made the
             panel look like it had two unrelated features. --}}
        <div class="mt-5 flex gap-1 border-b border-line">
            <button
                type="button"
                @click="tab = 'builtin'"
                class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                :class="tab === 'builtin' ? 'border-brand-strong text-brand-strong' : 'border-transparent text-ink-muted hover:text-ink'"
            >
                {{ __('i18n::messages.modules.tab_builtin') }}
                <span class="ml-1.5 rounded-full bg-surface-raised px-1.5 py-0.5 text-xs text-ink-faint" x-text="modules.length"></span>
            </button>
            <button
                type="button"
                @click="tab = 'plugins'"
                class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                :class="tab === 'plugins' ? 'border-brand-strong text-brand-strong' : 'border-transparent text-ink-muted hover:text-ink'"
            >
                {{ __('i18n::messages.modules.tab_plugins') }}
                <span class="ml-1.5 rounded-full bg-surface-raised px-1.5 py-0.5 text-xs text-ink-faint" x-text="plugins.length"></span>
            </button>
        </div>

        <p x-show="loading" x-cloak class="mt-6 text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>

        {{-- Built-in modules --}}
        <div x-show="!loading && tab === 'builtin'" x-cloak class="mt-5 space-y-3">
            <template x-for="module in modules" :key="module.key">
                <div class="flex items-center justify-between gap-4 rounded-xl border border-line bg-surface p-5">
                    <div class="min-w-0">
                        <p class="font-medium text-ink" x-text="moduleName(module.key)"></p>
                        <p class="mt-0.5 text-sm text-ink-muted" x-text="moduleDescription(module.key)"></p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="module.enabled.toString()"
                        @click="toggleModule(module)"
                        :disabled="pending[module.key]"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-50"
                        :class="module.enabled ? 'bg-brand-strong' : 'bg-surface-raised'"
                    >
                        <span class="inline-block size-4 rounded-full bg-canvas transition-transform" :class="module.enabled ? 'translate-x-6' : 'translate-x-1'"></span>
                    </button>
                </div>
            </template>

            <p x-show="modules.length === 0" class="rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.empty') }}
            </p>
        </div>

        {{-- Installed plugins --}}
        <div x-show="!loading && tab === 'plugins'" x-cloak class="mt-5">
            <div class="rounded-xl border border-dashed border-line bg-surface p-5">
                <p class="text-sm font-medium text-ink">{{ __('i18n::messages.plugins.upload_title') }}</p>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.plugins.upload_hint') }}</p>
                <input
                    type="file"
                    accept=".zip"
                    @change="upload($event)"
                    :disabled="uploading"
                    class="mt-3 block w-full text-sm text-ink-muted file:mr-3 file:rounded-lg file:border-0 file:bg-brand-strong file:px-3 file:py-2 file:text-sm file:font-medium file:text-canvas hover:file:opacity-90"
                >
                <p x-show="uploading" x-cloak class="mt-2 text-xs text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>
            </div>

            <div class="mt-3 space-y-3">
                <template x-for="plugin in plugins" :key="plugin.key">
                    <div class="flex items-center justify-between gap-4 rounded-xl border border-line bg-surface p-5">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-ink" x-text="plugin.name || plugin.key"></p>
                                <span x-show="plugin.version" class="rounded bg-surface-raised px-1.5 py-0.5 font-mono text-xs text-ink-faint" x-text="'v' + plugin.version"></span>
                            </div>
                            <p class="mt-0.5 text-sm text-ink-muted" x-text="plugin.description || plugin.key"></p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="plugin.enabled.toString()"
                                @click="togglePlugin(plugin)"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors"
                                :class="plugin.enabled ? 'bg-brand-strong' : 'bg-surface-raised'"
                            >
                                <span class="inline-block size-4 rounded-full bg-canvas transition-transform" :class="plugin.enabled ? 'translate-x-6' : 'translate-x-1'"></span>
                            </button>
                            <button
                                type="button"
                                @click="uninstall(plugin)"
                                class="rounded-lg p-2 text-ink-faint transition-colors hover:bg-red-500/10 hover:text-red-400"
                                :title="@js(__('i18n::messages.plugins.uninstall'))"
                            >
                                <x-icon name="trash" class="size-4" />
                            </button>
                        </div>
                    </div>
                </template>

                <p x-show="plugins.length === 0" class="rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">
                    {{ __('i18n::messages.plugins.none_installed') }}
                </p>
            </div>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.modulesPage = () => ({
                loading: true,
                error: '',
                tab: 'builtin',
                modules: [],
                plugins: [],
                pending: {},
                uploading: false,

                names: {
                    vip: @js(__('i18n::messages.modules.items.vip.name')),
                    skin: @js(__('i18n::messages.modules.items.skin.name')),
                    rank: @js(__('i18n::messages.modules.items.rank.name')),
                },
                descriptions: {
                    vip: @js(__('i18n::messages.modules.items.vip.description')),
                    skin: @js(__('i18n::messages.modules.items.skin.description')),
                    rank: @js(__('i18n::messages.modules.items.rank.description')),
                },

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                moduleName(key) { return this.names[key] ?? key; },
                moduleDescription(key) { return this.descriptions[key] ?? ''; },

                async init() {
                    // Both lists load together; the tabs only choose what is
                    // shown, so switching them never waits on a request.
                    await Promise.all([this.loadModules(), this.loadPlugins()]);
                    this.loading = false;
                },

                async loadModules() {
                    try {
                        const res = await fetch('/api/modules', { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        this.modules = (await res.json()).data;
                    } catch (e) {
                        this.error = @js(__('i18n::messages.common.error'));
                    }
                },

                async loadPlugins() {
                    try {
                        const res = await fetch('/api/plugins', { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        this.plugins = (await res.json()).data;
                    } catch (e) {
                        this.error = @js(__('i18n::messages.common.error'));
                    }
                },

                async toggleModule(module) {
                    const next = !module.enabled;
                    this.pending[module.key] = true;
                    this.error = '';
                    try {
                        const res = await fetch(`/api/modules/${module.key}`, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify({ enabled: next }),
                        });
                        if (!res.ok) throw new Error('request_failed');
                        module.enabled = next;
                    } catch (e) {
                        this.error = @js(__('i18n::messages.common.error'));
                    } finally {
                        delete this.pending[module.key];
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
                        await this.loadPlugins();
                    } catch (e) {
                        this.error = e.message;
                    } finally {
                        this.uploading = false;
                        event.target.value = '';
                    }
                },

                async togglePlugin(plugin) {
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
                        await this.loadPlugins();
                    } catch (e) {
                        this.error = @js(__('i18n::messages.common.error'));
                    }
                },
            });
        </script>
    @endpush
</x-layout.app>
