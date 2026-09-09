{{--
    Shown by RequireRconVerified when any currently online server has no
    verified RCON password - see that middleware and RconVerificationService
    for why this blocks Bans/RCON/Admins/Groups entirely rather than just
    the server in question.
--}}
<x-layout.app :title="__('i18n::messages.errors.rcon_not_verified_title')">
    <div class="mx-auto max-w-md py-16 text-center">
        <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-amber-500/10">
            <x-icon name="shield" class="size-7 text-amber-400" />
        </span>

        <h1 class="mt-5 text-xl font-semibold text-ink">{{ __('i18n::messages.errors.rcon_not_verified_title') }}</h1>

        @if (\App\Support\Access::isOwner())
            <p class="mt-2 text-sm text-ink-muted">{{ __('i18n::messages.errors.rcon_not_verified_body') }}</p>

            <ul class="mt-4 space-y-1.5 text-left">
                @foreach ($problems as $problem)
                    <li class="rounded-lg border border-line bg-surface px-3 py-2 text-sm">
                        <span class="font-mono text-ink">{{ $problem['label'] }}</span>
                        <span class="text-ink-faint">— {{ $problem['reason'] }}</span>
                    </li>
                @endforeach
            </ul>

            <a
                href="{{ route('settings.servers.page') }}"
                class="mt-6 inline-flex items-center gap-2 rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90"
            >
                <x-icon name="server" class="size-4" />
                {{ __('i18n::messages.errors.rcon_not_verified_fix') }}
            </a>
        @else
            <p class="mt-2 text-sm text-ink-muted">{{ __('i18n::messages.errors.rcon_not_verified_body_generic') }}</p>
        @endif
    </div>
</x-layout.app>
