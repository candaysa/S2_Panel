<x-layout.app :title="__('i18n::messages.nav.rcon')">
    <div
        x-data="{
            servers: [],
            serverId: '',
            command: '',
            log: [],
            running: false,
            error: '',

            kick: { target: '', reason: '' },
            ban: { target: '', duration: '0', reason: '' },
            slay: { target: '' },
            password: '',

            async loadServers() {
                try {
                    const res = await fetch('/api/servers?per_page=100', { headers: { Accept: 'application/json' } });
                    if (!res.ok) return;
                    const body = await res.json();
                    this.servers = body.data;
                    if (this.servers.length) this.serverId = this.servers[0].id;
                } catch (e) {}
            },

            csrf() {
                return document.querySelector('meta[name=csrf-token]').content;
            },

            append(entry) {
                this.log.push(entry);
                this.$nextTick(() => {
                    const el = this.$refs.log;
                    if (el) el.scrollTop = el.scrollHeight;
                });
            },

            async post(path, body) {
                const res = await fetch(`/api/rcon/${this.serverId}${path}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    body: JSON.stringify(body),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || 'request_failed');
                return data;
            },

            async runCommand() {
                if (!this.command.trim() || !this.serverId) return;
                this.running = true;
                this.error = '';
                const sent = this.command;
                try {
                    const body = await this.post('/command', { command: sent });
                    this.append({ command: sent, response: body.data.response ?? '' });
                    this.command = '';
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.running = false;
                }
            },

            async runKick() {
                if (!this.kick.target.trim() || !this.serverId) return;
                this.running = true;
                this.error = '';
                try {
                    const body = await this.post('/kick', this.kick);
                    this.append({ command: `kick ${this.kick.target}`, response: body.data.response ?? '' });
                    this.kick = { target: '', reason: '' };
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.running = false;
                }
            },

            async runBan() {
                if (!this.ban.target.trim() || !this.serverId) return;
                this.running = true;
                this.error = '';
                try {
                    const body = await this.post('/ban', this.ban);
                    this.append({ command: `ban ${this.ban.target}`, response: body.data.response ?? '' });
                    this.ban = { target: '', duration: '0', reason: '' };
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.running = false;
                }
            },

            async runSlay() {
                if (!this.slay.target.trim() || !this.serverId) return;
                this.running = true;
                this.error = '';
                try {
                    const body = await this.post('/slay', this.slay);
                    this.append({ command: `slay ${this.slay.target}`, response: body.data.response ?? '' });
                    this.slay = { target: '' };
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.running = false;
                }
            },

            async savePassword() {
                if (!this.password.trim() || !this.serverId) return;
                this.error = '';
                try {
                    const res = await fetch('/api/rcon/settings', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                        body: JSON.stringify({ server_id: Number(this.serverId), password: this.password }),
                    });
                    if (!res.ok) throw new Error('request_failed');
                    this.password = '';
                } catch (e) {
                    this.error = @js(__('i18n::messages.common.error'));
                }
            },

            init() { this.loadServers(); },
        }"
        x-init="init()"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-ink">{{ __('i18n::messages.nav.rcon') }}</h1>
            <select x-model="serverId" class="rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-brand-strong focus:outline-none">
                <option value="" disabled>{{ __('i18n::messages.rcon.select_server') }}</option>
                <template x-for="server in servers" :key="server.id">
                    <option :value="server.id" x-text="server.server_ip + ':' + server.server_port"></option>
                </template>
            </select>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            {{-- Console --}}
            <div class="rounded-xl border border-line bg-surface p-5 lg:col-span-2">
                <h2 class="text-sm font-semibold text-ink">{{ __('i18n::messages.rcon.console') }}</h2>

                <div x-ref="log" class="mt-3 h-64 space-y-2 overflow-y-auto rounded-lg bg-canvas p-3 font-mono text-xs">
                    <template x-for="(entry, i) in log" :key="i">
                        <div>
                            <p class="text-brand-strong">&gt; <span x-text="entry.command"></span></p>
                            <p class="whitespace-pre-wrap text-ink-muted" x-text="entry.response"></p>
                        </div>
                    </template>
                    <p x-show="log.length === 0" class="text-ink-faint">—</p>
                </div>

                <div class="mt-3 flex gap-2">
                    <input
                        type="text"
                        x-model="command"
                        @keydown.enter="runCommand()"
                        placeholder="{{ __('i18n::messages.rcon.command_placeholder') }}"
                        class="flex-1 rounded-lg border border-line bg-canvas px-3 py-2 font-mono text-sm text-ink focus:border-brand-strong focus:outline-none"
                    >
                    <button type="button" :disabled="running" @click="runCommand()" class="inline-flex items-center rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90 disabled:opacity-50">
                        {{ __('i18n::messages.rcon.send') }}
                    </button>
                </div>

                <p x-show="error" x-cloak class="mt-3 text-sm text-red-400" x-text="error"></p>
            </div>

            {{-- Quick actions --}}
            <div class="space-y-4">
                <div class="rounded-xl border border-line bg-surface p-4">
                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.rcon.kick') }}</p>
                    <input type="text" x-model="kick.target" placeholder="{{ __('i18n::messages.rcon.target') }}" class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-1.5 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <input type="text" x-model="kick.reason" placeholder="{{ __('i18n::messages.rcon.reason') }}" class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-1.5 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <button type="button" :disabled="running" @click="runKick()" class="mt-2 w-full rounded-lg border border-line py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                        {{ __('i18n::messages.rcon.run') }}
                    </button>
                </div>

                <div class="rounded-xl border border-line bg-surface p-4">
                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.rcon.ban') }}</p>
                    <input type="text" x-model="ban.target" placeholder="{{ __('i18n::messages.rcon.target') }}" class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-1.5 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <input type="number" x-model="ban.duration" placeholder="{{ __('i18n::messages.rcon.duration_minutes') }}" class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-1.5 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <input type="text" x-model="ban.reason" placeholder="{{ __('i18n::messages.rcon.reason') }}" class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-1.5 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <button type="button" :disabled="running" @click="runBan()" class="mt-2 w-full rounded-lg border border-line py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                        {{ __('i18n::messages.rcon.run') }}
                    </button>
                </div>

                <div class="rounded-xl border border-line bg-surface p-4">
                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.rcon.slay') }}</p>
                    <input type="text" x-model="slay.target" placeholder="{{ __('i18n::messages.rcon.target') }}" class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-1.5 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <button type="button" :disabled="running" @click="runSlay()" class="mt-2 w-full rounded-lg border border-line py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                        {{ __('i18n::messages.rcon.run') }}
                    </button>
                </div>

                <div class="rounded-xl border border-line bg-surface p-4">
                    <p class="text-xs font-semibold uppercase tracking-wider text-ink-faint">{{ __('i18n::messages.rcon.password_settings') }}</p>
                    <input type="password" x-model="password" placeholder="{{ __('i18n::messages.rcon.password_placeholder') }}" class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-1.5 text-sm text-ink focus:border-brand-strong focus:outline-none">
                    <button type="button" @click="savePassword()" class="mt-2 w-full rounded-lg border border-line py-1.5 text-sm text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink">
                        {{ __('i18n::messages.rcon.save_password') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-layout.app>
