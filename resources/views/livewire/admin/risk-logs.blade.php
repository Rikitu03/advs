<?php

use App\Models\Submission;
use App\Support\SubmissionPresenter;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * Flag-type metadata for the Risk Logs tab (§4): label, icon, and tint.
     *
     * @var array<string, array{label: string, icon: string, color: string}>
     */
    private const FLAG_TYPES = [
        'text_mismatch' => ['label' => 'Text mismatch', 'icon' => 'document-magnifying-glass', 'color' => 'sky'],
        'low_classification' => ['label' => 'Low classification confidence', 'icon' => 'cpu-chip', 'color' => 'amber'],
        'signature_mismatch' => ['label' => 'Signature mismatch', 'icon' => 'pencil-square', 'color' => 'rose'],
        'stamp_mismatch' => ['label' => 'Stamp mismatch', 'icon' => 'shield-exclamation', 'color' => 'rose'],
        'stamp_texture' => ['label' => 'Stamp scan/copy texture', 'icon' => 'document-duplicate', 'color' => 'amber'],
        'tampering' => ['label' => 'Tampering', 'icon' => 'exclamation-triangle', 'color' => 'rose'],
        'other' => ['label' => 'Other', 'icon' => 'flag', 'color' => 'zinc'],
    ];

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
     * Every flag raised across processed submissions, newest first (§4 Risk
     * Logs) — derived live from the validation results so the log always
     * agrees with each submission's drill-down view.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function allLogs(): Collection
    {
        $typeNames = SubmissionPresenter::typeNames();
        $logs = collect();

        Submission::query()
            ->whereIn('status', [
                Submission::STATUS_PENDING_REVIEW,
                Submission::STATUS_APPROVED,
                Submission::STATUS_RESUBMISSION_REQUESTED,
            ])
            ->with(['vendor.user', 'documents.validationResult'])
            ->latest()
            ->get()
            ->each(function (Submission $submission) use (&$logs, $typeNames): void {
                $summary = SubmissionPresenter::summary($submission, $typeNames);

                foreach ($submission->documents as $document) {
                    $result = $document->validationResult;

                    foreach ($result?->flags ?? [] as $flag) {
                        $logs->push([
                            'type' => $this->categorize($flag),
                            'severity' => $summary['risk_level'] === 'high' ? 'high' : 'medium',
                            'title' => SubmissionPresenter::flagLabel($flag),
                            'detail' => $document->original_filename,
                            'raised_at' => $result->updated_at ?? $submission->created_at,
                            'submission_id' => $submission->id,
                            'ref' => $summary['ref'],
                            'vendor' => $summary['vendor'],
                            'company' => $summary['company'],
                            'document_type' => $summary['document_type'],
                        ]);
                    }
                }
            });

        return $logs
            ->sortByDesc('raised_at')
            ->values()
            ->map(fn (array $log, int $i): array => [...$log, 'id' => $i + 1]);
    }

    private function categorize(string $flag): string
    {
        $needle = mb_strtolower($flag);

        return match (true) {
            in_array($flag, ['stamp_tampered', 'stamp_tamper_unavailable'], true) => 'stamp_texture',
            str_contains($needle, 'tamper') => 'tampering',
            str_contains($needle, 'signature') => 'signature_mismatch',
            str_contains($needle, 'stamp') || str_contains($needle, 'logo') => 'stamp_mismatch',
            str_contains($needle, 'classif') => 'low_classification',
            str_contains($needle, 'text') || str_contains($needle, 'ocr') || str_contains($needle, 'field') => 'text_mismatch',
            default => 'other',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $term = mb_strtolower(trim($this->search));
        $all = $this->allLogs();

        $rows = $all
            ->when($this->type !== 'all', fn (Collection $logs) => $logs->where('type', $this->type))
            ->when($this->severity !== 'all', fn (Collection $logs) => $logs->where('severity', $this->severity))
            ->when($this->range !== 'all', fn (Collection $logs) => $logs->filter(
                fn (array $log): bool => abs($log['raised_at']->diffInHours(now())) <= (int) $this->range
            ))
            ->when($term !== '', fn (Collection $logs) => $logs->filter(
                fn (array $log): bool => str_contains(
                    mb_strtolower("{$log['ref']} {$log['company']} {$log['vendor']} {$log['title']} {$log['document_type']}"),
                    $term,
                )
            ))
            ->values();

        return [
            'rows' => $rows,
            'total' => $all->count(),
            'types' => self::FLAG_TYPES,
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
                        class="w-full rounded-xl border border-cu-border bg-black/5 py-2.5 pl-9 pr-3 text-sm text-cu-text placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40 dark:bg-white/5"
                    />
                </label>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-1 rounded-xl border border-cu-border bg-black/5 p-1 dark:bg-white/5">
                        @foreach (['all' => 'All severity', 'high' => 'High', 'medium' => 'Medium'] as $value => $text)
                            <button type="button" wire:click="setSeverity('{{ $value }}')"
                                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $severity === $value ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}">{{ $text }}</button>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-1 rounded-xl border border-cu-border bg-black/5 p-1 dark:bg-white/5">
                        @foreach (['all' => 'All time', '24' => '24h', '168' => '7 days', '720' => '30 days'] as $value => $text)
                            <button type="button" wire:click="setRange('{{ $value }}')"
                                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $range === $value ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-cu-text' }}">{{ $text }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
            {{-- Flag-type filter (§4: text mismatch, low classification confidence, signature mismatch, stamp mismatch) --}}
            <div class="flex flex-wrap items-center gap-1 self-start rounded-xl border border-cu-border bg-black/5 p-1 dark:bg-white/5">
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
