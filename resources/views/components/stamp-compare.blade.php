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
            <x-detection-crop :url="$data['crop']['url']" :box="$data['crop']['box']" label="Detected stamp/logo" />
        @endif
        <div class="flex items-start gap-3">
            <flux:icon icon="exclamation-triangle" class="size-5 shrink-0 text-amber-700 dark:text-amber-300" />
            <p class="text-sm text-cu-text">
                @if ($detected)
                    A stamp/logo region was detected, but no reference logo is on file for this issuer yet, so no comparison could be run. A missing-component penalty was applied to the risk score.
                @else
                    No stamp region was detected by YOLOv8, so no comparison could be run. A missing-component penalty was applied to the risk score.
                @endif
            </p>
        </div>
    </div>
@else
    <div class="flex flex-col gap-4">
        <div class="grid gap-4 sm:grid-cols-2">
            {{-- Reference --}}
            <div class="rounded-xl border border-cu-border bg-cu-surface p-4">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-medium text-cu-muted">Issuer reference logo</span>
                    <flux:icon icon="check-badge" class="size-4 text-cu-blue" />
                </div>
                <div class="flex h-24 items-center justify-center rounded-lg bg-black/[0.03] dark:bg-white/[0.03] text-cu-blue">
                    @if ($data['reference_image_url'] ?? null)
                        <img src="{{ $data['reference_image_url'] }}" alt="Issuer reference logo" class="max-h-full max-w-full object-contain" />
                    @else
                        <span class="text-xs text-cu-muted">Reference image unavailable</span>
                    @endif
                </div>
            </div>

            {{-- Query --}}
            <div class="rounded-xl border {{ $queryRing }} bg-cu-surface p-4">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-medium text-cu-muted">Query (this submission)</span>
                    <x-pass-fail :pass="$pass" />
                </div>
                <div class="flex min-h-24 items-center justify-center rounded-lg bg-black/[0.03] p-2 dark:bg-white/[0.03] {{ $queryInk }}">
                    @if ($data['crop'] ?? null)
                        <x-detection-crop :url="$data['crop']['url']" :box="$data['crop']['box']" label="Detected stamp/logo" />
                    @else
                        <span class="text-xs text-cu-muted">Not available for this document</span>
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
                <p class="text-xs text-cu-muted">Cosine similarity</p>
                <p class="text-lg font-semibold text-cu-text">{{ $data['cosine'] }}</p>
            </div>
            <div class="rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]">
                <p class="text-xs text-cu-muted">Similarity threshold</p>
                <p class="text-lg font-semibold text-cu-text">≥ {{ $data['similarity_threshold'] ?? 85 }}%</p>
            </div>
        </div>
    </div>
@endunless

@if ($data['texture_checked'] ?? false)
    <div class="mt-4 flex items-start gap-3 rounded-xl border p-4 {{ ($data['scan_copy_texture'] ?? false) ? 'border-amber-400/25 bg-amber-400/5' : 'border-emerald-500/25 bg-emerald-500/5' }}">
        <flux:icon
            :icon="($data['scan_copy_texture'] ?? false) ? 'document-duplicate' : 'check-circle'"
            class="size-5 shrink-0 {{ ($data['scan_copy_texture'] ?? false) ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300' }}"
        />
        <div class="flex flex-col gap-1">
            <p class="text-sm font-medium text-cu-text">
                {{ ($data['scan_copy_texture'] ?? false) ? 'Scan/copy texture detected' : 'Wet-ink-like texture detected' }}
            </p>
            <p class="text-sm text-cu-muted">
                @if ($data['scan_copy_texture'] ?? false)
                    The stamp texture resembles a scanned, photocopied, or digital reproduction. This is separate from issuer-logo identity matching and does not mean the stamp artwork was altered.
                @else
                    The stamp texture resembles a wet-ink impression. This texture result is separate from issuer-logo identity matching.
                @endif
            </p>
        </div>
    </div>
@endif
