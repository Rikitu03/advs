<?php

use App\Models\Submission;
use App\Support\VendorDemoData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component
{
    public string $filter = 'all';

    public string $search = '';

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $vendor = Auth::user()->vendor;
        $typeNames = DB::table('document_types')->pluck('name', 'id');

        $realRows = $vendor
            ? Submission::query()
                ->with('documents')
                ->where('vendor_id', $vendor->id)
                ->latest()
                ->get()
                ->map(fn (Submission $submission): array => $this->row($submission, $typeNames))
            : collect();

        $sourceRows = $realRows->isNotEmpty() ? $realRows : $this->fallbackRows();

        $rows = $sourceRows
            ->when($this->filter !== 'all', fn (Collection $items): Collection => $items->where('status_key', $this->filter))
            ->filter(function (array $submission): bool {
                $search = mb_strtolower(trim($this->search));

                if ($search === '') {
                    return true;
                }

                return str_contains(mb_strtolower("{$submission['ref']} {$submission['document_summary']} {$submission['file_summary']} {$submission['status']}"), $search);
            })
            ->values();

        return [
            'rows' => $rows,
            'total' => $sourceRows->count(),
        ];
    }

    /**
     * @param  Collection<int, string>  $typeNames
     * @return array<string, mixed>
     */
    private function row(Submission $submission, Collection $typeNames): array
    {
        $status = $this->statusLabel($submission->status);
        $documents = $submission->documents;

        return [
            'id' => $submission->id,
            'ref' => 'SUB-'.str_pad((string) $submission->id, 5, '0', STR_PAD_LEFT),
            'document_summary' => 'Document Submission #'.$submission->id,
            'file_summary' => $documents->count().' '.($documents->count() === 1 ? 'file' : 'files'),
            'submitted_at' => $submission->created_at,
            'status_key' => $submission->status,
            'status' => $status,
            'progress' => $this->progressFor($submission->status),
            'note' => $this->noteFor($submission->status),
            'documents' => $documents->map(fn ($document): array => [
                'id' => $document->id,
                'type' => $typeNames[$document->document_type_id] ?? 'Unassigned document',
                'file_name' => $document->original_filename,
                'size' => $this->formatBytes((int) $document->file_size_bytes),
                'path' => $document->file_path,
                'url' => route('vendor.documents.show', $document),
                'processing_status' => str($document->processing_status)->headline()->toString(),
                'kind' => match (true) {
                    str_starts_with((string) $document->mime_type, 'image/') => 'image',
                    $document->mime_type === 'application/pdf' => 'pdf',
                    default => 'file',
                },
            ])->values(),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Submission::STATUS_PROCESSING => 'Processing',
            Submission::STATUS_PENDING_REVIEW => 'Pending Review',
            Submission::STATUS_APPROVED => 'Approved',
            Submission::STATUS_RESUBMISSION_REQUESTED => 'Resubmission Requested',
            default => str($status)->headline()->toString(),
        };
    }

    private function progressFor(string $status): int
    {
        return match ($status) {
            Submission::STATUS_PROCESSING => 35,
            Submission::STATUS_PENDING_REVIEW => 70,
            Submission::STATUS_APPROVED, Submission::STATUS_RESUBMISSION_REQUESTED => 100,
            default => 0,
        };
    }

    private function statusKeyFor(string $status): string
    {
        return match ($status) {
            'Processing' => Submission::STATUS_PROCESSING,
            'Pending Review' => Submission::STATUS_PENDING_REVIEW,
            'Approved' => Submission::STATUS_APPROVED,
            'Resubmission Requested' => Submission::STATUS_RESUBMISSION_REQUESTED,
            default => 'demo',
        };
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fallbackRows(): Collection
    {
        return VendorDemoData::submissions()
            ->map(function (array $submission): array {
                $documents = collect($submission['documents']);

                return [
                    'id' => 'demo-'.$submission['id'],
                    'ref' => $submission['ref'],
                    'document_summary' => 'Document Submission #'.$submission['id'],
                    'file_summary' => $documents->count().' '.($documents->count() === 1 ? 'file' : 'files'),
                    'submitted_at' => $submission['submitted_at'],
                    'status_key' => $this->statusKeyFor($submission['status']),
                    'status' => $submission['status'],
                    'progress' => $this->progressFor($this->statusKeyFor($submission['status'])),
                    'note' => $submission['note'],
                    'documents' => $documents->values()->map(fn (array $document, int $index): array => [
                        'id' => $submission['id'].'-'.($index + 1),
                        'type' => $document['type'],
                        'file_name' => $document['file_name'],
                        'size' => $document['size'],
                        'path' => null,
                        'url' => null,
                        'processing_status' => $submission['status'],
                        'kind' => match (pathinfo($document['file_name'], PATHINFO_EXTENSION)) {
                            'png', 'jpg', 'jpeg' => 'image',
                            'pdf' => 'pdf',
                            default => 'file',
                        },
                    ]),
                ];
            });
    }

    private function noteFor(string $status): string
    {
        return match ($status) {
            Submission::STATUS_PROCESSING => 'Your documents are queued for automated validation.',
            Submission::STATUS_PENDING_REVIEW => 'Your completed submission is waiting for compliance officer review.',
            Submission::STATUS_APPROVED => 'Your submission has been approved.',
            Submission::STATUS_RESUBMISSION_REQUESTED => 'Resubmission requested. Review the officer comments, correct the flagged documents, and submit again.',
            default => 'Submission status is being updated.',
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1024 / 1024, 2).' MB';
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
        <div class="cu-animate-in">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-cu-text">My Submissions</h1>
                    <p class="mt-1 text-sm text-cu-muted">Track your accreditation documents and review status history.</p>
                </div>
                <a href="{{ route('vendor.submit') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl cu-gradient px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90">
                    <flux:icon icon="arrow-up-tray" class="size-4" />
                    New submission
                </a>
            </div>
        </div>

        @if (session('status'))
            <div class="cu-animate-in rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm font-medium text-emerald-700 dark:text-emerald-300">
                {{ session('status') }}
            </div>
        @endif

        <div class="cu-animate-in rounded-2xl border border-cu-border bg-cu-surface p-4 shadow-sm" style="animation-delay: 80ms">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 p-1">
                    @foreach (['all' => 'All', 'processing' => 'Processing', 'pending_review' => 'Pending Review', 'approved' => 'Approved', 'resubmission_requested' => 'Resubmission Requested'] as $value => $label)
                        <button type="button" wire:click="setFilter('{{ $value }}')"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $filter === $value ? 'bg-cu-purple text-white shadow-sm' : 'text-cu-muted hover:bg-black/5 dark:hover:bg-white/5 hover:text-cu-text' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                <div class="relative min-w-0 lg:w-80">
                    <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-cu-muted" />
                    <input wire:model.live.debounce.200ms="search" type="search" placeholder="Search submissions"
                           class="h-10 w-full rounded-xl border border-cu-border bg-cu-surface pl-9 pr-3 text-sm text-cu-text outline-none transition placeholder:text-cu-muted focus:border-cu-purple focus:ring-2 focus:ring-cu-purple/20">
                </div>
            </div>
        </div>

        <div class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface shadow-sm" style="animation-delay: 140ms">
            <div class="hidden grid-cols-[1.1fr_.6fr_.7fr_.7fr_.7fr] gap-4 border-b border-cu-border bg-black/5 dark:bg-white/5 px-5 py-3 text-xs font-medium uppercase tracking-wide text-cu-muted lg:grid">
                <span>Document</span>
                <span>Date</span>
                <span>Status</span>
                <span>Progress</span>
                <span class="text-right">Action</span>
            </div>

            <div class="divide-y divide-cu-border" wire:loading.class="opacity-40">
                @forelse ($rows as $submission)
                    <div wire:key="vendor-submission-{{ $submission['id'] }}" x-data="{ open: false }">
                        {{-- Batch row --}}
                        <div
                            @click="open = !open"
                            class="grid cursor-pointer gap-4 px-5 py-4 transition hover:bg-black/5 dark:hover:bg-white/5 lg:grid-cols-[1.1fr_.6fr_.7fr_.7fr_.7fr] lg:items-center"
                        >
                            <div class="flex min-w-0 gap-3">
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-cu-blue/10 text-sky-700">
                                    <flux:icon icon="document-text" class="size-5" />
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-cu-text">{{ $submission['document_summary'] ?: 'Submission batch' }}</p>
                                    <p class="truncate text-xs text-cu-muted">{{ $submission['ref'] }} - {{ $submission['file_summary'] }}</p>
                                </div>
                            </div>
                            <div class="text-sm text-cu-muted">
                                <p>{{ $submission['submitted_at']->format('M j, Y') }}</p>
                            </div>
                            <div>
                                <x-vendor-status-badge :status="$submission['status']" />
                            </div>
                            <div class="min-w-0">
                                <div class="h-2 rounded-full bg-cu-border">
                                    <div class="h-2 rounded-full cu-gradient" style="width: {{ $submission['progress'] }}%"></div>
                                </div>
                                <p class="mt-1 text-xs text-cu-muted">{{ $submission['progress'] }}%</p>
                            </div>
                            <div class="flex flex-wrap justify-start gap-2 lg:justify-end">
                                <button
                                    type="button"
                                    @click.stop="open = !open"
                                    :aria-expanded="open"
                                    aria-label="Toggle submission details"
                                    class="inline-flex items-center gap-2 rounded-lg border border-cu-border bg-cu-surface px-3 py-1.5 text-sm font-medium text-cu-text transition hover:border-cu-blue hover:bg-cu-blue/10 hover:text-sky-700"
                                >
                                    <flux:icon icon="eye" class="size-4" />
                                    Details
                                    <flux:icon icon="chevron-down" class="size-3.5 transition-transform duration-200" ::class="open && 'rotate-180'" />
                                </button>
                            </div>
                        </div>

                        {{-- Batch detail panel: submission summary + document light table --}}
                        <div x-show="open" x-collapse x-cloak class="border-t border-cu-border/60 bg-black/[0.02] px-5 py-5 dark:bg-white/[0.02]">
                            <div class="flex flex-col gap-4">
                                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-cu-muted">
                                    <span class="inline-flex items-center gap-1.5">
                                        <flux:icon icon="calendar" class="size-4" />
                                        Submitted {{ $submission['submitted_at']->toDayDateTimeString() }}
                                    </span>
                                    <span class="inline-flex items-center gap-1.5">
                                        <flux:icon icon="paper-clip" class="size-4" />
                                        {{ $submission['file_summary'] }}
                                    </span>
                                    <span class="text-cu-text">{{ $submission['note'] }}</span>
                                </div>

                                <div>
                                    <p class="text-sm font-semibold text-cu-text">Included files</p>
                                    <div class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                        @foreach ($submission['documents'] as $document)
                                            @php
                                                $statusChip = match ($document['processing_status']) {
                                                    'Completed' => 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
                                                    'Failed' => 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
                                                    default => 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
                                                };
                                            @endphp
                                            @if ($document['url'])
                                                <a href="{{ $document['url'] }}" target="_blank" rel="noopener"
                                                   class="group block overflow-hidden rounded-xl border border-cu-border bg-cu-surface shadow-sm transition hover:-translate-y-0.5 hover:border-cu-purple/50 hover:shadow-md">
                                                    <div class="relative h-44 overflow-hidden bg-black/5 dark:bg-white/5">
                                                        @if ($document['kind'] === 'image')
                                                            <img
                                                                src="{{ $document['url'] }}"
                                                                alt="Preview of {{ $document['file_name'] }}"
                                                                loading="lazy"
                                                                class="h-full w-full object-cover object-top"
                                                            >
                                                        @elseif ($document['kind'] === 'pdf')
                                                            {{-- Stamped into the DOM only when the panel opens, so hidden
                                                                 rows never download their PDFs. First page only; the frame
                                                                 crops the viewer chrome. --}}
                                                            <template x-if="open">
                                                                <object
                                                                    data="{{ $document['url'] }}#page=1&toolbar=0&navpanes=0&scrollbar=0&view=FitH"
                                                                    type="application/pdf"
                                                                    class="pointer-events-none h-[calc(100%+3rem)] w-full"
                                                                    aria-hidden="true"
                                                                    tabindex="-1"
                                                                >
                                                                    <div class="flex h-44 flex-col items-center justify-center gap-2 text-cu-muted">
                                                                        <flux:icon icon="document-text" class="size-8" />
                                                                        <span class="text-xs">PDF preview unavailable</span>
                                                                    </div>
                                                                </object>
                                                            </template>
                                                        @else
                                                            <div class="flex h-full flex-col items-center justify-center gap-2 text-cu-muted">
                                                                <flux:icon icon="document" class="size-8" />
                                                                <span class="text-xs">No preview</span>
                                                            </div>
                                                        @endif
                                                        <span class="absolute right-2 top-2 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $statusChip }}">
                                                            {{ $document['processing_status'] }}
                                                        </span>
                                                        <span class="absolute inset-x-0 bottom-0 flex items-center justify-center gap-1.5 bg-gradient-to-t from-black/60 to-transparent py-2 text-xs font-medium text-white opacity-0 transition group-hover:opacity-100">
                                                            Open file
                                                            <flux:icon icon="arrow-up-right" class="size-3" />
                                                        </span>
                                                    </div>
                                                    <div class="border-t border-cu-border px-3 py-2.5">
                                                        <p class="truncate text-sm font-medium text-cu-text">{{ $document['type'] }}</p>
                                                        <p class="truncate text-xs text-cu-muted">{{ $document['file_name'] }} - {{ $document['size'] }}</p>
                                                    </div>
                                                </a>
                                            @else
                                                <div class="overflow-hidden rounded-xl border border-cu-border bg-cu-surface shadow-sm">
                                                    <div class="relative flex h-44 flex-col items-center justify-center gap-2 bg-black/5 text-cu-muted dark:bg-white/5">
                                                        <flux:icon icon="document-text" class="size-8" />
                                                        <span class="text-xs">Sample submission</span>
                                                        <span class="absolute right-2 top-2 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $statusChip }}">
                                                            {{ $document['processing_status'] }}
                                                        </span>
                                                    </div>
                                                    <div class="border-t border-cu-border px-3 py-2.5">
                                                        <p class="truncate text-sm font-medium text-cu-text">{{ $document['type'] }}</p>
                                                        <p class="truncate text-xs text-cu-muted">{{ $document['file_name'] }} - {{ $document['size'] }}</p>
                                                    </div>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                        <flux:icon icon="folder-open" class="size-8 text-cu-muted" />
                        <p class="text-sm font-medium text-cu-text">No submissions match your filters</p>
                        <p class="text-xs text-cu-muted">Try changing the status filter or search term.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <p class="text-center text-xs text-cu-muted">Showing {{ $rows->count() }} of {{ $total }} submissions.</p>
    </div>
</x-page>
