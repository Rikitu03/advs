<?php

use App\Support\DemoData;
use App\Support\DemoStore;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component {
    public string $search = '';

    public string $type = 'all';

    public string $severity = 'all';

    public string $range = 'all';

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function setSeverity(string $severity): void
    {
        $this->severity = $severity;
    }

    public function setRange(string $range): void
    {
        $this->range = $range;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function filtered(): Collection
    {
        $term = mb_strtolower(trim($this->search));

        return DemoStore::riskLogs()
            ->when($this->type !== 'all', fn (Collection $rows) => $rows->where('type', $this->type))
            ->when($this->severity !== 'all', fn (Collection $rows) => $rows->where('severity', $this->severity))
            ->when($this->range !== 'all', fn (Collection $rows) => $rows->filter(
                fn (array $log): bool => abs($log['raised_at']->diffInHours(now())) <= (int) $this->range
            ))
            ->when($term !== '', fn (Collection $rows) => $rows->filter(
                fn (array $log): bool => str_contains(
                    mb_strtolower("{$log['ref']} {$log['company']} {$log['vendor']} {$log['title']} {$log['document_type']}"),
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
            'total' => DemoStore::riskLogs()->count(),
            'types' => DemoData::flagTypes(),
        ];
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">

        {{-- Header --}}
        <div class="cu-animate-in flex flex-col gap-2">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Risk Logs</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-cu-text">Risk Logs</h1>
                    <p class="text-sm text-cu-muted">Chronological audit log of every flag the validation pipeline has raised.</p>
                </div>
                <span class="inline-flex items-center gap-2 rounded-full bg-black/5 px-3 py-1.5 text-sm text-cu-muted dark:bg-white/5">
                    <flux:icon.loading wire:loading variant="micro" class="size-3.5 text-rose-400" />
                    <span wire:loading.remove class="size-2 rounded-full bg-rose-400"></span>
                    {{ $rows->count() }} of {{ $total }} flags
                </span>
            </div>
        </div>

        {{-- Filter bar --}}
        <div class="cu-animate-in flex flex-col gap-3 rounded-2xl border border-cu-border bg-cu-surface p-4" style="animation-delay: 60ms">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <label class="relative flex-1">
                    <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-cu-muted" />
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search vendor, company, reference, or flag…"
                        class="w-full rounded-xl border border-cu-border bg-cu-bg py-2.5 pl-9 pr-3 text-sm text-cu-text placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                    />
                </label>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-1 rounded-xl border border-cu-border bg-cu-bg p-1">
                        @foreach (['all' => 'All severity', 'high' => 'High', 'medium' => 'Medium'] as $value => $text)
                            <button type="button" wire:click="setSeverity('{{ $value }}')"
                                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $severity === $value ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}">{{ $text }}</button>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-1 rounded-xl border border-cu-border bg-cu-bg p-1">
                        @foreach (['all' => 'All time', '24' => '24h', '168' => '7 days', '720' => '30 days'] as $value => $text)
                            <button type="button" wire:click="setRange('{{ $value }}')"
                                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $range === $value ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}">{{ $text }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
            {{-- Flag-type filter (§4: text mismatch, low classification confidence, signature mismatch, stamp mismatch) --}}
            <div class="flex flex-wrap items-center gap-1 self-start rounded-xl border border-cu-border bg-cu-bg p-1">
                <button type="button" wire:click="setType('all')"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $type === 'all' ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}">All flags</button>
                @foreach ($types as $key => $meta)
                    <button type="button" wire:click="setType('{{ $key }}')"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $type === $key ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}">{{ $meta['label'] }}</button>
                @endforeach
            </div>
        </div>

        {{-- Log table --}}
        <div class="cu-animate-in overflow-hidden rounded-2xl border border-cu-border bg-cu-surface" style="animation-delay: 120ms">
            <div class="overflow-x-auto" wire:loading.class="opacity-40" wire:target="search, setType, setSeverity, setRange">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-cu-border text-xs uppercase tracking-wide text-cu-muted">
                            <th class="px-5 py-3 font-medium">Flag</th>
                            <th class="px-5 py-3 font-medium">Severity</th>
                            <th class="px-5 py-3 font-medium">Vendor</th>
                            <th class="px-5 py-3 font-medium">Raised</th>
                            <th class="px-5 py-3 text-right font-medium">Report</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cu-border">
                        @foreach ($rows as $log)
                            @php($typeMeta = $types[$log['type']])
                            <tr wire:key="log-{{ $log['id'] }}" class="cu-animate-in transition hover:bg-black/[0.03] dark:hover:bg-white/[0.03]" style="animation-delay: {{ min($loop->index * 40, 320) }}ms">
                                <td class="px-5 py-4">
                                    <div class="flex items-start gap-3">
                                        <x-activity-icon :icon="$typeMeta['icon']" :color="$typeMeta['color']" />
                                        <div>
                                            <span class="block font-medium text-cu-text">{{ $log['title'] }}</span>
                                            <span class="block max-w-md text-xs text-cu-muted">{{ $log['detail'] }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <x-risk-badge :level="$log['severity']" />
                                </td>
                                <td class="px-5 py-4">
                                    <span class="block font-medium text-cu-text">{{ $log['company'] }}</span>
                                    <span class="block text-xs text-cu-muted">{{ $log['ref'] }} · {{ $log['document_type'] }}</span>
                                </td>
                                <td class="px-5 py-4 text-cu-muted">
                                    <span title="{{ $log['raised_at']->toDayDateTimeString() }}">{{ $log['raised_at']->diffForHumans() }}</span>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <a href="{{ route('admin.submissions.show', $log['submission_id']) }}" wire:navigate
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
                    <flux:icon icon="clipboard-document-list" class="size-8 text-cu-muted" />
                    <p class="text-sm font-medium text-cu-text">No flags match your filters</p>
                    <p class="text-xs text-cu-muted">Try a different search term, flag type, severity, or date range.</p>
                </div>
            @endif
        </div>
    </div>
</x-page>
