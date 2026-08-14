@php
    // Both read through the same SettingService the rest of the panel uses,
    // so an owner-picked accent color and light/dark preference apply
    // consistently everywhere this partial is included.
    $brandColor = app(\App\Modules\Settings\App\Services\SettingService::class)->get('brand_color');
@endphp
{{-- Owner-wide accent color. --color-brand-strong/-soft in app.css derive
     from --color-brand via color-mix(), so overriding just this one custom
     property re-tints every accent surface consistently. Must be placed
     after the @vite() stylesheet link so this :root rule wins the cascade
     (equal specificity, later source order). --}}
<style>:root{--color-brand:{{ $brandColor }}}</style>

{{-- Per-browser light/dark preference (not an owner-wide setting - see
     ModuleController-style reasoning in the Modules tab: this one is a
     personal viewer preference, so it lives in localStorage, not the DB).
     Runs synchronously, before first paint, to avoid a flash of the wrong
     theme; the toggle button (in the sidebar / login page) writes the same
     key.

     The nonce is required: script-src has no 'unsafe-inline', so without it
     CSP drops this block and the saved preference is never re-applied - the
     toggle appears to work, then "forgets" on every reload. See
     App\Http\Middleware\SecurityHeaders, which mints $cspNonce per request. --}}
<script @isset($cspNonce) nonce="{{ $cspNonce }}" @endisset>
    (function () {
        var theme = localStorage.getItem('theme');
        if (theme === 'light' || theme === 'dark') {
            document.documentElement.setAttribute('data-theme', theme);
        } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
            // No stored choice: follow the OS instead of forcing dark on
            // someone whose system is light. Not persisted - only an explicit
            // toggle writes localStorage, so this keeps tracking the OS.
            document.documentElement.setAttribute('data-theme', 'light');
        }
    })();
</script>
