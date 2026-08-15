@props(['key', 'label'])

{{--
    Clickable column header for a server-sorted table.

    Expects the enclosing Alpine scope to expose `sort` (current column),
    `dir` ('asc'|'desc') and a `sortBy(key)` method that flips direction on
    a repeat click and reloads. The chevron always renders so the column
    doesn't shift width when it becomes the active sort.
--}}
<button type="button" @click="sortBy('{{ $key }}')" class="inline-flex items-center gap-1 transition-colors hover:text-ink">
    {{ $label }}
    <x-icon
        name="chevron-left"
        class="size-3 -rotate-90 transition-opacity"
        ::class="sort === '{{ $key }}' ? 'opacity-100' : 'opacity-30'"
        ::style="sort === '{{ $key }}' && dir === 'asc' ? 'transform:rotate(90deg)' : ''"
    />
</button>
