@props([
    'level',
    'score' => null,
])

@php
    // Literal class strings per band so Tailwind's JIT compiler picks them up.
    $map = [
        'high' => 'bg-rose-500/15 text-rose-300 ring-rose-500/30',
        'medium' => 'bg-amber-400/15 text-amber-300 ring-amber-400/30',
        'low' => 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30',
    ];
    $dot = [
        'high' => 'bg-rose-400',
        'medium' => 'bg-amber-400',
        'low' => 'bg-emerald-400',
    ];
    $label = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'][$level] ?? 'Low';
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset '.($map[$level] ?? $map['low'])]) }}>
    <span class="size-1.5 rounded-full {{ $dot[$level] ?? $dot['low'] }}"></span>
    @unless (is_null($score)){{ $score }} ·@endunless {{ $label }}
</span>
