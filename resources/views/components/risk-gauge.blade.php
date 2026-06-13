@props([
    'score',
    'level',
])

@php
    $stroke = ['high' => '#fb7185', 'medium' => '#fbbf24', 'low' => '#34d399'][$level] ?? '#34d399';
    $radius = 52;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - max(0, min(100, (int) $score)) / 100);
    $textClass = ['high' => 'text-rose-300', 'medium' => 'text-amber-300', 'low' => 'text-emerald-300'][$level] ?? 'text-emerald-300';
@endphp

<div {{ $attributes->merge(['class' => 'relative flex size-36 shrink-0 items-center justify-center']) }}>
    <svg class="size-36 -rotate-90" viewBox="0 0 120 120" aria-hidden="true">
        <circle cx="60" cy="60" r="{{ $radius }}" fill="none" stroke="currentColor" stroke-width="10" class="text-white/10" />
        <circle
            cx="60" cy="60" r="{{ $radius }}"
            fill="none" stroke="{{ $stroke }}" stroke-width="10" stroke-linecap="round"
            stroke-dasharray="{{ $circumference }}"
            stroke-dashoffset="{{ $offset }}"
        />
    </svg>
    <div class="absolute flex flex-col items-center">
        <span class="text-4xl font-bold {{ $textClass }}">{{ $score }}</span>
        <span class="text-xs font-medium text-cu-muted">/ 100</span>
    </div>
</div>
