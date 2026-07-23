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
                A stamp/logo region was detected, but no reference logo is on file for this issuer yet, so no comparison could be run. A missing-component penalty was applied to the risk score.
            @else
                No stamp region was detected by YOLOv8, so no comparison could be run. A missing-component penalty was applied to the risk score.
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
                <div class="flex h-24 items-center justify-center rounded-lg bg-black/[0.03] dark:bg-white/[0.03] text-cu-blue">
                    <x-stamp-mark />
                </div>
            </div>

            {{-- Query --}}
            <div class="rounded-xl border {{ $queryRing }} bg-cu-surface p-4">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-medium text-cu-muted">Query (this submission)</span>
                    <x-pass-fail :pass="$pass" />
                </div>
                <div class="flex h-24 items-center justify-center rounded-lg bg-black/[0.03] dark:bg-white/[0.03] {{ $queryInk }}">
                    <x-stamp-mark />
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
                <p class="text-xs text-cu-muted">Cosine similarity</p>
                <p class="text-lg font-semibold text-cu-text">{{ $data['cosine'] }}</p>
            </div>
            <div class="rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]">
                <p class="text-xs text-cu-muted">Similarity threshold</p>
                <p class="text-lg font-semibold text-cu-text">≥ 0.85</p>
            </div>
        </div>
    </div>
@endunless
