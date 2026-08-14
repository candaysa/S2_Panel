@props(['rank' => null, 'label' => null, 'compact' => false])

{{--
    Competitive tier insignia.

    Drawn here rather than shipped as image assets: Valve's own rank icons are
    their artwork, and a panel that anyone can clone should not redistribute
    them. This is an original mark whose chevron count and colour follow the
    tier, so the ladder still reads at a glance.

    `rank` is the array from App\Support\CsRank::for() - an Alpine expression
    is expected, since every table row that uses this renders client-side.
--}}
<span
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset']) }}
    :class="{
        unranked: 'bg-surface-raised text-ink-faint ring-line',
        silver: 'bg-slate-400/10 text-slate-300 ring-slate-400/30',
        gold: 'bg-amber-400/10 text-amber-300 ring-amber-400/30',
        guardian: 'bg-sky-400/10 text-sky-300 ring-sky-400/30',
        eagle: 'bg-violet-400/10 text-violet-300 ring-violet-400/30',
        elite: 'bg-orange-400/10 text-orange-300 ring-orange-400/30',
    }[{{ $rank }}?.group ?? 'unranked']"
>
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 shrink-0" aria-hidden="true">
        {{-- One to three chevrons, then a star for the top family, so the
             silhouette differs between groups and not only the colour. --}}
        <template x-if="({{ $rank }}?.group ?? 'unranked') === 'elite'">
            <path d="M8 1.5l1.9 4 4.4.6-3.2 3 .8 4.4L8 11.4 4.1 13.5l.8-4.4-3.2-3 4.4-.6z" />
        </template>
        <template x-if="({{ $rank }}?.group ?? 'unranked') !== 'elite'">
            <g>
                <path d="M3 9.5L8 5l5 4.5" />
                <path x-show="['guardian','eagle'].includes({{ $rank }}?.group)" d="M3 12.5L8 8l5 4.5" />
                <path x-show="({{ $rank }}?.group ?? '') === 'unranked'" d="M8 12.5v.01" />
            </g>
        </template>
    </svg>

    @unless ($compact)
        <span x-text="{{ $label }}"></span>
    @endunless
</span>
