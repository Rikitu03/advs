@props([
    'icon',
    'color' => 'zinc',
])

@php
    // Literal class strings per color so Tailwind's JIT compiler picks them up.
    $colors = [
        'rose' => 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
        'amber' => 'bg-amber-400/15 text-amber-700 dark:text-amber-300',
        'sky' => 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
        'emerald' => 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        'zinc' => 'bg-black/5 text-cu-muted dark:bg-white/5',
    ];
@endphp

<span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ $colors[$color] ?? $colors['zinc'] }}">
    <flux:icon :icon="$icon" class="size-4" />
</span>
