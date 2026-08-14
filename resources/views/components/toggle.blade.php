@props([
    'state',
    'disabled' => 'false',
    'label' => null,
])

{{--
    The panel's only on/off control. Checkboxes are deliberately not used
    anywhere: a switch reads as a setting that takes effect immediately, which
    is what every one of these does, while a checkbox implies a pending choice
    waiting on a submit button.

    `state` is an Alpine expression evaluating to a boolean, `disabled` an
    optional one. Wire the click on the calling side so the component stays
    agnostic about what it is toggling:

        <x-toggle state="module.enabled" @click="toggle(module)" />
--}}
<button
    type="button"
    role="switch"
    :aria-checked="({{ $state }}).toString()"
    @if ($label) aria-label="{{ $label }}" @endif
    :disabled="{{ $disabled }}"
    :class="{{ $state }} ? 'bg-brand-strong' : 'bg-line'"
    {{ $attributes->merge(['class' => 'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-50']) }}
>
    <span
        :class="{{ $state }} ? 'translate-x-5' : 'translate-x-0.5'"
        class="inline-block size-5 transform rounded-full bg-white transition-transform"
    ></span>
</button>
