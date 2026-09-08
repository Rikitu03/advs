<?php

use App\Models\Submission;
use App\Support\SubmissionPresenter;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    public string $search = '';

    public string $risk = 'all';

    public function setRisk(string $risk): void
    {
        $this->risk = $risk;
    }

    /**
     * Pending-review queue, highest composite risk first (§5 Stage 6).
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function filtered(): Collection
    {
        $term = mb_strtolower(trim($this->search));
        $typeNames = SubmissionPresenter::typeNames();

        return Submission::query()
            ->where('status', Submission::STATUS_PENDING_REVIEW)
            ->when($this->risk !== 'all', fn ($query) => $query->where('risk_level', $this->risk))
            ->with(['vendor.user', 'documents.validationResult'])
            ->orderByDesc('composite_risk_score')
            ->get()
            ->map(fn (Submission $submission): array => SubmissionPresenter::summary($submission, $typeNames))
            ->when($term !== '', fn (Collection $rows) => $rows->filter(
                fn (array $s): bool => str_contains(
                    mb_strtolower("{$s['ref']} {$s['company']} {$s['vendor']} {$s['document_type']}"),
                    $term,
                )
            ))
            ->values();
    }

    /**
     * Total pending-review submissions, independent of the active filters.
     */
    #[Computed]
    public function total(): int
    {
        return Submission::query()
            ->where('status', Submission::STATUS_PENDING_REVIEW)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'rows' => $this->filtered,
            'total' => $this->total,
        ];
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">

        {{-- Header --}}
        <div class="cu-animate-in flex flex-col gap-2">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Pending Submissions</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-cu-text">Pending Submissions</h1>
                    <p class="text-sm text-cu-muted">Sorted by composite risk score, highest first.</p>
                </div>
                <span class="inline-flex items-center gap-2 rounded-full bg-black/5 dark:bg-white/5 px-3 py-1.5 text-sm text-cu-muted">
                    <flux:icon.loading wire:loading variant="micro" class="size-3.5 text-cu-purple" />
                    <span wire:loading.remove class="size-2 rounded-full bg-cu-purple"></span>
                    {{ $rows->count() }} of {{ $total }} awaiting review
                </span>
            </div>
        </div>

        {{-- Filter bar --}}
        <div class="cu-animate-in flex flex-col gap-3 rounded-2xl border border-cu-border bg-cu-surface p-4 sm:flex-row sm:items-center" style="animation-delay: 60ms">
            <label class="relative flex-1">
                <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-cu-muted" />
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search vendor, company, or reference…"
                    class="w-full rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 py-2.5 pl-9 pr-3 text-sm text-cu-text placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                />
            </label>
            <div class="flex items-center gap-1 rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 p-1">
                @foreach (['all' => 'All', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $value => $text)
                    <button
                        type="button"
                        wire:click="setRisk('{{ $value }}')"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $risk === $value ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}"
                    >{{ $text }}</button>
                @endforeach
            </div>
        </div>

        {{-- Queue table --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface" style="animation-delay: 120ms">
            <div class="overflow-x-auto" wire:loading.class="opacity-40" wire:target="search, setRisk">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-cu-border text-xs uppercase tracking-wide text-cu-muted">
                            <th class="px-5 py-3 font-medium">Submission</th>
                            <th class="px-5 py-3 font-medium">Type</th>
                            <th class="px-5 py-3 font-medium">Submitted</th>
                            <th class="px-5 py-3 font-medium">Flags</th>
                            <th class="px-5 py-3 font-medium">Risk</th>
                            <th class="px-5 py-3 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cu-border">
                        @foreach ($rows as $s)
                            <tr wire:key="pending-{{ $s['id'] }}" class="cu-animate-in transition hover:bg-black/[0.03] dark:hover:bg-white/[0.03]" style="animation-delay: {{ min($loop->index * 40, 320) }}ms">
                                <td class="px-5 py-4">
                                    <a href="{{ route('admin.submissions.show', $s['id']) }}" wire:navigate class="group block">
                                        <span class="font-medium text-cu-text group-hover:text-cu-blue">{{ $s['company'] }}</span>
                                        <span class="block text-xs text-cu-muted">{{ $s['ref'] }} · {{ $s['vendor'] }}</span>
                                    </a>
                                </td>
                                <td class="px-5 py-4 text-cu-muted">{{ $s['document_type'] }}</td>
                                <td class="px-5 py-4 text-cu-muted">
                                    <span title="{{ $s['submitted_at']->toDayDateTimeString() }}">{{ $s['submitted_at']->diffForHumans() }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    @if (count($s['flags']) > 0)
                                        <span class="inline-flex items-center gap-1.5 text-cu-muted">
                                            <flux:icon icon="flag" class="size-4 text-rose-400" />
                                            {{ count($s['flags']) }}
                                        </span>
                                    @else
                                        <span class="text-cu-muted/60">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <x-risk-badge :level="$s['risk_level']" :score="$s['risk_score']" />
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <a href="{{ route('admin.submissions.show', $s['id']) }}" wire:navigate
                                       class="inline-flex items-center gap-1.5 rounded-lg border border-cu-border px-3 py-1.5 text-sm font-medium text-cu-text transition hover:border-cu-purple hover:bg-cu-purple/10">
                                        Review
                                        <flux:icon icon="arrow-up-right" class="size-3.5" />
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Empty states --}}
            @if ($rows->isEmpty())
                <div class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                    @if ($total === 0)
                        <flux:icon icon="check-badge" class="size-8 text-emerald-400" />
                        <p class="text-sm font-medium text-cu-text">Queue cleared</p>
                        <p class="text-xs text-cu-muted">Every submission has a recorded decision. Decided reports live in the archive.</p>
                    @else
                        <flux:icon icon="inbox-stack" class="size-8 text-cu-muted" />
                        <p class="text-sm font-medium text-cu-text">No submissions match your filters</p>
                        <p class="text-xs text-cu-muted">Try a different search term or risk level.</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-page>
