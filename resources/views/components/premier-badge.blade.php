@props(['points' => null, 'size' => 'md'])

@php
    // CS2's own in-game "Seçkin" (Premier) rating plate: a gradient chip
    // banded by score, colored through the same seven families the
    // competitive ladder uses - grey, light blue, blue, purple, pink, red,
    // and gold for the top band (30000+, "shining" in the real client).
    // Thresholds/colors are Valve's well-documented public Premier bands,
    // not pulled from a live client - close enough for a leaderboard chip;
    // adjust BANDS below if a confirmed live export ever differs.
    //
    // Self-contained like rank-badge.blade.php: everything is one inline
    // Alpine expression rather than a page-level helper method, so this
    // drops into any page's markup without that page having to know it
    // exists.
    $sizes = [
        'sm' => 'h-6 min-w-14 px-2 text-xs',
        'md' => 'h-7 min-w-16 px-2.5 text-sm',
        'lg' => 'h-9 min-w-20 px-3 text-base',
    ];
    $sizeClass = $sizes[$size] ?? $sizes['md'];

    // [minimum points, gradient start, gradient end], highest first - the
    // inline expression below takes the first band the value clears.
    $bands = [
        [30000, '#FFD700', '#FF8C00'],
        [25000, '#EE4B4B', '#A61E1E'],
        [20000, '#D94FE0', '#9B1FA8'],
        [15000, '#8B5CF6', '#5B2C9E'],
        [10000, '#4C6FFF', '#2A3FB0'],
        [5000, '#4AC4F0', '#1E7EA8'],
        [0, '#9CA3AF', '#6B7280'],
    ];
@endphp

<span
    {{ $attributes->merge(['class' => "inline-flex items-center justify-center rounded-lg font-bold tabular-nums text-white shadow-sm $sizeClass"]) }}
    x-data="{ premierBands: @js($bands) }"
    :style="(() => {
        const p = {{ $points }} ?? 0;
        const [, c1, c2] = premierBands.find(([min]) => p >= min) ?? premierBands[premierBands.length - 1];
        return `background:linear-gradient(135deg, ${c1}, ${c2})`;
    })()"
    x-text="({{ $points }} ?? 0).toLocaleString()"
></span>
