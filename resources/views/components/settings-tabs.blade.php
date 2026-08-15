@props(['current' => 'general'])

{{--
    Sub-navigation shared by every owner-configuration page.

    Modules and Webhooks used to sit in the sidebar as separate entries.
    They are configuration, visited rarely, and belong with Settings - but
    each is a substantial page with its own Alpine component, so they keep
    their own routes and templates and are tied together by this strip
    rather than being pasted into one file.
--}}
@php
    $tabs = [
        ['general', __('i18n::messages.settings.tab_general'), route('settings.page')],
        ['modules', __('i18n::messages.nav.modules'), route('modules.page')],
        ['webhooks', __('i18n::messages.nav.webhooks'), route('webhooks.page')],
    ];
@endphp

<div class="mt-5 flex flex-wrap gap-1 border-b border-line">
    @foreach ($tabs as [$key, $label, $url])
        <a
            href="{{ $url }}"
            @class([
                '-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                'border-brand-strong text-brand-strong' => $current === $key,
                'border-transparent text-ink-muted hover:text-ink' => $current !== $key,
            ])
        >{{ $label }}</a>
    @endforeach
</div>
