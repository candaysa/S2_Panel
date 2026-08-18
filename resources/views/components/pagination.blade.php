{{--
    Shared pager for any Alpine list that pages server-side.

    Extracted from the leaderboard, which had the only copy, once Bans needed
    the same thing - a list capped at one page with no way past it is the
    same bug wherever it appears.

    The host component must expose: page, lastPage, total, perPage, go(n),
    pageNumbers, rangeFrom, rangeTo. See resources/views/bans/index.blade.php
    for the canonical set.

    `jump` adds a type-a-number box. Worth it on a list with hundreds of
    pages, where the windowed buttons only ever reach the two ends and the
    five pages around you; pointless on one with six.

    `range` prints "51-100 / 587". Off for a page that already states its
    own range somewhere better - the leaderboard puts it under the heading.
--}}
@props(['jump' => false, 'range' => true])

<div
    x-show="!loading && lastPage > 1"
    x-cloak
    @class([
        'mt-4 flex flex-wrap items-center gap-3',
        'justify-between' => $range,
        'justify-center' => ! $range,
    ])
>
    @if ($range)
        <p class="text-xs text-ink-faint" x-text="`${rangeFrom}–${rangeTo} / ${total.toLocaleString()}`"></p>
    @endif

    <div class="flex flex-wrap items-center gap-1">
        <button
            type="button"
            @click="go(page - 1)"
            :disabled="page === 1"
            class="inline-flex size-9 items-center justify-center rounded-lg border border-line text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink disabled:pointer-events-none disabled:opacity-40"
            aria-label="{{ __('i18n::messages.ranks.previous_page') }}"
        >
            <x-icon name="chevron-left" class="size-4" />
        </button>

        <template x-for="(n, i) in pageNumbers" :key="'p' + i">
            <span>
                <span x-show="n === '…'" class="inline-flex size-9 items-center justify-center text-ink-faint">…</span>
                <button
                    type="button"
                    x-show="n !== '…'"
                    @click="go(n)"
                    class="inline-flex min-w-9 items-center justify-center rounded-lg border px-2.5 py-2 text-sm font-medium transition-colors"
                    :class="n === page
                        ? 'border-brand-strong bg-brand-soft text-brand-strong'
                        : 'border-line text-ink-muted hover:bg-surface-raised hover:text-ink'"
                    x-text="n"
                ></button>
            </span>
        </template>

        <button
            type="button"
            @click="go(page + 1)"
            :disabled="page === lastPage"
            class="inline-flex size-9 items-center justify-center rounded-lg border border-line text-ink-muted transition-colors hover:bg-surface-raised hover:text-ink disabled:pointer-events-none disabled:opacity-40"
            aria-label="{{ __('i18n::messages.ranks.next_page') }}"
        >
            <x-icon name="chevron-left" class="size-4 rotate-180" />
        </button>

        @if ($jump)
            {{-- Committed on Enter or blur rather than per keystroke, so
                 typing "12" does not fetch page 1 on the way. --}}
            <label class="ml-2 flex items-center gap-1.5 text-xs text-ink-faint">
                {{ __('i18n::messages.pagination.go_to') }}
                <input
                    type="number"
                    min="1"
                    :max="lastPage"
                    :value="page"
                    @keydown.enter.prevent="go(Number($event.target.value))"
                    @change="go(Number($event.target.value))"
                    class="w-16 rounded-lg border border-line bg-surface px-2 py-1.5 text-center text-sm text-ink focus:border-brand-strong focus:outline-none"
                >
            </label>
        @endif
    </div>
</div>
