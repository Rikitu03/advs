@props([
    'label',
    'value',
    'icon',
    'accent' => 'purple',
    'hint' => null,
])

@php
    // Literal class strings per accent so Tailwind's JIT compiler picks them up.
    $accents = [
        'purple' => ['chip' => 'bg-cu-purple/15 text-cu-purple', 'bar' => 'bg-cu-purple'],
        'pink' => ['chip' => 'bg-cu-pink/15 text-cu-pink', 'bar' => 'bg-cu-pink'],
        'blue' => ['chip' => 'bg-cu-blue/15 text-cu-blue', 'bar' => 'bg-cu-blue'],
        'yellow' => ['chip' => 'bg-cu-yellow/15 text-cu-yellow', 'bar' => 'bg-cu-yellow'],
    ];
    $a = $accents[$accent] ?? $accents['purple'];
@endphp

<div {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-2xl border border-cu-border bg-cu-surface p-5 transition hover:border-cu-border']) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-cu-muted">{{ $label }}</p>
            <p class="mt-2 text-3xl font-semibold tracking-tight text-cu-text">{{ $value }}</p>
            @if ($hint)
                <p class="mt-1 text-xs text-cu-muted">{{ $hint }}</p>
            @endif
        </div>
        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $a['chip'] }}">
            <flux:icon :icon="$icon" class="size-5" />
        </span>
    </div>
    <span class="absolute inset-x-0 bottom-0 h-1 {{ $a['bar'] }}"></span>
</div>
