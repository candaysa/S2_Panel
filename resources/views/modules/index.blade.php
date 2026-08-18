<x-layout.app :title="__('i18n::messages.modules.title')">
    <div x-data="modulesPage()" x-init="init()">
        <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.modules.title') }}</h1>
        <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.modules.subtitle') }}</p>

        <x-settings-tabs current="modules" />

        <p x-show="error" x-cloak class="mt-4 rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400" x-text="error"></p>

        {{-- Modules and Docs, not Built-in/Plugins - a built-in module and an
             installed plugin are the same thing under the hood (same base
             provider, same enable gate), so splitting them across tabs made
             the panel look like it had two unrelated features for no reason. --}}
        <div class="mt-5 flex gap-1 border-b border-line">
            <button
                type="button"
                @click="tab = 'modules'"
                class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                :class="tab === 'modules' ? 'border-brand-strong text-brand-strong' : 'border-transparent text-ink-muted hover:text-ink'"
            >
                {{ __('i18n::messages.modules.tab_modules') }}
            </button>
            <button
                type="button"
                @click="tab = 'docs'"
                class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
                :class="tab === 'docs' ? 'border-brand-strong text-brand-strong' : 'border-transparent text-ink-muted hover:text-ink'"
            >
                {{ __('i18n::messages.modules.tab_docs') }}
            </button>
        </div>

        {{-- ================= MODULES TAB ================= --}}
        <div x-show="tab === 'modules'" x-cloak>
            {{-- Admin plugin: which schema Admins/Bans/RCON read from. Not a
                 whitelisted Settings field on purpose (see config/settings.php)
                 - this is the one deliberate way to change it, gated behind
                 its own confirmation rather than a generic settings form. --}}
            <div class="mt-5 rounded-xl border border-line bg-surface p-5">
                <p class="font-medium text-ink">{{ __('i18n::messages.modules.admin_plugin_title') }}</p>
                <p class="mt-0.5 text-sm text-ink-muted">{{ __('i18n::messages.modules.admin_plugin_hint') }}</p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <template x-for="opt in adminPluginOptions" :key="opt.key">
                        <button
                            type="button"
                            :disabled="adminPluginSaving"
                            @click="setAdminPlugin(opt.key)"
                            class="rounded-lg px-3 py-2 text-sm font-medium transition-colors disabled:opacity-50"
                            :class="adminPlugin === opt.key ? 'bg-brand-strong text-canvas' : 'border border-line text-ink-muted hover:bg-surface-raised hover:text-ink'"
                        >
                            <span x-text="opt.label"></span>
                        </button>
                    </template>
                </div>

                <p x-show="adminPlugin === 'swiftly_admins'" x-cloak class="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-400">
                    {{ __('i18n::messages.admins.swiftly_admins_notice') }}
                </p>
            </div>

            <div class="mt-5 rounded-xl border border-dashed border-line bg-surface p-5">
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

            <p x-show="loading" x-cloak class="mt-6 text-sm text-ink-faint">{{ __('i18n::messages.common.loading') }}</p>

            {{-- One list: every owner-toggleable built-in feature, then every
                 installed plugin, sorted by name. See
                 ModuleRegistry::toggleable() for what qualifies. --}}
            <div x-show="!loading" x-cloak class="mt-5 space-y-3">
                <template x-for="item in combined" :key="item.key">
                    <div class="flex items-center justify-between gap-4 rounded-xl border border-line bg-surface p-5">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-ink" x-text="item.name"></p>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="item.origin === 'plugin' ? 'bg-brand-soft text-brand-strong' : 'bg-surface-raised text-ink-faint'"
                                    x-text="item.origin === 'plugin' ? @js(__('i18n::messages.modules.badge_plugin')) : @js(__('i18n::messages.modules.badge_builtin'))"
                                ></span>
                                <span x-show="item.version" class="rounded bg-surface-raised px-1.5 py-0.5 font-mono text-xs text-ink-faint" x-text="'v' + item.version"></span>
                            </div>
                            <p class="mt-0.5 text-sm text-ink-muted" x-text="item.description"></p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="item.enabled.toString()"
                                @click="toggle(item)"
                                :disabled="pending[item.key]"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-50"
                                :class="item.enabled ? 'bg-brand-strong' : 'bg-surface-raised'"
                            >
                                <span class="inline-block size-4 rounded-full bg-canvas transition-transform" :class="item.enabled ? 'translate-x-6' : 'translate-x-1'"></span>
                            </button>
                            <button
                                type="button"
                                x-show="item.origin === 'plugin'"
                                @click="uninstall(item)"
                                class="rounded-lg p-2 text-ink-faint transition-colors hover:bg-red-500/10 hover:text-red-400"
                                :title="@js(__('i18n::messages.plugins.uninstall'))"
                            >
                                <x-icon name="trash" class="size-4" />
                            </button>
                        </div>
                    </div>
                </template>

                <p x-show="combined.length === 0" class="rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">
                    {{ __('i18n::messages.common.empty') }}
                </p>
            </div>
        </div>

        {{-- ================= DOCS TAB ================= --}}
        <div x-show="tab === 'docs'" x-cloak class="mt-6 max-w-3xl space-y-8">
            <section>
                <h2 class="text-lg font-semibold text-ink">{{ __('i18n::messages.modules.docs.intro_title') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ __('i18n::messages.modules.docs.intro_body') }}</p>
            </section>

            {{-- Ahead of the hand-written walkthrough below, because the
                 generator does most of what that walkthrough describes. --}}
            <section class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-lg font-semibold text-ink">{{ __('i18n::messages.modules.docs.scaffold_title') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ __('i18n::messages.modules.docs.scaffold_body') }}</p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-surface-raised p-4 font-mono text-xs text-ink-muted">php artisan make:module Trophy</pre>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-ink">{{ __('i18n::messages.modules.docs.structure_title') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ __('i18n::messages.modules.docs.structure_body') }}</p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-surface-raised p-4 font-mono text-xs text-ink-muted">YourModule/
  YourModuleServiceProvider.php
  App/
    Http/Controllers/
    Models/
    Services/
  Database/
    Migrations/
  Routes/
    api.php
  plugin.json          <span class="text-ink-faint"># {{ __('i18n::messages.modules.docs.structure_manifest_note') }}</span></pre>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-ink">{{ __('i18n::messages.modules.docs.provider_title') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ __('i18n::messages.modules.docs.provider_body') }}</p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-surface-raised p-4 font-mono text-xs text-ink-muted">&lt;?php

