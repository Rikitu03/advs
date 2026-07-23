@props(['data'])

@php
    $detected = $data['detected'] ?? false;
    $verified = $data['verified'] ?? false;
    $pass = $data['pass'] ?? false;
    $queryRing = $pass ? 'border-emerald-500/40' : 'border-rose-500/40';
    $queryInk = $pass ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300';
@endphp

@unless ($verified)
    <div class="flex items-center gap-3 rounded-xl border border-amber-400/20 bg-amber-400/5 px-4 py-3">
        <flux:icon icon="exclamation-triangle" class="size-5 shrink-0 text-amber-700 dark:text-amber-300" />
        <p class="text-sm text-cu-text">
            @if ($detected)
                A signature region was detected, but no reference is enrolled for this vendor yet, so no comparison could be run. A missing-component penalty was applied.
            @else
                No signature region was detected by YOLOv8, so no comparison could be run. A missing-component penalty was applied.
            @endif
        </p>
    </div>
@else
    <div class="flex flex-col gap-4">
        <div class="grid gap-4 sm:grid-cols-2">
            {{-- Reference --}}
            <div class="rounded-xl border border-cu-border bg-cu-surface p-4">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-medium text-cu-muted">Reference (enrolled)</span>
                    <flux:icon icon="check-badge" class="size-4 text-cu-blue" />
                </div>
                <div class="flex h-24 items-center justify-center rounded-lg bg-black/[0.03] text-cu-muted dark:bg-white/[0.03]">
                    <svg viewBox="0 0 200 70" class="h-16 w-auto" fill="none" aria-hidden="true">
                        <path d="M8 50 C 30 8, 45 62, 62 34 S 96 6, 116 44 S 150 60, 172 24 192 40 192 40" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            </div>

            {{-- Query --}}
            <div class="rounded-xl border {{ $queryRing }} bg-cu-surface p-4">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-medium text-cu-muted">Query (this submission)</span>
                    <x-pass-fail :pass="$pass" />
                </div>
                <div class="flex h-24 items-center justify-center rounded-lg bg-black/[0.03] dark:bg-white/[0.03] {{ $queryInk }}">
                    <svg viewBox="0 0 200 70" class="h-16 w-auto" fill="none" aria-hidden="true">
                        <path d="M8 36 C 26 60, 44 12, 60 40 S 92 64, 112 28 S 146 8, 168 48 190 30 190 30" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            </div>
        </div>

        {{-- Metrics --}}
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]">
                <p class="text-xs text-cu-muted">Similarity</p>
                <p class="text-lg font-semibold {{ $queryInk }}">{{ $data['similarity'] }}%</p>
            </div>
            <div class="rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]">
                <p class="text-xs text-cu-muted">Embedding distance (128-D)</p>
                <p class="text-lg font-semibold text-cu-text">{{ $data['distance'] }}</p>
            </div>
            <div class="rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]">
                <p class="text-xs text-cu-muted">Distance threshold</p>
                <p class="text-lg font-semibold text-cu-text">≤ {{ $data['distance_threshold'] }}</p>
            </div>
        </div>
    </div>
@endunless
