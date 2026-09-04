@props(['rank' => null, 'label' => null, 'showLabel' => false, 'size' => 'md'])

@php
    // Real artwork first - the 18 competitive-ladder plates under
    // public/images/ranks/{index}.png, keyed by tierFor()'s 1-based index
    // (1=Silver I ... 18=Global Elite, the same order RankCatalogService's
    // DEFAULT_RANKS ships, which is what every install actually renders
    // until an operator drops in a customized ranks.json). If a server ever
    // does customize ranks.json to a different ladder, that index stops
    // lining up with this fixed artwork set - @error there falls back to a
    // colored tag chip (the tier's own hex/tag) instead of a broken image,
    // the same failure-tracked fallback the Skin module's sticker slots use
    // (a stale src alone isn't enough signal; the image has to actually
    // fail to load before the fallback takes over).
    $heights = ['sm' => 'h-5', 'md' => 'h-7', 'lg' => 'h-9'];
    $height = $heights[$size] ?? $heights['md'];
    $chipSizes = [
        'sm' => 'h-5 min-w-5 px-1.5 text-[10px]',
        'md' => 'h-7 min-w-7 px-2 text-xs',
        'lg' => 'h-9 min-w-9 px-2.5 text-sm',
    ];
    $chipSize = $chipSizes[$size] ?? $chipSizes['md'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }} x-data="{ plateFailed: false }">
    <template x-if="({{ $rank }}?.index ?? 0) > 0">
        <span class="relative inline-flex shrink-0 items-center {{ $height }}">
            <img
                x-show="!plateFailed"
                :src="'/images/ranks/' + {{ $rank }}.index + '.png'"
                alt=""
                @if (! $showLabel) :alt="{{ $label }}" @endif
                :title="{{ $label }}"
                class="{{ $height }} w-auto shrink-0"
                loading="lazy"
                decoding="async"
                @@error="plateFailed = true"
            >
            <span
                x-show="plateFailed"
                class="inline-flex items-center justify-center rounded-md border font-bold uppercase tracking-wide {{ $chipSize }}"
                :class="{{ $rank }}?.hex ? '' : 'border-line bg-surface-raised text-ink-faint'"
                :style="{{ $rank }}?.hex ? ('background:' + {{ $rank }}.hex + '1a;border-color:' + {{ $rank }}.hex + '66;color:' + {{ $rank }}.hex) : ''"
                :title="{{ $label }}"
                x-text="{{ $rank }}?.tag || '?'"
            ></span>
        </span>
    </template>

    {{-- Unranked has no plate of its own, so it needs a visible fallback
         rather than rendering as an empty cell. --}}
    <template x-if="({{ $rank }}?.index ?? 0) === 0">
        <span class="inline-flex items-center rounded-md bg-surface-raised px-2 py-1 text-xs font-medium text-ink-faint ring-1 ring-inset ring-line" x-text="{{ $label }}"></span>
    </template>

    @if ($showLabel)
        <span class="truncate text-sm text-ink-muted" x-show="({{ $rank }}?.index ?? 0) > 0" x-text="{{ $label }}"></span>
    @endif
</span>