namespace App\Modules\YourModule;

use App\Support\ModuleServiceProvider;

class YourModuleServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'your_module';
    }

    protected function registerModule(): void
    {
        // container bindings, if any
    }

    protected function bootModule(): void
    {
        // usually empty - routes and migrations are wired automatically
    }
}</pre>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-ink">{{ __('i18n::messages.modules.docs.routes_title') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ __('i18n::messages.modules.docs.routes_body') }}</p>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-ink">{{ __('i18n::messages.modules.docs.migrations_title') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ __('i18n::messages.modules.docs.migrations_body') }}</p>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-ink">{{ __('i18n::messages.modules.docs.manifest_title') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ __('i18n::messages.modules.docs.manifest_body') }}</p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-surface-raised p-4 font-mono text-xs text-ink-muted">{
  "key": "your_module",
  "name": "Your Module",
  "version": "1.0.0",
  "author": "Your Name",
  "description": "What this module does."
}</pre>
                <p class="mt-2 text-xs text-ink-faint">{{ __('i18n::messages.modules.docs.naming_body') }}</p>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-ink">{{ __('i18n::messages.modules.docs.packaging_title') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ __('i18n::messages.modules.docs.packaging_body') }}</p>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-ink">{{ __('i18n::messages.modules.docs.install_title') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ __('i18n::messages.modules.docs.install_body') }}</p>
            </section>

            <section class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-5">
                <h2 class="text-lg font-semibold text-amber-400">{{ __('i18n::messages.modules.docs.security_title') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-amber-400/90">{{ __('i18n::messages.modules.docs.security_body') }}</p>
            </section>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.modulesPage = () => ({
                loading: true,
                error: '',
                tab: 'modules',
                modules: [],
                plugins: [],
                pending: {},
                uploading: false,
                adminPlugin: 'cs2_admin',
                adminPluginSaving: false,

                dependsWarning: @js(__('i18n::messages.modules.depends_warning')),

                adminPluginOptions: [
                    { key: 'cs2_admin', label: 'CS2_Admin' },
                    { key: 'swiftly_admins', label: @js(__('i18n::messages.install.admin_plugin_swiftly')) },
                ],

                names: {
                    vip: @js(__('i18n::messages.modules.items.vip.name')),
                    skin: @js(__('i18n::messages.modules.items.skin.name')),
                    rank: @js(__('i18n::messages.modules.items.rank.name')),
                    report: @js(__('i18n::messages.modules.items.report.name')),
                    appeal: @js(__('i18n::messages.modules.items.appeal.name')),
                    rcon: @js(__('i18n::messages.modules.items.rcon.name')),
                    cheat_check: @js(__('i18n::messages.modules.items.cheat_check.name')),
                },
                descriptions: {
                    vip: @js(__('i18n::messages.modules.items.vip.description')),
                    skin: @js(__('i18n::messages.modules.items.skin.description')),
                    rank: @js(__('i18n::messages.modules.items.rank.description')),
                    report: @js(__('i18n::messages.modules.items.report.description')),
                    appeal: @js(__('i18n::messages.modules.items.appeal.description')),
                    rcon: @js(__('i18n::messages.modules.items.rcon.description')),
                    cheat_check: @js(__('i18n::messages.modules.items.cheat_check.description')),
                },

                // Built-in toggleable modules and installed plugins, merged
                // into one alphabetised list - see the tab comment above.
                get combined() {
                    const builtins = this.modules.map((m) => ({
                        key: 'module:' + m.key,
                        rawKey: m.key,
                        origin: 'builtin',
                        name: this.names[m.key] ?? m.key,
                        description: this.descriptions[m.key] ?? '',
                        version: null,
                        enabled: m.enabled,
                        dependents: m.dependents ?? [],
                    }));
                    const plugins = this.plugins.map((p) => ({
                        key: 'plugin:' + p.key,
                        rawKey: p.key,
                        origin: 'plugin',
                        name: p.name || p.key,
                        description: p.description || p.key,
                        version: p.version,
                        enabled: p.enabled,
                        dependents: [],
                    }));
                    return [...builtins, ...plugins].sort((a, b) => a.name.localeCompare(b.name));
                },

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                async init() {
                    await Promise.all([this.loadModules(), this.loadPlugins()]);
                    this.loading = false;
                },

                async loadModules() {
                    try {
                        const res = await fetch('/api/modules', { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.modules = body.data;
                        this.adminPlugin = body.meta?.admin_plugin ?? 'cs2_admin';
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

                // Turning a module off is not always a local decision -
                // RCON, for instance, is what the server health checks run
                // through. Say so before the switch flips rather than leaving
                // the owner to find it in a log line afterwards.
                dependentNames(item) {
                    return (item.dependents ?? []).map((k) => this.names[k] ?? k);
                },

                async toggle(item) {
                    const next = !item.enabled;

                    if (!next) {
                        const affected = this.dependentNames(item);
                        if (affected.length && !confirm(this.dependsWarning.replace(':list', affected.join(', ')))) {
                            return;
                        }
                    }

                    this.pending[item.key] = true;
                    this.error = '';
                    try {
                        const url = item.origin === 'plugin' ? `/api/plugins/${item.rawKey}` : `/api/modules/${item.rawKey}`;
                        const res = await fetch(url, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify({ enabled: next }),
                        });
                        if (!res.ok) throw new Error('request_failed');
                        item.enabled = next;
                        // item comes from a computed getter (combined), so the
                        // underlying source array needs the same write too.
                        const list = item.origin === 'plugin' ? this.plugins : this.modules;
                        const src = list.find((x) => x.key === item.rawKey);
                        if (src) src.enabled = next;
                    } catch (e) {
                        this.error = @js(__('i18n::messages.common.error'));
                    } finally {
                        delete this.pending[item.key];
                    }
                },

                async setAdminPlugin(plugin) {
                    if (plugin === this.adminPlugin) return;
                    if (!confirm(@js(__('i18n::messages.modules.admin_plugin_confirm')))) return;
                    this.adminPluginSaving = true;
                    this.error = '';
                    try {
                        const res = await fetch('/api/modules/admin-plugin', {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify({ plugin }),
                        });
                        if (!res.ok) throw new Error('request_failed');
                        this.adminPlugin = plugin;
                    } catch (e) {
                        this.error = @js(__('i18n::messages.common.error'));
                    } finally {
                        this.adminPluginSaving = false;
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

                async uninstall(item) {
                    if (!confirm(@js(__('i18n::messages.plugins.uninstall_confirm')))) return;
                    this.error = '';
                    try {
                        const res = await fetch(`/api/plugins/${item.rawKey}`, {
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
