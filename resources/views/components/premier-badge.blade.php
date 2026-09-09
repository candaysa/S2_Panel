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

    // Everything here is an inline style on purpose, NOT Tailwind classes.
    // The deploy path for this panel copies PHP/Blade files; it does not
    // run a frontend build, so an arbitrary-value class (left-[14px],
    // text-[16px], ...) that Tailwind has not already emitted into
    // public/build simply does not exist in the served CSS. Two rounds of
    // "fixed" geometry did nothing live for exactly that reason: with no
    // rule behind them the number fell back to position:absolute with no
    // offset (hard against the plate's left edge, on top of the stripes)
    // at whatever font size it inherited from the table.
    //
    // Geometry is measured from the plate art itself (178x64, all seven
    // bands identical): columns 0-33 are the fully opaque stripe block,
    // 34-40 its fading edge, and everything past ~42 is the translucent
    // body the number is meant to sit on. 33/178 = 18.5% of the width, so
    // 27% clears the stripes with room for the italic slant and its
    // shadow to lean back without touching them, and reads visually
    // centered on the fading band rather than pinned to its right side
    // (a first pass at 30%/8% sat slightly too far right and too high).
    $heightPx = ['sm' => 24, 'md' => 28, 'lg' => 36][$size] ?? 28;

    // 178/64 - the plate's own aspect ratio, so "27% of the width" can be
    // expressed from the height, which is the dimension that is set.
    $widthPx = $heightPx * 2.78125;

    $leftPx = round($widthPx * 0.27);
    $fontPx = round($heightPx * 0.62);
    $topPx = round($heightPx * 0.12);
@endphp

<span
    {{ $attributes->merge(['class' => 'relative inline-flex items-center']) }}
    style="height:{{ $heightPx }}px"
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
        style="height:{{ $heightPx }}px;width:auto"
        loading="lazy"
        decoding="async"
    >
    {{-- One :style binding rather than a static style plus a bound one:
         the colour is the only part that varies, so building the whole
         declaration in one place leaves no question about how Alpine
         merges the two. --}}
    <span
        class="absolute"
        :style="'left:{{ $leftPx }}px;top:{{ $topPx }}px;font-size:{{ $fontPx }}px;font-weight:700;font-style:italic;line-height:1;white-space:nowrap;text-shadow:0 1px 0 black;color:' + band()[2]"
        x-text="({{ $points }} ?? 0).toLocaleString()"
    ></span>
</span>
