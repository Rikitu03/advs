@props(['data'])

@php
    $detected = $data['detected'] ?? false;
    $verified = $data['verified'] ?? false;
    $pass = $data['pass'] ?? false;
    $comparisons = $data['comparisons'] ?? [];
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
        @foreach ($comparisons as $index => $comparison)
            @php
                $comparisonPass = $comparison['pass'] ?? false;
                $comparisonInk = $comparisonPass ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300';
            @endphp
            <div class="rounded-xl border {{ $comparisonPass ? 'border-emerald-500/40' : 'border-rose-500/40' }} bg-cu-surface p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-cu-muted">Signature {{ $index + 1 }}</span>
                        @if ($comparison['page_index'] ?? null)
                            <span class="text-xs text-cu-muted">Page {{ $comparison['page_index'] }}</span>
                        @endif
                        @if (($comparison['confidence'] ?? null) !== null)
                            <span class="text-xs text-cu-muted">{{ round((float) $comparison['confidence'] * 100) }}% detected</span>
                        @endif
                    </div>
                    <x-pass-fail :pass="$comparisonPass" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <div class="mb-2 text-xs font-medium text-cu-muted">Query (this submission)</div>
                        <div class="flex min-h-24 items-center justify-center rounded-lg bg-black/[0.03] p-2 dark:bg-white/[0.03] {{ $comparisonInk }}">
                            @if ($comparison['crop'] ?? null)
                                <x-detection-crop :url="$comparison['crop']['url']" :box="$comparison['crop']['box']" label="Detected signature" />
                            @else
                                <span class="text-xs text-cu-muted">Not available for this document</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-medium text-cu-muted">Reference (enrolled)</span>
                            <flux:icon icon="check-badge" class="size-4 text-cu-blue" />
                        </div>
                        <div class="flex min-h-24 items-center justify-center rounded-lg bg-black/[0.03] text-cu-muted dark:bg-white/[0.03]">
                            @if ($comparison['reference_image_url'] ?? null)
                                <img src="{{ $comparison['reference_image_url'] }}" alt="Enrolled signature reference" class="max-h-24 max-w-full object-contain" />
                            @else
                                <span class="text-xs">Reference image unavailable</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]">
                        <p class="text-xs text-cu-muted">Similarity</p>
                        <p class="text-lg font-semibold {{ $comparisonInk }}">{{ $comparison['similarity'] ?? '—' }}%</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]">
                        <p class="text-xs text-cu-muted">Embedding distance (128-D)</p>
                        <p class="text-lg font-semibold text-cu-text">{{ $comparison['distance'] ?? '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]">
                        <p class="text-xs text-cu-muted">Distance threshold</p>
                        <p class="text-lg font-semibold text-cu-text">≤ {{ $comparison['distance_threshold'] ?? 'empirical' }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endunless
