<x-layout.app :title="__('i18n::messages.nav.groups')">
    <div x-data="groupsPage()" x-init="init()">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.groups') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ __('i18n::messages.admins.groups_subtitle') }}</p>
            </div>
            <button type="button" @click="openCreate()" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-strong px-3 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90">
                <x-icon name="plus" class="size-4" />
                {{ __('i18n::messages.admins.create_group') }}
            </button>
        </div>

        <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <template x-for="group in groups" :key="group.name">
                <button type="button" @click="openEdit(group)" class="rounded-xl border border-line bg-surface p-5 text-left transition-colors hover:bg-surface-raised">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-soft">
                                <x-icon name="group" class="size-4.5 text-brand-strong" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-medium text-ink" x-text="group.name"></p>
                                <p class="text-xs text-ink-faint"><span x-text="group.member_count ?? 0"></span> {{ __('i18n::messages.admins.members') }}</p>
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-[11px] uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.admins.immunity') }}</p>
                            <p class="text-lg font-semibold tabular-nums text-ink" x-text="group.immunity ?? 0"></p>
                        </div>
                    </div>

                    <div class="mt-3 h-1 overflow-hidden rounded-full bg-surface-raised">
                        <div class="h-full rounded-full bg-brand-strong" :style="'width:' + Math.min(100, group.immunity ?? 0) + '%'"></div>
                    </div>

                    <div class="mt-4 border-t border-line-soft pt-3">
                        <p class="text-[11px] uppercase tracking-wider text-ink-faint">
                            {{ __('i18n::messages.admins.flags') }}
                            <span class="ml-1 opacity-70" x-text="'(' + chips(group.flags).length + ')'"></span>
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <template x-for="flag in chips(group.flags)" :key="flag">
                                <span class="rounded px-1.5 py-0.5 font-mono text-[11px] font-medium ring-1 ring-inset" :class="flagColorClass(flag)" x-text="flag"></span>
                            </template>
                            <span x-show="chips(group.flags).length === 0" class="text-sm text-ink-faint">—</span>
                        </div>
                    </div>
                </button>
            </template>
        </div>

        <p x-show="loading" x-cloak class="mt-6 rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">
            {{ __('i18n::messages.common.loading') }}
        </p>
        <p x-show="!loading && !error && groups.length === 0" x-cloak class="mt-6 rounded-xl border border-line bg-surface px-4 py-8 text-center text-sm text-ink-faint">
            {{ __('i18n::messages.common.empty') }}
        </p>
        <p x-show="error" x-cloak class="mt-6 text-center text-sm text-red-400">
            {{ __('i18n::messages.common.error') }}
        </p>

        {{-- Create / edit modal --}}
        <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/70" @click="closeModal()"></div>

            <div x-show="modalOpen" x-transition class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl">
                <div class="flex items-center justify-between border-b border-line p-5">
                    <h2 class="text-base font-semibold text-ink" x-text="editing ? @js(__('i18n::messages.admins.edit_group')) : @js(__('i18n::messages.admins.create_group'))"></h2>
                    <button type="button" @click="closeModal()" class="rounded-lg p-1.5 text-ink-faint transition-colors hover:bg-surface-raised hover:text-ink">
                        <x-icon name="close" class="size-4" />
                    </button>
                </div>

                <div class="max-h-[70vh] space-y-4 overflow-y-auto p-5">
                    <p x-show="modalError" x-cloak class="rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400" x-text="modalError"></p>

                    <div>
                        <label class="block text-xs font-medium text-ink-muted">{{ __('i18n::messages.admins.group_name') }}</label>
                        <input type="text" x-model="form.name" :disabled="!!editing" placeholder="vip, moderator, senior-admin..." class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none disabled:opacity-60">
                        {{-- The name is Swiftly's own key for the group (admin_admins.groups
                             stores names, not ids), so renaming here would silently detach
                             every admin currently referencing it. --}}
                        <p x-show="editing" class="mt-1 text-xs text-ink-faint">{{ __('i18n::messages.admins.group_name_locked') }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-ink-muted">{{ __('i18n::messages.admins.immunity') }}</label>
                        <input type="number" min="0" max="100" x-model.number="form.immunity" class="mt-1 w-28 rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-ink-muted">{{ __('i18n::messages.admins.flags') }}</label>
                        <div class="mt-1.5 space-y-1.5">
                            <template x-for="flag in allFlags" :key="flag">
                                <label class="flex cursor-pointer items-start gap-2.5 rounded-lg p-1.5 transition-colors hover:bg-surface-raised">
                                    <input type="checkbox" :checked="form.flags.includes(flag)" @change="toggle(form.flags, flag)" class="mt-0.5 size-4 rounded border-line text-brand-strong focus:ring-brand-strong">
                                    <span class="min-w-0">
                                        <span class="rounded px-1.5 py-0.5 font-mono text-[11px] font-medium ring-1 ring-inset" :class="flagColorClass(flag)" x-text="flag"></span>
                                        <span class="ml-1.5 text-xs text-ink-muted" x-text="flagLabels[flag] ?? ''"></span>
                                    </span>
                                </label>
                            </template>
                            <p x-show="form.flags.length === 0" class="text-xs text-ink-faint">{{ __('i18n::messages.admins.no_flags') }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-line bg-surface-raised/40 p-4">
                    <button type="button" x-show="editing" @click="removeGroup()" class="rounded-lg px-3 py-2 text-sm text-red-400 transition-colors hover:bg-red-500/10">
                        {{ __('i18n::messages.admins.delete_group') }}
                    </button>
                    <div class="ml-auto flex items-center gap-2">
                        <button type="button" @click="closeModal()" class="rounded-lg px-3 py-2 text-sm text-ink-muted transition-colors hover:text-ink">
                            {{ __('i18n::messages.common.cancel') }}
                        </button>
                        <button type="button" :disabled="saving" @click="save()" class="inline-flex items-center gap-2 rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-60">
                            <span x-text="editing ? @js(__('i18n::messages.admins.save')) : @js(__('i18n::messages.admins.create'))"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.groupsPage = () => ({
                loading: true,
                error: false,
                groups: [],
                allFlags: [],
                flagLabels: @js(__('i18n::messages.flag_descriptions')),
                modalOpen: false,
                modalError: '',
                editing: null,
                saving: false,
                form: { name: '', immunity: 0, flags: [] },

                async init() {
                    try {
                        const res = await fetch('/api/admin/groups', { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.groups = body.data;
                        this.allFlags = body.meta?.flags ?? [];
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                async reload() {
                    const res = await fetch('/api/admin/groups', { headers: { Accept: 'application/json' } });
                    if (res.ok) {
                        const body = await res.json();
                        this.groups = body.data;
                    }
                },

                chips(value) {
                    return (value ?? '').split(',').map(v => v.trim()).filter(Boolean);
                },

                flagColorClass(flag) {
                    return window.flagColorClass(flag);
                },

                toggle(arr, value) {
                    const i = arr.indexOf(value);
                    if (i === -1) arr.push(value); else arr.splice(i, 1);
                },

                openCreate() {
                    this.editing = null;
                    this.modalError = '';
                    this.form = { name: '', immunity: 0, flags: [] };
                    this.modalOpen = true;
                },

                openEdit(group) {
                    this.editing = group;
                    this.modalError = '';
                    this.form = { name: group.name, immunity: group.immunity ?? 0, flags: this.chips(group.flags) };
                    this.modalOpen = true;
                },

                closeModal() {
                    this.modalOpen = false;
                },

                csrf() {
                    return document.querySelector('meta[name=csrf-token]').content;
                },

                async save() {
                    this.saving = true;
                    this.modalError = '';
                    try {
                        const payload = { immunity: this.form.immunity, flags: this.form.flags };
                        let url = '/api/admin/groups';
                        let method = 'POST';
                        if (this.editing) {
                            url = `/api/admin/groups/${encodeURIComponent(this.editing.name)}`;
                            method = 'PUT';
                        } else {
                            payload.name = this.form.name.trim();
                        }
                        const res = await fetch(url, {
                            method,
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                            body: JSON.stringify(payload),
                        });
                        const body = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            this.modalError = window.apiError(res, body, @js(__('i18n::messages.common.error')));
                            return;
                        }
                        this.modalOpen = false;
                        await this.reload();
                    } catch (e) {
                        this.modalError = @js(__('i18n::messages.common.error'));
                    } finally {
                        this.saving = false;
                    }
                },

                async removeGroup() {
                    if (!this.editing) return;
                    if (!confirm(@js(__('i18n::messages.admins.delete_group_confirm')))) return;
                    this.saving = true;
                    try {
                        const res = await fetch(`/api/admin/groups/${encodeURIComponent(this.editing.name)}`, {
                            method: 'DELETE',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        if (!res.ok) throw new Error('request_failed');
                        this.modalOpen = false;
                        await this.reload();
                    } catch (e) {
                        this.modalError = @js(__('i18n::messages.common.error'));
                    } finally {
                        this.saving = false;
                    }
                },
            });
        </script>
    @endpush
</x-layout.app>
