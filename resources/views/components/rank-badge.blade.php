@props(['rank' => null, 'label' => null, 'showLabel' => false, 'size' => 'md'])

@php
    // Tiers are server-defined now (K4-LevelRanks-SwiftlyS2's ranks.json via
    // RankCatalogService), not a fixed 18-entry enum with matching artwork -
    // so the plate is a small chip colored from the tier's own hex, the same
    // minimal class-for-shape/inline-style-for-color split the Skin module
    // already uses for rarity (see accent()/glow() there), rather than a
    // /images/ranks/{index}.png lookup that only ever matched one hardcoded
    // ladder.
    $sizes = [
        'sm' => 'h-5 min-w-5 px-1.5 text-[10px]',
        'md' => 'h-6 min-w-6 px-2 text-xs',
        'lg' => 'h-8 min-w-8 px-2.5 text-sm',
    ];
    $chipSize = $sizes[$size] ?? $sizes['md'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    <template x-if="({{ $rank }}?.index ?? 0) > 0">
        {{-- Background/border/text all come from the tier's own hex (light
             tint, not a solid fill, so it stays readable regardless of the
             hue - identical reasoning to glow()'s radial wash in the Skin
             module); falls back to a neutral chip if ranks.json carried no
             valid hex. The tag is short by design ("GN1", "GE", ...), sized
             for a chip this small - the full name sits beside it via
             $showLabel instead. --}}
        <span
            class="inline-flex shrink-0 items-center justify-center rounded-md border font-bold uppercase tracking-wide {{ $chipSize }}"
            :class="{{ $rank }}?.hex ? '' : 'border-line bg-surface-raised text-ink-faint'"
            :style="{{ $rank }}?.hex ? ('background:' + {{ $rank }}.hex + '1a;border-color:' + {{ $rank }}.hex + '66;color:' + {{ $rank }}.hex) : ''"
            :title="{{ $label }}"
            x-text="{{ $rank }}?.tag || '?'"
        ></span>
    </template>

    {{-- Unranked has no tier of its own, so it needs a visible fallback
         rather than rendering as an empty cell. --}}
    <template x-if="({{ $rank }}?.index ?? 0) === 0">
        <span class="inline-flex items-center rounded-md bg-surface-raised px-2 py-1 text-xs font-medium text-ink-faint ring-1 ring-inset ring-line" x-text="{{ $label }}"></span>
    </template>

    @if ($showLabel)
        <span class="truncate text-sm text-ink-muted" x-show="({{ $rank }}?.index ?? 0) > 0" x-text="{{ $label }}"></span>
    @endif
</span>
