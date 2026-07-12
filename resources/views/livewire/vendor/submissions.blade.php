<?php

use App\Models\Submission;
use App\Support\VendorDemoData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component {
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
            ])->values(),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Submission::STATUS_PROCESSING => 'Processing',
            Submission::STATUS_PENDING_REVIEW => 'Pending Review',
            Submission::STATUS_APPROVED => 'Approved',
            Submission::STATUS_REJECTED => 'Rejected',
            default => str($status)->headline()->toString(),
        };
    }

    private function progressFor(string $status): int
    {
        return match ($status) {
            Submission::STATUS_PROCESSING => 35,
            Submission::STATUS_PENDING_REVIEW => 70,
            Submission::STATUS_APPROVED, Submission::STATUS_REJECTED => 100,
            default => 0,
        };
    }

    private function statusKeyFor(string $status): string
    {
        return match ($status) {
            'Processing' => Submission::STATUS_PROCESSING,
            'Pending Review' => Submission::STATUS_PENDING_REVIEW,
            'Approved' => Submission::STATUS_APPROVED,
            'Rejected' => Submission::STATUS_REJECTED,
            default => 'demo',
        };
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fallbackRows(): Collection
    {
        return VendorDemoData::submissions()
            ->map(fn (array $submission): array => [
                'id' => 'demo-'.$submission['id'],
                'ref' => $submission['ref'],
                'document_summary' => 'Document Submission #'.$submission['id'],
                'file_summary' => '1 file',
                'submitted_at' => $submission['submitted_at'],
                'status_key' => $this->statusKeyFor($submission['status']),
                'status' => $submission['status'],
                'progress' => $this->progressFor($this->statusKeyFor($submission['status'])),
                'note' => $submission['note'],
                'documents' => [[
                    'id' => $submission['id'],
                    'type' => $submission['document_type'],
                    'file_name' => $submission['file_name'],
                    'size' => $submission['size'],
                    'path' => null,
                    'url' => null,
                    'processing_status' => $submission['status'],
                ]],
            ]);
    }

    private function noteFor(string $status): string
    {
        return match ($status) {
            Submission::STATUS_PROCESSING => 'Your documents are queued for automated validation.',
            Submission::STATUS_PENDING_REVIEW => 'Your completed submission is waiting for compliance officer review.',
            Submission::STATUS_APPROVED => 'Your submission has been approved.',
            Submission::STATUS_REJECTED => 'Your submission was rejected. Review the officer comments before submitting again.',
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
                    @foreach (['all' => 'All', 'processing' => 'Processing', 'pending_review' => 'Pending Review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label)
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
                    <div wire:key="vendor-submission-{{ $submission['id'] }}" class="grid gap-4 px-5 py-4 transition hover:bg-black/5 dark:hover:bg-white/5 lg:grid-cols-[1.1fr_.6fr_.7fr_.7fr_.7fr] lg:items-center">
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
                            <flux:modal.trigger name="submission-{{ $submission['id'] }}">
                                <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-cu-border bg-cu-surface px-3 py-1.5 text-sm font-medium text-cu-text transition hover:border-cu-blue hover:bg-cu-blue/10 hover:text-sky-700">
                                    <flux:icon icon="eye" class="size-4" />
                                    Details
                                </button>
                            </flux:modal.trigger>
                        </div>

                        <flux:modal name="submission-{{ $submission['id'] }}" class="md:w-[32rem]">
                            <div class="space-y-5">
                                <div>
                                    <flux:heading size="lg">{{ $submission['ref'] }}</flux:heading>
                                    <flux:subheading>{{ $submission['document_summary'] ?: 'Submission batch' }}</flux:subheading>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-xl border border-cu-border p-4">
                                        <p class="text-xs text-cu-muted">Status</p>
                                        <p class="mt-1 text-sm font-semibold">{{ $submission['status'] }}</p>
                                    </div>
                                    <div class="rounded-xl border border-cu-border p-4">
                                        <p class="text-xs text-cu-muted">Submitted</p>
                                        <p class="mt-1 text-sm font-semibold">{{ $submission['submitted_at']->toDayDateTimeString() }}</p>
                                    </div>
                                </div>
                                <p class="text-sm text-cu-muted">{{ $submission['note'] }}</p>
                                <div class="rounded-xl border border-cu-border p-4">
                                    <p class="text-sm font-semibold">Included files</p>
                                    <div class="mt-2 flex flex-col gap-2">
                                        @foreach ($submission['documents'] as $document)
                                            <div class="rounded-lg bg-black/5 px-3 py-2 text-sm dark:bg-white/5">
                                                @if ($document['url'])
                                                    <a href="{{ $document['url'] }}" target="_blank" rel="noopener" class="block rounded-lg transition hover:text-sky-700 dark:hover:text-sky-300">
                                                        <p class="font-medium text-cu-text">{{ $document['type'] }}</p>
                                                        <p class="text-xs text-cu-muted">{{ $document['file_name'] }} - {{ $document['size'] }} - {{ $document['processing_status'] }}</p>
                                                    </a>
                                                @else
                                                    <p class="font-medium text-cu-text">{{ $document['type'] }}</p>
                                                    <p class="text-xs text-cu-muted">{{ $document['file_name'] }} - {{ $document['size'] }} - {{ $document['processing_status'] }}</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </flux:modal>
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
