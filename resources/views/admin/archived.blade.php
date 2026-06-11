<x-layouts.app>
    @php
        $reports = \App\Support\DemoData::archivedReports();
        $metas = $reports->map(fn ($s) => [
            'id' => $s['id'],
            'level' => $s['risk_level'],
            'decision' => $s['decision'],
            'text' => trim("{$s['ref']} {$s['company']} {$s['vendor']} {$s['document_type']}"),
        ])->values();
    @endphp

    <div class="-m-6 min-h-full bg-cu-bg p-6 text-cu-text lg:-m-8 lg:p-8">
        <div
            class="mx-auto flex w-full max-w-7xl flex-col gap-6"
            x-data="{
                q: '',
                decision: 'all',
                risk: 'all',
                rows: @js($metas),
                match(r) {
                    const okDecision = this.decision === 'all' || r.decision === this.decision;
                    const okRisk = this.risk === 'all' || r.level === this.risk;
                    const okText = this.q.trim() === '' || r.text.toLowerCase().includes(this.q.toLowerCase());
                    return okDecision && okRisk && okText;
                },
                get visibleCount() { return this.rows.filter(r => this.match(r)).length; },
            }"
        >
            {{-- Header --}}
            <div class="flex flex-col gap-2">
                <flux:breadcrumbs>
                    <flux:breadcrumbs.item href="{{ route('admin.dashboard') }}" wire:navigate>Dashboard</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item>Archived Reports</flux:breadcrumbs.item>
                </flux:breadcrumbs>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-white">Archived Reports</h1>
                        <p class="text-sm text-cu-muted">All past validation reports with a recorded decision.</p>
                    </div>
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/5 px-3 py-1.5 text-sm text-cu-muted">
                        <span class="size-2 rounded-full bg-cu-blue"></span>
                        <span x-text="visibleCount"></span> of {{ $reports->count() }} reports
                    </span>
                </div>
            </div>

            {{-- Filter bar --}}
            <div class="flex flex-col gap-3 rounded-2xl border border-white/5 bg-cu-surface p-4 lg:flex-row lg:items-center">
                <label class="relative flex-1">
                    <flux:icon icon="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-cu-muted" />
                    <input
                        type="search"
                        x-model="q"
                        placeholder="Search vendor, company, or reference…"
                        class="w-full rounded-xl border border-white/10 bg-cu-bg py-2.5 pl-9 pr-3 text-sm text-white placeholder:text-cu-muted focus:border-cu-purple focus:outline-none focus:ring-2 focus:ring-cu-purple/40"
                    />
                </label>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-1 rounded-xl border border-white/10 bg-cu-bg p-1">
                        @foreach (['all' => 'All', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $text)
                            <button type="button" x-on:click="decision = '{{ $value }}'"
                                    :class="decision === '{{ $value }}' ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-white'"
                                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition">{{ $text }}</button>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-1 rounded-xl border border-white/10 bg-cu-bg p-1">
                        @foreach (['all' => 'All risk', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $value => $text)
                            <button type="button" x-on:click="risk = '{{ $value }}'"
                                    :class="risk === '{{ $value }}' ? 'bg-cu-purple text-white' : 'text-cu-muted hover:text-white'"
                                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition">{{ $text }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Reports table --}}
            <div class="overflow-hidden rounded-2xl border border-white/5 bg-cu-surface">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-white/5 text-xs uppercase tracking-wide text-cu-muted">
                                <th class="px-5 py-3 font-medium">Submission</th>
                                <th class="px-5 py-3 font-medium">Decision</th>
                                <th class="px-5 py-3 font-medium">Risk</th>
                                <th class="px-5 py-3 font-medium">Reviewed by</th>
                                <th class="px-5 py-3 font-medium">Decided</th>
                                <th class="px-5 py-3 text-right font-medium">Report</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach ($reports as $s)
                                <tr
                                    x-show="match(@js(['id' => $s['id'], 'level' => $s['risk_level'], 'decision' => $s['decision'], 'text' => trim("{$s['ref']} {$s['company']} {$s['vendor']} {$s['document_type']}")]))"
                                    class="transition hover:bg-white/[0.03]"
                                >
                                    <td class="px-5 py-4">
                                        <a href="{{ route('admin.submissions.show', $s['id']) }}" wire:navigate class="group block">
                                            <span class="font-medium text-white group-hover:text-cu-blue">{{ $s['company'] }}</span>
                                            <span class="block text-xs text-cu-muted">{{ $s['ref'] }} · {{ $s['vendor'] }}</span>
                                        </a>
                                    </td>
                                    <td class="px-5 py-4">
                                        @if ($s['decision'] === 'approved')
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                                                <flux:icon icon="check-circle" variant="micro" class="size-3.5" /> Approved
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-500/15 px-2.5 py-1 text-xs font-semibold text-rose-300">
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
                                           class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 px-3 py-1.5 text-sm font-medium text-white transition hover:border-cu-purple hover:bg-cu-purple/10">
                                            View
                                            <flux:icon icon="arrow-up-right" class="size-3.5" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div x-show="visibleCount === 0" x-cloak class="flex flex-col items-center gap-2 px-5 py-16 text-center">
                    <flux:icon icon="archive-box" class="size-8 text-cu-muted" />
                    <p class="text-sm font-medium text-white">No reports match your filters</p>
                    <p class="text-xs text-cu-muted">Try a different search term, decision, or risk level.</p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
