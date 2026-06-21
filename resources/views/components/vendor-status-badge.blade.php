@props(['status'])

@php
    $classes = match ($status) {
        'Processing' => 'border-cu-blue/30 bg-cu-blue/10 text-sky-700',
        'Pending Review' => 'border-cu-yellow/60 bg-cu-yellow/20 text-yellow-700',
        'Approved' => 'border-emerald-300 bg-emerald-50 text-emerald-700',
        'Rejected' => 'border-rose-300 bg-rose-50 text-rose-700',
        default => 'border-zinc-200 bg-zinc-50 text-zinc-700',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {$classes}"]) }}>
    {{ $status }}
</span>
