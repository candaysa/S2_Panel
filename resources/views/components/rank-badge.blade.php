@props(['rank' => null, 'label' => null, 'showLabel' => false, 'size' => 'md'])

@php
    // The 18 competitive-ladder plates under public/images/ranks/{index}.png,
    // keyed by tierFor()'s 1-based index (1=Silver I ... 18=Global Elite -
    // the same order RankCatalogService's DEFAULT_RANKS ships, which is what
    // every install actually renders until an operator drops in a
    // customized ranks.json). Confirmed reachable in production; kept as a
    // single unconditional <img> rather than an x-show-toggled image/chip
    // pair - that combination rendered BOTH at once live (image and the
    // fallback tag chip stacked side by side), not one-or-the-other as
    // intended, so the extra machinery was removed rather than chased
    // further blind.
    $heights = ['sm' => 'h-5', 'md' => 'h-7', 'lg' => 'h-9'];
    $height = $heights[$size] ?? $heights['md'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    <template x-if="({{ $rank }}?.index ?? 0) > 0">
        <img
            :src="'/images/ranks/' + {{ $rank }}.index + '.png'"
            :alt="{{ $showLabel ? "''" : $label }}"
            :title="{{ $label }}"
            class="{{ $height }} w-auto shrink-0"
            loading="lazy"
            decoding="async"
        >
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
