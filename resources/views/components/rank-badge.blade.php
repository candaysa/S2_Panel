@props(['rank' => null, 'label' => null, 'showLabel' => false, 'size' => 'md'])

@php
    // Competitive insignia, keyed by CsRank::for()['index'] - 0 is unranked
    // (no plate), 1-18 map to public/images/ranks/{index}.png in the same
    // order as the ladder in config/rank.php.
    $heights = ['sm' => 'h-5', 'md' => 'h-7', 'lg' => 'h-9'];
    $height = $heights[$size] ?? $heights['md'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    <template x-if="({{ $rank }}?.index ?? 0) > 0">
        <img
            :src="'/images/ranks/' + {{ $rank }}.index + '.png'"
            :alt="{{ $label }}"
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
