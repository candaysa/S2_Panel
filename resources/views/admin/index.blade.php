<x-layout.app :title="__('i18n::messages.nav.admin')">
    <div x-data="adminsPage()" x-init="init()">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.admin') }}</h1>
            <div class="flex items-center gap-2">
                <input
                    type="search"
                    x-model="search"
                    @input.debounce.350ms="load()"
                    placeholder="{{ __('i18n::messages.common.search') }}"
                    class="w-full max-w-xs rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-brand-strong focus:outline-none sm:w-56"
                >
                <button type="button" @click="openCreate()" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-strong px-3 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90">
                    <x-icon name="plus" class="size-4" />
                    {{ __('i18n::messages.admins.add') }}
                </button>
            </div>
        </div>

        @if (($adminPlugin ?? 'cs2_admin') === 'swiftly_admins')
            <div class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-sm text-amber-400">
                {{ __('i18n::messages.admins.swiftly_admins_notice') }}
            </div>
        @endif

        <div class="mt-6 overflow-x-auto rounded-xl border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line text-xs font-semibold uppercase tracking-wider text-ink-faint">
                    <tr>
                        @foreach ([
                            ['name', __('i18n::messages.nav.admin'), false],
                            ['flags', __('i18n::messages.admins.flags'), false],
                            ['groups', __('i18n::messages.nav.groups'), true],
                            ['immunity', __('i18n::messages.admins.immunity'), false],
                            ['playtime', __('i18n::messages.admins.playtime'), false],
                            ['expires_at', __('i18n::messages.admins.expires'), true],
                            [null, __('i18n::messages.admins.status'), false],
                            [null, '', false],
                        ] as [$key, $label, $hideBelowLg])
                            <th class="px-4 py-3 {{ in_array($key, ['immunity', 'playtime']) ? 'text-center' : '' }} {{ $hideBelowLg ? 'hidden lg:table-cell' : '' }}">
                                @if ($key)
                                    <button type="button" @click="sortBy('{{ $key }}')" class="inline-flex items-center gap-1 transition-colors hover:text-ink">
                                        {{ $label }}
                                        <x-icon name="chevron-left" class="size-3 -rotate-90 transition-opacity" ::class="sort === '{{ $key }}' ? 'opacity-100' : 'opacity-30'" ::style="sort === '{{ $key }}' && dir === 'asc' && 'transform:rotate(90deg)'" />
                                    </button>
                                @else
                                    {{ $label }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    <template x-for="admin in admins" :key="admin.id">
                        <tr class="group text-ink-muted transition-colors hover:bg-surface-raised">
                            <td class="cursor-pointer px-4 py-3" @click="openEdit(admin)">
                                <div class="flex items-center gap-2.5">
                                    <img x-show="admin.avatar" :src="admin.avatar" alt="" loading="lazy" class="size-9 shrink-0 rounded-full object-cover ring-1 ring-line">
                                    <span x-show="!admin.avatar" class="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-raised text-sm font-semibold text-ink-faint" x-text="(admin.name ?? '?').charAt(0).toUpperCase()"></span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-ink transition-colors group-hover:text-brand-strong" x-text="admin.name"></span>
                                        <span class="block truncate font-mono text-xs text-ink-faint" x-text="admin.steamid"></span>
                                    </span>
                                </div>
                            </td>

                            {{-- Shows the admin's EFFECTIVE flags (own +
                                 whatever their group(s) grant), not just the
                                 admin row's own column - most admins here
                                 carry permissions purely via a group, which
                                 otherwise made this column look empty despite
                                 real access. A flag earned only through a
                                 group renders as an outline instead of solid
                                 fill, and the edit form below still only ever
                                 edits the admin's own flags. --}}
                            <td class="px-4 py-3">
                                <div class="flex max-w-xs flex-wrap gap-1">
                                    <template x-for="flag in chips(admin.effective_flags)" :key="flag">
                                        <span
                                            class="rounded px-1.5 py-0.5 font-mono text-[11px] font-medium ring-1 ring-inset"
                                            :class="chips(admin.flags).includes(flag) ? flagColorClass(flag) : 'bg-transparent text-ink-faint ring-line'"
                                            :title="chips(admin.flags).includes(flag) ? '' : @js(__('i18n::messages.admins.flag_from_group'))"
                                            x-text="flag"
                                        ></span>
                                    </template>
                                    <span x-show="chips(admin.effective_flags).length === 0" class="text-ink-faint">—</span>
                                </div>
                            </td>

                            <td class="hidden px-4 py-3 lg:table-cell">
                                <div class="flex max-w-xs flex-wrap gap-1">
                                    <template x-for="group in chips(admin.groups)" :key="group">
                                        <span class="rounded bg-brand-soft px-1.5 py-0.5 text-[11px] font-medium text-brand-strong" x-text="group"></span>
                                    </template>
                                    <span x-show="chips(admin.groups).length === 0" class="text-ink-faint">—</span>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <div class="mx-auto w-14">
                                    <p class="text-center text-sm font-medium tabular-nums text-ink" x-text="admin.immunity ?? 0"></p>
                                    <div class="mt-1 h-1 overflow-hidden rounded-full bg-surface-raised">
                                        <div class="h-full rounded-full bg-brand-strong" :style="'width:' + Math.min(100, admin.immunity ?? 0) + '%'"></div>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3 text-center tabular-nums" x-text="playtime(admin.playtime_minutes)"></td>

                            <td class="hidden px-4 py-3 text-sm lg:table-cell" x-text="expiry(admin)"></td>

                            <td class="px-4 py-3">
                                <span
                                    :class="isActive(admin) ? 'bg-emerald-500/10 text-emerald-400' : 'bg-surface-raised text-ink-faint'"
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                >
                                    <span class="size-1.5 rounded-full" :class="isActive(admin) ? 'bg-emerald-400' : 'bg-ink-faint/50'"></span>
                                    <span x-text="isActive(admin) ? @js(__('i18n::messages.admins.active')) : @js(__('i18n::messages.admins.disabled'))"></span>
                                </span>
                            </td>

                            <td class="px-4 py-3 text-right">
                                <button type="button" @click="openEdit(admin)" class="rounded-lg p-1.5 text-ink-faint opacity-0 transition-opacity hover:bg-surface-raised hover:text-ink group-hover:opacity-100">
                                    <x-icon name="cog" class="size-4" />
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <p x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.loading') }}
            </p>
            <p x-show="!loading && !forbidden && !error && admins.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.empty') }}
            </p>
            <p x-show="forbidden" x-cloak class="px-4 py-8 text-center text-sm text-ink-faint">
                {{ __('i18n::messages.common.forbidden') }}
            </p>
            <p x-show="error" x-cloak class="px-4 py-8 text-center text-sm text-red-400">
                {{ __('i18n::messages.common.error') }}
            </p>
        </div>

        {{-- Create / edit modal --}}
        <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/70" @click="closeModal()"></div>

            <div x-show="modalOpen" x-transition class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl">
                <div class="flex items-center justify-between border-b border-line p-5">
                    <h2 class="text-base font-semibold text-ink" x-text="editing ? @js(__('i18n::messages.admins.edit')) : @js(__('i18n::messages.admins.add'))"></h2>
                    <button type="button" @click="closeModal()" class="rounded-lg p-1.5 text-ink-faint transition-colors hover:bg-surface-raised hover:text-ink">
                        <x-icon name="close" class="size-4" />
                    </button>
                </div>

                <div class="max-h-[70vh] space-y-4 overflow-y-auto p-5">
                    <p x-show="modalError" x-cloak class="rounded-lg bg-red-500/10 px-3 py-2 text-sm text-red-400" x-text="modalError"></p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-medium text-ink-muted">{{ __('i18n::messages.admins.steamid') }}</label>
                            <input type="text" x-model="form.steamid" placeholder="STEAM_0:1:... / 7656119..." class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-ink-muted">{{ __('i18n::messages.admins.player_name') }}</label>
                            <input type="text" x-model="form.name" class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-ink-muted">{{ __('i18n::messages.admins.immunity') }}</label>
                        <input type="number" min="0" max="100" x-model.number="form.immunity" class="mt-1 w-28 rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    </div>

                    <div>
                        <label class="flex items-center gap-2.5 text-sm text-ink-muted">
                            <span class="relative inline-flex h-6 w-11 shrink-0 items-center">
                                <input type="checkbox" x-model="form.permanent" class="peer sr-only">
                                <span class="pointer-events-none absolute inset-0 rounded-full bg-surface-raised transition-colors peer-checked:bg-brand-strong"></span>
                                <span class="pointer-events-none absolute left-1 inline-block size-4 rounded-full bg-canvas transition-transform peer-checked:translate-x-5"></span>
                            </span>
                            {{ __('i18n::messages.admins.permanent') }}
                        </label>
                        <div x-show="!form.permanent" x-cloak class="mt-2">
                            <label class="block text-xs font-medium text-ink-muted">{{ __('i18n::messages.admins.ends_on') }}</label>
                            <input type="date" x-model="form.expiresDate" class="mt-1 rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                        </div>
                    </div>

                    <div x-data="{ groupsOpen: false }" class="relative">
                        <label class="block text-xs font-medium text-ink-muted">{{ __('i18n::messages.nav.groups') }}</label>
                        <button
                            type="button"
                            @click="groupsOpen = !groupsOpen"
                            @click.outside="groupsOpen = false"
                            class="mt-1 flex w-full items-center justify-between gap-2 rounded-lg border border-line bg-canvas px-3 py-2 text-left text-sm focus:border-brand-strong focus:outline-none"
                        >
                            <span class="truncate" :class="form.groups.length ? 'text-ink' : 'text-ink-faint'" x-text="form.groups.length ? form.groups.join(', ') : @js(__('i18n::messages.admins.select_groups'))"></span>
                            <x-icon name="chevron-left" class="size-3.5 shrink-0 -rotate-90 text-ink-faint transition-transform" ::class="groupsOpen && 'rotate-90'" />
                        </button>
                        <div x-show="groupsOpen" x-cloak x-transition class="absolute z-10 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-line bg-surface p-1.5 shadow-lg">
                            <template x-for="g in availableGroups" :key="g.name">
                                <label class="flex cursor-pointer items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised">
                                    <span x-text="g.name"></span>
                                    <span class="relative inline-flex h-6 w-11 shrink-0 items-center">
                                        <input type="checkbox" :checked="form.groups.includes(g.name)" @change="toggle(form.groups, g.name)" class="peer sr-only">
                                        <span class="pointer-events-none absolute inset-0 rounded-full bg-surface-raised transition-colors peer-checked:bg-brand-strong"></span>
                                        <span class="pointer-events-none absolute left-1 inline-block size-4 rounded-full bg-canvas transition-transform peer-checked:translate-x-5"></span>
                                    </span>
                                </label>
                            </template>
                            <p x-show="availableGroups.length === 0" class="px-2.5 py-1.5 text-xs text-ink-faint">—</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-ink-muted">{{ __('i18n::messages.admins.flags') }}</label>
                        <div class="mt-1.5 space-y-1.5">
                            <template x-for="flag in allFlags" :key="flag">
                                <label class="flex cursor-pointer items-center justify-between gap-2.5 rounded-lg p-1.5 transition-colors hover:bg-surface-raised">
                                    <span class="min-w-0">
                                        <span class="rounded px-1.5 py-0.5 font-mono text-[11px] font-medium ring-1 ring-inset" :class="flagColorClass(flag)" x-text="flag"></span>
                                        <span class="ml-1.5 text-xs text-ink-muted" x-text="flagLabels[flag] ?? ''"></span>
                                    </span>
                                    <span class="relative inline-flex h-6 w-11 shrink-0 items-center">
                                        <input type="checkbox" :checked="form.flags.includes(flag)" @change="toggle(form.flags, flag)" class="peer sr-only">
                                        <span class="pointer-events-none absolute inset-0 rounded-full bg-surface-raised transition-colors peer-checked:bg-brand-strong"></span>
                                        <span class="pointer-events-none absolute left-1 inline-block size-4 rounded-full bg-canvas transition-transform peer-checked:translate-x-5"></span>
                                    </span>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-line bg-surface-raised/40 p-4">
                    <button type="button" x-show="editing" @click="removeAdmin()" class="rounded-lg px-3 py-2 text-sm text-red-400 transition-colors hover:bg-red-500/10">
                        {{ __('i18n::messages.common.delete') }}
                    </button>
                    <div class="ml-auto flex items-center gap-2">
                        <button type="button" @click="closeModal()" class="rounded-lg px-3 py-2 text-sm text-ink-muted transition-colors hover:text-ink">
                            {{ __('i18n::messages.common.cancel') }}
                        </button>
                        <button type="button" :disabled="saving" @click="save()" class="inline-flex items-center gap-2 rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-60">
                            <span x-text="@js(__('i18n::messages.admins.save'))"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
            window.adminsPage = () => ({
                loading: true,
                forbidden: false,
                error: false,
                search: '',
                sort: 'id',
                dir: 'desc',
                admins: [],
                availableGroups: [],
                allFlags: [],
                flagLabels: @js(__('i18n::messages.flag_descriptions')),
                modalOpen: false,
                modalError: '',
                editing: null,
                saving: false,
                form: { steamid: '', name: '', immunity: 0, permanent: true, expiresDate: '', groups: [], flags: [] },

                async init() {
                    await this.load();
                },

                async load() {
                    this.loading = true;
                    this.error = false;
                    this.forbidden = false;
                    try {
                        const url = new URL('/api/admin', window.location.origin);
                        if (this.search) url.searchParams.set('search', this.search);
                        url.searchParams.set('sort', this.sort);
                        url.searchParams.set('dir', this.dir);
                        url.searchParams.set('per_page', '100');
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (res.status === 403) { this.forbidden = true; return; }
                        if (!res.ok) throw new Error('request_failed');
                        const body = await res.json();
                        this.admins = body.data;
                        this.allFlags = body.meta?.flags ?? this.allFlags;

                        const g = await fetch('/api/admin/groups', { headers: { Accept: 'application/json' } });
                        if (g.ok) {
                            const gb = await g.json();
                            this.availableGroups = gb.data;
                            this.allFlags = gb.meta?.flags ?? this.allFlags;
                        }
                    } catch (e) {
                        this.error = true;
                    } finally {
                        this.loading = false;
                    }
                },

                sortBy(key) {
                    if (this.sort === key) {
                        this.dir = this.dir === 'asc' ? 'desc' : 'asc';
                    } else {
                        this.sort = key;
                        this.dir = key === 'name' ? 'asc' : 'desc';
                    }
                    this.load();
                },

                isActive(admin) {
                    return !admin.expires_at || new Date(admin.expires_at) > new Date();
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

                expiry(admin) {
                    if (!admin.expires_at) return @js(__('i18n::messages.bans.never'));
                    return new Date(admin.expires_at).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
                },

                playtime(minutes) {
                    if (minutes === null || minutes === undefined) return '—';
                    const h = Math.floor(minutes / 60);
                    return h >= 1 ? h.toLocaleString() + 'h' : minutes + 'm';
                },

                openCreate() {
                    this.editing = null;
                    this.modalError = '';
                    this.form = { steamid: '', name: '', immunity: 0, permanent: true, expiresDate: '', groups: [], flags: [] };
                    this.modalOpen = true;
                },

                openEdit(admin) {
                    this.editing = admin;
                    this.modalError = '';
                    this.form = {
                        steamid: admin.steamid,
                        name: admin.name,
                        immunity: admin.immunity ?? 0,
                        permanent: !admin.expires_at,
                        expiresDate: admin.expires_at ? admin.expires_at.slice(0, 10) : '',
                        groups: this.chips(admin.groups),
                        flags: this.chips(admin.flags),
                    };
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
                        const payload = {
                            steamid: this.form.steamid.trim(),
                            name: this.form.name.trim(),
                            immunity: this.form.immunity,
                            groups: this.form.groups,
                            flags: this.form.flags,
                            expires_at: this.form.permanent ? null : (this.form.expiresDate || null),
                        };
                        const url = this.editing ? `/api/admin/${this.editing.id}` : '/api/admin';
                        const method = this.editing ? 'PUT' : 'POST';
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
                        await this.load();
                    } catch (e) {
                        this.modalError = @js(__('i18n::messages.common.error'));
                    } finally {
                        this.saving = false;
                    }
                },

                async removeAdmin() {
                    if (!this.editing) return;
                    if (!confirm(@js(__('i18n::messages.admins.delete_admin_confirm')))) return;
                    this.saving = true;
                    try {
                        const res = await fetch(`/api/admin/${this.editing.id}`, {
                            method: 'DELETE',
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        });
                        if (!res.ok) throw new Error('request_failed');
                        this.modalOpen = false;
                        await this.load();
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
