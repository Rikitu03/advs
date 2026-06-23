<?php

use App\Support\VendorDemoData;
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
        $rows = VendorDemoData::submissions()
            ->when($this->filter !== 'all', fn ($items) => $items->where('status', $this->filter))
            ->filter(function (array $submission): bool {
                $search = mb_strtolower(trim($this->search));

                if ($search === '') {
                    return true;
                }

                return str_contains(mb_strtolower("{$submission['ref']} {$submission['document_type']} {$submission['file_name']} {$submission['status']}"), $search);
            })
            ->values();

        return [
            'rows' => $rows,
            'total' => VendorDemoData::submissions()->count(),
        ];
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

        <div class="cu-animate-in rounded-2xl border border-cu-border bg-cu-surface p-4 shadow-sm" style="animation-delay: 80ms">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 p-1">
                    @foreach (['all' => 'All', 'Processing' => 'Processing', 'Pending Review' => 'Pending Review', 'Approved' => 'Approved', 'Rejected' => 'Rejected'] as $value => $label)
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
                                <p class="truncate text-sm font-medium text-cu-text">{{ $submission['document_type'] }}</p>
                                <p class="truncate text-xs text-cu-muted">{{ $submission['ref'] }} - {{ $submission['file_name'] }}</p>
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
                            <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-rose-500/40 bg-cu-surface px-3 py-1.5 text-sm font-medium text-rose-700 dark:text-rose-300 transition hover:bg-rose-500/15">
                                <flux:icon icon="arrow-uturn-left" class="size-4" />
                                Unsubmit
                            </button>
                        </div>

                        <flux:modal name="submission-{{ $submission['id'] }}" class="md:w-[32rem]">
                            <div class="space-y-5">
                                <div>
                                    <flux:heading size="lg">{{ $submission['ref'] }}</flux:heading>
                                    <flux:subheading>{{ $submission['document_type'] }} - {{ $submission['file_name'] }}</flux:subheading>
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
                                    <p class="text-sm font-semibold">Included file</p>
                                    <p class="mt-1 text-sm text-cu-muted">{{ $submission['file_name'] }} - {{ $submission['size'] }}</p>
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
