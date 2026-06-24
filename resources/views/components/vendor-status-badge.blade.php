@props(['status'])

@php
    $classes = match ($status) {
        'Processing' => 'border-cu-blue/30 bg-cu-blue/10 text-sky-700 dark:text-sky-300',
        'Pending Review' => 'border-cu-yellow/60 bg-cu-yellow/20 text-yellow-700 dark:text-yellow-300',
        'Approved' => 'border-emerald-500/40 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        'Rejected' => 'border-rose-500/40 bg-rose-500/15 text-rose-700 dark:text-rose-300',
        default => 'border-cu-border bg-black/5 text-cu-text dark:bg-white/5',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {$classes}"]) }}>
    {{ $status }}
</span>
