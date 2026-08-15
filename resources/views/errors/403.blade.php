{{--
    Shown when a signed-in user opens a page their flags do not cover.

    Uses the panel layout rather than Laravel's bare error page: the person
    seeing this is logged in and mid-session, so dropping them onto an
    unstyled wall reads like the panel broke. The sidebar stays, and it only
    lists what they can actually reach - so this page also shows them where
    they can go instead.
--}}
<x-layout.app :title="__('i18n::messages.errors.forbidden_title')">
    <div class="mx-auto max-w-md py-16 text-center">
        <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-amber-500/10">
            <x-icon name="shield" class="size-7 text-amber-400" />
        </span>

        <h1 class="mt-5 text-xl font-semibold text-ink">{{ __('i18n::messages.errors.forbidden_title') }}</h1>
        <p class="mt-2 text-sm text-ink-muted">{{ __('i18n::messages.errors.forbidden_body') }}</p>

        <a
            href="{{ route('dashboard') }}"
            class="mt-6 inline-flex items-center gap-2 rounded-lg bg-brand-strong px-4 py-2 text-sm font-medium text-canvas transition-opacity hover:opacity-90"
        >
            <x-icon name="home" class="size-4" />
            {{ __('i18n::messages.errors.back_to_dashboard') }}
        </a>
    </div>
</x-layout.app>
