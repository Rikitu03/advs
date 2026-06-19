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

<div class="-m-6 min-h-svh bg-zinc-50 p-6 text-zinc-950 lg:-m-8 lg:p-8">
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
        <div class="cu-animate-in">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-zinc-950">My Submissions</h1>
                    <p class="mt-1 text-sm text-zinc-500">Track your accreditation documents and review status history.</p>
                </div>
                <a href="{{ route('vendor.submit') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl cu-gradient px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90">
                    <flux:icon icon="arrow-up-tray" class="size-4" />
                    New submission
                </a>
            </div>
        </div>

        <div class="cu-animate-in rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm" style="animation-delay: 80ms">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-1 rounded-xl border border-zinc-200 bg-zinc-50 p-1">
                    @foreach (['all' => 'All', 'Processing' => 'Processing', 'Pending Review' => 'Pending Review', 'Approved' => 'Approved', 'Rejected' => 'Rejected'] as $value => $label)
                        <button type="button" wire:click="setFilter('{{ $value }}')"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $filter === $value ? 'bg-cu-purple text-white shadow-sm' : 'text-zinc-600 hover:bg-white hover:text-zinc-950' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                <div class="relative min-w-0 lg:w-80">
                    <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                    <input wire:model.live.debounce.200ms="search" type="search" placeholder="Search submissions"
                           class="h-10 w-full rounded-xl border border-zinc-200 bg-white pl-9 pr-3 text-sm text-zinc-950 outline-none transition placeholder:text-zinc-400 focus:border-cu-purple focus:ring-2 focus:ring-cu-purple/20">
                </div>
            </div>
        </div>

        <div class="cu-animate-in overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm" style="animation-delay: 140ms">
            <div class="hidden grid-cols-[1.1fr_.6fr_.7fr_.7fr_.7fr] gap-4 border-b border-zinc-100 bg-zinc-50 px-5 py-3 text-xs font-medium uppercase tracking-wide text-zinc-500 lg:grid">
                <span>Document</span>
                <span>Date</span>
                <span>Status</span>
                <span>Progress</span>
                <span class="text-right">Action</span>
            </div>

            <div class="divide-y divide-zinc-100" wire:loading.class="opacity-40">
                @forelse ($rows as $submission)
                    <div wire:key="vendor-submission-{{ $submission['id'] }}" class="grid gap-4 px-5 py-4 transition hover:bg-zinc-50 lg:grid-cols-[1.1fr_.6fr_.7fr_.7fr_.7fr] lg:items-center">
                        <div class="flex min-w-0 gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-cu-blue/10 text-sky-700">
                                <flux:icon icon="document-text" class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-zinc-950">{{ $submission['document_type'] }}</p>
                                <p class="truncate text-xs text-zinc-500">{{ $submission['ref'] }} - {{ $submission['file_name'] }}</p>
                            </div>
                        </div>
                        <div class="text-sm text-zinc-600">
                            <p>{{ $submission['submitted_at']->format('M j, Y') }}</p>
                        </div>
                        <div>
                            <x-vendor-status-badge :status="$submission['status']" />
                        </div>
                        <div class="min-w-0">
                            <div class="h-2 rounded-full bg-zinc-100">
                                <div class="h-2 rounded-full cu-gradient" style="width: {{ $submission['progress'] }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-zinc-500">{{ $submission['progress'] }}%</p>
                        </div>
                        <div class="flex flex-wrap justify-start gap-2 lg:justify-end">
                            <flux:modal.trigger name="submission-{{ $submission['id'] }}">
                                <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 transition hover:border-cu-blue hover:bg-cu-blue/10 hover:text-sky-700">
                                    <flux:icon icon="eye" class="size-4" />
                                    Details
                                </button>
                            </flux:modal.trigger>
                            <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-sm font-medium text-rose-700 transition hover:bg-rose-50">
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
                                    <div class="rounded-xl border border-zinc-200 p-4">
                                        <p class="text-xs text-zinc-500">Status</p>
                                        <p class="mt-1 text-sm font-semibold">{{ $submission['status'] }}</p>
                                    </div>
                                    <div class="rounded-xl border border-zinc-200 p-4">
                                        <p class="text-xs text-zinc-500">Submitted</p>
                                        <p class="mt-1 text-sm font-semibold">{{ $submission['submitted_at']->toDayDateTimeString() }}</p>
                                    </div>
                                </div>
                                <p class="text-sm text-zinc-600">{{ $submission['note'] }}</p>
                                <div class="rounded-xl border border-zinc-200 p-4">
                                    <p class="text-sm font-semibold">Included file</p>
                                    <p class="mt-1 text-sm text-zinc-600">{{ $submission['file_name'] }} - {{ $submission['size'] }}</p>
                                </div>
                            </div>
                        </flux:modal>
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                        <flux:icon icon="folder-open" class="size-8 text-zinc-400" />
                        <p class="text-sm font-medium text-zinc-950">No submissions match your filters</p>
                        <p class="text-xs text-zinc-500">Try changing the status filter or search term.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <p class="text-center text-xs text-zinc-500">Showing {{ $rows->count() }} of {{ $total }} submissions.</p>
    </div>
</div>
