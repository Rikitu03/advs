@props(['data'])

@php
    $detected = $data['detected'] ?? false;
    $verified = $data['verified'] ?? false;
    $pass = $data['pass'] ?? false;
    $references = $data['references'] ?? [];
    $queryRing = $verified
        ? ($pass ? 'border-emerald-500/40' : 'border-rose-500/40')
        : 'border-cu-border';
    $queryInk = $verified
        ? ($pass ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300')
        : 'text-cu-text';
@endphp

@if (! $detected)
    <div class="flex items-start gap-3 rounded-xl border border-amber-400/20 bg-amber-400/5 p-4">
        <flux:icon icon="exclamation-triangle" class="size-5 shrink-0 text-amber-700 dark:text-amber-300" />
        <p class="text-sm text-cu-text">
            No stamp region was detected by YOLOv8, so no comparison could be run. A missing-component penalty was applied to the risk score.
        </p>
    </div>
@else
    <div class="flex flex-col gap-4">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {{-- Query --}}
            <div class="flex min-w-0 flex-col rounded-xl border {{ $queryRing }} bg-cu-surface p-4">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <span class="text-xs font-medium text-cu-muted">Query (this submission)</span>
                    @if ($verified)
                        <x-pass-fail :pass="$pass" />
                    @else
                        <flux:badge size="sm" color="zinc">Unverified</flux:badge>
                    @endif
                </div>
                <div class="flex aspect-[4/3] min-h-48 items-center justify-center overflow-hidden rounded-lg bg-black/[0.03] p-3 dark:bg-white/[0.03] {{ $queryInk }}">
                    @if ($data['crop'] ?? null)
                        <x-detection-crop :url="$data['crop']['url']" :box="$data['crop']['box']" label="Detected stamp/logo" />
                    @else
                        <span class="text-xs text-cu-muted">Preview unavailable for this document</span>
                    @endif
                </div>
            </div>

            @if ($references === [])
                <div class="flex min-w-0 flex-col rounded-xl border border-cu-border bg-cu-surface p-4">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <span class="text-xs font-medium text-cu-muted">Issuer references</span>
                        <flux:icon icon="check-badge" class="size-4 text-cu-blue" />
                    </div>
                    <div class="flex aspect-[4/3] min-h-48 items-center justify-center rounded-lg bg-black/[0.03] px-4 text-center dark:bg-white/[0.03]">
                        <span class="text-xs text-cu-muted">No references currently available</span>
                    </div>
                </div>
            @else
                @foreach ($references as $reference)
                    <figure data-issuer-reference-card="{{ $reference['key'] }}"
                            class="flex min-w-0 flex-col rounded-xl border border-cu-border bg-cu-surface p-4">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <span class="text-xs font-medium text-cu-muted">
                                {{ $reference['source'] === 'curated' ? 'Reference (curated)' : 'Reference (enrolled)' }}
                            </span>
                            @if ($reference['best'])
                                <flux:badge size="sm" color="blue" icon="check-badge">Best match</flux:badge>
                            @else
                                <flux:icon icon="check-badge" class="size-4 text-cu-blue" />
                            @endif
                        </div>
                        <div class="flex aspect-[4/3] min-h-48 items-center justify-center overflow-hidden rounded-lg bg-black/[0.03] p-3 dark:bg-white/[0.03]">
                            <img src="{{ $reference['url'] }}"
                                 alt="{{ $reference['label'] }}"
                                 class="h-full w-full object-contain" />
                        </div>
                        <figcaption class="mt-3 min-w-0 text-center">
                            <p class="text-sm font-medium text-cu-text">{{ $reference['label'] }}</p>
                            <p class="mt-1 text-xs text-cu-muted">
                                @if ($reference['similarity'] !== null)
                                    {{ $reference['similarity'] }}% similarity
                                @else
                                    Not evaluated in this run
                                @endif
                            </p>
                        </figcaption>
                    </figure>
                @endforeach
            @endif
        </div>

        @if ($verified)
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
                    <p class="text-lg font-semibold text-cu-text">&ge; {{ $data['similarity_threshold'] ?? 85 }}%</p>
                </div>
            </div>
        @endif
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
