{{--
    Marks one extracted OCR key/value pair as worth re-reading: the value was
    read with low confidence, breaks the field's expected format, or reads as
    noisy output. Amber, not rose — this is "check this value", not a failed
    verification component (cf. <x-pass-fail>).
--}}
@props([
    'label',
    'reasons' => [],
])

<span title="{{ implode(' ', $reasons) }}"
      {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-300']) }}>
    <flux:icon icon="exclamation-triangle" variant="micro" class="size-3.5" />
    {{ $label }}
    <span class="sr-only">— {{ implode(' ', $reasons) }}</span>
</span>
