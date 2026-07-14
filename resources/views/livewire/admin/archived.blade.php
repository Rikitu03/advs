<?php

use App\Models\Submission;
use App\Support\SubmissionPresenter;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component {
    public string $search = '';

    public string $decision = 'all';

    public string $risk = 'all';

    public function setDecision(string $decision): void
    {
        $this->decision = $decision;
    }

    public function setRisk(string $risk): void
    {
        $this->risk = $risk;
    }

    /**
     * Decided submissions, newest decision first (§4 Archived Reports).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function filtered(): Collection
    {
        $term = mb_strtolower(trim($this->search));
        $typeNames = SubmissionPresenter::typeNames();

        return $this->archivedQuery()
            ->when($this->decision !== 'all', fn ($query) => $query->where('status', $this->decision))
            ->when($this->risk !== 'all', fn ($query) => $query->where('risk_level', $this->risk))
            ->with(['vendor.user', 'reviewer', 'documents.validationResult'])
            ->orderByDesc('reviewed_at')
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

    private function archivedQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Submission::query()
            ->whereIn('status', [Submission::STATUS_APPROVED, Submission::STATUS_REJECTED]);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'rows' => $this->filtered(),
            'total' => $this->archivedQuery()->count(),
        ];
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">

        {{-- Header --}}
        <div class="cu-animate-in flex flex-col gap-2">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Archived Reports</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-cu-text">Archived Reports</h1>
                    <p class="text-sm text-cu-muted">All past validation reports with a recorded decision.</p>
                </div>
                <span class="inline-flex items-center gap-2 rounded-full bg-black/5 dark:bg-white/5 px-3 py-1.5 text-sm text-cu-muted">
                    <flux:icon.loading wire:loading variant="micro" class="size-3.5 text-cu-blue" />
                    <span wire:loading.remove class="size-2 rounded-full bg-cu-blue"></span>
                    {{ $rows->count() }} of {{ $total }} reports
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
                    placeholder="Search vendor, company, or reference…"
                    class="w-full rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 py-2.5 pl-9 pr-3 text-sm text-cu-text placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                />
            </label>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1 rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 p-1">
                    @foreach (['all' => 'All', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $text)
                        <button type="button" wire:click="setDecision('{{ $value }}')"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $decision === $value ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}">{{ $text }}</button>
                    @endforeach
                </div>
                <div class="flex items-center gap-1 rounded-xl border border-cu-border bg-black/5 dark:bg-white/5 p-1">
                    @foreach (['all' => 'All risk', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $value => $text)
                        <button type="button" wire:click="setRisk('{{ $value }}')"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $risk === $value ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}">{{ $text }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Reports table --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface" style="animation-delay: 120ms">
            <div class="overflow-x-auto" wire:loading.class="opacity-40" wire:target="search, setDecision, setRisk">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-cu-border text-xs uppercase tracking-wide text-cu-muted">
                            <th class="px-5 py-3 font-medium">Submission</th>
                            <th class="px-5 py-3 font-medium">Decision</th>
                            <th class="px-5 py-3 font-medium">Risk</th>
                            <th class="px-5 py-3 font-medium">Reviewed by</th>
                            <th class="px-5 py-3 font-medium">Decided</th>
                            <th class="px-5 py-3 text-right font-medium">Report</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cu-border">
                        @foreach ($rows as $s)
                            <tr wire:key="archived-{{ $s['id'] }}" class="cu-animate-in transition hover:bg-black/[0.03] dark:hover:bg-white/[0.03]" style="animation-delay: {{ min($loop->index * 40, 320) }}ms">
                                <td class="px-5 py-4">
                                    <a href="{{ route('admin.submissions.show', $s['id']) }}" wire:navigate class="group block">
                                        <span class="font-medium text-cu-text group-hover:text-cu-blue">{{ $s['company'] }}</span>
                                        <span class="block text-xs text-cu-muted">{{ $s['ref'] }} · {{ $s['vendor'] }}</span>
                                    </a>
                                </td>
                                <td class="px-5 py-4">
                                    @if ($s['decision'] === 'approved')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                                            <flux:icon icon="check-circle" variant="micro" class="size-3.5" /> Approved
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-500/15 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:text-rose-300">
                                            <flux:icon icon="x-circle" variant="micro" class="size-3.5" /> Rejected
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-4"><x-risk-badge :level="$s['risk_level']" :score="$s['risk_score']" /></td>
                                <td class="px-5 py-4 text-cu-muted">{{ $s['reviewed_by'] }}</td>
                                <td class="px-5 py-4 text-cu-muted">
                                    <span title="{{ $s['reviewed_at']?->toDayDateTimeString() }}">{{ $s['reviewed_at']?->diffForHumans() }}</span>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <a href="{{ route('admin.submissions.show', $s['id']) }}" wire:navigate
                                       class="inline-flex items-center gap-1.5 rounded-lg border border-cu-border px-3 py-1.5 text-sm font-medium text-cu-text transition hover:border-cu-purple hover:bg-cu-purple/10">
                                        View
                                        <flux:icon icon="arrow-up-right" class="size-3.5" />
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($rows->isEmpty())
                <div class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                    <flux:icon icon="archive-box" class="size-8 text-cu-muted" />
                    <p class="text-sm font-medium text-cu-text">No reports match your filters</p>
                    <p class="text-xs text-cu-muted">Try a different search term, decision, or risk level.</p>
                </div>
            @endif
        </div>
    </div>
</x-page>
