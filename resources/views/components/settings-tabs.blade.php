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
    // [key, label, url, owner-only]. Logs is the one tab a non-owner can
    // reach: it is admin.root-gated rather than owner.only, because the
    // people who need to review what admins have been doing are exactly the
    // root admins, not just whoever installed the panel. Every other tab
    // here would 403 for them, so they are not offered.
    $tabs = [
        ['general', __('i18n::messages.settings.tab_general'), route('settings.page'), true],
        ['design', __('i18n::messages.settings.tab_design'), route('settings.design.page'), true],
        ['tickets', __('i18n::messages.settings.tab_tickets'), route('settings.tickets.page'), true],
        ['servers', __('i18n::messages.settings.tab_servers'), route('settings.servers.page'), true],
        ['logs', __('i18n::messages.nav.audit'), route('audit.page'), false],
        ['modules', __('i18n::messages.nav.modules'), route('modules.page'), true],
        ['webhooks', __('i18n::messages.nav.webhooks'), route('webhooks.page'), true],
    ];

    $isOwner = \App\Support\Access::isOwner();
    $auditOn = app(\App\Support\ModuleRegistry::class)->isEnabled('audit');

    $tabs = array_values(array_filter(
        $tabs,
        fn (array $tab): bool => ($isOwner || ! $tab[3]) && ($tab[0] !== 'logs' || $auditOn),
    ));
@endphp

<div class="mt-5 flex flex-wrap gap-1 border-b border-line">
    @foreach ($tabs as [$key, $label, $url, $ownerOnly])
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
