@props(['name', 'class' => 'size-5'])
{{-- Small outline icon set (24x24, currentColor, 1.75 stroke), hand-drawn to
     match the reference navbar's icon language. Only the icons the current
     pages actually use are defined here — extend per-icon as new module
     pages are wired up, rather than pre-building an unused full set. --}}
@switch($name)
    @case('home')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M3.5 10.5 12 3.5l8.5 7" />
            <path d="M5.5 9v10a1 1 0 0 0 1 1h3.5v-6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v6H18a1 1 0 0 0 1-1V9" />
        </svg>
        @break

    @case('users')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <circle cx="9" cy="8" r="3" />
            <path d="M3.5 19.5c0-3 2.5-5.25 5.5-5.25s5.5 2.25 5.5 5.25" />
            <path d="M15.5 5.75c1.4.35 2.4 1.55 2.4 3s-1 2.65-2.4 3" />
            <path d="M18 14.75c2 .4 3.5 2.15 3.5 4.25" />
        </svg>
        @break

    @case('group')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <circle cx="8.5" cy="7.5" r="2.75" />
            <circle cx="16" cy="8.5" r="2.25" />
            <path d="M3 19c0-2.9 2.46-5.25 5.5-5.25S14 16.1 14 19" />
            <path d="M14.75 14.35c2.4.35 4.25 2.3 4.25 4.65" />
        </svg>
        @break

    @case('server')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <rect x="3.5" y="4" width="17" height="6.5" rx="1.5" />
            <rect x="3.5" y="13.5" width="17" height="6.5" rx="1.5" />
            <path d="M7 7.25h.01M7 16.75h.01" />
        </svg>
        @break

    @case('puzzle')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M9.5 4.5h3a1 1 0 0 1 1 1v1.75a1.75 1.75 0 1 0 0 3.5V12a1 1 0 0 1-1 1h-1.75a1.75 1.75 0 1 1-3.5 0H5.5a1 1 0 0 1-1-1V8.75a1.75 1.75 0 1 0 0-3.5V4.5a1 1 0 0 1 1-1H8" />
            <path d="M14.5 12h1.75a1.75 1.75 0 1 0 0-3.5V6.75a1 1 0 0 0-1-1H13.5" />
            <path d="M13.5 19.5h-8a1 1 0 0 1-1-1v-3.75a1.75 1.75 0 1 1 3.5 0V16a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1.75a1.75 1.75 0 1 1 3.5 0V18a1 1 0 0 1-1 1h-2Z" />
        </svg>
        @break

    @case('trophy')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M7 4.5h10v5a5 5 0 0 1-10 0Z" />
            <path d="M7 5.5H4a1 1 0 0 0-1 1c0 2.2 1.5 3.5 3.5 3.7M17 5.5h3a1 1 0 0 1 1 1c0 2.2-1.5 3.5-3.5 3.7" />
            <path d="M12 14.5v3M9 19.5h6M9.5 17.5h5l.5 2h-6Z" />
        </svg>
        @break

    @case('palette')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M12 3.5a8.5 8.5 0 1 0 0 17c1 0 1.6-.7 1.6-1.5 0-.4-.15-.75-.4-1a1.5 1.5 0 0 1 1.1-2.5H16a4 4 0 0 0 4-4c0-4.4-3.6-8-8-8Z" />
            <circle cx="7.5" cy="11" r="1" />
            <circle cx="9.5" cy="7.5" r="1" />
            <circle cx="14.5" cy="7.5" r="1" />
            <circle cx="16.5" cy="11" r="1" />
        </svg>
        @break

    @case('terminal')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <rect x="3.5" y="4.5" width="17" height="15" rx="1.5" />
            <path d="M7 9.5 10 12l-3 2.5M12 14.5h4.5" />
        </svg>
        @break

    @case('list')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M8 6.5h12M8 12h12M8 17.5h12" />
            <path d="M4 6.5h.01M4 12h.01M4 17.5h.01" />
        </svg>
        @break

    @case('bell')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M6 10a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5h-15S6 14 6 10Z" />
            <path d="M10 19a2 2 0 0 0 4 0" />
        </svg>
        @break

    @case('webhook')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <circle cx="6" cy="17" r="2.25" />
            <circle cx="16.5" cy="5" r="2.25" />
            <circle cx="17.5" cy="17" r="2.25" />
            <path d="M8 16 13.5 6.5M6 14.75V10a4 4 0 0 1 4-4h4.2M15.3 17h-5.6" />
        </svg>
        @break

    @case('chart')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M4 20V4M4 20h16" />
            <path d="M8 16v-4M12.5 16V8M17 16v-7" />
        </svg>
        @break

    @case('scale')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M12 3.5v17M8 20.5h8" />
            <path d="M5 7.5h6M13 7.5h6" />
            <path d="m5 7.5-2.5 5a2.5 2.5 0 0 0 5 0Z" />
            <path d="m19 7.5-2.5 5a2.5 2.5 0 0 0 5 0Z" />
        </svg>
        @break

    @case('star')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M12 3.5l2.6 5.4 5.9.7-4.3 4.1 1.1 5.9L12 16.7l-5.3 2.9 1.1-5.9-4.3-4.1 5.9-.7Z" />
        </svg>
        @break

    @case('ban')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <circle cx="12" cy="12" r="8.25" />
            <path d="M6.4 6.4l11.2 11.2" />
        </svg>
        @break

    @case('cog')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <circle cx="12" cy="12" r="3.25" />
            <path d="M12 3.5v2.1M12 18.4v2.1M20.5 12h-2.1M5.6 12H3.5M17.8 6.2l-1.5 1.5M7.7 16.3l-1.5 1.5M17.8 17.8l-1.5-1.5M7.7 7.7 6.2 6.2" />
        </svg>
        @break

    @case('logout')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M9 4.5H6a1.5 1.5 0 0 0-1.5 1.5v12A1.5 1.5 0 0 0 6 19.5h3" />
            <path d="M14.5 8 18.5 12l-4 4" />
            <path d="M18.5 12h-10" />
        </svg>
        @break

    @case('menu')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M4 6.5h16M4 12h16M4 17.5h16" />
        </svg>
        @break

    @case('close')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M6 6l12 12M18 6 6 18" />
        </svg>
        @break

    @case('sun')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <circle cx="12" cy="12" r="4" />
            <path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4" />
        </svg>
        @break

    @case('moon')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a7 7 0 1 0 10.5 10.5Z" />
        </svg>
        @break

    @case('flag')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M6 20.5V4" />
            <path d="M6 5c1.5-1 3-1 5 0s3.5 1 5 0v8c-1.5 1-3 1-5 0s-3.5-1-5 0Z" />
        </svg>
        @break

    @case('pulse')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M3.5 12h3.2l1.8-4.5 3 9L14 9l1.6 3H20.5" />
        </svg>
        @break

    @case('refresh')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}">
            <path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3" />
            <path d="M19.5 4.5v4.5H15" />
        </svg>
        @break

    @case('steam')
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="{{ $class }}">
            <path d="M12 2C6.99 2 2.87 5.8 2.25 10.71l5.37 2.28a2.9 2.9 0 0 1 1.62-.5c.06 0 .11 0 .17.01l2.39-3.55v-.05a3.61 3.61 0 1 1 3.61 3.65h-.08l-3.44 2.49v.09a2.9 2.9 0 0 1-4.82 2.18l-3.82-1.62A10 10 0 1 0 12 2Zm-2.28 14.9-1.1-.47a2.19 2.19 0 0 0 3.99-1.55l1.19.5a3.4 3.4 0 0 1-4.08 1.52ZM12.94 10.7a2.4 2.4 0 1 1 2.4 2.4 2.4 2.4 0 0 1-2.4-2.4Zm.6 0a1.8 1.8 0 1 0 1.8-1.8 1.8 1.8 0 0 0-1.8 1.8Z" />
        </svg>
        @break
@endswitch
