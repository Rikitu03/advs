@props([
    'pass',
    'neutralLabel' => null,
])

@if ($pass)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300']) }}>
        <flux:icon icon="check-circle" variant="micro" class="size-3.5" />
        Pass
    </span>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-rose-500/15 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:text-rose-300']) }}>
        <flux:icon icon="x-circle" variant="micro" class="size-3.5" />
        {{ $neutralLabel ?? 'Fail' }}
    </span>
@endif
