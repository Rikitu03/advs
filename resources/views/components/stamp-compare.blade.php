@props(['data'])

@php
    $detected = $data['detected'] ?? false;
    $comparisons = $data['comparisons'] ?? [];
@endphp

@if ($comparisons === [])
    <div class="flex items-start gap-3 rounded-xl border border-amber-400/20 bg-amber-400/5 p-4">
        <flux:icon icon="exclamation-triangle" class="size-5 shrink-0 text-amber-700 dark:text-amber-300" />
        <p class="text-sm text-cu-text">
            No stamp/logo comparison results are available for this document. A missing-component penalty was applied to the risk score.
        </p>
    </div>
@else
    <div class="flex flex-col gap-4">
        @foreach ($comparisons as $index => $comparison)
            @php
                $comparisonPass = $comparison['pass'] ?? false;
                $comparisonInk = $comparisonPass ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300';
                $reference = $comparison['references'][0] ?? null;
            @endphp
            <div class="rounded-xl border {{ $comparisonPass ? 'border-emerald-500/40' : 'border-rose-500/40' }} bg-cu-surface p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-cu-muted">Stamp/logo {{ $index + 1 }}</span>
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
                                <x-detection-crop :url="$comparison['crop']['url']" :box="$comparison['crop']['box']" label="Detected stamp/logo" />
                            @else
                                <span class="text-xs text-cu-muted">Preview unavailable for this document</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-medium text-cu-muted">Reference (issuer)</span>
                            <flux:icon icon="check-badge" class="size-4 text-cu-blue" />
                        </div>
                        <div @if ($reference) data-issuer-reference-card="{{ $reference['key'] }}" @endif class="flex min-h-24 items-center justify-center rounded-lg bg-black/[0.03] text-cu-muted dark:bg-white/[0.03]">
                            @if ($reference)
                                <img src="{{ $reference['url'] }}" alt="{{ $reference['label'] }}" class="max-h-32 max-w-full object-contain" />
                            @else
                                <span class="text-xs">No issuer reference available</span>
                            @endif
                        </div>
                        @if ($reference)
                            <p class="mt-2 text-center text-xs text-cu-muted">{{ $reference['label'] }}</p>
                        @endif
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]">
                        <p class="text-xs text-cu-muted">Similarity</p>
                        <p class="text-lg font-semibold {{ $comparisonInk }}">{{ $comparison['similarity'] ?? '—' }}%</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]">
                        <p class="text-xs text-cu-muted">Cosine similarity</p>
                        <p class="text-lg font-semibold text-cu-text">{{ $comparison['cosine'] ?? '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-black/[0.03] px-3 py-2 dark:bg-white/[0.03]">
                        <p class="text-xs text-cu-muted">Similarity threshold</p>
                        <p class="text-lg font-semibold text-cu-text">&ge; {{ $comparison['similarity_threshold'] ?? ($data['similarity_threshold'] ?? 85) }}%</p>
                    </div>
                </div>

                @if ($comparison['texture_checked'] ?? false)
                    <div class="mt-4 flex items-start gap-3 rounded-xl border p-4 {{ ($comparison['scan_copy_texture'] ?? false) ? 'border-amber-400/25 bg-amber-400/5' : 'border-emerald-500/25 bg-emerald-500/5' }}">
                        <flux:icon :icon="($comparison['scan_copy_texture'] ?? false) ? 'document-duplicate' : 'check-circle'" class="size-5 shrink-0 {{ ($comparison['scan_copy_texture'] ?? false) ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300' }}" />
                        <p class="text-sm text-cu-muted">
                            {{ ($comparison['scan_copy_texture'] ?? false) ? 'Scan/copy texture detected.' : 'Wet-ink-like texture detected.' }}
                        </p>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif

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
