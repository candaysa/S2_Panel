@props(['class' => ''])

<button
    type="button"
    x-data="{
        theme: document.documentElement.getAttribute('data-theme') || 'dark',
        toggle() {
            this.theme = this.theme === 'dark' ? 'light' : 'dark';
            localStorage.setItem('theme', this.theme);
            document.documentElement.setAttribute('data-theme', this.theme);
        },
    }"
    @click="toggle()"
    :aria-label="theme === 'dark' ? '{{ __('i18n::messages.theme.switch_to_light') }}' : '{{ __('i18n::messages.theme.switch_to_dark') }}'"
    {{ $attributes->merge(['class' => 'flex size-9 shrink-0 items-center justify-center rounded-lg text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink '.$class]) }}
>
    <x-icon name="sun" class="size-5" x-show="theme === 'dark'" x-cloak />
    <x-icon name="moon" class="size-5" x-show="theme === 'light'" x-cloak />
</button>
