@props(['points' => null, 'size' => 'md'])

@php
    // CS2's own "Seçkin" (Premier) rating plate, ported 1:1 from the
    // predecessor panel (CS2_Panel's CommonHelper::getCSRatingImage()) -
    // same 7 score bands, same Valve rating icon per band, same italic bold
    // number overlaid in the band's own color - rather than redesigned from
    // scratch. Assets are the exact rating.{band}.png files from there,
    // copied verbatim into public/images/ratings/; colors/thresholds match
    // that helper and its cs2rating-text-* CSS classes exactly.
    //
    // [minimum points, icon basename, text color]
    $bands = [
        [30000, 'unusual', '#FFFF00'],
        [25000, 'ancient', '#EB4B4B'],
        [20000, 'legendary', '#D22CE6'],
        [15000, 'mythical', '#8846FF'],
        [10000, 'rare', '#4B69FF'],
        [5000, 'uncommon', '#5E98D7'],
        [0, 'common', '#B1C3D9'],
    ];

    // The source's icon is a fixed 24px (h-6) with a fixed 16px number;
    // scaled proportionally here for the handful of places this badge
    // needs to be smaller (a table row) or larger (the profile header).
    $heights = ['sm' => 'h-5', 'md' => 'h-6', 'lg' => 'h-8'];
    $height = $heights[$size] ?? $heights['md'];
    $textSizes = ['sm' => 'text-[9px]', 'md' => 'text-xs', 'lg' => 'text-base'];
    $textSize = $textSizes[$size] ?? $textSizes['md'];
    $textPos = ['sm' => 'left-2.5 top-0', 'md' => 'left-3.5 top-0.5', 'lg' => 'left-5 top-1'];
    $textPosition = $textPos[$size] ?? $textPos['md'];
@endphp

<span
    {{ $attributes->merge(['class' => "relative inline-flex items-center $height"]) }}
    x-data="{
        premierBands: @js($bands),
        band() {
            const p = {{ $points }} ?? 0;
            return this.premierBands.find(([min]) => p >= min) ?? this.premierBands[this.premierBands.length - 1];
        },
    }"
>
    <img
        :src="'/images/ratings/rating.' + band()[1] + '.png'"
        alt="CS Rating"
        class="{{ $height }} w-auto"
        loading="lazy"
        decoding="async"
    >
    <span
        class="absolute font-bold italic {{ $textSize }} {{ $textPosition }}"
        :style="'text-shadow:0 1px 0 black;color:' + band()[2]"
        x-text="({{ $points }} ?? 0).toLocaleString()"
    ></span>
</span>
