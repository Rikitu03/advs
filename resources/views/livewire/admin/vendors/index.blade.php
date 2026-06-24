<?php

use App\Support\DemoStore;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component {
    public string $search = '';

    public string $status = 'all';

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function filtered(): Collection
    {
        $term = mb_strtolower(trim($this->search));

        return DemoStore::vendors()
            ->when($this->status !== 'all', fn (Collection $rows) => $rows->where('status', $this->status))
            ->when($term !== '', fn (Collection $rows) => $rows->filter(
                fn (array $v): bool => str_contains(
                    mb_strtolower("{$v['company']} {$v['contact']} {$v['registration_number']}"),
                    $term,
                )
            ))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'rows' => $this->filtered(),
            'total' => DemoStore::vendors()->count(),
        ];
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">

        {{-- Header --}}
        <div class="cu-animate-in flex flex-col gap-2">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Vendor Profiles</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-cu-text">Vendor Profiles</h1>
                    <p class="text-sm text-cu-muted">Directory of registered vendors and their accreditation status.</p>
                </div>
                <span class="inline-flex items-center gap-2 rounded-full bg-black/5 px-3 py-1.5 text-sm text-cu-muted dark:bg-white/5">
                    <flux:icon.loading wire:loading variant="micro" class="size-3.5 text-cu-purple" />
                    <span wire:loading.remove class="size-2 rounded-full bg-cu-purple"></span>
                    {{ $rows->count() }} of {{ $total }} vendors
                </span>
            </div>
        </div>

        {{-- Filter bar --}}
        <div class="cu-animate-in flex flex-col gap-3 rounded-2xl border border-cu-border bg-cu-surface p-4 lg:flex-row lg:items-center" style="animation-delay: 60ms">
            <label class="relative flex-1">
                <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-cu-muted" />
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search company, contact, or registration no.…"
                    class="w-full rounded-xl border border-cu-border bg-black/5 py-2.5 pl-9 pr-3 text-sm text-cu-text placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5"
                />
            </label>
            <div class="flex flex-wrap items-center gap-1 rounded-xl border border-cu-border bg-black/5 p-1 dark:bg-white/5">
                @foreach (['all' => 'All', 'approved' => 'Approved', 'under_review' => 'Under review', 'pending' => 'Pending', 'rejected' => 'Rejected'] as $value => $text)
                    <button type="button" wire:click="setStatus('{{ $value }}')"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $status === $value ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}">{{ $text }}</button>
                @endforeach
            </div>
        </div>

        {{-- Vendor grid --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" wire:loading.class="opacity-40" wire:target="search, setStatus">
            @foreach ($rows as $v)
                <a
                    href="{{ route('admin.vendors.show', $v['id']) }}" wire:navigate wire:key="vendor-{{ $v['id'] }}"
                    class="cu-animate-in group flex flex-col gap-4 rounded-2xl border border-cu-border bg-cu-surface p-5 transition hover:-translate-y-0.5 hover:border-cu-purple/40 hover:bg-black/[0.03] dark:hover:bg-white/[0.03]"
                    style="animation-delay: {{ min(120 + $loop->index * 50, 480) }}ms"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-cu-purple/15 text-sm font-semibold text-cu-purple">
                                {{ \Illuminate\Support\Str::of($v['company'])->explode(' ')->take(2)->map(fn ($w) => \Illuminate\Support\Str::substr($w, 0, 1))->implode('') }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-cu-text group-hover:text-cu-blue">{{ $v['company'] }}</p>
                                <p class="truncate text-xs text-cu-muted">{{ $v['contact'] }}</p>
                            </div>
                        </div>
                        <flux:badge size="sm" :color="\App\Support\DemoData::vendorStatusColor($v['status'])">{{ str($v['status'])->headline() }}</flux:badge>
                    </div>

                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-cu-muted">Registered</dt>
                            <dd class="text-cu-text">{{ $v['registered_at']->format('M Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">Submissions</dt>
                            <dd class="text-cu-text">{{ $v['submissions_count'] }}</dd>
                        </div>
                    </dl>

                    <div class="flex items-center justify-between border-t border-cu-border pt-3">
                        @if ($v['enrolled'])
                            <span class="inline-flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-300">
                                <flux:icon icon="check-badge" variant="micro" class="size-3.5" /> References enrolled
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 text-xs text-cu-muted">
                                <flux:icon icon="minus-circle" variant="micro" class="size-3.5" /> Not enrolled
                            </span>
                        @endif
                        <flux:icon icon="chevron-right" class="size-4 text-cu-muted transition group-hover:translate-x-0.5 group-hover:text-cu-text" />
                    </div>
                </a>
            @endforeach
        </div>

        @if ($rows->isEmpty())
            <div class="flex flex-col items-center gap-2 rounded-2xl border border-cu-border bg-cu-surface px-5 py-16 text-center">
                <flux:icon icon="identification" class="size-8 text-cu-muted" />
                <p class="text-sm font-medium text-cu-text">No vendors match your filters</p>
                <p class="text-xs text-cu-muted">Try a different search term or status.</p>
            </div>
        @endif
    </div>
</x-page>
