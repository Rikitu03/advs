@props(['data'])

@php
    $detected = $data['detected'] ?? false;
    $verified = $data['verified'] ?? false;
    $pass = $data['pass'] ?? false;
    $queryRing = $pass ? 'border-emerald-500/40' : 'border-rose-500/40';
    $queryInk = $pass ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300';
@endphp

@unless ($verified)
    <div class="flex flex-col gap-4 rounded-xl border border-amber-400/20 bg-amber-400/5 p-4 sm:flex-row sm:items-start">
        @if ($detected && ($data['crop'] ?? null))
            <x-detection-crop :url="$data['crop']['url']" :box="$data['crop']['box']" label="Detected signature" />
        @endif
        <div class="flex items-start gap-3">
            <flux:icon icon="exclamation-triangle" class="size-5 shrink-0 text-amber-700 dark:text-amber-300" />
            <p class="text-sm text-cu-text">
                @if ($detected)
                    A signature region was detected, but no reference is enrolled for this vendor yet, so no comparison could be run. A missing-component penalty was applied.
                @else
                    No signature region was detected by YOLOv8, so no comparison could be run. A missing-component penalty was applied.
                @endif
            </p>
        </div>
    </div>
@else
    <div class="flex flex-col gap-4">
        <div class="grid gap-4 sm:grid-cols-2">
            {{-- Query --}}
            <div class="rounded-xl border {{ $queryRing }} bg-cu-surface p-4">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-medium text-cu-muted">Query (this submission)</span>
                    <x-pass-fail :pass="$pass" />
                </div>
                <div class="flex min-h-24 items-center justify-center rounded-lg bg-black/[0.03] p-2 dark:bg-white/[0.03] {{ $queryInk }}">
                    @if ($data['crop'] ?? null)
                        <x-detection-crop :url="$data['crop']['url']" :box="$data['crop']['box']" label="Detected signature" />
                    @else
                        <span class="text-xs text-cu-muted">Not available for this document</span>
                    @endif
                </div>
            </div>

            {{-- Reference --}}
            <div class="rounded-xl border border-cu-border bg-cu-surface p-4">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-medium text-cu-muted">Reference (enrolled)</span>
                    <flux:icon icon="check-badge" class="size-4 text-cu-blue" />
                </div>
                <div class="flex h-24 items-center justify-center rounded-lg bg-black/[0.03] text-cu-muted dark:bg-white/[0.03]">
                    @if ($data['reference_image_url'] ?? null)
                        <img src="{{ $data['reference_image_url'] }}" alt="Enrolled signature reference" class="max-h-full max-w-full object-contain" />
                    @else
                        <span class="text-xs">Reference image unavailable</span>
                    @endif
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
